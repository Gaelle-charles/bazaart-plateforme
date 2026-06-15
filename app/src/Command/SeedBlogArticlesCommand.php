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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande Symfony pour insérer (ou mettre à jour) 4 articles de blog publiés.
 *
 * Utilisations :
 *   php bin/console app:seed-blog-articles
 *           → crée les articles manquants, ignore ceux déjà présents.
 *
 *   php bin/console app:seed-blog-articles --update
 *           → crée les articles manquants ET met à jour le contenu
 *             (title, excerpt, content, coverImagePath) des articles existants.
 *             L'auteur, le statut, publishedAt et createdAt ne sont PAS modifiés.
 *
 * La commande est IDEMPOTENTE : relançable sans risque de doublon.
 *
 * Prérequis : au moins un utilisateur avec ROLE_ADMIN doit exister en base.
 */
#[AsCommand(
    name: 'app:seed-blog-articles',
    description: 'Insère (ou met à jour avec --update) 4 articles de blog publiés pour la Ressourcerie.',
)]
class SeedBlogArticlesCommand extends Command
{
    /**
     * EntityManagerInterface : persiste et flush les entités vers PostgreSQL.
     * ArticleRepository     : vérifie l'existence d'un article par son slug.
     *
     * Injection par constructeur (autowiring Symfony, pas de services.yaml nécessaire).
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articleRepository,
    ) {
        // Toujours appeler parent::__construct() pour que Symfony enregistre la commande
        parent::__construct();
    }

    /**
     * configure() déclare les options et arguments de la commande.
     *
     * InputOption::VALUE_NONE signifie que l'option est un drapeau booléen :
     *   --update présent  → true
     *   --update absent   → false (getOption renvoie null, testé avec (bool))
     */
    protected function configure(): void
    {
        $this->addOption(
            'update',          // nom de l'option (utilisé dans --update)
            null,              // pas de raccourci (-u réservé à Symfony en interne)
            InputOption::VALUE_NONE,
            'Si activé, met à jour le contenu des articles déjà existants (title, excerpt, content, coverImagePath).',
        );
    }

    /**
     * execute() est le point d'entrée de la commande.
     *
     * InputInterface  → donne accès aux arguments et options CLI
     * OutputInterface → flux de sortie, wrappé par SymfonyStyle
     *
     * Retourne Command::SUCCESS (0) ou Command::FAILURE (1).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle fournit un affichage formaté : titres, listes, tableaux, alertes.
        $io = new SymfonyStyle($input, $output);

        // Lecture de l'option --update : true si le drapeau est passé, false sinon
        $shouldUpdate = (bool) $input->getOption('update');

        $io->title('Seed : 4 articles de blog Bazaart');

        if ($shouldUpdate) {
            $io->note('Mode --update activé : les articles existants seront mis à jour.');
        }

        // ── Étape 1 : récupérer un utilisateur ROLE_ADMIN ─────────────────────
        //
        // La colonne "roles" est un tableau JSON en PostgreSQL. L'opérateur LIKE
        // n'est pas applicable sur le type JSON, on filtre donc côté PHP via
        // getRoles(). Le nombre d'admins est négligeable en V1.
        $admin = null;
        foreach ($this->em->getRepository(User::class)->findAll() as $candidate) {
            if (in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                $admin = $candidate;
                break;
            }
        }

        // Vérification de type stricte pour PHPStan niveau 6
        if (!$admin instanceof User) {
            $io->error(
                'Aucun utilisateur avec ROLE_ADMIN trouvé en base. '
                . 'Créez d\'abord un admin (ex: php bin/console app:create-admin), '
                . 'puis relancez cette commande.'
            );

            return Command::FAILURE;
        }

        $io->text(sprintf(
            'Auteur admin trouve : %s (id=%d)',
            $admin->getEmail(),
            (int) $admin->getId()
        ));

        // ── Étape 2 : définir les 4 articles ──────────────────────────────────
        //
        // RÈGLE STRICTE : aucun tiret cadratin (U+2014) ni demi-cadratin (U+2013)
        // dans aucun des textes ci-dessous. Remplacés par virgules, parenthèses,
        // deux-points ou tiret simple (trait d'union ASCII U+002D).
        //
        // La clé 'slug' sert d'identifiant d'idempotence : si un article avec ce
        // slug existe déjà, on le saute (ou on le met à jour si --update est actif).
        // Les slugs et coverImagePath sont identiques à la version précédente du seed.
        $articles = [
            // ── Article 1 : Financer sa résidence artistique ───────────────────
            [
                'slug'           => 'financer-residence-artistique-etranger',
                'title'          => 'Financer sa résidence artistique à l\'étranger',
                'coverImagePath' => 'uploads/articles/financer-residence-artistique-etranger.jpg',
                'excerpt'        => 'Bourses, aides à la mobilité, fonds privés, financement participatif : le guide des leviers pour partir en résidence sans se ruiner.',
                'content'        => '<p>Partir en résidence à l\'étranger est une étape précieuse : du temps pour créer, un nouvel environnement, des rencontres et souvent un vrai tremplin pour la suite. Reste la question qui freine beaucoup d\'artistes : comment financer ce projet ? Bonne nouvelle, les dispositifs existent. Encore faut il savoir où chercher, et s\'y prendre suffisamment tôt.</p>
<h2>Les aides publiques</h2>
<p>De nombreux organismes soutiennent la mobilité des artistes. Selon votre discipline et votre destination, vous pouvez solliciter des institutions nationales, des fonds européens ou des collectivités territoriales (région, ville). Ces aides couvrent souvent le transport, l\'hébergement, parfois une bourse de production ou un per diem.</p>
<p>Surveillez notamment les appels du Centre national de la musique, de l\'Institut français, ou les programmes européens dédiés à la mobilité culturelle. Les délais de dépôt sont parfois longs : anticipez de plusieurs mois.</p>
<h2>Les fonds privés et les fondations</h2>
<p>Fondations d\'entreprise, mécènes et fonds de dotation financent régulièrement des projets artistiques. Leurs critères sont parfois plus souples que ceux du secteur public, mais la concurrence est forte. Un dossier clair, sincère et bien incarné fait la différence : montrez en quoi la résidence sert votre parcours et votre démarche.</p>
<h2>Le financement participatif</h2>
<p>Le crowdfunding peut compléter un budget, surtout si vous avez déjà une communauté. Au delà de l\'argent récolté, c\'est un excellent moyen de fédérer un public autour de votre projet. Soignez la vidéo de présentation et proposez des contreparties simples et utiles.</p>
<h2>Monter un budget solide</h2>
<p>Listez toutes vos dépenses : transport, logement, matériel, assurance, frais de vie, production. Présentez un budget équilibré, où les recettes couvrent les dépenses. Le financement croisé, qui combine plusieurs sources, rassure les financeurs et sécurise votre projet : ne misez jamais sur une seule aide.</p>
<h2>Nos conseils pour maximiser vos chances</h2>
<p>Commencez tôt, lisez attentivement chaque règlement, et adaptez votre dossier à chaque financeur plutôt que d\'envoyer un texte générique. Gardez une trace de vos candidatures et de leurs échéances. Sur Bazaart, retrouvez les opportunités de mobilité et de financement au même endroit, et activez des alertes pour ne rien manquer.</p>',
            ],

            // ── Article 2 : Répondre à un appel à projets ─────────────────────
            [
                'slug'           => 'repondre-appel-a-projets-dossier-solide',
                'title'          => 'Répondre à un appel à projets : nos conseils pour un dossier solide',
                'coverImagePath' => 'uploads/articles/repondre-appel-a-projets-dossier-solide.jpg',
                'excerpt'        => 'Un bon dossier ne s\'improvise pas. Méthode, note d\'intention, budget, visuels : les réflexes qui font la différence face à un jury.',
                'content'        => '<p>Un appel à projets, c\'est une promesse et une exigence. Pour convaincre un jury, la qualité artistique ne suffit pas : il faut aussi un dossier lisible, complet, et déposé dans les temps. Voici comment mettre toutes les chances de votre côté.</p>
<h2>Décrypter le règlement</h2>
<p>Avant tout, lisez attentivement les critères d\'éligibilité et les attendus. Un dossier hors cadre est écarté, quelle que soit sa valeur artistique. Notez la liste exacte des pièces demandées, le format attendu et la date limite. En cas de doute, contactez l\'organisateur : mieux vaut une question que des heures de travail perdues.</p>
<h2>La note d\'intention, le coeur du dossier</h2>
<p>La note d\'intention est ce que le jury lit en premier et retient le plus. Expliquez votre démarche, le sens du projet, et ce que cette aide ou cette résidence va concrètement permettre. Soyez précis et sincère, allez à l\'essentiel, et bannissez le jargon. Une idée forte, clairement formulée, vaut mieux qu\'un texte ampoulé.</p>
<h2>Le budget prévisionnel</h2>
<p>Présentez un budget honnête et équilibré. Détaillez les postes de dépenses et les sources de financement envisagées. Un budget réaliste inspire confiance ; un budget flou ou déséquilibré inquiète.</p>
<h2>Les pièces visuelles et le portfolio</h2>
<p>Joignez des visuels de qualité qui montrent votre travail sous son meilleur jour. Le jury se fait une opinion en quelques secondes : facilitez lui la lecture avec des images nettes, légendées, et un portfolio cohérent.</p>
<h2>Les erreurs à éviter</h2>
<p>Ne déposez pas à la dernière minute : un imprévu technique peut tout gâcher. Ne réutilisez pas un dossier tel quel d\'un appel à l\'autre sans l\'adapter. Ne négligez pas la relecture : une faute ou un fichier manquant donne une impression de négligence. Anticipez, relisez, et faites vous relire.</p>',
            ],

            // ── Article 3 : Diaspora afro-atlantique et création numérique ─────
            [
                'slug'           => 'diaspora-afro-atlantique-creation-numerique',
                'title'          => 'Diaspora afro-atlantique : les nouvelles formes de création numérique',
                'coverImagePath' => 'uploads/articles/diaspora-afro-atlantique-creation-numerique.jpg',
                'excerpt'        => 'Du net art à l\'intelligence artificielle, le numérique ouvre de nouveaux territoires d\'expression, de mémoire et de diffusion aux artistes de la diaspora.',
                'content'        => '<p>Les outils numériques redessinent la création contemporaine. Pour les artistes de la diaspora afro-atlantique, ils ouvrent de nouveaux territoires d\'expression, de mémoire et de diffusion, souvent en dehors des circuits traditionnels.</p>
<h2>De nouveaux langages artistiques</h2>
<p>Net art, art génératif, réalité augmentée, créations assistées par intelligence artificielle : ces formes permettent de raconter des histoires longtemps restées invisibles, et de toucher des publics partout dans le monde. Le numérique abolit certaines barrières géographiques et financières de la diffusion.</p>
<h2>Mémoire, archive et transmission</h2>
<p>Le numérique devient aussi un puissant outil d\'archivage et de transmission : préserver des récits, documenter des pratiques, faire dialoguer les générations et les territoires de la diaspora. Des artistes s\'emparent de ces outils pour réactiver des mémoires familiales et collectives.</p>
<h2>L\'intelligence artificielle et ses questions</h2>
<p>L\'IA ouvre des possibilités créatives inédites, mais soulève aussi des questions : biais des modèles, représentation, droits d\'auteur. Se les approprier de façon critique, c\'est garder la main sur le sens de son travail plutôt que de subir l\'outil.</p>
<h2>Des opportunités concrètes à saisir</h2>
<p>Festivals, résidences et appels à projets dédiés aux arts numériques se multiplient. C\'est le moment d\'expérimenter, de se former et de candidater. Bazaart vous aide à repérer ces occasions et à vous y préparer, pour que ces nouveaux outils deviennent de vrais leviers de création.</p>',
            ],

            // ── Article 4 : Construire un portfolio d'artiste ──────────────────
            [
                'slug'           => 'construire-portfolio-artiste',
                'title'          => 'Construire un portfolio d\'artiste qui fait la différence',
                'coverImagePath' => 'uploads/articles/construire-portfolio-artiste.jpg',
                'excerpt'        => 'Votre portfolio est votre première impression. Sélection, narration, présentation, format : comment le rendre clair, cohérent et mémorable.',
                'content'        => '<p>Le portfolio est souvent le premier contact entre un artiste et un jury, une galerie ou un partenaire. En quelques pages, il doit donner envie d\'en savoir plus. Voici comment le construire pour qu\'il vous serve vraiment.</p>
<h2>Sélectionner, pas tout montrer</h2>
<p>Choisissez vos pièces les plus fortes plutôt que de tout présenter. Un portfolio resserré et cohérent est bien plus convaincant qu\'un catalogue exhaustif. En cas de doute sur une oeuvre, retirez la : le doute se ressent.</p>
<h2>Raconter une histoire</h2>
<p>Organisez vos travaux pour qu\'ils racontent un parcours et une démarche. L\'ordre des pièces, les transitions et un court texte de présentation aident le lecteur à comprendre votre univers. Un fil conducteur clair transforme une suite d\'images en propos artistique.</p>
<h2>Soigner la présentation</h2>
<p>Des visuels de bonne qualité, une mise en page sobre, une typographie lisible : la forme sert le fond. Évitez les fonds chargés et les effets inutiles. Chaque oeuvre mérite de respirer.</p>
<h2>Choisir le bon format</h2>
<p>Adaptez le format au contexte : une page en ligne pour être trouvé et partagé, un PDF léger pour les candidatures. Vérifiez que vos fichiers s\'ouvrent partout et restent légers à envoyer.</p>
<h2>Garder son portfolio vivant</h2>
<p>Un portfolio n\'est jamais figé. Mettez le à jour régulièrement, retirez les travaux qui ne vous ressemblent plus, ajoutez vos réalisations récentes. Un portfolio clair et actuel, c\'est plus de candidatures abouties et plus d\'opportunités saisies.</p>',
            ],
        ];

        // ── Étape 3 : parcourir les articles et créer ou mettre à jour ────────
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        // Tableau de récapitulatif pour l'affichage final (slug, action)
        $recap = [];

        // Date de publication pour les articles nouvellement créés (now au moment du seed)
        $publishedAt = new \DateTime();

        // Booléen pour savoir si un flush est nécessaire en fin de commande
        $hasChanges = false;

        foreach ($articles as $data) {
            // ── Idempotence : chercher si cet article existe déjà par son slug ─
            $existing = $this->articleRepository->findOneBy(['slug' => $data['slug']]);

            if ($existing instanceof Article) {
                // L'article existe déjà en base. Deux comportements possibles :

                if (!$shouldUpdate) {
                    // Sans --update : on ignore cet article (comportement historique).
                    $io->text(sprintf('  [IGNORE]  %s (slug deja present)', $data['slug']));
                    $recap[] = [$data['slug'], 'ignoré'];
                    $skippedCount++;
                    continue;
                }

                // Avec --update : on met à jour uniquement les champs de contenu.
                // NE PAS toucher : author, status, publishedAt, createdAt.
                // updatedAt sera rafraichi automatiquement par #[ORM\PreUpdate]
                // quand Doctrine détecte les changements et flush.
                $existing->setTitle($data['title']);
                $existing->setExcerpt($data['excerpt']);
                $existing->setContent($data['content']);
                $existing->setCoverImagePath($data['coverImagePath']);

                // persist() n'est pas nécessaire pour une entité déjà gérée par
                // l'UnitOfWork Doctrine. Doctrine détectera les changements au flush.

                $io->text(sprintf('  [MAJ]     %s', $data['slug']));
                $recap[] = [$data['slug'], 'mis à jour'];
                $updatedCount++;
                $hasChanges = true;

                continue;
            }

            // ── L'article n'existe pas encore : on le crée ────────────────────
            $article = new Article();

            // Titre affiché sur la page et dans les listes
            $article->setTitle($data['title']);

            // Slug : identifiant URL unique (ex: /articles/financer-residence-...)
            $article->setSlug($data['slug']);

            // Accroche courte (résumé en liste ou carte)
            $article->setExcerpt($data['excerpt']);

            // Contenu HTML complet de l'article
            $article->setContent($data['content']);

            // Chemin relatif vers l'image de couverture dans public/
            $article->setCoverImagePath($data['coverImagePath']);

            // L'auteur est l'admin récupéré à l'étape 1
            $article->setAuthor($admin);

            // Statut "publié" via l'enum PHP 8.1 ArticleStatus::Published
            // La valeur stockée en BDD est la string 'published' (backed enum)
            $article->setStatus(ArticleStatus::Published);

            // Date de publication : maintenant, au moment de l'exécution du seed
            $article->setPublishedAt($publishedAt);

            // Note : createdAt et updatedAt sont initialisés automatiquement par
            // #[ORM\PrePersist] via initTimestamps() dans l'entité Article.
            // Ne pas les définir manuellement ici.

            // persist() marque l'entité pour insertion. L'INSERT SQL sera lancé au flush()
            $this->em->persist($article);

            $io->text(sprintf('  [CREE]    %s', $data['slug']));
            $recap[] = [$data['slug'], 'créé'];
            $createdCount++;
            $hasChanges = true;
        }

        // ── Étape 4 : flush - envoyer toutes les opérations en une transaction ──
        // On flush uniquement si au moins une entité a été créée ou modifiée
        // pour éviter une transaction vide inutile.
        if ($hasChanges) {
            $this->em->flush();
        }

        // ── Étape 5 : afficher le récapitulatif ───────────────────────────────
        $io->newLine();
        // Tableau SymfonyStyle : 2 colonnes, 1 ligne par article traité
        $io->table(
            ['Slug', 'Action'],
            $recap
        );

        // Message de synthèse adapté selon les compteurs
        if ($createdCount > 0 || $updatedCount > 0) {
            $io->success(sprintf(
                '%d article(s) créé(s), %d mis à jour, %d ignoré(s).',
                $createdCount,
                $updatedCount,
                $skippedCount
            ));
        } else {
            $io->info(sprintf(
                'Aucune modification : les %d article(s) existaient déjà (utilisez --update pour forcer la mise à jour).',
                $skippedCount
            ));
        }

        return Command::SUCCESS;
    }
}
