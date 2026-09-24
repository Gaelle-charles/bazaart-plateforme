<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectDriveConnectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProjectDriveConnection — connexion OAuth au Google Drive de l'équipe (ADR-0037).
 *
 * Une seule ligne en pratique : le Drive de reinesdestempsmodernes@gmail.com.
 *
 * POURQUOI une table dédiée plutôt que app_settings ?
 *   La page /admin/settings liste et permet de modifier TOUTES les clés de
 *   app_settings. Le jeton Drive donne un accès complet au Drive de l'équipe :
 *   il ne doit être ni visible ni modifiable par erreur depuis cette page.
 *
 * SÉCURITÉ : `refreshToken` est CHIFFRÉ (libsodium, clé dérivée de APP_SECRET,
 * cf. TokenCipher). Une fuite de la base seule ne suffit pas à accéder au Drive.
 */
#[ORM\Entity(repositoryClass: ProjectDriveConnectionRepository::class)]
#[ORM\Table(name: 'project_drive_connections')]
class ProjectDriveConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Adresse du compte Google connecté (vérifiée auprès de Google à la connexion). */
    #[ORM\Column(type: 'string', length: 180)]
    private string $accountEmail = '';

    /** Refresh token OAuth, chiffré (format « v1:base64 »). */
    #[ORM\Column(type: 'text')]
    private string $encryptedRefreshToken = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'connected_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $connectedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $connectedAt;

    public function __construct()
    {
        $this->connectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountEmail(): string
    {
        return $this->accountEmail;
    }

    public function setAccountEmail(string $accountEmail): static
    {
        $this->accountEmail = $accountEmail;

        return $this;
    }

    public function getEncryptedRefreshToken(): string
    {
        return $this->encryptedRefreshToken;
    }

    public function setEncryptedRefreshToken(string $encryptedRefreshToken): static
    {
        $this->encryptedRefreshToken = $encryptedRefreshToken;

        return $this;
    }

    public function getConnectedBy(): ?User
    {
        return $this->connectedBy;
    }

    public function setConnectedBy(?User $connectedBy): static
    {
        $this->connectedBy = $connectedBy;

        return $this;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function renew(): static
    {
        $this->connectedAt = new \DateTimeImmutable();

        return $this;
    }
}
