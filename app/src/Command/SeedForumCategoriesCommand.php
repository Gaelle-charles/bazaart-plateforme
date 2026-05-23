<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ForumCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande Symfony pour pré-remplir les catégories du forum Bazaart.
 *
 * Utilisation :
 *   docker compose exec app php bin/console app:forum:seed-categories
 *
 * Cette commande est idempotente : elle vérifie si des catégories existent déjà
 * avant d'en créer de nouvelles. Elle peut être exécutée plusieurs fois sans danger.
 *
 * #[AsCommand] est l'attribut PHP 8 qui remplace l'ancienne méthode configure().
 * Il déclare le nom de la commande (utilisé dans le terminal) et sa description.
 */
#[AsCommand(
    name: 'app:forum:seed-categories',
    description: 'Insère les 4 catégories par défaut du forum Bazaart si la table est vide.',
)]
class SeedForumCategoriesCommand extends Command
{
    /**
     * L'EntityManager permet d'interagir avec la base de données (persist + flush).
     * Il est injecté via l'autowiring — pas besoin de le déclarer dans services.yaml.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        // Le parent::__construct() est obligatoire pour les commandes Symfony
        parent::__construct();
    }

    /**
     * Logique principale de la commande.
     *
     * SymfonyStyle fournit des méthodes d'affichage formatées dans le terminal :
     *   $io->success() → ligne verte avec ✓
     *   $io->info()    → ligne bleue
     *   $io->warning() → ligne orange
     *   $io->error()   → ligne rouge
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle améliore le rendu visuel des commandes
        $io = new SymfonyStyle($input, $output);

        $io->title('Initialisation des catégories du forum Bazaart');

        // ── Vérification d'idempotence ─────────────────────────────────────────
        // On vérifie si des catégories existent déjà pour ne pas créer de doublons.
        // La commande est conçue pour n'être nécessaire qu'une seule fois (setup initial).
        $existingCount = $this->em
            ->getRepository(ForumCategory::class)
            ->count([]);

        if ($existingCount > 0) {
            $io->warning(sprintf(
                'La table forum_categories contient déjà %d catégorie(s). Aucune insertion effectuée.',
                $existingCount
            ));
            // Command::SUCCESS = code de retour 0 (pas d'erreur, mais rien fait)
            return Command::SUCCESS;
        }

        // ── Définition des catégories par défaut ───────────────────────────────
        // Ces 4 catégories correspondent aux besoins identifiés pour la communauté
        // Bazaart lors du cadrage V1 (mai 2026).
        //
        // Structure de chaque catégorie :
        //   name  : nom affiché dans l'interface
        //   slug  : identifiant URL (doit être unique, ne pas contenir d'accents ni espaces)
        //   icon  : emoji représentatif (affiché dans la sidebar et les cards)
        //   color : couleur hexadécimale d'accentuation de la catégorie
        //   order : position dans la liste (0 = premier, ordre croissant)
        $categories = [
            [
                'name'  => 'Actualités & Annonces',
                'slug'  => 'actualites-annonces',
                'desc'  => 'Informations officielles de l\'équipe Bazaart, annonces d\'événements, nouvelles fonctionnalités.',
                'icon'  => '📢',
                'color' => '#C8503A',  // Rouge Bazaart — couleur principale de la marque
                'order' => 1,
            ],
            [
                'name'  => 'Ressources & Opportunités',
                'slug'  => 'ressources-opportunites',
                'desc'  => 'Partage d\'opportunités professionnelles, résidences, bourses, appels à projets.',
                'icon'  => '🎯',
                'color' => '#2D6A4F',  // Vert foncé — cohérent avec la sidebar verte Bazaart
                'order' => 2,
            ],
            [
                'name'  => 'Projets & Collaborations',
                'slug'  => 'projets-collaborations',
                'desc'  => 'Cherchez des collaborateurs, présentez vos projets, formez des équipes créatives.',
                'icon'  => '🤝',
                'color' => '#1D3557',  // Bleu nuit — sérieux, professionnel
                'order' => 3,
            ],
            [
                'name'  => 'Vie artistique',
                'slug'  => 'vie-artistique',
                'desc'  => 'Échanges libres sur la création, les pratiques artistiques, les inspirations.',
                'icon'  => '🎨',
                'color' => '#9B5DE5',  // Violet — créativité, art
                'order' => 4,
            ],
        ];

        // ── Création des entités ───────────────────────────────────────────────
        $createdCount = 0;
        foreach ($categories as $data) {
            $category = new ForumCategory();
            $category->setName($data['name']);
            $category->setSlug($data['slug']);
            $category->setDescription($data['desc']);
            $category->setIcon($data['icon']);
            $category->setColor($data['color']);
            $category->setOrderPosition($data['order']);
            $category->setIsActive(true);

            // persist() marque l'entité pour insertion — l'INSERT SQL n'est pas encore exécuté
            $this->em->persist($category);

            $io->text(sprintf('  + %s %s', $data['icon'], $data['name']));
            $createdCount++;
        }

        // flush() exécute tous les INSERT en base en une seule transaction
        $this->em->flush();

        $io->success(sprintf('%d catégories créées avec succès !', $createdCount));
        $io->info('Le forum est maintenant prêt. Lance le projet et visite /forum pour voir le résultat.');

        // Command::SUCCESS = code de retour 0 = tout s'est bien passé
        return Command::SUCCESS;
    }
}
