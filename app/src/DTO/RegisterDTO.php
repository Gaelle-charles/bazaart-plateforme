<?php

declare(strict_types=1);

namespace App\DTO;

class RegisterDTO
{
    /**
     * @param string $email           Adresse email de l'utilisateur
     * @param string $password        Mot de passe en clair (sera haché côté service)
     * @param string $confirmPassword Confirmation du mot de passe (validation côté controller)
     */
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $confirmPassword,
    ) {}

    /**
     * Crée un DTO à partir des données brutes de la requête.
     * Retourne null si les champs obligatoires sont manquants.
     *
     * Note : confirm_password est lu depuis les données du formulaire.
     * Si le champ est absent (ex : appel API sans ce champ), on retourne null.
     */
    public static function fromArray(array $data): ?self
    {
        // Les trois champs sont obligatoires pour valider l'inscription
        if (empty($data['email']) || empty($data['password']) || !isset($data['confirm_password'])) {
            return null;
        }

        return new self(
            email:           trim($data['email']),
            password:        $data['password'],
            confirmPassword: $data['confirm_password'],
        );
    }

    public function isEmailValid(): bool
    {
        return filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Vérifie que le mot de passe respecte la politique définie dans le CDC §9 :
     *   - au moins 10 caractères
     *   - au moins 1 lettre majuscule (A-Z)
     *   - au moins 1 chiffre (0-9)
     *
     * La règle est intentionnellement simple pour V1 — pas de caractère spécial
     * obligatoire afin de ne pas bloquer les utilisateurs moins à l'aise.
     */
    public function isPasswordStrong(): bool
    {
        // Politique définie dans le CDC §9 :
        // - minimum 10 caractères
        // - au moins 1 lettre majuscule
        // - au moins 1 chiffre
        return strlen($this->password) >= 10
            && preg_match('/[A-Z]/', $this->password) === 1
            && preg_match('/[0-9]/', $this->password) === 1;
    }

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
