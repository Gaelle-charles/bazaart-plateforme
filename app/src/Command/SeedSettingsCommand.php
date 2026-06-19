<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SettingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SeedSettingsCommand — Initialise les paramètres applicatifs en base de données.
 *
 * ⚠️ Périmètre : cette commande gère UNIQUEMENT les settings de configuration externe
 * (clés API, flags métier activables par l'admin depuis l'interface).
 * Les "feature flags techniques" internes (ex. 'archive_use_legacy') sont initialisés
 * par migration Doctrine et ne doivent PAS être ajoutés ici — leur valeur par défaut
 * est portée par la migration, pas par cette commande.
 *
 * Cette commande crée les enregistrements app_settings s'ils n'existent pas encore.
 * Elle est idempotente : on peut la relancer sans risque de réécraser les valeurs déjà saisies.
 *
 * Pourquoi une commande plutôt que des DataFixtures ?
 *   → Les DataFixtures sont conçues pour les environnements de test (elles peuvent être purgées).
 *   → Une commande dédiée peut être lancée en production sans risque de purge.
 *   → Plus explicite : l'admin sait exactement ce qui est initialisé.
 *
 * Lancement :
 *   docker compose exec app php bin/console app:seed-settings
 *
 * Option --force pour écraser les valeurs existantes (à utiliser avec précaution) :
 *   docker compose exec app php bin/console app:seed-settings --force
 */
#[AsCommand(
    name: 'app:seed-settings',
    description: 'Initialise les paramètres applicatifs (app_settings) en BDD si absents',
)]
class SeedSettingsCommand extends Command
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Écrase les valeurs existantes (attention : efface les valeurs déjà saisies par l\'admin)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $force   = (bool) $input->getOption('force');

        $io->title('BazaArt — Initialisation des paramètres applicatifs');

        if ($force) {
            $io->warning('Mode --force activé : les valeurs existantes seront écrasées !');
        }

        /**
         * Définition des paramètres à initialiser.
         *
         * Format de chaque entrée :
         *   key         → Clé technique utilisée dans le code (ne pas modifier après déploiement)
         *   value       → Valeur par défaut (null si à renseigner par l'admin)
         *   is_secret   → true = masqué dans l'UI (champ password)
         *   label       → Libellé affiché dans l'admin
         *   description → Explication pour l'admin
         *
         * POUR AJOUTER UN PARAMÈTRE :
         *   1. Ajouter une entrée dans ce tableau
         *   2. Relancer la commande en production : docker compose exec app php bin/console app:seed-settings
         *   3. Utiliser $settingService->get('ma_cle') dans le code
         */
        $settings = [
            [
                'key'         => 'anthropic_api_key',
                'value'       => null, // L'admin doit renseigner sa clé depuis /admin/settings
                'is_secret'   => true,
                'label'       => 'Clé API Anthropic (Claude Haiku)',
                'description' => 'Utilisée pour l\'extraction LLM des sources HTML si llm_provider = "anthropic". '
                    . 'Récupérer sur console.anthropic.com → API Keys. '
                    . 'Modèle utilisé : claude-haiku-4-5.',
            ],
            [
                'key'         => 'scraping_enabled',
                'value'       => '1', // '1' = activé, '0' = désactivé
                'is_secret'   => false,
                'label'       => 'Scraping automatique activé',
                'description' => 'Mettre à "0" pour désactiver temporairement le scraping automatique '
                    . '(utile pendant la maintenance ou en cas d\'erreurs répétées). '
                    . '"1" = activé, "0" = désactivé.',
            ],
            [
                // Provider LLM — choisir entre Mistral (recommandé) et Anthropic (fallback).
                // Mistral Small 3.2 est recommandé car il supporte response_format json_object
                // nativement → JSON garanti en sortie, pas de regex nécessaire.
                'key'         => 'llm_provider',
                'value'       => 'mistral',
                'is_secret'   => false,
                'label'       => 'Provider LLM (extraction HTML)',
                'description' => 'Provider LLM pour l\'extraction des sources HTML : '
                    . '"mistral" (Mistral Small 3.2, JSON natif, recommandé) '
                    . 'ou "anthropic" (Claude Haiku, fallback). '
                    . 'Nécessite la clé API correspondante configurée ci-dessous.',
            ],
            [
                // Clé API Mistral — configurer sur console.mistral.ai
                'key'         => 'mistral_api_key',
                'value'       => null, // À renseigner par l'admin depuis /admin/settings
                'is_secret'   => true,
                'label'       => 'Clé API Mistral',
                'description' => 'Clé API Mistral AI pour l\'extraction LLM (si llm_provider = "mistral"). '
                    . 'Récupérer sur console.mistral.ai → API Keys. '
                    . 'Modèle utilisé : mistral-small-latest.',
            ],
            [
                // Active ou désactive la commande app:discover-sources.
                // Utile pour suspendre la découverte sans supprimer les agrégateurs en BDD.
                'key'         => 'discovery_enabled',
                'value'       => 'true',
                'is_secret'   => false,
                'label'       => 'Découverte de sources activée',
                'description' => 'Active la commande app:discover-sources (true/false). '
                    . 'Si false, la commande s\'arrête immédiatement avec un message explicatif. '
                    . 'Utile pour suspendre la découverte pendant la maintenance ou si le LLM est indisponible.',
            ],
            [
                // Plafond de nouvelles suggestions par exécution de app:discover-sources.
                // Évite de polluer la file admin avec des centaines de suggestions d'un seul run.
                'key'         => 'discovery_max_suggestions',
                'value'       => '30',
                'is_secret'   => false,
                'label'       => 'Nb max de suggestions par run de découverte',
                'description' => 'Nombre maximum de nouvelles suggestions créées par run de app:discover-sources. '
                    . 'Valeur recommandée : 30 (évite de surcharger la file de validation admin). '
                    . 'La commande s\'arrête proprement dès que ce plafond est atteint.',
            ],

            // ── REPLI API DE SCRAPING ─────────────────────────────────────────────────
            // Ces 3 réglages contrôlent le repli sur une API de scraping tierce quand
            // le fetch direct est bloqué (IP droplet bannie, Cloudflare Challenge, JS lourd).
            // PRINCIPE D'ÉCONOMIE : le repli n'est déclenché QUE si le fetch direct échoue.
            // La clé ne vit PAS dans .env — elle est éditée depuis /admin/settings.
            [
                // Interrupteur global du repli API de scraping.
                // "true" = le repli est autorisé (déclenché seulement en cas d'échec direct)
                // "false" = pas de repli (comportement identique à avant l'implémentation du repli)
                // Désactivé par défaut pour ne pas consommer de quota sans décision explicite.
                'key'         => 'scraper_api_enabled',
                'value'       => 'false', // Désactivé par défaut — activer depuis /admin/settings
                'is_secret'   => false,
                'label'       => 'Repli API de scraping activé',
                'description' => 'Active le repli sur une API de scraping tierce (ScraperAPI, ScrapingAnt, etc.) '
                    . 'quand le fetch direct est bloqué par le site cible (IP bannie, Cloudflare, JS lourd). '
                    . '"true" = repli autorisé (déclenché uniquement en cas d\'échec direct). '
                    . '"false" = comportement inchangé (pas de repli). '
                    . 'Nécessite que scraper_api_key soit configurée.',
            ],
            [
                // Clé API du service de scraping tiers.
                // Obtenir sur https://www.scraperapi.com (compte gratuit = 1000 requêtes/mois)
                // ou chez un autre provider (ScrapingAnt, BrightData, etc.).
                // Cette clé ne doit jamais apparaître dans les logs — le service ScraperApiClient
                // garantit qu'elle n'est jamais loggée.
                'key'         => 'scraper_api_key',
                'value'       => null, // À renseigner par l'admin depuis /admin/settings
                'is_secret'   => true, // Masqué dans l'UI (champ type="password")
                'label'       => 'Clé API du service de scraping (ScraperAPI, etc.)',
                'description' => 'Clé d\'authentification pour l\'API de scraping tierce. '
                    . 'Obtenir sur https://www.scraperapi.com (1000 req./mois gratuites) '
                    . 'ou chez un autre provider (configurer scraper_api_url_template en conséquence). '
                    . 'La clé n\'est jamais loggée ni affichée en clair.',
            ],
            [
                // Template d'URL pour l'appel à l'API de scraping.
                // PLACEHOLDERS disponibles :
                //   {key} → remplacé par la valeur de scraper_api_key (URL-encodé)
                //   {url} → remplacé par l'URL cible à scraper (URL-encodé avec rawurlencode)
                // Ce template permet de changer de provider sans modifier le code.
                //
                // POURQUOI sans &render=true par défaut ?
                //   &render=true active le rendu JavaScript côté ScraperAPI (navigateur headless).
                //   Inconvénients du rendu JS :
                //     - Plus lent (~5-15s au lieu de ~1-3s)
                //     - Consomme 5 crédits API au lieu de 1 (coût x5)
                //     - Peut échouer ou timeout sur certains sites avec animations lourdes
                //   Pour Bazaart, le cas d'usage principal est le contournement de blocage IP,
                //   PAS le rendu de SPAs JavaScript (les pages-listes cibles ont du HTML statique).
                //   Le proxy simple (sans render) suffit largement pour les blocages d'IP.
                //   Si un site nécessite vraiment le rendu JS, ajouter un 2e setting
                //   'scraper_api_url_template_js' avec &render=true depuis /admin/settings.
                //
                // Valeurs par défaut :
                //   ScraperAPI (proxy simple) : https://api.scraperapi.com/?api_key={key}&url={url}
                //   ScraperAPI (avec rendu JS) : https://api.scraperapi.com/?api_key={key}&url={url}&render=true
                //   ScrapingAnt               : https://api.scrapingant.com/v2/general?api_key={key}&url={url}
                'key'         => 'scraper_api_url_template',
                'value'       => 'https://api.scraperapi.com/?api_key={key}&url={url}',
                'is_secret'   => false, // Template visible — la clé reste dans scraper_api_key
                'label'       => 'Template URL de l\'API de scraping',
                'description' => 'Template provider-agnostique pour l\'appel à l\'API de scraping. '
                    . 'Placeholders : {key} = clé API, {url} = URL cible (URL-encodée). '
                    . 'Défaut ScraperAPI (proxy simple, sans rendu JS) : '
                    . 'https://api.scraperapi.com/?api_key={key}&url={url} '
                    . 'Avec rendu JS (x5 crédits, pour SPAs) : ajouter &render=true à la fin. '
                    . 'ScrapingAnt : https://api.scrapingant.com/v2/general?api_key={key}&url={url} '
                    . 'Modifier uniquement si vous changez de provider.',
            ],
        ];

        $inserted = 0; // Paramètres créés pour la première fois
        $updated  = 0; // Paramètres mis à jour (mode --force uniquement)
        $skipped  = 0; // Paramètres ignorés (déjà existants, sans --force)

        foreach ($settings as $def) {
            // upsertWithoutFlush() crée ou met à jour sans flush immédiat.
            // Un seul flush est fait après la boucle (anti-pattern N transactions évité).
            $wasChanged = $this->settingService->upsertWithoutFlush(
                key: $def['key'],
                value: $def['value'],
                isSecret: $def['is_secret'],
                label: $def['label'],
                description: $def['description'],
                overwrite: $force,
            );

            if ($wasChanged === 'created') {
                // Nouveau paramètre créé en BDD
                $io->text(sprintf('  <info>%s</info> → créé', $def['key']));
                $inserted++;
            } elseif ($wasChanged === 'updated') {
                // Paramètre existant mis à jour grâce à --force
                $io->text(sprintf('  <comment>%s</comment> → mis à jour (--force)', $def['key']));
                $updated++;
            } else {
                // Paramètre déjà présent, --force absent → inchangé pour protéger les valeurs admin
                $io->text(sprintf('  <info>%s</info> → inchangé (déjà configuré)', $def['key']));
                $skipped++;
            }
        }

        // Flush unique : toutes les insertions/modifications en une seule transaction BDD
        $this->settingService->flush();

        $io->newLine();
        $io->success(sprintf(
            '%d créé(s) | %d mis à jour | %d inchangé(s). Accédez à /admin/settings pour configurer les valeurs.',
            $inserted,
            $updated,
            $skipped
        ));

        return Command::SUCCESS;
    }
}
