<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : insertion idempotente du setting 'archive_use_legacy'.
 *
 * Ce setting contrôle le branchement dans ScrapeOpportunitiesCommand :
 *   - valeur '0' (défaut) → utilise archiveExpired() en DQL (performant)
 *   - valeur '1'          → bascule sur archiveExpiredLegacy() (PHP string-parsing)
 *
 * Le ON CONFLICT DO NOTHING rend la migration idempotente :
 * si la ligne a déjà été insérée manuellement en SQL, elle n'est pas dupliquée
 * et la migration réussit quand même.
 */
final class Version20260528014450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Insère le setting 'archive_use_legacy' (feature flag archivage DQL vs legacy) s'il n'existe pas encore. "
            . "Le down() ne supprime la ligne QUE si la valeur est encore '0' — un rollback avec valeur modifiée laisse la ligne en place (intentionnel).";
    }

    public function up(Schema $schema): void
    {
        // Insertion idempotente : ON CONFLICT DO NOTHING évite un doublon si la ligne
        // a été insérée manuellement avant cette migration.
        // La valeur par défaut est '0' : on utilise la version DQL (archiveExpired()).
        // Note : la table app_settings n'a pas de colonne created_at — uniquement updated_at.
        $this->addSql(<<<'SQL'
            INSERT INTO app_settings (setting_key, setting_value, is_secret, label, description, updated_at)
            VALUES (
                'archive_use_legacy',
                '0',
                false,
                'Forcer l''ancienne logique d''archivage',
                'Filet de sécurité : si défini à 1, utilise archiveExpiredLegacy() (PHP) au lieu de archiveExpired() (DQL). À activer en cas de régression du DQL.',
                NULL
            )
            ON CONFLICT (setting_key) DO NOTHING
        SQL);
    }

    public function down(Schema $schema): void
    {
        // On ne supprime la ligne que si la valeur n'a pas été modifiée par un admin.
        // Raisonnement : si quelqu'un a changé le setting à '1' (mode legacy activé),
        // supprimer la ligne sans condition efface une config en production → dangereux.
        // Avec ce DELETE conditionnel, un admin ayant changé la valeur ne perd rien.
        $this->addSql(<<<'SQL'
            DELETE FROM app_settings
            WHERE setting_key = 'archive_use_legacy'
              AND setting_value = '0'
        SQL);
    }
}
