<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * AppSetting — Paramètre de configuration stocké en base de données.
 *
 * Cette entité permet à l'admin de modifier des paramètres applicatifs
 * directement depuis l'interface web, sans toucher aux fichiers .env.
 *
 * Exemples d'utilisation :
 *   - Clé API Anthropic (Claude Haiku pour l'extracteur LLM)
 *   - Activation/désactivation du scraping automatique
 *   - Paramètres email, URLs de services externes, etc.
 *
 * Pourquoi en BDD plutôt que dans .env ?
 *   → L'admin peut modifier ces valeurs sans redémarrer les containers
 *   → Historique des modifications via updatedAt
 *   → Masquage des secrets dans l'UI (isSecret = true)
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
#[ORM\HasLifecycleCallbacks]
class AppSetting
{
    /**
     * Identifiant auto-généré par PostgreSQL (séquence).
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Clé technique unique du paramètre (slug en snake_case).
     * Exemples : "anthropic_api_key", "scraping_enabled", "mailer_from"
     *
     * IMPORTANT : cette clé est utilisée dans le code PHP pour lire la valeur.
     * Ne jamais la renommer sans mettre à jour les références dans le code.
     */
    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $settingKey;

    /**
     * Valeur du paramètre, stockée en texte libre.
     * Peut être null si le paramètre n'a pas encore été configuré.
     *
     * Exemples :
     *   - "sk-ant-api03-..."  (clé API)
     *   - "1" ou "0"          (booléen simulé)
     *   - "contact@bazaart.fr" (adresse email)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $settingValue = null;

    /**
     * Si true, la valeur est masquée dans l'interface admin (champ password).
     * À utiliser pour les clés API, mots de passe, tokens secrets.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isSecret = false;

    /**
     * Libellé en français affiché dans l'interface admin.
     * Exemple : "Clé API Anthropic (Claude Haiku)"
     */
    #[ORM\Column(type: 'string', length: 200)]
    private string $label;

    /**
     * Description optionnelle pour expliquer à l'admin l'utilité du paramètre.
     * Exemple : "Utilisée pour l'extraction LLM des sources HTML sans RSS."
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Date de la dernière modification, mise à jour automatiquement via PreUpdate.
     * Null tant que le paramètre n'a jamais été modifié (seulement créé).
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    // ── Lifecycle Callbacks ──────────────────────────────────────────────────

    /**
     * Mise à jour automatique de updatedAt avant chaque UPDATE en BDD.
     * Le lifecycle callback #[ORM\PreUpdate] est déclenché par Doctrine
     * juste avant d'exécuter la requête UPDATE SQL.
     *
     * POURQUOI nullable ? updatedAt reste null à la création (PrePersist non utilisé ici
     * car ce n'est pas pertinent — l'admin verra "jamais modifié" pour les settings seedés).
     */
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function setSettingKey(string $settingKey): static
    {
        $this->settingKey = $settingKey;
        return $this;
    }

    public function getSettingValue(): ?string
    {
        return $this->settingValue;
    }

    public function setSettingValue(?string $settingValue): static
    {
        $this->settingValue = $settingValue;
        return $this;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    public function setIsSecret(bool $isSecret): static
    {
        $this->isSecret = $isSecret;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
