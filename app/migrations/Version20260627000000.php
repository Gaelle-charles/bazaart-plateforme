<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Ajout de trial_ends_at sur la table users (ADR-0028).
 *
 * CONTEXTE (ADR-0028) :
 *   Tout compte créé depuis cette migration bénéficie automatiquement d'un mois
 *   d'accès premium complet à compter de son inscription. La date de fin d'essai
 *   est initialisée dans User::initCreatedAt() (PrePersist Doctrine) :
 *     trialEndsAt = createdAt + 1 mois calendaire
 *
 *   Après ce mois, si l'utilisateur n'a pas souscrit un abonnement Stripe,
 *   il bascule vers le mode gratuit (3 matchings/jour — ADR-0022).
 *
 * SCHÉMA UNIQUEMENT (pas de données) :
 *   Cette migration ne touche PAS aux lignes existantes.
 *   Le backfill des comptes déjà inscrits (null → now() + 1 mois) est fait
 *   SÉPARÉMENT après migration, via le SQL suivant à exécuter une seule fois
 *   sur la production :
 *
 *     UPDATE users
 *     SET trial_ends_at = NOW() + INTERVAL '1 month'
 *     WHERE trial_ends_at IS NULL;
 *
 *   Ce geste offre 1 mois d'essai à tous les artistes déjà inscrits à la date
 *   du déploiement (décision Gaëlle, cf. ADR-0028 §Décision).
 *
 * COLONNE :
 *   trial_ends_at — TIMESTAMP WITHOUT TIME ZONE, nullable (DEFAULT NULL).
 *   null = compte antérieur au backfill OU essai révoqué manuellement.
 *   non null = date limite au-delà de laquelle l'essai est expiré.
 *
 * ROLLBACK :
 *   down() supprime simplement la colonne. Aucune contrainte FK ni index à gérer.
 */
final class Version20260627000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0028 : ajout de trial_ends_at (nullable) sur users — essai gratuit automatique d\'un mois à l\'inscription.';
    }

    public function up(Schema $schema): void
    {
        // Ajout de la colonne trial_ends_at sur la table users.
        //
        // DEFAULT NULL : les comptes existants ont trial_ends_at = null jusqu'au backfill.
        // Le backfill SQL (voir docblock de classe) est exécuté manuellement après migration.
        //
        // TIMESTAMP(0) WITHOUT TIME ZONE : cohérent avec les autres colonnes datetime de users
        // (createdAt, anonymizedAt, resetTokenExpiresAt sont toutes en TIMESTAMP WITHOUT TIME ZONE).
        // On stocke en UTC côté applicatif (Symfony ne convertit pas automatiquement les timezones
        // en Doctrine — la responsabilité est dans le code PHP, cf. User::isInTrial()).
        $this->addSql(
            'ALTER TABLE users ADD trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // Supprime la colonne en cas de rollback.
        // Si le backfill SQL avait été exécuté, les données de trial_ends_at sont perdues.
        // Acceptable : la colonne est nullable et son absence = pas d'essai (mode gratuit).
        $this->addSql('ALTER TABLE users DROP COLUMN trial_ends_at');
    }
}
