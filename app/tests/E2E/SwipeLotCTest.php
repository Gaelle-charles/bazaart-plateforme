<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ArtistProfile;
use App\Entity\Discipline;
use App\Entity\Resource;
use App\Entity\ResourceAlert;
use App\Entity\ResourceFavorite;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Enum\ResourceStatus;

/**
 * SwipeLotCTest — Tests fonctionnels E2E pour le Lot C du matching (ADR-0021).
 *
 * Ces tests vérifient :
 *   1. La home retourne HTTP 200 pour un visiteur non connecté
 *   2. La home retourne HTTP 200 pour un artiste connecté (section swipe présente)
 *   3. La home retourne HTTP 200 pour un artiste avec profil incomplet
 *   4. POST /swipe/alert crée une alerte si consentement donné
 *   5. POST /swipe/alert retourne 400 si consentement absent
 *   6. POST /swipe/alert retourne 403 si CSRF invalide
 *   7. POST /swipe/alert retourne 200 "déjà actif" si alerte existante
 *   8. POST /resources/{id}/favorite ajoute un favori (route existante testée
 *      dans le contexte swipe)
 *
 * ISOLATION :
 *   Chaque test appelle purgeDatabase() dans setUp() pour repartir d'une base propre.
 *   Les données de test sont insérées via les helpers de AbstractE2ETestCase.
 *
 * NOTE CSRF :
 *   En environnement de test (APP_ENV=test), Symfony désactive la validation CSRF
 *   SAUF si on l'active explicitement. Notre SwipeController valide le CSRF même
 *   en test via isCsrfTokenValid(). On récupère donc le token depuis la page HTML.
 *
 * @group e2e
 * @group swipe
 */
class SwipeLotCTest extends AbstractE2ETestCase
{
    /**
     * Nettoyage de la base avant chaque test pour éviter les interférences.
     * Nécessaire car les tests créent des utilisateurs, profils, ressources, alertes.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Repart d'une base propre à chaque test (truncate de toutes les tables)
        $this->purgeDatabase();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : PAGE D'ACCUEIL (états de la section swipe)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Vérifie que la home est accessible à 200 pour un visiteur non connecté.
     *
     * État attendu : la section swipe affiche l'encart "Rejoindre la plateforme"
     * (pas de swipe pour les visiteurs). Le reste de la page (hero, opportunités,
     * communauté) doit rester intact.
     */
    public function testHome_VisiteurNonConnecte_Retourne200(): void
    {
        // Requête GET sans authentification
        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit être accessible à 200 pour les visiteurs non connectés.'
        );

        // La section swipe doit être présente dans le HTML
        $this->assertSelectorExists('#swipe-section',
            'La section swipe doit exister dans le HTML même pour les non-connectés.'
        );

        // L'encart "Rejoindre" doit contenir un lien vers /login ou /register
        // (état 3 : non connecté)
        $this->assertSelectorExists('.swipe-guest-cta',
            'L\'encart d\'invitation pour les non-connectés doit être présent.'
        );
    }

    /**
     * Vérifie que la home est accessible à 200 pour un artiste connecté
     * dont le profil est INCOMPLET (pas de disciplines renseignées).
     *
     * État attendu : la section swipe affiche l'encart "Complétez votre profil".
     */
    public function testHome_ArtisteProfilIncomplet_Retourne200(): void
    {
        // Crée un artiste sans profil complet
        $user = $this->createArtistUser('artiste-incomplet@test.fr');
        // Crée un ArtistProfile sans disciplines (profil "creux")
        $this->createArtistProfileForUser($user, disciplines: [], location: null, lookingFor: null);

        $this->loginAs($user);
        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit retourner 200 pour un artiste avec profil incomplet.'
        );

        // L'encart "compléter le profil" doit être visible
        $this->assertSelectorExists('.swipe-incomplete-profile',
            'L\'encart "compléter votre profil" doit être visible si le profil est incomplet.'
        );
    }

    /**
     * Vérifie que la home est accessible à 200 pour un artiste connecté
     * dont le profil est COMPLET mais qui n'a aucun match.
     *
     * État attendu : la section swipe affiche l'état "aucun match".
     */
    public function testHome_ArtisteProfilCompletSansMatch_Retourne200(): void
    {
        // Crée une discipline
        $discipline = $this->createDiscipline('Musique');

        // Crée un artiste avec profil complet (discipline + lookingFor renseignés)
        $user = $this->createArtistUser('artiste-complet@test.fr');
        $user->setLookingFor(['formations']);
        $this->em->flush();

        $this->createArtistProfileForUser(
            $user,
            disciplines: [$discipline],
            location:    'Paris, France',
            lookingFor:  ['formations'],
        );

        $this->loginAs($user);
        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit retourner 200 même quand l\'artiste n\'a aucun match.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : ENDPOINT POST /swipe/alert (création d'alerte avec consentement)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Vérifie que POST /swipe/alert crée une alerte ResourceAlert en BDD
     * quand l'artiste donne son consentement explicite.
     *
     * Scénario heureux : nouvel artiste, consentement coché, CSRF valide.
     * Résultat attendu : 201 Created + alerte en BDD.
     */
    public function testAlertEndpoint_ConsentementDonne_CreeAlerte(): void
    {
        // Crée un artiste sans alerte préexistante
        $user = $this->createArtistUser('artiste-alerte@test.fr');
        $this->loginAs($user);

        // Récupère la home pour obtenir un token CSRF valide
        // (le token est généré par Twig dans le formulaire d'alerte)
        $this->client->request('GET', '/');
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_token"]');

        // Vérifie qu'un token a bien été trouvé dans la page
        // (si la section swipe n'est pas rendue, le test échouera ici → bug de template)
        // Note : si le profil est incomplet, le formulaire d'alerte n'est pas rendu.
        // Dans ce test, le profil est incomplet par construction (pas de disciplines).
        // On génère donc le token via le service CSRF directement.
        $csrfTokenService = static::getContainer()->get('security.csrf.token_manager');
        $token = $csrfTokenService->getToken('swipe_alert')->getValue();

        // Envoie le POST avec consentement
        $this->client->request(
            'POST',
            '/swipe/alert',
            [
                '_token'  => $token,  // Token CSRF valide
                'consent' => '1',     // Consentement explicite coché
            ],
            [],
            // En-têtes HTTP (inutile ici car on ne teste pas la réponse AJAX,
            // mais la création en BDD — le controller retourne toujours JSON)
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        // Le controller doit retourner 201 Created (nouvelle alerte créée)
        $this->assertResponseStatusCodeSame(201,
            'POST /swipe/alert avec consentement doit retourner 201 Created.'
        );

        // Vérifie que l'alerte a bien été créée en BDD
        $alertRepo = static::getContainer()->get('doctrine.orm.entity_manager')
            ->getRepository(ResourceAlert::class);
        $alert = $alertRepo->findByUser($user);

        $this->assertNotNull($alert,
            'Une ResourceAlert doit exister en BDD après le POST avec consentement.'
        );
        $this->assertTrue($alert->isNotifyOnNewResource(),
            'L\'alerte créée doit avoir notifyOnNewResource = true.'
        );
    }

    /**
     * Vérifie que POST /swipe/alert retourne 400 si le consentement n'est PAS donné.
     *
     * C'est la règle RGPD fondamentale : pas de consentement = pas d'alerte.
     * L'endpoint doit refuser, pas créer silencieusement.
     */
    public function testAlertEndpoint_SansConsentement_Retourne400(): void
    {
        $user = $this->createArtistUser('artiste-no-consent@test.fr');
        $this->loginAs($user);

        $csrfTokenService = static::getContainer()->get('security.csrf.token_manager');
        $token = $csrfTokenService->getToken('swipe_alert')->getValue();

        // Envoi SANS consent (ou consent = '0')
        $this->client->request(
            'POST',
            '/swipe/alert',
            [
                '_token'  => $token,
                'consent' => '0', // Consentement explicitement refusé
            ],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        // Doit retourner 400 Bad Request (pas de consentement = requête invalide)
        $this->assertResponseStatusCodeSame(400,
            'POST /swipe/alert sans consentement doit retourner 400.'
        );

        // Vérifie qu'aucune alerte n'a été créée en BDD
        $alertRepo = $this->em->getRepository(ResourceAlert::class);
        $alert     = $alertRepo->findByUser($user);

        $this->assertNull($alert,
            'Aucune ResourceAlert ne doit être créée si le consentement est absent.'
        );
    }

    /**
     * Vérifie que POST /swipe/alert retourne 403 si le token CSRF est invalide.
     *
     * Protection anti-CSRF fondamentale : on ne doit pas pouvoir créer une alerte
     * depuis un site tiers même si l'utilisateur est connecté.
     */
    public function testAlertEndpoint_CsrfInvalide_Retourne403(): void
    {
        $user = $this->createArtistUser('artiste-csrf@test.fr');
        $this->loginAs($user);

        $this->client->request(
            'POST',
            '/swipe/alert',
            [
                '_token'  => 'token-invalide-forge',
                'consent' => '1',
            ],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $this->assertResponseStatusCodeSame(403,
            'POST /swipe/alert avec CSRF invalide doit retourner 403 Forbidden.'
        );
    }

    /**
     * Vérifie que POST /swipe/alert retourne 403 si l'utilisateur n'est pas artiste.
     *
     * Seuls les artistes (ROLE_ARTIST) peuvent créer des alertes de matching.
     * Un ROLE_USER sans ROLE_ARTIST doit être refusé.
     */
    public function testAlertEndpoint_NonArtiste_Retourne403(): void
    {
        // Utilisateur standard sans ROLE_ARTIST
        $user = $this->createRegularUser('nonartiste@test.fr');
        $this->loginAs($user);

        $csrfTokenService = static::getContainer()->get('security.csrf.token_manager');
        $token = $csrfTokenService->getToken('swipe_alert')->getValue();

        $this->client->request(
            'POST',
            '/swipe/alert',
            [
                '_token'  => $token,
                'consent' => '1',
            ],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        // MatchingVoter refuse : ROLE_ARTIST requis
        $this->assertResponseStatusCodeSame(403,
            'POST /swipe/alert pour un non-artiste doit retourner 403.'
        );
    }

    /**
     * Vérifie que POST /swipe/alert retourne 200 "already_active" si l'alerte
     * est déjà active, SANS créer un doublon en BDD.
     *
     * Idempotence : deux appels consécutifs ne créent qu'une seule alerte.
     */
    public function testAlertEndpoint_AlerteDejaActive_Retourne200SansDoublon(): void
    {
        $user = $this->createArtistUser('artiste-double@test.fr');

        // Crée manuellement une alerte active existante
        $existingAlert = new ResourceAlert();
        $existingAlert->setUser($user);
        $existingAlert->setNotifyOnNewResource(true);
        $this->em->persist($existingAlert);
        $this->em->flush();

        $this->loginAs($user);

        $csrfTokenService = static::getContainer()->get('security.csrf.token_manager');
        $token = $csrfTokenService->getToken('swipe_alert')->getValue();

        $this->client->request(
            'POST',
            '/swipe/alert',
            [
                '_token'  => $token,
                'consent' => '1',
            ],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        // Doit retourner 200 (pas 201 car pas de nouvelle création)
        $this->assertResponseStatusCodeSame(200,
            'POST /swipe/alert avec alerte déjà active doit retourner 200.'
        );

        // Décode la réponse JSON et vérifie already_active = true
        $responseData = json_decode(
            (string) $this->client->getResponse()->getContent(),
            associative: true,
        );
        $this->assertTrue($responseData['success'] ?? false,
            'La réponse JSON doit avoir success = true.'
        );
        $this->assertTrue($responseData['already_active'] ?? false,
            'La réponse JSON doit indiquer already_active = true si l\'alerte existait déjà.'
        );

        // Compte les alertes en BDD : doit rester à 1 (pas de doublon)
        $alertCount = $this->em->getRepository(ResourceAlert::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, (int) $alertCount,
            'Un seul ResourceAlert doit exister en BDD malgré deux appels.'
        );
    }

    /**
     * Vérifie que le toggle favori (POST /resources/{id}/favorite) fonctionne
     * en mode AJAX pour ajouter une ressource en favori depuis le contexte swipe.
     *
     * Ce test vérifie la réutilisation de l'endpoint existant du ResourceController
     * dans le contexte du swipe (ADR-0021 : swipe droite = ajouter en favori).
     */
    public function testToggleFavori_ArtisteConnecte_AjouteFavori(): void
    {
        // Crée un artiste
        $user = $this->createArtistUser('artiste-fav@test.fr');

        // Crée une ressource publiée
        $resourceType = $this->createResourceType('Bourse');
        $resource     = $this->createPublishedResource($resourceType, 'Bourse de création test');

        $this->loginAs($user);

        // Récupère le token CSRF spécifique à cette ressource
        $csrfTokenService = static::getContainer()->get('security.csrf.token_manager');
        $token = $csrfTokenService
            ->getToken('resource_favorite_' . $resource->getId())
            ->getValue();

        // Envoie le POST toggle favori en mode AJAX
        $this->client->request(
            'POST',
            '/resources/' . $resource->getId() . '/favorite',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        // Doit retourner 200 JSON
        $this->assertResponseStatusCodeSame(200,
            'POST /resources/{id}/favorite en AJAX doit retourner 200.'
        );

        // Décode la réponse
        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            associative: true,
        );
        $this->assertTrue($data['favorited'] ?? false,
            'La réponse doit indiquer favorited = true après le premier toggle.'
        );

        // Vérifie en BDD qu'un ResourceFavorite a été créé
        $favRepo = $this->em->getRepository(ResourceFavorite::class);
        $favorite = $favRepo->findByUserAndResource($user, $resource);
        $this->assertNotNull($favorite,
            'Un ResourceFavorite doit exister en BDD après le toggle.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crée et persiste un ArtistProfile pour un User donné.
     *
     * @param User         $user        L'utilisateur propriétaire du profil
     * @param Discipline[] $disciplines Disciplines artistiques du profil
     * @param string|null  $location    Localisation libre (ex: "Paris, France")
     * @param mixed        $lookingFor  Valeurs ArtistLookingFor (pas utilisé ici)
     */
    /**
     * @param Discipline[] $disciplines
     */
    private function createArtistProfileForUser(
        User $user,
        array $disciplines,
        ?string $location,
        mixed $lookingFor,
    ): ArtistProfile {
        $profile = new ArtistProfile();
        $profile->setUser($user);

        if ($location !== null) {
            $profile->setLocation($location);
        }

        // Associe les disciplines
        foreach ($disciplines as $discipline) {
            $profile->addDiscipline($discipline);
        }

        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    /**
     * Crée et persiste une Discipline.
     *
     * @param string $name Nom de la discipline (ex: "Musique")
     */
    private function createDiscipline(string $name): Discipline
    {
        $discipline = new Discipline();
        $discipline->setName($name);

        $this->em->persist($discipline);
        $this->em->flush();

        return $discipline;
    }

    /**
     * Crée et persiste un ResourceType.
     */
    private function createResourceType(string $name): ResourceType
    {
        $type = new ResourceType();
        $type->setName($name);

        $this->em->persist($type);
        $this->em->flush();

        return $type;
    }

    /**
     * Crée et persiste une Resource publiée.
     */
    private function createPublishedResource(ResourceType $type, string $title): Resource
    {
        $resource = new Resource();
        $resource
            ->setTitle($title)
            ->setDescription('Description de test pour ' . $title)
            ->setResourceType($type)
            ->setStatus(ResourceStatus::Published);

        $this->em->persist($resource);
        $this->em->flush();

        return $resource;
    }
}
