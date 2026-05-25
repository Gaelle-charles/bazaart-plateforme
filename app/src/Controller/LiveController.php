<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Live;
use App\Entity\User;
use App\Security\Voter\LiveVoter;
use App\Service\LiveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * LiveController — pages publiques (zone connectée) du module Lives.
 *
 * Ce controller est "fin" par convention Bazaart :
 *   - Il gère uniquement HTTP (request, response, CSRF, redirects, flash messages)
 *   - Toute la logique métier est dans LiveService
 *   - Les autorisations sont dans LiveVoter
 *
 * Toutes les routes nécessitent d'être connecté (#[IsGranted('ROLE_USER')]).
 * Le calendrier et le détail des lives ne sont pas accessibles aux visiteurs non connectés.
 *
 * Préfixe de route : /lives
 */
#[IsGranted('ROLE_USER')]
#[Route('/lives', name: 'app_live_')]
class LiveController extends AbstractController
{
    public function __construct(
        private readonly LiveService $liveService,
    ) {}

    // ─── Calendrier des lives ─────────────────────────────────────────────────

    /**
     * Affiche la page calendrier : lives à venir + replays disponibles.
     *
     * Route : GET /lives
     * Nom   : app_live_index
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Les prochains lives planifiés (SCHEDULED, triés par date ASC)
        $upcomingLives = $this->liveService->getUpcoming(limit: 20);

        // Les replays des lives terminés (ENDED avec replayUrl, triés par date DESC)
        $pastLives = $this->liveService->getPast(limit: 10);

        return $this->render('live/index.html.twig', [
            'upcomingLives' => $upcomingLives,
            'pastLives'     => $pastLives,
        ]);
    }

    // ─── Détail d'un live ─────────────────────────────────────────────────────

    /**
     * Affiche le détail d'un live : titre, description, date, hôte, bouton inscription.
     *
     * Route : GET /lives/{id}
     * Nom   : app_live_show
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Live $live): Response
    {
        // Vérifie que l'utilisateur peut voir ce live (LiveVoter::LIVE_VIEW).
        // On passe $live (et non null) pour que le voter puisse prendre des décisions
        // contextuelles : ex. un live CANCELLED visible uniquement par l'hôte ou un admin.
        $this->denyAccessUnlessGranted(LiveVoter::LIVE_VIEW, $live);

        // On détermine si l'utilisateur courant est inscrit (pour le bouton)
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $isRegistered = $this->liveService->isUserRegistered($live, $currentUser);

        return $this->render('live/show.html.twig', [
            'live'         => $live,
            'isRegistered' => $isRegistered,
        ]);
    }

    // ─── Inscription à un live ────────────────────────────────────────────────

    /**
     * Inscrit l'utilisateur courant à un live.
     *
     * Route : POST /lives/{id}/attend
     * Nom   : app_live_attend
     *
     * Protection CSRF : le token est envoyé depuis le formulaire Twig (champ hidden).
     * L'inscription est uniquement possible si le live est SCHEDULED.
     */
    #[Route('/{id}/attend', name: 'attend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attend(Live $live, Request $request): Response
    {
        // Vérification du token CSRF — protection contre les attaques cross-site
        // Le token est généré dans le template : {{ csrf_token('live_attend_' ~ live.id) }}
        if (!$this->isCsrfTokenValid('live_attend_' . $live->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_live_show', ['id' => $live->getId()]);
        }

        // Vérifie l'autorisation via LiveVoter (LIVE_REGISTER) — on passe $live pour
        // que le voter puisse vérifier le statut du live (ex: ne pas s'inscrire à un live CANCELLED)
        $this->denyAccessUnlessGranted(LiveVoter::LIVE_REGISTER, $live);

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $this->liveService->registerAttendee($live, $currentUser);
            $this->addFlash('success', sprintf(
                'Vous êtes inscrit(e) au live "%s". Un rappel vous sera envoyé 24h avant.',
                $live->getTitle()
            ));
        } catch (\LogicException $e) {
            // L'exception est levée si le live n'est plus SCHEDULED ou si doublon
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_live_show', ['id' => $live->getId()]);
    }

    // ─── Désinscription d'un live ─────────────────────────────────────────────

    /**
     * Désinscrit l'utilisateur courant d'un live.
     *
     * Route : POST /lives/{id}/unattend
     * Nom   : app_live_unattend
     *
     * Protection CSRF : identique à l'inscription.
     */
    #[Route('/{id}/unattend', name: 'unattend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unattend(Live $live, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('live_unattend_' . $live->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_live_show', ['id' => $live->getId()]);
        }

        $this->denyAccessUnlessGranted(LiveVoter::LIVE_REGISTER, $live);

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // unregisterAttendee est idempotent : pas d'exception si pas inscrit
        $this->liveService->unregisterAttendee($live, $currentUser);

        $this->addFlash('info', sprintf(
            'Vous êtes désinscrit(e) du live "%s".',
            $live->getTitle()
        ));

        return $this->redirectToRoute('app_live_show', ['id' => $live->getId()]);
    }
}
