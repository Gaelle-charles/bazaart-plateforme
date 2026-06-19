<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Discipline;
use App\Repository\DisciplineRepository;
use Psr\Log\LoggerInterface;

/**
 * DisciplineMapperService — Convertit les libellés texte en entités Discipline.
 *
 * Problème résolu :
 *   Le LLM retourne les disciplines sous forme de libellés texte libres
 *   (ex : "Arts plastiques", "musique", "cinema audiovisuel").
 *   Ces libellés peuvent différer légèrement des noms canoniques stockés en BDD
 *   (casse différente, accents manquants, synonymes…).
 *
 *   Ce service normalise les libellés et les fait correspondre aux entités
 *   Discipline existantes — SANS en créer de nouvelles.
 *
 * Philosophie :
 *   → On ne crée jamais de discipline inconnue (risque de doublons et de pollution).
 *   → Si aucun match n'est trouvé pour un libellé, on l'ignore silencieusement.
 *   → On log les libellés non mappés pour permettre l'amélioration des synonymes.
 *
 * Méthode de matching (ordre de priorité) :
 *   1. Correspondance exacte (après normalisation casse/accents)
 *   2. Correspondance par synonyme déclaré dans SYNONYMS
 *   3. Correspondance par sous-chaîne (le libellé LLM contient le nom canonique)
 *
 * Disciplines existantes en V1 (issues des fixtures, cf. ADR-0016) :
 *   - Musique
 *   - Cinéma & Audiovisuel
 *   - Arts visuels
 *   - Danse
 *   - Théâtre & Performance
 *   - Littérature
 *   - Arts numériques
 *   - Mode & Design
 */
class DisciplineMapperService
{
    /**
     * Table de synonymes : libellé normalisé (clé) → nom BDD attendu (valeur).
     *
     * Pourquoi une table de synonymes plutôt que la sous-chaîne seule ?
     * La sous-chaîne capture "musique" dans "Musique" mais pas "jazz" dans "Musique",
     * ni "arts visuels" dans "Arts visuels" (si le LLM écrit "arts plastiques").
     * Les synonymes couvrent les variantes les plus fréquentes des retours LLM.
     *
     * Convention des clés : tout en minuscules, sans accents (après normalizeText()).
     * Les valeurs doivent correspondre EXACTEMENT aux noms en BDD (après normalizeText()).
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        // Musique
        'musique'                   => 'musique',
        'music'                     => 'musique',
        'jazz'                      => 'musique',
        'rap'                       => 'musique',
        'chanson'                   => 'musique',
        'compositeur'               => 'musique',
        'composition'               => 'musique',

        // Cinéma & Audiovisuel
        'cinema'                    => 'cinema & audiovisuel',
        'audiovisuel'               => 'cinema & audiovisuel',
        'film'                      => 'cinema & audiovisuel',
        'video'                     => 'cinema & audiovisuel',
        'documentaire'              => 'cinema & audiovisuel',
        'animation'                 => 'cinema & audiovisuel',
        'court-metrage'             => 'cinema & audiovisuel',
        'court metrage'             => 'cinema & audiovisuel',

        // Arts visuels
        'arts visuels'              => 'arts visuels',
        'arts plastiques'           => 'arts visuels',
        'peinture'                  => 'arts visuels',
        'sculpture'                 => 'arts visuels',
        'photographie'              => 'arts visuels',
        'photo'                     => 'arts visuels',
        'dessin'                    => 'arts visuels',
        'illustration'              => 'arts visuels',
        'installation'              => 'arts visuels',
        'arts contemporains'        => 'arts visuels',
        'art contemporain'          => 'arts visuels',
        'art visuel'                => 'arts visuels',

        // Danse
        'danse'                     => 'danse',
        'dance'                     => 'danse',
        'choregraphie'              => 'danse',
        'choreographie'             => 'danse',

        // Théâtre & Performance
        'theatre'                   => 'theatre & performance',
        'performance'               => 'theatre & performance',
        'theatre & performance'     => 'theatre & performance',
        'theatre et performance'    => 'theatre & performance',
        'arts de la scene'          => 'theatre & performance',
        'arts vivants'              => 'theatre & performance',
        'cirque'                    => 'theatre & performance',

        // Littérature
        'litterature'               => 'litterature',
        'literature'                => 'litterature',
        'ecriture'                  => 'litterature',
        'poesie'                    => 'litterature',
        'roman'                     => 'litterature',
        'auteur'                    => 'litterature',

        // Arts numériques
        'arts numeriques'           => 'arts numeriques',
        'art numerique'             => 'arts numeriques',
        'digital'                   => 'arts numeriques',
        'numerique'                 => 'arts numeriques',
        'jeu video'                 => 'arts numeriques',
        'jeux video'                => 'arts numeriques',
        'new media'                 => 'arts numeriques',

        // Mode & Design
        'mode'                      => 'mode & design',
        'design'                    => 'mode & design',
        'mode & design'             => 'mode & design',
        'mode et design'            => 'mode & design',
        'fashion'                   => 'mode & design',
        'textile'                   => 'mode & design',
    ];

    /**
     * Cache en mémoire des disciplines BDD normalisées.
     * Rempli au premier appel de mapLabelsToEntities() et réutilisé ensuite.
     * Clé : libellé normalisé (sans accents, en minuscules).
     * Valeur : entité Discipline correspondante.
     *
     * @var array<string, Discipline>|null
     */
    private ?array $normalizedCache = null;

    public function __construct(
        // Repository pour charger les disciplines depuis la BDD
        private readonly DisciplineRepository $disciplineRepository,
        // Logger pour tracer les libellés non mappés (aide à enrichir SYNONYMS)
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Convertit une liste de libellés texte en entités Discipline existantes.
     *
     * Entrée  : tableau de libellés retournés par le LLM.
     *           Ex : ["Arts plastiques", "musique", "Cinema & Audiovisuel"]
     *
     * Sortie  : tableau d'entités Discipline trouvées en BDD.
     *           Les libellés sans correspondance sont ignorés (pas de création).
     *
     * Algorithme pour chaque libellé :
     *   1. Normalisation (minuscules, suppression des accents)
     *   2. Recherche directe dans le cache BDD normalisé
     *   3. Si non trouvé : recherche dans SYNONYMS
     *   4. Si non trouvé : sous-chaîne (libellé contient un nom BDD)
     *   5. Si toujours pas trouvé : libellé ignoré (log warning)
     *
     * @param string[] $labels Libellés retournés par le LLM
     * @return Discipline[]   Entités Discipline correspondantes (peut être vide)
     */
    public function mapLabelsToEntities(array $labels): array
    {
        if (empty($labels)) {
            return [];
        }

        // Charge le cache BDD normalisé au premier appel (lazy loading)
        $this->buildNormalizedCache();

        /** @var Discipline[] $found */
        $found   = [];
        $seenIds = []; // Évite les doublons si le LLM envoie 2 synonymes du même concept

        foreach ($labels as $rawLabel) {
            // ── Étape 1 : normaliser le libellé du LLM ────────────────────────
            $normalized = $this->normalizeText($rawLabel);

            if ($normalized === '') {
                continue;
            }

            $discipline = null;

            // ── Étape 2 : correspondance exacte dans le cache BDD ─────────────
            // Exemple : LLM retourne "Arts visuels" → normalisé "arts visuels" → trouvé
            if (isset($this->normalizedCache[$normalized])) {
                $discipline = $this->normalizedCache[$normalized];
            }

            // ── Étape 3 : correspondance via la table des synonymes ───────────
            // Exemple : LLM retourne "Arts plastiques" → "arts plastiques" → synonyme → "arts visuels"
            if ($discipline === null && isset(self::SYNONYMS[$normalized])) {
                $canonicalKey = self::SYNONYMS[$normalized];
                $discipline   = $this->normalizedCache[$canonicalKey] ?? null;
            }

            // ── Étape 4 : correspondance par sous-chaîne ──────────────────────
            // Exemple : LLM retourne "Cinéma documentaire" → contient "cinema" → Cinéma & Audiovisuel
            // Moins précise que les étapes précédentes mais couvre les libellés composés.
            //
            // GUARD longueur >= 4 caractères (correctif faux positifs) :
            //   Sans ce guard, un libellé court comme "art" (3 chars) matche la clé BDD
            //   "arts numeriques" via str_contains($dbKey, "art") → faux positif.
            //   Exemple réel : "art" → matcherait TOUTES les disciplines contenant "art"
            //   (arts visuels, arts numériques, theatre & performance…) et prendrait
            //   la première venue alphabétiquement, ce qui est arbitraire et incorrect.
            //   En imposant >= 4 chars, on exige un minimum de signal sémantique.
            if ($discipline === null && mb_strlen($normalized) >= 4) {
                foreach ($this->normalizedCache as $dbKey => $dbDiscipline) {
                    // On cherche si le libellé normalisé du LLM contient la clé BDD
                    // ou si la clé BDD contient le libellé LLM (matching bidirectionnel)
                    if (str_contains($normalized, $dbKey) || str_contains($dbKey, $normalized)) {
                        $discipline = $dbDiscipline;
                        break;
                    }
                }
            }

            // ── Étape 5 : libellé non mappé — on log pour améliorer SYNONYMS ─
            if ($discipline === null) {
                $this->logger->debug('[DisciplineMapper] Libellé non mappé : "{label}" (normalisé : "{normalized}")', [
                    'label'      => $rawLabel,
                    'normalized' => $normalized,
                ]);
                continue;
            }

            // Éviter les doublons (ex : "Arts plastiques" ET "Art visuel" → même Discipline)
            $id = $discipline->getId();
            if ($id !== null && !in_array($id, $seenIds, true)) {
                $seenIds[] = $id;
                $found[]   = $discipline;
            }
        }

        return $found;
    }

    /**
     * Construit le cache des disciplines BDD sous leur forme normalisée.
     *
     * Cette opération effectue UNE SEULE requête BDD (findAllOrdered) et
     * stocke les résultats dans $normalizedCache pour toute la durée de vie
     * de la requête HTTP (le service est shared par défaut en Symfony).
     *
     * Structure du cache :
     *   clé   = libellé normalisé (ex : "arts visuels", "cinema & audiovisuel")
     *   valeur = entité Discipline (objet Doctrine chargé)
     */
    private function buildNormalizedCache(): void
    {
        // Si le cache est déjà rempli, on ne refait pas la requête BDD
        if ($this->normalizedCache !== null) {
            return;
        }

        $this->normalizedCache = [];

        // findAllOrdered() trie par nom ASC — pratique pour les logs mais
        // n'affecte pas la logique de matching.
        foreach ($this->disciplineRepository->findAllOrdered() as $discipline) {
            $key = $this->normalizeText($discipline->getName());
            $this->normalizedCache[$key] = $discipline;
        }
    }

    /**
     * Normalise une chaîne pour la comparaison : minuscules + suppression des accents.
     *
     * Transformation appliquée :
     *   "Cinéma & Audiovisuel" → "cinema & audiovisuel"
     *   "Théâtre & Performance" → "theatre & performance"
     *   "Arts visuels" → "arts visuels"
     *
     * Pourquoi supprimer les accents et non utiliser mb_strtolower seul ?
     *   Le LLM peut omettre les accents ("theatre" au lieu de "théâtre") ou
     *   en ajouter de manière incorrecte. La normalisation sans accents maximise
     *   les correspondances sans sacrifier la précision.
     *
     * Note : on conserve les caractères spéciaux comme '&' car ils font partie
     *   des noms canoniques ("Cinéma & Audiovisuel", "Mode & Design").
     *
     * ── CORRECTIF iconv//TRANSLIT ─────────────────────────────────────────────
     * L'ancienne implémentation utilisait iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE').
     * Sur certains systèmes Linux (locale C/POSIX), iconv translitère les accents
     * en séquences avec apostrophe : é → 'e, â → ^a, etc.
     * Résultat : "Théâtre" → "Th'e^atre" → les clés du cache BDD et les valeurs
     * de SYNONYMS ne correspondaient plus → aucun match en étapes 2-4 !
     *
     * Solution : Normalizer::normalize(FORM_D) décompose les caractères accentués
     * en lettre de base + combining accent (NFD). Ensuite preg_replace supprime
     * les combining characters (\p{Mn} = Mark, non-spacing). Résultat :
     *   é (U+00E9) → e + ́ (U+0301) → e
     *   â (U+00E2) → a + ̂ (U+0302) → a
     * Ce comportement est identique sur tous les OS (pas de dépendance à la locale).
     * Prérequis : extension PHP intl (installée par défaut dans Docker avec PHP 8.3).
     */
    private function normalizeText(string $text): string
    {
        // Mise en minuscules (unicode-aware)
        $lower = mb_strtolower(trim($text), 'UTF-8');

        // Décomposition NFD : sépare les lettres accentuées en lettre + diacritique
        // Exemple : "é" (U+00E9) → "e" + combining accent aigu (U+0301)
        $decomposed = \Normalizer::normalize($lower, \Normalizer::FORM_D);

        // Si Normalizer est indisponible (très rare), on retourne la version minuscule
        // sans suppression d'accents — le matching restera correct pour les libellés
        // exacts mais pourrait rater sur les accents manquants.
        if ($decomposed === false) {
            return $lower;
        }

        // Suppression des "Mn" (Mark, non-spacing) = les diacritiques (accents, trémas…)
        // \p{Mn} est une propriété Unicode standard — ne supprime PAS les & ou les espaces.
        $withoutDiacritics = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;

        // Normalise les espaces multiples (robustesse sur les libellés mal formés)
        return preg_replace('/\s+/', ' ', trim($withoutDiacritics)) ?? trim($withoutDiacritics);
    }
}
