<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScrapedResource;
use App\Enum\ScrapedResourceStatus;
use App\Service\TitleNormalizerService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScrapedResource>
 */
class ScrapedResourceRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        // TitleNormalizerService : remplace la méthode normalizeTitle() privée locale (S1).
        // Un seul service partagé garantit que tous les points de dédup utilisent
        // EXACTEMENT le même algorithme — divergence = doublons non détectés ou faux positifs.
        private readonly TitleNormalizerService $titleNormalizer,
    ) {
        parent::__construct($registry, ScrapedResource::class);
    }

    /**
     * Cherche une opportunité par son URL.
     * Utilisé pour éviter les doublons lors du scraping.
     */
    public function findByUrl(string $url): ?ScrapedResource
    {
        return $this->findOneBy(['url' => $url]);
    }

    /**
     * Cherche une ScrapedResource existante par clé de contenu (titre normalisé + deadline).
     *
     * Cette méthode implémente la DÉDUPLICATION PAR CONTENU, en complément de la
     * déduplication par URL déjà existante.
     *
     * Cas d'usage : une même opportunité publiée sur deux sites différents (ex: On The Move
     * ET Wooloo) aurait deux URLs distinctes mais un contenu identique → doublon non détecté
     * par la dédup URL. Cette méthode le rattrape.
     *
     * ── CLÉ DE DÉDUPLICATION ───────────────────────────────────────────────────
     *
     * La clé est composée de :
     *   1. titleNormalized : titre normalisé PHP (minuscules, sans accents, sans ponctu.,
     *                         espaces compressés). On compare en PHP — pas de fonction SQL
     *                         de normalisation d'accents portable sur toutes les BDD.
     *   2. deadlineDate    : date de clôture parsée (DateTimeImmutable, ou null).
     *
     * Pourquoi inclure la deadline dans la clé ?
     *   → Deux bourses distinctes peuvent porter le même titre "Bourse de création"
     *     mais avoir des deadlines différentes : ce sont des opportunités DIFFÉRENTES.
     *     La deadline dans la clé évite la sur-déduplication.
     *
     * Cas null+null (deux opportunités sans deadline, même titre normalisé) :
     *   → On considère quand même comme doublon probable (voir docblock de persistBatch()).
     *   → Un log de traçabilité est émis dans ScrapedResourcePersister.
     *   → On reste PRUDENT : si trop de faux-positifs, on peut désactiver ce cas.
     *
     * ── STRATÉGIE SQL ──────────────────────────────────────────────────────────
     *
     * On charge tous les candidats dont le deadlineDate correspond (ou IS NULL des deux
     * côtés), puis on filtre par titre normalisé EN PHP pour éviter de dépendre d'une
     * fonction SQL de normalisation (accents, casse) non portable.
     *
     * Performance : la comparaison PHP porte sur quelques dizaines de candidats au pire
     * (même deadline) — pas de risque de scan complet de la table.
     *
     * @param string                    $titleNormalized Titre normalisé (voir normalizeTitle() dans le persister)
     * @param \DateTimeImmutable|null   $deadlineDate    Date parsée de la deadline, ou null
     * @return ScrapedResource|null     Le premier doublon trouvé, ou null
     */
    public function findByContentKey(
        string $titleNormalized,
        ?\DateTimeImmutable $deadlineDate,
    ): ?ScrapedResource {
        // On construit le QueryBuilder selon la présence ou non d'une deadlineDate.
        // IS NULL vs = :deadlineDate nécessitent deux chemins distincts en DQL
        // (IS NULL n'accepte pas de paramètre lié).
        $qb = $this->createQueryBuilder('s');

        if ($deadlineDate !== null) {
            // Cas standard : on filtre les candidats qui ont exactement la même deadlineDate.
            //
            // Stratégie : on filtre par plage de 24h (minuit → minuit+1 jour) en DQL pur,
            // sans recourir à DATE() qui n'est pas une fonction DQL native (Doctrine rejette
            // les fonctions SQL non enregistrées dans le DQL parser).
            //
            // "Même jour" = deadlineDate >= minuit de ce jour ET < minuit du lendemain.
            // Ce critère est équivalent à DATE(deadlineDate) = la date donnée, de manière
            // portable sur toutes les bases de données supportées par Doctrine.
            $dayStart = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $deadlineDate->format('Y-m-d') . ' 00:00:00');
            $dayEnd   = $dayStart !== false ? $dayStart->modify('+1 day') : null;

            if ($dayStart !== false && $dayEnd !== null) {
                $qb->where('s.deadlineDate IS NOT NULL')
                   ->andWhere('s.deadlineDate >= :dayStart')
                   ->andWhere('s.deadlineDate < :dayEnd')
                   ->setParameter('dayStart', $dayStart)
                   ->setParameter('dayEnd', $dayEnd);
            } else {
                // Fallback si le parsing échoue (très improbable) : pas de filtre date → PHP filtre tout
                $qb->where('s.deadlineDate IS NOT NULL');
            }
        } else {
            // Cas null : on cherche dans les entrées qui n'ont pas non plus de deadlineDate.
            // Ce cas est plus risqué (sur-déduplication possible) — on reste prudent.
            $qb->where('s.deadlineDate IS NULL');
        }

        // On charge les candidats (potentiellement quelques-uns avec la même deadline)
        /** @var ScrapedResource[] $candidates */
        $candidates = $qb->getQuery()->getResult();

        // Filtrage PHP par titre normalisé.
        // On ne peut pas faire LOWER() + strip_accents() portable en SQL pur (pas de
        // fonction standard cross-BDD). Le filtrage PHP est suffisant car on travaille
        // sur un sous-ensemble déjà filtré par deadlineDate (quelques lignes au plus).
        foreach ($candidates as $candidate) {
            // Normalisation via le service centralisé (S1) — même algorithme que le persister.
            if ($this->titleNormalizer->normalize($candidate->getTitle()) === $titleNormalized) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Retourne toutes les opportunités en attente de validation, triées par score desc.
     *
     * @return ScrapedResource[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Pending)
            ->orderBy('s.relevanceScore', 'DESC')
            ->addOrderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les opportunités déjà validées, triées par date desc.
     *
     * @return ScrapedResource[]
     */
    public function findVerified(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Verified)
            ->orderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les opportunités (pending + verified), triées par score puis date.
     *
     * @return ScrapedResource[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.status', 'ASC')        // pending avant verified
            ->addOrderBy('s.relevanceScore', 'DESC')
            ->addOrderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les opportunités en attente de validation.
     *
     * Utilisé pour le badge "X en attente" dans le widget scraping du dashboard admin.
     * On utilise COUNT en DQL plutôt que count(findPending()) pour éviter de charger
     * tous les objets en mémoire — bien plus efficace sur une table volumineuse.
     */
    public function countPending(): int
    {
        // getSingleScalarResult() retourne une chaîne en PHP ; le cast (int) est obligatoire
        // pour satisfaire PHPStan niveau 6 (return type strict : int).
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne toutes les opportunités rejetées, triées du plus récent au plus ancien.
     *
     * @return ScrapedResource[]
     */
    public function findRejected(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Rejected)
            ->orderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les opportunités archivées (expirées ou archivées manuellement).
     *
     * Triées par date de scraping décroissante (les plus récentes d'abord).
     * Utilisé par AdminController::scrapedOpportunities() pour l'onglet "Archivé".
     *
     * @return ScrapedResource[]
     */
    public function findArchived(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', ScrapedResourceStatus::Archived)
            ->orderBy('s.scrapedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Archive toutes les opportunités pending dont la deadline structurée est passée.
     *
     * VERSION DQL — plus performante que archiveExpiredLegacy() :
     *   - Une seule requête UPDATE en base (pas de chargement en mémoire)
     *   - Atomique : toutes les archives en une transaction
     *   - Requiert que deadlineDate soit rempli (par le listener prePersist/preUpdate
     *     ou par la commande app:backfill-deadline-date)
     *
     * CONDITIONS D'ARCHIVAGE :
     *   - status = pending (jamais rejected ni verified — décisions admin à respecter)
     *   - deadlineDate IS NOT NULL (le champ structuré doit être renseigné)
     *   - deadlineDate < aujourd'hui minuit (deadline clairement passée)
     *   - scrapedAt < now - 48h (grâce de 48h — protège les items fraîchement insérés ;
     *     certains scrapers stockent une "date de publication" dans deadline, pas la vraie
     *     deadline de candidature — sans cette protection, ces items seraient archivés
     *     immédiatement avant que l'admin les voie)
     *
     * FEATURE FLAG :
     *   Pour revenir à l'ancien comportement string-parsing en cas de régression,
     *   activer le setting archive_use_legacy = 1 dans /admin/settings.
     *   Le branchement est dans ScrapeOpportunitiesCommand.
     *
     * PRÉ-REQUIS DÉPLOIEMENT :
     *   Lancer app:backfill-deadline-date après la migration pour que deadlineDate
     *   soit rempli sur les enregistrements existants. Sans ça, cette méthode
     *   ne trouvera rien à archiver (deadlineDate IS NOT NULL sera toujours faux).
     *
     * @return int Nombre d'opportunités archivées par cette requête
     */
    public function archiveExpired(): int
    {
        // Référence temporelle : minuit aujourd'hui EN HEURE DE PARIS (correction C1).
        //
        // PROBLÈME SANS CE CORRECTIF :
        //   Le container Docker tourne en UTC. Sans timezone explicite,
        //   new \DateTimeImmutable('today') retourne minuit UTC.
        //   Or minuit UTC = 2h du matin à Paris : entre minuit et 2h heure de Paris,
        //   une opportunité dont la deadline est "aujourd'hui" (dernier jour valide)
        //   serait archivée prématurément — les artistes ne pourraient plus candidater.
        //
        // SOLUTION :
        //   Europe/Paris comme timezone → 'today' = minuit heure de Paris → correct.
        //   La décision de ne PAS modifier la timezone globale du container est réservée
        //   à l'infra (Gaëlle) — on corrige localement sans toucher au Dockerfile.
        //
        // Note : deadlineDate est de type DATETIME (champ structuré dans l'entité).
        // La comparaison DQL < :today utilise le timestamp complet.
        // Minuit Paris = 22h UTC la veille → une deadline au 18 juin 2026 00:00:00 Paris
        // est < :today (17 juin 2026 22:00:00 UTC) uniquement si elle date d'avant le 18 juin.
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));

        // Grâce 48h : on ne touche jamais un item créé il y a moins de 48 heures
        $graceLimit = new \DateTimeImmutable('-48 hours');

        // Requête DQL UPDATE directe — plus efficace que charger tous les pending en mémoire.
        // execute() est la méthode correcte sur Doctrine\ORM\Query (objet DQL).
        // executeStatement() appartient à DBAL (connexion bas niveau) — pas à ORM\Query.
        // execute() retourne un int : le nombre de lignes affectées par l'UPDATE.
        $count = (int) $this->getEntityManager()
            ->createQuery(
                'UPDATE App\Entity\ScrapedResource s
                 SET s.status = :archived
                 WHERE s.status = :pending
                   AND s.deadlineDate IS NOT NULL
                   AND s.deadlineDate < :today
                   AND s.scrapedAt < :graceLimit'
            )
            ->setParameter('archived', \App\Enum\ScrapedResourceStatus::Archived)
            ->setParameter('pending',  \App\Enum\ScrapedResourceStatus::Pending)
            ->setParameter('today',    $today)
            ->setParameter('graceLimit', $graceLimit)
            ->execute();

        return $count;
    }

    /**
     * @deprecated Utiliser archiveExpired() (DQL) à la place.
     *             Conservée comme porte de sortie en cas de régression.
     *             Activable depuis /admin/settings (setting archive_use_legacy = 1).
     *             Suppression prévue après validation en production.
     *
     * Archive toutes les opportunités en attente (pending) dont la deadline est passée.
     *
     * Stratégie : parse le champ texte libre `deadline` en mémoire PHP.
     * Formats tentés : ISO 8601 (YYYY-MM-DD), français court (JJ/MM/AAAA),
     * français long ("31 mai 2026"). Si parsing échoue → non archivé.
     * Grâce 48h sur scrapedAt : protège les items fraîchement insérés.
     *
     * @return int Nombre d'opportunités archivées (flush si > 0)
     */
    public function archiveExpiredLegacy(): int
    {
        // On charge uniquement les pending (les rejected et verified sont ignorés)
        $pending = $this->findPending();

        // Référence temporelle : minuit aujourd'hui EN HEURE DE PARIS (correction C1).
        // Même logique que archiveExpired() — voir son commentaire pour l'explication complète.
        // Sans la timezone Europe/Paris, le container UTC archiverait les deadlines
        // "d'aujourd'hui" entre minuit et 2h du matin heure de Paris → archivage prématuré.
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $count = 0;

        // Mapping des noms de mois français vers leurs numéros (pour le format "31 mai 2026")
        $monthsFr = [
            'janvier'   => '01',
            'février'   => '02',
            'mars'      => '03',
            'avril'     => '04',
            'mai'       => '05',
            'juin'      => '06',
            'juillet'   => '07',
            'août'      => '08',
            'septembre' => '09',
            'octobre'   => '10',
            'novembre'  => '11',
            'décembre'  => '12',
        ];

        // Grâce de 48h : on ne touche jamais un item créé il y a moins de 48 heures.
        // Raison : certains scrapers stockent une "date de publication" dans deadline
        // (pas la vraie deadline de candidature). Sans cette protection, des items
        // fraîchement insérés seraient archivés immédiatement avant que l'admin les voie.
        $gracePeriod = new \DateTimeImmutable('-48 hours');

        foreach ($pending as $resource) {
            // Protection grâce de 48h : item trop récent → on ne touche pas
            if ($resource->getScrapedAt() > $gracePeriod) {
                continue;
            }

            $deadline = trim($resource->getDeadline() ?? '');

            // Cas triviaux : deadline vide, tiret, ou valeur non informative → on ignore
            if ($deadline === '' || $deadline === '-' || $deadline === '—') {
                continue;
            }

            // $deadlineDate sera null si aucun format ne correspond
            $deadlineDate = null;

            // ── Tentative 1 : format ISO 8601 court (YYYY-MM-DD) ─────────────
            // Exemple : "2026-05-31"
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
                $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
                if ($parsed !== false) {
                    $deadlineDate = $parsed;
                }
            }

            // ── Tentative 2 : format français court (JJ/MM/AAAA) ─────────────
            // Exemple : "31/05/2026" ou "1/5/2026" (jour ET mois peuvent être sans zéro)
            // Aligné sur DeadlineParserService::parse() — même pattern pour cohérence.
            if ($deadlineDate === null && preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $deadline)) {
                $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', $deadline);
                if ($parsed !== false) {
                    $deadlineDate = $parsed;
                }
            }

            // ── Tentative 3 : format français long ("JJ mois AAAA") ───────────
            // Exemple : "31 mai 2026" ou "15 décembre 2026"
            if ($deadlineDate === null && preg_match('/^(\d{1,2})\s+(\w+)\s+(\d{4})$/i', $deadline, $matches)) {
                $monthStr = mb_strtolower($matches[2]);
                if (isset($monthsFr[$monthStr])) {
                    // Reconstruction en format JJ/MM/AAAA pour createFromFormat
                    $normalized  = sprintf('%02d/%s/%s', (int) $matches[1], $monthsFr[$monthStr], $matches[3]);
                    $parsed      = \DateTimeImmutable::createFromFormat('d/m/Y', $normalized);
                    if ($parsed !== false) {
                        $deadlineDate = $parsed;
                    }
                }
            }

            // ── Archivage si deadline clairement passée ───────────────────────
            // On n'archive que si :
            //   a) le parsing a réussi (deadlineDate n'est pas null)
            //   b) la date est strictement antérieure à aujourd'hui
            //      (une deadline "aujourd'hui" = dernier jour pour candidater, pas encore expirée)
            if ($deadlineDate !== null && $deadlineDate < $today) {
                $resource->setStatus(ScrapedResourceStatus::Archived);
                $count++;
            }
        }

        // Flush groupé : on écrit toutes les modifications en une seule transaction
        // (plus efficace qu'un flush par entité modifiée)
        if ($count > 0) {
            $this->getEntityManager()->flush();
        }

        return $count;
    }

    /**
     * Retourne les ScrapedResource candidates au backfill de deadlineDate.
     *
     * Critères :
     *   - deadlineDate IS NULL          → pas encore rempli
     *   - deadline IS NOT NULL AND != ''→ deadline string disponible pour parsing
     *
     * Utilisé UNIQUEMENT par BackfillDeadlineDateCommand (commande one-shot).
     * Ne PAS utiliser ailleurs — pour les usages courants, voir findPending() etc.
     *
     * @return ScrapedResource[]
     */
    public function findForDeadlineBackfill(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.deadlineDate IS NULL')
            ->andWhere('s.deadline IS NOT NULL')
            ->andWhere("s.deadline != ''")
            ->andWhere("s.deadline != '-'")
            ->andWhere("s.deadline != '—'")
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les ScrapedResource candidates à l'enrichissement IA.
     *
     * NOUVEAU CRITÈRE DE SÉLECTION (remplace la sélection par description IS NULL) :
     *   On cible maintenant les items dont enriched_at IS NULL — c'est-à-dire
     *   ceux qui n'ont JAMAIS été enrichis par le LLM.
     *
     * AVANTAGES vs l'ancienne approche (description IS NULL) :
     *   1. Idempotence stricte : un item enrichi une fois ne sera plus jamais sélectionné
     *      automatiquement, quelle que soit la valeur de description (certains champs
     *      comme disciplines peuvent être vides sans que la description le soit).
     *   2. Préservation des éditions admin : si un admin modifie la description d'une
     *      ScrapedResource, enriched_at est déjà non null → le cron ne l'écrase pas.
     *   3. Pas de drift LLM : les items déjà correctement enrichis ne sont pas
     *      re-traités, ce qui évite une variation aléatoire due au LLM.
     *
     * BACKFILL :
     *   La migration Version20260619113604 a peuplé enriched_at = NOW() pour tous
     *   les items du stock existant ayant une description non vide. Seuls les items
     *   vraiment sans description démarrent avec enriched_at = NULL après le déploiement.
     *
     * --force :
     *   Avec $includeWithDescription = true, on ignore le filtre enriched_at
     *   et on cible tous les items actifs (comportement --force inchangé).
     *
     * CONDITIONS :
     *   - enriched_at IS NULL (jamais enrichi) ← NOUVEAU, remplace description IS NULL
     *   - url IS NOT NULL (on a besoin de l'URL pour fetcher la page)
     *   - status IN (pending, verified) — on n'enrichit pas les rejected ni les archived
     *
     * @param int         $limit               Nombre max de résultats (maîtrise du coût LLM)
     * @param string|null $sourceSite          Filtre sur le site source (ex: "on-the-move.org")
     * @param bool        $includeWithDescription Si true (--force), ignore le filtre enriched_at
     *
     * ORDRE : les plus récentes d'abord (par scrapedAt DESC) — on traite en priorité les
     *   nouvelles opportunités, car ce sont les plus susceptibles d'être encore valides.
     *
     * @return ScrapedResource[]
     */
    public function findForEnrichment(
        int $limit = 20,
        ?string $sourceSite = null,
        bool $includeWithDescription = false,
    ): array {
        $qb = $this->createQueryBuilder('s')
            // On doit avoir une URL pour pouvoir fetcher la page de détail
            ->where('s.url IS NOT NULL')
            // On n'enrichit pas les opportunités rejetées ou archivées
            // (décision admin à respecter — elles sont hors cycle de vie actif)
            ->andWhere('s.status IN (:activeStatuses)')
            ->setParameter('activeStatuses', [
                ScrapedResourceStatus::Pending,
                ScrapedResourceStatus::Verified,
            ])
            // Les plus récentes en priorité (nouvelles opportunités actives d'abord)
            ->orderBy('s.scrapedAt', 'DESC')
            // Limite le nombre de résultats pour maîtriser le coût LLM et le temps d'exécution
            ->setMaxResults($limit);

        // ── Filtre d'éligibilité : enriched_at IS NULL (sauf --force) ──────────
        //
        // SANS --force (cas normal, run cron) :
        //   On ne sélectionne que les items qui n'ont jamais été enrichis.
        //   Critère : enriched_at IS NULL
        //   Un item enrichi une fois (enriched_at non null) n'est plus sélectionné.
        //
        // AVEC --force ($includeWithDescription = true) :
        //   On ignore complètement le filtre enriched_at → tous les items actifs
        //   avec URL sont candidates. Usage manuel uniquement (amélioration globale,
        //   re-enrichissement après évolution du prompt, etc.).
        //   ⚠️  Coûteux : peut retraiter des centaines d'items. À utiliser avec --limit.
        if (!$includeWithDescription) {
            // enriched_at IS NULL → jamais enrichi par le LLM
            $qb->andWhere('s.enrichedAt IS NULL');
        }

        // Filtre par source (option --source de la commande)
        if ($sourceSite !== null) {
            $qb->andWhere('s.sourceSite = :sourceSite')
               ->setParameter('sourceSite', $sourceSite);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retourne la date du scraping le plus récent, ou null si la table est vide.
     *
     * Utilisé dans le dashboard admin pour afficher "Dernier scraping : XX/XX/XXXX".
     * On utilise SELECT MAX() en DQL pour éviter de charger toute la table en mémoire.
     *
     * Note : getSingleScalarResult() peut retourner null (table vide) ou une string ISO-8601.
     * On construit un \DateTime depuis cette string, ou on retourne null si table vide.
     */
    public function findLatestScrapedAt(): ?\DateTimeInterface
    {
        // Retourne la valeur scalaire maximale de scrapedAt (ou null si table vide)
        $result = $this->createQueryBuilder('s')
            ->select('MAX(s.scrapedAt) AS latestAt')
            ->getQuery()
            ->getSingleScalarResult();

        // Si la table est vide, MAX() retourne null — on renvoie null directement
        if ($result === null) {
            return null;
        }

        // getSingleScalarResult() retourne une string (format ISO-8601 depuis PostgreSQL).
        // On la convertit en \DateTime pour que Twig puisse appliquer le filtre |date().
        return new \DateTime((string) $result);
    }
}
