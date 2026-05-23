<?php

declare(strict_types=1);

namespace App\DTO;

class RegisterDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}

    /**
     * Crée un DTO à partir des données brutes de la requête.
     * Retourne null si les champs obligatoires sont manquants.
     */
    public static function fromArray(array $data): ?self
    {
        if (empty($data['email']) || empty($data['password'])) {
            return null;
        }

        return new self(
            email: trim($data['email']),
            password: $data['password'],
        );
    }

    public function isEmailValid(): bool
    {
        return filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isPasswordStrong(): bool
    {
        // Minimum 8 caractères
        return strlen($this->password) >= 8;
    }
}
