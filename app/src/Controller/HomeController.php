<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur de la page d'accueil publique.
 *
 * La vitrine (/) est accessible à TOUS, connectés ou non.
 * La navbar dans base.html.twig gère déjà l'affichage conditionnel
 * (bouton "Connexion" vs avatar utilisateur).
 *
 * Pourquoi ce changement ?
 * Avant : l'utilisateur connecté était redirigé vers app_dashboard.
 * Bug : cliquer sur le logo depuis n'importe quelle page → /  → redirect
 * → dashboard → boucle. L'utilisateur ne pouvait plus accéder à la vitrine.
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
        // On affiche la vitrine pour tout le monde.
        // La navbar se charge d'adapter son contenu selon l'état de connexion
        // via {{ app.user }} dans base.html.twig.
        return $this->render('vitrine/index.html.twig');
    }
}
