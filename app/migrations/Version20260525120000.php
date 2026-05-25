<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table de jointure artist_disciplines.
 *
 * Cette migration matérialise la relation ManyToMany entre ArtistProfile
 * et Discipline. Elle permet à un artiste d'associer plusieurs disciplines
 * artistiques à son profil public (ex : Musique + Danse + Arts visuels).
 *
 * Structure :
 *   artist_disciplines
 *     ├── artist_profile_id  INT  FK → artist_profiles.id  (ON DELETE CASCADE)
 *     └── discipline_id      INT  FK → disciplines.id      (ON DELETE CASCADE)
 *
 * CASCADE côté artiste : supprimer un profil artiste supprime ses associations.
 * CASCADE côté discipline : supprimer une discipline nettoie les associations.
 *
 * Note : la table `disciplines` et la table `artist_profiles` existent déjà
 * en base (créées dans des migrations antérieures). Cette migration ne crée
 * que la table de jointure.
 */
final class Version20260525120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table de jointure artist_disciplines (relation ManyToMany entre ArtistProfile et Discipline).';
    }

    public function up(Schema $schema): void
    {
        // ─── Table de jointure artist_disciplines ─────────────────────────────
        // Clé primaire composite sur les deux colonnes : on ne peut pas lier
        // deux fois le même artiste à la même discipline.
        $this->addSql(<<<'SQL'
            CREATE TABLE artist_disciplines (
                artist_profile_id INT NOT NULL,
                discipline_id     INT NOT NULL,
                PRIMARY KEY (artist_profile_id, discipline_id)
            )
        SQL);

        // Index sur artist_profile_id — accélère la requête "disciplines de l'artiste X"
        // (ex : dans l'annuaire, on charge toutes les disciplines d'une liste de profils)
        $this->addSql('CREATE INDEX IDX_ARTIST_DISCIPLINES_PROFILE ON artist_disciplines (artist_profile_id)');

        // Index sur discipline_id — accélère la requête inverse "artistes ayant cette discipline"
        // (utile pour les filtres dans l'annuaire)
        $this->addSql('CREATE INDEX IDX_ARTIST_DISCIPLINES_DISCIPLINE ON artist_disciplines (discipline_id)');

        // FK vers artist_profiles — ON DELETE CASCADE :
        // supprimer un profil artiste supprime automatiquement ses associations de disciplines
        $this->addSql('ALTER TABLE artist_disciplines ADD CONSTRAINT FK_ARTIST_DISCIPLINES_PROFILE FOREIGN KEY (artist_profile_id) REFERENCES artist_profiles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // FK vers disciplines — ON DELETE CASCADE :
        // supprimer une discipline supprime ses liens avec les artistes
        $this->addSql('ALTER TABLE artist_disciplines ADD CONSTRAINT FK_ARTIST_DISCIPLINES_DISC FOREIGN KEY (discipline_id) REFERENCES disciplines (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Suppression dans l'ordre inverse : d'abord les FK, puis la table.
        // PostgreSQL interdit de supprimer une table si des FK pointent encore vers elle.
        $this->addSql('ALTER TABLE artist_disciplines DROP CONSTRAINT FK_ARTIST_DISCIPLINES_PROFILE');
        $this->addSql('ALTER TABLE artist_disciplines DROP CONSTRAINT FK_ARTIST_DISCIPLINES_DISC');
        $this->addSql('DROP TABLE artist_disciplines');
    }
}
