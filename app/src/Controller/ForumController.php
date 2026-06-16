<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ForumThread;
use App\Entity\ForumReply;
use App\Entity\User;
use App\Enum\ForumReactionType;
use App\Repository\ForumCategoryRepository;
use App\Repository\ForumReactionRepository;
use App\Repository\ForumReplyRepository;
use App\Repository\ForumThreadRepository;
use App\Security\Voter\ForumVoter;
use App\Service\ForumService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        // Pour afficher les compteurs de réactions et les réactions de l'utilisateur
        private readonly ForumReactionRepository $reactionRepository,
    ) {}

    // ─── Page d'accueil du forum ──────────────────────────────────────────────

    /**
     * Affiche la liste de toutes les catégories actives du forum
     * ainsi que le feed des threads récents (toutes catégories confondues).
     *
     * Avant : on chargeait 3 threads par catégorie dans une boucle PHP,
     * puis le template aplatissait tout via `merge` — tri par catégorie,
     * pas chronologique global.
     *
     * Maintenant : on délègue le tri à la BDD via findRecent(20).
     * Le template reçoit `threads` directement — plus besoin de `merge`.
     *
     * Route : GET /forum
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Charge toutes les catégories actives, triées par orderPosition
        // (utilisé pour la colonne gauche et le bouton "Nouveau post")
        $categories = $this->categoryRepository->findAllActive();

        // Charge les 20 threads les plus récents, toutes catégories confondues,
        // triés par updatedAt DESC (activité récente en tête du feed).
        // findRecent() fait un FETCH JOIN sur category et author → pas de N+1.
        $threads = $this->threadRepository->findRecent(20);

        return $this->render('forum/index.html.twig', [
            'categories' => $categories,
            // Variable plate, triée côté BDD — le template l'utilise directement
            'threads'    => $threads,
        ]);
    }

    // ─── Création rapide d'un thread depuis l'index du forum ──────────────────────

    /**
     * Crée un nouveau thread directement depuis le compositeur inline de l'index forum.
     *
     * Cette route est distincte de newThread() : elle n'a pas de {categorySlug}
     * dans l'URL. La catégorie est transmise via le champ POST `categoryId`.
     * Cela évite au JS d'avoir à construire l'URL dynamiquement selon la catégorie
     * choisie — le serveur résout la catégorie depuis l'ID envoyé en POST body.
     *
     * En cas d'erreur de validation : on revient sur l'index avec un flash error.
     * En cas de succès : on redirige vers le thread fraîchement créé.
     *
     * Route : POST /forum/nouveau
     * Placée AVANT /{categorySlug} pour que la résolution de route Symfony
     * priorise la route statique "/nouveau" avant la route paramétrique.
     */
    #[Route('/nouveau', name: 'quick_post', methods: ['POST'])]
    public function quickPost(Request $request): Response
    {
        // ── Validation CSRF ───────────────────────────────────────────────────
        // Même token que newThread() (forum_new_thread) : les deux créent un
        // ForumThread, il est cohérent de partager l'intention du token.
        if (!$this->isCsrfTokenValid('forum_new_thread', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_forum_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        // ── Résolution de la catégorie depuis le POST body ────────────────────
        // Le formulaire inline envoie un <select name="categoryId"> dont la
        // valeur est l'ID de la catégorie (entier). L'ID est plus stable que le
        // slug comme valeur de <option> et plus simple à résoudre côté serveur.
        //
        // On utilise getInt() plutôt que get() + is_numeric() + (int) cast :
        //   - getInt() retourne 0 si le champ est absent, vide, non-numérique
        //     ou un flottant comme "1.5" (contrairement à is_numeric() qui
        //     laisse passer les flottants, (int)"1.5" → 1, comportement implicite).
        //   - Un ID valide est toujours > 0 (auto-increment PostgreSQL).
        //   - Pas de cast supplémentaire nécessaire : le type est déjà int.
        $categoryId = $request->request->getInt('categoryId');

        if ($categoryId <= 0) {
            $this->addFlash('error', 'Catégorie invalide. Veuillez sélectionner une catégorie.');
            return $this->redirectToRoute('app_forum_index');
        }

        $category = $this->categoryRepository->find($categoryId);

        if ($category === null || !$category->isActive()) {
            $this->addFlash('error', 'Catégorie introuvable ou désactivée. Veuillez réessayer.');
            return $this->redirectToRoute('app_forum_index');
        }

        // ── Délégation au service (même logique que newThread()) ─────────────
        // ForumService::createThread() valide le titre/contenu et crée le thread.
        // Il retourne un string (message d'erreur) ou un ForumThread (succès).
        // $request->files->get('image') : fichier image optionnel (null si absent).
        $result = $this->forumService->createThread(
            $user,
            $category,
            $request->request->all(),
            $request->files->get('image'),
        );

        if (is_string($result)) {
            // Erreur de validation (titre vide, contenu trop court, etc.)
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_forum_index');
        }

        // Succès : redirection vers le thread fraîchement créé
        $this->addFlash('success', 'Votre sujet a été publié avec succès !');
        return $this->redirectToRoute('app_forum_thread', [
            'categorySlug' => $category->getSlug(),
            'threadSlug'   => $result->getSlug(),
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

        // ── Réactions emoji ───────────────────────────────────────────────────
        // On charge en BATCH (anti-N+1) les comptages de réactions pour le thread
        // ET toutes ses réponses en quelques requêtes seulement, plutôt qu'une
        // requête par entité. Idem pour les réactions de l'utilisateur connecté
        // (utilisées pour surligner les boutons déjà cliqués).
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $reactionCounts = $this->reactionRepository->countForThreadAndReplies($thread, $replies);
        $userReactions  = $this->reactionRepository->findUserReactionsForThreadAndReplies(
            $currentUser,
            $thread,
            $replies
        );

        return $this->render('forum/thread.html.twig', [
            'category'    => $category,
            'thread'      => $thread,
            'replies'     => $replies,
            'canReply'    => $canReply,
            'canModerate' => $canModerate,
            // Données de réactions pour l'affichage initial de la page
            'reactionCounts' => $reactionCounts,
            'userReactions'  => $userReactions,
            // Liste ordonnée des types de réaction (emoji + libellé) pour les boutons
            'reactionTypes'  => ForumReactionType::orderedCases(),
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

            // ── Résolution de la catégorie choisie dans le select ────────────
            // Le template new_thread.html.twig affiche un <select name="categoryId">
            // si plusieurs catégories existent. L'utilisateur peut avoir changé
            // de catégorie par rapport à celle de l'URL ({categorySlug}).
            // On résout ici la catégorie réelle à partir du champ du formulaire.
            $categoryIdFromForm = $request->request->get('categoryId');
            if ($categoryIdFromForm !== null && (int) $categoryIdFromForm !== $category->getId()) {
                // L'utilisateur a sélectionné une catégorie différente de celle de l'URL.
                // On cherche la catégorie correspondante en base.
                $newCategory = $this->categoryRepository->find((int) $categoryIdFromForm);
                if ($newCategory !== null && $newCategory->isActive()) {
                    // On substitue silencieusement la catégorie — pas de redirection HTTP,
                    // car le contenu du formulaire serait perdu. On utilise directement
                    // la nouvelle catégorie pour la création du thread.
                    $category = $newCategory;
                }
                // Si la catégorie soumise est introuvable ou inactive, on garde
                // la catégorie de l'URL sans lever d'erreur (dégradation gracieuse).
            }

            // ── Récupération de l'utilisateur connecté ────────────────────────
            /** @var User $user */
            $user = $this->getUser();

            // ── Délégation au service ─────────────────────────────────────────
            // $request->files->get('image') : image optionnelle jointe au thread.
            $result = $this->forumService->createThread(
                $user,
                $category,
                $request->request->all(),
                $request->files->get('image'),
            );

            if (is_string($result)) {
                // Le service a retourné un message d'erreur (string = erreur).
                // On réaffiche le formulaire avec les données saisies pour éviter
                // à l'utilisateur de tout retaper.
                $this->addFlash('error', $result);
                return $this->render('forum/new_thread.html.twig', [
                    'category'      => $category,
                    // toutes les catégories actives pour le select de catégorie
                    'allCategories' => $this->categoryRepository->findAllActive(),
                    // On repasse les données pour pré-remplir le formulaire
                    'formData'      => $request->request->all(),
                ]);
            }

            // $result est un ForumThread : succès
            $this->addFlash('success', 'Votre sujet a été publié avec succès !');
            return $this->redirectToRoute('app_forum_thread', [
                'categorySlug' => $category->getSlug(),
                'threadSlug'   => $result->getSlug(),
            ]);
        }

        // Affichage du formulaire vide (méthode GET).
        // On passe allCategories pour que le template puisse afficher le select
        // permettant à l'utilisateur de choisir une catégorie différente de celle
        // présente dans l'URL (pratique quand on arrive depuis le compositeur).
        return $this->render('forum/new_thread.html.twig', [
            'category'      => $category,
            'allCategories' => $this->categoryRepository->findAllActive(),
            'formData'      => [],
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

        // $request->files->get('image') : image optionnelle jointe à la réponse.
        $result = $this->forumService->addReply(
            $user,
            $thread,
            $request->request->all(),
            $request->files->get('image'),
        );

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

    // ─── Réactions emoji ──────────────────────────────────────────────────────

    /**
     * Bascule une réaction emoji (toggle) sur un thread ou une réponse.
     *
     * Route : POST /forum/react (name: app_forum_react)
     *
     * Corps de la requête :
     *   - targetType : 'thread' ou 'reply'
     *   - targetId   : int (ID de la cible)
     *   - type       : valeur backed de l'enum ('like','fire','bravo','heart','idea')
     *   - _token     : token CSRF avec intention 'forum_react'
     *
     * Réponse JSON (succès) :
     *   {"ok": true, "counts": {"like": 5, ...}, "userReactions": ["like"]}
     *
     * Fallback non-JS :
     *   Si la requête n'est pas XHR / n'attend pas du JSON, on redirige vers
     *   l'index du forum (le JS gère le rendu fin sans rechargement de page).
     *
     * Sécurité :
     *   - CSRF vérifié EN PREMIER (cohérent avec lock/pin/delete).
     *   - ROLE_USER garanti par #[IsGranted] au niveau de la classe.
     */
    #[Route('/react', name: 'react', methods: ['POST'])]
    public function react(Request $request): Response
    {
        // ── Vérification CSRF EN PREMIER ──────────────────────────────────────
        if (!$this->isCsrfTokenValid('forum_react', $request->request->get('_token'))) {
            if ($this->isXhrOrJsonRequest($request)) {
                return new JsonResponse(['ok' => false, 'error' => 'Token de sécurité invalide.'], 403);
            }
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_forum_index');
        }

        // ── Récupération et validation des paramètres ─────────────────────────
        $targetType = $request->request->get('targetType');
        $targetId   = $request->request->getInt('targetId');
        $typeValue  = (string) $request->request->get('type', '');

        // targetType doit valoir 'thread' ou 'reply'
        if (!in_array($targetType, ['thread', 'reply'], strict: true)) {
            return $this->reactionError($request, 'Paramètre de réaction invalide.', 400);
        }

        if ($targetId <= 0) {
            return $this->reactionError($request, 'Cible de réaction invalide.', 400);
        }

        // tryFrom() retourne null si la valeur est inconnue (au lieu de lever une exception)
        $reactionType = ForumReactionType::tryFrom($typeValue);
        if ($reactionType === null) {
            return $this->reactionError($request, 'Type de réaction non reconnu.', 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        // ── Délégation au service ─────────────────────────────────────────────
        // toggleReaction() lève une \InvalidArgumentException si la cible est introuvable.
        try {
            $result = $this->forumService->toggleReaction($user, $targetType, $targetId, $reactionType);
        } catch (\InvalidArgumentException $e) {
            return $this->reactionError($request, $e->getMessage(), 404);
        }

        // ── Réponse JSON (requête XHR) ────────────────────────────────────────
        if ($this->isXhrOrJsonRequest($request)) {
            return new JsonResponse($result);
        }

        // ── Fallback non-JS : redirection vers l'index ────────────────────────
        $this->addFlash('success', 'Réaction enregistrée.');
        return $this->redirectToRoute('app_forum_index');
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    /**
     * Construit la réponse d'erreur d'une réaction selon le contexte (JSON ou redirection).
     *
     * Factorise le code dupliqué de react() : pour chaque cas d'erreur, on renvoie
     * du JSON si la requête vient du JavaScript, sinon un flash + redirection.
     */
    private function reactionError(Request $request, string $message, int $status): Response
    {
        if ($this->isXhrOrJsonRequest($request)) {
            return new JsonResponse(['ok' => false, 'error' => $message], $status);
        }
        $this->addFlash('error', $message);
        return $this->redirectToRoute('app_forum_index');
    }

    /**
     * Détecte si la requête est une requête XHR (JavaScript) ou attend du JSON.
     *
     * Deux critères :
     *   1. En-tête X-Requested-With: XMLHttpRequest (fetch/axios le positionnent souvent)
     *   2. En-tête Accept contenant 'application/json'
     */
    private function isXhrOrJsonRequest(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains($request->headers->get('Accept', ''), 'application/json');
    }
}
