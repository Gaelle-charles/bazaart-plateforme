<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Course;
use App\Entity\CourseModule;
use App\Entity\Lesson;
use App\Enum\CourseLevel;
use App\Repository\CourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande Symfony pour insérer 2 formations de démonstration publiées.
 *
 * Utilisations :
 *   php bin/console app:seed:courses
 *           → Crée les formations manquantes. Ignore celles déjà présentes (idempotent).
 *
 * Règle d'idempotence :
 *   Chaque formation est identifiée par son SLUG unique.
 *   Si un enregistrement Course avec ce slug existe déjà en base, la formation
 *   est ignorée silencieusement (aucune modification des données existantes).
 *   Cela permet de relancer la commande sans risque de doublon ni d'écrasement.
 *
 * Périmètre strict :
 *   - Aucune modification des utilisateurs, inscriptions (enrollment) ou autres entités.
 *   - Pas de purge de table.
 *   - Seules les 2 formations PUBLIÉES des fixtures (AppFixtures::loadCourses) sont créées.
 *   - Le brouillon (formation 3 dans les fixtures) est volontairement exclu.
 *
 * Cascade de persistance :
 *   Course a cascade: ['persist'] sur ses modules, CourseModule sur ses leçons.
 *   Un seul $em->persist($course) suffit pour toute la hiérarchie.
 *   Un seul $em->flush() à la fin envoie tout en une seule transaction.
 *
 * Cette commande est particulièrement utile sur les environnements de démonstration
 * (ex : branche demo) où AppFixtures ne peut pas être chargée car elle dépend d'un
 * utilisateur admin préexistant et d'autres entités.
 *
 * Contenu identique à AppFixtures::loadCourses() — synchroniser si les fixtures évoluent.
 */
#[AsCommand(
    name: 'app:seed:courses',
    description: 'Insère 2 formations publiées de démonstration (idempotent — vérifie par slug avant insertion).',
)]
class SeedCoursesCommand extends Command
{
    /**
     * EntityManagerInterface : accès Doctrine pour persister et flusher les entités.
     * CourseRepository       : vérifie l'existence d'une formation par son slug.
     *
     * Injection par constructeur (autowiring Symfony — pas de config services.yaml nécessaire).
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courseRepository,
    ) {
        // parent::__construct() est obligatoire pour que Symfony enregistre la commande
        parent::__construct();
    }

    /**
     * execute() est le point d'entrée de la commande.
     *
     * La méthode suit ce plan :
     *   1. Construire les données de chaque formation (entités Course + Modules + Lessons)
     *   2. Vérifier par slug si la formation existe déjà → si oui, passer
     *   3. Sinon, persister la Course (cascade propage aux modules et leçons)
     *   4. Un seul flush() à la fin pour toutes les insertions en une transaction
     *   5. Afficher un récapitulatif lisible dans le terminal
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle fournit des méthodes d'affichage formatées (titre, liste, tableau, etc.)
        $io = new SymfonyStyle($input, $output);

        $io->title('Seed : 2 formations de démonstration Bazaart');

        // ── Compteurs pour le récapitulatif final ──────────────────────────────
        $createdCount = 0;
        $skippedCount = 0;

        // Tableau pour le tableau récapitulatif affiché en fin de commande
        // Chaque ligne : [slug, titre court, action]
        $recap = [];

        // Indicateur : y a-t-il au moins une entité à flusher ?
        // On n'appelle flush() que si nécessaire (évite une transaction vide)
        $hasChanges = false;

        // ══════════════════════════════════════════════════════════════════════
        // FORMATION 1 — Introduction à l'Afrobeats (PUBLIÉE, gratuite)
        // ══════════════════════════════════════════════════════════════════════
        //
        // Cette formation sert à prévisualiser le catalogue public (/formations),
        // la page de vente (/formations/{slug}), et le parcours apprenant
        // (/formations/{slug}/learn). Elle est gratuite (priceInCents = null).

        $slugAfrobeats = 'introduction-afrobeats-rythme-composition';

        // ── Idempotence : vérifier si la formation existe déjà ────────────────
        // findOneBy(['slug' => ...]) exécute un SELECT simple (pas de jointures).
        // On ne teste que le slug car c'est la clé unique métier de la formation.
        if ($this->courseRepository->findOneBy(['slug' => $slugAfrobeats]) !== null) {
            $io->text(sprintf('  [IGNORE]  "%s" (slug déjà présent en base)', $slugAfrobeats));
            $recap[] = [$slugAfrobeats, 'déjà présente'];
            $skippedCount++;
        } else {
            // ── Construire l'entité Course ─────────────────────────────────────
            $courseAfrobeats = new Course();
            $courseAfrobeats
                ->setSlug($slugAfrobeats)
                ->setTitle('Introduction à l\'Afrobeats : rythme et composition')
                ->setSubtitle('Apprenez les bases des percussions et de la production afrobeats en 4 semaines')
                ->setDescription(
                    // Description longue : contexte culturel + programme + public cible
                    "L'afrobeats est bien plus qu'un genre musical — c'est un langage culturel global, "
                    . "né au Nigeria dans les années 2000, qui mêle rythmes traditionnels yoruba, highlife "
                    . "ghanéen, coupures R&B et productions électroniques modernes. "
                    . "Cette formation vous introduit aux fondamentaux rythmiques et compositionnels "
                    . "qui font la signature sonore de l'afrobeats.\n\n"
                    . "Au programme : décryptage des rythmes de base (pattern 4/4, swung 8ths, "
                    . "polyrhythmie), introduction aux instruments clés (talking drum, shekere, congas), "
                    . "initiation à la production sur DAW (Ableton Live / FL Studio), "
                    . "et analyse de productions d'artistes comme Burna Boy, Wizkid et Davido.\n\n"
                    . "Aucune connaissance musicale préalable n'est requise. La formation est accessible "
                    . "aux artistes pluridisciplinaires, aux producteurs débutants et à toute personne "
                    . "souhaitant comprendre les mécanismes culturels de ce mouvement musical."
                )
                // Pas d'image de couverture : null est accepté (coverImage est nullable en BDD)
                ->setCoverImage(null)
                // URL de teaser : YouTube embed (Bunny Stream sera configuré en production)
                ->setTrailerVideoUrl('https://www.youtube.com/embed/example-afrobeats-teaser')
                ->setInstructorName('Kofi Mensah')
                ->setInstructorBio(
                    'Producteur et percussionniste ghanéen basé à Paris depuis 2014. '
                    . 'Kofi a collaboré avec plus de 40 artistes de la scène afrobeats européenne. '
                    . 'Formateur régulier aux ateliers du Pôle Studio Bazaart.'
                )
                ->setInstructorAvatar(null)
                // Durée totale : somme manuelle des leçons des 2 modules (185 min)
                // En production, CourseService::recalculateDuration() le calcule automatiquement
                ->setDurationMinutesTotal(185)
                // Niveau débutant : aucune connaissance préalable requise
                ->setLevel(CourseLevel::BEGINNER)
                // Formation gratuite : null → getFormattedPrice() retournera "Gratuit"
                ->setPriceInCents(null)
                ->setIsPublished(true)
                // publishedAt simulé à 10 jours avant la date d'exécution
                ->setPublishedAt(new \DateTime('-10 days'));

            // ── Module 1 : Histoire et origines ───────────────────────────────
            $moduleAfro1 = new CourseModule();
            $moduleAfro1
                ->setTitle('Histoire et origines de l\'afrobeats')
                ->setDescription(
                    'Comprendre les racines culturelles pour mieux appréhender le son : '
                    . 'du highlife nigérian des années 60 à Fela Kuti, jusqu\'à l\'explosion mondiale.'
                )
                // orderPosition = 0 → premier module affiché dans le parcours
                ->setOrderPosition(0);

            // Leçon 1.1 — Aperçu gratuit (isFreePreview = true)
            // Visible sans inscription pour donner un avant-goût aux visiteurs
            $lessonAfro1_1 = new Lesson();
            $lessonAfro1_1
                ->setTitle('Des racines africaines au Lagos Sound')
                ->setDescription(
                    'Panorama historique : highlife, jùjú music, afrobeat de Fela Kuti '
                    . '— comment ces genres ont posé les bases de l\'afrobeats moderne.'
                )
                // videoBunnyId = null car Bunny Stream n'est pas configuré localement.
                // En production, ce serait l'UUID de la vidéo uploadée sur Bunny Stream.
                ->setVideoBunnyId(null)
                // En attendant Bunny Stream, on utilise une URL YouTube embed en placeholder
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-1-1')
                ->setDurationSeconds(780)       // 13 minutes
                ->setOrderPosition(0)
                // isFreePreview = true → accessible sans inscription (teaser)
                ->setIsFreePreview(true);

            // Leçon 1.2 — Réservée aux inscrits
            $lessonAfro1_2 = new Lesson();
            $lessonAfro1_2
                ->setTitle('Fela Kuti et la naissance de l\'afrobeat')
                ->setDescription(
                    'L\'héritage de Fela Anikulapo Kuti : comment son afrobeat "avec un e" '
                    . 'diffère de l\'afrobeats "avec un s" contemporain.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-1-2')
                ->setDurationSeconds(960)       // 16 minutes
                ->setOrderPosition(1)
                ->setIsFreePreview(false);

            // Leçon 1.3 — Réservée aux inscrits
            $lessonAfro1_3 = new Lesson();
            $lessonAfro1_3
                ->setTitle('L\'explosion mondiale : de Lagos à Londres')
                ->setDescription(
                    'Comment Wizkid, Davido et Burna Boy ont exporté le son nigérian '
                    . 'sur les charts européens et américains dans les années 2010–2020.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-1-3')
                ->setDurationSeconds(840)       // 14 minutes
                ->setOrderPosition(2)
                ->setIsFreePreview(false);

            // addLesson() synchronise les deux côtés de la relation
            // (Lesson.$module est renseigné via Lesson::setModule())
            $moduleAfro1->addLesson($lessonAfro1_1);
            $moduleAfro1->addLesson($lessonAfro1_2);
            $moduleAfro1->addLesson($lessonAfro1_3);

            // ── Module 2 : Rythme et percussions ──────────────────────────────
            $moduleAfro2 = new CourseModule();
            $moduleAfro2
                ->setTitle('Rythme et percussions afrobeats')
                ->setDescription(
                    'Décryptage des patterns rythmiques qui caractérisent le son afrobeats : '
                    . 'du 4/4 swingué aux polyrhythmies, en passant par les instruments traditionnels.'
                )
                ->setOrderPosition(1);

            // Leçon 2.1 — Pattern de base
            $lessonAfro2_1 = new Lesson();
            $lessonAfro2_1
                ->setTitle('Le pattern de base : 4/4 et swung 8ths')
                ->setDescription(
                    'Comprendre pourquoi l\'afrobeats "groove" : analyse du swing, '
                    . 'de la syncope et du décalage rythmique par rapport à la house ou au hip-hop.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-2-1')
                ->setDurationSeconds(1020)      // 17 minutes
                ->setOrderPosition(0)
                ->setIsFreePreview(false);

            // Leçon 2.2 — Instruments clés
            $lessonAfro2_2 = new Lesson();
            $lessonAfro2_2
                ->setTitle('Shekere, talking drum et congas : les instruments clés')
                ->setDescription(
                    'Introduction pratique aux instruments de percussion traditionnels '
                    . 'et à leur rôle dans une production afrobeats moderne.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-2-2')
                ->setDurationSeconds(1200)      // 20 minutes
                ->setOrderPosition(1)
                ->setIsFreePreview(false);

            // Leçon 2.3 — Polyrhythmie
            $lessonAfro2_3 = new Lesson();
            $lessonAfro2_3
                ->setTitle('Polyrhythmie : superposer plusieurs rythmes')
                ->setDescription(
                    'Exercice pratique : construire une grille rythmique à plusieurs couches '
                    . 'en combinant kick, snare, hi-hat et percussions africaines.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-2-3')
                ->setDurationSeconds(1080)      // 18 minutes
                ->setOrderPosition(2)
                ->setIsFreePreview(false);

            $moduleAfro2->addLesson($lessonAfro2_1);
            $moduleAfro2->addLesson($lessonAfro2_2);
            $moduleAfro2->addLesson($lessonAfro2_3);

            // addModule() synchronise CourseModule.$course ← Course::addModule()
            // (appelle $module->setCourse($this) en interne)
            $courseAfrobeats->addModule($moduleAfro1);
            $courseAfrobeats->addModule($moduleAfro2);

            // persist() sur la Course suffit : cascade: ['persist'] sur modules et leçons
            // propagera automatiquement la persistance à toute la hiérarchie.
            // L'INSERT SQL réel est différé jusqu'au flush() unique à la fin.
            $this->em->persist($courseAfrobeats);

            $io->text(sprintf('  [CRÉE]    "%s"', $slugAfrobeats));
            $recap[] = [$slugAfrobeats, 'créée'];
            $createdCount++;
            $hasChanges = true;
        }

        // ══════════════════════════════════════════════════════════════════════
        // FORMATION 2 — Cultural Engineering (PUBLIÉE, payante 49€)
        // ══════════════════════════════════════════════════════════════════════
        //
        // Représente une offre premium du Pôle Lab Bazaart.
        // Le paiement (Stripe) n'est pas intégré en V1 : priceInCents est stocké
        // pour la V2, mais le tunnel d'achat n'existe pas encore.

        $slugCE = 'cultural-engineering-projets-diaspora';

        // ── Idempotence : vérifier si la formation existe déjà ────────────────
        if ($this->courseRepository->findOneBy(['slug' => $slugCE]) !== null) {
            $io->text(sprintf('  [IGNORE]  "%s" (slug déjà présent en base)', $slugCE));
            $recap[] = [$slugCE, 'déjà présente'];
            $skippedCount++;
        } else {
            // ── Construire l'entité Course ─────────────────────────────────────
            $courseCE = new Course();
            $courseCE
                ->setSlug($slugCE)
                ->setTitle('Cultural Engineering : monter et piloter un projet culturel diaspora')
                ->setSubtitle('De l\'idée au financement : méthodes et outils du Pôle Lab Bazaart')
                ->setDescription(
                    // Description longue : discipline, expérience terrain, programme, public cible
                    "Le cultural engineering est la discipline qui permet de concevoir, financer "
                    . "et déployer des projets culturels de manière structurée et durable. "
                    . "Cette formation s'appuie sur l'expérience concrète du Pôle Lab Bazaart "
                    . "et de ses partenaires pour vous donner les outils pratiques du secteur.\n\n"
                    . "Contenu : identification des porteurs de projets et partenaires institutionnels, "
                    . "modèles économiques hybrides (subventions + autofinancement + mécénat), "
                    . "rédaction de dossiers de subvention convaincants, gestion de projet culturel "
                    . "(planning, budget, équipe), et stratégies de communication pour les artistes "
                    . "et collectifs de la diaspora afro-atlantique.\n\n"
                    . "Cette formation est conçue pour les artistes souhaitant professionnaliser "
                    . "leur démarche, les responsables de structures culturelles en développement, "
                    . "et toute personne impliquée dans l'accompagnement de projets artistiques."
                )
                ->setCoverImage(null)
                ->setTrailerVideoUrl('https://www.youtube.com/embed/example-ce-teaser')
                ->setInstructorName('Gaëlle Charles-Belamour')
                ->setInstructorBio(
                    'Co-fondatrice du Pôle Lab chez Bazaart, Gaëlle accompagne des artistes '
                    . 'et collectifs culturels depuis 2019 dans la structuration de leurs projets. '
                    . 'Diplômée en gestion des organisations culturelles (Paris-Sorbonne).'
                )
                ->setInstructorAvatar(null)
                // Durée totale calculée manuellement depuis la somme des leçons (240 min)
                ->setDurationMinutesTotal(240)
                // Niveau intermédiaire : bases du secteur culturel déjà acquises
                ->setLevel(CourseLevel::INTERMEDIATE)
                // Formation payante : 49,00€ = 4 900 centimes
                // Stripe n'est pas intégré en V1 — le tunnel de paiement sera implémenté en V2
                ->setPriceInCents(4900)
                ->setIsPublished(true)
                // publishedAt simulé à 5 jours avant la date d'exécution
                ->setPublishedAt(new \DateTime('-5 days'));

            // ── Module CE-1 : Écosystème culturel ─────────────────────────────
            $moduleCE1 = new CourseModule();
            $moduleCE1
                ->setTitle('L\'écosystème culturel en France et en Europe')
                ->setDescription(
                    'Cartographie des acteurs : ministères, DRAC, collectivités, fondations, '
                    . 'opérateurs culturels — qui finance quoi, comment et pourquoi.'
                )
                ->setOrderPosition(0);

            // Leçon CE-1.1 — Aperçu gratuit pour attirer les futurs inscrits
            $lessonCE1_1 = new Lesson();
            $lessonCE1_1
                ->setTitle('Panorama du financement public de la culture')
                ->setDescription(
                    'MCC, DRAC, CNM, CNC, CNAP : les grands opérateurs publics et '
                    . 'leurs dispositifs de soutien aux artistes et aux structures.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-1-1')
                ->setDurationSeconds(1320)      // 22 minutes
                ->setOrderPosition(0)
                // Leçon de présentation accessible gratuitement pour convertir les visiteurs
                ->setIsFreePreview(true);

            // Leçon CE-1.2 — Réservée aux inscrits
            $lessonCE1_2 = new Lesson();
            $lessonCE1_2
                ->setTitle('Mécénat privé et fondations : trouver les bons interlocuteurs')
                ->setDescription(
                    'Fondation de France, fondations d\'entreprises, crowdfunding culturel : '
                    . 'identifier et approcher les financeurs privés adaptés à votre projet.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-1-2')
                ->setDurationSeconds(1140)      // 19 minutes
                ->setOrderPosition(1)
                ->setIsFreePreview(false);

            $moduleCE1->addLesson($lessonCE1_1);
            $moduleCE1->addLesson($lessonCE1_2);

            // ── Module CE-2 : Dossier de subvention ───────────────────────────
            $moduleCE2 = new CourseModule();
            $moduleCE2
                ->setTitle('Rédiger un dossier de subvention convaincant')
                ->setDescription(
                    'Méthode pas à pas pour construire un dossier de demande de subvention '
                    . 'qui répond exactement aux attentes des commissions de sélection.'
                )
                ->setOrderPosition(1);

            // Leçon CE-2.1 — Anatomie d'un bon dossier
            $lessonCE2_1 = new Lesson();
            $lessonCE2_1
                ->setTitle('Anatomie d\'un bon dossier : les éléments incontournables')
                ->setDescription(
                    'Note d\'intention, budget prévisionnel, CV artistique, portfolio : '
                    . 'analyse de dossiers réels (anonymisés) acceptés et refusés.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-2-1')
                ->setDurationSeconds(1500)      // 25 minutes
                ->setOrderPosition(0)
                ->setIsFreePreview(false);

            // Leçon CE-2.2 — Budget prévisionnel
            $lessonCE2_2 = new Lesson();
            $lessonCE2_2
                ->setTitle('Formuler un budget prévisionnel réaliste')
                ->setDescription(
                    'Comment estimer les coûts d\'un projet culturel, anticiper les imprévus '
                    . 'et présenter un budget crédible aux financeurs institutionnels.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-2-2')
                ->setDurationSeconds(1380)      // 23 minutes
                ->setOrderPosition(1)
                ->setIsFreePreview(false);

            // Leçon CE-2.3 — Adapter le dossier selon l'interlocuteur
            $lessonCE2_3 = new Lesson();
            $lessonCE2_3
                ->setTitle('Adapter son dossier à chaque interlocuteur')
                ->setDescription(
                    'Un même projet, plusieurs dossiers : personnaliser le discours selon '
                    . 'que l\'on s\'adresse à une DRAC, une fondation privée ou un mécène d\'entreprise.'
                )
                ->setVideoBunnyId(null)
                ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-2-3')
                ->setDurationSeconds(960)       // 16 minutes
                ->setOrderPosition(2)
                ->setIsFreePreview(false);

            $moduleCE2->addLesson($lessonCE2_1);
            $moduleCE2->addLesson($lessonCE2_2);
            $moduleCE2->addLesson($lessonCE2_3);

            $courseCE->addModule($moduleCE1);
            $courseCE->addModule($moduleCE2);

            // persist() sur la Course propage la persistance à tous les modules et leçons
            $this->em->persist($courseCE);

            $io->text(sprintf('  [CRÉE]    "%s"', $slugCE));
            $recap[] = [$slugCE, 'créée'];
            $createdCount++;
            $hasChanges = true;
        }

        // ── Flush unique — une seule transaction pour toutes les insertions ────
        //
        // On ne flush() que si au moins une formation a été créée.
        // Cela évite d'ouvrir une transaction vide (légèrement moins efficace
        // et légèrement plus bruyant dans les logs Doctrine).
        if ($hasChanges) {
            $this->em->flush();
        }

        // ── Récapitulatif terminal ─────────────────────────────────────────────
        $io->newLine();
        // Tableau lisible avec 2 colonnes (SymfonyStyle::table)
        $io->table(
            ['Slug', 'Action'],
            $recap
        );

        if ($createdCount > 0) {
            $io->success(sprintf(
                '%d formation(s) créée(s), %d ignorée(s) (déjà présentes).',
                $createdCount,
                $skippedCount
            ));
        } else {
            // Toutes les formations existaient déjà : rien à faire
            $io->info(sprintf(
                'Aucune création : les %d formation(s) étaient déjà présentes en base.',
                $skippedCount
            ));
        }

        return Command::SUCCESS;
    }
}
