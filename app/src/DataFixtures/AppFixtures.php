<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\ArtistProfile;
use App\Entity\Discipline;
use App\Entity\OrganizationProfile;
use App\Entity\Resource;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Enum\ArticleStatus;
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

        // ── Étape 5 : 12 ressources publiées ─────────────────────────────────
        $this->createResources($manager, $adminUser, $structureUser, $structureOrg, $disciplines, $resourceTypes);

        // ── Étape 6 : 3 articles publiés ─────────────────────────────────────
        $this->createArticles($manager, $adminUser, $artistUser);

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
     *   Password : Admin1234!
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
     *   Password : Test1234!
     *   Rôles    : ROLE_USER (implicite) + ROLE_ARTIST
     */
    private function createArtistUser(ObjectManager $manager): User
    {
        $artist = new User();
        $artist
            ->setEmail('artiste@bazaart.fr')
            ->setRoles(['ROLE_ARTIST'])
            ->setIsVerified(true)
            ->setPassword(
                $this->passwordHasher->hashPassword($artist, 'Test1234!')
            );

        $manager->persist($artist);

        return $artist;
    }

    /**
     * Crée l'utilisateur structure (partenaire) de test.
     *
     * Identifiants :
     *   Email    : structure@bazaart.fr
     *   Password : Test1234!
     *   Rôles    : ROLE_USER (implicite) + ROLE_STRUCTURE
     */
    private function createStructureUser(ObjectManager $manager): User
    {
        $structure = new User();
        $structure
            ->setEmail('structure@bazaart.fr')
            ->setRoles(['ROLE_STRUCTURE'])
            ->setIsVerified(true)
            ->setPassword(
                $this->passwordHasher->hashPassword($structure, 'Test1234!')
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
}
