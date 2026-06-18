<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618181356 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3 annulation/remboursement événements : '
            . 'statut inscription (ACTIVE/CANCELLED), cancelledAt sur course_enrollments ; '
            . 'refundedAt, stripeRefundId sur course_payments. '
            . 'Migration non destructive : les inscriptions existantes héritent du statut DEFAULT "active".';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE course_enrollments ADD status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE course_enrollments ADD cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE course_payments ADD refunded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE course_payments ADD stripe_refund_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE course_enrollments DROP status');
        $this->addSql('ALTER TABLE course_enrollments DROP cancelled_at');
        $this->addSql('ALTER TABLE course_payments DROP refunded_at');
        $this->addSql('ALTER TABLE course_payments DROP stripe_refund_id');
    }
}
