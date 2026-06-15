<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\ArticleStatus;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande Symfony pour insérer 4 articles de blog publiés en base de données.
 *
 * Utilisation :
 *   docker compose exec app php bin/console app:seed-blog-articles
 *
 * Cette commande est IDEMPOTENTE : pour chaque article, si un article avec
 * le même slug existe déjà en base, on le saute sans erreur.
 * Elle peut être relancée sans risque de créer des doublons.
 *
 * Prérequis :
 *   - Au moins un utilisateur avec ROLE_ADMIN doit exister en base.
 *   - Si aucun admin n'est trouvé, la commande s'arrête proprement.
 *
 * #[AsCommand] est l'attribut PHP 8 qui déclare le nom et la description
 * de la commande (remplace l'ancienne méthode configure() + setName()).
 */
#[AsCommand(
    name: 'app:seed-blog-articles',
    description: 'Insère 4 articles de blog publiés pour alimenter la page Ressourcerie (idempotent).',
)]
class SeedBlogArticlesCommand extends Command
{
    /**
     * EntityManagerInterface permet de persister et flusher les entités en base.
     * ArticleRepository permet de vérifier l'existence d'un article par slug.
     *
     * Injection par constructeur (autowiring Symfony) — pas besoin de services.yaml.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articleRepository,
    ) {
        // Toujours appeler parent::__construct() pour que Symfony enregistre la commande
        parent::__construct();
    }

    /**
     * execute() est le point d'entrée de la commande.
     *
     * InputInterface  → arguments et options passés en CLI (non utilisés ici)
     * OutputInterface → flux de sortie (wrappé par SymfonyStyle)
     *
     * Retourne Command::SUCCESS (0) ou Command::FAILURE (1).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle fournit un affichage formaté : titres, listes, tableaux, alertes.
        $io = new SymfonyStyle($input, $output);

        $io->title('Seed : 4 articles de blog Bazaart');

        // ── Étape 1 : récupérer un utilisateur ROLE_ADMIN ─────────────────────
        //
        // La colonne "roles" en base est un tableau JSON (ex: ["ROLE_USER","ROLE_ADMIN"]).
        // On cherche le premier utilisateur dont la représentation JSON contient "ROLE_ADMIN".
        //
        // Note technique : la colonne "roles" est de type JSON en PostgreSQL, sur lequel
        // l'opérateur SQL LIKE n'est PAS applicable (erreur "operator does not exist: json ~~").
        // On filtre donc côté PHP via getRoles() : fiable et indépendant du type de colonne.
        // (Le nombre d'utilisateurs reste modeste en V1, le chargement complet est acceptable.)
        $admin = null;
        foreach ($this->em->getRepository(User::class)->findAll() as $candidate) {
            if (in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                $admin = $candidate;
                break;
            }
        }

        // Vérification de type stricte pour PHPStan et sécurité :
        // getOneOrNullResult() retourne mixed, on s'assure que c'est bien un User.
        if (!$admin instanceof User) {
            $io->error(
                'Aucun utilisateur avec ROLE_ADMIN n\'a été trouvé en base de données. '
                . 'Créez d\'abord un compte admin (ex: php bin/console app:create-admin), '
                . 'puis relancez cette commande.'
            );
            // Command::FAILURE = code de retour 1 = la commande a échoué
            return Command::FAILURE;
        }

        $io->text(sprintf(
            'Auteur admin trouvé : %s (id=%d)',
            $admin->getEmail(),
            (int) $admin->getId()
        ));

        // ── Étape 2 : définir les 4 articles à insérer ────────────────────────
        //
        // Chaque entrée du tableau correspond à un article.
        // La clé "slug" est utilisée comme identifiant d'idempotence :
        //   si un article avec ce slug existe déjà, on le saute.
        //
        // Les contenus HTML sont exactement ceux fournis dans le brief.
        // Aucun tiret cadratin (—) ni demi-cadratin (–) n'est présent.
        $articles = [
            [
                'slug'            => 'financer-residence-artistique-etranger',
                'title'           => 'Financer sa résidence artistique à l\'étranger',
                'coverImagePath'  => 'uploads/articles/financer-residence-artistique-etranger.jpg',
                'excerpt'         => 'Bourses, aides à la mobilité, fonds privés : un tour d\'horizon des leviers pour financer une résidence hors de France.',
                'content'         => '<p>Partir en résidence à l\'étranger est une étape précieuse dans un parcours d\'artiste. Reste une question concrète : comment la financer ? Bonne nouvelle, les dispositifs existent, à condition de savoir où chercher et de s\'y prendre tôt.</p>
<h2>Les aides publiques</h2>
<p>De nombreux organismes soutiennent la mobilité des artistes : institutions nationales, fonds européens, collectivités territoriales. Ces aides couvrent souvent le voyage, l\'hébergement ou une bourse de production. Surveillez les appels du Centre national de la musique, de l\'Institut français ou des programmes européens de mobilité.</p>
<h2>Les fonds privés et fondations</h2>
<p>Fondations d\'entreprise, mécènes et fonds de dotation financent régulièrement des projets artistiques. Leurs critères sont parfois plus souples que ceux du public, mais la concurrence est forte : un dossier clair et incarné fait la différence.</p>
<h2>Monter un budget réaliste</h2>
<p>Listez toutes vos dépenses (transport, logement, matériel, per diem) et présentez un budget équilibré. Un financement croisé, qui combine plusieurs sources, rassure les financeurs et sécurise votre projet.</p>
<p>Sur Bazaart, retrouvez les opportunités de mobilité et de financement rassemblées au même endroit, et activez des alertes pour ne rien manquer.</p>',
            ],
            [
                'slug'            => 'repondre-appel-a-projets-dossier-solide',
                'title'           => 'Répondre à un appel à projets : nos conseils pour un dossier solide',
                'coverImagePath'  => 'uploads/articles/repondre-appel-a-projets-dossier-solide.jpg',
                'excerpt'         => 'Un bon dossier ne s\'improvise pas. Voici les réflexes qui font la différence face à un jury.',
                'content'         => '<p>Un appel à projets, c\'est une promesse et une exigence. Pour convaincre un jury, la qualité artistique ne suffit pas : il faut aussi un dossier lisible, complet et déposé dans les temps.</p>
<h2>Lire le règlement, vraiment</h2>
<p>Avant tout, lisez attentivement les critères d\'éligibilité et les attendus. Un dossier hors cadre est écarté, quelle que soit sa valeur. Notez les pièces demandées et la date limite.</p>
<h2>Soigner la note d\'intention</h2>
<p>La note d\'intention est le cœur du dossier. Expliquez votre démarche, le sens du projet et ce que la résidence ou l\'aide va permettre. Soyez précis et sincère, évitez le jargon.</p>
<h2>Le budget et les pièces visuelles</h2>
<p>Présentez un budget honnête et équilibré, et joignez des visuels de qualité qui montrent votre travail. Un jury se fait une opinion en quelques secondes : facilitez lui la tâche.</p>
<p>Anticipez toujours les délais : un dossier déposé à la dernière minute laisse peu de place aux imprévus techniques.</p>',
            ],
            [
                'slug'            => 'diaspora-afro-atlantique-creation-numerique',
                'title'           => 'Diaspora afro-atlantique : les nouvelles formes de création numérique',
                'coverImagePath'  => 'uploads/articles/diaspora-afro-atlantique-creation-numerique.jpg',
                'excerpt'         => 'Du net art à l\'intelligence artificielle, la création numérique ouvre de nouveaux espaces aux artistes de la diaspora.',
                'content'         => '<p>Les outils numériques redessinent la création contemporaine. Pour les artistes de la diaspora afro-atlantique, ils offrent de nouveaux territoires d\'expression, de mémoire et de diffusion.</p>
<h2>De nouveaux langages</h2>
<p>Net art, art génératif, réalité augmentée, créations assistées par intelligence artificielle : ces formes permettent de raconter des histoires souvent absentes des circuits traditionnels, et de toucher des publics dans le monde entier.</p>
<h2>Mémoire et transmission</h2>
<p>Le numérique devient aussi un outil d\'archivage et de transmission : préserver des récits, documenter des pratiques, faire dialoguer les générations et les territoires de la diaspora.</p>
<h2>Des opportunités à saisir</h2>
<p>Festivals, résidences et appels à projets dédiés aux arts numériques se multiplient. C\'est le moment d\'expérimenter, de se former et de candidater. Bazaart vous aide à repérer ces occasions et à vous y préparer.</p>',
            ],
            [
                'slug'            => 'construire-portfolio-artiste',
                'title'           => 'Construire un portfolio d\'artiste qui fait la différence',
                'coverImagePath'  => 'uploads/articles/construire-portfolio-artiste.jpg',
                'excerpt'         => 'Votre portfolio est votre première impression. Comment le rendre clair, cohérent et mémorable.',
                'content'         => '<p>Le portfolio est souvent le premier contact entre un artiste et un jury, une galerie ou un partenaire. En quelques pages, il doit donner envie d\'en savoir plus.</p>
<h2>Sélectionner, pas tout montrer</h2>
<p>Choisissez vos pièces les plus fortes plutôt que de tout présenter. Un portfolio resserré et cohérent est plus convaincant qu\'un catalogue exhaustif.</p>
<h2>Raconter une histoire</h2>
<p>Organisez vos travaux pour qu\'ils racontent un parcours et une démarche. L\'ordre, les transitions et un court texte de présentation aident le lecteur à comprendre votre univers.</p>
<h2>Soigner la forme</h2>
<p>Des visuels de bonne qualité, une mise en page sobre et un format adapté (page en ligne ou PDF léger) font toute la différence. Pensez à mettre votre portfolio à jour régulièrement.</p>
<p>Un portfolio clair, c\'est plus de candidatures abouties et plus d\'opportunités saisies.</p>',
            ],
        ];

        // ── Étape 3 : parcourir les articles et insérer ceux qui n'existent pas ─
        $createdCount = 0;
        $skippedCount = 0;
        // Liste pour le récap final affiché dans le tableau SymfonyStyle
        $recap = [];

        // Date de publication unique pour tous les articles créés lors de ce seed.
        // On utilise new \DateTime() afin d'obtenir un DateTimeInterface valide.
        $publishedAt = new \DateTime();

        foreach ($articles as $data) {
            // ── Idempotence : vérifier si le slug existe déjà ─────────────────
            // findOneBy(['slug' => ...]) retourne null si aucun article ne correspond.
            $existing = $this->articleRepository->findOneBy(['slug' => $data['slug']]);

            if ($existing !== null) {
                // Cet article existe déjà : on le saute sans modifier quoi que ce soit.
                $io->text(sprintf('  [IGNORÉ]  %s (slug déjà présent)', $data['slug']));
                $recap[] = [$data['slug'], 'ignoré'];
                $skippedCount++;
                continue;
            }

            // ── Création de l'entité Article ──────────────────────────────────
            $article = new Article();

            // Titre affiché en page et dans les listes
            $article->setTitle($data['title']);

            // Slug : identifiant URL unique (ex: /articles/financer-residence-...)
            $article->setSlug($data['slug']);

            // Accroche courte (résumé en liste/carte)
            $article->setExcerpt($data['excerpt']);

            // Contenu HTML complet de l'article
            $article->setContent($data['content']);

            // Chemin relatif de l'image de couverture (stockée dans public/)
            $article->setCoverImagePath($data['coverImagePath']);

            // L'auteur est l'admin récupéré en début de commande
            $article->setAuthor($admin);

            // Statut "publié" — utilise l'enum PHP 8.1 ArticleStatus::Published
            // La valeur stockée en base sera la string 'published' (backed enum)
            $article->setStatus(ArticleStatus::Published);

            // Date de publication : now() au moment de l'exécution du seed
            $article->setPublishedAt($publishedAt);

            // Note : createdAt et updatedAt sont gérés automatiquement par
            // #[ORM\PrePersist] via initTimestamps() dans l'entité Article.
            // Il ne faut PAS les définir manuellement ici.

            // persist() marque l'entité pour insertion — l'INSERT SQL sera exécuté au flush()
            $this->em->persist($article);

            $io->text(sprintf('  [CRÉÉ]    %s', $data['slug']));
            $recap[] = [$data['slug'], 'créé'];
            $createdCount++;
        }

        // ── Étape 4 : flush — envoyer tous les INSERTs en une seule transaction ─
        // On flush uniquement si au moins un article a été créé pour éviter
        // une transaction vide inutile.
        if ($createdCount > 0) {
            $this->em->flush();
        }

        // ── Étape 5 : afficher le récapitulatif ───────────────────────────────
        $io->newLine();
        // Tableau SymfonyStyle : 2 colonnes, 1 ligne par article
        $io->table(
            ['Slug', 'Action'],
            $recap
        );

        // Message de synthèse coloré selon le résultat
        if ($createdCount > 0) {
            $io->success(sprintf(
                '%d article(s) créé(s), %d ignoré(s) (slug déjà présent).',
                $createdCount,
                $skippedCount
            ));
        } else {
            $io->info(sprintf(
                'Aucun article créé : les %d article(s) existaient déjà (idempotence).',
                $skippedCount
            ));
        }

        return Command::SUCCESS;
    }
}
