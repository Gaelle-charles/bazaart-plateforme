<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Service\PostService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller du hub social (fil d'actualité, posts, commentaires, likes).
 */
#[IsGranted('ROLE_USER')]
#[Route('/community', name: 'app_post_')]
class PostController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly CommentRepository $commentRepository,
        private readonly PostService $postService,
    ) {}

    /**
     * Fil d'actualité — affiche les posts + formulaire de publication.
     * Supporte la pagination via le paramètre ?page=N.
     */
    #[Route('', name: 'feed')]
    public function feed(Request $request): Response
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = 15; // Posts par page
        $offset = ($page - 1) * $limit;

        $posts      = $this->postRepository->findFeed($limit, $offset);
        $totalPosts = $this->postRepository->countAll();
        $totalPages = (int) ceil($totalPosts / $limit);

        return $this->render('post/feed.html.twig', [
            'posts'      => $posts,
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * Crée un nouveau post depuis le formulaire du fil.
     */
    #[Route('/post/new', name: 'new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('post_new', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_post_feed');
        }

        /** @var User $user */
        $user   = $this->getUser();
        $result = $this->postService->createPost($user, $request->request->get('content', ''));

        if (is_string($result)) {
            $this->addFlash('error', $result);
        }

        // Redirige vers le fil — le post apparaîtra en haut
        return $this->redirectToRoute('app_post_feed');
    }

    /**
     * Ajoute un commentaire sur un post.
     */
    #[Route('/post/{id}/comment', name: 'comment', methods: ['POST'])]
    public function comment(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comment_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_post_feed');
        }

        $post = $this->postRepository->find($id);
        if ($post === null) {
            throw $this->createNotFoundException('Post introuvable.');
        }

        /** @var User $user */
        $user   = $this->getUser();
        $result = $this->postService->addComment($post, $user, $request->request->get('content', ''));

        if (is_string($result)) {
            $this->addFlash('error', $result);
        }

        // Redirige vers le fil en ancrant sur le post concerné
        return $this->redirect($this->generateUrl('app_post_feed') . '#post-' . $id);
    }

    /**
     * Like / unlike d'un post.
     * Retourne une JsonResponse pour permettre une mise à jour sans rechargement de page.
     */
    #[Route('/post/{id}/like', name: 'like', methods: ['POST'])]
    public function like(int $id, Request $request): Response
    {
        // Vérifie le token CSRF (envoyé dans le corps de la requête fetch())
        if (!$this->isCsrfTokenValid('like_' . $id, $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Token invalide'], 403);
        }

        $post = $this->postRepository->find($id);
        if ($post === null) {
            return new JsonResponse(['error' => 'Post introuvable'], 404);
        }

        /** @var User $user */
        $user  = $this->getUser();
        $liked = $this->postService->toggleLike($post, $user);

        // On recharge le post pour avoir le bon compteur après le toggle
        $post = $this->postRepository->find($id);

        // Retourne le nouvel état : liked (bool) + nouveau compteur
        return new JsonResponse([
            'liked' => $liked,
            'count' => $post->getLikesCount(),
        ]);
    }

    /**
     * Supprime un post (auteur ou admin uniquement).
     */
    #[Route('/post/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('post_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_post_feed');
        }

        $post = $this->postRepository->find($id);
        if ($post === null) {
            throw $this->createNotFoundException('Post introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$this->postService->deletePost($post, $user)) {
            $this->addFlash('error', 'Vous n\'êtes pas autorisé à supprimer ce post.');
        }

        return $this->redirectToRoute('app_post_feed');
    }

    /**
     * Supprime un commentaire (auteur ou admin uniquement).
     */
    #[Route('/comment/{id}/delete', name: 'comment_delete', methods: ['POST'])]
    public function deleteComment(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comment_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_post_feed');
        }

        $comment = $this->commentRepository->find($id);
        if ($comment === null) {
            throw $this->createNotFoundException('Commentaire introuvable.');
        }

        /** @var User $user */
        $user   = $this->getUser();
        $postId = $comment->getPost()->getId();

        if (!$this->postService->deleteComment($comment, $user)) {
            $this->addFlash('error', 'Vous n\'êtes pas autorisé à supprimer ce commentaire.');
        }

        return $this->redirect($this->generateUrl('app_post_feed') . '#post-' . $postId);
    }
}
