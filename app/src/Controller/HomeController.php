<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ResourceRepository;
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
     * Nombre d'opportunités affichées dans la section "Ça se passe maintenant".
     * Correspond exactement au nombre de cartes statiques qu'il y avait avant
     * la dynamisation — on garde la même grille.
     */
    private const OPPORTUNITIES_COUNT = 3;

    /**
     * Injection du repository Resource via le constructeur.
     *
     * Symfony résout automatiquement ResourceRepository grâce à l'autowiring :
     * le type-hint suffit, pas besoin de configuration dans services.yaml.
     *
     * On utilise readonly pour respecter les bonnes pratiques PHP 8.1+ :
     * une fois injecté, le repository ne doit pas être réaffecté.
     */
    public function __construct(
        private readonly ResourceRepository $resourceRepository,
    ) {}

    /**
     * Page d'accueil — route "/"
     *
     * La route est nommée "app_home" — on peut y faire référence
     * dans les templates avec : path('app_home')
     *
     * On charge les N dernières opportunités publiées pour alimenter la section
     * "Ça se passe maintenant". Le calcul du J-X se fait côté Twig avec date_diff,
     * pour ne pas alourdir ce contrôleur avec de la logique de présentation.
     */
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // On affiche la vitrine pour tout le monde.
        // La navbar se charge d'adapter son contenu selon l'état de connexion
        // via {{ app.user }} dans base.html.twig.

        // findPublished() avec $page = 1 et $limit = OPPORTUNITIES_COUNT
        // retourne les N ressources publiées les plus récentes (tri par createdAt DESC).
        //
        // Pourquoi $page = 1 et non $page = null ?
        //   - $page = null = rétro-compat "toutes les ressources" (utilisé par admin/dashboard)
        //   - $page = 1 avec un $limit petit = pagination : on borne au premier lot
        //   C'est la façon idiomatique pour obtenir "les N premiers" via ce repository.
        $opportunities = $this->resourceRepository->findPublished(
            typeId:       null,        // Pas de filtre sur le type
            disciplineId: null,        // Pas de filtre sur la discipline
            search:       null,        // Pas de recherche textuelle
            page:         1,
            limit:        self::OPPORTUNITIES_COUNT,
            // hideExpired: true → la vitrine est une surface publique.
            // On n'y montre pas d'opportunités dont la deadline est passée :
            // ce serait trompeur de proposer à un visiteur de "candidater" à quelque chose
            // qui ne peut plus être fait. L'admin accède à /admin/resources pour tout voir.
            hideExpired:  true,
        );

        return $this->render('vitrine/index.html.twig', [
            // Variable disponible dans le template sous {{ opportunities }}
            // C'est un tableau de Resource[] (peut être vide si aucune ressource publiée).
            'opportunities' => $opportunities,
        ]);
    }
}
