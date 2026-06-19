<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * LegalStatus — Statut juridique de l'artiste.
 *
 * Utilisé dans l'onboarding (nouvelle étape 4 "matching") pour permettre
 * à l'artiste de préciser sa situation juridique. Cette information
 * est cruciale pour le moteur de matching (Lot C) : certaines opportunités
 * (bourses, appels à projets, contrats) sont réservées à des statuts précis.
 *
 * Valeurs backed 'string' → stockées en BDD dans ArtistProfile::$legalStatus
 * via un type Doctrine 'string' (pas besoin d'un type Doctrine custom pour un enum string).
 *
 * Convention : aucun tiret cadratin (ni en, ni em) dans les libellés.
 * (cf. règle feedback_no_em_dashes.md dans la mémoire agent)
 */
enum LegalStatus: string
{
    /** Artiste-auteur affilié à la Maison des Artistes ou à l'AGESSA */
    case ARTISTE_AUTEUR    = 'artiste_auteur';

    /** Auto-entrepreneur (micro-entreprise) exerçant une activité artistique */
    case AUTOENTREPRENEUR  = 'autoentrepreneur';

    /** Association culturelle (loi 1901 ou équivalent) */
    case ASSOCIATION       = 'association';

    /** Société (SASU, SARL, SAS, EURL...) avec activité artistique principale */
    case SOCIETE           = 'societe';

    /** En cours de structuration juridique (pas encore de statut établi) */
    case EN_STRUCTURATION  = 'en_structuration';

    /** Autre statut non listé ci-dessus */
    case AUTRE             = 'autre';

    /**
     * Retourne le libellé affiché dans le formulaire d'onboarding et les templates.
     *
     * Convention de code : libellés en français, sans tiret cadratin.
     * Utilisé dans les templates Twig via {{ legalStatus.label() }}.
     */
    public function label(): string
    {
        return match ($this) {
            self::ARTISTE_AUTEUR   => 'Artiste-auteur (MDA / AGESSA)',
            self::AUTOENTREPRENEUR => 'Auto-entrepreneur',
            self::ASSOCIATION      => 'Association (loi 1901 ou équivalent)',
            self::SOCIETE          => 'Société (SASU, SARL, SAS...)',
            self::EN_STRUCTURATION => 'En cours de structuration',
            self::AUTRE            => 'Autre',
        };
    }
}
