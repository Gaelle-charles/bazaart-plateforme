<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\PostLike;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\PostLikeRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Logique métier du hub social.
 * Gère la création de posts, commentaires, et le système de likes.
 */
class PostService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $postRepository,
        private readonly CommentRepository $commentRepository,
        private readonly PostLikeRepository $likeRepository,
    ) {}

    /**
     * Crée un nouveau post.
     * Retourne le post créé ou un message d'erreur.
     */
    public function createPost(User $author, string $content): Post|string
    {
        $content = trim($content);

        if ($content === '') {
            return 'Le contenu du post ne peut pas être vide.';
        }

        // On limite à 2000 caractères pour rester lisible dans le fil
        if (mb_strlen($content) > 2000) {
            return 'Le post ne peut pas dépasser 2000 caractères.';
        }

        $post = new Post();
        $post->setAuthor($author);
        $post->setContent($content);

        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    /**
     * Ajoute un commentaire sur un post.
     * Retourne le commentaire créé ou un message d'erreur.
     */
    public function addComment(Post $post, User $author, string $content): Comment|string
    {
        $content = trim($content);

        if ($content === '') {
            return 'Le commentaire ne peut pas être vide.';
        }

        if (mb_strlen($content) > 1000) {
            return 'Le commentaire ne peut pas dépasser 1000 caractères.';
        }

        $comment = new Comment();
        $comment->setPost($post);
        $comment->setAuthor($author);
        $comment->setContent($content);

        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    /**
     * Bascule le like d'un utilisateur sur un post (like / unlike).
     * Retourne true si le post est maintenant liké, false si le like a été retiré.
     */
    public function toggleLike(Post $post, User $user): bool
    {
        $existingLike = $this->likeRepository->findByPostAndUser($post, $user);

        if ($existingLike !== null) {
            // L'utilisateur avait déjà liké → on retire le like
            $this->em->remove($existingLike);
            $this->em->flush();
            return false;
        }

        // L'utilisateur n'avait pas encore liké → on ajoute le like
        $like = new PostLike($post, $user);
        $this->em->persist($like);
        $this->em->flush();
        return true;
    }

    /**
     * Supprime un post (uniquement si l'utilisateur en est l'auteur ou est admin).
     */
    public function deletePost(Post $post, User $user): bool
    {
        // Vérifie que l'utilisateur a le droit de supprimer ce post
        $isAuthor = $post->getAuthor() === $user;
        $isAdmin  = in_array('ROLE_ADMIN', $user->getRoles(), true);

        if (!$isAuthor && !$isAdmin) {
            return false;
        }

        $this->em->remove($post);
        $this->em->flush();
        return true;
    }

    /**
     * Supprime un commentaire (uniquement si l'utilisateur en est l'auteur ou est admin).
     */
    public function deleteComment(Comment $comment, User $user): bool
    {
        $isAuthor = $comment->getAuthor() === $user;
        $isAdmin  = in_array('ROLE_ADMIN', $user->getRoles(), true);

        if (!$isAuthor && !$isAdmin) {
            return false;
        }

        $this->em->remove($comment);
        $this->em->flush();
        return true;
    }
}
