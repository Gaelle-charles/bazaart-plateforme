<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * AppSettingRepository — Requêtes Doctrine pour la table app_settings.
 *
 * Principe Repository : toute la logique de requêtage BDD est ici,
 * jamais dans les controllers ou les services.
 *
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    /**
     * Trouve un paramètre par sa clé technique.
     *
     * Retourne null si la clé n'existe pas en BDD.
     * Le SettingService appelle cette méthode pour lire les valeurs.
     *
     * Exemple :
     *   $setting = $repo->findByKey('anthropic_api_key');
     *   // Retourne l'objet AppSetting, ou null si la clé n'existe pas
     *
     * @param string $key Clé technique du paramètre (ex: "anthropic_api_key")
     */
    public function findByKey(string $key): ?AppSetting
    {
        // findOneBy est une méthode magique de Doctrine qui génère automatiquement
        // une requête "SELECT * FROM app_settings WHERE setting_key = :key LIMIT 1"
        return $this->findOneBy(['settingKey' => $key]);
    }
}
