<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout du champ enriched_at (marqueur d'enrichissement LLM) sur resources et scraped_resources.
 *
 * OBJECTIF :
 *   Permettre à app:enrich-opportunities de n'enrichir chaque item QU'UNE SEULE FOIS.
 *   Avant ce changement, la commande ciblait les items sans description — mais après
 *   un premier enrichissement, une description était présente → sélection basée sur
 *   description IS NULL était peu fiable pour le suivi (disciplines, howToApply, etc.
 *   pouvaient être vides sans que la description le soit).
 *
 *   enriched_at = NULL  → jamais enrichi via LLM → éligible au run cron
 *   enriched_at non NULL → déjà enrichi → EXCLU de la sélection sans --force
 *
 * BACKFILL :
 *   Le stock actuel (opportunités avec une description non vide) a déjà été enrichi
 *   lors des passes LLM précédentes. On les marque comme enrichis immédiatement
 *   pour éviter que le cron nuit ne les retraite tous en masse le lendemain du déploiement.
 *
 *   Critère de backfill : description IS NOT NULL AND description <> ''
 *   (on exclut les placeholders vides — ils n'ont pas réellement été enrichis).
 *
 * IDEMPOTENCE :
 *   Les colonnes sont nullable (DEFAULT NULL) → ALTER TABLE non-destructif.
 *   Le backfill UPDATE est idempotent : re-exécuter cette migration ne change rien
 *   (les enriched_at déjà positionnés restent inchangés grâce au WHERE IS NULL).
 */
final class Version20260619113604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout enriched_at (marqueur enrichissement LLM unique) sur resources et scraped_resources + backfill du stock existant';
    }

    public function up(Schema $schema): void
    {
        // ── Étape 1 : Ajout des colonnes nullable ─────────────────────────────
        // DEFAULT NULL : migration non-destructive, tous les existants démarrent à NULL.
        // TIMESTAMP(0) : précision à la seconde (cohérent avec les autres champs datetime
        //   de ces tables : created_at, updated_at, scraped_at…).
        $this->addSql('ALTER TABLE resources ADD enriched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD enriched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // ── Étape 2 : Backfill du stock existant ──────────────────────────────
        //
        // Toutes les opportunités qui ont déjà une description non vide ont été
        // enrichies lors des passes LLM précédentes (app:enrich-opportunities).
        // On les marque comme "enrichi maintenant" pour que le cron ne les retraite pas.
        //
        // ON EXCLUT intentionnellement :
        //   - description IS NULL        → jamais enrichi, normal qu'il soit NULL
        //   - description = ''           → chaîne vide (scraper sans description)
        //   - description = 'Description non disponible.' → placeholder scraper
        //
        // CES EXCLUSIONS signifient que les items sans description réelle resteront
        // à enriched_at = NULL → ils seront traités lors du prochain run de la commande.
        // C'est le comportement souhaité.
        //
        // On utilise addSql() avec du SQL natif PostgreSQL directement :
        //   - Doctrine Migrations supporte le SQL natif dans up()/down()
        //   - NOW() est la fonction PostgreSQL pour l'horodatage courant
        //   - Le WHERE enriched_at IS NULL rend ce UPDATE idempotent
        $this->addSql(
            "UPDATE scraped_resources
             SET enriched_at = NOW()
             WHERE enriched_at IS NULL
               AND description IS NOT NULL
               AND description <> ''
               AND description <> 'Description non disponible.'"
        );

        $this->addSql(
            "UPDATE resources
             SET enriched_at = NOW()
             WHERE enriched_at IS NULL
               AND description IS NOT NULL
               AND description <> ''
               AND description <> 'Description non disponible.'"
        );
    }

    public function down(Schema $schema): void
    {
        // Suppression des colonnes — le backfill est perdu mais c'est acceptable :
        // si on rollback cette migration, on revient au comportement pre-enrichedAt
        // (sélection basée sur description IS NULL dans findForEnrichment()).
        $this->addSql('ALTER TABLE resources DROP enriched_at');
        $this->addSql('ALTER TABLE scraped_resources DROP enriched_at');
    }
}
