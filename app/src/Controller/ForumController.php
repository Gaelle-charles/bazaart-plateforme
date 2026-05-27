<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ForumThread;
use App\Entity\ForumReply;
use App\Entity\User;
use App\Repository\ForumCategoryRepository;
use App\Repository\ForumReplyRepository;
use App\Repository\ForumThreadRepository;
use App\Security\Voter\ForumVoter;
use App\Service\ForumService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ForumController — gère toutes les pages et actions du forum communautaire.
 *
 * Convention Symfony : le controller est "fin" — il ne contient que :
 *   - La récupération des données via les repositories
 *   - L'orchestration des appels au ForumService
 *   - La gestion HTTP (request, response, CSRF, redirects, flash messages)
 *
 * Toute la logique métier (validation, manipulation des entités) est dans ForumService.
 * Toutes les autorisations sont dans ForumVoter.
 *
 * Préfixe de route : /forum (déclaré sur la classe)
 * Toutes les routes ont le nom "app_forum_" + le nom de l'action.
 */
#[IsGranted('ROLE_USER')]
#[Route('/forum', name: 'app_forum_')]
class ForumController extends AbstractController
{
    public function __construct(
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumReplyRepository $replyRepository,
        private readonly ForumService $forumService,
    ) {}

    // ─── Page d'accueil du forum ──────────────────────────────────────────────

    /**
     * Affiche la liste de toutes les catégories actives du forum.
     *
     * Pour chaque catégorie, on charge également les 3 derniers threads
     * pour donner un aperçu de l'activité récente sans afficher tous les threads.
     *
     * Route : GET /forum
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Charge toutes les catégories actives, triées par orderPosition
        $categories = $this->categoryRepository->findAllActive();

        // Pour chaque catégorie, on récupère les derniers threads (aperçu)
        // On construit un tableau associatif : [categoryId => [threads...]]
        $latestThreadsByCategory = [];
        foreach ($categories as $category) {
            $latestThreadsByCategory[$category->getId()] = $this->threadRepository->findLatestByCategory($category, 3);
        }

        return $this->render('forum/index.html.twig', [
            'categories'              => $categories,
            'latestThreadsByCategory' => $latestThreadsByCategory,
        ]);
    }

    // ─── Liste des threads d'une catégorie ────────────────────────────────────

    /**
     * Affiche les threads d'une catégorie avec pagination.
     *
     * Pagination : 20 threads par page.
     * Le paramètre GET ?page=N détermine la page courante (défaut : page 1).
     *
     * Route : GET /forum/{categorySlug}
     */
    #[Route('/{categorySlug}', name: 'category', methods: ['GET'])]
    public function category(string $categorySlug, Request $request): Response
    {
        // Cherche la catégorie par son slug — retourne null si inexistante
        $category = $this->categoryRepository->findBySlug($categorySlug);

        if ($category === null || !$category->isActive()) {
            // 404 si la catégorie n'existe pas ou est désactivée
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        // ── Pagination ────────────────────────────────────────────────────────
        $limit  = 20;
        // max(1, ...) : évite une page 0 ou négative si quelqu'un manipule l'URL
        $page   = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;

        // Charge les threads de cette page
        $threads    = $this->threadRepository->findByCategory($category, $limit, $offset);
        $totalCount = $this->threadRepository->countByCategory($category);
        $totalPages = (int) ceil($totalCount / $limit);

        return $this->render('forum/category.html.twig', [
            'category'   => $category,
            'threads'    => $threads,
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
    }

    // ─── Affichage d'un thread ────────────────────────────────────────────────

    /**
     * Affiche un thread avec toutes ses réponses.
     *
     * Actions réalisées à chaque affichage :
     *   1. Incrémentation du compteur de vues (via ForumService)
     *   2. Chargement de toutes les réponses (ordre chronologique)
     *   3. Calcul des permissions pour afficher/masquer les boutons d'action
     *
     * Route : GET /forum/{categorySlug}/{threadSlug}
     */
    // Note : la contrainte 'requirements' exclut le mot réservé "nouveau" pour éviter
    // que cette route capte GET /forum/{cat}/nouveau avant la route new_thread.
    // Sans cette contrainte, "nouveau" serait interprété comme un threadSlug → 404.
    #[Route('/{categorySlug}/{threadSlug}', name: 'thread', methods: ['GET'], requirements: ['threadSlug' => '(?!nouveau$).+'])]
    public function thread(string $categorySlug, string $threadSlug): Response
    {
        // ── Récupération des entités ──────────────────────────────────────────

        $category = $this->categoryRepository->findBySlug($categorySlug);
        if ($category === null || !$category->isActive()) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        $thread = $this->threadRepository->findBySlugAndCategory($threadSlug, $category);
        if ($thread === null) {
            throw $this->createNotFoundException('Sujet introuvable.');
        }

        // ── Incrémentation des vues ───────────────────────────────────────────
        // Chaque affichage de la page compte comme une vue.
        // Note : pas de déduplication par user/session en V1.
        $this->forumService->incrementViews($thread);

        // ── Réponses ──────────────────────────────────────────────────────────
        $replies = $this->replyRepository->findByThread($thread);

        // ── Permissions pour le template ──────────────────────────────────────
        // $canReply : l'utilisateur peut-il répondre ?
        //   → Oui si le thread n'est pas verrouillé, OU si l'utilisateur est admin/modo
        $canReply = !$thread->isLocked() || $this->isGranted('ROLE_MODERATOR');

        // $canModerate : l'utilisateur peut-il modérer ce thread (lock, pin, delete) ?
        //   → Délégué au ForumVoter pour cohérence avec le reste du système d'auth
        $canModerate = $this->isGranted(ForumVoter::FORUM_MODERATE, $thread);

        return $this->render('forum/thread.html.twig', [
            'category'    => $category,
            'thread'      => $thread,
            'replies'     => $replies,
            'canReply'    => $canReply,
            'canModerate' => $canModerate,
        ]);
    }

    // ─── Créer un nouveau thread ──────────────────────────────────────────────

    /**
     * Formulaire de création d'un nouveau thread dans une catégorie.
     *
     * GET  : affiche le formulaire vide
     * POST : traite la soumission, crée le thread, redirige vers le thread créé
     *
     * La vérification ROLE_USER est faite au niveau de la classe (#[IsGranted]).
     *
     * Route : GET/POST /forum/{categorySlug}/nouveau
     */
    #[Route('/{categorySlug}/nouveau', name: 'new_thread', methods: ['GET', 'POST'])]
    public function newThread(string $categorySlug, Request $request): Response
    {
        $category = $this->categoryRepository->findBySlug($categorySlug);
        if ($category === null || !$category->isActive()) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        if ($request->isMethod('POST')) {
            // ── Vérification CSRF ─────────────────────────────────────────────
            // Le token CSRF protège contre les attaques Cross-Site Request Forgery.
            // Le token 'forum_new_thread' doit correspondre au champ caché dans le formulaire.
            if (!$this->isCsrfTokenValid('forum_new_thread', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_forum_new_thread', ['categorySlug' => $categorySlug]);
            }

            // ── Récupération de l'utilisateur connecté ────────────────────────
            /** @var User $user */
            $user = $this->getUser();

            // ── Délégation au service ─────────────────────────────────────────
            $result = $this->forumService->createThread($user, $category, $request->request->all());

            if (is_string($result)) {
                // Le service a retourné un message d'erreur (string = erreur)
                $this->addFlash('error', $result);
                return $this->render('forum/new_thread.html.twig', [
                    'category' => $category,
                    // On repasse les données pour pré-remplir le formulaire
                    'formData' => $request->request->all(),
                ]);
            }

            // $result est un ForumThread : succès
            $this->addFlash('success', 'Votre sujet a été publié avec succès !');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $category->getSlug(),
                'threadSlug'   => $result->getSlug(),
            ]);
        }

        // Affichage du formulaire vide (méthode GET)
        return $this->render('forum/new_thread.html.twig', [
            'category' => $category,
            'formData' => [],
        ]);
    }

    // ─── Poster une réponse ───────────────────────────────────────────────────

    /**
     * Ajoute une réponse à un thread.
     *
     * Vérifie que le thread n'est pas verrouillé (sauf pour les modérateurs).
     * Redirige vers le thread avec une ancre vers la nouvelle réponse.
     *
     * Route : POST /forum/thread/{id}/reply
     */
    #[Route('/thread/{id}/reply', name: 'reply', methods: ['POST'])]
    public function reply(ForumThread $thread, Request $request): Response
    {
        // ── Vérification du verrouillage ──────────────────────────────────────
        // Un thread verrouillé n'accepte plus de réponses, sauf des modérateurs.
        if ($thread->isLocked() && !$this->isGranted('ROLE_MODERATOR')) {
            $this->addFlash('error', 'Ce sujet est verrouillé. Les nouvelles réponses ne sont pas autorisées.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        // ── Vérification CSRF ─────────────────────────────────────────────────
        // Le token contient l'ID du thread pour être unique par thread.
        if (!$this->isCsrfTokenValid('forum_reply_' . $thread->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        /** @var User $user */
        $user = $this->getUser();

        $result = $this->forumService->addReply($user, $thread, $request->request->all());

        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
                '#'            => 'reply-form',
            ]);
        }

        // Redirection vers le thread avec ancre vers la nouvelle réponse
        // L'ancre #reply-{id} correspond à l'attribut id="reply-{id}" dans le template
        return $this->redirect(
            $this->generateUrl('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]) . '#reply-' . $result->getId()
        );
    }

    // ─── Actions de modération ────────────────────────────────────────────────

    /**
     * Verrouille ou déverrouille un thread (toggle).
     * Réservé aux admins et modérateurs (vérifié par ForumVoter::FORUM_LOCK).
     *
     * Route : POST /forum/thread/{id}/lock
     */
    #[Route('/thread/{id}/lock', name: 'lock', methods: ['POST'])]
    public function lock(ForumThread $thread, Request $request): Response
    {
        // ── CSRF en premier, autorisation ensuite ──────────────────────────────
        // L'ordre est intentionnel : si on vérifiait l'autorisation en premier,
        // un attaquant pourrait distinguer "403 = utilisateur non autorisé" de
        // "token invalide = token manquant" et inférer les rôles de ses cibles.
        // En vérifiant le CSRF d'abord, les deux cas retournent le même comportement.
        if (!$this->isCsrfTokenValid('forum_lock_' . $thread->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        // denyAccessUnlessGranted appelle le ForumVoter et lève une AccessDeniedException
        // (→ 403 Forbidden) si l'utilisateur n'a pas le droit de verrouiller.
        $this->denyAccessUnlessGranted(ForumVoter::FORUM_LOCK, $thread);

        $isNowLocked = $this->forumService->toggleLock($thread);

        // Message flash selon le nouvel état
        $message = $isNowLocked
            ? 'Sujet verrouillé. Les membres ne peuvent plus répondre.'
            : 'Sujet déverrouillé. Les membres peuvent à nouveau répondre.';
        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_forum_thread', [
            'categorySlug' => $thread->getCategory()->getSlug(),
            'threadSlug'   => $thread->getSlug(),
        ]);
    }

    /**
     * Épingle ou désépingle un thread en haut de sa catégorie.
     * Réservé aux admins uniquement (vérifié par ForumVoter::FORUM_PIN).
     *
     * Route : POST /forum/thread/{id}/pin
     */
    #[Route('/thread/{id}/pin', name: 'pin', methods: ['POST'])]
    public function pin(ForumThread $thread, Request $request): Response
    {
        // CSRF avant autorisation (même raison que lock() ci-dessus)
        if (!$this->isCsrfTokenValid('forum_pin_' . $thread->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        $this->denyAccessUnlessGranted(ForumVoter::FORUM_PIN, $thread);

        $isNowPinned = $this->forumService->togglePin($thread);

        $message = $isNowPinned
            ? 'Sujet épinglé. Il apparaîtra en tête de la catégorie.'
            : 'Sujet désépinglé.';
        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_forum_thread', [
            'categorySlug' => $thread->getCategory()->getSlug(),
            'threadSlug'   => $thread->getSlug(),
        ]);
    }

    /**
     * Supprime un thread (et toutes ses réponses en cascade).
     * Autorisé à l'auteur, aux admins et modérateurs (ForumVoter::FORUM_DELETE).
     *
     * Route : POST /forum/thread/{id}/delete
     */
    #[Route('/thread/{id}/delete', name: 'delete_thread', methods: ['POST'])]
    public function deleteThread(ForumThread $thread, Request $request): Response
    {
        // CSRF avant autorisation (même raison que lock() ci-dessus)
        if (!$this->isCsrfTokenValid('forum_delete_thread_' . $thread->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        $this->denyAccessUnlessGranted(ForumVoter::FORUM_DELETE, $thread);

        // On sauvegarde le slug de la catégorie AVANT la suppression du thread
        // car après remove + flush, l'entité n'est plus disponible
        $categorySlug = $thread->getCategory()->getSlug();

        $this->forumService->deleteThread($thread);
        $this->addFlash('success', 'Le sujet a été supprimé.');

        // Redirection vers la catégorie (le thread n'existe plus)
        return $this->redirectToRoute('app_forum_category', ['categorySlug' => $categorySlug]);
    }

    // ─── Signalement d'un thread ──────────────────────────────────────────────

    /**
     * Signale un thread inapproprié à l'équipe de modération.
     *
     * Correction 3 — fonctionnalité CDC manquante.
     *
     * Règles métier :
     *   - Seuls les utilisateurs authentifiés peuvent signaler (IS_AUTHENTICATED_FULLY).
     *   - Un utilisateur ne peut pas signaler son propre thread (anti-spam).
     *   - Le signalement envoie un email à l'admin via ForumService::reportThread().
     *   - Pas de persistance en BDD en V1 (pas de migration nécessaire).
     *
     * Sécurité :
     *   - Token CSRF unique par thread : "report_{thread.id}"
     *   - IS_AUTHENTICATED_FULLY vérifié par #[IsGranted] au niveau de la classe
     *     (tous les utilisateurs connectés via la déclaration sur ForumController)
     *
     * Route : POST /forum/thread/{id}/report (name: app_forum_report)
     */
    #[Route('/thread/{id}/report', name: 'report', methods: ['POST'])]
    public function report(ForumThread $thread, Request $request): Response
    {
        // ── Vérification CSRF ─────────────────────────────────────────────────
        // Token unique par thread pour éviter le rejeu entre différents threads.
        if (!$this->isCsrfTokenValid('report_' . $thread->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        /** @var User $user */
        $user = $this->getUser();

        // ── Anti-spam : on ne peut pas signaler son propre thread ─────────────
        // Comparer les instances d'entité Doctrine est correct ici car elles sont
        // toutes les deux dans le même EntityManager et donc potentiellement identiques.
        // On compare les IDs pour être certain (évite les problèmes de proxy lazy).
        if ($thread->getAuthor()->getId() === $user->getId()) {
            $this->addFlash('warning', 'Vous ne pouvez pas signaler votre propre sujet.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $thread->getCategory()->getSlug(),
                'threadSlug'   => $thread->getSlug(),
            ]);
        }

        // ── Délégation au service (toute la logique d'envoi d'email est là-bas) ──
        $this->forumService->reportThread($thread, $user);

        $this->addFlash('success', 'Votre signalement a bien été transmis à l\'équipe de modération. Merci.');

        return $this->redirectToRoute('app_forum_thread', [
            'categorySlug' => $thread->getCategory()->getSlug(),
            'threadSlug'   => $thread->getSlug(),
        ]);
    }

    /**
     * Supprime une réponse (et décrémente le compteur du thread).
     * Autorisé à l'auteur, aux admins et modérateurs (ForumVoter::FORUM_DELETE).
     *
     * Route : POST /forum/reply/{id}/delete
     */
    #[Route('/reply/{id}/delete', name: 'delete_reply', methods: ['POST'])]
    public function deleteReply(ForumReply $reply, Request $request): Response
    {
        // CSRF avant autorisation (même raison que lock() ci-dessus)
        if (!$this->isCsrfTokenValid('forum_delete_reply_' . $reply->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $reply->getThread()->getCategory()->getSlug(),
                'threadSlug'   => $reply->getThread()->getSlug(),
            ]);
        }

        $this->denyAccessUnlessGranted(ForumVoter::FORUM_DELETE, $reply);

        // On sauvegarde les infos de navigation AVANT la suppression
        $threadSlug   = $reply->getThread()->getSlug();
        $categorySlug = $reply->getThread()->getCategory()->getSlug();

        $this->forumService->deleteReply($reply);
        $this->addFlash('success', 'La réponse a été supprimée.');

        // Redirection vers le thread avec l'ancre #replies pour revenir à la liste des réponses
        return $this->redirect(
            $this->generateUrl('app_forum_thread', [
                'categorySlug' => $categorySlug,
                'threadSlug'   => $threadSlug,
            ]) . '#replies'
        );
    }
}
