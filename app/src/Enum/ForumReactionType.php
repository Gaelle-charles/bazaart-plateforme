<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ForumReactionType — les types de réactions emoji disponibles sur le forum Bazaart.
 *
 * Pourquoi un enum PHP 8.1 backed par 'string' ?
 *   - La valeur string est stockée directement en BDD (colonne VARCHAR).
 *   - Cela permet de lire les données brutes sans passer par PHP (debug SQL direct).
 *   - L'enum évite les "magic strings" éparpillées dans le code.
 *   - Doctrine utilise enumType: ForumReactionType::class pour désérialiser
 *     automatiquement la valeur BDD vers l'enum PHP.
 *
 * Jeu de réactions inspiré de Slack/Discord : un utilisateur peut poser
 * PLUSIEURS types de réactions différents sur un même message, mais une seule
 * fois chaque type (toggle — deuxième clic retire la réaction).
 *
 * Convention projet : les enums sont dans src/Enum/ (cf. CLAUDE.md §4)
 */
enum ForumReactionType: string
{
    // ── Les 5 réactions disponibles ───────────────────────────────────────────

    /** Pouce levé — équivalent du "like" classique */
    case Like  = 'like';

    /** Flamme — contenu enflammé, particulièrement fort */
    case Fire  = 'fire';

    /** Applaudissements — bravo, félicitations */
    case Bravo = 'bravo';

    /** Coeur — amour, soutien affectif */
    case Heart = 'heart';

    /** Ampoule — idée inspirante, ca donne a réfléchir */
    case Idea  = 'idea';

    // ── Méthodes utilitaires ──────────────────────────────────────────────────

    /**
     * Retourne l'emoji Unicode correspondant au type de réaction.
     *
     * Ces emojis sont affichés dans les boutons de réaction du template Twig.
     * Exemple : ForumReactionType::Like->emoji() retourne '👍'
     */
    public function emoji(): string
    {
        return match ($this) {
            self::Like  => '👍',
            self::Fire  => '🔥',
            self::Bravo => '👏',
            self::Heart => '❤️',
            self::Idea  => '💡',
        };
    }

    /**
     * Retourne le libellé français lisible (pour l'attribut title="" des boutons).
     *
     * Utilisé dans les templates Twig comme tooltip et pour l'accessibilité (aria-label).
     * Exemple : ForumReactionType::Like->label() retourne "J'aime"
     */
    public function label(): string
    {
        return match ($this) {
            self::Like  => "J'aime",
            self::Fire  => 'Flamme',
            self::Bravo => 'Bravo',
            self::Heart => 'Cœur',
            self::Idea  => 'Inspirant',
        };
    }

    /**
     * Retourne tous les cas dans l'ordre d'affichage voulu.
     *
     * Utilisé dans Twig pour générer la rangée de boutons :
     *   {% for type in enum('App\\Enum\\ForumReactionType').orderedCases() %}
     *
     * @return array<int, ForumReactionType>
     */
    public static function orderedCases(): array
    {
        return [self::Like, self::Fire, self::Bravo, self::Heart, self::Idea];
    }
}
