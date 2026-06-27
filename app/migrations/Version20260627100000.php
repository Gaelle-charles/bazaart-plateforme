<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Ajout de first_name et last_name sur la table users.
 *
 * CONTEXTE :
 *   Le formulaire d'inscription collecte désormais le prénom et le nom de famille
 *   de chaque nouvel utilisateur (champs obligatoires côté formulaire et RegisterDTO).
 *   Cette migration ajoute les colonnes correspondantes en BDD.
 *
 * RÉTROCOMPATIBILITÉ :
 *   Les deux colonnes sont NULLABLE (DEFAULT NULL).
 *   Raison : les 22 comptes existants au moment du déploiement n'ont pas ces données.
 *   Rendre les colonnes NOT NULL nécessiterait un backfill préalable sur tous les
 *   comptes existants, ce qui n'est pas justifié pour le MVP V1.
 *   L'obligation est portée par le formulaire et RegisterDTO::fromArray(),
 *   pas par une contrainte BDD — approche classique pour les migrations d'ajout de champ.
 *
 * COLONNES :
 *   first_name — VARCHAR(100) DEFAULT NULL
 *   last_name  — VARCHAR(100) DEFAULT NULL
 *
 * TAILLE :
 *   100 caractères couvre les noms composés africains, hispanophones et asiatiques
 *   (ex : "Ayọ-Oluwatobi", "De La Vega-Castellanos"). Choix conservateur (pas 255)
 *   pour garder un BDD propre et des index éventuels efficaces.
 *
 * ROLLBACK :
 *   down() supprime les deux colonnes. Aucune contrainte FK ni index à gérer.
 *   Les données saisies seront perdues — acceptable car migration récente.
 */
final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de first_name et last_name (nullable) sur users — identité collectée à l\'inscription.';
    }

    public function up(Schema $schema): void
    {
        // Ajout de la colonne prénom.
        // VARCHAR(100) : large enough pour les noms composés.
        // DEFAULT NULL : les comptes existants gardent null jusqu'à une éventuelle saisie.
        $this->addSql(
            'ALTER TABLE users ADD first_name VARCHAR(100) DEFAULT NULL'
        );

        // Ajout de la colonne nom de famille.
        // Même politique que first_name.
        $this->addSql(
            'ALTER TABLE users ADD last_name VARCHAR(100) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // Suppression des deux colonnes en cas de rollback.
        // Les données de prénom/nom saisies depuis le déploiement seront perdues.
        $this->addSql('ALTER TABLE users DROP COLUMN first_name');
        $this->addSql('ALTER TABLE users DROP COLUMN last_name');
    }
}
