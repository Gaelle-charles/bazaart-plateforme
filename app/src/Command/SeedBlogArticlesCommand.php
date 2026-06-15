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
                'title'          => 'Financer sa résidence artistique à l\'étranger : le guide complet 2026',
                'coverImagePath' => 'uploads/articles/financer-residence-artistique-etranger.jpg',
                'excerpt'        => 'Bourses, aides à la mobilité, mécénat, financement participatif : toutes les solutions concrètes pour financer votre résidence artistique à l\'étranger.',
                'content'        => '<p>Décrocher une résidence artistique à l\'étranger, c\'est gagner du temps pour créer, changer de regard et tisser un réseau international. Mais une fois la sélection passée, une question revient sans cesse : comment financer ce séjour ? Entre le transport, le logement, le matériel et la vie sur place, la facture grimpe vite. La bonne nouvelle, c\'est qu\'il existe de nombreux leviers de financement, publics comme privés. Voici un guide concret pour boucler votre budget et partir sereinement.</p>
<h2>Combien coûte vraiment une résidence à l\'étranger ?</h2>
<p>Avant de chercher des financements, posez les chiffres. Une résidence engage généralement plusieurs types de dépenses :</p>
<ul>
<li>le transport aller-retour, parfois plusieurs trajets ;</li>
<li>l\'hébergement, quand il n\'est pas pris en charge par la structure d\'accueil ;</li>
<li>le matériel et les frais de production de vos oeuvres ;</li>
<li>les frais de vie quotidienne (nourriture, déplacements locaux, assurance) ;</li>
<li>une éventuelle perte de revenus pendant votre absence.</li>
</ul>
<p>Chiffrer ces postes avec honnêteté est la première étape. Un budget clair vous servira pour chaque demande de financement.</p>
<h2>Les aides publiques à la mobilité</h2>
<p>De nombreux organismes soutiennent la mobilité des artistes. Selon votre discipline et votre destination, vous pouvez solliciter des institutions nationales, des programmes européens ou des collectivités territoriales (votre région, votre ville). Ces aides couvrent souvent le voyage, l\'hébergement, parfois une bourse de production.</p>
<p>Surveillez en particulier les appels du Centre national de la musique, de l\'Institut français et les dispositifs européens dédiés à la mobilité culturelle. Les calendriers sont parfois très en amont : commencez vos recherches plusieurs mois avant le départ.</p>
<h2>Le mécénat et les fondations privées</h2>
<p>Fondations d\'entreprise, mécènes et fonds de dotation financent régulièrement des projets artistiques. Leurs critères sont parfois plus souples que ceux du secteur public, mais la concurrence reste forte. Pour vous démarquer, reliez clairement votre résidence à votre démarche artistique : que va-t-elle permettre que vous ne pourriez pas faire autrement ?</p>
<h2>Le financement participatif</h2>
<p>Le crowdfunding peut compléter votre budget, surtout si vous avez déjà une communauté qui vous suit. Au delà de l\'argent récolté, c\'est un formidable outil pour fédérer un public autour de votre projet. Soignez votre vidéo de présentation, racontez une histoire, et proposez des contreparties simples et sincères.</p>
<h2>Construire un budget qui inspire confiance</h2>
<p>La règle d\'or : ne jamais miser sur une seule source. Le financement croisé, qui combine bourse publique, mécénat et apport personnel, rassure les financeurs et sécurise votre départ. Présentez un budget équilibré, où les recettes couvrent les dépenses, et gardez une marge pour les imprévus.</p>
<h2>Passez à l\'action</h2>
<p>Le financement d\'une résidence se prépare comme un projet à part entière : tôt, avec méthode, et en multipliant les pistes. Pour ne rien manquer, <a href="/resources">explorez les opportunités de mobilité et de financement</a> rassemblées sur Bazaart, et <a href="/register">créez votre compte gratuitement</a> pour recevoir des alertes personnalisées. Votre prochaine résidence commence par un bon dossier de financement.</p>',
            ],

            // ── Article 2 : Répondre à un appel à projets ─────────────────────
            [
                'slug'           => 'repondre-appel-a-projets-dossier-solide',
                'title'          => 'Répondre à un appel à projets artistique : 7 étapes pour un dossier qui convainc',
                'coverImagePath' => 'uploads/articles/repondre-appel-a-projets-dossier-solide.jpg',
                'excerpt'        => 'Note d\'intention, budget, visuels, calendrier : la méthode complète pour bâtir un dossier d\'appel à projets solide et convaincre un jury.',
                'content'        => '<p>Un appel à projets, c\'est une opportunité réelle, mais aussi une compétition. Face à des dizaines de candidatures, la qualité artistique ne suffit pas toujours : c\'est souvent la clarté et le sérieux du dossier qui font la différence. Voici une méthode en étapes pour transformer une bonne idée en dossier qui retient l\'attention du jury.</p>
<h2>1. Décrypter le règlement avant tout</h2>
<p>Lisez attentivement les critères d\'éligibilité et les attendus. Un dossier hors cadre est écarté, quelle que soit sa valeur. Repérez la liste exacte des pièces demandées, le format attendu et la date limite. En cas de doute, posez la question à l\'organisateur : mieux vaut un mail que des heures de travail perdues.</p>
<h2>2. Clarifier votre intention</h2>
<p>La note d\'intention est le coeur du dossier, ce que le jury lit en premier et retient le plus. Expliquez votre démarche, le sens du projet, et ce que cette aide va concrètement permettre. Allez à l\'essentiel, soyez sincère, et bannissez le jargon. Une idée forte clairement formulée vaut mieux qu\'un texte ampoulé.</p>
<h2>3. Soigner le budget prévisionnel</h2>
<p>Détaillez vos postes de dépenses et vos sources de financement. Un budget réaliste et équilibré inspire confiance ; un budget flou inquiète. N\'hésitez pas à valoriser vos apports en nature (temps, matériel déjà possédé).</p>
<h2>4. Choisir des visuels qui parlent</h2>
<p>Joignez des images nettes, légendées, qui montrent votre travail sous son meilleur jour. Le jury se fait une opinion en quelques secondes : un portfolio cohérent et soigné facilite sa lecture et renforce votre crédibilité.</p>
<h2>5. Proposer un calendrier crédible</h2>
<p>Un planning clair, avec des étapes réalistes, montre que vous maîtrisez votre projet. Évitez les délais intenables qui sonnent faux.</p>
<h2>6. Relire, faire relire, vérifier</h2>
<p>Une faute, un fichier manquant ou un lien mort donnent une impression de négligence. Relisez à tête reposée, faites relire par un tiers, et vérifiez chaque pièce avant l\'envoi.</p>
<h2>7. Anticiper le dépôt</h2>
<p>Ne déposez jamais à la dernière minute : un imprévu technique peut tout gâcher. Visez la veille de la date limite.</p>
<h2>Les erreurs qui coûtent cher</h2>
<p>Réutiliser un dossier tel quel d\'un appel à l\'autre, négliger la note d\'intention, oublier une pièce : ces erreurs fréquentes sont évitables. Adaptez toujours votre candidature au dispositif visé.</p>
<h2>Trouvez votre prochain appel à projets</h2>
<p>La régularité paie : plus vous candidatez avec méthode, plus vos chances augmentent. <a href="/resources">Parcourez les appels à projets et bourses</a> rassemblés sur Bazaart, et <a href="/register">inscrivez-vous gratuitement</a> pour être alerté dès qu\'une opportunité correspond à votre profil.</p>',
            ],

            // ── Article 3 : Diaspora afro-atlantique et création numérique ─────
            [
                'slug'           => 'diaspora-afro-atlantique-creation-numerique',
                'title'          => 'Diaspora afro-atlantique et création numérique : un nouveau territoire artistique',
                'coverImagePath' => 'uploads/articles/diaspora-afro-atlantique-creation-numerique.jpg',
                'excerpt'        => 'Net art, art génératif, intelligence artificielle : comment le numérique offre aux artistes de la diaspora afro-atlantique de nouveaux espaces de création et de mémoire.',
                'content'        => '<p>Les outils numériques redessinent la création contemporaine. Pour les artistes de la diaspora afro-atlantique, ils ouvrent bien plus qu\'un terrain technique : un territoire d\'expression, de mémoire et de diffusion, souvent loin des circuits traditionnels. Tour d\'horizon d\'un champ en pleine effervescence et des opportunités qu\'il dessine.</p>
<h2>De nouveaux langages artistiques</h2>
<p>Net art, art génératif, réalité augmentée, créations assistées par intelligence artificielle : ces formes permettent de raconter des histoires longtemps restées invisibles, et de toucher des publics partout dans le monde. Le numérique abaisse certaines barrières géographiques et financières de la diffusion, ce qui change la donne pour beaucoup d\'artistes.</p>
<h2>Le numérique comme outil de mémoire</h2>
<p>Au delà de la création, le numérique devient un puissant instrument d\'archivage et de transmission. Préserver des récits familiaux, documenter des pratiques, faire dialoguer les générations et les territoires de la diaspora : des artistes s\'emparent de ces outils pour réactiver des mémoires collectives et leur donner une forme contemporaine.</p>
<h2>L\'intelligence artificielle et ses questions</h2>
<p>L\'IA ouvre des possibilités créatives inédites, mais soulève aussi des enjeux importants : biais des modèles, représentation, droits d\'auteur. Se les approprier de façon critique, c\'est garder la main sur le sens de son travail plutôt que de subir l\'outil. Les artistes qui interrogent ces technologies, autant qu\'ils les utilisent, produisent souvent les oeuvres les plus fortes.</p>
<h2>Des compétences à acquérir</h2>
<p>Explorer ces territoires demande de se former : logiciels, culture numérique, droits liés aux oeuvres digitales. Se faire accompagner, échanger avec des pairs et suivre des formations courtes permet de gagner un temps précieux.</p>
<h2>Des opportunités concrètes à saisir</h2>
<p>Festivals, résidences et appels à projets dédiés aux arts numériques se multiplient. C\'est le moment d\'expérimenter et de candidater. <a href="/resources">Découvrez les opportunités liées aux arts numériques</a> sur Bazaart, et <a href="/register">rejoignez la communauté</a> pour échanger, vous former et faire grandir votre pratique. Le numérique n\'est pas une mode : c\'est un nouvel espace de création à investir dès maintenant.</p>',
            ],

            // ── Article 4 : Construire un portfolio d'artiste ──────────────────
            [
                'slug'           => 'construire-portfolio-artiste',
                'title'          => 'Portfolio d\'artiste : comment le construire pour décrocher des opportunités',
                'coverImagePath' => 'uploads/articles/construire-portfolio-artiste.jpg',
                'excerpt'        => 'Sélection, narration, mise en page, format : la méthode pour construire un portfolio d\'artiste clair, cohérent et mémorable, qui ouvre des portes.',
                'content'        => '<p>Le portfolio est souvent le premier contact entre un artiste et un jury, une galerie ou un partenaire. En quelques pages, il doit donner envie d\'en savoir plus. Pourtant, beaucoup d\'artistes commettent les mêmes erreurs : trop d\'oeuvres, pas de fil conducteur, une présentation négligée. Voici comment construire un portfolio qui vous serve vraiment.</p>
<h2>Sélectionner, plutôt que tout montrer</h2>
<p>Choisissez vos pièces les plus fortes au lieu de présenter l\'intégralité de votre travail. Un portfolio resserré et cohérent est bien plus convaincant qu\'un catalogue exhaustif. En cas de doute sur une oeuvre, retirez la : le doute se ressent toujours.</p>
<h2>Raconter une histoire</h2>
<p>Organisez vos travaux pour qu\'ils racontent un parcours et une démarche. L\'ordre des pièces, les transitions et un court texte de présentation aident le lecteur à comprendre votre univers. Un fil conducteur clair transforme une suite d\'images en véritable propos artistique.</p>
<h2>Soigner la présentation</h2>
<p>Des visuels de bonne qualité, une mise en page sobre et une typographie lisible : la forme sert le fond. Évitez les fonds chargés et les effets inutiles. Chaque oeuvre mérite de respirer. Pensez aussi à légender vos pièces (titre, année, technique, dimensions).</p>
<h2>Choisir le bon format</h2>
<p>Adaptez le format au contexte : une page en ligne pour être trouvé et partagé facilement, un PDF léger pour les candidatures officielles. Vérifiez que vos fichiers s\'ouvrent partout et restent légers à envoyer. Un portfolio que personne ne parvient à ouvrir ne sert à rien.</p>
<h2>Garder un portfolio vivant</h2>
<p>Un portfolio n\'est jamais figé. Mettez le à jour régulièrement : retirez les travaux qui ne vous ressemblent plus, ajoutez vos réalisations récentes. Un portfolio clair et actuel, c\'est plus de candidatures abouties et plus d\'opportunités saisies.</p>
<h2>Mettez votre portfolio au service de vos candidatures</h2>
<p>Un bon portfolio est un investissement qui se rentabilise à chaque appel à projets. Une fois le vôtre prêt, <a href="/resources">trouvez les opportunités faites pour vous</a> sur Bazaart et <a href="/register">créez votre compte gratuitement</a> pour candidater au bon moment. Votre travail mérite d\'être vu : donnez lui le cadre qu\'il mérite.</p>',
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
