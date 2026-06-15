<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration correctif — Renommage des index Stripe pour correspondre aux noms générés par Doctrine.
 *
 * Doctrine génère des noms d'index basés sur un hash (ex : UNIQ_4778A01B5DBB761).
 * La migration précédente (Version20260615211300) utilisait des noms lisibles.
 * Cette migration les renomme pour que doctrine:schema:validate soit satisfait.
 *
 * Ce pattern est courant quand on écrit des migrations manuelles :
 *   Doctrine calcule ses noms d'index via un algorithme interne (hash de la table + colonne),
 *   et le validate compare exactement ces noms avec ceux en base.
 */
final class Version20260615211301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renommage des index Stripe pour correspondre aux noms Doctrine (schema:validate)';
    }

    public function up(Schema $schema): void
    {
        // Renommage de l'index unique sur subscriptions.stripe_subscription_id
        // Nom Doctrine attendu : UNIQ_4778A01B5DBB761 (hash de "subscriptions" + "stripe_subscription_id")
        $this->addSql('ALTER INDEX IF EXISTS uniq_subscriptions_stripe_id RENAME TO "UNIQ_4778A01B5DBB761"');

        // Renommage de l'index unique sur course_payments.stripe_payment_intent_id
        // Nom Doctrine attendu : UNIQ_CC66F664FC72F97E (hash de "course_payments" + "stripe_payment_intent_id")
        $this->addSql('ALTER INDEX IF EXISTS uniq_course_payments_payment_intent RENAME TO "UNIQ_CC66F664FC72F97E"');
    }

    public function down(Schema $schema): void
    {
        // Retour aux noms lisibles si on rollback
        $this->addSql('ALTER INDEX IF EXISTS "UNIQ_4778A01B5DBB761" RENAME TO uniq_subscriptions_stripe_id');
        $this->addSql('ALTER INDEX IF EXISTS "UNIQ_CC66F664FC72F97E" RENAME TO uniq_course_payments_payment_intent');
    }
}
