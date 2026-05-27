<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SettingService — Lecture et écriture des paramètres applicatifs (app_settings).
 *
 * Ce service fait le pont entre le code PHP et la table app_settings.
 * Il est utilisé par :
 *   - LlmExtractorService : lire la clé API Anthropic
 *   - ScrapeOpportunitiesCommand : vérifier si le scraping est activé
 *   - AdminSettingController : afficher et modifier les settings dans l'admin
 *
 * Pourquoi un service dédié plutôt que d'appeler le repository directement ?
 * → Centralisation de la logique (valeur par défaut, cast de type, cache futur)
 * → Facilite les tests unitaires (on mock ce service)
 * → Permet d'ajouter du cache en V2 sans modifier les appelants
 */
class SettingService
{
    public function __construct(
        // Repository pour les requêtes BDD
        private readonly AppSettingRepository $settingRepository,
        // EntityManager pour persister les modifications
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Lit la valeur d'un paramètre par sa clé.
     *
     * Retourne $default si :
     *   - la clé n'existe pas en BDD
     *   - la valeur stockée est null
     *
     * Exemples :
     *   $apiKey  = $this->settingService->get('anthropic_api_key');
     *   $enabled = $this->settingService->get('scraping_enabled', '1');
     *
     * @param string      $key     Clé technique du paramètre
     * @param string|null $default Valeur retournée si le paramètre est absent ou null
     * @return string|null         La valeur stockée, ou $default
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $setting = $this->settingRepository->findByKey($key);

        // Si le setting n'existe pas en BDD → retourner la valeur par défaut
        if ($setting === null) {
            return $default;
        }

        // Si la valeur stockée est null → retourner la valeur par défaut
        // (un setting peut exister en BDD avec une valeur non renseignée)
        return $setting->getSettingValue() ?? $default;
    }

    /**
     * Modifie ou crée un paramètre.
     *
     * Si la clé existe déjà → met à jour la valeur existante
     * Si la clé n'existe pas → lève une exception (il faut d'abord seeder le setting)
     *
     * POURQUOI ne pas créer si absent ?
     * → Évite de créer des settings "fantômes" sans label ni description.
     * → Tous les settings doivent être initialisés via app:seed-settings.
     *
     * @throws \RuntimeException Si la clé n'existe pas en BDD
     */
    public function set(string $key, ?string $value): void
    {
        $setting = $this->settingRepository->findByKey($key);

        if ($setting === null) {
            throw new \RuntimeException(sprintf(
                'Setting "%s" introuvable. Lancez d\'abord "app:seed-settings" pour initialiser les paramètres.',
                $key
            ));
        }

        // Met à jour la valeur — le lifecycle callback #[ORM\PreUpdate]
        // mettra à jour automatiquement updatedAt lors du flush()
        $setting->setSettingValue($value);

        // Doctrine détecte le changement et exécute UPDATE SQL au flush()
        $this->em->flush();
    }

    /**
     * Crée ou met à jour un paramètre (upsert) AVEC flush immédiat.
     *
     * Contrairement à set(), crée le setting s'il n'existe pas.
     * À utiliser pour une mise à jour unitaire isolée.
     *
     * ATTENTION : si tu appelles cette méthode dans une boucle (plusieurs settings),
     * préfère upsertWithoutFlush() + un seul flush() final pour éviter N transactions.
     *
     * @param string      $key         Clé technique
     * @param string|null $value       Valeur initiale (peut être null)
     * @param bool        $isSecret    Si true, masqué dans l'UI
     * @param string      $label       Libellé FR pour l'admin
     * @param string|null $description Explication optionnelle
     * @param bool        $overwrite   Si false, ne modifie pas une valeur déjà renseignée
     */
    public function upsert(
        string $key,
        ?string $value,
        bool $isSecret,
        string $label,
        ?string $description = null,
        bool $overwrite = false,
    ): void {
        $this->upsertWithoutFlush($key, $value, $isSecret, $label, $description, $overwrite);
        $this->em->flush();
    }

    /**
     * Crée ou met à jour un paramètre SANS flush.
     *
     * Utilisé par SeedSettingsCommand pour initialiser plusieurs settings en une seule transaction.
     * Le appelant doit appeler flush() (ou $this->flush()) après la boucle.
     *
     * Retourne une chaîne indiquant ce qui s'est passé :
     *   'created' → nouveau setting créé
     *   'updated' → setting existant mis à jour (overwrite=true)
     *   'skipped' → setting existant non modifié (overwrite=false)
     *
     * @param string      $key         Clé technique
     * @param string|null $value       Valeur initiale (peut être null)
     * @param bool        $isSecret    Si true, masqué dans l'UI
     * @param string      $label       Libellé FR pour l'admin
     * @param string|null $description Explication optionnelle
     * @param bool        $overwrite   Si false, ne modifie pas une valeur déjà renseignée
     * @return string 'created' | 'updated' | 'skipped'
     */
    public function upsertWithoutFlush(
        string $key,
        ?string $value,
        bool $isSecret,
        string $label,
        ?string $description = null,
        bool $overwrite = false,
    ): string {
        $setting = $this->settingRepository->findByKey($key);

        if ($setting === null) {
            // Création d'un nouveau setting — persist() sans flush pour grouper les INSERTs
            $setting = new AppSetting();
            $setting->setSettingKey($key);
            $setting->setSettingValue($value);
            $setting->setIsSecret($isSecret);
            $setting->setLabel($label);
            $setting->setDescription($description);
            $this->em->persist($setting);
            return 'created';
        }

        if ($overwrite) {
            // Mise à jour uniquement si overwrite demandé
            // (en temps normal, on ne réinitialise pas une valeur existante)
            $setting->setSettingValue($value);
            $setting->setIsSecret($isSecret);
            $setting->setLabel($label);
            $setting->setDescription($description);
            return 'updated';
        }

        // Si le setting existe et overwrite=false → on ne touche rien
        // (cas normal au seed : ne pas écraser la clé API déjà saisie par l'admin)
        return 'skipped';
    }

    /**
     * Exécute le flush de l'EntityManager.
     *
     * Expose le flush pour permettre à SeedSettingsCommand de déclencher
     * une seule transaction après N appels à upsertWithoutFlush().
     *
     * Convention : toujours utiliser upsertWithoutFlush() dans une boucle,
     * puis flush() une seule fois à la fin.
     */
    public function flush(): void
    {
        $this->em->flush();
    }
}
