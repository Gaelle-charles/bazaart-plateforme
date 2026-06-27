<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * RegisterDTO — Données d'inscription d'un nouvel utilisateur.
 *
 * Utilise PasswordStrengthTrait pour la validation de la politique de mot de passe
 * (factorisation commune avec ResetPasswordDTO — évite la duplication de code).
 *
 * Champs obligatoires : firstName, lastName, email, password, confirmPassword.
 * Champ facultatif   : displayName (nom de scène / nom d'artiste affiché).
 */
class RegisterDTO
{
    // Importe la méthode isPasswordStrong() depuis le trait partagé.
    // Le trait lit $this->password, qui est défini comme propriété de ce DTO.
    use PasswordStrengthTrait;

    /**
     * @param string      $firstName       Prénom (obligatoire)
     * @param string      $lastName        Nom de famille (obligatoire)
     * @param string      $email           Adresse email de l'utilisateur
     * @param string      $password        Mot de passe en clair (sera haché côté service)
     * @param string      $confirmPassword Confirmation du mot de passe
     * @param string|null $displayName     Nom affiché / nom de scène (facultatif —
     *                                     peut être défini plus tard à l'onboarding)
     */
    public function __construct(
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly string  $email,
        public readonly string  $password,
        public readonly string  $confirmPassword,
        public readonly ?string $displayName = null,
    ) {}

    /**
     * Crée un DTO à partir des données brutes de la requête POST.
     * Retourne null si l'un des champs obligatoires est absent ou vide.
     *
     * Logique de validation :
     *   - first_name  → obligatoire, ne doit pas être vide après trim
     *   - last_name   → obligatoire, ne doit pas être vide après trim
     *   - email       → obligatoire
     *   - password    → obligatoire
     *   - confirm_password → obligatoire (même s'il est vide, isset doit être true)
     *   - display_name → facultatif ; null si vide (l'utilisateur le définira à l'onboarding)
     *
     * Note : on retourne null (pas une exception) pour que le contrôleur puisse
     * afficher un message d'erreur propre côté utilisateur.
     */
    public static function fromArray(array $data): ?self
    {
        // ── Champs obligatoires : prénom et nom ──────────────────────────────
        // trim() d'abord pour éviter qu'un espace seul valide le champ
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName  = trim((string) ($data['last_name']  ?? ''));

        if ($firstName === '' || $lastName === '') {
            // Retourne null → le contrôleur affichera un message sur les champs manquants
            return null;
        }

        // Borne de longueur alignée sur la colonne BDD (VARCHAR(100)) : sans cette
        // garde, une saisie trop longue lèverait une exception Doctrine au flush
        // (erreur 500) au lieu d'un message utilisateur propre.
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            return null;
        }

        // ── Champs obligatoires : email, password, confirmation ──────────────
        if (empty($data['email']) || empty($data['password']) || !isset($data['confirm_password'])) {
            return null;
        }

        // ── Champ facultatif : display_name ─────────────────────────────────
        // L'opérateur ?: renvoie null si la valeur est vide après trim.
        // Ainsi un champ laissé vide → displayName = null (pas une chaîne vide).
        $displayName = trim((string) ($data['display_name'] ?? '')) ?: null;

        return new self(
            firstName:       $firstName,
            lastName:        $lastName,
            email:           trim($data['email']),
            password:        $data['password'],
            confirmPassword: $data['confirm_password'],
            displayName:     $displayName,
        );
    }

    public function isEmailValid(): bool
    {
        return filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // isPasswordStrong() est fournie par PasswordStrengthTrait (voir import ci-dessus).

    /**
     * Vérifie que le mot de passe et sa confirmation sont identiques.
     *
     * Cette vérification est indépendante de isPasswordStrong() — on valide
     * la force PUIS la correspondance dans le controller, dans cet ordre,
     * pour donner à l'utilisateur le message d'erreur le plus précis possible.
     */
    public function doPasswordsMatch(): bool
    {
        // Comparaison stricte (sensible à la casse) entre les deux saisies
        return $this->password === $this->confirmPassword;
    }
}
