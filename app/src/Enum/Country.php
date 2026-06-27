<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Country — Pays d'Europe + Territoires français d'Outre-mer.
 *
 * Cet enum est utilisé pour le champ "localisation" des profils artistes.
 * L'objectif : remplacer la saisie texte libre par un menu déroulant standardisé,
 * ce qui permet :
 *   - des réponses uniformes (pas de "France", "france", "FR", "Paris, France" pour le même pays)
 *   - des statistiques sur la distribution géographique des artistes
 *   - un matching plus fiable sur le critère territoire
 *
 * PÉRIMÈTRE — France métropolitaine + Outre-mer + Europe géographique :
 *   La mission Bazaart est dédiée à la diaspora afro-atlantique. Les artistes
 *   des Antilles, de Guyane, de La Réunion, de Mayotte et des autres territoires
 *   d'Outre-mer sont au cœur de cette mission. Ils sont regroupés avec la France
 *   métropolitaine dans un premier groupe "France et Outre-mer", suivi de "Europe".
 *   Cette liste sera étendue à d'autres continents en V2 si besoin.
 *
 * VALEUR STOCKÉE EN BDD :
 *   La colonne `location` reste de type `string` (aucune migration nécessaire).
 *   On stocke le nom français du pays/territoire (ex: "France", "Guadeloupe") directement.
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

    // ── Territoires français d'Outre-mer ──────────────────────────────────────
    //
    // Ces territoires sont essentiels à la mission Bazaart : la plateforme est
    // dédiée à la diaspora afro-atlantique, et les artistes des Antilles, de
    // Guyane, de La Réunion, de Mayotte ou des autres collectivités d'Outre-mer
    // font pleinement partie de cette communauté.
    //
    // Statuts administratifs (pour info) :
    //   - DOM (Départements d'Outre-mer) : Guadeloupe, Martinique, Guyane,
    //     La Réunion, Mayotte → régions et départements français à part entière.
    //   - COM (Collectivités d'Outre-mer) : Saint-Martin, Saint-Barthélemy,
    //     Saint-Pierre-et-Miquelon, Wallis-et-Futuna.
    //   - Collectivité sui generis : Nouvelle-Calédonie.
    //   - Collectivité d'Outre-mer : Polynésie française.

    /** Guadeloupe (DOM — Antilles) */
    case Guadeloupe             = 'Guadeloupe';

    /** Martinique (DOM — Antilles) */
    case Martinique             = 'Martinique';

    /** Guyane (DOM — Amérique du Sud) */
    case Guyane                 = 'Guyane';

    /** La Réunion (DOM — Océan Indien) */
    case LaReunion              = 'La Réunion';

    /** Mayotte (DOM — Océan Indien) */
    case Mayotte                = 'Mayotte';

    /** Saint-Martin (COM — Antilles) */
    case SaintMartin            = 'Saint-Martin';

    /** Saint-Barthélemy (COM — Antilles) */
    case SaintBarthelemy        = 'Saint-Barthélemy';

    /** Saint-Pierre-et-Miquelon (COM — Atlantique Nord) */
    case SaintPierreEtMiquelon  = 'Saint-Pierre-et-Miquelon';

    /** Polynésie française (Collectivité d'Outre-mer — Pacifique) */
    case PolynesieFrancaise     = 'Polynésie française';

    /** Nouvelle-Calédonie (Collectivité sui generis — Pacifique) */
    case NouvelleCaledonie      = 'Nouvelle-Calédonie';

    /** Wallis-et-Futuna (COM — Pacifique) */
    case WallisEtFutuna         = 'Wallis-et-Futuna';

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Liste constante des territories d'Outre-mer.
     *
     * Définie comme constante de classe (PHP 8.1 le permet dans les enums)
     * pour éviter de répéter la liste dans casesGroupedFr() et isOverseas().
     * Les éléments sont les cases Outre-mer déclarées ci-dessus.
     *
     * Note : on ne peut PAS utiliser self::... comme valeur de constante directe
     * dans une constante PHP — il faut l'initialiser via un tableau de cases.
     * PHP 8.1 autorise les constantes d'enum qui référencent des cases du même enum.
     */
    private const array OVERSEAS = [
        self::Guadeloupe,
        self::Martinique,
        self::Guyane,
        self::LaReunion,
        self::Mayotte,
        self::SaintMartin,
        self::SaintBarthelemy,
        self::SaintPierreEtMiquelon,
        self::PolynesieFrancaise,
        self::NouvelleCaledonie,
        self::WallisEtFutuna,
    ];

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

    /**
     * Retourne vrai si ce territoire est un Outre-mer français.
     *
     * Méthode utilitaire utilisée par casesGroupedFr() pour séparer
     * les Outre-mer des pays européens sans dupliquer la liste OVERSEAS.
     *
     * Exemple d'usage :
     *   Country::Guadeloupe->isOverseas() → true
     *   Country::France->isOverseas()     → false
     *   Country::Germany->isOverseas()    → false
     */
    public function isOverseas(): bool
    {
        // in_array() avec comparaison stricte (troisième argument true).
        // On compare des instances d'enum par identité — PHP 8.1 garantit
        // qu'une case d'enum est un singleton, donc === fonctionne.
        return in_array($this, self::OVERSEAS, strict: true);
    }

    /**
     * Retourne les pays regroupés en deux optgroups, ordonnés pour le <select> HTML.
     *
     * STRUCTURE RETOURNÉE :
     * [
     *   'France et Outre-mer' => [Country::France, Country::Guadeloupe, ...],
     *   'Europe'              => [Country::Albanie, Country::Allemagne, ...],
     * ]
     *
     * RÈGLES D'ORDONNANCEMENT :
     *   Groupe 1 — "France et Outre-mer" :
     *     France apparaît EN PREMIER (priorité explicite, car c'est le territoire
     *     principal de la diaspora basée en métropole). Puis les 11 Outre-mer
     *     triés alphabétiquement FR (repli d'accents, sans setlocale).
     *
     *   Groupe 2 — "Europe" :
     *     Tous les pays européens SAUF France et SAUF les Outre-mer, triés
     *     alphabétiquement FR (même algorithme que casesSortedFr()).
     *
     * COMPATIBILITÉ PHPSTAN NIVEAU 6 :
     *   Le type de retour est déclaré dans le docblock : array<string, list<self>>.
     *   "list" signifie un tableau indexé de 0..n (pas de clés string).
     *   PHPStan 1.x comprend cette annotation et la valide correctement.
     *
     * NOTE SUR LE TRI SANS setlocale() :
     *   Même approche que casesSortedFr() — accent folding via strtr() + strcmp().
     *   Voir le commentaire de casesSortedFr() pour l'explication complète.
     *
     * @return array<string, list<self>>
     */
    public static function casesGroupedFr(): array
    {
        // ── Closure de repli d'accent ────────────────────────────────────────
        // Factorisée localement dans cette méthode (pas de static field possible
        // dans les enums PHP 8.1). Identique à celle de casesSortedFr() :
        // on les duplique volontairement plutôt que de créer une méthode privée
        // (qui serait plus de complexité pour très peu de lignes).
        $fold = static function (string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            return strtr($s, [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
                'ç' => 'c',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ñ' => 'n',
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ý' => 'y', 'ÿ' => 'y',
            ]);
        };

        // ── Construction des deux groupes ────────────────────────────────────

        // Groupe 1 : France (toujours en premier) + Outre-mer triés alpha
        $overseasSorted = self::OVERSEAS; // copie du tableau constant
        usort(
            $overseasSorted,
            static fn(self $a, self $b): int => strcmp($fold($a->value), $fold($b->value))
        );
        // France est placée en tête, puis les Outre-mer triés alphabétiquement.
        $franceGroup = [self::France, ...$overseasSorted];

        // Groupe 2 : tous les pays SAUF France ET SAUF les Outre-mer.
        // On filtre en excluant France et tous les Outre-mer (isOverseas()).
        $europeGroup = array_values(array_filter(
            self::cases(),
            static fn(self $c): bool => $c !== self::France && !$c->isOverseas()
        ));
        // Tri alphabétique français (repli d'accents)
        usort(
            $europeGroup,
            static fn(self $a, self $b): int => strcmp($fold($a->value), $fold($b->value))
        );

        // ── Retour sous forme de tableau associatif ordonné ──────────────────
        // L'ordre des clés est garanti en PHP : les tableaux sont ordonnés
        // dans l'ordre d'insertion. "France et Outre-mer" apparaît TOUJOURS
        // avant "Europe" dans le <select> rendu côté Twig.
        return [
            'France et Outre-mer' => $franceGroup,
            'Europe'              => $europeGroup,
        ];
    }
}
