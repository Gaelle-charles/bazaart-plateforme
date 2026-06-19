<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619040230 extends AbstractMigration
{
    public function getDescription(): string
    {
        // ADR-0019 : ajout des colonnes application_url (lien candidature distinct)
        // et logo_url (logo de l'organisme) sur resources et scraped_resources.
        // Toutes les colonnes sont nullable — migration non-destructive.
        return 'ADR-0019 : add application_url and logo_url to resources and scraped_resources';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resources ADD application_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD logo_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD application_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD logo_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resources DROP application_url');
        $this->addSql('ALTER TABLE resources DROP logo_url');
        $this->addSql('ALTER TABLE scraped_resources DROP application_url');
        $this->addSql('ALTER TABLE scraped_resources DROP logo_url');
    }
}
