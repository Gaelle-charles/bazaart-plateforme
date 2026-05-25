<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\RgpdService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RgpdController — gestion des droits RGPD de l'utilisateur connecté.
 *
 * Toutes les routes de ce controller nécessitent ROLE_USER (utilisateur connecté).
 * La logique métier est déléguée à RgpdService (convention projet).
 *
 * Routes exposées :
 *   GET  /profil/rgpd                → app_rgpd_index      (page d'information + liens)
 *   GET  /profil/rgpd/export         → app_rgpd_export     (téléchargement JSON)
 *   POST /profil/rgpd/delete-request → app_rgpd_delete     (demande de suppression)
 *
 * Sécurité :
 *   - #[IsGranted('ROLE_USER')] sur la classe → s'applique à toutes les actions
 *   - Token CSRF sur l'action POST (delete-request)
 *   - L'utilisateur ne peut modifier/exporter QUE ses propres données
 *     (on récupère toujours l'utilisateur connecté via $this->getUser())
 */
#[IsGranted('ROLE_USER')]
class RgpdController extends AbstractController
{
    /**
     * Injection du service RGPD et du token storage (pour déconnexion après anonymisation).
     *
     * TokenStorageInterface est le service bas niveau de Symfony Security qui gère
     * le token d'authentification en session. On l'utilise pour déconnecter
     * l'utilisateur après anonymisation de son compte (le token devient invalide
     * car l'email a changé).
     */
    public function __construct(
        private readonly RgpdService $rgpdService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    /**
     * Page d'information RGPD — liens vers l'export et la demande de suppression.
     *
     * Cette page est le point d'entrée de l'espace RGPD utilisateur.
     * Elle affiche :
     *   - Une explication des droits RGPD (accès, portabilité, effacement)
     *   - Un lien vers le téléchargement du fichier JSON
     *   - Un formulaire de demande de suppression (avec token CSRF)
     */
    #[Route('/profil/rgpd', name: 'app_rgpd_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('rgpd/index.html.twig');
    }

    /**
     * Téléchargement des données personnelles au format JSON.
     *
     * Implémente l'article 20 du RGPD (droit à la portabilité des données).
     * Le fichier est nommé avec la date du jour pour que l'utilisateur puisse
     * conserver plusieurs exports horodatés.
     *
     * L'en-tête "Content-Disposition: attachment" force le téléchargement
     * (au lieu d'afficher le JSON dans le navigateur).
     *
     * Sécurité : on récupère TOUJOURS $this->getUser() — jamais un ID passé en URL.
     * Cela garantit qu'un utilisateur ne peut exporter QUE ses propres données.
     */
    #[Route('/profil/rgpd/export', name: 'app_rgpd_export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        // Récupération de l'utilisateur connecté (garanti par #[IsGranted('ROLE_USER')])
        /** @var User $user */
        $user = $this->getUser();

        // Délégation à RgpdService — le controller ne fait qu'orchestrer
        $data = $this->rgpdService->exportUserData($user);

        // Nom du fichier horodaté : "mes-donnees-bazaart-2026-05-25.json"
        $filename = sprintf('mes-donnees-bazaart-%s.json', date('Y-m-d'));

        // JsonResponse avec JSON encodé "proprement" (unicode non échappé, indenté)
        // pour que l'utilisateur puisse lire le fichier facilement.
        $response = new JsonResponse($data, Response::HTTP_OK, [], false);
        $response->setEncodingOptions(
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );

        // En-tête Content-Disposition : force le téléchargement au lieu de l'affichage
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $filename)
        );

        return $response;
    }

    /**
     * Demande de suppression / anonymisation du compte.
     *
     * Implémente l'article 17 du RGPD (droit à l'effacement).
     * En V1, on procède à une anonymisation (pas suppression brutale) pour
     * préserver l'intégrité référentielle de la base de données.
     *
     * Processus :
     *   1. Vérification du token CSRF (prévention CSRF)
     *   2. Anonymisation via RgpdService::anonymizeUser()
     *   3. Invalidation de la session Symfony (déconnexion)
     *   4. Flash message d'information
     *   5. Redirection vers la page d'accueil
     *
     * Pourquoi POST et pas DELETE ?
     *   Les formulaires HTML standard ne supportent que GET et POST.
     *   On utilise POST avec une protection CSRF — équivalent sécuritaire de DELETE
     *   dans ce contexte (pas d'API REST, formulaire Twig classique).
     */
    #[Route('/profil/rgpd/delete-request', name: 'app_rgpd_delete', methods: ['POST'])]
    public function deleteRequest(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // ── Vérification du token CSRF ─────────────────────────────────────────
        // L'identifiant 'rgpd_delete' doit correspondre exactement à celui utilisé
        // dans le template Twig : {{ csrf_token('rgpd_delete') }}
        // Sans cette vérification, n'importe quel site tiers pourrait déclencher
        // la suppression du compte d'un utilisateur connecté en lui faisant cliquer
        // un lien malveillant.
        if (!$this->isCsrfTokenValid('rgpd_delete', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_rgpd_index');
        }

        // ── Anonymisation du compte ────────────────────────────────────────────
        // Délégation complète à RgpdService — le controller ne contient aucune
        // logique métier (convention projet CDC V3 §4).
        $this->rgpdService->anonymizeUser($user);

        // ── Invalidation de la session ─────────────────────────────────────────
        // Après anonymisation, l'email du compte a changé.
        // Si on ne déconnecte pas l'utilisateur, Symfony pourrait se retrouver
        // avec un token invalide (l'identifiant ne correspond plus à un compte actif).
        // setToken(null) supprime le token d'authentification de la session.
        $this->tokenStorage->setToken(null);

        // On invalide aussi la session HTTP elle-même pour garantir une déconnexion propre.
        // Cela supprime les données de session (dont le token CSRF et les flash messages).
        $request->getSession()->invalidate();

        // Flash message persisté APRÈS l'invalidation de session pour qu'il survive
        // en fait ce n'est pas possible après invalidate() — on le met avant dans la
        // session qui sera détruite. L'utilisateur verra un état neutre sur la home.
        // Note : si on voulait un flash, il faudrait le stocker dans la session de la
        // réponse suivante via un cookie spécial — trop complexe pour V1, on simplifie.

        // ── Redirection vers la page d'accueil ────────────────────────────────
        // L'utilisateur est maintenant déconnecté → redirection vers la vitrine publique.
        return $this->redirectToRoute('app_home');
    }
}
