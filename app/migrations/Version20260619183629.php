<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot A matching (ADR-0021/0022) : ajout du champ legal_status sur artist_profiles.
 *
 * Ce champ stocke le statut juridique de l'artiste (valeur de l'enum LegalStatus).
 * Il est nullable car :
 *   - Les comptes existants n'ont pas encore renseigné ce champ
 *   - Le champ est optionnel dans l'onboarding (l'artiste peut passer l'étape 4)
 *
 * Valeurs possibles : artiste_auteur, autoentrepreneur, association, societe,
 *   en_structuration, autre (cf. App\Enum\LegalStatus)
 *
 * Migration non destructive : uniquement un ADD COLUMN nullable, aucun UPDATE requis.
 */
final class Version20260619183629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot A matching : ajout legal_status (nullable) sur artist_profiles pour l\'onboarding matching';
    }

    public function up(Schema $schema): void
    {
        // Ajout du champ legal_status : VARCHAR(30) car la valeur la plus longue
        // de l'enum LegalStatus est 'en_structuration' (16 caractères).
        // DEFAULT NULL car le champ est optionnel et non rempli pour les comptes existants.
        $this->addSql('ALTER TABLE artist_profiles ADD legal_status VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Rollback : suppression du champ (non destructif car toujours nullable)
        $this->addSql('ALTER TABLE artist_profiles DROP legal_status');
    }
}
