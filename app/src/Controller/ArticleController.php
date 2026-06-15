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
 *
 * ACCÈS PUBLIC (SEO) :
 *   - index() et show() sont publics — accessibles sans connexion.
 *   - Google peut donc indexer /articles et /articles/{slug}.
 *   - Seuls les articles PUBLIÉS sont visibles par le public.
 *   - Les brouillons restent invisibles pour les visiteurs anonymes (→ 404).
 *
 * ACCÈS RESTREINT (ROLE_ADMIN) :
 *   - new, edit, delete, my portent chacun #[IsGranted('ROLE_ADMIN')] au niveau
 *     méthode → défense en profondeur même si access_control ouvre /articles/*.
 */
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
            // L'article n'est pas publié — on vérifie s'il existe en brouillon.
            $draft = $this->articleRepository->findOneBy(['slug' => $slug]);

            if ($draft !== null) {
                // getUser() retourne null pour un visiteur anonyme et un objet User
                // pour un utilisateur connecté. On n'utilise PAS @var User ici car
                // la valeur peut légitimement être null depuis que la page est publique.
                $currentUser = $this->getUser();

                // Un visiteur anonyme (null) ne peut jamais voir un brouillon → 404.
                // On retourne 404 et NON 403 pour ne pas révéler l'existence du brouillon.
                if ($currentUser === null) {
                    throw $this->createNotFoundException('Article introuvable.');
                }

                // Un utilisateur connecté : seul l'auteur ou un admin peut prévisualiser.
                // On compare par IDENTIFIANT UNIQUE (getUserIdentifier = email) plutôt que
                // par identité d'objet : $currentUser vient du token de sécurité (typé
                // UserInterface), $draft->getAuthor() d'un proxy Doctrine ; ce ne sont pas
                // forcément la même instance PHP. La comparaison d'identifiants scalaires
                // est fiable quel que soit le cycle de vie des objets.
                if ($draft->getAuthor()->getUserIdentifier() !== $currentUser->getUserIdentifier() && !$this->isGranted('ROLE_ADMIN')) {
                    throw $this->createNotFoundException('Article introuvable.');
                }

                // L'auteur ou l'admin peut prévisualiser le brouillon avant publication.
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
     *
     * Décision produit V1 : la publication d'articles est réservée aux admins.
     * La lecture (index, show) est PUBLIQUE (blog indexable pour le SEO).
     * #[IsGranted('ROLE_ADMIN')] ci-dessous est la SEULE barrière de cette méthode :
     * depuis que la classe n'a plus de #[IsGranted] global et que /articles est
     * PUBLIC_ACCESS au niveau firewall, cet attribut doit IMPÉRATIVEMENT figurer
     * sur new(), edit(), delete() et my(), sinon n'importe qui pourrait y accéder.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            // Protection CSRF : on vérifie que le token soumis par le formulaire Twig
            // correspond bien au token généré pour cette action.
            // Sans cette vérification, un site tiers pourrait déclencher une soumission
            // à l'insu de l'utilisateur (attaque CSRF).
            // Le token côté Twig : {{ csrf_token('article_form') }} dans un <input type="hidden" name="_token">
            if (!$this->isCsrfTokenValid('article_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_article_new');
            }

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
     *
     * Décision produit V1 : seuls les admins peuvent modifier des articles.
     * L'autorisation est intégralement portée par #[IsGranted('ROLE_ADMIN')] ci-dessus :
     * Symfony lève une AccessDeniedException avant même d'entrer dans la méthode.
     * L'ancienne garde interne « auteur OU admin » a été retirée car elle était
     * inatteignable (dead code) et aurait pu créer une confusion pour les futurs devs.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $article = $this->articleRepository->find($id);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            // Protection CSRF — même logique que new() : on vérifie le token avant
            // tout traitement. On utilise le même identifiant de token ('article_form')
            // pour que le template form.html.twig puisse partager un seul champ CSRF,
            // quelle que soit la route (création ou édition).
            // En cas d'échec, on redirige vers l'édition pour ne pas perdre le contexte.
            if (!$this->isCsrfTokenValid('article_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_article_edit', ['id' => $id]);
            }

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
     *
     * Décision produit V1 : seuls les admins peuvent supprimer des articles.
     * La redirection après suppression pointe vers app_article_my (liste des
     * articles de l'admin), ce qui reste cohérent puisque les admins sont les
     * seuls à avoir des articles.
     */
    #[IsGranted('ROLE_ADMIN')]
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
     *
     * Décision produit V1 : cette page n'a de sens que pour les admins, puisque
     * seuls eux peuvent créer des articles. On la restreint donc à ROLE_ADMIN.
     * Un artiste ou membre standard qui tenterait d'accéder à /articles/my
     * recevra une 403 Access Denied.
     */
    #[IsGranted('ROLE_ADMIN')]
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
