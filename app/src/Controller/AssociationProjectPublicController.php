<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssociationProject;
use App\Repository\AssociationProjectBlockRepository;
use App\Repository\AssociationProjectRepository;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use App\Service\AssociationProject\AssociationProjectPresenter;
use App\Service\AssociationProject\PublicAccessToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page « Projets en cours » de l'association, SANS CONNEXION (ADR-0039).
 *
 * COMMENT LA PAGE EST ACCESSIBLE SANS LOGIN :
 *   security.yaml contient la règle `^/projets/` → PUBLIC_ACCESS (même mécanisme
 *   que /articles ou /formations). Sans cette règle, la règle finale `^/` exigerait
 *   une connexion et renverrait le visiteur vers /login.
 *
 * CE QUI PROTÈGE LA PAGE : le jeton secret dans l'adresse (PublicAccessToken).
 *   - Jeton absent, faux, ou variable non configurée → 404 (pas 403 : on ne
 *     confirme même pas que la page existe).
 *   - Fiche inconnue ou NON PUBLIÉE → la même 404.
 *   - Le jeton est vérifié AVANT toute lecture en base.
 *
 * EN-TÊTES ajoutés à chaque réponse (page, fiche, API, images) :
 *   - X-Robots-Tag: noindex, nofollow → les moteurs de recherche n'indexent rien ;
 *   - Referrer-Policy: no-referrer → quand un partenaire clique un lien externe,
 *     le navigateur n'envoie pas l'adresse de la page (qui contient le jeton).
 *     SecurityHeadersSubscriber ne pose sa propre valeur que si l'en-tête est
 *     absent : la nôtre est conservée.
 *
 * ⚠️ RÈGLE POUR LA SUITE : tout ce qui est sous /projets/ est public. N'y ajouter
 *   que des routes protégées par le jeton (comme ici), jamais une page réservée.
 */
class AssociationProjectPublicController extends AbstractController
{
    /** Adresse de contact affichée aux partenaires (adresse de l'association, pas une adresse perso). */
    public const CONTACT_EMAIL = 'bonjourbazaart@gmail.com';

    /** Slug : lettres minuscules, chiffres et tirets ; « api » est réservé à l'API. */
    private const SLUG_REQUIREMENT = '(?!api$)[a-z0-9-]+';

    public function __construct(
        private readonly PublicAccessToken $accessToken,
        private readonly AssociationProjectRepository $repository,
        private readonly AssociationProjectBlockRepository $blockRepository,
        private readonly AssociationProjectPresenter $presenter,
        private readonly AssociationProjectImageStorage $imageStorage,
    ) {
    }

    /** GET /projets/{token} : la page carrousel. */
    #[Route('/projets/{token}', name: 'app_asso_projects_page', methods: ['GET'])]
    public function page(string $token): Response
    {
        $this->denyUnlessValidToken($token);

        $projects = array_map(
            fn (AssociationProject $project): array => $this->presenter->present($project, $token),
            $this->repository->findPublishedOrdered(),
        );

        return $this->privateHeaders($this->render('association_projects/index.html.twig', [
            'projects'     => $projects,
            'token'        => $token,
            'contactEmail' => self::CONTACT_EMAIL,
        ]));
    }

    /**
     * GET /projets/{token}/api : les projets PUBLIÉS en JSON (même jeton exigé).
     *
     * Mêmes données que la page (AssociationProjectPresenter), sans dates de création
     * ni de mise à jour, sans identifiant interne.
     */
    #[Route('/projets/{token}/api', name: 'app_asso_projects_api', methods: ['GET'])]
    public function api(string $token): JsonResponse
    {
        $this->denyUnlessValidToken($token);

        $projects = array_map(
            fn (AssociationProject $project): array => $this->presenter->present($project, $token),
            $this->repository->findPublishedOrdered(withBlocks: true),
        );

        $response = $this->json(['projects' => $projects, 'contact' => self::CONTACT_EMAIL]);
        // Caractères accentués lisibles dans le JSON (« Média » plutôt que « Média »).
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->privateHeaders($response);
    }

    /** GET /projets/{token}/{slug} : la fiche complète d'un projet publié. */
    #[Route('/projets/{token}/{slug}', name: 'app_asso_projects_show', requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['GET'])]
    public function show(string $token, string $slug): Response
    {
        $this->denyUnlessValidToken($token);

        $project = $this->repository->findPublishedBySlug($slug)
            ?? throw $this->createNotFoundException();

        return $this->privateHeaders($this->render('association_projects/show.html.twig', [
            'project'      => $this->presenter->present($project, $token),
            'token'        => $token,
            'contactEmail' => self::CONTACT_EMAIL,
        ]));
    }

    /**
     * GET /projets/{token}/{slug}/visuel : visuel principal d'un projet publié.
     *
     * Pas d'extension (.jpg) dans l'adresse : le Nginx de production sert lui-même
     * toute URL finissant par .jpg/.png… et ne trouverait pas le fichier (stocké hors
     * du dossier public). Sans extension, la requête arrive bien à PHP.
     */
    #[Route('/projets/{token}/{slug}/visuel', name: 'app_asso_projects_image', requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['GET'])]
    public function image(string $token, string $slug): Response
    {
        $this->denyUnlessValidToken($token);

        $project = $this->repository->findPublishedBySlug($slug)
            ?? throw $this->createNotFoundException();

        return $this->imageResponse($project->getImagePath());
    }

    /**
     * GET /projets/{token}/{slug}/image/{blockId} : image d'un bloc de présentation.
     *
     * SÉCURITÉ : le bloc est cherché DANS le projet publié désigné par le slug
     * (findOneForProject), jamais par son seul numéro. Sinon, à partir de l'adresse
     * d'un projet publié, on pourrait lire l'image d'un bloc d'un projet non publié
     * en changeant simplement le numéro.
     */
    #[Route('/projets/{token}/{slug}/image/{blockId}', name: 'app_asso_projects_block_image', requirements: ['slug' => self::SLUG_REQUIREMENT, 'blockId' => '\d+'], methods: ['GET'])]
    public function blockImage(string $token, string $slug, int $blockId): Response
    {
        $this->denyUnlessValidToken($token);

        $project = $this->repository->findPublishedBySlug($slug)
            ?? throw $this->createNotFoundException();
        $block = $this->blockRepository->findOneForProject($blockId, $project)
            ?? throw $this->createNotFoundException();

        return $this->imageResponse($block->getImagePath());
    }

    /**
     * 404 si le jeton n'est pas le bon. Aucun message dans l'exception : le jeton
     * (ou ce qui a été tapé à sa place) ne doit pas finir dans les journaux.
     */
    private function denyUnlessValidToken(string $token): void
    {
        if (!$this->accessToken->matches($token)) {
            throw $this->createNotFoundException();
        }
    }

    private function imageResponse(?string $imagePath): Response
    {
        $path = $this->imageStorage->absolutePath($imagePath);
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        // Cache PRIVÉ (navigateur du partenaire uniquement, jamais un cache partagé)
        // d'une journée : l'URL change quand l'image change (?v=…), donc pas de risque
        // de voir une ancienne image.
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->setAutoLastModified();

        return $this->privateHeaders($response);
    }

    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private function privateHeaders(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
