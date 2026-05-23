<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\PostRepository;
use App\Repository\ResourceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord principal — page d'accueil après connexion.
 * Affiche des statistiques globales + des widgets avec les données récentes.
 */
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ResourceRepository $resourceRepository,
        private readonly PostRepository $postRepository,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        // Les admins ont leur propre tableau de bord dédié — on les redirige
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Les 5 dernières ressources publiées (widget "Ressources récentes")
        $recentResources = array_slice($this->resourceRepository->findPublished(), 0, 5);

        // Les 3 derniers posts du fil communauté (widget "Communauté")
        $recentPosts = $this->postRepository->findFeed(3);

        // Nombre total de soumissions de cet utilisateur (stat personnelle)
        $submissions = count($this->resourceRepository->findByUser($user));

        return $this->render('dashboard/index.html.twig', [
            'user'             => $user,
            'recent_resources' => $recentResources,
            'recent_posts'     => $recentPosts,
            'submissions'      => $submissions,
        ]);
    }
}
