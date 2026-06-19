<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScrapedResource;
use App\Entity\ScrapingSource;
use App\Enum\ScrapedResourceStatus;
use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpportunityToSourcePromoter — Transforme une opportunité scrapée en source de scraping.
 *
 * Cas d'usage :
 *   L'admin consulte une opportunité dans la file d'attente et réalise que l'organisme
 *   émetteur publie régulièrement des opportunités pertinentes pour Bazaart.
 *   Plutôt que de valider manuellement chaque opportunité future, il "promeut" l'organiseme
 *   en source de scraping : le bot visitera dorénavant le site régulièrement.
 *
 * Flux :
 *   1. Extraire la base-URL du domaine de l'opportunité (ex: "https://cnap.fr")
 *   2. Lancer ListingUrlDiscoverer::discoverForSite() pour trouver la page-liste
 *      (heuristique + fallback LLM — même logique que app:discover-listing-urls)
 *   3. Si découverte réussie → ScrapingSource créée/déjà existante
 *   4. Si découverte échouée → créer une source minimale à partir de l'URL de l'opportunité
 *      (fallback de dernier recours — l'admin pourra affiner la config plus tard)
 *   5. Dans tous les cas → archiver la ScrapedResource (status Archived)
 *      Raison : l'opportunité sera désormais couverte par la source scrapée ;
 *      l'archiver évite la confusion "À vérifier" pour une URL déjà traitée.
 *
 * Résultat :
 *   Un objet PromotionResult est retourné au controller pour construire le flash message.
 *
 * Séparation des responsabilités :
 *   - Ce service orchestre uniquement ; il ne contient PAS la logique de découverte HTTP
 *     (dans ListingUrlDiscoverer) ni la logique de déduplication BDD (dans ScrapingSourceRepository).
 *   - Le controller ne doit pas appeler ListingUrlDiscoverer directement.
 */
class OpportunityToSourcePromoter
{
    public function __construct(
        // Discoverer : heuristique + LLM pour trouver la page-liste d'un site
        private readonly ListingUrlDiscoverer $discoverer,
        // Repository source : déduplication par URL exacte
        private readonly ScrapingSourceRepository $sourceRepository,
        // EntityManager : persist + flush de la ScrapingSource de fallback
        private readonly EntityManagerInterface $em,
        // Logger PSR-3 : trace sans bloquer
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Promeut une opportunité scrapée en source de scraping.
     *
     * @param ScrapedResource $scraped    L'opportunité à promouvoir
     * @return PromotionResult           Résultat décrivant ce qui a été créé/trouvé
     */
    public function promote(ScrapedResource $scraped): PromotionResult
    {
        // ── Étape 1 : récupérer l'URL de l'opportunité ───────────────────────
        // L'URL source est la référence pour déterminer le domaine à scraper.
        // Si l'URL est nulle (cas rare — opportunité sans lien externe),
        // on ne peut pas déterminer le site à scraper → on archive quand même.
        $siteUrl = $scraped->getUrl();

        if ($siteUrl === null || $siteUrl === '') {
            $this->logger->warning('[ToSource] URL nulle sur la ScrapedResource, promotion impossible.', [
                'id'    => $scraped->getId(),
                'title' => $scraped->getTitle(),
            ]);

            // On archive quand même : l'admin a manifesté l'intention de ne plus la traiter
            $scraped->setStatus(ScrapedResourceStatus::Archived);
            $this->em->flush();

            return new PromotionResult(
                sourceUrl: null,
                isNew: false,
                success: false,
                message: 'URL manquante sur l\'opportunité — impossible de déterminer le site source. Opportunité archivée.',
            );
        }

        // ── Étape 2 : déterminer le nom lisible du site ───────────────────────
        // On utilise le sourceSite de la ScrapedResource (ex: "cnap.fr") comme nom,
        // ou le domaine extrait de l'URL si sourceSite n'est pas renseigné.
        $nom = $scraped->getSourceSite() ?? $this->extractDomain($siteUrl);

        $this->logger->info('[ToSource] Lancement découverte listing URL.', [
            'id'     => $scraped->getId(),
            'site'   => $siteUrl,
            'nom'    => $nom,
        ]);

        // ── Étape 3 : découvrir la page-liste du site via ListingUrlDiscoverer ─
        // discoverForSite() teste les chemins heuristiques FR+EN puis le LLM si besoin.
        // Il persiste automatiquement la source en BDD si une page-liste est trouvée.
        // Il gère lui-même la déduplication (findByUrl) — on ne crée pas de doublon.
        //
        // dryRun: false → on veut vraiment créer la source
        $result = $this->discoverer->discoverForSite(
            siteUrl: $siteUrl,
            nomSite: $nom,
            paysZone: null,
            dryRun: false,
        );

        // ── Étape 4 : interpréter le résultat de la découverte ───────────────
        if ($result->listingUrl !== null) {
            // Cas A : une page-liste a été trouvée (heuristique ou LLM)
            // persistIfNew() dans ListingUrlDiscoverer a déjà persisté + flush non encore fait.
            // On flush ici pour s'assurer que la source est bien en BDD avant d'archiver.
            $this->em->flush();

            $isNew = ($result->sourceId !== -1); // -1 = doublon selon le contrat de DiscoveryResult

            $this->logger->info('[ToSource] Page-liste trouvée.', [
                'url'   => $result->listingUrl,
                'isNew' => $isNew,
            ]);

            // Archiver la ScrapedResource : elle est désormais couverte par la source
            $scraped->setStatus(ScrapedResourceStatus::Archived);
            $this->em->flush();

            $sourceUrl = $result->listingUrl;
            $message   = $isNew
                ? sprintf('Nouvelle source créée : %s', $sourceUrl)
                : sprintf('Source déjà existante (non dupliquée) : %s', $sourceUrl);

            return new PromotionResult(
                sourceUrl: $sourceUrl,
                isNew: $isNew,
                success: true,
                message: $message,
            );
        }

        // Cas B : la découverte n'a rien trouvé (heuristique + LLM en échec)
        // Fallback : créer une source minimale directement depuis l'URL de l'opportunité.
        // L'admin pourra affiner le type, le slug et les paramètres plus tard dans /admin/scraping-sources.
        $this->logger->warning('[ToSource] Découverte en echec, creation source de secours depuis URL directe.', [
            'url' => $siteUrl,
        ]);

        $fallbackResult = $this->createFallbackSource($siteUrl, $nom);

        // Archiver dans tous les cas
        $scraped->setStatus(ScrapedResourceStatus::Archived);
        $this->em->flush();

        return $fallbackResult;
    }

    // ════════════════════════════════════════════════════════════════════════════
    //  MÉTHODES PRIVÉES
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Crée une source de scraping de secours directement depuis l'URL de l'opportunité.
     *
     * Ce fallback est déclenché quand ListingUrlDiscoverer n'a pas trouvé de page-liste.
     * On enregistre au minimum le domaine du site pour que l'admin puisse le consulter
     * et affiner manuellement la configuration dans /admin/scraping-sources.
     *
     * Déduplication : si une source avec exactement cette URL existe déjà, on ne crée pas
     * de doublon — on retourne un résultat "déjà existante".
     *
     * @param string $url URL de l'opportunité (utilisée comme URL de source de secours)
     * @param string $nom Nom lisible du site
     */
    private function createFallbackSource(string $url, string $nom): PromotionResult
    {
        // Vérification déduplication : on ne crée pas deux sources avec la même URL
        $existing = $this->sourceRepository->findByUrl($url);

        if ($existing !== null) {
            // La source existe déjà (créée lors d'un précédent "En faire une source")
            $this->logger->info('[ToSource] Fallback : source deja en BDD.', ['url' => $url]);

            return new PromotionResult(
                sourceUrl: $url,
                isNew: false,
                success: true,
                message: sprintf('Source déjà existante (non dupliquée) : %s', $url),
            );
        }

        // Créer la source minimale
        // Type html_llm : GenericScraper tentera d'extraire les opportunités via LLM
        // depuis cette URL. L'admin peut changer le type plus tard.
        $source = new ScrapingSource();
        $source->setNom(mb_substr($nom, 0, 255));
        $source->setUrl($url);
        $source->setType(ScrapingSourceType::HtmlLlm);
        $source->setScraperSlug(null);      // Pas de scraper dédié → GenericScraper
        $source->setEstAgregateur(false);   // On ne sait pas encore si c'est un agrégateur
        $source->setActif(true);            // Active immédiatement

        $this->em->persist($source);
        // Note : flush() sera appelé par le caller (promote()) après archivage de la ScrapedResource
        // pour grouper les deux opérations en un seul flush.

        $this->logger->info('[ToSource] Fallback : source creee depuis URL directe.', [
            'url' => $url,
            'nom' => $nom,
        ]);

        return new PromotionResult(
            sourceUrl: $url,
            isNew: true,
            success: true,
            message: sprintf(
                'Page-liste introuvable — source créée depuis l\'URL de l\'opportunité : %s. '
                . 'Vérifiez la config dans /admin/scraping-sources.',
                $url
            ),
        );
    }

    /**
     * Extrait le domaine (scheme + host) d'une URL.
     *
     * Utilisé pour nommer la source quand ScrapedResource::$sourceSite est vide.
     * Retourne l'URL brute tronquée si parse_url échoue.
     *
     * Exemples :
     *   "https://www.cnap.fr/open-calls/123"  → "www.cnap.fr"
     *   "http://institutfrancais.com/"         → "institutfrancais.com"
     *
     * @param string $url URL complète
     * @return string     Nom de domaine ou URL tronquée (max 100 chars)
     */
    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);

        if (is_array($parsed) && isset($parsed['host']) && $parsed['host'] !== '') {
            return (string) $parsed['host'];
        }

        // Fallback : URL tronquée pour éviter un nom trop long
        return mb_substr($url, 0, 100);
    }
}
