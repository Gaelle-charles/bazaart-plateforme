<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ArtistLookingFor — Ce que recherche un artiste sur la plateforme.
 *
 * Cet enum est utilisé dans le parcours d'onboarding (étape 3 "Que recherches-tu ?").
 * Les valeurs cochées par l'utilisateur sont stockées dans User::$lookingFor (JSON).
 *
 * Valeurs backed 'string' pour le stockage en BDD (tableau JSON de strings).
 *
 *   FORMATIONS        → l'artiste cherche des formations pour se développer
 *   RESSOURCES_AIDES  → l'artiste cherche des aides financières (bourses, subventions)
 *   RESSOURCES_APPELS → l'artiste cherche des appels à projets / résidences
 *   AUTRE             → option libre, ouvre le champ lookingForOther
 *
 * Note sur le mapping vers les types de ressources (ResourceType) à l'étape 4 :
 *   RESSOURCES_AIDES  → pré-cocher "Bourse & Financement"
 *   RESSOURCES_APPELS → pré-cocher "Appel à projets" + "Résidence artistique" + "Prix & Concours"
 *   FORMATIONS        → pré-cocher "Formation"
 *   (mapping défini dans OnboardingService::getPreselectedResourceTypes())
 */
enum ArtistLookingFor: string
{
    case FORMATIONS        = 'formations';
    case RESSOURCES_AIDES  = 'ressources_aides';
    case RESSOURCES_APPELS = 'ressources_appels';
    case AUTRE             = 'autre';

    /**
     * Retourne le libellé affiché dans le formulaire d'onboarding.
     * Utilisé dans les templates Twig via {{ value.label() }}.
     */
    public function label(): string
    {
        // S4-FIX : correction des accents manquants dans les libellés.
        // Règle éditoriale : aucun tiret cadratin « — » dans les libellés (CLAUDE.md §14).
        return match ($this) {
            self::FORMATIONS        => 'Des formations pour développer mes compétences',
            self::RESSOURCES_AIDES  => 'Des aides financières (bourses, subventions, financements)',
            // S4 : 'residences' → 'résidences', 'appels a projets' → 'appels à projets'
            self::RESSOURCES_APPELS => 'Des appels à projets, résidences et concours',
            // S4 : 'precise ci-dessous' → 'précise ci-dessous'
            self::AUTRE             => 'Autre chose (précise ci-dessous)',
        };
    }
}
