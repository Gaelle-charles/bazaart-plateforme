<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * AbstractE2ETestCase — Classe de base pour tous les tests fonctionnels E2E.
 *
 * Cette classe fournit :
 *   1. Des méthodes helper pour créer rapidement un utilisateur de test en BDD
 *   2. Des méthodes helper pour se connecter avec $client->loginUser()
 *   3. Un setUp() qui s'assure que la base de test est propre entre chaque test
 *
 * Pourquoi WebTestCase ?
 *   WebTestCase (from symfony/framework-bundle) fournit :
 *   - static::createClient() → un KernelBrowser qui simule des requêtes HTTP
 *   - $client->loginUser($user) → connexion sans passer par le formulaire HTML
 *   - accès au container de services Symfony (pour les repositories, etc.)
 *
 * Convention d'isolation des tests :
 *   Chaque test repart d'une base propre. On tronque les tables dans setUp()
 *   SANS recréer les migrations (trop lent). On insère uniquement les données
 *   nécessaires à chaque test via des méthodes helper.
 *
 * Pourquoi pas DAMA/DoctrineTestBundle (transactions) ?
 *   Le bundle n'est pas installé et son installation ajouterait une dépendance
 *   non triviale. L'approche par truncate + helpers est suffisante pour V1.
 */
abstract class AbstractE2ETestCase extends WebTestCase
{
    /**
     * Référence au browser HTTP Symfony.
     * Initialisé dans setUp() — partagé dans toute la sous-classe.
     */
    protected KernelBrowser $client;

    /**
     * EntityManager pour les assertions de BDD dans les tests.
     * Obtenu depuis le container Symfony du kernel de test.
     */
    protected EntityManagerInterface $em;

    // ─── Initialisation ───────────────────────────────────────────────────────

    /**
     * setUp() est appelé avant CHAQUE méthode de test.
     *
     * On crée un nouveau client à chaque test pour isoler les états de session.
     * On récupère aussi l'EntityManager pour pouvoir interroger la BDD dans les tests.
     *
     * Note : on ne tronque pas la base ici pour ne pas ralentir les tests.
     * Chaque test est responsable de créer ses propres données via createTestUser() etc.
     * La base est vidée UNE SEULE FOIS au début de la suite via tearDownAfterClass().
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Crée le browser HTTP Symfony — c'est lui qui simule GET, POST, etc.
        // followRedirects(false) → on ne suit pas les redirections automatiquement
        // pour pouvoir asserter les status codes 302 et les headers Location.
        $this->client = static::createClient();
        $this->client->followRedirects(false);

        // Récupère l'EntityManager depuis le container du kernel de test.
        // Le container est accessible via static::getContainer() (Symfony 5.3+).
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
    }

    // ─── Nettoyage de la base ─────────────────────────────────────────────────

    /**
     * Vide les tables dans l'ordre inverse des dépendances FK.
     * Appelé manuellement dans les tests qui ont besoin d'un état connu.
     *
     * On utilise TRUNCATE ... CASCADE pour ne pas avoir à gérer l'ordre des FK.
     * PostgreSQL propage la troncature aux tables liées par les FK.
     *
     * ⚠️ DANGER : ne JAMAIS appeler sur la base de dev ou prod.
     *    Cette méthode est réservée à l'environnement de test.
     */
    protected function purgeDatabase(): void
    {
        // On désactive temporairement les contraintes FK pour le TRUNCATE
        // (PostgreSQL ne supporte pas TRUNCATE sans CASCADE pour les tables liées)
        $conn = $this->em->getConnection();

        // Tronque les tables dans l'ordre inverse des dépendances
        $conn->executeStatement('TRUNCATE TABLE
            lesson_progresses,
            course_enrollments,
            lesson_resources,
            lessons,
            course_modules,
            courses,
            live_attendees,
            lives,
            messages,
            conversation_participants,
            conversations,
            forum_replies,
            forum_threads,
            forum_categories,
            resource_alerts,
            resource_favorites,
            resources,
            resource_types,
            notifications,
            artist_disciplines,
            disciplines,
            organization_profiles,
            artist_profiles,
            users
            RESTART IDENTITY CASCADE
        ');

        // Vide l'identity map de Doctrine pour éviter les objets "fantômes"
        // (entités en mémoire qui ne correspondent plus à la BDD)
        $this->em->clear();
    }

    // ─── Helpers de création d'utilisateurs ──────────────────────────────────

    /**
     * Crée et persiste un utilisateur de test en BDD.
     *
     * @param string   $email    Email de l'utilisateur
     * @param string   $password Mot de passe EN CLAIR (sera haché avant persistance)
     * @param string[] $roles    Rôles Symfony (ex: ['ROLE_ARTIST'])
     * @param bool     $verified Compte vérifié ?
     *
     * @return User L'entité persistée (avec son ID)
     */
    protected function createTestUser(
        string $email,
        string $password,
        array $roles = [],
        bool $verified = true,
    ): User {
        // Récupère le service de hachage de mot de passe depuis le container
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user
            ->setEmail($email)
            ->setRoles($roles)
            ->setIsVerified($verified)
            ->setPassword(
                // Hache le mot de passe en clair avec bcrypt (cost: 4 en test = rapide)
                $hasher->hashPassword($user, $password)
            );

        $this->em->persist($user);
        $this->em->flush();

        // Recharge depuis la BDD pour avoir l'ID généré par la séquence PostgreSQL
        $this->em->refresh($user);

        return $user;
    }

    /**
     * Crée un utilisateur artiste avec les rôles appropriés.
     * Raccourci pour éviter de répéter les rôles dans chaque test.
     *
     * Note : le mot de passe respecte la politique CDC §9 (10 chars, 1 maj, 1 chiffre)
     * bien que ces utilisateurs soient créés directement en BDD (pas via le DTO).
     */
    protected function createArtistUser(string $email = 'artiste@test.fr'): User
    {
        return $this->createTestUser(
            email:    $email,
            // Conforme CDC §9 : 10 chars, majuscule, chiffre
            password: 'TestPass12!',
            roles:    ['ROLE_ARTIST'],
        );
    }

    /**
     * Crée un utilisateur admin.
     */
    protected function createAdminUser(string $email = 'admin@test.fr'): User
    {
        return $this->createTestUser(
            email:    $email,
            // Conforme CDC §9 : 10 chars, majuscule, chiffre
            password: 'Admin1234!',
            roles:    ['ROLE_ADMIN'],
        );
    }

    /**
     * Crée un utilisateur standard (ROLE_USER seulement).
     */
    protected function createRegularUser(string $email = 'user@test.fr'): User
    {
        return $this->createTestUser(
            email:    $email,
            // Conforme CDC §9 : 10 chars, majuscule, chiffre
            password: 'TestPass12!',
            roles:    [],
        );
    }

    // ─── Helper de création de profil organisation ────────────────────────

    /**
     * Crée et persiste un OrganizationProfile pour un utilisateur donné.
     *
     * Nécessaire pour les tests de soumission de ressources, car le formulaire
     * /resources/submit affiche un message d'erreur si l'utilisateur n'a pas
     * d'OrganizationProfile (comportement du template submit.html.twig).
     *
     * @param User   $user             L'utilisateur propriétaire du profil
     * @param string $name             Nom de l'organisation
     * @param bool   $isStructure      L'organisation est-elle partenaire structure ?
     *
     * @return \App\Entity\OrganizationProfile Le profil persisté
     */
    protected function createOrganizationProfile(
        User $user,
        string $name = 'Organisation Test E2E',
        bool $isStructure = false,
    ): \App\Entity\OrganizationProfile {
        $org = new \App\Entity\OrganizationProfile();
        $org
            ->setUser($user)
            ->setName($name)
            ->setDescription('Organisation créée pour les tests E2E automatisés.')
            ->setIsVerified(true)
            ->setIsStructurePartner($isStructure);

        $this->em->persist($org);
        $this->em->flush();

        return $org;
    }

    // ─── Helper de connexion ──────────────────────────────────────────────────

    /**
     * Connecte l'utilisateur dans le browser de test.
     *
     * Utilise $client->loginUser() introduit dans Symfony 5.1+.
     * Cette méthode injecte directement la session d'authentification SANS
     * passer par le formulaire de login — c'est beaucoup plus rapide.
     *
     * Pourquoi c'est mieux que simuler le formulaire ?
     *   - Le formulaire de login enverrait une requête POST /login qui déclenche
     *     le LoginThrottling, les listeners, etc.
     *   - loginUser() bypass tout ça et simule directement une session valide.
     *   - Les tests de connexion réelle (RegistrationTest) font exception et
     *     testent le formulaire explicitement.
     */
    protected function loginAs(User $user): void
    {
        // loginUser() place l'utilisateur dans la session du firewall "main"
        // (défini dans security.yaml). La requête suivante sera authentifiée.
        $this->client->loginUser($user);
    }

    // ─── Helpers CSRF ─────────────────────────────────────────────────────────

    /**
     * Extrait le token CSRF depuis un champ caché dans le HTML d'une page.
     *
     * Utilisation type :
     *   $this->client->request('GET', '/ma-page'); // rend le formulaire
     *   $token = $this->getCsrfTokenFromHtml('input[name="_token"]');
     *   $this->client->request('POST', '/ma-page', ['_token' => $token, ...]);
     *
     * @param string $selector Sélecteur CSS du champ caché (ex: 'input[name="_token"]')
     *
     * @return string Le token CSRF extrait (ou '' si champ introuvable)
     */
    protected function getCsrfTokenFromHtml(string $selector = 'input[name="_token"]'): string
    {
        // getCrawler() retourne le DomCrawler de la dernière réponse.
        // On filtre sur le sélecteur CSS et on lit l'attribut "value".
        $crawler = $this->client->getCrawler();
        $input   = $crawler->filter($selector);

        // Si l'input n'existe pas dans le HTML (template conditionnel, etc.),
        // on retourne une chaîne vide — le test échouera proprement sur l'assertion suivante.
        if ($input->count() === 0) {
            return '';
        }

        return (string) $input->attr('value');
    }

}

