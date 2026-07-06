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
use Symfony\Bundle\SecurityBundle\Security;

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
        // Security est injecté pour vérifier ROLE_ADMIN via isGranted() plutôt que
        // de lire $user->getRoles() directement (audit sécurité) — isGranted() tient
        // compte de la hiérarchie des rôles Symfony (role_hierarchy dans security.yaml),
        // contrairement à un in_array() brut sur getRoles() qui ne verrait QUE les
        // rôles explicitement stockés en base sur l'utilisateur, pas ceux hérités.
        // Même pattern que ResourceService (cf. son constructeur).
        private readonly Security $security,
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
        // Vérifie que l'utilisateur a le droit de supprimer ce post.
        //
        // Sécurité (audit) : on utilise isGranted() plutôt que in_array() brut sur
        // getRoles() — isGranted() applique la hiérarchie des rôles Symfony
        // (role_hierarchy dans security.yaml : ROLE_ADMIN hérite de tous les autres),
        // et surtout reste correct si un jour ROLE_ADMIN n'est plus accordé
        // uniquement via un rôle direct sur $user (ex: attribut de session,
        // impersonation Symfony...). $user est toujours l'utilisateur courant
        // (passé depuis $this->getUser() dans PostController), donc isGranted()
        // — qui vérifie le token de sécurité courant — est cohérent ici.
        $isAuthor = $post->getAuthor() === $user;
        $isAdmin  = $this->security->isGranted('ROLE_ADMIN');

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
        // Même logique que deletePost() ci-dessus : isGranted() plutôt que
        // in_array() sur getRoles() (audit sécurité).
        $isAuthor = $comment->getAuthor() === $user;
        $isAdmin  = $this->security->isGranted('ROLE_ADMIN');

        if (!$isAuthor && !$isAdmin) {
            return false;
        }

        $this->em->remove($comment);
        $this->em->flush();
        return true;
    }
}
