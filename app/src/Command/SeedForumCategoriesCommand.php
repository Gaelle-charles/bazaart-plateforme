<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ForumCategory;
use App\Entity\ForumThread;
use App\Entity\User;
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
    description: 'Insère les 5 catégories par défaut du forum Bazaart et un thread d\'amorce par catégorie si la table est vide.',
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
        // Correction 5 : CDC impose au minimum 5 catégories avec un thread d'amorce chacune.
        // La 5ème catégorie "Entraide & Soutien" a été ajoutée.
        //
        // Structure de chaque catégorie :
        //   name         : nom affiché dans l'interface
        //   slug         : identifiant URL (doit être unique, sans accents ni espaces)
        //   desc         : description courte affichée sous le nom
        //   icon         : caractère ou code HTML représentatif
        //   color        : couleur hexadécimale d'accentuation de la catégorie
        //   order        : position dans la liste (ordre croissant)
        //   seed_title   : titre du thread d'amorce épinglé créé par l'admin
        //   seed_content : contenu de bienvenue du thread d'amorce (2-3 phrases)
        $categories = [
            [
                'name'         => 'Actualités & Annonces',
                'slug'         => 'actualites-annonces',
                'desc'         => 'Informations officielles de l\'équipe Bazaart, annonces d\'événements, nouvelles fonctionnalités.',
                'icon'         => '&#128226;',
                'color'        => '#C8503A',  // Rouge Bazaart — couleur principale de la marque
                'order'        => 1,
                'seed_title'   => 'Bienvenue sur le forum Bazaart',
                'seed_content' => "Bienvenue dans l'espace communautaire de Bazaart ! Ce forum est votre espace d'échange, de partage et de soutien mutuel.\n\nRetrouvez ici toutes les annonces officielles de l'équipe : nouveautés de la plateforme, événements à venir, et informations importantes pour la communauté.\n\nN'hésitez pas à activer les notifications pour rester informé.",
            ],
            [
                'name'         => 'Ressources & Opportunités',
                'slug'         => 'ressources-opportunites',
                'desc'         => 'Partage d\'opportunités professionnelles, résidences, bourses, appels à projets.',
                'icon'         => '&#127919;',
                'color'        => '#2D6A4F',  // Vert foncé — cohérent avec la sidebar verte Bazaart
                'order'        => 2,
                'seed_title'   => 'Comment partager une opportunité sur ce forum',
                'seed_content' => "Cette catégorie est dédiée aux opportunités professionnelles pour les artistes de la diaspora afro-atlantique : résidences, bourses, appels à projets, offres de collaboration.\n\nPour partager une opportunité, créez un nouveau sujet avec le titre, les conditions et la date limite en en-tête.\n\nSoyez précis et synthétiques pour faciliter la lecture de tous.",
            ],
            [
                'name'         => 'Projets & Collaborations',
                'slug'         => 'projets-collaborations',
                'desc'         => 'Cherchez des collaborateurs, présentez vos projets, formez des équipes créatives.',
                'icon'         => '&#129309;',
                'color'        => '#1D3557',  // Bleu nuit — sérieux, professionnel
                'order'        => 3,
                'seed_title'   => 'Presentez-vous et vos projets en cours',
                'seed_content' => "Vous cherchez un collaborateur pour un projet musical, une exposition, une résidence ou un court-métrage ? Vous êtes au bon endroit.\n\nPartagez votre projet, vos besoins et vos coordonnées dans un nouveau sujet. La communauté Bazaart regroupe des artistes aux disciplines complémentaires.\n\nLa collaboration, c'est au coeur de notre mission.",
            ],
            [
                'name'         => 'Vie artistique',
                'slug'         => 'vie-artistique',
                'desc'         => 'Echanges libres sur la création, les pratiques artistiques, les inspirations.',
                'icon'         => '&#127912;',
                'color'        => '#9B5DE5',  // Violet — créativité, art
                'order'        => 4,
                'seed_title'   => 'De quoi parle-t-on ici ?',
                'seed_content' => "Vie artistique est l'espace de conversation libre du forum. Partagez vos inspirations du moment, vos questionnements sur votre pratique, vos découvertes culturelles.\n\nPas de format imposé ici : une question, un extrait, un coup de coeur, une réflexion... tout est bienvenu tant que c'est en lien avec la création et la culture de la diaspora afro-atlantique.\n\nSoyez curieux, bienveillants et ouverts.",
            ],
            [
                // 5ème catégorie — ajout requis par le CDC V1 (correction 5)
                'name'         => 'Entraide & Soutien',
                'slug'         => 'entraide-soutien',
                'desc'         => 'Questions pratiques, retours d\'expérience, difficultés du quotidien d\'artiste. La communauté répond.',
                'icon'         => '&#129293;',
                'color'        => '#E07A5F',  // Terracotta — chaleur, soutien
                'order'        => 5,
                'seed_title'   => 'Un espace pour poser vos questions sans jugement',
                'seed_content' => "Etre artiste, c'est aussi faire face à des défis concrets : comment facturer une prestation, gérer son statut, trouver un atelier, se faire payer à temps...\n\nCette catégorie est un espace sécurisé pour poser ces questions sans gêne. La communauté Bazaart est là pour partager ses expériences et ses conseils.\n\nToutes les questions sont bienvenues. Ici, on s'entraide.",
            ],
        ];

        // ── Recherche de l'admin pour les threads d'amorce ────────────────────
        // Les threads d'amorce doivent être attribués à un utilisateur ROLE_ADMIN.
        // On interroge la colonne JSON "roles" de la table users via une requête DQL.
        //
        // Note : la colonne roles est un JSON array en PostgreSQL (ex: ["ROLE_USER", "ROLE_ADMIN"]).
        // L'opérateur LIKE sur la représentation JSON (texte) est une approximation volontaire en V1.
        // En V2 : utiliser l'opérateur PostgreSQL natif @> sur JSONB pour une recherche correcte.
        $admin = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u WHERE u.roles LIKE :role'
        )
            ->setParameter('role', '%ROLE_ADMIN%')
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (!$admin instanceof User) {
            // Pas d'admin en base : les threads d'amorce seront ignorés.
            // La commande reste utile pour les catégories — elle continue.
            $io->warning('Aucun utilisateur ROLE_ADMIN trouvé en base. Les catégories seront créées mais sans thread d\'amorce. Relancez la commande après avoir créé un compte admin.');
        }

        // ── Création des entités catégories ───────────────────────────────────
        $createdCount = 0;
        // On conserve les objets ForumCategory dans un tableau indexé pour y accéder
        // juste après le flush (les IDs sont disponibles après flush, pas avant).
        $createdCategories = [];

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

            // On stocke la catégorie et ses données d'amorce pour le deuxième tour
            $createdCategories[] = [
                'entity'       => $category,
                'seed_title'   => $data['seed_title'],
                'seed_content' => $data['seed_content'],
            ];

            $io->text(sprintf('  + %s', $data['name']));
            $createdCount++;
        }

        // Premier flush : on enregistre les catégories pour obtenir leurs IDs.
        // C'est nécessaire car ForumThread a besoin d'une catégorie persistée
        // (référence FK valide) avant son propre flush.
        $this->em->flush();

        $io->success(sprintf('%d catégories créées avec succès !', $createdCount));

        // ── Création des threads d'amorce ─────────────────────────────────────
        // Un thread épinglé par catégorie, attribué à l'admin, pour "amorcer" la discussion.
        // Requis par le CDC V1 (section 5 : "au moins 5 catégories avec un thread d'amorce chacun").
        if ($admin instanceof User) {
            $io->section('Création des threads d\'amorce');
            $threadCount = 0;

            foreach ($createdCategories as $item) {
                $thread = new ForumThread();
                $thread->setTitle($item['seed_title']);
                $thread->setContent($item['seed_content']);
                $thread->setAuthor($admin);
                $thread->setCategory($item['entity']);

                // Génère un slug simple depuis le titre (nettoyage basique)
                // Note : on n'utilise pas ForumService ici pour ne pas créer de dépendance
                // circulaire entre la commande et le service (la commande est un point d'entrée CLI).
                $slugRaw = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $item['seed_title']) ?: $item['seed_title'];
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slugRaw)), '-');
                $thread->setSlug($slug);

                // Le thread d'amorce est épinglé : il reste en tête de la catégorie.
                $thread->setIsPinned(true);

                $this->em->persist($thread);

                $io->text(sprintf('  + Thread : "%s" dans [%s]', $item['seed_title'], $item['entity']->getName()));
                $threadCount++;
            }

            // Deuxième flush : enregistre tous les threads d'amorce
            $this->em->flush();

            $io->success(sprintf('%d threads d\'amorce créés et épinglés.', $threadCount));
        }

        $io->info('Le forum est maintenant prêt. Lance le projet et visite /forum pour voir le résultat.');

        // Command::SUCCESS = code de retour 0 = tout s'est bien passé
        return Command::SUCCESS;
    }
}
