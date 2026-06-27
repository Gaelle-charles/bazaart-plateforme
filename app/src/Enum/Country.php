<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Country — Pays d'Europe (liste géographique, ~44 pays).
 *
 * Cet enum est utilisé pour le champ "localisation" des profils artistes.
 * L'objectif : remplacer la saisie texte libre par un menu déroulant standardisé,
 * ce qui permet :
 *   - des réponses uniformes (pas de "France", "france", "FR", "Paris, France" pour le même pays)
 *   - des statistiques sur la distribution géographique des artistes
 *   - un matching plus fiable sur le critère territoire
 *
 * PÉRIMÈTRE VOLONTAIREMENT LIMITÉ À L'EUROPE :
 *   La V1 de Bazaart cible principalement les artistes de la diaspora afro-atlantique
 *   basés en Europe. Cette liste sera étendue à d'autres continents en V2 si besoin.
 *
 * VALEUR STOCKÉE EN BDD :
 *   La colonne `location` reste de type `string` (aucune migration nécessaire).
 *   On stocke le nom français du pays (ex: "France", "Belgique") directement.
 *   Ce choix simplifie la lecture des données en admin et dans les stats,
 *   et évite de devoir joindre une table de traduction pour afficher le label.
 *
 * MATCHING :
 *   Le MatchingService lit `location` comme une chaîne de caractères — pas de changement
 *   dans la logique de calcul. Les valeurs normalisées améliorent simplement la précision.
 *
 * Convention PHP 8.1 : backed enum string (chaque case a une valeur string associée).
 */
enum Country: string
{
    // ── Europe du Nord ────────────────────────────────────────────────────────

    /** Danemark */
    case Denmark    = 'Danemark';

    /** Estonie */
    case Estonia    = 'Estonie';

    /** Finlande */
    case Finland    = 'Finlande';

    /** Islande */
    case Iceland    = 'Islande';

    /** Irlande */
    case Ireland    = 'Irlande';

    /** Lettonie */
    case Latvia     = 'Lettonie';

    /** Lituanie */
    case Lithuania  = 'Lituanie';

    /** Norvège */
    case Norway     = 'Norvège';

    /** Royaume-Uni */
    case UnitedKingdom = 'Royaume-Uni';

    /** Suède */
    case Sweden     = 'Suède';

    // ── Europe de l'Ouest ─────────────────────────────────────────────────────

    /** Allemagne */
    case Germany    = 'Allemagne';

    /** Andorre */
    case Andorra    = 'Andorre';

    /** Autriche */
    case Austria    = 'Autriche';

    /** Belgique */
    case Belgium    = 'Belgique';

    /** France */
    case France     = 'France';

    /** Irlande est dans Nord — voir ci-dessus */

    /** Liechtenstein */
    case Liechtenstein = 'Liechtenstein';

    /** Luxembourg */
    case Luxembourg = 'Luxembourg';

    /** Monaco */
    case Monaco     = 'Monaco';

    /** Pays-Bas */
    case Netherlands = 'Pays-Bas';

    /** Portugal */
    case Portugal   = 'Portugal';

    /** Saint-Marin */
    case SanMarino  = 'Saint-Marin';

    /** Suisse */
    case Switzerland = 'Suisse';

    /** Vatican (Saint-Siège) */
    case Vatican    = 'Vatican';

    // ── Europe du Sud ─────────────────────────────────────────────────────────

    /** Albanie */
    case Albania    = 'Albanie';

    /** Bosnie-Herzégovine */
    case BosniaHerzegovina = 'Bosnie-Herzégovine';

    /** Chypre */
    case Cyprus     = 'Chypre';

    /** Croatie */
    case Croatia    = 'Croatie';

    /** Espagne */
    case Spain      = 'Espagne';

    /** Grèce */
    case Greece     = 'Grèce';

    /** Italie */
    case Italy      = 'Italie';

    /** Kosovo */
    case Kosovo     = 'Kosovo';

    /** Macédoine du Nord */
    case NorthMacedonia = 'Macédoine du Nord';

    /** Malte */
    case Malta      = 'Malte';

    /** Monténégro */
    case Montenegro = 'Monténégro';

    /** Serbie */
    case Serbia     = 'Serbie';

    /** Slovénie */
    case Slovenia   = 'Slovénie';

    // ── Europe de l'Est ───────────────────────────────────────────────────────

    /** Biélorussie */
    case Belarus    = 'Biélorussie';

    /** Bulgarie */
    case Bulgaria   = 'Bulgarie';

    /** Hongrie */
    case Hungary    = 'Hongrie';

    /** Moldavie */
    case Moldova    = 'Moldavie';

    /** Pologne */
    case Poland     = 'Pologne';

    /** République tchèque */
    case CzechRepublic = 'République tchèque';

    /** Roumanie */
    case Romania    = 'Roumanie';

    /** Slovaquie */
    case Slovakia   = 'Slovaquie';

    /** Ukraine */
    case Ukraine    = 'Ukraine';

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne le libellé affiché dans les templates Twig.
     *
     * Pour cet enum, la valeur (value) est déjà le nom français du pays.
     * Cette méthode existe par cohérence avec les autres enums du projet
     * (LegalStatus, ArtistLookingFor) qui exposent tous une méthode label().
     * Cela permet d'écrire {{ c.label() }} dans Twig sans se soucier de la
     * structure interne de l'enum.
     */
    public function label(): string
    {
        // La valeur de l'enum est déjà le nom français → on la retourne directement.
        return $this->value;
    }

    /**
     * Retourne tous les cas de l'enum triés par ordre alphabétique FRANÇAIS,
     * de façon INDÉPENDANTE de la locale serveur.
     *
     * Pourquoi cette implémentation et pas setlocale() + strcoll() ?
     * ──────────────────────────────────────────────────────────────
     * L'ancienne implémentation utilisait setlocale(LC_COLLATE, 'fr_FR.UTF-8')
     * + strcoll(). Elle avait trois défauts en production :
     *
     *   1. Le serveur DigitalOcean n'a PAS la locale fr_FR installée.
     *      → setlocale() échoue silencieusement, strcoll() tombe en ASCII pur.
     *      → les accents (É, È, Ç…) sont mal classés ("Émirats" après "Z").
     *
     *   2. setlocale() est une fonction GLOBALE : elle modifie l'état de tout
     *      le processus PHP, ce qui peut casser d'autres opérations de tri
     *      qui tournent en parallèle dans la même requête.
     *
     *   3. setlocale() était appelé ~242 fois (une fois par paire comparée
     *      dans la closure de usort) au lieu d'une seule fois.
     *
     * Solution retenue : "accent folding"
     * ────────────────────────────────────
     * On réduit chaque libellé à sa forme "désaccentuée" (é→e, ç→c, etc.)
     * UNIQUEMENT pour la clé de comparaison (les valeurs affichées restent
     * inchangées). Un simple strcmp() en ASCII donne alors le bon ordre
     * alphabétique français pour les 45 pays de cet enum.
     *
     * Note sur le cache intra-request :
     *   PHP 8.1 interdit les propriétés statiques dans les enums (seules les
     *   constantes sont autorisées). On ne peut donc pas mémoïser le résultat
     *   dans la même classe. Ce n'est pas grave : usort() sur 45 éléments
     *   s'exécute en quelques microsecondes, et l'OPcache de PHP évite toute
     *   recompilation. Si une mise en cache intra-request devenait nécessaire,
     *   on la déplacerait dans un service Symfony à portée "request".
     *
     * Exemples de placements délicats vérifiés :
     *   - "Grèce"            → fold → "grece"            → classé en G avant Hongrie ✓
     *   - "Macédoine du Nord"→ fold → "macedoine du nord" → classé en M ✓
     *   - "Monténégro"       → fold → "montenegro"        → après Moldavie ✓
     *   - "Biélorussie"      → fold → "bielorussie"       → avant Bosnie ✓
     *   - "Norvège"          → fold → "norvege"           → entre Monaco et Pays-Bas ✓
     *   - "République tchèque"→fold → "republique tcheque"→ classé en R ✓
     *
     * @return self[]
     */
    public static function casesSortedFr(): array
    {
        // Closure de "repli d'accent" (accent folding).
        // Prend un libellé français et retourne sa version minuscule sans accents,
        // utilisée UNIQUEMENT comme clé de tri (jamais affichée).
        // "static" évite de capturer $this (méthode statique dans un enum = pas de $this).
        $fold = static function (string $s): string {
            // Étape 1 : minuscules multi-octets.
            // mb_strtolower() gère correctement "É" → "é", "Î" → "î", etc.
            $s = mb_strtolower($s, 'UTF-8');

            // Étape 2 : remplacement des caractères accentués par leur base latine.
            // strtr() avec une table fixe est plus rapide que preg_replace()
            // car il fait un seul passage sur la chaîne.
            return strtr($s, [
                // Famille A
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
                // Famille C
                'ç' => 'c',
                // Famille E
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                // Famille I
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                // Famille N
                'ñ' => 'n',
                // Famille O
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
                // Famille U
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                // Famille Y
                'ý' => 'y', 'ÿ' => 'y',
            ]);
        };

        // Récupère tous les cas de l'enum (dans l'ordre de déclaration du fichier).
        $cases = self::cases();

        // Tri stable par libellé désaccentué.
        // usort() modifie $cases en place. La fn flèche capture $fold par valeur.
        // strcmp() compare des chaînes ASCII → ordre alphabétique correct
        // une fois les accents repliés.
        usort($cases, static fn(self $a, self $b): int => strcmp($fold($a->value), $fold($b->value)));

        return $cases;
    }
}
