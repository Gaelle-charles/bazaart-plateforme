<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0036 (point D) — Corrige les URL de 2 agrégateurs devenues mortes (HTTP 404).
 *
 * Diagnostic (voir ADR-0036 et docs/scraping.md) : les URL seedées initialement pour
 * ces deux agrégateurs ne répondent plus HTTP 200 depuis mai 2026 :
 *   - On The Move  : "https://on-the-move.org/calls" (404) → l'URL vivante et déjà
 *     utilisée par OnTheMoveScraper est "https://on-the-move.org/news/deadlines"
 *     (cf. docs/scraping.md §4 — historique des URLs testées).
 *   - EACEA / Creative Europe : "https://eacea.ec.europa.eu/grants_en" (404) → l'URL
 *     vivante et déjà utilisée par CultureMovesEuropeScraper est
 *     "https://culture.ec.europa.eu/fr/funding".
 *
 * Ces 2 sources sont marquées estAgregateur = true : tant que leur URL en BDD était
 * morte, app:discover-sources ne pouvait jamais rien découvrir depuis elles — un des
 * facteurs identifiés du diagnostic ADR-0036 (sources stériles).
 *
 * SQL idempotent (UPDATE ... WHERE url = 'ancienne valeur') : rejouer cette migration
 * sur une base déjà à jour ne fait rien (0 ligne affectée), conformément à la
 * convention du projet pour les migrations de données.
 */
final class Version20260907230632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "ADR-0036 : corrige les URL mortes (HTTP 404) de 2 agrégateurs "
            . "(On The Move, EACEA/Creative Europe) pour que app:discover-sources "
            . "puisse à nouveau les analyser.";
    }

    public function up(Schema $schema): void
    {
        // GARDE-FOU (contrainte d'unicité) : scraping_sources.url est UNIQUE
        // (ScrapingSource::$url, unique: true — index UNIQ_AAFABD16F47645AE).
        // Si un admin a déjà ajouté à la main une source avec la NOUVELLE URL
        // (ex. via « En faire une source » ou app:discover-listing-urls), un UPDATE
        // brut violerait l'index et ferait échouer toute la migration en prod.
        // On ne renomme donc QUE si la nouvelle URL n'existe pas encore ; sinon
        // l'ancienne ligne (morte) est laissée telle quelle et pourra être
        // désactivée/supprimée par l'admin — mieux qu'un déploiement bloqué.
        $this->addSql(
            "UPDATE scraping_sources SET url = 'https://on-the-move.org/news/deadlines' "
            . "WHERE url = 'https://on-the-move.org/calls' "
            . "AND NOT EXISTS (SELECT 1 FROM scraping_sources s2 "
            . "WHERE s2.url = 'https://on-the-move.org/news/deadlines')"
        );
        $this->addSql(
            "UPDATE scraping_sources SET url = 'https://culture.ec.europa.eu/fr/funding' "
            . "WHERE url = 'https://eacea.ec.europa.eu/grants_en' "
            . "AND NOT EXISTS (SELECT 1 FROM scraping_sources s2 "
            . "WHERE s2.url = 'https://culture.ec.europa.eu/fr/funding')"
        );
    }

    public function down(Schema $schema): void
    {
        // Inverse exact — restaure les anciennes URL (mortes, mais on garde la
        // réversibilité de la migration par convention du projet).
        // Même garde-fou d'unicité que dans up().
        $this->addSql(
            "UPDATE scraping_sources SET url = 'https://on-the-move.org/calls' "
            . "WHERE url = 'https://on-the-move.org/news/deadlines' "
            . "AND NOT EXISTS (SELECT 1 FROM scraping_sources s2 "
            . "WHERE s2.url = 'https://on-the-move.org/calls')"
        );
        $this->addSql(
            "UPDATE scraping_sources SET url = 'https://eacea.ec.europa.eu/grants_en' "
            . "WHERE url = 'https://culture.ec.europa.eu/fr/funding' "
            . "AND NOT EXISTS (SELECT 1 FROM scraping_sources s2 "
            . "WHERE s2.url = 'https://eacea.ec.europa.eu/grants_en')"
        );
    }
}
