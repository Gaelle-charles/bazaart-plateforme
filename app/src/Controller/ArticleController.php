<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Service\ArticleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller des articles longs (blog/magazine).
 */
#[IsGranted('ROLE_USER')]
#[Route('/articles', name: 'app_article_')]
class ArticleController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly ArticleService $articleService,
    ) {}

    /**
     * Liste de tous les articles publiés.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $articles = $this->articleRepository->findPublished();

        return $this->render('article/index.html.twig', [
            'articles' => $articles,
        ]);
    }

    /**
     * Détail d'un article publié, accessible via son slug dans l'URL.
     * Ex : /articles/mon-premier-article
     */
    #[Route('/{slug}', name: 'show', requirements: ['slug' => '[a-z0-9-]+'], priority: -1)]
    public function show(string $slug): Response
    {
        // Priority: -1 pour que cette route ne capture pas /new, /my, /create etc.
        $article = $this->articleRepository->findPublishedBySlug($slug);

        if ($article === null) {
            // Peut-être que l'article existe mais est en brouillon ?
            $draft = $this->articleRepository->findOneBy(['slug' => $slug]);
            if ($draft !== null) {
                /** @var User $user */
                $user = $this->getUser();
                // Un brouillon n'est visible que par son auteur ou un admin
                if ($draft->getAuthor() !== $user && !$this->isGranted('ROLE_ADMIN')) {
                    throw $this->createAccessDeniedException('Cet article n\'est pas encore publié.');
                }
                // L'auteur peut prévisualiser son brouillon
                return $this->render('article/show.html.twig', [
                    'article'   => $draft,
                    'isPreview' => true,
                ]);
            }
            throw $this->createNotFoundException('Article introuvable.');
        }

        return $this->render('article/show.html.twig', [
            'article'   => $article,
            'isPreview' => false,
        ]);
    }

    /**
     * Formulaire de création d'un nouvel article.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $coverFile = $request->files->get('cover');
            $data      = $request->request->all();
            $result    = $this->articleService->saveArticle($user, $data, $coverFile);

            if (is_string($result)) {
                return $this->render('article/form.html.twig', [
                    'article'  => null,
                    'error'    => $result,
                    'formData' => $data,
                ]);
            }

            $msg = $result->isPublished()
                ? 'Article publié avec succès !'
                : 'Brouillon enregistré.';
            $this->addFlash('success', $msg);

            return $this->redirectToRoute('app_article_show', ['slug' => $result->getSlug()]);
        }

        return $this->render('article/form.html.twig', [
            'article'  => null,
            'error'    => null,
            'formData' => [],
        ]);
    }

    /**
     * Formulaire de modification d'un article existant.
     * Accessible uniquement à l'auteur ou un admin.
     */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $article = $this->articleRepository->find($id);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Seul l'auteur ou un admin peut modifier
        if ($article->getAuthor() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier cet article.');
        }

        if ($request->isMethod('POST')) {
            $coverFile = $request->files->get('cover');
            $data      = $request->request->all();
            $result    = $this->articleService->saveArticle($user, $data, $coverFile, $article);

            if (is_string($result)) {
                return $this->render('article/form.html.twig', [
                    'article'  => $article,
                    'error'    => $result,
                    'formData' => $data,
                ]);
            }

            $msg = $result->isPublished()
                ? 'Article mis à jour et publié.'
                : 'Brouillon mis à jour.';
            $this->addFlash('success', $msg);

            return $this->redirectToRoute('app_article_show', ['slug' => $result->getSlug()]);
        }

        return $this->render('article/form.html.twig', [
            'article'  => $article,
            'error'    => null,
            'formData' => [],
        ]);
    }

    /**
     * Supprime un article.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('article_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_article_my');
        }

        $article = $this->articleRepository->find($id);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$this->articleService->deleteArticle($article, $user)) {
            $this->addFlash('error', 'Vous n\'êtes pas autorisé à supprimer cet article.');
            return $this->redirectToRoute('app_article_my');
        }

        $this->addFlash('success', 'Article supprimé.');
        return $this->redirectToRoute('app_article_my');
    }

    /**
     * Mes articles — liste des articles de l'utilisateur connecté (brouillons inclus).
     */
    #[Route('/my', name: 'my')]
    public function my(): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $articles = $this->articleRepository->findByAuthor($user);

        return $this->render('article/my.html.twig', [
            'articles' => $articles,
        ]);
    }
}
