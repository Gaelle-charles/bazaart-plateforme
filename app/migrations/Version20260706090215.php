<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706090215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de 4 index sur scraped_resources (status, deadline_date, source_site, enriched_at) '
            . 'pour les requêtes fréquentes des onglets admin, de l\'archivage automatique et de l\'enrichissement LLM.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_scraped_resources_status ON scraped_resources (status)');
        $this->addSql('CREATE INDEX idx_scraped_resources_deadline_date ON scraped_resources (deadline_date)');
        $this->addSql('CREATE INDEX idx_scraped_resources_source_site ON scraped_resources (source_site)');
        $this->addSql('CREATE INDEX idx_scraped_resources_enriched_at ON scraped_resources (enriched_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_scraped_resources_status');
        $this->addSql('DROP INDEX idx_scraped_resources_deadline_date');
        $this->addSql('DROP INDEX idx_scraped_resources_source_site');
        $this->addSql('DROP INDEX idx_scraped_resources_enriched_at');
    }
}
