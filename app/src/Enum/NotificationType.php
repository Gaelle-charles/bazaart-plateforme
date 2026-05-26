<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * NotificationType — les différents types de notifications du système Bazaart.
 *
 * Pourquoi un enum PHP 8.1 backed par 'string' ?
 *   - La valeur string est stockée directement en BDD (colonne VARCHAR/TEXT).
 *   - Cela permet de lire les données brutes sans passer par PHP (debug SQL direct).
 *   - L'enum évite les "magic strings" éparpillées dans le code.
 *
 * Doctrine utilise enumType: NotificationType::class dans la colonne ORM
 * pour désérialiser automatiquement la valeur BDD vers l'enum PHP.
 *
 * Convention projet : les enums sont dans src/Enum/ (cf. CLAUDE.md)
 */
enum NotificationType: string
{
    // ── Types de notifications V1 ─────────────────────────────────────────────

    /** Nouveau message privé reçu dans une conversation */
    case NewMessage = 'new_message';

    /** Quelqu'un a répondu à un thread forum dont l'utilisateur est l'auteur */
    case NewReply = 'new_reply';

    /**
     * L'utilisateur a été mentionné (@user) dans un post ou une réponse.
     * TODO V2 — la détection de @username n'est pas implémentée en V1.
     */
    case Mention = 'mention';

    /** Un live a été planifié par quelqu'un suivi par l'utilisateur */
    case NewLive = 'new_live';

    /** La ressource soumise par l'utilisateur vient d'être publiée par un admin */
    case ResourceValidated = 'resource_validated';

    /** Une nouvelle ressource correspond aux alertes de l'utilisateur */
    case ResourceMatch = 'resource_match';

    // ─── Méthode utilitaire ───────────────────────────────────────────────────

    /**
     * Retourne le libellé français lisible pour l'affichage dans l'interface.
     *
     * Utilisé dans les templates Twig et les notifications email.
     * Exemple : NotificationType::NewMessage->label() → 'Nouveau message'
     */
    public function label(): string
    {
        return match($this) {
            // Chaque case de l'enum est mappé vers son libellé FR
            self::NewMessage       => 'Nouveau message',
            self::NewReply         => 'Nouvelle réponse',
            self::Mention          => 'Mention',
            self::NewLive          => 'Nouveau live',
            self::ResourceValidated => 'Ressource publiée',
            self::ResourceMatch    => 'Alerte ressource',
        };
    }
}
