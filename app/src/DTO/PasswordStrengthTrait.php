<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * PasswordStrengthTrait — Validation de la politique de mot de passe Bazaart.
 *
 * Ce trait centralise la règle de force du mot de passe définie dans le CDC §9
 * pour éviter la duplication entre RegisterDTO et ResetPasswordDTO.
 *
 * Politique V1 (intentionnellement simple — pas de caractère spécial obligatoire) :
 *   - Au moins 10 caractères
 *   - Au moins 1 lettre majuscule (A-Z)
 *   - Au moins 1 chiffre (0-9)
 *
 * Convention d'usage :
 *   Le DTO qui utilise ce trait DOIT exposer une propriété $password de type string.
 *   La méthode isPasswordStrong() lit $this->password directement.
 *
 * Pourquoi un trait plutôt qu'une classe abstraite ou un service ?
 *   → Les DTOs sont des value objects simples (readonly, pas d'héritage commun).
 *   → Un trait permet d'injecter la méthode sans imposer une hiérarchie de classes.
 *   → Le service de validation est volontairement séparé du service métier.
 */
trait PasswordStrengthTrait
{
    /**
     * Vérifie que le mot de passe respecte la politique Bazaart.
     *
     * La méthode lit $this->password — le DTO hôte doit définir cette propriété.
     *
     * @return bool true si le mot de passe est assez fort, false sinon
     */
    public function isPasswordStrong(): bool
    {
        // Règle 1 : longueur minimale de 10 caractères.
        // On utilise mb_strlen (et pas strlen) car un utilisateur peut choisir un
        // mot de passe avec des accents (ex: "Été2024Sûr!"). strlen compterait les
        // OCTETS UTF-8 (un "é" = 2 octets), ce qui fausserait la mesure ; mb_strlen
        // compte les vrais CARACTÈRES. C'est aussi plus prudent vis-à-vis de bcrypt
        // qui tronque au-delà de 72 octets.
        if (mb_strlen($this->password, 'UTF-8') < 10) {
            return false;
        }

        // Règle 2 : au moins une lettre majuscule A-Z
        // preg_match retourne 1 si le pattern est trouvé, 0 sinon, false en cas d'erreur
        if (preg_match('/[A-Z]/', $this->password) !== 1) {
            return false;
        }

        // Règle 3 : au moins un chiffre 0-9
        if (preg_match('/[0-9]/', $this->password) !== 1) {
            return false;
        }

        return true;
    }
}
