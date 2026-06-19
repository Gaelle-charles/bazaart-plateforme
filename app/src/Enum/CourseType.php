<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CourseType — Type de formation sur la plateforme Bazaart.
 *
 * Un Course peut désormais être de deux natures fondamentalement différentes :
 *
 *   CONTENU  → Formation asynchrone « classique » composée de modules et de leçons
 *              vidéo (le type historique). L'apprenant avance à son rythme.
 *              Exemple : « Introduction à l'Afrobeats ».
 *
 *   EVENEMENT → Formation synchrone (événement en ligne ou présentiel) avec une
 *              date/heure précise, un mode (visio ou présentiel), une localisation
 *              ou un lien de connexion, et éventuellement une capacité limitée.
 *              Exemple : « Masterclass Composition – Zoom – 28 juin 2026 ».
 *
 * Pourquoi backing string 'content' / 'event' ?
 *   Valeurs courtes, stables, explicites en anglais pour la cohérence avec le
 *   reste des enums du projet (ResourceStatus, CourseLevel…).
 *   La valeur par défaut en base est 'content' (cf. colonne Course.type).
 *
 * Impact sur le schéma :
 *   La colonne `type` sur la table `courses` est NOT NULL avec default 'content'.
 *   Toutes les formations existantes deviennent automatiquement CONTENU lors
 *   de la migration — aucune donnée n'est perdue.
 */
enum CourseType: string
{
    /**
     * Formation asynchrone : modules → leçons vidéo.
     * Type historique — toutes les formations créées avant cette migration sont CONTENU.
     */
    case CONTENU    = 'content';

    /**
     * Événement synchrone : date, mode (visio/présentiel), lieu ou lien externe.
     * Les modules/leçons ne sont pas utilisés pour ce type.
     */
    case EVENEMENT  = 'event';

    /**
     * Retourne l'intitulé lisible en français pour l'affichage dans les templates.
     *
     * Utilisation Twig : {{ course.type.label() }}
     */
    public function label(): string
    {
        return match($this) {
            self::CONTENU   => 'Formation en ligne (modules/leçons)',
            self::EVENEMENT => 'Événement (visio ou présentiel)',
        };

        // Remarque : match exhaustif — PHPStan détectera si un nouveau case
        // est ajouté sans mettre à jour ce match.
    }

    /**
     * Retourne une version très courte pour les badges admin.
     *
     * Utilisation Twig : {{ course.type.shortLabel() }}
     * Exemple : badge "Contenu" / "Événement" dans la liste admin.
     */
    public function shortLabel(): string
    {
        return match($this) {
            self::CONTENU   => 'Contenu',
            self::EVENEMENT => 'Événement',
        };
    }
}
