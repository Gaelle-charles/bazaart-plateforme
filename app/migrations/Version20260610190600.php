<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS1 — Extension de scraping_sources pour le pipeline multi-méthodes.
 *
 * Ajoute 4 colonnes sur la table scraping_sources :
 *   - feed_url            : URL du flux RSS/Atom distinct de l'URL de la page (nullable)
 *   - last_successful_fetch : horodatage du dernier fetch réussi (nullable, géré par WS3)
 *   - consecutive_failures  : compteur d'échecs consécutifs, remis à 0 au succès (défaut 0)
 *   - auto_publish          : flag préparatoire V2 sans effet en V1 (défaut false)
 */
final class Version20260610190600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS1 — Ajout feed_url, last_successful_fetch, consecutive_failures, auto_publish sur scraping_sources';
    }

    public function up(Schema $schema): void
    {
        // ── feed_url ─────────────────────────────────────────────────────────
        // URL du flux RSS/Atom de la source.
        // Distincte de `url` (page humaine de référence).
        // Renseignée par l'admin ou par app:detect-feeds (WS2 — futur).
        // NULL tant qu'aucun flux n'est connu.
        $this->addSql('ALTER TABLE scraping_sources ADD feed_url VARCHAR(500) DEFAULT NULL');

        // ── last_successful_fetch ─────────────────────────────────────────────
        // Date/heure du dernier run ayant produit des données exploitables.
        // Différent de derniere_execution (qui enregistre succès ET erreurs).
        // Mis à jour par l'orchestrateur du pipeline (WS3) après chaque succès.
        // NULL si jamais réussi.
        $this->addSql('ALTER TABLE scraping_sources ADD last_successful_fetch TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // ── consecutive_failures ──────────────────────────────────────────────
        // Compteur d'échecs consécutifs depuis le dernier succès.
        // Incrémenté à chaque erreur, remis à 0 au succès (géré par WS3).
        // À 5, l'orchestrateur (WS3) désactivera automatiquement la source.
        // NOT NULL avec DEFAULT 0 : cohérent avec la logique d'incrémentation.
        $this->addSql('ALTER TABLE scraping_sources ADD consecutive_failures INT DEFAULT 0 NOT NULL');

        // ── auto_publish ──────────────────────────────────────────────────────
        // Flag préparatoire — SANS EFFET EN V1.
        // En V1, toute ressource collectée part en file de modération (pending).
        // Ce champ est créé pour un usage futur (sources de confiance).
        // DEFAULT false = comportement prudent : modération manuelle par défaut.
        $this->addSql('ALTER TABLE scraping_sources ADD auto_publish BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Suppression des 4 colonnes en sens inverse
        // (rollback propre si la migration doit être annulée)
        $this->addSql('ALTER TABLE scraping_sources DROP feed_url');
        $this->addSql('ALTER TABLE scraping_sources DROP last_successful_fetch');
        $this->addSql('ALTER TABLE scraping_sources DROP consecutive_failures');
        $this->addSql('ALTER TABLE scraping_sources DROP auto_publish');
    }
}
