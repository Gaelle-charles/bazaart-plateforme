<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\DTO\ScrapedOpportunity;

/**
 * AdagpScraper — Scrape les actualités de l'ADAGP.
 *
 * ADAGP = Société des auteurs dans les arts graphiques et plastiques
 * Page : https://www.adagp.fr/fr/actualites
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * STRUCTURE HTML RÉELLE (confirmée par debug en mai 2026)
 * ────────────────────────────────────────────────────────────────────────────────
 * La page /fr/actualites contient des balises <article> (9 trouvées lors du debug).
 * Chaque article a cette structure :
 *
 *   <article>
 *     <a href="/fr/actualites/[slug]">... image ...</a>       ← lien image (pas de texte)
 *     <h3><a href="/fr/actualites/[slug]">Titre de l'article</a></h3>   ← titre
 *     ...
 *   </article>
 *
 * POURQUOI L'ANCIENNE VERSION RETOURNAIT 0 RÉSULTAT ?
 *   L'ancienne version ciblait `a[href*="/fr/actualites/"]` directement — ce qui
 *   récupérait bien les liens, mais le texte du lien image était vide (< 10 chars),
 *   et le filtre de mots-clés sur les textes courts rejetait tout.
 *   Solution : cibler `h3 a` à l'intérieur de chaque article pour avoir le vrai titre.
 *
 * STRATÉGIE ACTUELLE
 * ────────────────────────────────────────────────────────────────────────────────
 * 1. Parcourir chaque <article> avec ->each()
 * 2. Dans chaque article, chercher d'abord `h3 a` (titre principal)
 * 3. Si pas de h3, chercher tout lien vers /fr/actualites/ avec un texte long (> 10 chars)
 * 4. Appliquer le filtre par mots-clés sur le titre récupéré
 *
 * On ne scrape que la première page pour l'instant — la page 2 (?page=1) peut parfois
 * échouer avec un code HTTP non-200 selon la pagination Drupal du site.
 */
class AdagpScraper extends AbstractScraper
{
    private const BASE_URL = 'https://www.adagp.fr';

    /**
     * Pages à scraper.
     *
     * On garde les deux pages mais la logique est robuste : si la page 2 est
     * inaccessible, la page 1 est déjà traitée et retourne ses résultats.
     */
    private const PAGES = [
        'https://www.adagp.fr/fr/actualites',
        'https://www.adagp.fr/fr/actualites?page=1',
    ];

    /**
     * Mots-clés pour ne garder que les opportunités (appels, bourses, résidences...).
     * On écarte les articles de presse, communiqués et actualités générales.
     */
    private const KEYWORDS = [
        'appel', 'candidature', 'bourse', 'résidence', 'prix',
        'aide', 'soutien', 'subvention', 'financement', 'label',
        // Note : 'appel à' et 'appels à' sont inutiles — str_contains('appel') les captures déjà
    ];

    public function getName(): string
    {
        return 'ADAGP - Arts graphiques et plastiques';
    }

    public function getTestUrl(): string
    {
        return self::PAGES[0];
    }

    /**
     * @return ScrapedOpportunity[]
     */
    public function scrape(): array
    {
        $opportunities = [];

        // $seenUrls évite les doublons entre pages 1 et 2
        // (un article peut apparaître en bas de page 1 ET en haut de page 2)
        $seenUrls = [];

        foreach (self::PAGES as $pageUrl) {
            $crawler = $this->fetch($pageUrl);
            if ($crawler === null) {
                // Page inaccessible → on passe à la suivante silencieusement
                continue;
            }

            // ── Parcourir chaque <article> ────────────────────────────────────
            // On travaille article par article pour s'assurer d'associer le bon
            // titre à la bonne URL (évite les confusions entre articles adjacents).
            $crawler->filter('article')->each(
                function ($articleNode) use (&$opportunities, &$seenUrls): void {
                    // ── Étape 1 : trouver le lien titre ──────────────────────────
                    // Priorité au h3 a (titre sémantique), sinon tout lien vers /fr/actualites/
                    $titleLink = $articleNode->filter('h3 a');

                    if ($titleLink->count() === 0) {
                        // Pas de h3 → on cherche n'importe quel lien vers une page d'article
                        $titleLink = $articleNode->filter('a[href*="/fr/actualites/"]');
                    }

                    if ($titleLink->count() === 0) {
                        // Aucun lien trouvé dans cet article → on passe
                        return;
                    }

                    // On prend le premier lien correspondant (en cas de doublons dans l'article)
                    $firstLink = $titleLink->first();

                    $href  = $firstLink->attr('href') ?? '';
                    $title = $this->cleanText($firstLink->text());

                    // ── Étape 2 : validation basique ──────────────────────────────
                    // Ignorer les liens sans texte (liens image) ou texte trop court
                    if (empty($href) || strlen($title) < 10) {
                        return;
                    }

                    // Ignorer "En savoir plus" et variantes (répétitif, pas informatif)
                    if (str_contains(mb_strtolower($title), 'en savoir plus')) {
                        return;
                    }

                    $absoluteUrl = $this->absoluteUrl($href, self::BASE_URL);

                    // ── Étape 3 : déduplication ───────────────────────────────────
                    // Un même article peut avoir le même lien plusieurs fois dans le DOM
                    if (isset($seenUrls[$absoluteUrl])) {
                        return;
                    }
                    $seenUrls[$absoluteUrl] = true;

                    // ── Étape 4 : filtrage par mots-clés ─────────────────────────
                    // On ne garde que les articles qui parlent d'opportunités (pas les
                    // actualités générales sur les droits d'auteur ou les partenariats)
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

                    // ── Étape 5 : créer l'opportunité ────────────────────────────
                    $opportunities[] = new ScrapedOpportunity(
                        title:        $title,
                        type:         'Bourse / Aide',
                        url:          $absoluteUrl,
                        source:       'adagp.fr',
                        description:  '',   // La description détaillée est sur la page de l'article
                        deadline:     '',   // Pas de deadline visible sur la liste
                        disciplines:  'Arts plastiques, Photographie, Illustration',
                        documents:    '',
                        relevanceScore: 0,
                    );
                }
            );
        }

        return $opportunities;
    }
}
