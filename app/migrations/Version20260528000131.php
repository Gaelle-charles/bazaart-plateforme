<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528000131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chantier 2A — Ajout de la colonne deadline_date (datetime_immutable nullable) sur scraped_resources. '
             . 'Alimentée par ScrapedResourceListener (prePersist/preUpdate) via DeadlineParserService. '
             . 'Après migration, lancer : php bin/console app:backfill-deadline-date';
    }

    public function up(Schema $schema): void
    {
        // Ajout de deadline_date : colonne nullable pour ne pas bloquer les lignes existantes.
        // Elle sera remplie en rétro-remplissage par app:backfill-deadline-date.
        // PostgreSQL stocke les TIMESTAMP WITHOUT TIME ZONE en UTC — cohérent avec Doctrine datetime_immutable.
        $this->addSql('ALTER TABLE scraped_resources ADD deadline_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scraped_resources DROP deadline_date');
    }
}
