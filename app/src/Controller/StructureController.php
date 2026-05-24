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
        // on laisse le code continuer vers le render() sans ajouter de flash.
        //
        // Pourquoi ne pas ajouter de flash ici ?
        // Le template register.html.twig détecte `alreadyApplied = true` et
        // affiche directement un écran de statut "Demande envoyée" avec son propre
        // texte explicatif. Ajouter un addFlash('info') ici provoquerait un
        // double message identique (flash global dans base_app + texte du template).
        if ($orgProfile !== null && $orgProfile->hasPendingStructureApplication()) {
            // On laisse délibérément passer — le template gère l'affichage du statut.
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

        // Calcul : l'utilisateur a-t-il déjà soumis une candidature ?
        // Une candidature existe si le profil org existe ET que structureApplicationAt est renseigné.
        $alreadyApplied = $orgProfile !== null && $orgProfile->getStructureApplicationAt() !== null;

        // Calcul du statut de la candidature, utilisé dans le template pour
        // afficher le bon message (en attente, approuvée, etc.).
        $applicationStatus = null;
        if ($alreadyApplied) {
            if ($orgProfile->isStructurePartner()) {
                // isStructurePartner() = true → la structure a été validée par l'admin.
                $applicationStatus = 'approved';
            } elseif ($orgProfile->getStructureActivatedAt() !== null) {
                // Double garde : activatedAt renseigné sans isStructurePartner → considéré approuvé.
                $applicationStatus = 'approved';
            } else {
                // La candidature existe (applicationAt renseigné) mais n'est pas encore validée.
                // En V1 il n'existe pas de champ "rejected" sur l'entité :
                // une structure refusée reste techniquement "pending" côté BDD.
                // Ce cas est géré côté admin (message direct à la structure).
                $applicationStatus = 'pending';
            }
        }

        return $this->render('structure/register.html.twig', [
            // Profil existant pour pré-remplir les champs du formulaire.
            // null si l'utilisateur n'a jamais créé de profil organisation.
            'orgProfile'        => $orgProfile,
            // Booléen : true si une candidature a déjà été soumise.
            'alreadyApplied'    => $alreadyApplied,
            // String : 'pending' | 'approved' | null (null si aucune candidature).
            'applicationStatus' => $applicationStatus,
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

        // Récupère le profil — en théorie non-null car le voter l'a déjà vérifié,
        // mais on se protège en cas d'incohérence de données (ex : rôle attribué
        // manuellement sans que le profil OrganizationProfile ait été créé).
        $orgProfile = $this->orgRepository->findByUser($user);

        // Garde de sécurité : si le profil est introuvable malgré le voter,
        // on évite un crash Twig et on informe l'utilisateur.
        if ($orgProfile === null) {
            $this->addFlash('error', 'Profil structure introuvable. Contactez l\'administrateur.');
            return $this->redirectToRoute('app_dashboard');
        }

        // Récupère toutes les ressources soumises par cet utilisateur.
        //
        // On utilise findByUser() du ResourceRepository plutôt que findBy() natif
        // de Doctrine, car findByUser() fait un leftJoin sur resourceType avec
        // addSelect('rt') : cela charge la relation en une seule requête SQL
        // (eager loading) et évite le problème N+1 dans le template Twig.
        //
        // Sans ce join, chaque appel à resource.resourceType.name dans le template
        // déclencherait une requête SQL séparée — coûteux si la structure a
        // soumis de nombreuses ressources.
        $resources = $this->resourceRepository->findByUser($user);

        return $this->render('structure/dashboard.html.twig', [
            'orgProfile' => $orgProfile,
            'resources'  => $resources,
        ]);
    }
}
