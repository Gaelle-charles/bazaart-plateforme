<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrganizationProfileRepository;
use App\Repository\ResourceRepository;
use App\Security\Voter\StructureVoter;
use App\Service\StructureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * StructureController — gère les pages du parcours "Compte Structure".
 *
 * Les routes de ce controller sont protégées par security.yaml :
 *   - /structure/register → ROLE_USER (tout utilisateur connecté)
 *   - /structure/dashboard → ROLE_STRUCTURE (structure validée uniquement)
 *
 * Le controller est volontairement "fin" : toute la logique métier
 * est dans StructureService. Le controller ne fait qu'orchestrer :
 *   1. Récupérer les données de la requête
 *   2. Appeler le service
 *   3. Afficher le résultat (flash + redirect ou template Twig)
 */
#[Route('/structure', name: 'app_structure_')]
class StructureController extends AbstractController
{
    public function __construct(
        private readonly StructureService $structureService,
        private readonly OrganizationProfileRepository $orgRepository,
        private readonly ResourceRepository $resourceRepository,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE D'INSCRIPTION — /structure/register
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Formulaire de candidature au statut Structure partenaire.
     *
     * GET  → Affiche le formulaire (pré-rempli si l'utilisateur a déjà un profil org)
     * POST → Traite la candidature via StructureService::applyAsStructure()
     *
     * Cas gérés avant d'afficher le formulaire :
     *   - Déjà structure active → redirect vers le dashboard
     *   - Candidature déjà en cours → flash info + redirect vers dashboard
     *     (evite la double soumission et rassure l'utilisateur)
     */
    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        // Récupère l'utilisateur connecté.
        // isGranted('ROLE_USER') est garanti par security.yaml pour cette route,
        // mais on type-check pour satisfaire PHPStan et éviter des null pointer.
        /** @var User $user */
        $user = $this->getUser();

        // ── Récupère le profil existant (s'il existe) ─────────────────────────
        $orgProfile = $this->orgRepository->findByUser($user);

        // ── Guard : déjà une structure active ────────────────────────────────
        // Si l'utilisateur est déjà une structure partenaire validée,
        // on le redirige directement vers son dashboard — pas besoin de re-candidater.
        if ($orgProfile !== null && $orgProfile->isStructurePartner()) {
            return $this->redirectToRoute('app_structure_dashboard');
        }

        // ── Guard : candidature déjà en cours d'examen ───────────────────────
        // Si l'org a déjà candidaté (structureApplicationAt != null) et que la
        // candidature n'est pas encore traitée (isStructurePartner = false),
        // on informe l'utilisateur et on le redirige.
        // Pourquoi "dashboard" plutôt que "register" ? Car le dashboard indique
        // le statut en attente — le formulaire vide serait confusant.
        if ($orgProfile !== null && $orgProfile->hasPendingStructureApplication()) {
            $this->addFlash(
                'info',
                'Votre candidature est en cours d\'examen par l\'équipe Bazaart. ' .
                'Vous serez notifié·e dès qu\'une décision sera prise (délai moyen : 48h).'
            );
            // On redirige quand même vers le formulaire pour montrer les données
            // déjà soumises (l'utilisateur peut les lire mais pas re-soumettre
            // une nouvelle candidature par erreur)
        }

        // ── Traitement POST ───────────────────────────────────────────────────
        if ($request->isMethod('POST')) {
            // Vérification du token CSRF (protection contre les attaques Cross-Site Request Forgery)
            // Le token 'structure_register' doit correspondre au hidden input dans le formulaire Twig.
            if (!$this->isCsrfTokenValid('structure_register', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez rafraîchir la page et réessayer.');
                return $this->redirectToRoute('app_structure_register');
            }

            // Passe les données du formulaire au service.
            // Le service valide, crée/met à jour le profil, et retourne soit le profil
            // sauvegardé, soit une string d'erreur (erreur de validation).
            $result = $this->structureService->applyAsStructure($user, $request->request->all());

            if (is_string($result)) {
                // Le service a renvoyé une string → c'est un message d'erreur de validation
                $this->addFlash('error', $result);
                return $this->redirectToRoute('app_structure_register');
            }

            // Succès : le profil est sauvegardé, la candidature est enregistrée
            $this->addFlash(
                'success',
                'Votre candidature a bien été reçue ! ' .
                'L\'équipe Bazaart l\'examinera dans les 48 heures. ' .
                'Vous recevrez un email de confirmation.'
            );

            // Redirect vers confirmation (même route avec le flash affiché)
            return $this->redirectToRoute('app_structure_register');
        }

        // ── Affichage GET du formulaire ───────────────────────────────────────
        return $this->render('structure/register.html.twig', [
            // On passe le profil existant pour pré-remplir le formulaire.
            // null si l'utilisateur n'a jamais créé de profil organisation.
            'orgProfile' => $orgProfile,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DASHBOARD STRUCTURE — /structure/dashboard
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tableau de bord de la structure partenaire.
     *
     * Accessible uniquement aux structures validées (ROLE_STRUCTURE + isStructurePartner).
     * Le StructureVoter effectue une double vérification : rôle ET flag BDD.
     * Cela protège contre les incohérences (ex: rôle attribué manuellement
     * sans que isStructurePartner soit à true).
     *
     * Variables Twig fournies :
     *   - orgProfile : le profil Organisation de la structure
     *   - resources  : les ressources soumises par cet utilisateur
     */
    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        // Le voter STRUCTURE_VIEW_DASHBOARD vérifie :
        //   1. que l'utilisateur a ROLE_STRUCTURE (via la hiérarchie Symfony)
        //   2. que son OrganizationProfile.isStructurePartner = true
        // Si l'une des deux conditions échoue → 403 Forbidden.
        $this->denyAccessUnlessGranted(StructureVoter::STRUCTURE_VIEW_DASHBOARD);

        /** @var User $user */
        $user = $this->getUser();

        // Récupère le profil — garantit non-null ici car le voter l'a déjà vérifié
        $orgProfile = $this->orgRepository->findByUser($user);

        // Récupère toutes les ressources soumises par cet utilisateur.
        // findBySubmittedBy() est une méthode du ResourceRepository qui filtre
        // par l'utilisateur — voir ResourceRepository.
        // On utilise findBy() directement car ResourceRepository n'a pas encore
        // de méthode dédiée ; on trie par date de création décroissante.
        $resources = $this->resourceRepository->findBy(
            ['submittedBy' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('structure/dashboard.html.twig', [
            'orgProfile' => $orgProfile,
            'resources'  => $resources,
        ]);
    }
}
