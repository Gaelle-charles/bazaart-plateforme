<?php

/**
 * bootstrap_phpstan.php — Chargeur de l'ObjectManager Doctrine pour PHPStan.
 *
 * Ce fichier est référencé par phpstan.neon (paramètre doctrine.objectManagerLoader).
 * Il permet à l'extension phpstan/phpstan-doctrine de comprendre :
 *   - Les types des colonnes de chaque entité (ex: datetime → \DateTimeInterface)
 *   - Les associations Doctrine (@ManyToOne, etc.)
 *   - Les types de retour des repositories (ex: EntityRepository<User>)
 *
 * POURQUOI ce fichier plutôt que tests/bootstrap.php ?
 *   L'extension Doctrine de PHPStan a besoin d'un EntityManager actif, pas juste
 *   de l'autoloader. tests/bootstrap.php charge l'autoloader uniquement.
 *   Ce fichier va plus loin en bootant le Kernel Symfony en mode test pour
 *   accéder à l'EntityManager du conteneur de services.
 *
 * Source : https://github.com/phpstan/phpstan-doctrine#configuration
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

// On réutilise l'autoloader Composer déjà chargé
require_once __DIR__ . '/../vendor/autoload.php';

// Chargement des variables d'environnement via le composant Dotenv Symfony
// Nécessaire pour DATABASE_URL, APP_SECRET, etc. utilisés par le Kernel
if (class_exists(Dotenv::class)) {
    (new Dotenv())->bootEnv(__DIR__ . '/../.env');
}

// Forcer APP_ENV=test pour éviter de toucher la BDD de développement pendant l'analyse
$_ENV['APP_ENV']   = 'test';
$_SERVER['APP_ENV'] = 'test';

// Création du Kernel Symfony en mode test + debug désactivé (plus rapide pour PHPStan)
$kernel = new Kernel('test', false);
$kernel->boot();

// Retour de l'EntityManager — c'est ce que phpstan-doctrine attend
return $kernel->getContainer()->get('doctrine.orm.entity_manager');
