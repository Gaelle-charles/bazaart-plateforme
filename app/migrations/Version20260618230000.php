<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill ADR-0015 — Passer tous les comptes existants à is_verified = true.
 *
 * CONTEXTE
 * --------
 * L'ADR-0015 Lot 1 a introduit un UserChecker (src/Security/UserChecker.php)
 * qui bloque la connexion de tout compte dont is_verified = false.
 * Ce comportement est intentionnel pour les NOUVELLES inscriptions :
 *   1. L'utilisateur s'inscrit → is_verified = false
 *   2. Il reçoit un email avec un lien signé
 *   3. Il clique → is_verified = true → connexion possible
 *
 * PROBLÈME
 * --------
 * Les comptes créés AVANT la mise en place de ce mécanisme ont
 * is_verified = false (valeur par défaut de la colonne, appliquée
 * lors de sa création). Sans ce backfill, tous ces comptes seraient
 * définitivement bloqués dès le déploiement du UserChecker en production.
 *
 * SOLUTION
 * --------
 * Mettre is_verified = true sur TOUS les comptes dont is_verified = false.
 * Ces comptes ont été créés et utilisés normalement : l'email est considéré
 * implicitement vérifié (l'utilisateur s'est connecté, a rempli son profil, etc.).
 *
 * IDEMPOTENCE
 * -----------
 * La migration est strictement idempotente : si elle est rejouée (ex : double
 * déploiement accidentel ou rollback + re-migrate), la clause WHERE garantit
 * que 0 ligne est affectée si tous les comptes sont déjà à true.
 *
 * IRRÉVERSIBILITÉ
 * ---------------
 * down() est volontairement no-op et documenté.
 * On ne peut pas revenir à "is_verified = false" pour ces comptes :
 *   - On ne sait pas quels comptes étaient réellement non-vérifiés (s'il y en avait)
 *   - Re-verrouiller des comptes actifs serait une régression bloquante
 *   - Ce type de backfill "forward-only" est la pratique standard pour les
 *     migrations de données irréversibles (contrairement aux migrations de schéma).
 */
final class Version20260618230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill ADR-0015 : passe tous les comptes existants à is_verified = true pour éviter un lock-out au déploiement du UserChecker';
    }

    /**
     * Mise à jour unique, idempotente, non-destructive.
     *
     * Table cible  : users
     * Colonne      : is_verified (boolean, NOT NULL, DEFAULT false)
     * Condition    : WHERE is_verified = false
     *   → En local (comptes de dev déjà vérifiés) : 0 ligne affectée, OK.
     *   → En production (comptes historiques)       : N lignes passées à true.
     *
     * Pas de modification de schéma (aucun ALTER TABLE) : doctrine:schema:validate
     * ne sera pas impacté.
     */
    public function up(Schema $schema): void
    {
        // Backfill : on ne touche que les lignes concernées (WHERE).
        // L'UPDATE est atomique dans la transaction Doctrine Migrations.
        // Si la migration échoue pour une raison externe, la transaction est
        // annulée et la migration n'est pas marquée comme appliquée → safe.
        $this->addSql('UPDATE users SET is_verified = true WHERE is_verified = false');
    }

    /**
     * down() intentionnellement vide — migration de données irréversible.
     *
     * Raison : on ne peut pas revenir à "non-vérifié" sans savoir quels comptes
     * étaient effectivement dans cet état avant le backfill. Tenter de le faire
     * bloquerait les comptes actifs de production.
     *
     * Convention : pour les migrations de données forward-only, laisser down()
     * vide est préférable à throwIrreversibleMigrationException() qui fait
     * échouer bruyamment un `migrations:migrate --down` — inutile ici.
     */
    public function down(Schema $schema): void
    {
        // Migration de données irréversible — aucune action en rollback.
        // Re-passer les comptes à false bloquerait des utilisateurs actifs.
    }
}
