<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706223423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute ScrapingSource.allowInsecureSsl : contournement TLS ciblé par source '
            . '(ex: resartis.org, certificat sans CA intermédiaire) pour le pipeline RSS.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scraping_sources ADD allow_insecure_ssl BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scraping_sources DROP allow_insecure_ssl');
    }
}
