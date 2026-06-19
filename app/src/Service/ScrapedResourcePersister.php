<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use App\Entity\ScrapedResource;
use App\Enum\ExperienceLevel;
use App\Enum\ScrapedResourceStatus;
use App\Repository\ResourceRepository;
use App\Repository\ScrapedResourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * ScrapedResourcePersister — Déduplication et persistance des opportunités scrapées.
 *
 * Ce service factorise la logique de déduplication/persistance qui était
 * inline dans ScrapeOpportunitiesCommand (lignes ~248-350).
 *
 * ── POURQUOI factoriser ici ? ────────────────────────────────────────────────
 * En WS2, le pipeline RSS (FeedReaderService) produit des ScrapedOpportunity[]
 * exactement comme l'ancien pipeline LLM/CSS. Plutôt que de dupliquer 80 lignes
 * de logique délicate dans chaque pipeline, on centralise ici.
 * Un seul endroit de vérité → une seule correction si un bug de dédup est trouvé.
 *
 * ── LES 5 CAS DE DÉDUPLICATION ──────────────────────────────────────────────
 *   1. URL inconnue en BDD         → INSERT, status = pending
 *   2. URL connue + status archived → réactivation en pending + reset scrapedAt
 *   3. URL connue + status rejected → mise à jour des champs, status inchangé
 *   4. URL connue + status pending  → mise à jour des champs, status inchangé
 *   5. URL connue + status verified → skip complet (travail de modération protégé)
 *
 * ── GUARD INTRA-LOT ─────────────────────────────────────────────────────────
 * Le LLM ou le parseur RSS peut retourner deux fois la même URL dans le même lot.
 * Sans guard, le second passage ne trouve rien en BDD (pas encore flushé) et tente
 * un INSERT → violation de contrainte UNIQUE. Le set $seenUrls déduplique en mémoire
 * AVANT la vérification BDD, évitant cette collision.
 *
 * ── FLUSH UNIQUE ────────────────────────────────────────────────────────────
 * Un seul flush() à la fin du batch. Doctrine regroupe toutes les opérations en
 * une seule transaction → bien plus performant qu'un flush par item.
 */
class ScrapedResourcePersister
{
    public function __construct(
        // EntityManager pour persister les nouvelles ScrapedResource
        private readonly EntityManagerInterface $em,
        // Repository ScrapedResource : déduplication par URL ET par contenu (findByContentKey)
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        // Repository Resource : cherche les doublons dans les Resource publiées (findPublishedByContentKey)
        // Nécessaire car une opportunité peut avoir été scrapée, vérifiée, et déjà publiée —
        // dans ce cas elle n'est plus en scraped_resources mais en resources.
        private readonly ResourceRepository $resourceRepository,
        // Logger pour tracer les skips "sans deadline" (doublon potentiel, log obligatoire)
        private readonly LoggerInterface $logger,
        // TitleNormalizerService : remplace la méthode normalizeTitle() privée locale (S1).
        // Utiliser ce service garantit que le persister et les repositories appliquent
        // EXACTEMENT le même algorithme — divergence = doublons non détectés ou faux positifs.
        private readonly TitleNormalizerService $titleNormalizer,
        // DeadlineParserService : injecté pour parseDtoDeadline() (correction A1).
        // Supporte maintenant les 3 formats : ISO, FR court, FR long ("30 septembre 2026").
        // Sans ce service, les deadlines FR long produisaient une clé "titre|" (sans date)
        // → deux opportunités identiques avec deadline "30 septembre 2026" sous des URLs
        //   différentes n'étaient PAS reconnues comme doublons de contenu.
        private readonly DeadlineParserService $deadlineParser,
    ) {
    }

    /**
     * Persiste un lot d'opportunités en BDD avec déduplication complète.
     *
     * C'est la méthode principale de ce service. Elle reçoit les opportunités
     * brutes depuis n'importe quel pipeline (LLM, CSS, RSS) et applique
     * systématiquement la même logique de dédup/persistance.
     *
     * ── ORDRE DE VÉRIFICATION (du plus spécifique au plus large) ────────────
     *  1. Dédup intra-lot URL     → même URL dans le même batch (guard mémoire)
     *  2. Dédup intra-lot contenu → même (titre+deadline) dans le même batch
     *  3. Dédup inter-lots URL    → findByUrl() → ScrapedResource existante
     *  4. Dédup contenu ScrapedResource → findByContentKey() → doublon contenu
     *  5. Dédup contenu Resource  → findPublishedByContentKey() → déjà publié
     *
     * @param ScrapedOpportunity[] $opportunities Liste d'opportunités à persister
     *
     * @return PersistResult Compteurs : inserted / reactivated / updated / skipped / contentDedup
     */
    public function persistBatch(array $opportunities): PersistResult
    {
        // Compteurs pour les logs et l'affichage dans la commande
        $inserted     = 0;
        $reactivated  = 0;
        $updated      = 0;
        $skipped      = 0;
        $contentDedup = 0; // Doublons détectés par titre+deadline (URLs différentes)

        /**
         * URLs réellement insérées (Cas 1 — nouvelles en BDD).
         * Collectées ici pour être renvoyées dans PersistResult et permettre
         * à ImportGrantCsvCommand (--enrich) de n'enrichir QUE les nouvelles.
         *
         * @var string[]
         */
        $insertedUrls = [];

        // ── Guard en mémoire contre les doublons INTRA-LOT (URL) ─────────────
        // Sans ce set, deux occurrences de la même URL dans le même batch
        // (cas fréquent avec les retours LLM) provoqueraient une violation
        // de contrainte UNIQUE sur scraped_resources.url au moment du flush.
        // On garde une trace de chaque URL déjà traitée dans ce batch.
        /** @var array<string, true> $seenUrls */
        $seenUrls = [];

        // ── Guard en mémoire contre les doublons INTRA-LOT (contenu) ─────────
        // Sans ce guard, deux opportunités avec le même titre+deadline mais des URLs
        // différentes dans le même batch passeraient toutes les deux la vérification
        // BDD (la 2e n'est pas encore flushée → pas encore cherchable) et doubleraient.
        //
        // Format de la clé : "titleNormalized|YYYY-MM-DD" ou "titleNormalized|" (sans date)
        /** @var array<string, true> $seenContentKeys */
        $seenContentKeys = [];

        foreach ($opportunities as $opp) {
            // ── Déduplication intra-lot URL ───────────────────────────────────
            // Si la même URL apparaît deux fois dans ce batch, on ignore les
            // occurrences après la première.
            // Note : $opp->url est string (non nullable) — pas besoin du !== null.
            if ($opp->url !== '') {
                if (isset($seenUrls[$opp->url])) {
                    $skipped++;
                    continue;
                }
                $seenUrls[$opp->url] = true;
            }

            // ── Déduplication intra-lot CONTENU ──────────────────────────────
            // On construit la clé de contenu pour ce batch (titre normalisé + deadline).
            // Même si la BDD ne contient pas encore ce doublon (pas encore flushé),
            // on empêche dès maintenant la 2e occurrence de passer.
            // Normalisation via le service centralisé (S1) — remplace l'appel local.
            // TitleNormalizerService garantit le même algorithme que les repositories.
            $titleNorm   = $this->titleNormalizer->normalize($opp->title);
            // deadlineDate pour ce DTO : on parse la string deadline du DTO si renseignée.
            // Pour les RSS scrapers, $opp->publishedAt est rempli mais pas $opp->deadline.
            // On essaie de parser $opp->deadline sous forme ISO "YYYY-MM-DD" si présent.
            $deadlineDateDto = $this->parseDtoDeadline($opp->deadline);
            $contentKey      = $titleNorm . '|' . ($deadlineDateDto?->format('Y-m-d') ?? '');

            if (isset($seenContentKeys[$contentKey])) {
                // Doublon de contenu intra-lot → on ignore et on incrémente le compteur dédié
                $contentDedup++;
                continue;
            }
            $seenContentKeys[$contentKey] = true;

            // ── Recherche en BDD (déduplication inter-lots URL) ──────────────
            // findByUrl() fait un SELECT sur la contrainte UNIQUE url.
            // Si null → URL jamais vue → cas 1 (INSERT).
            // Sinon → on applique le cas approprié selon le statut.
            $existing = $opp->url ? $this->scrapedResourceRepository->findByUrl($opp->url) : null;

            if ($existing !== null) {
                // ── Cas 5 : déjà vérifiée par un admin → intouchable ─────────
                // Un admin a validé cette opportunité. On préserve son travail
                // de modération : ni mise à jour, ni réinsertion.
                if ($existing->isVerified()) {
                    $skipped++;
                    continue;
                }

                // ── Cas 2 : archivée → réactivation en pending ───────────────
                // L'opportunité avait expiré (deadline passée) ou avait été
                // archivée manuellement. Le scraper la retrouve sur le site
                // → elle est probablement de nouveau valide.
                // On remet scrapedAt à "maintenant" pour que archiveExpired()
                // ne la ré-archive pas immédiatement (protection 48h).
                if ($existing->isArchived()) {
                    $existing->setTitle($opp->title);
                    $existing->setDescription($opp->description ?: null);
                    $existing->setType($opp->type ?: null);
                    $existing->setDeadline($opp->deadline ?: null);
                    $existing->setRelevanceScore($opp->relevanceScore);
                    $existing->setDisciplines($opp->disciplines ?: null);
                    $existing->setStatus(ScrapedResourceStatus::Pending); // réactivation
                    $existing->setScrapedAt(new \DateTime());              // reset grâce 48h

                    // Mise à jour de publishedAt uniquement si le nouveau scraping
                    // fournit une date (on ne pilonne pas une valeur existante par null).
                    // Raison : si le flux RSS ne fournit plus de pubDate, on conserve
                    // l'ancienne — mieux vaut une date potentiellement périmée que null.
                    if ($opp->publishedAt !== null) {
                        $existing->setPublishedAt($opp->publishedAt);
                    }

                    // ADR-0016 Lot 1 : mise à jour des champs LLM enrichis (si disponibles)
                    // On n'écrase que si les nouvelles valeurs sont non vides.
                    $this->updateEnrichedFields($existing, $opp);

                    $reactivated++;
                    continue;
                }

                // ── Cas 3 & 4 : rejected ou pending → rafraîchir les données ─
                // On ne change PAS le statut : si un admin a rejeté l'opportunité,
                // elle reste rejetée. On met à jour les données (titre, desc, etc.)
                // au cas où le site a modifié l'annonce entre deux runs.
                // Doctrine détecte les changements via le Unit of Work — pas besoin
                // d'appeler persist() explicitement sur une entité déjà managée.
                $existing->setTitle($opp->title);
                $existing->setDescription($opp->description ?: null);
                $existing->setType($opp->type ?: null);
                $existing->setDeadline($opp->deadline ?: null);
                $existing->setRelevanceScore($opp->relevanceScore);
                $existing->setDisciplines($opp->disciplines ?: null);

                // Même logique que le cas 2 : on met à jour publishedAt seulement
                // si le flux fournit une valeur — jamais on n'écrase par null.
                if ($opp->publishedAt !== null) {
                    $existing->setPublishedAt($opp->publishedAt);
                }

                // ADR-0016 Lot 1 : mise à jour des champs LLM enrichis (si disponibles)
                $this->updateEnrichedFields($existing, $opp);

                $updated++;
                continue;
            }

            // ── Déduplication par CONTENU inter-lots (BDD) ───────────────────
            //
            // On arrive ici uniquement si l'URL est nouvelle (cas 1 sinon déjà traité).
            // Mais l'URL inconnue ne signifie pas que l'opportunité est nouvelle :
            // elle peut exister sous une autre URL.
            //
            // On vérifie donc si un doublon de CONTENU existe déjà :
            //   a) Dans scraped_resources (pending, rejected, archived) via findByContentKey()
            //   b) Dans resources publiées               via findPublishedByContentKey()
            //
            // Règle de décision :
            //   - deadlineDate connue : clé = titleNorm + deadlineDate → fiable, on skip
            //   - deadlineDate null des deux côtés : on skip mais on LOG (prudence)
            //
            // ⚠️ On utilise $deadlineDateDto calculé ci-dessus (parsé depuis $opp->deadline).
            // Si le DTO n'a pas de deadline string parseable, $deadlineDateDto est null.
            // Dans ce cas, deux opportunités sans deadline et même titre normalisé → skip + log.
            //
            // On ne sur-déduplique pas : deux titres identiques mais deadlines DIFFÉRENTES
            // auront des clés différentes (les deadlines sont dans la clé).
            $existingScraped  = $this->scrapedResourceRepository->findByContentKey($titleNorm, $deadlineDateDto);
            $existingResource = ($existingScraped === null)
                ? $this->resourceRepository->findPublishedByContentKey($titleNorm, $deadlineDateDto)
                : null;

            if ($existingScraped !== null || $existingResource !== null) {
                // Cas spécial "double null" : on logue pour traçabilité.
                // Deux opportunités sans deadline ET même titre normalisé → doublon probable
                // mais non certain. On skippe par sécurité mais on prévient dans les logs.
                if ($deadlineDateDto === null) {
                    $this->logger->info(
                        '[ScrapedResourcePersister] Doublon contenu (sans deadline) ignoré.',
                        [
                            // URL de la nouvelle opportunité qu'on a décidé de skipper
                            'url_skipped'     => $opp->url,
                            // Titre normalisé qui a matché
                            'title_normalized' => $titleNorm,
                            // Indique si le doublon est dans scraped_resources ou resources
                            'matched_in'      => $existingScraped !== null ? 'scraped_resources' : 'resources',
                        ]
                    );
                }

                $contentDedup++;
                continue;
            }

            // ── Cas 1 : nouvelle URL → INSERT en BDD ─────────────────────────
            // Status par défaut : pending (l'admin valide depuis /admin/scraped-opportunities)
            $scraped = new ScrapedResource();
            $scraped->setTitle($opp->title);
            $scraped->setDescription($opp->description ?: null);
            $scraped->setUrl($opp->url ?: null);
            $scraped->setType($opp->type ?: null);
            $scraped->setSourceSite($opp->source ?: null);
            $scraped->setDeadline($opp->deadline ?: null);
            $scraped->setRelevanceScore($opp->relevanceScore);
            $scraped->setDocuments($opp->documents ?: null);
            $scraped->setDisciplines($opp->disciplines ?: null);

            // publishedAt : date de publication sur la source (flux RSS/Atom).
            // Null pour les scrapers CSS/LLM (pas de notion de pubDate dans ce cas).
            // JAMAIS la date limite — celle-ci est dans $opp->deadline (texte) qui sera
            // parsée en deadlineDate par ScrapedResourceListener.
            $scraped->setPublishedAt($opp->publishedAt);

            // ADR-0016 Lot 1 : champs LLM enrichis (city, country, experienceLevel)
            // Ces champs sont remplis uniquement quand le DTO provient de LlmExtractorService.
            // Pour les scrapers RSS, ils restent à null (valeur par défaut de l'entité).
            $this->updateEnrichedFields($scraped, $opp);

            // Status = pending par défaut (valeur initiale définie dans ScrapedResource)

            // persist() ajoute l'entité dans l'Unit of Work de Doctrine.
            // L'INSERT SQL réel est exécuté lors du flush() à la fin du batch.
            $this->em->persist($scraped);
            $inserted++;

            // On mémorise l'URL insérée pour que l'appelant puisse cibler
            // l'enrichissement LLM uniquement sur les nouvelles entrées (AV-4).
            if ($opp->url !== '') {
                $insertedUrls[] = $opp->url;
            }
        }

        // ── Flush unique ──────────────────────────────────────────────────────
        // Toutes les insertions, réactivations et mises à jour sont envoyées
        // en une seule transaction. Plus efficace et plus sûr qu'un flush par item
        // (si un item échoue, toute la transaction est rollbackée).
        $this->em->flush();

        return new PersistResult(
            inserted:     $inserted,
            reactivated:  $reactivated,
            updated:      $updated,
            skipped:      $skipped,
            // Liste des URLs effectivement insérées (Cas 1), pour ciblage --enrich (AV-4)
            insertedUrls: $insertedUrls,
            // Doublons de contenu ignorés (même titre+deadline, URLs différentes) — ADR-0016 Lot 2
            contentDedup: $contentDedup,
        );
    }

    /**
     * Tente de parser la deadline string du DTO en DateTimeImmutable.
     *
     * Le DTO ScrapedOpportunity::$deadline est une chaîne libre (format variable).
     * Pour la déduplication par contenu, on a besoin d'une date structurée.
     *
     * ── CORRECTION A1 ────────────────────────────────────────────────────────────
     * On délègue désormais à DeadlineParserService::parse() qui supporte les 3 formats :
     *   1. ISO 8601 court : "YYYY-MM-DD" (ex: "2026-09-30")
     *   2. Français court : "JJ/MM/AAAA" (ex: "30/09/2026")
     *   3. Français long  : "JJ mois AAAA" (ex: "30 septembre 2026")
     *
     * Avant ce correctif, seuls les formats 1 et 2 étaient tentés ici.
     * Le format long "30 septembre 2026" retournait null → clé = "titre|" (sans date).
     * Conséquence : deux opportunités identiques avec deadline "30 septembre 2026"
     * (URLs différentes) n'étaient PAS reconnues comme doublons → double insertion.
     *
     * Retourne null si la deadline est vide, inconnue, ou dans un format non reconnu.
     * Dans ce cas, la clé de dédup sera "titleNorm|" (sans date) — voir persistBatch().
     */
    private function parseDtoDeadline(string $deadline): ?\DateTimeImmutable
    {
        // Délégation à DeadlineParserService::parse() — gère les 3 formats (A1).
        // parse() retourne null si la deadline est vide, tiret, ou format inconnu.
        // Elle ne lève jamais d'exception (contrat de DeadlineParserService).
        return $this->deadlineParser->parse($deadline);
    }

    /**
     * Met à jour les champs enrichis d'une ScrapedResource depuis un ScrapedOpportunity.
     *
     * Couvre :
     *   - ADR-0016 Lot 1 : city, country, experienceLevel
     *   - ADR-0018      : howToApply, fundingAmount, fundingType
     *
     * Règle d'écrasement :
     *   On n'écrase que si la nouvelle valeur du DTO est non vide.
     *   Raison : les scrapers RSS n'ont pas ces champs (ils restent à '' dans le DTO).
     *   On ne veut pas effacer une valeur LLM existante avec une chaîne vide.
     *
     * Cette méthode est appelée :
     *   - À l'insertion (Cas 1) : remplit les champs vides de la nouvelle entité.
     *   - À la réactivation (Cas 2) : met à jour si des nouvelles données sont disponibles.
     *   - À la mise à jour (Cas 3 & 4) : même logique.
     *
     * Conversion ExperienceLevel :
     *   Le DTO stocke la backed value string (ex: "beginner").
     *   On utilise ExperienceLevel::tryFrom() pour convertir en enum nullable.
     *   Si la valeur est vide ou invalide → null (tous niveaux / non précisé).
     */
    private function updateEnrichedFields(ScrapedResource $entity, ScrapedOpportunity $opp): void
    {
        // ── ADR-0016 Lot 1 : champs géo + niveau ─────────────────────────────

        // Ville : on ne met à jour que si la nouvelle valeur est non vide.
        // Troncature défensive à 150 chars : évite une violation de contrainte Doctrine
        // si la valeur issue du DTO n'avait pas encore été tronquée en amont
        // (ex : DTO construit manuellement dans les tests ou par un futur parseur RSS).
        // En production normale, LlmExtractorService tronque déjà avant le DTO,
        // mais une double protection ne coûte rien et garantit l'invariant en BDD.
        if ($opp->city !== '') {
            $entity->setCity(mb_substr($opp->city, 0, 150));
        }

        // Pays : même logique, colonne limitée à 100 caractères.
        if ($opp->country !== '') {
            $entity->setCountry(mb_substr($opp->country, 0, 100));
        }

        // Niveau d'expérience : conversion string → enum nullable
        // ExperienceLevel::tryFrom() retourne null si la valeur est vide ou invalide.
        // On ne met à jour que si le DTO fournit une valeur valide.
        if ($opp->experienceLevel !== '') {
            $level = ExperienceLevel::tryFrom($opp->experienceLevel);
            // tryFrom retourne null si la valeur n'est pas un case valide de l'enum
            // → pas d'exception levée, juste ignoré si invalide.
            if ($level !== null) {
                $entity->setExperienceLevel($level);
            }
        }

        // ── ADR-0018 : modalités de candidature + financement ─────────────────

        // Modalités de candidature (TEXT — pas de limite serrée, mais troncature
        // défensive à 8 000 chars identique à LlmExtractorService).
        if ($opp->howToApply !== '') {
            $entity->setHowToApply(mb_substr($opp->howToApply, 0, 8000));
        }

        // Montant du financement — tronqué à 255 chars (limite colonne BDD).
        if ($opp->fundingAmount !== '') {
            $entity->setFundingAmount(mb_substr($opp->fundingAmount, 0, 255));
        }

        // Nature du financement — tronqué à 255 chars.
        if ($opp->fundingType !== '') {
            $entity->setFundingType(mb_substr($opp->fundingType, 0, 255));
        }

        // ── ADR-0019 : lien de candidature + logo ────────────────────────────
        //
        // Règle d'écrasement identique aux autres champs :
        //   - On n'écrase jamais une valeur existante avec une chaîne vide.
        //   - Les scrapers RSS ne renseignent pas ces champs ('' par défaut dans DTO).
        //   - Troncature défensive à 500 chars (limite colonne BDD définie dans l'entité).
        //
        // applicationUrl : URL du bouton "Candidater" extraite par le LLM.
        //   Garde anti-hallucination appliquée en amont (LlmExtractorService +
        //   OpportunityEnrichmentService) : l'URL est déjà validée, mais on tronque
        //   par prudence en cas de DTO construit manuellement.
        if ($opp->applicationUrl !== '') {
            $entity->setApplicationUrl(mb_substr($opp->applicationUrl, 0, 500));
        }

        // logoUrl : URL du logo de l'organisme, récupérée par LogoFetcherService.
        //   Pas de validation sémantique ici : le fetcher vérifie déjà le SSRF
        //   et retourne uniquement des URLs HTTP(s) issues du HTML de la page d'accueil.
        if ($opp->logoUrl !== '') {
            $entity->setLogoUrl(mb_substr($opp->logoUrl, 0, 500));
        }
    }
}
