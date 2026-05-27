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
