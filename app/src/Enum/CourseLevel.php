<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Niveau requis pour suivre une formation (Course).
 *
 * Cet enum PHP 8.1 « backed » permet à Doctrine de stocker la valeur string
 * en base de données (colonne VARCHAR) tout en manipulant un type fort en PHP.
 *
 * Trois niveaux couvrent l'ensemble du catalogue V1 :
 *
 *   BEGINNER     → aucune connaissance préalable requise
 *   INTERMEDIATE → bases déjà acquises, pratique régulière
 *   ADVANCED     → expérience significative dans la discipline
 *
 * La méthode label() renvoie l'intitulé français à afficher dans les templates
 * Twig sans avoir à répéter la logique de traduction côté frontend.
 *
 * Pourquoi un enum et pas des constantes ?
 * Les enums PHP 8.1 sont typés : Doctrine refuse d'enregistrer une valeur
 * invalide, et PHPStan peut détecter les branches manquantes dans les match.
 * Les constantes de classe ne donnent pas ces garanties.
 */
enum CourseLevel: string
{
    case BEGINNER     = 'beginner';
    case INTERMEDIATE = 'intermediate';
    case ADVANCED     = 'advanced';

    /**
     * Retourne l'intitulé lisible en français pour l'affichage dans les templates.
     *
     * Utilisation Twig : {{ course.level.label() }}
     */
    public function label(): string
    {
        return match($this) {
            self::BEGINNER     => 'Débutant',
            self::INTERMEDIATE => 'Intermédiaire',
            self::ADVANCED     => 'Avancé',
        };
    }
}
