<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1 — Formations événement (ADR-0014 Option A).
 *
 * Ajoute 7 colonnes à la table `courses` pour supporter le nouveau type
 * de formation "événement" (visio ou présentiel) en plus du type historique
 * "contenu" (modules/leçons asynchrones).
 *
 * Colonnes ajoutées :
 *   type             VARCHAR(20) NOT NULL DEFAULT 'content'
 *                    → CourseType enum : 'content' | 'event'
 *                    → DEFAULT 'content' : toutes les formations existantes
 *                       deviennent de type CONTENU sans aucune perte de données.
 *
 *   event_mode       VARCHAR(20) NULL
 *                    → CourseEventMode enum : 'online' | 'in_person'
 *                    → null pour les formations CONTENU
 *
 *   event_start_at   TIMESTAMP NULL  → date/heure de début de l'événement
 *   event_end_at     TIMESTAMP NULL  → date/heure de fin de l'événement
 *
 *   event_location   VARCHAR(255) NULL → adresse physique (mode présentiel)
 *   event_external_url VARCHAR(500) NULL → lien de connexion (mode visio)
 *
 *   capacity         INT NULL
 *                    → nombre de places max (null = illimité)
 *
 * Rollback : down() supprime les 7 colonnes. Sans impact sur les formations
 * CONTENU existantes qui ne les utilisaient pas.
 */
final class Version20260618154123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 formations-événement (ADR-0014) : ajout type, event_mode, event_start_at, event_end_at, event_location, event_external_url, capacity sur la table courses.';
    }

    public function up(Schema $schema): void
    {
        // Colonne type : NOT NULL avec DEFAULT 'content'.
        // L'instruction ALTER TABLE applique la valeur par défaut à toutes les
        // lignes existantes → les formations historiques deviennent CONTENU.
        $this->addSql("ALTER TABLE courses ADD type VARCHAR(20) DEFAULT 'content' NOT NULL");

        // Mode événement (VISIO / PRESENTIEL) — nullable (null pour les CONTENU)
        $this->addSql('ALTER TABLE courses ADD event_mode VARCHAR(20) DEFAULT NULL');

        // Dates de l'événement — TIMESTAMP sans timezone (UTC), nullable
        $this->addSql('ALTER TABLE courses ADD event_start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE courses ADD event_end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Lieu (présentiel) — VARCHAR 255, nullable
        $this->addSql('ALTER TABLE courses ADD event_location VARCHAR(255) DEFAULT NULL');

        // Lien de connexion (visio) — VARCHAR 500 comme trailerVideoUrl, nullable
        $this->addSql('ALTER TABLE courses ADD event_external_url VARCHAR(500) DEFAULT NULL');

        // Capacité en nombre de places — INT nullable (null = illimité)
        $this->addSql('ALTER TABLE courses ADD capacity INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Suppression des 7 colonnes dans l'ordre inverse de création
        $this->addSql('ALTER TABLE courses DROP capacity');
        $this->addSql('ALTER TABLE courses DROP event_external_url');
        $this->addSql('ALTER TABLE courses DROP event_location');
        $this->addSql('ALTER TABLE courses DROP event_end_at');
        $this->addSql('ALTER TABLE courses DROP event_start_at');
        $this->addSql('ALTER TABLE courses DROP event_mode');
        $this->addSql('ALTER TABLE courses DROP type');
    }
}
