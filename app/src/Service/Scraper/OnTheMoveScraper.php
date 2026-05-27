<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OnTheMoveScraper — Scrape les deadlines de mobilité internationale sur On The Move.
 *
 * On The Move (on-the-move.org) est un réseau culturel européen majeur spécialisé
 * dans la mobilité internationale des artistes. Il centralise des offres de résidences,
 * bourses et aides à la mobilité du monde entier — très pertinent pour les artistes
 * de la diaspora afro-atlantique qui cherchent à développer une carrière internationale.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * HISTORIQUE DES APPROCHES PRÉCÉDENTES
 * ────────────────────────────────────────────────────────────────────────────────
 * v1 : RSS /calls/rss.xml → retournait HTTP 404 depuis Docker (flux supprimé)
 * v2 : Fallback LLM (claude-haiku-4-5) sur /calls → 0 résultat (LLM API instable,
 *      trop coûteux en quota, et la page /calls était vide)
 *
 * v3 (actuelle) : Scraping CSS pur sur /news/deadlines
 * ────────────────────────────────────────────────────────────────────────────────
 * STRATÉGIE ACTUELLE
 * ────────────────────────────────────────────────────────────────────────────────
 * La page /news/deadlines (HTTP 200, ~98KB) liste les appels à candidature
 * avec leurs deadlines. Structure HTML stable (confirmée en mai 2026) :
 *
 *   <div class="views-field views-field-nothing">
 *     <span class="field-content">
 *       <a href="/news/[slug]" class="lit under">Titre de l'opportunité</a>
 *       <div class='st'>
 *         Deadline: <time datetime="2026-06-15T12:00:00Z" class="datetime">15 Jun 2026</time>
 *       </div>
 *     </span>
 *   </div>
 *
 * On cible le sélecteur `a.lit.under` (classe "lit" ET "under") pour les titres.
 * Le <time> dans le même .field-content donne la deadline exacte en attribut datetime.
 *
 * Avantages par rapport au LLM :
 *   - Pas de quota API consommé
 *   - Pas de dépendance sur LlmExtractorService / AfrodiasporaRelevanceScorer
 *   - Résultats déterministes et plus rapides
 *   - Plus facile à déboguer
 */
class OnTheMoveScraper extends AbstractScraper
{
    /**
     * URL de la page des deadlines d'appels à candidature.
     *
     * On cible /news/deadlines (et non /calls ni /grants qui sont morts ou vides).
     * Cette page est mise à jour régulièrement par l'équipe On The Move.
     */
    private const DEADLINES_URL = 'https://on-the-move.org/news/deadlines';

    /**
     * URL de base du site pour construire les URLs absolues.
     * Les hrefs dans la page sont relatifs : /news/[slug]
     */
    private const BASE_URL = 'https://on-the-move.org';

    /**
     * Constructeur simplifié — plus besoin de LlmExtractorService ni de AfrodiasporaRelevanceScorer.
     *
     * On ne garde que HttpClientInterface (injecté au parent) car le scraping est
     * désormais 100% CSS, sans appel LLM.
     *
     * Convention Symfony : le parent AbstractScraper attend HttpClientInterface,
     * donc on appelle parent::__construct($httpClient).
     */
    public function __construct(
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($httpClient);
    }

    public function getName(): string
    {
        return 'On The Move - Réseau de mobilité internationale';
    }

    public function getTestUrl(): string
    {
        // L'URL de test pointe désormais vers /news/deadlines (et non /calls)
        return self::DEADLINES_URL;
    }

    /**
     * Scrape les opportunités de mobilité depuis /news/deadlines.
     *
     * Algorithme :
     *   1. Fetch() la page → DomCrawler
     *   2. Pour chaque lien a.lit.under → titre + href relatif
     *   3. Dans le même .field-content, chercher le <time class="datetime">
     *      → attribut datetime contient la date ISO (ex: "2026-06-15T12:00:00Z")
     *   4. Construire l'URL absolue : BASE_URL + href
     *   5. Créer un ScrapedOpportunity
     *
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        try {
            // fetch() est définie dans AbstractScraper — retourne un Crawler ou null
            $crawler = $this->fetch(self::DEADLINES_URL);

            if ($crawler === null) {
                // La page était inaccessible (timeout, 404, erreur réseau...)
                return [];
            }

            $opportunities = [];

            // ── Sélecteur principal : a.lit.under ────────────────────────────────
            // Ces liens ont les classes CSS "lit" ET "under" — c'est la façon dont
            // le CMS de on-the-move.org marque les titres cliquables des opportunités.
            // Chaque a.lit.under est le titre d'un appel à candidature.
            $crawler->filter('a.lit.under')->each(
                function ($linkNode) use (&$opportunities): void {
                    // ── Titre ─────────────────────────────────────────────────────
                    $title = $this->cleanText($linkNode->text());

                    // Ignorer les liens sans texte (ne devrait pas arriver, mais sécurité)
                    if (empty($title)) {
                        return;
                    }

                    // ── href relatif ──────────────────────────────────────────────
                    // Le href est de la forme /news/[slug] — on le convertit en absolu
                    $href = $linkNode->attr('href') ?? '';
                    if (empty($href)) {
                        return;
                    }
                    $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

                    // ── Deadline ──────────────────────────────────────────────────
                    // On remonte dans le DOM au parent .field-content pour trouver
                    // le <time class="datetime"> qui contient la deadline.
                    //
                    // Pourquoi closest('.field-content') ?
                    //   Le a.lit.under et le <time> sont des frères dans le même
                    //   span.field-content. On remonte au conteneur commun pour
                    //   éviter de prendre la date d'un autre article.
                    $deadline = '';
                    try {
                        // Remonte au conteneur parent .field-content pour isoler la deadline
                        // de cet article (et non celle d'un article voisin).
                        // closest() est disponible dans symfony/dom-crawler >= 5.4 (v7.4 installée ici).
                        $fieldContent = $linkNode->closest('.field-content');

                        if ($fieldContent !== null) {
                            // Chercher le <time class="datetime"> dans ce conteneur
                            $timeNode = $fieldContent->filter('time.datetime');

                            if ($timeNode->count() > 0) {
                                // L'attribut datetime contient la date ISO : "2026-06-15T12:00:00Z"
                                // On la convertit en format français "dd/mm/yyyy"
                                $datetimeAttr = $timeNode->first()->attr('datetime') ?? '';
                                if (!empty($datetimeAttr)) {
                                    try {
                                        $date     = new \DateTime($datetimeAttr);
                                        $deadline = $date->format('d/m/Y');
                                    } catch (\Exception) {
                                        // Si la date est mal formée, on laisse $deadline = ''
                                        // plutôt que de planter le scraper entier
                                        $deadline = '';
                                    }
                                }
                            }
                        }
                    } catch (\Exception) {
                        // Le DOM traversal peut parfois échouer — on continue sans deadline
                        $deadline = '';
                    }

                    // ── Détection du type d'opportunité ──────────────────────────
                    // On analyse le titre pour deviner s'il s'agit d'une résidence,
                    // d'une bourse, d'un prix... (même logique que dans AbstractRssScraper)
                    $type = $this->detectType($title);

                    $opportunities[] = new ScrapedOpportunity(
                        title:        $title,
                        type:         $type,
                        url:          $absoluteUrl,
                        source:       'on-the-move.org',
                        description:  '',   // Pas de description sur la page de liste
                        deadline:     $deadline,
                        disciplines:  'Mobilité internationale, Toutes disciplines',
                        documents:    '',
                        relevanceScore: 0,  // Score par défaut (pas de scorer LLM ici)
                    );
                }
            );

            return $opportunities;

        } catch (\Exception) {
            // Convention : un scraper ne lève JAMAIS d'exception vers l'extérieur.
            // Toute erreur imprévue retourne silencieusement un tableau vide.
            return [];
        }
    }

    /**
     * Détecte le type d'opportunité à partir du titre.
     *
     * On cherche des mots-clés en minuscules pour être insensible à la casse.
     * On couvre aussi les mots anglais car On The Move est un site international.
     *
     * Remarque : on ne dépend plus d'AfrodiasporaRelevanceScorer ici.
     * Le relevanceScore restera à 0 — la pertinence sera évaluée côté admin
     * lors de la validation manuelle des ressources importées.
     */
    private function detectType(string $text): string
    {
        $text = mb_strtolower($text);

        // Résidence / mobility (les deux concepts sont proches pour On The Move)
        if (str_contains($text, 'résidence') || str_contains($text, 'residenc') || str_contains($text, 'mobility') || str_contains($text, 'mobilité')) {
            return 'Résidence';
        }

        // Bourse
        if (str_contains($text, 'bourse') || str_contains($text, 'grant') || str_contains($text, 'scholarship') || str_contains($text, 'fellowship')) {
            return 'Bourse';
        }

        // Prix
        if (str_contains($text, 'prix') || str_contains($text, 'award') || str_contains($text, 'prize')) {
            return 'Prix';
        }

        // Financement
        if (str_contains($text, 'financement') || str_contains($text, 'soutien') || str_contains($text, 'funding') || str_contains($text, 'support')) {
            return 'Financement';
        }

        // Par défaut : appel à projets
        return 'Appel à projets';
    }
}
