<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration manuelle — ajout de ON DELETE RESTRICT explicite sur la FK host_user_id.
 *
 * Contexte :
 *   La migration Version20260525212745 a créé la contrainte FK entre lives.host_user_id
 *   et users.id SANS clause ON DELETE. PostgreSQL applique alors implicitement NO ACTION,
 *   qui est fonctionnellement proche de RESTRICT mais n'est pas exprimé dans le schéma.
 *
 * Objectif :
 *   Rendre l'intention explicite : un User qui anime des lives ne peut pas être supprimé
 *   sans traiter ses lives au préalable. RESTRICT est la sémantique la plus sûre ici.
 *
 * Stratégie PostgreSQL :
 *   On ne peut pas modifier une contrainte FK en place (ALTER TABLE ... ALTER CONSTRAINT
 *   ne prend pas en charge le changement de ON DELETE sur PostgreSQL < 15).
 *   On doit donc : DROP l'ancienne contrainte + ADD la nouvelle avec ON DELETE RESTRICT.
 *
 * Note : cette opération est rapide (pas de scan de table) et safe en production
 * car elle ne modifie que le catalogue système.
 */
final class Version20260525220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lives : ajout explicite ON DELETE RESTRICT sur la FK host_user_id → users';
    }

    public function up(Schema $schema): void
    {
        // Étape 1 : suppression de la contrainte FK existante (sans clause ON DELETE)
        $this->addSql('ALTER TABLE lives DROP CONSTRAINT FK_5D347E5E9092FFA4');

        // Étape 2 : recréation de la FK avec ON DELETE RESTRICT explicite.
        // RESTRICT = PostgreSQL refuse de supprimer un User tant qu'il a des lives associés.
        // C'est la règle métier correcte : on ne peut pas faire disparaître l'hôte d'un live.
        $this->addSql(
            'ALTER TABLE lives ADD CONSTRAINT FK_5D347E5E9092FFA4 '
            . 'FOREIGN KEY (host_user_id) REFERENCES users (id) '
            . 'ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }

    public function down(Schema $schema): void
    {
        // Retour à l'état précédent : FK sans clause ON DELETE explicite (NO ACTION implicite)
        $this->addSql('ALTER TABLE lives DROP CONSTRAINT FK_5D347E5E9092FFA4');
        $this->addSql(
            'ALTER TABLE lives ADD CONSTRAINT FK_5D347E5E9092FFA4 '
            . 'FOREIGN KEY (host_user_id) REFERENCES users (id) '
            . 'NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }
}
