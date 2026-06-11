<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout des champs de réinitialisation de mot de passe sur la table users.
 *
 * Deux colonnes ajoutées :
 *
 *   reset_token_hash (VARCHAR 64, nullable) :
 *     Hash SHA-256 du token de réinitialisation.
 *     NULL si aucune demande en cours.
 *     On ne stocke JAMAIS le token en clair — sécurité.
 *     Taille : SHA-256 produit 64 chars hexadécimaux.
 *     Index dédié idx_users_reset_token_hash pour que la recherche
 *     par token soit rapide (appelée à chaque clic sur le lien email).
 *
 *   reset_token_expires_at (TIMESTAMP, nullable) :
 *     Date d'expiration du token (généralement now + 1 heure).
 *     NULL si pas de token actif.
 *     Vérifié dans PasswordResetService::validateToken() pour rejeter
 *     les tokens expirés même si leur hash correspond.
 */
final class Version20260611000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout reset_token_hash et reset_token_expires_at sur users — fonctionnalité mot de passe oublié';
    }

    public function up(Schema $schema): void
    {
        // ── reset_token_hash ──────────────────────────────────────────────────
        // Colonne nullable : NULL = pas de demande en cours (état par défaut pour
        // tous les utilisateurs existants après la migration).
        // VARCHAR(64) : taille exacte d'un hash SHA-256 en hexadécimal.
        $this->addSql('ALTER TABLE users ADD reset_token_hash VARCHAR(64) DEFAULT NULL');

        // Index sur reset_token_hash pour accélérer la recherche par token.
        // UserRepository::findByResetTokenHash() exécute un SELECT WHERE reset_token_hash = ?
        // à chaque fois qu'un utilisateur clique sur le lien de réinitialisation.
        // Sans index, cette requête serait un full scan de la table users.
        $this->addSql('CREATE INDEX idx_users_reset_token_hash ON users (reset_token_hash)');

        // ── reset_token_expires_at ────────────────────────────────────────────
        // TIMESTAMP sans timezone (cohérent avec les autres colonnes datetime de la table).
        // DEFAULT NULL : aucun token actif pour les utilisateurs existants.
        $this->addSql('ALTER TABLE users ADD reset_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Suppression de l'index avant la colonne (obligation PostgreSQL :
        // on ne peut pas supprimer une colonne indexée sans supprimer l'index d'abord,
        // sauf si l'index a été créé explicitement — ce qui est le cas ici).
        $this->addSql('DROP INDEX IF EXISTS idx_users_reset_token_hash');

        // Suppression des deux colonnes dans l'ordre inverse de leur création
        $this->addSql('ALTER TABLE users DROP reset_token_expires_at');
        $this->addSql('ALTER TABLE users DROP reset_token_hash');
    }
}
