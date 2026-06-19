<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619034700 extends AbstractMigration
{
    public function getDescription(): string
    {
        // ADR-0018 : ajout des champs candidature + financement sur Resource et ScrapedResource.
        // Migration non-destructive : DEFAULT NULL, les lignes existantes gardent NULL.
        // Colonnes ajoutées :
        //   how_to_apply   (TEXT)          : modalités de candidature (LLM)
        //   funding_amount (VARCHAR 255)   : montant lisible du financement
        //   funding_type   (VARCHAR 255)   : nature du financement
        return 'ADR-0018 : howToApply/fundingAmount/fundingType sur resources et scraped_resources';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resources ADD how_to_apply TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD funding_amount VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD funding_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD how_to_apply TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD funding_amount VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE scraped_resources ADD funding_type VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resources DROP how_to_apply');
        $this->addSql('ALTER TABLE resources DROP funding_amount');
        $this->addSql('ALTER TABLE resources DROP funding_type');
        $this->addSql('ALTER TABLE scraped_resources DROP how_to_apply');
        $this->addSql('ALTER TABLE scraped_resources DROP funding_amount');
        $this->addSql('ALTER TABLE scraped_resources DROP funding_type');
    }
}
