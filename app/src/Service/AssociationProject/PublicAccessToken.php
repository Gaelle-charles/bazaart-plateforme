<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * PublicAccessToken : le jeton secret qui ouvre la page « Projets en cours » (ADR-0039).
 *
 * PRINCIPE : la page n'exige AUCUNE connexion (pas de compte partenaire, pas de mot
 * de passe). La seule protection est ce jeton, écrit dans l'adresse :
 *     https://app.bazaart.fr/projets/<jeton>
 * Qui a le lien voit la page ; sans le bon jeton, le site répond 404, exactement
 * comme pour une page qui n'existe pas (on ne révèle même pas que la page existe).
 *
 * D'OÙ VIENT LE JETON ? De la variable d'environnement PROJETS_PARTENAIRES_TOKEN
 * (jamais écrite dans le code : le dépôt GitHub est public). Valeur par défaut
 * VIDE dans services.yaml : tant qu'elle n'est pas configurée sur le serveur, la
 * page est éteinte (404 partout). Voir docs/projets-association.md.
 *
 * Pour générer un jeton : php -r "echo bin2hex(random_bytes(32));"  (64 caractères)
 */
final class PublicAccessToken
{
    /** En dessous, le jeton est jugé trop facile à deviner (avertissement dans le back-office). */
    public const RECOMMENDED_MIN_LENGTH = 32;

    public function __construct(
        private readonly string $token,
    ) {
    }

    /** La page est-elle allumée (jeton configuré) ? */
    public function isEnabled(): bool
    {
        return $this->token !== '';
    }

    /**
     * Le jeton reçu dans l'adresse est-il le bon ?
     *
     * hash_equals() compare en TEMPS CONSTANT : avec une comparaison classique (===),
     * la réponse arrive un peu plus vite quand les premiers caractères sont faux, ce
     * qui permettrait en théorie de deviner le jeton caractère par caractère.
     */
    public function matches(string $candidate): bool
    {
        return $this->isEnabled() && hash_equals($this->token, $candidate);
    }

    /** Jeton configuré (sert uniquement à afficher le lien à partager dans le back-office). */
    public function value(): string
    {
        return $this->token;
    }

    public function isTooShort(): bool
    {
        return $this->isEnabled() && strlen($this->token) < self::RECOMMENDED_MIN_LENGTH;
    }
}
