<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * ResetPasswordDTO — Données soumises par le formulaire de nouveau mot de passe.
 *
 * Ce DTO valide les deux saisies du nouveau mot de passe lors de la réinitialisation.
 * Il partage la politique de force du mot de passe avec RegisterDTO via PasswordStrengthTrait.
 *
 * Champs :
 *   - $password        : nouveau mot de passe en clair (sera haché dans PasswordResetService)
 *   - $confirmPassword : confirmation (doit être identique à $password)
 *
 * Note : ce DTO ne contient PAS le token de réinitialisation.
 * Le token est passé directement depuis l'URL ({token} dans la route),
 * géré indépendamment dans le contrôleur pour éviter qu'il ne soit
 * accidentellement loggué ou sérialisé avec les données du formulaire.
 */
class ResetPasswordDTO
{
    // Importe isPasswordStrong() : vérifie ≥10 caractères, 1 majuscule, 1 chiffre.
    // Même politique que RegisterDTO — le trait évite la duplication.
    use PasswordStrengthTrait;

    /**
     * @param string $password        Nouveau mot de passe en clair
     * @param string $confirmPassword Confirmation du nouveau mot de passe
     */
    public function __construct(
        public readonly string $password,
        public readonly string $confirmPassword,
    ) {}

    /**
     * Crée un DTO à partir des données brutes de la requête HTTP.
     *
     * Retourne null si les deux champs sont absents ou vides.
     * Le contrôleur affiche alors une erreur "champs obligatoires".
     *
     * @param array<string, mixed> $data Données du POST (request->request->all())
     * @return self|null null si les champs requis sont manquants
     */
    public static function fromArray(array $data): ?self
    {
        // Les deux champs sont obligatoires — on refuse un DTO incomplet.
        // On utilise empty() sur les DEUX champs (et pas isset() sur la confirmation) :
        // isset("") vaut true, donc une confirmation vide passerait le garde-fou et
        // afficherait à tort "les mots de passe ne correspondent pas" au lieu de
        // "champs obligatoires". empty() rejette correctement la chaîne vide.
        if (empty($data['password']) || empty($data['confirm_password'])) {
            return null;
        }

        return new self(
            password:        $data['password'],
            confirmPassword: $data['confirm_password'],
        );
    }

    /**
     * Vérifie que le mot de passe et sa confirmation sont identiques.
     *
     * Séparé de isPasswordStrong() : on teste la force D'ABORD, puis la
     * correspondance, pour donner le message d'erreur le plus précis possible
     * à l'utilisateur (idem RegisterDTO).
     */
    public function doPasswordsMatch(): bool
    {
        // Comparaison stricte, sensible à la casse
        return $this->password === $this->confirmPassword;
    }
}
