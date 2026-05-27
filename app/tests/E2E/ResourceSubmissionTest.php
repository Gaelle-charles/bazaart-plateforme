<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ResourceType;
use App\Enum\ResourceStatus;
use App\Repository\ResourceRepository;
use App\Repository\ResourceTypeRepository;

/**
 * ResourceSubmissionTest — Test E2E #2 : Soumission d'une ressource.
 *
 * Ce test couvre le parcours "Soumettre une ressource (artiste)" du CDC V3 §10.
 *
 * Scénarios testés :
 *   1. Un utilisateur connecté peut accéder au formulaire de soumission (/resources/submit)
 *   2. Un utilisateur non connecté est redirigé vers /login
 *   3. La soumission avec des données valides crée une ressource en BDD
 *   4. La ressource créée par un artiste a le statut PENDING_VALIDATION
 *
 * Notes d'implémentation :
 *   - ResourceController::submit() vérifie le CSRF token
 *   - ResourceService::createResource() détermine le statut selon le rôle
 *   - Un artiste sans OrganizationProfile → SubmitterRole::Artist → status PendingValidation
 *   - Une structure partenaire → auto-publication directe
 */
class ResourceSubmissionTest extends AbstractE2ETestCase
{
    /**
     * ResourceType de test créé dans setUp() et réutilisé dans les méthodes de test.
     * On le stocke comme propriété pour éviter de le recréer à chaque test.
     */
    private ?ResourceType $resourceType = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Vide la BDD pour partir d'un état propre
        $this->purgeDatabase();

        // Crée un ResourceType de test (requis pour soumettre une ressource)
        $this->resourceType = new ResourceType();
        $this->resourceType->setName('Résidence artistique');
        $this->em->persist($this->resourceType);
        $this->em->flush();
    }

    // ─── Test 2.1 : Page /resources/submit accessible pour un utilisateur connecté ─

    /**
     * Vérifie qu'un utilisateur connecté peut accéder au formulaire de soumission.
     *
     * ResourceController::submit() est protégé par #[IsGranted('ROLE_USER')]
     * → tout utilisateur authentifié peut soumettre une ressource en V1.
     */
    public function testSubmitPageAccessibleWhenLoggedIn(): void
    {
        // Crée et connecte un utilisateur artiste
        $user = $this->createArtistUser();
        $this->loginAs($user);

        $this->client->request('GET', '/resources/submit');

        // 200 OK : le formulaire s'affiche
        $this->assertResponseIsSuccessful();
    }

    // ─── Test 2.2 : Non connecté → redirect /login ───────────────────────────

    /**
     * Vérifie qu'un utilisateur non authentifié est redirigé vers /login.
     *
     * Le access_control dans security.yaml redirige tous les /resources/* non-admins
     * vers /login si non authentifié.
     *
     * C'est une vérification de sécurité basique : la route ne doit jamais
     * être accessible sans authentification.
     */
    public function testSubmitPageRedirectsToLoginWhenNotAuthenticated(): void
    {
        // Requête sans session (pas de loginAs())
        $this->client->request('GET', '/resources/submit');

        // Doit rediriger vers /login (ou /_profiler/login, ou autre entry point)
        $this->assertResponseRedirects();
        $this->assertResponseStatusCodeSame(302);

        // On suit la redirection et on vérifie qu'on atterrit sur /login
        $this->client->followRedirect();
        $this->assertRouteSame('app_login');
    }

    // ─── Test 2.3 : Soumission valide crée une ressource en BDD ─────────────

    /**
     * Vérifie qu'une soumission valide crée bien une ressource en base de données.
     *
     * Flux attendu :
     *   POST /resources/submit → redirect 302 vers /resources/my
     *
     * Après la soumission, on vérifie en BDD que la ressource existe.
     */
    public function testSubmitValidResourceCreatesEntry(): void
    {
        // Crée un artiste avec un OrganizationProfile
        // Note : le template resource/submit.html.twig n'affiche le formulaire que si
        // l'utilisateur a un profil organisation — sans org, il voit un message d'erreur.
        $user = $this->createArtistUser('artiste.submit@test.fr');
        $this->createOrganizationProfile($user, 'Association Test Soumission');
        $this->loginAs($user);

        // ── Étape 1 : vérifie que la page GET répond 200 et récupère le token CSRF ─
        // Le formulaire /resources/submit contient un champ caché "_token"
        // généré par csrf_token('resource_submit') dans le template Twig.
        $this->client->request('GET', '/resources/submit');
        $this->assertResponseIsSuccessful();

        // Extrait le token CSRF depuis le champ caché du formulaire.
        // Le champ s'appelle "_token" dans submit.html.twig.
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_token"]');

        // ── Étape 2 : soumet le formulaire ────────────────────────────────────
        // Note : le champ select s'appelle "resourceTypeId" dans submit.html.twig
        //        (pas "resourceType") — correspond au nom lu par ResourceService::createResource().
        $this->client->request('POST', '/resources/submit', [
            'title'          => 'Résidence test E2E — Création contemporaine',
            'description'    => 'Description de test pour le test E2E de soumission de ressource. '
                              . 'Contenu suffisamment long pour passer la validation minimale.',
            'resourceTypeId' => (string) $this->resourceType->getId(),
            'location'       => 'Paris',
            'externalUrl'    => 'https://test-e2e.example.com/residence',
            '_token'         => $csrfToken,
        ]);

        // ── Étape 3 : vérifie la redirection ─────────────────────────────────
        // ResourceController::submit() redirige vers app_resource_my après succès
        $this->assertResponseRedirects('/resources/my');

        // ── Étape 4 : vérifie la présence en BDD ─────────────────────────────
        /** @var ResourceRepository $repo */
        $repo = static::getContainer()->get(ResourceRepository::class);

        // findOneBy retourne null si la ressource n'existe pas → le test échouerait
        $resource = $repo->findOneBy(['title' => 'Résidence test E2E — Création contemporaine']);

        $this->assertNotNull(
            $resource,
            'La ressource doit avoir été créée en BDD après soumission valide'
        );

        $this->assertSame(
            'Résidence test E2E — Création contemporaine',
            $resource->getTitle()
        );
    }

    // ─── Test 2.4 : Statut PENDING_VALIDATION pour un artiste ───────────────

    /**
     * Vérifie que la ressource soumise par un artiste a bien le statut
     * PENDING_VALIDATION (en attente de validation par l'équipe Bazaart).
     *
     * Règle métier CDC V3 §5.3 :
     *   - ROLE_ADMIN → auto-publication directe (Published)
     *   - ROLE_STRUCTURE partenaire → auto-publication directe (Published)
     *   - ROLE_ARTIST / ROLE_USER → statut PendingValidation (validation requise)
     *
     * Ce test s'assure que cette règle est bien appliquée par ResourceService.
     */
    public function testArtistSubmittedResourceHasPendingValidationStatus(): void
    {
        // Un artiste avec un OrganizationProfile (requis par le template pour afficher le formulaire)
        // Cet artiste n'est PAS un ROLE_STRUCTURE partenaire → sa ressource sera en PendingValidation
        $artist = $this->createArtistUser('artiste.statut@test.fr');
        $this->createOrganizationProfile($artist, 'Asso Artiste Test Statut', false);
        $this->loginAs($artist);

        // Charge la page GET pour récupérer le token CSRF
        $this->client->request('GET', '/resources/submit');
        $this->assertResponseIsSuccessful();
        $csrfToken = $this->getCsrfTokenFromHtml('input[name="_token"]');

        // Soumission d'une ressource
        // Note : le champ select s'appelle "resourceTypeId" dans submit.html.twig
        $this->client->request('POST', '/resources/submit', [
            'title'          => 'Resource Status Test — Statut artiste',
            'description'    => 'Description de test pour vérifier le statut initial d\'une ressource artiste.',
            'resourceTypeId' => (string) $this->resourceType->getId(),
            '_token'         => $csrfToken,
        ]);

        // Après redirection, on va en BDD
        // Note : si pas de redirection (erreur), la ressource n'aura pas été créée
        $this->assertResponseRedirects('/resources/my');

        /** @var ResourceRepository $repo */
        $repo = static::getContainer()->get(ResourceRepository::class);
        $resource = $repo->findOneBy(['title' => 'Resource Status Test — Statut artiste']);

        // La ressource doit exister
        $this->assertNotNull($resource, 'La ressource doit avoir été créée');

        // Le statut doit être PendingValidation (pas Published directement)
        // Un artiste ne peut pas auto-publier — il faut la validation d'un admin.
        $this->assertSame(
            ResourceStatus::PendingValidation,
            $resource->getStatus(),
            'Un artiste doit soumettre en statut PendingValidation, pas Published'
        );
    }
}
