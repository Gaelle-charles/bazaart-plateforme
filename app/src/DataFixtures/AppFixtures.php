<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\ArtistProfile;
use App\Entity\Course;
use App\Entity\CourseEnrollment;
use App\Entity\CourseModule;
use App\Entity\Discipline;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use App\Entity\OrganizationProfile;
use App\Entity\Resource;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Entity\ResourceAlert;
use App\Enum\AlertFrequency;
use App\Enum\ArticleStatus;
use App\Enum\ArtistLookingFor;
use App\Enum\CourseEventMode;
use App\Enum\CourseLevel;
use App\Enum\CourseType;
use App\Enum\ResourceStatus;
use App\Enum\SubmitterRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures principales de la plateforme Bazaart.
 *
 * Ces fixtures peuplent la base locale avec des données de test réalistes
 * représentatives du contenu de la Ressourcerie (artistes de la diaspora
 * afro-atlantique).
 *
 * ⚠️  À LANCER SUR BASE VIDE UNIQUEMENT (ou après doctrine:fixtures:load --purge-with-truncate)
 *     car il n'y a pas de vérification d'idempotence pour les ressources et articles.
 *     Les emails admin@bazaart.fr / artiste@bazaart.fr / structure@bazaart.fr
 *     peuvent provoquer une violation d'unicité si des utilisateurs existent déjà.
 *
 * Ordre de création dans load() :
 *   1. Utilisateurs (admin, artiste, structure)
 *   2. Profils (ArtistProfile, OrganizationProfile)
 *   3. Disciplines artistiques
 *   4. Types de ressources (ResourceType)
 *   5. Ressources publiées (12)
 *   6. Articles publiés (3)
 *   7. Formations (2 formations CONTENU publiées + 1 brouillon + 2 événements publiés + 1 inscription)
 */
class AppFixtures extends Fixture
{
    /**
     * On injecte le hasheur de mots de passe via le constructeur.
     * Symfony Autowiring le fournit automatiquement — aucune config manuelle
     * nécessaire dans services.yaml.
     */
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Point d'entrée appelé par doctrine:fixtures:load.
     * L'ObjectManager est le gestionnaire Doctrine (équivalent de l'EntityManager).
     */
    public function load(ObjectManager $manager): void
    {
        // ── Étape 1 : Utilisateurs ────────────────────────────────────────────
        $adminUser     = $this->createAdminUser($manager);
        $artistUser    = $this->createArtistUser($manager);
        $structureUser = $this->createStructureUser($manager);

        // ── Étape 2 : Profils associés aux utilisateurs ───────────────────────
        $this->createArtistProfile($manager, $artistUser);
        $structureOrg = $this->createOrganizationProfile($manager, $structureUser, $adminUser);

        // ── Étape 3 : Disciplines artistiques ────────────────────────────────
        $disciplines = $this->createDisciplines($manager);

        // ── Étape 4 : Types de ressources ────────────────────────────────────
        $resourceTypes = $this->createResourceTypes($manager);

        // On persiste tout ce qu'on a créé jusqu'ici AVANT de créer les ressources,
        // car Resource a des FK vers User, ResourceType, etc. qui doivent exister.
        $manager->flush();

        // ── Étape 4b : ResourceAlert pour l'artiste de démo ──────────────────
        // On crée un profil d'alertes pour artiste@bazaart.fr, cohérent avec
        // les lookingFor définis ci-dessus (RESSOURCES_APPELS + FORMATIONS).
        // disciplines et types sont disponibles après le flush.
        $this->createArtistResourceAlert($manager, $artistUser, $disciplines, $resourceTypes);

        // ── Étape 5 : 12 ressources publiées ─────────────────────────────────
        $this->createResources($manager, $adminUser, $structureUser, $structureOrg, $disciplines, $resourceTypes);

        // ── Étape 6 : 3 articles publiés ─────────────────────────────────────
        $this->createArticles($manager, $adminUser, $artistUser);

        // ── Étape 7 : Formations (module Formation) ───────────────────────────
        // On passe $artistUser pour créer l'inscription de démonstration.
        // Les users doivent déjà être persistés (flush() ci-dessus), mais ici
        // on utilise les entités PHP qui sont déjà trackées par Doctrine — pas
        // besoin d'un second flush avant de les référencer en relation.
        $this->loadCourses($manager, $artistUser);

        // Flush final pour tout enregistrer en base
        $manager->flush();
    }

    // =========================================================================
    // Création des utilisateurs
    // =========================================================================

    /**
     * Crée l'administrateur principal de la plateforme.
     *
     * Identifiants :
     *   Email    : admin@bazaart.fr
     *   Password : Admin1234! (conforme CDC §9 : 10 chars, 1 maj, 1 chiffre)
     *   Rôle     : ROLE_ADMIN (hérite de tous les autres selon security.yaml)
     */
    private function createAdminUser(ObjectManager $manager): User
    {
        $admin = new User();
        $admin
            ->setEmail('admin@bazaart.fr')
            // setRoles prend un tableau sans ROLE_USER (il est ajouté automatiquement
            // dans User::getRoles()). On ne stocke que les rôles supplémentaires.
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true)
            // Onboarding complété = true pour les comptes de démo (ils existaient avant le Lot 2)
            ->setOnboardingCompleted(true)
            ->setPassword(
                // hashPassword() hache le mot de passe en clair avec l'algorithme
                // configuré dans security.yaml (bcrypt par défaut en Symfony 7.x).
                // NE JAMAIS stocker le mot de passe en clair en base !
                $this->passwordHasher->hashPassword($admin, 'Admin1234!')
            );

        $manager->persist($admin);

        return $admin;
    }

    /**
     * Crée l'utilisateur artiste de test.
     *
     * Identifiants :
     *   Email    : artiste@bazaart.fr
     *   Password : TestPass12! (conforme CDC §9 : 10 chars, 1 maj, 1 chiffre)
     *   Rôles    : ROLE_USER (implicite) + ROLE_ARTIST
     */
    private function createArtistUser(ObjectManager $manager): User
    {
        $artist = new User();
        $artist
            ->setEmail('artiste@bazaart.fr')
            ->setRoles(['ROLE_ARTIST'])
            ->setIsVerified(true)
            // Onboarding complété avec des données réalistes pour prévisualiser le dashboard
            ->setOnboardingCompleted(true)
            ->setLookingFor([
                ArtistLookingFor::RESSOURCES_APPELS->value,
                ArtistLookingFor::FORMATIONS->value,
            ])
            ->setPassword(
                // Conforme à la politique CDC §9 : min 10 chars, 1 maj, 1 chiffre
                $this->passwordHasher->hashPassword($artist, 'TestPass12!')
            );

        $manager->persist($artist);

        return $artist;
    }

    /**
     * Crée l'utilisateur structure (partenaire) de test.
     *
     * Identifiants :
     *   Email    : structure@bazaart.fr
     *   Password : TestPass12! (conforme CDC §9 : 10 chars, 1 maj, 1 chiffre)
     *   Rôles    : ROLE_USER (implicite) + ROLE_STRUCTURE
     */
    private function createStructureUser(ObjectManager $manager): User
    {
        $structure = new User();
        $structure
            ->setEmail('structure@bazaart.fr')
            ->setRoles(['ROLE_STRUCTURE'])
            ->setIsVerified(true)
            // Onboarding complété = true (compte structure = pas le parcours artiste)
            ->setOnboardingCompleted(true)
            ->setPassword(
                // Conforme à la politique CDC §9 : min 10 chars, 1 maj, 1 chiffre
                $this->passwordHasher->hashPassword($structure, 'TestPass12!')
            );

        $manager->persist($structure);

        return $structure;
    }

    // =========================================================================
    // Création des profils
    // =========================================================================

    /**
     * Crée le profil artiste associé à l'utilisateur artiste@bazaart.fr.
     *
     * ArtistProfile a une relation OneToOne avec User. Les lifecycle callbacks
     * de ArtistProfile (PrePersist) s'occuperont de createdAt/updatedAt.
     */
    private function createArtistProfile(ObjectManager $manager, User $artistUser): void
    {
        $profile = new ArtistProfile();
        $profile
            ->setUser($artistUser)
            ->setDisplayName('Amara Diallo')
            ->setBio(
                'Artiste pluridisciplinaire originaire de Guinée, basé à Paris depuis 2018. '
                . 'Mon travail explore les tensions entre mémoire collective africaine et modernité '
                . 'numérique à travers la photographie, la vidéo et les installations sonores. '
                . 'Lauréat du Prix Émergences 2023.'
            )
            ->setLocation('Paris, France')
            ->setWebsiteUrl('https://amaradiallo.art')
            ->setSocialLinks([
                'instagram' => 'https://instagram.com/amara.diallo.art',
                'linkedin'  => 'https://linkedin.com/in/amara-diallo',
            ]);

        $manager->persist($profile);
    }

    /**
     * Crée le profil organisation associé à l'utilisateur structure@bazaart.fr.
     *
     * Cette organisation est un compte Structure validé (isStructurePartner = true),
     * ce qui permet à la structure de publier des ressources sans validation admin.
     */
    private function createOrganizationProfile(
        ObjectManager $manager,
        User $structureUser,
        User $adminUser,
    ): OrganizationProfile {
        $org = new OrganizationProfile();
        $org
            ->setUser($structureUser)
            ->setName('Collectif Afrik\'Art')
            ->setDescription(
                'Association loi 1901 fondée en 2015, dédiée à la promotion des artistes '
                . 'de la diaspora africaine en France et en Europe. Nous organisons des expositions, '
                . 'résidences et ateliers dans toute la région Île-de-France.'
            )
            ->setContactEmail('contact@afrikart.fr')
            ->setWebsiteUrl('https://www.afrikart.fr')
            ->setLocation('Paris, France')
            ->setIsVerified(true)
            // Activation du compte Structure : validé par l'admin
            ->setIsStructurePartner(true)
            ->setStructureActivatedAt(new \DateTime('-15 days'))
            ->setStructureActivationValidatedBy($adminUser)
            // La candidature a été soumise avant d'être acceptée
            ->setStructureApplicationAt(new \DateTime('-20 days'));

        $manager->persist($org);

        return $org;
    }

    // =========================================================================
    // Création des disciplines artistiques
    // =========================================================================

    /**
     * Crée les 8 disciplines de base de la plateforme.
     *
     * Ces disciplines sont pré-remplies dans la base — les utilisateurs ne
     * peuvent pas en créer de nouvelles (interface admin uniquement, V2).
     *
     * Retourne un tableau associatif [nom => Discipline] pour faciliter
     * l'attribution aux ressources plus bas.
     *
     * @return array<string, Discipline>
     */
    private function createDisciplines(ObjectManager $manager): array
    {
        // Chaque discipline a un nom et une icône émoji pour l'affichage
        $disciplinesData = [
            'Musique'                => '🎵',
            'Cinéma & Audiovisuel'   => '🎬',
            'Arts visuels'           => '🖼️',
            'Danse'                  => '💃',
            'Théâtre & Performance'  => '🎭',
            'Littérature'            => '📖',
            'Arts numériques'        => '💻',
            'Mode & Design'          => '✂️',
        ];

        $disciplines = [];

        foreach ($disciplinesData as $name => $icon) {
            $discipline = new Discipline();
            $discipline
                ->setName($name)
                ->setIcon($icon);

            $manager->persist($discipline);

            // On indexe par nom pour pouvoir récupérer facilement via $disciplines['Musique']
            $disciplines[$name] = $discipline;
        }

        return $disciplines;
    }

    // =========================================================================
    // Création des types de ressources
    // =========================================================================

    /**
     * Crée les 5 types de ressources de la Ressourcerie.
     *
     * Comme pour les disciplines, ces types sont pré-définis et gérés par les
     * admins. Ils servent de catégories dans les filtres de recherche.
     *
     * @return array<string, ResourceType>
     */
    private function createResourceTypes(ObjectManager $manager): array
    {
        $typesData = [
            'Appel à projets'       => '📢',
            'Résidence artistique'  => '🏠',
            'Bourse & Financement'  => '💰',
            'Formation'             => '🎓',
            'Prix & Concours'       => '🏆',
        ];

        $types = [];

        foreach ($typesData as $name => $icon) {
            $type = new ResourceType();
            $type
                ->setName($name)
                ->setIcon($icon);

            $manager->persist($type);

            $types[$name] = $type;
        }

        return $types;
    }

    // =========================================================================
    // Création du ResourceAlert de démo pour l'artiste
    // =========================================================================

    /**
     * Crée un profil d'alertes pour l'artiste de démo (artiste@bazaart.fr).
     *
     * Ce profil illustre ce que l'onboarding crée :
     *   - Fréquence quotidienne
     *   - Filtre sur les disciplines "Musique" et "Arts visuels"
     *   - Filtre sur les types "Appel à projets", "Formation", "Prix & Concours"
     *
     * @param array<string, Discipline>   $disciplines
     * @param array<string, ResourceType> $resourceTypes
     */
    private function createArtistResourceAlert(
        ObjectManager $manager,
        User $artistUser,
        array $disciplines,
        array $resourceTypes,
    ): void {
        $alert = new ResourceAlert();
        $alert
            ->setUser($artistUser)
            ->setNotifyOnNewResource(true)
            ->setFrequency(AlertFrequency::Daily);

        // Disciplines d'intérêt : Musique et Arts visuels
        // (cohérent avec le profil d'Amara Diallo — musicien et plasticien)
        if (isset($disciplines['Musique'])) {
            $alert->addFilterDiscipline($disciplines['Musique']);
        }
        if (isset($disciplines['Arts visuels'])) {
            $alert->addFilterDiscipline($disciplines['Arts visuels']);
        }

        // Types de ressources d'intérêt : appels, formations et prix
        // (cohérent avec lookingFor = RESSOURCES_APPELS + FORMATIONS)
        if (isset($resourceTypes['Appel à projets'])) {
            $alert->addFilterResourceType($resourceTypes['Appel à projets']);
        }
        if (isset($resourceTypes['Formation'])) {
            $alert->addFilterResourceType($resourceTypes['Formation']);
        }
        if (isset($resourceTypes['Prix & Concours'])) {
            $alert->addFilterResourceType($resourceTypes['Prix & Concours']);
        }

        $manager->persist($alert);
        // Pas de flush() ici : le flush global dans load() s'en chargera
    }

    // =========================================================================
    // Création des 12 ressources publiées
    // =========================================================================

    /**
     * Crée les 12 ressources de démonstration publiées.
     *
     * Chaque ressource représente une opportunité réelle du secteur culturel
     * afro-atlantique. Les données sont réalistes mais fictives.
     *
     * Convention createdAt/updatedAt : comme Resource n'expose pas de setCreatedAt()
     * (les timestamps sont gérés par PrePersist uniquement), on force les propriétés
     * privées via la réflexion PHP. C'est la technique standard pour les fixtures
     * quand on veut simuler des données d'historique.
     *
     * @param array<string, Discipline>   $disciplines
     * @param array<string, ResourceType> $resourceTypes
     */
    private function createResources(
        ObjectManager $manager,
        User $adminUser,
        User $structureUser,
        OrganizationProfile $structureOrg,
        array $disciplines,
        array $resourceTypes,
    ): void {
        // Tableau des 12 ressources à créer.
        // Format de chaque entrée :
        //   'title'          => string
        //   'type'           => nom du ResourceType
        //   'disciplines'    => liste de noms de Discipline
        //   'deadline_days'  => int|null (positif = dans N jours, null = pas de deadline)
        //   'location'       => string|null
        //   'description'    => string
        //   'submitter'      => 'admin' | 'structure' (qui a soumis la ressource)
        //   'created_days_ago' => int (combien de jours avant aujourd'hui a été créée la ressource)
        //   'external_url'   => string|null

        $resourcesData = [
            [
                'title'            => 'Appel à projets — Carte Blanche Diaspora',
                'type'             => 'Appel à projets',
                'disciplines'      => ['Musique', 'Arts visuels'],
                'deadline_days'    => 45,
                'location'         => 'Paris',
                'description'      => 'La Fondation Diaspora Arts lance sa 3e édition de la Carte Blanche, '
                    . 'un dispositif de soutien à la création destiné aux artistes de la diaspora '
                    . 'afro-atlantique résidant en France. Les projets sélectionnés bénéficieront d\'une '
                    . 'dotation de 5 000 € et d\'un accompagnement artistique sur 6 mois. '
                    . 'Les candidatures sont ouvertes à tous les artistes émergents et confirmés '
                    . 'travaillant dans les champs de la musique et des arts visuels.',
                'submitter'        => 'admin',
                'created_days_ago' => 5,
                'external_url'     => 'https://fondation-diaspora-arts.fr/carte-blanche-2026',
            ],
            [
                'title'            => 'Résidence de création — Villa Média Dakar',
                'type'             => 'Résidence artistique',
                'disciplines'      => ['Cinéma & Audiovisuel'],
                'deadline_days'    => 60,
                'location'         => 'Dakar, Sénégal',
                'description'      => 'L\'Institut Français du Sénégal propose une résidence de création '
                    . 'cinématographique et audiovisuelle à la Villa Média Dakar pour une durée de 3 mois '
                    . '(septembre à novembre 2026). La résidence accueille deux réalisateurs ou documentaristes '
                    . 'souhaitant développer un projet en lien avec la réalité sénégalaise ou ouest-africaine. '
                    . 'Hébergement, atelier de montage et bourse de 3 000 € inclus. '
                    . 'Ouvert aux ressortissants de la diaspora africaine basés en Europe.',
                'submitter'        => 'admin',
                'created_days_ago' => 12,
                'external_url'     => 'https://institutfrancais-senegal.com/residences',
            ],
            [
                'title'            => 'Bourse Émergence Artistique SAIF 2026',
                'type'             => 'Bourse & Financement',
                'disciplines'      => ['Arts visuels'],
                'deadline_days'    => 30,
                'location'         => 'France',
                'description'      => 'La Société des Auteurs des arts visuels et de l\'Image Fixe (SAIF) '
                    . 'ouvre les candidatures pour sa Bourse Émergence 2026, dotée de 8 000 €. '
                    . 'Elle s\'adresse aux photographes, illustrateurs et plasticiens numériques '
                    . 'en début de carrière (moins de 5 ans de pratique professionnelle). '
                    . 'Le dossier comprend un portfolio de 15 œuvres maximum et une note d\'intention '
                    . 'd\'une page. Aucune restriction de nationalité.',
                'submitter'        => 'admin',
                'created_days_ago' => 8,
                'external_url'     => 'https://www.saif.fr/bourse-emergence-2026',
            ],
            [
                'title'            => 'Formation — Produire et diffuser sa musique en streaming',
                'type'             => 'Formation',
                'disciplines'      => ['Musique'],
                'deadline_days'    => null,
                'location'         => 'En ligne',
                'description'      => 'Formation complète de 20h pour les musiciens indépendants souhaitant '
                    . 'maîtriser l\'écosystème du streaming musical (Spotify, Deezer, Apple Music, YouTube). '
                    . 'Au programme : distribution numérique, stratégie de playlist pitching, analyse '
                    . 'des données d\'écoute, monétisation et droits voisins. '
                    . 'La formation est dispensée en ligne, à votre rythme, avec un accès à vie aux ressources. '
                    . 'Tarif réduit disponible pour les adhérents de structures culturelles partenaires.',
                'submitter'        => 'structure',
                'created_days_ago' => 20,
                'external_url'     => null,
            ],
            [
                'title'            => 'Prix de la Création Francophone — Édition 2026',
                'type'             => 'Prix & Concours',
                'disciplines'      => ['Littérature', 'Théâtre & Performance'],
                'deadline_days'    => 90,
                'location'         => 'Bruxelles, Belgique',
                'description'      => 'Le Centre Wallonie-Bruxelles à Paris organise la 12e édition du '
                    . 'Prix de la Création Francophone, récompensant des œuvres inédites en français '
                    . 'dans les catégories Texte dramatique et Récit littéraire. '
                    . 'Chaque catégorie est dotée de 4 000 € et d\'une publication chez un éditeur partenaire. '
                    . 'La cérémonie de remise des prix se tiendra à Bruxelles en octobre 2026. '
                    . 'Ouvert à tout auteur francophone, quelle que soit sa nationalité.',
                'submitter'        => 'admin',
                'created_days_ago' => 3,
                'external_url'     => 'https://www.cwb.fr/prix-creation-francophone-2026',
            ],
            [
                'title'            => 'Résidence Croisée Afrique-Europe — CNAP',
                'type'             => 'Résidence artistique',
                'disciplines'      => ['Arts visuels', 'Arts numériques'],
                'deadline_days'    => null,
                'location'         => 'France',
                'description'      => 'Le Centre National des Arts Plastiques (CNAP) propose un programme '
                    . 'de résidences croisées entre la France et cinq pays africains (Maroc, Sénégal, '
                    . 'Côte d\'Ivoire, Cameroun, Afrique du Sud). Chaque artiste séjourne 2 mois dans '
                    . 'son pays d\'accueil pour développer une œuvre en dialogue avec les scènes artistiques '
                    . 'locales. La résidence inclut un atelier, un logement et une allocation mensuelle '
                    . 'de 2 500 €. Les dossiers sont examinés en continu par un comité de sélection.',
                'submitter'        => 'admin',
                'created_days_ago' => 25,
                'external_url'     => 'https://www.cnap.fr/residences-croisees',
            ],
            [
                'title'            => 'Appel à films courts — Festival du Cinéma Afro-Européen',
                'type'             => 'Appel à projets',
                'disciplines'      => ['Cinéma & Audiovisuel'],
                'deadline_days'    => 20,
                'location'         => 'Marseille',
                'description'      => 'Le Festival du Cinéma Afro-Européen de Marseille (FCAEM) recherche '
                    . 'des films de court-métrage (5 à 25 minutes) pour sa sélection officielle 2026. '
                    . 'Toutes les formes sont acceptées : fiction, documentaire, animation, expérimental. '
                    . 'Le film doit avoir été réalisé par un réalisateur d\'origine africaine ou '
                    . 'ayant un lien fort avec le continent. Soumission gratuite, en ligne.',
                'submitter'        => 'structure',
                'created_days_ago' => 18,
                'external_url'     => 'https://fcaem.fr/appel-a-films-2026',
            ],
            [
                'title'            => 'Bourse de mobilité artistique — Institut Français',
                'type'             => 'Bourse & Financement',
                'disciplines'      => ['Danse', 'Musique'],
                'deadline_days'    => 75,
                'location'         => 'France',
                'description'      => 'L\'Institut Français propose des bourses de mobilité pour permettre '
                    . 'à des artistes français ou résidant en France de présenter leurs travaux à '
                    . 'l\'international. La bourse couvre les frais de transport, d\'hébergement '
                    . 'et d\'inscription pour une participation à un festival, une résidence ou '
                    . 'une exposition à l\'étranger. Montant : jusqu\'à 3 500 € selon la destination. '
                    . 'Priorité aux artistes de la diaspora souhaitant se reconnecter à leurs origines.',
                'submitter'        => 'admin',
                'created_days_ago' => 10,
                'external_url'     => 'https://www.institutfrancais.com/bourses-mobilite',
            ],
            [
                'title'            => 'Formation — Droits d\'auteur et propriété intellectuelle pour artistes',
                'type'             => 'Formation',
                'disciplines'      => [
                    'Musique', 'Arts visuels', 'Littérature',
                    'Cinéma & Audiovisuel', 'Arts numériques',
                ],
                'deadline_days'    => null,
                'location'         => 'En ligne',
                'description'      => 'Formation juridique spécialisée destinée aux artistes et créateurs '
                    . 'souhaitant comprendre et protéger leurs droits. Dispensée par des avocats '
                    . 'spécialisés en droit de la propriété intellectuelle, elle aborde : '
                    . 'le droit d\'auteur et ses exceptions, la cession et la licence de droits, '
                    . 'la SACEM, l\'ADAMI, la SPEDIDAM et les autres sociétés de gestion collective, '
                    . 'ainsi que les enjeux spécifiques à l\'ère numérique (streaming, NFT, IA générative). '
                    . 'Attestation de suivi délivrée à l\'issue de la formation.',
                'submitter'        => 'structure',
                'created_days_ago' => 30,
                'external_url'     => null,
            ],
            [
                'title'            => 'Appel à résidences — Friche la Belle de Mai',
                'type'             => 'Résidence artistique',
                'disciplines'      => ['Mode & Design', 'Arts visuels'],
                'deadline_days'    => null,
                'location'         => 'Marseille',
                'description'      => 'La Friche la Belle de Mai à Marseille ouvre ses ateliers à des '
                    . 'artistes et designers souhaitant développer un projet à la croisée des arts '
                    . 'visuels et de la mode. Les candidats retenus disposeront d\'un atelier équipé '
                    . 'pour une période de 3 à 6 mois, avec accès à l\'écosystème créatif de la Friche '
                    . '(expositions, concerts, rencontres professionnelles). '
                    . 'Pas de bourse attachée, mais hébergement possible via partenaire. '
                    . 'Candidatures examinées deux fois par an.',
                'submitter'        => 'admin',
                'created_days_ago' => 22,
                'external_url'     => 'https://lafriche.org/residences',
            ],
            [
                'title'            => 'Prix Révélations Afrique de l\'Ouest — MASA 2026',
                'type'             => 'Prix & Concours',
                'disciplines'      => ['Musique', 'Danse', 'Théâtre & Performance'],
                'deadline_days'    => 50,
                'location'         => 'Abidjan, Côte d\'Ivoire',
                'description'      => 'Le Marché des Arts du Spectacle d\'Abidjan (MASA) lance le Prix '
                    . 'Révélations Afrique de l\'Ouest 2026, destiné aux artistes scéniques de moins de '
                    . '35 ans originaires de la sous-région ouest-africaine. Les lauréats (un par catégorie : '
                    . 'musique, danse, théâtre) seront sélectionnés pour se produire sur la scène principale '
                    . 'du MASA en mars 2026, avec une couverture médiatique internationale. '
                    . 'Dotation : 2 500 € + prise en charge totale du séjour à Abidjan.',
                'submitter'        => 'admin',
                'created_days_ago' => 7,
                'external_url'     => 'https://www.masa.ci/prix-revelations-2026',
            ],
            [
                'title'            => 'Atelier — Écriture et mise en scène contemporaine',
                'type'             => 'Formation',
                'disciplines'      => ['Théâtre & Performance', 'Littérature'],
                'deadline_days'    => null,
                'location'         => 'Paris',
                'description'      => 'Atelier intensif de 5 jours (lundi au vendredi, 10h–18h) animé '
                    . 'par deux artistes de la scène contemporaine francophone. Au programme : '
                    . 'écriture dramaturgique à partir de matériaux autobiographiques, mise en jeu '
                    . 'du texte, exploration des formes hybrides (performance, témoignage, fiction). '
                    . 'L\'atelier accueille 12 participants maximum, tous niveaux. '
                    . 'Il se déroule au Théâtre du Soleil, Cartoucherie de Vincennes. '
                    . 'Frais d\'inscription : 250 € (tarif solidaire disponible sur demande).',
                'submitter'        => 'structure',
                'created_days_ago' => 15,
                'external_url'     => 'https://theatre-du-soleil.fr/ateliers',
            ],
        ];

        // On utilise la réflexion PHP pour forcer createdAt et updatedAt sur Resource,
        // car ces propriétés sont privées et n'ont pas de setter public
        // (elles sont gérées par PrePersist/PreUpdate lifecycle callbacks).
        // C'est la technique recommandée dans les fixtures Doctrine pour historiser les données.
        $resourceClass    = new \ReflectionClass(Resource::class);
        $propCreatedAt    = $resourceClass->getProperty('createdAt');
        $propUpdatedAt    = $resourceClass->getProperty('updatedAt');

        // On rend les propriétés accessibles depuis l'extérieur de la classe
        $propCreatedAt->setAccessible(true);
        $propUpdatedAt->setAccessible(true);

        foreach ($resourcesData as $data) {
            $resource = new Resource();

            // ── Champs de base ────────────────────────────────────────────────
            $resource
                ->setTitle($data['title'])
                ->setDescription($data['description'])
                ->setExternalUrl($data['external_url'])
                ->setLocation($data['location'])
                ->setResourceType($resourceTypes[$data['type']]);

            // ── Deadline ─────────────────────────────────────────────────────
            if ($data['deadline_days'] !== null) {
                // On calcule la date limite en ajoutant N jours à aujourd'hui
                $deadline = new \DateTime('+' . $data['deadline_days'] . ' days');
                $resource->setDeadline($deadline);
            }

            // ── Disciplines ───────────────────────────────────────────────────
            // On n'attache que les 5 premières disciplines pour les formations "toutes disciplines"
            // (la ressource n°9 a 5 disciplines, on les ajoute toutes)
            foreach ($data['disciplines'] as $disciplineName) {
                $resource->addDiscipline($disciplines[$disciplineName]);
            }

            // ── Statut et dates de publication ───────────────────────────────
            $createdAt = new \DateTime('-' . $data['created_days_ago'] . ' days');
            $resource
                ->setStatus(ResourceStatus::Published)
                ->setPublishedAt($createdAt);

            // ── Soumetteur, rôle et autoPublished ────────────────────────────
            if ($data['submitter'] === 'structure') {
                // Ressource soumise par une structure partenaire.
                // Règle métier : les structures publient en auto-publication directe
                // (autoPublished = true, validatedAt/validatedBy restent null).
                $resource
                    ->setSubmittedBy($structureUser)
                    ->setSubmitterRole(SubmitterRole::Structure)
                    ->setOrganization($structureOrg)
                    ->setAutoPublished(true);
                // Pas de validatedAt/validatedBy : la structure n'a pas besoin
                // d'une validation admin — la publication est automatique.
            } else {
                // Ressource soumise et publiée manuellement par l'admin.
                // autoPublished = false : l'admin a explicitement cliqué "Publier".
                // validatedAt et validatedBy tracent qui a validé et quand.
                $resource
                    ->setSubmittedBy($adminUser)
                    ->setSubmitterRole(SubmitterRole::Admin)
                    ->setOrganization(null)
                    ->setAutoPublished(false)
                    ->setValidatedAt($createdAt)
                    ->setValidatedBy($adminUser);
            }

            // ── Forçage des timestamps privés via réflexion ───────────────────
            // PrePersist initialise createdAt/updatedAt à NOW() automatiquement.
            // On écrase ensuite ces valeurs pour avoir des dates historiques réalistes.
            // Sans cela, toutes les ressources auraient la même date de création (maintenant).
            $propCreatedAt->setValue($resource, $createdAt);
            $propUpdatedAt->setValue($resource, $createdAt);

            $manager->persist($resource);
        }
    }

    // =========================================================================
    // Création des 3 articles publiés
    // =========================================================================

    /**
     * Crée 3 articles de blog publiés sur la plateforme.
     *
     * Article a les mêmes contraintes que Resource pour les timestamps :
     * PrePersist gère createdAt/updatedAt. On utilise là aussi la réflexion.
     */
    private function createArticles(ObjectManager $manager, User $adminUser, User $artistUser): void
    {
        // Réflexion pour forcer les timestamps sur Article
        $articleClass  = new \ReflectionClass(Article::class);
        $propCreatedAt = $articleClass->getProperty('createdAt');
        $propUpdatedAt = $articleClass->getProperty('updatedAt');
        $propCreatedAt->setAccessible(true);
        $propUpdatedAt->setAccessible(true);

        // ── Article 1 ─────────────────────────────────────────────────────────
        $article1 = new Article();
        $article1
            ->setTitle('5 conseils pour répondre à un appel à projets artistiques')
            ->setSlug('5-conseils-pour-repondre-a-un-appel-a-projets-artistiques')
            ->setExcerpt(
                'Les appels à projets sont une source majeure de financement pour les artistes indépendants. '
                . 'Mais comment rédiger un dossier qui se démarque ? Voici 5 conseils issus de notre '
                . 'expérience d\'accompagnement d\'artistes de la diaspora.'
            )
            ->setContent(
                "Chaque année, des centaines d'appels à projets sont lancés par des fondations, "
                . "des institutions culturelles et des collectivités. Pourtant, beaucoup d'artistes "
                . "renoncent à y répondre, découragés par la complexité des dossiers ou la peur du refus. "
                . "Voici 5 conseils pour maximiser vos chances.\n\n"
                . "1. Lire le cahier des charges en entier\n"
                . "Cela peut sembler évident, mais de nombreuses candidatures sont éliminées dès le premier "
                . "tri parce qu'elles ne respectent pas les critères d'éligibilité. Avant de rédiger quoi "
                . "que ce soit, lisez le règlement de A à Z et vérifiez que votre projet correspond bien "
                . "au périmètre attendu.\n\n"
                . "2. Personnaliser votre note d'intention\n"
                . "Évitez le copier-coller de votre biographie ou de votre dossier artistique générique. "
                . "La note d'intention doit montrer que vous avez compris les objectifs spécifiques de "
                . "l'appel et que votre projet y répond directement. Montrez le lien entre votre démarche "
                . "artistique et les valeurs de l'organisme sélectionneur.\n\n"
                . "3. Soigner la présentation formelle\n"
                . "Un dossier bien structuré, avec une pagination claire, des titres explicites et des "
                . "images de qualité, inspire confiance. Les jurys reçoivent souvent des dizaines de "
                . "candidatures — un dossier lisible et esthétique facilite la lecture et valorise votre "
                . "travail.\n\n"
                . "4. Respecter les délais et formats demandés\n"
                . "Envoyez votre dossier plusieurs jours avant la deadline pour éviter les problèmes "
                . "techniques de dernière minute. Si des formats spécifiques sont demandés (PDF, format "
                . "A4, images en JPEG sous 2 Mo), respectez-les à la lettre.\n\n"
                . "5. Ne pas vous décourager après un refus\n"
                . "Le taux de sélection des appels à projets est souvent inférieur à 10 %. Un refus ne "
                . "signifie pas que votre projet est mauvais — il peut simplement ne pas correspondre "
                . "à la sensibilité du jury de cette édition. Demandez un retour quand c'est possible, "
                . "améliorez votre dossier et repostulez."
            )
            ->setAuthor($adminUser)
            ->setStatus(ArticleStatus::Published)
            ->setPublishedAt(new \DateTime('-20 days'));

        // Forçage des timestamps historiques
        $createdAt1 = new \DateTime('-21 days');
        $propCreatedAt->setValue($article1, $createdAt1);
        $propUpdatedAt->setValue($article1, new \DateTime('-20 days'));

        $manager->persist($article1);

        // ── Article 2 ─────────────────────────────────────────────────────────
        $article2 = new Article();
        $article2
            ->setTitle('La diaspora afro-atlantique et les nouvelles formes de création numérique')
            ->setSlug('diaspora-afro-atlantique-nouvelles-formes-creation-numerique')
            ->setExcerpt(
                'Entre la NFT art, les performances en ligne et les installations immersives, '
                . 'les artistes de la diaspora s\'emparent du numérique pour raconter de nouvelles histoires '
                . 'et toucher des publics globaux. Rencontres et réflexions.'
            )
            ->setContent(
                "Le numérique a profondément transformé les pratiques artistiques au cours de la dernière "
                . "décennie. Pour les artistes de la diaspora afro-atlantique, ces nouveaux outils "
                . "représentent à la fois une opportunité sans précédent de visibilité mondiale et un "
                . "terrain de questionnement identitaire fertile.\n\n"
                . "Des créateurs comme Misan Harriman, Amoako Boafo ou Zanele Muholi ont su exploiter "
                . "les plateformes numériques pour diffuser leur travail au-delà des circuits traditionnels "
                . "des galeries et des musées. Instagram, TikTok et les plateformes de NFT ont permis à "
                . "des artistes émergents de constituer des communautés engagées à l'échelle internationale.\n\n"
                . "Mais cette présence numérique soulève aussi des questions essentielles : comment préserver "
                . "l'authenticité d'une pratique artistique dans un environnement algorithmique qui favorise "
                . "la viralité ? Comment se réapproprier des outils conçus par et pour les industries "
                . "culturelles dominantes ? Et comment faire valoir ses droits dans un écosystème où la "
                . "reproduction est instantanée et gratuite ?\n\n"
                . "Ces tensions sont au cœur des discussions au sein de la communauté Bazaart. "
                . "Nous vous invitons à partager vos expériences et vos réflexions dans le forum."
            )
            ->setAuthor($artistUser)
            ->setStatus(ArticleStatus::Published)
            ->setPublishedAt(new \DateTime('-10 days'));

        $createdAt2 = new \DateTime('-12 days');
        $propCreatedAt->setValue($article2, $createdAt2);
        $propUpdatedAt->setValue($article2, new \DateTime('-10 days'));

        $manager->persist($article2);

        // ── Article 3 ─────────────────────────────────────────────────────────
        $article3 = new Article();
        $article3
            ->setTitle('Comment financer sa résidence artistique à l\'étranger ?')
            ->setSlug('comment-financer-sa-residence-artistique-a-letranger')
            ->setExcerpt(
                'Une résidence à l\'étranger peut transformer une pratique artistique. '
                . 'Mais entre les billets d\'avion, le logement et les frais de vie, le budget peut vite '
                . 's\'envoler. Voici les principaux dispositifs de financement disponibles en 2026.'
            )
            ->setContent(
                "Partir en résidence artistique à l'étranger est une expérience fondatrice pour de nombreux "
                . "artistes. Mais face aux coûts que cela implique, beaucoup renoncent à l'idée avant même "
                . "d'avoir cherché des solutions de financement. Or, il existe en France et en Europe "
                . "plusieurs dispositifs méconnus qui peuvent couvrir tout ou partie de ces dépenses.\n\n"
                . "Les bourses de mobilité de l'Institut Français\n"
                . "L'Institut Français propose chaque année des bourses de mobilité permettant aux artistes "
                . "français ou résidant en France de se rendre dans plus de 100 pays. Ces bourses couvrent "
                . "généralement les frais de transport et d'hébergement pour une durée de 1 à 3 mois. "
                . "Les dossiers sont déposés auprès des instituts français locaux dans les pays de destination.\n\n"
                . "Les aides régionales et départementales\n"
                . "De nombreuses régions françaises (Île-de-France, Occitanie, Grand Est, etc.) disposent "
                . "de fonds dédiés à la mobilité artistique internationale. Ces aides sont souvent peu "
                . "connues mais accessibles : renseignez-vous auprès de votre Direction Régionale des "
                . "Affaires Culturelles (DRAC) ou de votre conseil régional.\n\n"
                . "Les fondations privées\n"
                . "Des fondations comme la Fondation de France, la Fondation FACE ou encore les fondations "
                . "d'entreprises (LVMH, Total Énergies Culture) soutiennent des projets de mobilité "
                . "artistique à l'international. Leurs appels à projets sont annuels — pensez à vous "
                . "y abonner dès maintenant pour la session 2027.\n\n"
                . "Bazaart référence en continu ces opportunités dans sa Ressourcerie. Consultez la section "
                . "'Bourse & Financement' pour trouver les aides actuellement ouvertes aux candidatures."
            )
            ->setAuthor($adminUser)
            ->setStatus(ArticleStatus::Published)
            ->setPublishedAt(new \DateTime('-3 days'));

        $createdAt3 = new \DateTime('-5 days');
        $propCreatedAt->setValue($article3, $createdAt3);
        $propUpdatedAt->setValue($article3, new \DateTime('-3 days'));

        $manager->persist($article3);
    }

    // =========================================================================
    // Création des formations (module Formation — CDC V3 §5.7)
    // =========================================================================

    /**
     * Crée les formations de démonstration pour prévisualiser le module Formation.
     *
     * Contenu créé :
     *   - Formation 1 "introduction-afrobeats-rythme-composition"       → PUBLIÉE, gratuite, CONTENU
     *   - Formation 2 "cultural-engineering-projets-diaspora"           → PUBLIÉE, payante, CONTENU
     *   - Formation 3 "creation-sonore-numerique-brouillon"             → BROUILLON, CONTENU
     *   - Événement 4  "masterclass-composition-afrobeats-en-ligne"     → PUBLIÉE, payante, EVENEMENT VISIO
     *   - Événement 5  "atelier-scenique-presence-scene-paris"          → PUBLIÉE, gratuite, EVENEMENT PRESENTIEL
     *   - Inscription de artiste@bazaart.fr à la Formation 1
     *   - LessonProgress sur la première leçon de la Formation 1
     *   - Inscription de artiste@bazaart.fr à l'Événement PRESENTIEL gratuit (dashboard Phase 2)
     *
     * Pourquoi utiliser addModule() / addLesson() plutôt que setModule() direct ?
     * Ces méthodes synchronisent les DEUX côtés de la relation (owning side +
     * inverse side) en une seule opération. Si on appelait seulement
     * $module->setCourse($course), la collection $course->modules ne serait pas
     * mise à jour en mémoire — ce qui causerait des bugs lors des itérations
     * Twig ou des recalculs en PHP (même si la BDD resterait correcte grâce
     * aux FK).
     *
     * Pourquoi pas de flush() intermédiaire ici ?
     * Course a cascade: ['persist'] sur ses modules, et CourseModule a
     * cascade: ['persist'] sur ses leçons. Un seul $manager->persist($course)
     * suffit pour propager la persistance à toute la hiérarchie.
     * Le flush() global dans load() finalisera tout en une seule transaction.
     */
    private function loadCourses(ObjectManager $manager, User $artistUser): void
    {
        // ── Formation 1 : Introduction à l'Afrobeats (PUBLIÉE, gratuite) ──────
        //
        // Cette formation sert à prévisualiser le catalogue public (/formations),
        // la page de vente (/formations/{slug}), et le parcours apprenant
        // (/formations/{slug}/learn) car c'est elle qui reçoit l'inscription.

        $courseAfrobeats = new Course();
        $courseAfrobeats
            ->setSlug('introduction-afrobeats-rythme-composition')
            ->setTitle('Introduction à l\'Afrobeats : rythme et composition')
            ->setSubtitle('Apprenez les bases des percussions et de la production afrobeats en 4 semaines')
            ->setDescription(
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
            // Pas d'image de couverture en fixtures : null est accepté (nullable: true)
            ->setCoverImage(null)
            // URL de teaser : on utilise un embed YouTube public pour les fixtures
            // (pas de Bunny Stream configuré en local)
            ->setTrailerVideoUrl('https://www.youtube.com/embed/example-afrobeats-teaser')
            ->setInstructorName('Kofi Mensah')
            ->setInstructorBio(
                'Producteur et percussionniste ghanéen basé à Paris depuis 2014. '
                . 'Kofi a collaboré avec plus de 40 artistes de la scène afrobeats européenne. '
                . 'Formateur régulier aux ateliers du Pôle Studio Bazaart.'
            )
            ->setInstructorAvatar(null)
            // Durée totale : 4 modules × 3 leçons × ~15 min = ~180 min
            // (valeur calculée manuellement ici pour les fixtures, sinon
            //  CourseService::recalculateDuration() le ferait automatiquement)
            ->setDurationMinutesTotal(185)
            ->setLevel(CourseLevel::BEGINNER)
            // Formation gratuite en V1 : priceInCents = null → getFormattedPrice() retourne "Gratuit"
            ->setPriceInCents(null)
            ->setIsPublished(true)
            // publishedAt = date de la première publication (simulée il y a 10 jours)
            ->setPublishedAt(new \DateTime('-10 days'));

        // ── Module 1 : Histoire et origines de l'afrobeats ──────────────────
        $moduleAfro1 = new CourseModule();
        $moduleAfro1
            ->setTitle('Histoire et origines de l\'afrobeats')
            ->setDescription(
                'Comprendre les racines culturelles pour mieux appréhender le son : '
                . 'du highlife nigérian des années 60 à Fela Kuti, jusqu\'à l\'explosion mondiale.'
            )
            ->setOrderPosition(0);

        // Leçon 1.1 : Disponible en aperçu gratuit (isFreePreview = true)
        // → permet aux visiteurs non inscrits de voir un extrait du contenu
        $lessonAfro1_1 = new Lesson();
        $lessonAfro1_1
            ->setTitle('Des racines africaines au Lagos Sound')
            ->setDescription(
                'Panorama historique : highlife, jùjú music, afrobeat de Fela Kuti '
                . '— comment ces genres ont posé les bases de l\'afrobeats moderne.'
            )
            // videoBunnyId null car pas de compte Bunny Stream configuré en local.
            // En production, ce serait l'UUID de la vidéo uploadée sur Bunny Stream.
            ->setVideoBunnyId(null)
            // En attendant Bunny Stream, on utilise une URL YouTube embed
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-1-1')
            ->setDurationSeconds(780)   // 13 minutes
            ->setOrderPosition(0)
            // isFreePreview = true → accessible sans inscription (teaser)
            ->setIsFreePreview(true);

        // Leçon 1.2 : Réservée aux inscrits
        $lessonAfro1_2 = new Lesson();
        $lessonAfro1_2
            ->setTitle('Fela Kuti et la naissance de l\'afrobeat')
            ->setDescription(
                'L\'héritage de Fela Anikulapo Kuti : comment son afrobeat "avec un e" '
                . 'diffère de l\'afrobeats "avec un s" contemporain.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-1-2')
            ->setDurationSeconds(960)   // 16 minutes
            ->setOrderPosition(1)
            ->setIsFreePreview(false);

        // Leçon 1.3 : Réservée aux inscrits
        $lessonAfro1_3 = new Lesson();
        $lessonAfro1_3
            ->setTitle('L\'explosion mondiale : de Lagos à Londres')
            ->setDescription(
                'Comment Wizkid, Davido et Burna Boy ont exporté le son nigérian '
                . 'sur les charts européens et américains dans les années 2010–2020.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-1-3')
            ->setDurationSeconds(840)   // 14 minutes
            ->setOrderPosition(2)
            ->setIsFreePreview(false);

        // On attache les leçons au module via addLesson() qui synchronise les deux côtés
        $moduleAfro1->addLesson($lessonAfro1_1);
        $moduleAfro1->addLesson($lessonAfro1_2);
        $moduleAfro1->addLesson($lessonAfro1_3);

        // ── Module 2 : Rythme et percussions ────────────────────────────────
        $moduleAfro2 = new CourseModule();
        $moduleAfro2
            ->setTitle('Rythme et percussions afrobeats')
            ->setDescription(
                'Décryptage des patterns rythmiques qui caractérisent le son afrobeats : '
                . 'du 4/4 swingué aux polyrhythmies, en passant par les instruments traditionnels.'
            )
            ->setOrderPosition(1);

        $lessonAfro2_1 = new Lesson();
        $lessonAfro2_1
            ->setTitle('Le pattern de base : 4/4 et swung 8ths')
            ->setDescription(
                'Comprendre pourquoi l\'afrobeats "groove" : analyse du swing, '
                . 'de la syncope et du décalage rythmique par rapport à la house ou au hip-hop.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-2-1')
            ->setDurationSeconds(1020)  // 17 minutes
            ->setOrderPosition(0)
            ->setIsFreePreview(false);

        $lessonAfro2_2 = new Lesson();
        $lessonAfro2_2
            ->setTitle('Shekere, talking drum et congas : les instruments clés')
            ->setDescription(
                'Introduction pratique aux instruments de percussion traditionnels '
                . 'et à leur rôle dans une production afrobeats moderne.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-2-2')
            ->setDurationSeconds(1200)  // 20 minutes
            ->setOrderPosition(1)
            ->setIsFreePreview(false);

        $lessonAfro2_3 = new Lesson();
        $lessonAfro2_3
            ->setTitle('Polyrhythmie : superposer plusieurs rythmes')
            ->setDescription(
                'Exercice pratique : construire une grille rythmique à plusieurs couches '
                . 'en combinant kick, snare, hi-hat et percussions africaines.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-lecon-2-3')
            ->setDurationSeconds(1080)  // 18 minutes
            ->setOrderPosition(2)
            ->setIsFreePreview(false);

        $moduleAfro2->addLesson($lessonAfro2_1);
        $moduleAfro2->addLesson($lessonAfro2_2);
        $moduleAfro2->addLesson($lessonAfro2_3);

        // On attache les modules à la formation via addModule() qui synchronise
        // la relation Course ↔ CourseModule (les deux côtés restent cohérents en mémoire)
        $courseAfrobeats->addModule($moduleAfro1);
        $courseAfrobeats->addModule($moduleAfro2);

        // persist() sur la Course suffit : cascade: ['persist'] sur modules et leçons
        // propagera automatiquement la persistance à toute la hiérarchie
        $manager->persist($courseAfrobeats);

        // ── Formation 2 : Cultural Engineering (PUBLIÉE, payante) ────────────
        //
        // Cette formation représente une offre premium du Pôle Lab Bazaart.
        // Elle est publiée mais payante (priceInCents = 4900 → 49,00€).
        // En V1 Stripe n'est pas encore intégré, donc le paiement n'est pas
        // fonctionnel — mais les fixtures testent que le champ est bien stocké.

        $courseCE = new Course();
        $courseCE
            ->setSlug('cultural-engineering-projets-diaspora')
            ->setTitle('Cultural Engineering : monter et piloter un projet culturel diaspora')
            ->setSubtitle('De l\'idée au financement : méthodes et outils du Pôle Lab Bazaart')
            ->setDescription(
                "Le cultural engineering est la discipline qui permet de concevoir, financer "
                . "et déployer des projets culturels de manière structurée et durable. "
                . "Cette formation s\'appuie sur l\'expérience concrète du Pôle Lab Bazaart "
                . "et de ses partenaires pour vous donner les outils pratiques du secteur.\n\n"
                . "Contenu : identification des porteurs de projets et partenaires institutionnels, "
                . "modèles économiques hybrides (subventions + autofinancement + mécénat), "
                . "rédaction de dossiers de subvention convaincants, gestion de projet culturel "
                . "(planning, budget, équipe), et stratégies de communication pour les artistes "
                . "et collectifs de la diaspora afro-atlantique.\n\n"
                . "Cette formation est conçue pour les artistes souhaitant professionnaliser "
                . "leur démarche, les responsables de structures culturelles en développement, "
                . "et toute personne impliquée dans l\'accompagnement de projets artistiques."
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
            ->setDurationMinutesTotal(240)
            ->setLevel(CourseLevel::INTERMEDIATE)
            // Formation payante : 49,00€ (4900 centimes)
            // Stripe n'est pas intégré en V1 — le champ est stocké pour la V2
            ->setPriceInCents(4900)
            ->setIsPublished(true)
            ->setPublishedAt(new \DateTime('-5 days'));

        // ── Module CE-1 : Comprendre l'écosystème culturel ──────────────────
        $moduleCE1 = new CourseModule();
        $moduleCE1
            ->setTitle('L\'écosystème culturel en France et en Europe')
            ->setDescription(
                'Cartographie des acteurs : ministères, DRAC, collectivités, fondations, '
                . 'opérateurs culturels — qui finance quoi, comment et pourquoi.'
            )
            ->setOrderPosition(0);

        $lessonCE1_1 = new Lesson();
        $lessonCE1_1
            ->setTitle('Panorama du financement public de la culture')
            ->setDescription(
                'MCC, DRAC, CNM, CNC, CNAP : les grands opérateurs publics et '
                . 'leurs dispositifs de soutien aux artistes et aux structures.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-1-1')
            ->setDurationSeconds(1320)  // 22 minutes
            ->setOrderPosition(0)
            // Leçon de présentation accessible gratuitement pour attirer les inscrits
            ->setIsFreePreview(true);

        $lessonCE1_2 = new Lesson();
        $lessonCE1_2
            ->setTitle('Mécénat privé et fondations : trouver les bons interlocuteurs')
            ->setDescription(
                'Fondation de France, fondations d\'entreprises, crowdfunding culturel : '
                . 'identifier et approcher les financeurs privés adaptés à votre projet.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-1-2')
            ->setDurationSeconds(1140)  // 19 minutes
            ->setOrderPosition(1)
            ->setIsFreePreview(false);

        $moduleCE1->addLesson($lessonCE1_1);
        $moduleCE1->addLesson($lessonCE1_2);

        // ── Module CE-2 : Rédiger un dossier de subvention ──────────────────
        $moduleCE2 = new CourseModule();
        $moduleCE2
            ->setTitle('Rédiger un dossier de subvention convaincant')
            ->setDescription(
                'Méthode pas à pas pour construire un dossier de demande de subvention '
                . 'qui répond exactement aux attentes des commissions de sélection.'
            )
            ->setOrderPosition(1);

        $lessonCE2_1 = new Lesson();
        $lessonCE2_1
            ->setTitle('Anatomie d\'un bon dossier : les éléments incontournables')
            ->setDescription(
                'Note d\'intention, budget prévisionnel, CV artistique, portfolio : '
                . 'analyse de dossiers réels (anonymisés) acceptés et refusés.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-2-1')
            ->setDurationSeconds(1500)  // 25 minutes
            ->setOrderPosition(0)
            ->setIsFreePreview(false);

        $lessonCE2_2 = new Lesson();
        $lessonCE2_2
            ->setTitle('Formuler un budget prévisionnel réaliste')
            ->setDescription(
                'Comment estimer les coûts d\'un projet culturel, anticiper les imprévus '
                . 'et présenter un budget crédible aux financeurs institutionnels.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-2-2')
            ->setDurationSeconds(1380)  // 23 minutes
            ->setOrderPosition(1)
            ->setIsFreePreview(false);

        $lessonCE2_3 = new Lesson();
        $lessonCE2_3
            ->setTitle('Adapter son dossier à chaque interlocuteur')
            ->setDescription(
                'Un même projet, plusieurs dossiers : personnaliser le discours selon '
                . 'que l\'on s\'adresse à une DRAC, une fondation privée ou un mécène d\'entreprise.'
            )
            ->setVideoBunnyId(null)
            ->setVideoUrl('https://www.youtube.com/embed/placeholder-ce-2-3')
            ->setDurationSeconds(960)   // 16 minutes
            ->setOrderPosition(2)
            ->setIsFreePreview(false);

        $moduleCE2->addLesson($lessonCE2_1);
        $moduleCE2->addLesson($lessonCE2_2);
        $moduleCE2->addLesson($lessonCE2_3);

        $courseCE->addModule($moduleCE1);
        $courseCE->addModule($moduleCE2);

        $manager->persist($courseCE);

        // ── Formation 3 : Création sonore numérique (BROUILLON) ───────────────
        //
        // Formation en cours de construction, isPublished = false.
        // Elle doit :
        //   - NE PAS apparaître dans le catalogue public (/formations)
        //   - APPARAÎTRE dans le back-office admin (/admin/formations)
        //
        // Contenu intentionnellement incomplet (1 module, 1 leçon) pour simuler
        // une formation "en cours de rédaction" réaliste.

        $courseDraft = new Course();
        $courseDraft
            ->setSlug('creation-sonore-numerique-brouillon')
            ->setTitle('Création sonore numérique pour artistes visuels')
            ->setSubtitle('Initiation à l\'ambiance sonore, au sound design et au montage audio pour vos œuvres')
            ->setDescription(
                "Vous êtes plasticien, vidéaste ou installateur et vous souhaitez intégrer "
                . "une dimension sonore à vos œuvres sans avoir de formation musicale ? "
                . "Cette formation vous donne les outils pratiques pour composer des ambiances "
                . "sonores, enregistrer des sons du quotidien et les assembler dans un logiciel "
                . "de montage audio accessible (Audacity, Reaper).\n\n"
                . "[Formation en cours de préparation — contenu à compléter avant publication]"
            )
            ->setCoverImage(null)
            ->setTrailerVideoUrl(null)
            ->setInstructorName('À définir')
            ->setInstructorBio(null)
            ->setInstructorAvatar(null)
            ->setDurationMinutesTotal(null)  // Durée inconnue, formation incomplète
            ->setLevel(CourseLevel::BEGINNER)
            ->setPriceInCents(null)
            // isPublished = false → brouillon, invisible dans le catalogue public
            ->setIsPublished(false)
            // publishedAt = null → jamais publiée
            ->setPublishedAt(null);

        // Module unique (brouillon incomplet)
        $moduleDraft1 = new CourseModule();
        $moduleDraft1
            ->setTitle('Introduction : qu\'est-ce que le sound design ?')
            ->setDescription('Module introductif — contenu à compléter.')
            ->setOrderPosition(0);

        // Leçon unique (titre provisoire)
        $lessonDraft1_1 = new Lesson();
        $lessonDraft1_1
            ->setTitle('[À compléter] Le son dans l\'art contemporain')
            ->setDescription('Leçon en cours de rédaction.')
            ->setVideoBunnyId(null)
            ->setVideoUrl(null)          // Pas encore de vidéo associée
            ->setDurationSeconds(null)   // Durée inconnue
            ->setOrderPosition(0)
            ->setIsFreePreview(false);

        $moduleDraft1->addLesson($lessonDraft1_1);
        $courseDraft->addModule($moduleDraft1);

        $manager->persist($courseDraft);

        // ─────────────────────────────────────────────────────────────────────
        // ── Événement 1 : Masterclass Composition Afrobeats (VISIO, payant) ──
        //
        // Démontre le nouveau type EVENEMENT avec mode VISIO.
        // Publié, payant (3900 centimes = 39,00€), capacité limitée à 30 places.
        // Date dans le futur pour qu'il apparaisse comme "à venir".
        // Slug : "masterclass-composition-afrobeats-en-ligne"

        $eventVisio = new Course();
        $eventVisio
            ->setSlug('masterclass-composition-afrobeats-en-ligne')
            ->setTitle('Masterclass : Composer un titre Afrobeats de A à Z')
            ->setSubtitle('Session live en ligne — Zoom — avec Kofi Mensah')
            ->setDescription(
                "Rejoignez Kofi Mensah pour une masterclass intensive en ligne. "
                . "En 3 heures, vous apprendrez à construire un titre afrobeats complet "
                . "depuis le beat initial jusqu'au mixage final.\n\n"
                . "Au programme :\n"
                . "— Construction d'un beat afrobeats (pattern, percussions, bass)\n"
                . "— Arrangement des parties (intro, couplet, refrain, outro)\n"
                . "— Mixage basique sur Ableton Live / FL Studio\n"
                . "— Session Q&A de 45 minutes\n\n"
                . "Prérequis : avoir suivi \"Introduction à l'Afrobeats\" ou équivalent. "
                . "Un DAW (Ableton ou FL Studio) installé sur votre ordinateur est recommandé."
            )
            ->setCoverImage(null)
            ->setTrailerVideoUrl(null)
            ->setInstructorName('Kofi Mensah')
            ->setInstructorBio(
                'Producteur et percussionniste ghanéen basé à Paris depuis 2014. '
                . 'Kofi a collaboré avec plus de 40 artistes de la scène afrobeats européenne.'
            )
            ->setInstructorAvatar(null)
            ->setLevel(CourseLevel::INTERMEDIATE)
            // Payant : 39,00€ (3900 centimes). Stripe non intégré en V1 — champ stocké pour V2.
            ->setPriceInCents(3900)
            // ── Champs spécifiques EVENEMENT ──────────────────────────────────
            ->setType(CourseType::EVENEMENT)
            ->setEventMode(CourseEventMode::VISIO)
            // Date dans le futur : +30 jours depuis le chargement des fixtures
            ->setEventStartAt(new \DateTime('+30 days 14:00:00'))
            ->setEventEndAt(new \DateTime('+30 days 17:00:00'))
            ->setEventLocation(null)  // Pas d'adresse physique pour une visio
            ->setEventExternalUrl('https://us02web.zoom.us/j/85012345678?pwd=EXAMPLE_FIXTURES_URL')
            ->setCapacity(30)    // 30 places max — réaliste pour une masterclass interactive
            ->setIsPublished(true)
            ->setPublishedAt(new \DateTime('-3 days'));

        $manager->persist($eventVisio);

        // ─────────────────────────────────────────────────────────────────────
        // ── Événement 2 : Atelier scénique présentiel (PRESENTIEL, gratuit) ──
        //
        // Démontre le type EVENEMENT avec mode PRESENTIEL.
        // Gratuit (priceInCents = null), capacité 20 places.
        // Organisé à Paris dans 45 jours.
        // Slug : "atelier-scenique-presence-scene-paris"

        $eventPresentiel = new Course();
        $eventPresentiel
            ->setSlug('atelier-scenique-presence-scene-paris')
            ->setTitle('Atelier : Présence scénique et performance live pour artistes diaspora')
            ->setSubtitle('Atelier pratique en présentiel — Paris 11e — gratuit')
            ->setDescription(
                "Cet atelier pratique explore les techniques de présence scénique "
                . "adaptées aux artistes de la diaspora afro-atlantique. "
                . "Dirigé par la coach scénique Nadia Kouakou, il combine exercices "
                . "de théâtre physique, improvisation musicale et analyse de performances "
                . "de référence (Burna Boy, Yemi Alade, Angélique Kidjo).\n\n"
                . "Format : atelier en demi-journée (9h–13h), groupe de 20 participants max.\n\n"
                . "Ce que vous repartirez avec :\n"
                . "— Techniques de placement scénique et gestion du trac\n"
                . "— Exercices de connexion avec le public\n"
                . "— Retour individualisé sur votre présence\n\n"
                . "Gratuit, ouvert aux membres et non-membres Bazaart.\n"
                . "Inscription obligatoire (places limitées)."
            )
            ->setCoverImage(null)
            ->setTrailerVideoUrl(null)
            ->setInstructorName('Nadia Kouakou')
            ->setInstructorBio(
                'Coach scénique et metteuse en scène. '
                . 'Travaille avec des artistes francophones et anglophones d\'Afrique subsaharienne '
                . 'depuis 2017. Partenaire régulière du Pôle Lab Bazaart.'
            )
            ->setInstructorAvatar(null)
            ->setLevel(CourseLevel::BEGINNER)
            // Gratuit : priceInCents = null → getFormattedPrice() retourne "Gratuit"
            ->setPriceInCents(null)
            // ── Champs spécifiques EVENEMENT ──────────────────────────────────
            ->setType(CourseType::EVENEMENT)
            ->setEventMode(CourseEventMode::PRESENTIEL)
            // Dans 45 jours, matin (9h–13h)
            ->setEventStartAt(new \DateTime('+45 days 09:00:00'))
            ->setEventEndAt(new \DateTime('+45 days 13:00:00'))
            // Adresse réelle fictive dans le 11e arrondissement de Paris
            ->setEventLocation('12 rue de la Roquette, 75011 Paris')
            ->setEventExternalUrl(null)  // Pas de lien visio pour un événement présentiel
            ->setCapacity(20)    // Atelier pratique en petit groupe — 20 places max
            ->setIsPublished(true)
            ->setPublishedAt(new \DateTime('-1 day'));

        $manager->persist($eventPresentiel);

        // ── Inscription de artiste@bazaart.fr à la Formation 1 ───────────────
        //
        // Permet de prévisualiser le parcours apprenant :
        //   /formations/introduction-afrobeats-rythme-composition/learn
        //
        // CourseEnrollment.enrolledAt est initialisé par PrePersist (pas de setEnrolledAt),
        // donc on n'a pas besoin de le renseigner explicitement.

        $enrollment = new CourseEnrollment();
        $enrollment
            ->setUser($artistUser)
            ->setCourse($courseAfrobeats)
            // 25% de progression simulée : l'artiste a commencé la formation
            // (valeur stockée dénormalisée — cf. commentaire de l'entité CourseEnrollment)
            ->setProgressPercent(25);

        $manager->persist($enrollment);

        // ── Inscription de artiste@bazaart.fr à l'Événement PRESENTIEL gratuit ─
        //
        // Permet de prévisualiser le dashboard membre avec la section
        // « Mes formations & événements à venir » (ADR-0014 Phase 2).
        //
        // L'atelier présentiel (atelier-scenique-presence-scene-paris) est gratuit
        // et dans 45 jours → il apparaîtra dans la section du dashboard.
        // On pourra voir :
        //   - Le bloc calendrier avec la date
        //   - Le mode "Présentiel" + l'adresse complète révélée (accès dashboard)
        //   - Le bouton "Voir la fiche"
        //
        // On ne crée PAS d'inscription à l'événement VISIO payant (eventVisio)
        // car il faudrait simuler un paiement Stripe, ce qui sort du périmètre fixtures.

        $enrollmentEvent = new CourseEnrollment();
        $enrollmentEvent
            ->setUser($artistUser)
            ->setCourse($eventPresentiel)
            // Pour un événement, progressPercent n'a pas de sens métier (pas de leçons),
            // mais l'entité requiert un int — on garde 0 par défaut.
            ->setProgressPercent(0);

        $manager->persist($enrollmentEvent);

        // ── LessonProgress sur la première leçon (leçon 1.1) ────────────────
        //
        // On simule que l'apprenant a démarré et terminé la leçon 1.1,
        // et s'est arrêté en plein milieu de la leçon 1.2.
        //
        // Cela permet de tester :
        //   - l'affichage du checkmark "leçon terminée" (leçon 1.1)
        //   - la reprise de lecture à lastPositionSeconds (leçon 1.2)
        //   - le calcul de progression (progressPercent = 25% ≈ 1 leçon sur 6 terminée)

        // Progression sur la leçon 1.1 : TERMINÉE
        $progress1 = new LessonProgress();
        $progress1
            ->setEnrollment($enrollment)
            ->setLesson($lessonAfro1_1)
            ->setStartedAt(new \DateTime('-8 days'))
            ->setCompletedAt(new \DateTime('-8 days'))
            ->setLastPositionSeconds(780); // Position = durée totale → vidéo vue en entier

        $manager->persist($progress1);

        // Progression sur la leçon 1.2 : COMMENCÉE, non terminée
        // L'apprenant s'est arrêté à 4 min 42 sec (282 secondes)
        $progress2 = new LessonProgress();
        $progress2
            ->setEnrollment($enrollment)
            ->setLesson($lessonAfro1_2)
            ->setStartedAt(new \DateTime('-7 days'))
            // completedAt = null → leçon commencée mais pas terminée
            ->setCompletedAt(null)
            ->setLastPositionSeconds(282); // Reprise à 4:42

        $manager->persist($progress2);
    }
}
