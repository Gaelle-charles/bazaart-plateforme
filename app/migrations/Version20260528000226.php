<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528000226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chantier 2A — Nettoyage : retrait du commentaire PostgreSQL sur deadline_date (non géré par Doctrine, crée une divergence de schéma).';
    }

    public function up(Schema $schema): void
    {
        // Doctrine ne gère pas les commentaires PostgreSQL ajoutés manuellement.
        // Cette migration resynchronise le schéma en supprimant le commentaire
        // ajouté par erreur dans Version20260528000131.
        $this->addSql('COMMENT ON COLUMN scraped_resources.deadline_date IS \'\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN scraped_resources.deadline_date IS \'Date de clôture parsée depuis deadline (string) — gérée par ScrapedResourceListener\'');
    }
}
