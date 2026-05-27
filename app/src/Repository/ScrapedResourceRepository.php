<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScrapedResource;
use App\Enum\ScrapedResourceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScrapedResource>
 */
class ScrapedResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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
     * Archive toutes les opportunités en attente (pending) dont la deadline est passée.
     *
     * Stratégie pragmatique : on tente de parser le champ texte libre `deadline`.
     * Si le parsing réussit et que la date est antérieure à aujourd'hui → on archive.
     * Si le parsing échoue (format non reconnu, valeur vide, tiret...) → on ne touche pas.
     *
     * Formats de deadline tentés dans l'ordre :
     *   1. ISO 8601 court :     "2026-05-31"
     *   2. Français court :     "31/05/2026"
     *   3. Français long :      "31 mai 2026"
     *
     * Pourquoi seulement les "pending" (pas les rejected) ?
     *   Les rejected ont déjà fait l'objet d'une décision admin (rejet manuel).
     *   On ne veut pas écraser cette décision avec un archivage automatique.
     *   Les verified ont déjà généré une Resource publiée → intouchables aussi.
     *
     * Appelée à chaque run de ScrapeOpportunitiesCommand, après la sauvegarde BDD.
     *
     * @return int Nombre d'opportunités effectivement archivées (flush si > 0)
     */
    public function archiveExpired(): int
    {
        // On charge uniquement les pending (les rejected et verified sont ignorés)
        $pending = $this->findPending();

        // Référence temporelle : minuit aujourd'hui — une deadline "aujourd'hui" n'est pas expirée
        $today = new \DateTimeImmutable('today');
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
            // Exemple : "31/05/2026" ou "1/5/2026"
            if ($deadlineDate === null && preg_match('/^\d{1,2}\/\d{2}\/\d{4}$/', $deadline)) {
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
