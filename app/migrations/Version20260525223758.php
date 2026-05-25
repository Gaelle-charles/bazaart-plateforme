<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260525223758 extends AbstractMigration
{
    public function getDescription(): string
    {
        // Ajoute le champ anonymized_at sur la table users pour le module RGPD.
        // Ce champ est null pour les comptes actifs et rempli lors de l'anonymisation
        // (demande de suppression de compte — art. 17 RGPD droit à l'effacement).
        return 'RGPD : ajoute anonymized_at sur users pour tracer les anonymisations de comptes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD anonymized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP anonymized_at');
    }
}
