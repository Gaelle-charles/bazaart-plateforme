<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Niveau d'expérience requis pour une opportunité ou une ressource.
 *
 * Utilisé sur l'entité Resource (et son intermédiaire ScrapedResource) pour
 * permettre aux artistes de filtrer les opportunités selon leur profil.
 *
 * Trois valeurs suffisent pour la V1 :
 *
 *   DEBUTANT      → peu ou pas d'expérience professionnelle dans la discipline.
 *                   Convient aux étudiants, artistes en émergence.
 *   INTERMEDIAIRE → pratique régulière, quelques projets réalisés.
 *   EXPERIMENTE   → parcours confirmé, expositions / productions significatives.
 *
 * CONVENTION IMPORTANTE :
 *   La valeur NULL (colonne nullable en BDD) signifie « tous niveaux » —
 *   c'est-à-dire que l'opportunité ne précise pas de niveau requis ou s'adresse
 *   à tout type de profil. NULL n'est PAS une valeur de cet enum.
 *   → Ne créez pas de case "ALL_LEVELS" ou "TOUS" : utilisez null à la place.
 *
 * Pourquoi des backed values en anglais ?
 *   Les valeurs stockées en BDD (colonne VARCHAR) sont en anglais pour rester
 *   cohérentes avec CourseLevel (même schéma). Les labels en français sont
 *   fournis par la méthode label() pour l'affichage côté templates Twig.
 */
enum ExperienceLevel: string
{
    /**
     * Débutant : ouvert aux artistes sans expérience professionnelle préalable.
     * Valeur BDD : 'beginner'
     */
    case DEBUTANT = 'beginner';

    /**
     * Intermédiaire : pratique régulière, bases professionnelles acquises.
     * Valeur BDD : 'intermediate'
     */
    case INTERMEDIAIRE = 'intermediate';

    /**
     * Expérimenté : profil confirmé avec un parcours artistique établi.
     * Valeur BDD : 'experienced'
     */
    case EXPERIMENTE = 'experienced';

    /**
     * Retourne l'intitulé lisible en français pour les templates Twig.
     *
     * Utilisation Twig : {{ resource.experienceLevel.label() }}
     * Utilisation PHP  : $resource->getExperienceLevel()?->label()
     *
     * Note : l'opérateur ?-> est nécessaire car experienceLevel est nullable.
     */
    public function label(): string
    {
        return match($this) {
            self::DEBUTANT      => 'Débutant',
            self::INTERMEDIAIRE => 'Intermédiaire',
            self::EXPERIMENTE   => 'Expérimenté',
        };
    }
}
