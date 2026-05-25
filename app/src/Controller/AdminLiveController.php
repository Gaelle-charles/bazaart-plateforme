<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Live;
use App\Entity\User;
use App\Enum\LiveStatus;
use App\Repository\LiveRepository;
use App\Repository\UserRepository;
use App\Service\LiveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminLiveController — interface d'administration des lives planifiés.
 *
 * Toutes les routes sont protégées par ROLE_ADMIN (déclaré sur la classe).
 * Préfixe de route : /admin/lives (name: 'app_admin_live_')
 *
 * Ce controller gère :
 *   - La liste admin de tous les lives (tous statuts)
 *   - La création d'un nouveau live
 *   - L'édition d'un live (titre, description, URL, replay, statut, hôte)
 *   - L'annulation d'un live planifié (notifie les inscrits)
 *   - La suppression d'un live (uniquement SCHEDULED)
 *
 * Convention Bazaart : le controller est "fin" → logique dans LiveService.
 */
#[Route('/admin/lives', name: 'app_admin_live_')]
#[IsGranted('ROLE_ADMIN')]
class AdminLiveController extends AbstractController
{
    public function __construct(
        private readonly LiveService    $liveService,
        private readonly LiveRepository $liveRepository,
        private readonly UserRepository $userRepository,
    ) {}

    // ─── Liste admin ──────────────────────────────────────────────────────────

    /**
     * GET /admin/lives — Liste tous les lives (tous statuts, triés par date DESC).
     *
     * L'admin voit les lives SCHEDULED, LIVE, ENDED et CANCELLED pour avoir
     * une vue complète de l'historique.
     *
     * Route : app_admin_live_index
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $lives = $this->liveRepository->findAllForAdmin();

        return $this->render('admin/live/index.html.twig', [
            'lives' => $lives,
        ]);
    }

    // ─── Création ─────────────────────────────────────────────────────────────

    /**
     * GET|POST /admin/lives/new — Formulaire de création d'un nouveau live.
     *
     * GET  → affiche le formulaire vide
     * POST → valide les données, crée le live, redirige vers la liste
     *
     * Route : app_admin_live_new
     *
     * Note : on n'utilise pas les Symfony Forms ici (Bazaart V1 utilise des
     * formulaires HTML simples avec validation PHP manuelle dans les controllers,
     * déléguant la logique au service). Cette approche est cohérente avec
     * AdminCourseController et evite la verbosité des FormType pour des formulaires simples.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // Charge la liste des utilisateurs pour le sélecteur "hôte"
        // En V1 : l'admin peut désigner n'importe quel utilisateur comme hôte
        $users = $this->userRepository->findBy([], ['email' => 'ASC']);

        // Gestion de la soumission du formulaire (méthode POST)
        if ($request->isMethod('POST')) {
            // Vérification du token CSRF
            if (!$this->isCsrfTokenValid('admin_live_new', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_admin_live_new');
            }

            // ── Validation simple des données requises ───────────────────────
            $title       = trim((string) $request->request->get('title', ''));
            $externalUrl = trim((string) $request->request->get('externalUrl', ''));
            $scheduledAt = $request->request->get('scheduledAt', '');
            $hostId      = (int) $request->request->get('hostId', 0);

            $errors = [];

            if (strlen($title) < 5 || strlen($title) > 255) {
                $errors[] = 'Le titre doit faire entre 5 et 255 caractères.';
            }
            if (empty($externalUrl) || !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Veuillez renseigner une URL valide pour le lien du live.';
            }
            if (empty($scheduledAt)) {
                $errors[] = 'La date et l\'heure du live sont obligatoires.';
            }
            if ($hostId <= 0) {
                $errors[] = 'Veuillez sélectionner un hôte pour ce live.';
            }

            // Si des erreurs, on ré-affiche le formulaire avec les messages d'erreur
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('admin/live/form.html.twig', [
                    'live'  => null,
                    'users' => $users,
                    'statuses' => LiveStatus::cases(),
                ]);
            }

            // Récupère l'hôte depuis la BDD
            $host = $this->userRepository->find($hostId);
            if ($host === null) {
                $this->addFlash('error', 'Hôte introuvable.');
                return $this->redirectToRoute('app_admin_live_new');
            }

            // Conversion de la chaîne datetime-local en objet DateTime
            try {
                $scheduledAtDate = new \DateTime($scheduledAt);
            } catch (\Exception) {
                $this->addFlash('error', 'Format de date invalide.');
                return $this->redirectToRoute('app_admin_live_new');
            }

            // ── Validation des URLs optionnelles ────────────────────────────
            // replayUrl et coverImageUrl sont optionnelles, mais si elles sont
            // renseignées, elles doivent être des URLs valides (même logique que externalUrl).
            $replayUrl     = trim((string) $request->request->get('replayUrl', '')) ?: null;
            $coverImageUrl = trim((string) $request->request->get('coverImageUrl', '')) ?: null;

            if ($replayUrl !== null && !filter_var($replayUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Le lien replay doit être une URL valide (ex : https://youtube.com/...).';
            }
            if ($coverImageUrl !== null && !filter_var($coverImageUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'L\'image de couverture doit être une URL valide (ex : https://...).';
            }

            // Re-vérifie les erreurs après validation des URLs optionnelles
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('admin/live/form.html.twig', [
                    'live'  => null,
                    'users' => $users,
                    'statuses' => LiveStatus::cases(),
                ]);
            }

            // Construit le tableau de données pour le service
            $data = [
                'title'        => $title,
                'description'  => trim((string) $request->request->get('description', '')) ?: null,
                'scheduledAt'  => $scheduledAtDate,
                'externalUrl'  => $externalUrl,
                'replayUrl'    => $replayUrl,
                'coverImageUrl' => $coverImageUrl,
            ];

            // Délègue la création au service (logique métier)
            $live = $this->liveService->createLive($data, $host);

            $this->addFlash('success', sprintf('Live "%s" créé avec succès.', $live->getTitle()));
            return $this->redirectToRoute('app_admin_live_index');
        }

        // GET : affiche le formulaire vide
        return $this->render('admin/live/form.html.twig', [
            'live'     => null,
            'users'    => $users,
            'statuses' => LiveStatus::cases(),
        ]);
    }

    // ─── Édition ──────────────────────────────────────────────────────────────

    /**
     * GET|POST /admin/lives/{id}/edit — Formulaire d'édition d'un live.
     *
     * Permet de modifier tous les champs : titre, description, URL, replay,
     * couverture, statut, hôte. Utile pour mettre à jour le replay après
     * la fin d'un live, ou pour changer le statut de SCHEDULED à LIVE.
     *
     * Route : app_admin_live_edit
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Live $live, Request $request): Response
    {
        $users = $this->userRepository->findBy([], ['email' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_live_edit_' . $live->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_admin_live_edit', ['id' => $live->getId()]);
            }

            $title       = trim((string) $request->request->get('title', ''));
            $externalUrl = trim((string) $request->request->get('externalUrl', ''));
            $scheduledAt = $request->request->get('scheduledAt', '');
            $hostId      = (int) $request->request->get('hostId', 0);
            $statusValue = $request->request->get('status', '');

            $errors = [];

            if (strlen($title) < 5 || strlen($title) > 255) {
                $errors[] = 'Le titre doit faire entre 5 et 255 caractères.';
            }
            if (empty($externalUrl) || !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Veuillez renseigner une URL valide pour le lien du live.';
            }
            if (empty($scheduledAt)) {
                $errors[] = 'La date et l\'heure du live sont obligatoires.';
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('admin/live/form.html.twig', [
                    'live'     => $live,
                    'users'    => $users,
                    'statuses' => LiveStatus::cases(),
                ]);
            }

            // Conversion de la date
            try {
                $scheduledAtDate = new \DateTime($scheduledAt);
            } catch (\Exception) {
                $this->addFlash('error', 'Format de date invalide.');
                return $this->redirectToRoute('app_admin_live_edit', ['id' => $live->getId()]);
            }

            // Résolution du statut depuis la valeur string du formulaire
            $newStatus = LiveStatus::tryFrom($statusValue) ?? $live->getStatus();

            // ── Validation des URLs optionnelles (même logique que dans new()) ──
            $replayUrl     = trim((string) $request->request->get('replayUrl', '')) ?: null;
            $coverImageUrl = trim((string) $request->request->get('coverImageUrl', '')) ?: null;

            if ($replayUrl !== null && !filter_var($replayUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Le lien replay doit être une URL valide (ex : https://youtube.com/...).';
            }
            if ($coverImageUrl !== null && !filter_var($coverImageUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'L\'image de couverture doit être une URL valide (ex : https://...).';
            }

            // Re-vérifie les erreurs après validation des URLs optionnelles
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('admin/live/form.html.twig', [
                    'live'     => $live,
                    'users'    => $users,
                    'statuses' => LiveStatus::cases(),
                ]);
            }

            // Construction du tableau de mise à jour
            $data = [
                'title'        => $title,
                'description'  => trim((string) $request->request->get('description', '')) ?: null,
                'scheduledAt'  => $scheduledAtDate,
                'externalUrl'  => $externalUrl,
                'replayUrl'    => $replayUrl,
                'coverImageUrl' => $coverImageUrl,
                'status'       => $newStatus,
            ];

            // Hôte — optionnel en édition (on garde l'hôte existant si non fourni)
            if ($hostId > 0) {
                $host = $this->userRepository->find($hostId);
                if ($host !== null) {
                    $data['hostedBy'] = $host;
                }
            }

            $this->liveService->updateLive($live, $data);

            $this->addFlash('success', sprintf('Live "%s" mis à jour.', $live->getTitle()));
            return $this->redirectToRoute('app_admin_live_index');
        }

        // GET : affiche le formulaire pré-rempli
        return $this->render('admin/live/form.html.twig', [
            'live'     => $live,
            'users'    => $users,
            'statuses' => LiveStatus::cases(),
        ]);
    }

    // ─── Annulation ───────────────────────────────────────────────────────────

    /**
     * POST /admin/lives/{id}/cancel — Annule un live et notifie les inscrits.
     *
     * Le live passe en statut CANCELLED. Tous les inscrits reçoivent un email.
     * Cette action est irréversible (pour revenir, il faut créer un nouveau live).
     *
     * Protection CSRF : obligatoire pour toutes les actions destructives.
     *
     * Route : app_admin_live_cancel
     */
    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Live $live, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_live_cancel_' . $live->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_live_index');
        }

        // On ne peut annuler que les lives SCHEDULED ou LIVE (pas ENDED ou déjà CANCELLED)
        if ($live->getStatus() === LiveStatus::CANCELLED) {
            $this->addFlash('error', 'Ce live est déjà annulé.');
            return $this->redirectToRoute('app_admin_live_index');
        }
        if ($live->getStatus() === LiveStatus::ENDED) {
            $this->addFlash('error', 'Un live terminé ne peut pas être annulé.');
            return $this->redirectToRoute('app_admin_live_index');
        }

        // Délègue au service (qui change le statut ET envoie les emails)
        $this->liveService->cancelLive($live);

        $this->addFlash('success', sprintf(
            'Le live "%s" a été annulé. Les inscrits ont été notifiés.',
            $live->getTitle()
        ));

        return $this->redirectToRoute('app_admin_live_index');
    }

    // ─── Suppression ──────────────────────────────────────────────────────────

    /**
     * POST /admin/lives/{id}/delete — Supprime définitivement un live.
     *
     * La suppression n'est autorisée que pour les lives SCHEDULED.
     * Un live LIVE, ENDED ou CANCELLED doit être annulé, pas supprimé.
     * (Règle métier : conserver la traçabilité des lives passés.)
     *
     * Les LiveAttendee associés sont supprimés en cascade (cascade: ['remove']
     * sur l'entité Live + onDelete: 'CASCADE' sur la FK en BDD).
     *
     * Route : app_admin_live_delete
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Live $live, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_live_delete_' . $live->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_live_index');
        }

        // Guard métier : on ne supprime que les lives pas encore démarrés
        if ($live->getStatus() !== LiveStatus::SCHEDULED) {
            $this->addFlash('error', sprintf(
                'Impossible de supprimer un live en statut "%s". Seuls les lives planifiés peuvent être supprimés.',
                $live->getStatus()->label()
            ));
            return $this->redirectToRoute('app_admin_live_index');
        }

        $title = $live->getTitle();

        // Doctrine supprime automatiquement les LiveAttendee via orphanRemoval + cascade
        $liveRepository = $this->liveRepository;
        $em = $liveRepository->getEntityManager();
        $em->remove($live);
        $em->flush();

        $this->addFlash('success', sprintf('Le live "%s" a été supprimé.', $title));

        return $this->redirectToRoute('app_admin_live_index');
    }
}
