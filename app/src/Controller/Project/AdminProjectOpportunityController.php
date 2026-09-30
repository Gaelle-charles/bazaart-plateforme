<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\AssociationProfile;
use App\Entity\Resource;
use App\Enum\BazaartAssociation;
use App\Enum\OpportunityReviewStatus;
use App\Repository\DisciplineRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\Project\AssociationProfileService;
use App\Service\Project\ProjectOpportunityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminProjectOpportunityController — onglet « Opportunités » de l'Espace projets (ADR-0038).
 *
 * Réservé aux membres de l'Espace projets (ROLE_PROJECT, via ProjectVoter::ACCESS) :
 * les autres personnes connectées (artistes, structures…) n'y ont pas accès.
 *
 *   GET  /admin/projets/opportunites                    liste (?asso=guadeloupe|paris&vue=…)
 *   POST /admin/projets/opportunites/{id}/decision      retenir / écarter / remettre à étudier
 *   POST /admin/projets/opportunites/{id}/candidater    crée le projet de candidature
 *   GET|POST /admin/projets/opportunites/associations/{asso}  fiche d'une association
 *                                                       (critères du tri + identité)
 */
#[Route('/admin/projets/opportunites', name: 'app_admin_pm_')]
#[IsGranted(ProjectVoter::ACCESS)]
class AdminProjectOpportunityController extends AbstractController
{
    use ProjectControllerTrait;

    public function __construct(
        private readonly ProjectOpportunityService $opportunityService,
        private readonly AssociationProfileService $profileService,
        private readonly DisciplineRepository $disciplineRepository,
    ) {}

    #[Route('', name: 'opportunities', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view = (string) $request->query->get('vue', 'a-etudier');
        if (!in_array($view, ProjectOpportunityService::VIEWS, true)) {
            $view = 'a-etudier';
        }
        $association = BazaartAssociation::tryFrom((string) $request->query->get('asso', ''));

        $listing = $this->opportunityService->listing($view, $association);

        return $this->render('admin/projects/opportunities.html.twig', [
            'rows'         => $listing['rows'],
            'counts'       => $listing['counts'],
            'view'         => $view,
            'association'  => $association,
            'associations' => BazaartAssociation::cases(),
            'profiles'     => $this->profileService->getProfiles(),
        ]);
    }

    /**
     * Fiche d'une association : identité (recopiée dans les candidatures) et
     * critères qui affinent le tri des opportunités.
     */
    #[Route('/associations/{asso}', name: 'association_profile', requirements: ['asso' => 'guadeloupe|paris'], methods: ['GET', 'POST'])]
    public function profile(string $asso, Request $request): Response
    {
        $association = BazaartAssociation::from($asso);
        $profile     = $this->profileService->getProfile($association);
        $errors      = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pm_association_' . $asso, (string) $request->request->get('_token'))) {
                $this->flashInvalidToken();

                return $this->redirectToRoute('app_admin_pm_association_profile', ['asso' => $asso]);
            }
            $errors = $this->profileService->updateFromRequest($profile, $request, $this->currentUser());
            if ($errors === []) {
                $this->addFlash('success', sprintf('Fiche %s enregistrée : le tri des opportunités en tient compte.', $association->label()));

                return $this->redirectToRoute('app_admin_pm_association_profile', ['asso' => $asso]);
            }
        }

        // Aperçu : combien d'opportunités ouvertes correspondent avec la fiche actuelle.
        $preview = $this->opportunityService->listing('a-etudier', $association);

        return $this->render('admin/projects/association_profile.html.twig', [
            'profile'      => $profile,
            'association'  => $association,
            'associations' => BazaartAssociation::cases(),
            'disciplines'  => $this->disciplineRepository->findAllOrdered(),
            'types'        => AssociationProfile::OPPORTUNITY_TYPES,
            'errors'       => $errors,
            'matching'     => $preview['counts']['a-etudier'],
            // Valeurs du formulaire refusé (pour ne pas perdre la saisie) ou de la fiche.
            'form'         => $errors !== [] ? $request->request->all() : null,
        ]);
    }

    /** Champ « decision » : retenue | ecartee | a-etudier (supprime la décision). */
    #[Route('/{id}/decision', name: 'opportunity_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(Resource $resource, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_opportunity_' . $resource->getId(), (string) $request->request->get('_token'))) {
            $this->flashInvalidToken();

            return $this->redirectBack($request, 'app_admin_pm_opportunities');
        }

        $decision = (string) $request->request->get('decision', '');
        if ($decision === 'a-etudier') {
            $this->opportunityService->reset($resource);
            $this->addFlash('success', 'Opportunité remise « à étudier ».');
        } else {
            // Candidater passe par l'action dédiée (création du projet) : pas ici.
            $status = OpportunityReviewStatus::tryFrom($decision);
            if ($status === null || $status === OpportunityReviewStatus::Applying) {
                $this->addFlash('error', 'Décision inconnue.');

                return $this->redirectBack($request, 'app_admin_pm_opportunities');
            }
            $this->opportunityService->decide($resource, $status, $this->currentUser());
            $this->addFlash('success', $status === OpportunityReviewStatus::Shortlisted
                ? sprintf('« %s » retenue : retrouve-la dans « Retenues ».', $resource->getTitle())
                : sprintf('« %s » écartée.', $resource->getTitle()));
        }

        return $this->redirectBack($request, 'app_admin_pm_opportunities');
    }

    /** Crée le projet « Candidature » (tâches planifiées à rebours depuis la date limite). */
    #[Route('/{id}/candidater', name: 'opportunity_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(Resource $resource, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_opportunity_' . $resource->getId(), (string) $request->request->get('_token'))) {
            $this->flashInvalidToken();

            return $this->redirectBack($request, 'app_admin_pm_opportunities');
        }

        $association = BazaartAssociation::tryFrom((string) $request->request->get('association', ''));
        if ($association === null) {
            $this->addFlash('error', 'Choisis l\'association qui candidate.');

            return $this->redirectBack($request, 'app_admin_pm_opportunities');
        }

        $project = $this->opportunityService->apply($resource, $association, $this->currentUser());
        $this->addFlash('success', sprintf('Projet « %s » prêt : les étapes de la candidature sont planifiées avant la date limite.', $project->getName()));

        return $this->redirectToRoute('app_admin_pm_project_show', ['id' => $project->getId()]);
    }
}
