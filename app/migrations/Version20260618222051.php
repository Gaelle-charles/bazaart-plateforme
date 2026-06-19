<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0016 Lot 1 — Localisation fine et niveau d'expérience sur Resource et ScrapedResource.
 *
 * Ajoute 3 colonnes nullable (non-destructif) sur chacune des deux tables :
 *   - city (VARCHAR 150)         : ville extraite par le LLM
 *   - country (VARCHAR 100)      : pays en clair extrait par le LLM
 *   - experience_level (VARCHAR 20) : beginner | intermediate | experienced (backed value ExperienceLevel)
 *
 * Toutes les colonnes sont DEFAULT NULL :
 *   - Les lignes existantes conservent NULL, pas de valeur par défaut forcée.
 *   - Pas de migration de données nécessaire.
 *   - Le scraper enrichi remplira ces colonnes lors des prochains runs.
 *
 * Migration strictement additive — aucun DROP, aucune modification de colonne existante.
 */
final class Version20260618222051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0016 Lot 1 : ajout city, country, experience_level (nullable) sur resources et scraped_resources';
    }

    public function up(Schema $schema): void
    {
        // ── Table resources ────────────────────────────────────────────────────
        // Toutes les nouvelles colonnes sont nullable (DEFAULT NULL) :
        //   - migration non-destructive : les ressources existantes gardent NULL
        //   - pas de valeur de transition SQL nécessaire (contrairement aux colonnes NOT NULL)
        $this->addSql('ALTER TABLE resources ADD city VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD country VARCHAR(100) DEFAULT NULL');
        // experience_level stocke la backed value de ExperienceLevel enum
        // Valeurs valides : 'beginner', 'intermediate', 'experienced' — ou NULL (tous niveaux)
        $this->addSql('ALTER TABLE resources ADD experience_level VARCHAR(20) DEFAULT NULL');

        // ── Table scraped_resources ────────────────────────────────────────────
        // Mêmes colonnes, même convention : remplis par LlmExtractorService lors du scraping.
        $this->addSql('ALTER TABLE scraped_resources ADD city VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD country VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD experience_level VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Rollback : suppression des colonnes ajoutées.
        // Exécutable sans risque si les colonnes sont encore vides (nouveau déploiement).
        $this->addSql('ALTER TABLE resources DROP city');
        $this->addSql('ALTER TABLE resources DROP country');
        $this->addSql('ALTER TABLE resources DROP experience_level');
        $this->addSql('ALTER TABLE scraped_resources DROP city');
        $this->addSql('ALTER TABLE scraped_resources DROP country');
        $this->addSql('ALTER TABLE scraped_resources DROP experience_level');
    }
}
