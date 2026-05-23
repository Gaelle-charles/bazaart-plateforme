<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rôle du contributeur ayant créé une ressource (opportunité) dans la Ressourcerie.
 *
 * Cet enum sert à reconnaître la **provenance** d'une Resource :
 *   - Admin     : créée par un administrateur Bazaart (CRUD complet sans validation)
 *   - Structure : créée par un compte Structure (auto-publiée, sans validation)
 *   - Artist    : soumise par un artiste membre (passe par validation admin)
 *
 * --- Différence avec ROLE_* de Symfony Security ---
 *
 * Attention à ne pas confondre cet enum avec les rôles Symfony Security
 * (ROLE_USER, ROLE_ADMIN, ROLE_STRUCTURE...). Ce sont deux concepts distincts :
 *
 *   • Les ROLE_* contrôlent l'**accès** : qui peut accéder à /admin, etc.
 *   • SubmitterRole est une **métadonnée métier** sur une Resource :
 *     elle fige qui a contribué cette ressource au moment T (et donc si elle
 *     doit être auto-publiée ou passer par modération).
 *
 * Pourquoi figer cette info sur la Resource plutôt que de la dériver à
 * la volée du User ? Parce qu'un User peut perdre son rôle Structure plus
 * tard (révocation), mais les ressources qu'il a créées auparavant doivent
 * conserver leur statut d'auto-publication d'origine.
 */
enum SubmitterRole: string
{
    case Admin     = 'admin';
    case Structure = 'structure';
    case Artist    = 'artist';
}
