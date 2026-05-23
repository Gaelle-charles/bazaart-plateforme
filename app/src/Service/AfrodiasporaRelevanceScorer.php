<?php

namespace App\Service;

/**
 * AfrodiasporaRelevanceScorer — Calcule un score de pertinence Afrodiaspora.
 *
 * Ce service analyse le titre et la description d'une opportunité artistique
 * pour estimer sa pertinence pour les artistes de l'Afrodiaspora francophone.
 *
 * Le score va de 0 (aucun lien) à 5 (très fortement lié).
 * Il est ensuite affiché sous forme d'étoiles dans Google Sheets (ex: "★★★☆☆").
 *
 * Ce service est injecté automatiquement par l'autowiring Symfony —
 * pas besoin de le déclarer manuellement dans services.yaml.
 */
class AfrodiasporaRelevanceScorer
{
    /**
     * Mots-clés de haute pertinence Afrodiaspora.
     *
     * Ces termes désignent directement des territoires, populations ou concepts
     * liés à la diaspora africaine et aux DOM-TOM français.
     *
     * Chaque mot-clé trouvé rapporte 1 point, avec un maximum de 3 points
     * pour cette catégorie (pour éviter qu'un seul texte très répétitif
     * sature tout le score).
     */
    private const HIGH_RELEVANCE_KEYWORDS = [
        'caraïbe',
        'caribéen',
        'antilles',
        'guadeloupe',
        'martinique',
        'réunion',
        'mayotte',
        'guyane',
        'dom-tom',
        'dom/tom',
        'outre-mer',
        'afrique',
        'africain',
        'afrodiaspora',
        'diaspora afro',
        'diaspora africaine',
    ];

    /**
     * Mots-clés de pertinence moyenne.
     *
     * Ces termes indiquent un lien possible mais moins direct avec l'Afrodiaspora :
     * pays africains, îles caribéennes anglophones, ou notions de mobilité internationale
     * qui peuvent concerner des artistes de la diaspora.
     *
     * Chaque mot-clé trouvé rapporte 1 point, avec un maximum de 2 points
     * pour cette catégorie.
     */
    private const MEDIUM_RELEVANCE_KEYWORDS = [
        'francophonie',
        'francophone',
        'international',
        'mobilité internationale',
        'résidence internationale',
        'maghreb',
        'sénégal',
        "côte d'ivoire",
        'mali',
        'cameroun',
        'congo',
        'bénin',
        'togo',
        'burkina',
        'haïti',
        'jamaïque',
        'trinidad',
    ];

    /**
     * Calcule le score de pertinence Afrodiaspora d'une opportunité.
     *
     * La méthode :
     * 1. Fusionne le titre et la description en une seule chaîne en minuscules
     *    (pour une comparaison insensible à la casse)
     * 2. Compte les occurrences de mots-clés haute pertinence (max 3 pts)
     * 3. Compte les occurrences de mots-clés pertinence moyenne (max 2 pts)
     * 4. Additionne les deux scores, plafonnés à 5
     *
     * @param string $title       Titre de l'opportunité
     * @param string $description Description de l'opportunité
     * @return int Score entre 0 et 5
     */
    public function score(string $title, string $description): int
    {
        // Étape 1 : fusionner titre + description en minuscules pour la recherche
        // On sépare les deux par un espace pour éviter des faux positifs en cas de
        // concaténation directe (ex: "guadeloupe" + "enne" → "guadeloupeenne")
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        // Étape 2 : compter les mots-clés de haute pertinence trouvés
        // On utilise mb_strpos pour gérer correctement les caractères accentués (UTF-8)
        $highScore = 0;
        foreach (self::HIGH_RELEVANCE_KEYWORDS as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                $highScore++;
            }
        }
        // Plafond : 3 points maximum pour cette catégorie
        $highScore = min($highScore, 3);

        // Étape 3 : compter les mots-clés de pertinence moyenne trouvés
        $mediumScore = 0;
        foreach (self::MEDIUM_RELEVANCE_KEYWORDS as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                $mediumScore++;
            }
        }
        // Plafond : 2 points maximum pour cette catégorie
        $mediumScore = min($mediumScore, 2);

        // Étape 4 : score total = somme des deux catégories, plafonné à 5
        return min($highScore + $mediumScore, 5);
    }
}
