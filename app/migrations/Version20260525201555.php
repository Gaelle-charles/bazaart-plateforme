<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260525201555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_artist_disciplines_profile RENAME TO IDX_8427EA9F2F85CDC1');
        $this->addSql('ALTER INDEX idx_artist_disciplines_discipline RENAME TO IDX_8427EA9FA5522701');
        $this->addSql('ALTER INDEX idx_97b31e6aa76ed395 RENAME TO IDX_B8B6F1E6A76ED395');
        $this->addSql('ALTER INDEX idx_97b31e6a591cc992 RENAME TO IDX_B8B6F1E6591CC992');
        $this->addSql('ALTER INDEX idx_d0c0e4c591cc992 RENAME TO IDX_2674463B591CC992');
        $this->addSql('ALTER INDEX uniq_a9185589989d9b62 RENAME TO UNIQ_A9A55A4C989D9B62');
        $this->addSql('ALTER INDEX idx_3f12f21aadb5c28 RENAME TO IDX_68C6BDF88F7DB25B');
        $this->addSql('ALTER INDEX idx_3f12f21acdf80196 RENAME TO IDX_68C6BDF8CDF80196');
        $this->addSql('ALTER INDEX idx_ab9e0e5bcdf80196 RENAME TO IDX_8B450424CDF80196');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_8427ea9fa5522701 RENAME TO idx_artist_disciplines_discipline');
        $this->addSql('ALTER INDEX idx_8427ea9f2f85cdc1 RENAME TO idx_artist_disciplines_profile');
        $this->addSql('ALTER INDEX idx_b8b6f1e6591cc992 RENAME TO idx_97b31e6a591cc992');
        $this->addSql('ALTER INDEX idx_b8b6f1e6a76ed395 RENAME TO idx_97b31e6aa76ed395');
        $this->addSql('ALTER INDEX idx_2674463b591cc992 RENAME TO idx_d0c0e4c591cc992');
        $this->addSql('ALTER INDEX uniq_a9a55a4c989d9b62 RENAME TO uniq_a9185589989d9b62');
        $this->addSql('ALTER INDEX idx_68c6bdf88f7db25b RENAME TO idx_3f12f21aadb5c28');
        $this->addSql('ALTER INDEX idx_68c6bdf8cdf80196 RENAME TO idx_3f12f21acdf80196');
        $this->addSql('ALTER INDEX idx_8b450424cdf80196 RENAME TO idx_ab9e0e5bcdf80196');
    }
}
