<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;
use App\Service\AfrodiasporaRelevanceScorer;
use App\Service\LlmExtractorService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CultureMovesEuropeScraper — Scrape les financements culturels de la Commission européenne.
 *
 * Ce scraper cible la Direction générale de l'Éducation, de la Jeunesse, du Sport
 * et de la Culture (DG EAC) de la Commission européenne, qui gère le programme
 * Creative Europe / Culture.
 *
 * HISTORIQUE DES URLS TESTÉES
 * ────────────────────────────────────────────────────────────────────────────────
 * v1 : culturemoveseurope.eu/opportunities/ → DNS introuvable depuis Docker
 *      ("Name or service not known") — le domaine n'existe plus
 * v2 : eacea.ec.europa.eu/grants_en → HTTP 404 depuis Docker (mai 2026)
 *      L'EACEA a restructuré ses URL, cette page n'existe plus
 * v3 (actuelle) : culture.ec.europa.eu/fr/funding → HTTP 200, 179KB ✓
 *      Page des financements de la DG Culture — mise à jour régulièrement
 *
 * C'est une source majeure pour les artistes de la diaspora résidant en Europe :
 *   - Subventions Creative Europe / Culture
 *   - Bourses de mobilité individuelle
 *   - Projets de coopération culturelle transfrontaliers
 *   - Résidences financées dans les pays de l'UE
 *
 * Stratégie :
 *   Le site culture.ec.europa.eu est un site institutionnel statique (pas de SPA JS).
 *   Le HTML brut contient les appels à propositions → extraction via LLM.
 *   Pas de flux RSS public connu sur cette section.
 */
class CultureMovesEuropeScraper extends AbstractScraper
{
    /**
     * Page des financements culturels de la Commission européenne (Creative Europe / Culture).
     *
     * Historique :
     *   - culturemoveseurope.eu/opportunities/ : DNS mort
     *   - eacea.ec.europa.eu/grants_en : HTTP 404 depuis mai 2026
     *   - culture.ec.europa.eu/fr/funding : HTTP 200 ✓ (URL actuelle)
     */
    private const OPPORTUNITIES_URL = 'https://culture.ec.europa.eu/fr/funding';

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly LlmExtractorService $llmExtractor,
        private readonly AfrodiasporaRelevanceScorer $relevanceScorer,
    ) {
        parent::__construct($httpClient);
    }

    public function getName(): string
    {
        // On utilise le nom du domaine + programme pour que les opportunités importées
        // soient identifiables dans l'interface admin.
        // Remplace l'ancien nom 'eacea.ec.europa.eu (Creative Europe)' qui pointait
        // vers un domaine désormais 404.
        return 'culture.ec.europa.eu (Creative Europe - Culture)';
    }

    public function getTestUrl(): string
    {
        // Pointe désormais sur culture.ec.europa.eu/fr/funding (et non plus eacea)
        return self::OPPORTUNITIES_URL;
    }

    /**
     * Scrape les opportunités depuis la page HTML + LLM.
     *
     * culture.ec.europa.eu est un site institutionnel statique (pas de SPA JavaScript),
     * donc le HTML brut (~179KB) contient les appels à propositions directement dans la page.
     * Le LLM extrait les données structurées depuis ce HTML brut.
     *
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        try {
            // Récupérer le HTML de la page des opportunités
            $html = $this->fetchHtml(self::OPPORTUNITIES_URL);

            if (empty($html)) {
                // Le site était inaccessible — le scraper retourne silencieusement []
                return [];
            }

            // Déléguer l'extraction au LLM (claude-haiku-4-5)
            // Le LLM comprend la structure de la page et extrait les champs structurés
            $opportunities = $this->llmExtractor->extractFromHtml(
                $html,
                self::OPPORTUNITIES_URL,
                $this->getName()
            );

            // Recalculer le score Afrodiaspora pour chaque opportunité.
            // Le LLM met relevanceScore = 0 par défaut (convention dans ScrapedOpportunity).
            // AfrodiasporaRelevanceScorer fait une analyse lexicale du titre + description.
            return array_map(function (ScrapedOpportunity $opp): ScrapedOpportunity {
                $score = $this->relevanceScorer->score($opp->title, $opp->description);

                // On reconstruit un nouveau DTO avec le score calculé
                // (les DTOs sont readonly, donc on ne peut pas modifier en place)
                return new ScrapedOpportunity(
                    title: $opp->title,
                    type: $opp->type,
                    url: $opp->url,
                    source: $opp->source,
                    description: $opp->description,
                    deadline: $opp->deadline,
                    disciplines: $opp->disciplines,
                    documents: $opp->documents,
                    relevanceScore: $score,
                );
            }, $opportunities);

        } catch (\Exception) {
            // Sécurité : un scraper ne doit JAMAIS faire planter la commande principale.
            // Toute exception est avalée et on retourne silencieusement [].
            return [];
        }
    }
}
