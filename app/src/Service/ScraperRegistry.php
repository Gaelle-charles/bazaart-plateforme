<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Scraper\AbstractScraper;
use App\Service\Scraper\AdagpScraper;
use App\Service\Scraper\CnapScraper;
use App\Service\Scraper\CnmScraper;
use App\Service\Scraper\CultureGouvScraper;
use App\Service\Scraper\CultureMovesEuropeScraper;
use App\Service\Scraper\MusiquesActuellesScraper;
use App\Service\Scraper\OnTheMoveScraper;
use App\Service\Scraper\ProHelvetiaScraper;
use App\Service\Scraper\ResartisScraper;
use App\Service\Scraper\SaifScraper;

/**
 * ScraperRegistry — Annuaire des scrapers custom indexés par slug.
 *
 * Ce service fait le lien entre le slug stocké en BDD (champ scraperSlug
 * de ScrapingSource) et la classe PHP correspondante.
 *
 * Fonctionnement :
 *   - Quand une ScrapingSource a un scraperSlug renseigné, la commande
 *     interroge ce registry pour obtenir l'instance du scraper.
 *   - Si le slug est inconnu → erreur visible dans l'admin (pas d'exception silencieuse).
 *   - Si scraperSlug = null → pas de passage par le registry → GenericScraper prend le relais.
 *
 * POUR AJOUTER UN NOUVEAU SCRAPER CUSTOM :
 *   1. Créer la classe dans App\Service\Scraper\ qui étend AbstractScraper
 *   2. L'injecter dans le constructeur ici
 *   3. Ajouter l'entrée dans $this->scrapers avec son slug
 *   4. Ajouter une ligne dans SeedScrapingSourcesCommand avec ce slug
 *
 * Pourquoi un registry plutôt que des tags Symfony ?
 *   → Plus explicite : on voit d'un coup d'œil tous les scrapers disponibles.
 *   → Pas de "magie" cachée : la correspondance slug → classe est lisible ici.
 *   → Validation simple : getKnownSlugs() permet à l'admin de voir les slugs valides.
 */
class ScraperRegistry
{
    /**
     * Map slug → instance de scraper.
     * La clé (slug) correspond EXACTEMENT à ScrapingSource.scraperSlug en BDD.
     *
     * @var array<string, AbstractScraper>
     */
    private array $scrapers;

    public function __construct(
        AdagpScraper $adagp,                       // adagp.fr — arts graphiques et plastiques
        CnapScraper $cnap,                         // cnap.fr — Centre National des Arts Plastiques
        CnmScraper $cnm,                           // cnm.fr — Centre National de la Musique (RSS)
        CultureGouvScraper $cultureGouv,           // culture.gouv.fr — DÉSACTIVÉ (JS/Algolia), conservé dans le registry
        CultureMovesEuropeScraper $cultureMoves,   // eacea.ec.europa.eu — Creative Europe UE (LLM)
        MusiquesActuellesScraper $musiquesActuelles, // musiquesactuelles.fr — musique FR (RSS)
        OnTheMoveScraper $onTheMove,               // on-the-move.org — mobilité internationale (LLM)
        ProHelvetiaScraper $proHelvetia,           // prohelvetia.ch — Fondation suisse culture (RSS)
        ResartisScraper $resartis,                 // resartis.org — résidences mondiales (RSS+LLM)
        SaifScraper $saif,                         // saif.fr — Auteurs arts visuels et image fixe
    ) {
        // La clé doit correspondre EXACTEMENT à scraperSlug en BDD.
        // IMPORTANT : si tu ajoutes une classe, ajoute son slug ici ET dans SeedScrapingSourcesCommand.
        $this->scrapers = [
            'adagp'              => $adagp,
            'cnap'               => $cnap,
            'cnm'                => $cnm,
            'culture-gouv'       => $cultureGouv,
            'culture-moves-eu'   => $cultureMoves,
            'musiques-actuelles' => $musiquesActuelles,
            'on-the-move'        => $onTheMove,
            'prohelvetia'        => $proHelvetia,
            'resartis'           => $resartis,
            'saif'               => $saif,
        ];
    }

    /**
     * Retourne le scraper custom associé à un slug.
     *
     * Retourne null si le slug n'est pas connu — la commande traitera ce cas
     * comme une erreur visible dans l'admin (pas une exception silencieuse).
     *
     * @param string $slug Slug du scraper (ex: "cnap", "on-the-move")
     */
    public function getBySlug(string $slug): ?AbstractScraper
    {
        return $this->scrapers[$slug] ?? null;
    }

    /**
     * Retourne la liste de tous les slugs connus.
     *
     * Utilisé par :
     *   - AdminScrapingSourceController : validation du slug soumis par l'admin
     *   - ScrapeOpportunitiesCommand : message d'erreur si slug inconnu
     *
     * @return string[]
     */
    public function getKnownSlugs(): array
    {
        return array_keys($this->scrapers);
    }
}
