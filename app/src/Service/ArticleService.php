<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\ArticleStatus;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Logique métier pour les articles.
 * Gère la création, la mise à jour, la génération de slug et l'upload de couverture.
 */
class ArticleService
{
    private const COVER_DIR = 'uploads/articles';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articleRepository,
        private readonly string $projectDir,
    ) {}

    /**
     * Crée ou met à jour un article.
     * Retourne l'article sauvegardé, ou un message d'erreur.
     *
     * @param Article|null $article Null pour créer, article existant pour modifier
     */
    public function saveArticle(
        User $author,
        array $data,
        ?UploadedFile $coverFile,
        ?Article $article = null,
    ): Article|string {
        // --- Validation ---
        $title   = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');

        if ($title === '') {
            return 'Le titre est obligatoire.';
        }
        if (mb_strlen($title) > 255) {
            return 'Le titre ne peut pas dépasser 255 caractères.';
        }
        if ($content === '') {
            return 'Le contenu est obligatoire.';
        }

        // --- Création ou récupération de l'entité ---
        $isNew = ($article === null);
        if ($isNew) {
            $article = new Article();
            $article->setAuthor($author);
        }

        $article->setTitle($title);
        $article->setContent($content);
        $article->setExcerpt(trim($data['excerpt'] ?? '') ?: null);

        // --- Génération du slug ---
        // On génère le slug uniquement à la création (évite de casser les URLs existantes)
        if ($isNew) {
            $article->setSlug($this->generateUniqueSlug($title));
        }

        // --- Gestion du statut ---
        // Le bouton cliqué ('save_draft' ou 'save_publish') détermine le statut
        $action = $data['action'] ?? 'save_draft';
        if ($action === 'save_publish') {
            if ($article->isDraft()) {
                // On enregistre la date de première publication
                $article->setPublishedAt(new \DateTime());
            }
            $article->setStatus(ArticleStatus::Published);
        } else {
            $article->setStatus(ArticleStatus::Draft);
        }

        // --- Upload de l'image de couverture ---
        if ($coverFile !== null) {
            // Supprime l'ancienne image si elle existe
            $this->deleteCover($article->getCoverImagePath());
            $filename = uniqid('cover_') . '.' . $coverFile->guessExtension();
            $coverFile->move($this->projectDir . '/public/' . self::COVER_DIR, $filename);
            $article->setCoverImagePath(self::COVER_DIR . '/' . $filename);
        }

        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    /**
     * Supprime un article (uniquement si l'utilisateur en est l'auteur ou est admin).
     */
    public function deleteArticle(Article $article, User $user): bool
    {
        $isAuthor = $article->getAuthor() === $user;
        $isAdmin  = in_array('ROLE_ADMIN', $user->getRoles(), true);

        if (!$isAuthor && !$isAdmin) {
            return false;
        }

        $this->deleteCover($article->getCoverImagePath());
        $this->em->remove($article);
        $this->em->flush();

        return true;
    }

    /**
     * Génère un slug unique à partir d'un titre.
     * Ex: "Mon Premier Article !" → "mon-premier-article"
     * Si le slug existe déjà en base, ajoute un suffixe numérique.
     */
    private function generateUniqueSlug(string $title): string
    {
        // Translittération des caractères accentués
        $slug = $title;
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;

        // Minuscules, remplace les non-alphanumériques par des tirets
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Vérifie l'unicité et ajoute un suffixe si nécessaire (-2, -3, ...)
        $baseSlug = $slug;
        $counter  = 1;
        while ($this->articleRepository->findOneBy(['slug' => $slug]) !== null) {
            $counter++;
            $slug = $baseSlug . '-' . $counter;
        }

        return $slug;
    }

    /**
     * Supprime le fichier image de couverture du disque.
     */
    private function deleteCover(?string $coverPath): void
    {
        if ($coverPath === null) {
            return;
        }
        $fullPath = $this->projectDir . '/public/' . $coverPath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}
