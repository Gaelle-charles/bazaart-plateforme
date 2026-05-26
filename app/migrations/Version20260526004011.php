<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526004011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne read_at (datetime nullable) à la table notifications — enregistre la date de première lecture.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications ADD read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications DROP read_at');
    }
}
