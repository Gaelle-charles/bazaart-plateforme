<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * SaifScraper — Scrape les bourses et prix de la SAIF.
 *
 * SAIF = Société des Auteurs des arts visuels et de l'Image Fixe
 * Site : https://www.saif.fr
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * HISTORIQUE DES URLs TESTÉES
 * ────────────────────────────────────────────────────────────────────────────────
 * Anciennes URLs (retournaient 404 ou page vide) :
 *   - /fr/bourses-et-prix           → chemin inexistant sur le site actuel
 *   - /fr/aides-a-la-creation       → chemin inexistant sur le site actuel
 *
 * Structure réelle du site saif.fr (mai 2026) :
 *   Le site utilise un chemin /soutien-a-la-creation/ pour toute la section aides.
 *   Les URLs correctes sont :
 *     - /soutien-a-la-creation/les-bourses-de-la-saif/   → HTTP 200, 136KB ✓
 *     - /soutien-a-la-creation/les-prix-de-la-saif/      → à vérifier, inclus par précaution
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * BOURSES CONNUES DE LA SAIF (2026)
 * ────────────────────────────────────────────────────────────────────────────────
 *   - Bourse du Talent SAIF (4 000€) → photographes émergents
 *   - Prix Camille Lepage (8 000€)   → photojournalisme
 *   - Prix SAIF Les Femmes s'exposent (3 000€) → femmes photographes
 *   - Bourse Benoît Schaeffer (10 000€) → livre photo
 *
 * STRATÉGIE DE SCRAPING
 * ────────────────────────────────────────────────────────────────────────────────
 * Les pages de bourses/prix sont statiques (pas de JS bundle comme /fr/actualites).
 * On cherche tous les liens dont le texte contient les mots-clés bourse/prix/appel.
 * Les liens vers des PDFs sont ignorés (pas scrappables).
 */
class SaifScraper extends AbstractScraper
{
    private const BASE_URL = 'https://www.saif.fr';

    /**
     * Pages statiques des bourses et prix de la SAIF.
     *
     * Correction mai 2026 : les anciennes URLs /fr/bourses-et-prix et
     * /fr/aides-a-la-creation retournaient 404. Les vraies URLs utilisent
     * le préfixe /soutien-a-la-creation/.
     */
    private const PAGES = [
        'https://www.saif.fr/soutien-a-la-creation/les-bourses-de-la-saif/',
        'https://www.saif.fr/soutien-a-la-creation/les-prix-de-la-saif/',
    ];

    /**
     * Mots-clés pour ne garder que les liens d'opportunités.
     * On cible les bourses, prix, appels à candidatures, aides directes.
     */
    private const KEYWORDS = [
        'bourse', 'prix', 'appel', 'candidature', 'aide', 'résidence', 'soutien',
    ];

    public function getName(): string
    {
        return 'SAIF - Auteurs arts visuels et image fixe';
    }

    public function getTestUrl(): string
    {
        // Pointe sur la page des bourses (ancienne URL /fr/bourses-et-prix était 404)
        return self::PAGES[0];
    }

    /**
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        $opportunities = [];

        // $seenUrls évite les doublons si un même lien apparaît sur plusieurs pages
        $seenUrls = [];

        foreach (self::PAGES as $pageUrl) {
            $crawler = $this->fetch($pageUrl);

            if ($crawler === null) {
                // La page est inaccessible (404, timeout...) → on continue avec la suivante
                continue;
            }

            $crawler->filter('a[href]')->each(
                function ($linkNode) use (&$opportunities, &$seenUrls): void {
                    $href  = $linkNode->attr('href') ?? '';
                    $title = $this->cleanText($linkNode->text());

                    // ── Filtres d'exclusion rapides ───────────────────────────────

                    // Ignorer les liens vides ou trop courts (navigation, icônes...)
                    if (empty($title) || strlen($title) < 10) {
                        return;
                    }

                    // Ignorer les ancres, emails et liens téléphoniques
                    if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                        return;
                    }

                    // Ignorer les PDFs — ils ne sont pas scrappables comme pages web
                    // (les PDFs contiennent les règlements mais pas une page d'opportunité)
                    if (str_contains(mb_strtolower($href), '.pdf')) {
                        return;
                    }

                    $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

                    // Ignorer les URLs externes (hors domaine saif.fr)
                    // On utilise parse_url() sur le host plutôt que str_contains() sur l'URL complète
                    // pour éviter les faux positifs (ex: ?redirect=saif.fr dans un lien externe)
                    $host = parse_url($absoluteUrl, PHP_URL_HOST) ?? '';
                    if (!str_ends_with($host, 'saif.fr')) {
                        return;
                    }

                    // Déduplication
                    if (isset($seenUrls[$absoluteUrl])) {
                        return;
                    }
                    $seenUrls[$absoluteUrl] = true;

                    // ── Filtre par mots-clés ──────────────────────────────────────
                    // On garde uniquement les liens dont le texte parle d'opportunités
                    $titleLower = mb_strtolower($title);
                    $isRelevant = false;
                    foreach (self::KEYWORDS as $keyword) {
                        if (str_contains($titleLower, $keyword)) {
                            $isRelevant = true;
                            break;
                        }
                    }

                    if (!$isRelevant) {
                        return;
                    }

                    // ── Détection du type ─────────────────────────────────────────
                    // "prix" → Prix, sinon → Bourse / Aide
                    $type = str_contains($titleLower, 'prix') ? 'Prix' : 'Bourse / Aide';

                    $opportunities[] = new ScrapedOpportunity(
                        title:        $title,
                        type:         $type,
                        url:          $absoluteUrl,
                        source:       'saif.fr',
                        description:  '',
                        deadline:     '',
                        disciplines:  'Photographie, Arts visuels, Illustration',
                        documents:    '',
                        relevanceScore: 0,
                    );
                }
            );
        }

        return $opportunities;
    }
}
