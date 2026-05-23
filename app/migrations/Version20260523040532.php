<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523040532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization_profiles ADD is_structure_partner BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE organization_profiles ADD structure_activated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_profiles ADD structure_application_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_profiles ADD structure_activation_validated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_profiles ADD CONSTRAINT FK_64284792EFFEBC4E FOREIGN KEY (structure_activation_validated_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_64284792EFFEBC4E ON organization_profiles (structure_activation_validated_by_id)');
        $this->addSql('ALTER TABLE resources ADD submitter_role VARCHAR(20) DEFAULT \'artist\' NOT NULL');
        $this->addSql('ALTER TABLE resources ADD auto_published BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE resources ADD published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD validated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD validated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE resources ADD CONSTRAINT FK_EF66EBAEC69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_EF66EBAEC69DE5E5 ON resources (validated_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization_profiles DROP CONSTRAINT FK_64284792EFFEBC4E');
        $this->addSql('DROP INDEX IDX_64284792EFFEBC4E');
        $this->addSql('ALTER TABLE organization_profiles DROP is_structure_partner');
        $this->addSql('ALTER TABLE organization_profiles DROP structure_activated_at');
        $this->addSql('ALTER TABLE organization_profiles DROP structure_application_at');
        $this->addSql('ALTER TABLE organization_profiles DROP structure_activation_validated_by_id');
        $this->addSql('ALTER TABLE resources DROP CONSTRAINT FK_EF66EBAEC69DE5E5');
        $this->addSql('DROP INDEX IDX_EF66EBAEC69DE5E5');
        $this->addSql('ALTER TABLE resources DROP submitter_role');
        $this->addSql('ALTER TABLE resources DROP auto_published');
        $this->addSql('ALTER TABLE resources DROP published_at');
        $this->addSql('ALTER TABLE resources DROP validated_at');
        $this->addSql('ALTER TABLE resources DROP validated_by_id');
    }
}
