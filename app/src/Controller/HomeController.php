<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur de la page d'accueil publique.
 *
 * Cette page est accessible sans être connecté.
 * Si l'utilisateur est déjà connecté, on le redirige directement
 * vers son tableau de bord.
 */
class HomeController extends AbstractController
{
    /**
     * Page d'accueil — route "/"
     *
     * La route est nommée "app_home" — on peut y faire référence
     * dans les templates avec : path('app_home')
     */
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Si l'utilisateur est déjà connecté, pas besoin de voir la landing page
        // On le redirige directement vers son tableau de bord
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Sinon, on affiche la page vitrine publique
        return $this->render('vitrine/index.html.twig');
    }
}
