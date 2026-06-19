<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Onboarding (Lot 2) — Ajout des champs onboarding sur la table users.
 *
 * Champs ajoutés :
 *   - onboarding_completed : bool, NOT NULL, default false (nouveaux comptes)
 *   - looking_for          : json, nullable (objectifs artiste : formations, aides, appels...)
 *   - looking_for_other    : varchar(255), nullable (texte libre si case "Autre")
 *
 * IMPORTANT : Pour ne pas bloquer les comptes EXISTANTS avec le nouveau gating
 * (OnboardingGatingListener), on met à jour IMMÉDIATEMENT après l'ALTER TABLE
 * tous les utilisateurs déjà créés avec onboarding_completed = true.
 * Seuls les comptes créés APRÈS cette migration partiront à false et seront
 * dirigés vers l'onboarding.
 */
final class Version20260618210427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Onboarding Lot 2 : champs onboarding_completed, looking_for, looking_for_other '
            . 'sur la table users. Les comptes existants sont mis à onboarding_completed=true '
            . 'pour éviter de bloquer les utilisateurs déjà créés.';
    }

    public function up(Schema $schema): void
    {
        // ── Étape 1 : Ajouter les colonnes ────────────────────────────────────
        //
        // onboarding_completed : DEFAULT false pour les futurs inserts SQL,
        //   mais on va immédiatement UPDATE les lignes existantes à true ci-dessous.
        // looking_for          : JSON nullable — non renseigné avant l'onboarding
        // looking_for_other    : texte libre pour la case "Autre" — nullable
        $this->addSql('ALTER TABLE users ADD onboarding_completed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD looking_for JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD looking_for_other VARCHAR(255) DEFAULT NULL');

        // ── Étape 2 : Mettre à true les comptes EXISTANTS ─────────────────────
        //
        // Sans ce UPDATE, tous les utilisateurs créés avant le Lot 2 se verraient
        // forcés vers l'onboarding au prochain login, même s'ils ont déjà un profil.
        //
        // On met onboarding_completed = true pour TOUS les users existants.
        // Les users créés après cette migration partiront bien à false (DEFAULT ci-dessus).
        //
        // Note : addSql() exécute ce SQL dans la même transaction que les ALTER TABLE.
        // Si la migration échoue, tout est rollback automatiquement (PostgreSQL).
        $this->addSql('UPDATE users SET onboarding_completed = true');
    }

    public function down(Schema $schema): void
    {
        // En cas de rollback : suppression des trois colonnes.
        // Les données (looking_for, lookingForOther) seront perdues — acceptable en dev.
        $this->addSql('ALTER TABLE users DROP onboarding_completed');
        $this->addSql('ALTER TABLE users DROP looking_for');
        $this->addSql('ALTER TABLE users DROP looking_for_other');
    }
}
