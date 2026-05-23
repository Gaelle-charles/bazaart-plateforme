<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Resource;
use App\Entity\ResourceFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité ResourceFavorite.
 *
 * Ce repository encapsule toutes les requêtes liées aux favoris.
 * Aucune requête Doctrine ne doit se trouver ailleurs (ni dans les controllers,
 * ni dans les services) — c'est la convention du projet (CDC §4).
 *
 * @extends ServiceEntityRepository<ResourceFavorite>
 */
class ResourceFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceFavorite::class);
    }

    /**
     * Trouve le favori d'un utilisateur pour une ressource donnée.
     * Retourne null si l'utilisateur n'a pas mis cette ressource en favori.
     *
     * Utilisé pour :
     * - vérifier l'état du bouton coeur dans resource/show.html.twig
     * - décider si on doit créer ou supprimer dans le toggle
     */
    public function findByUserAndResource(User $user, Resource $resource): ?ResourceFavorite
    {
        // findOneBy(['user' => $user, 'resource' => $resource]) fonctionnerait aussi,
        // mais on utilise un QueryBuilder explicite pour faciliter la lecture et
        // potentiellement ajouter des conditions supplémentaires plus tard.
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->andWhere('f.resource = :resource')
            ->setParameter('user', $user)
            ->setParameter('resource', $resource)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne toutes les ressources mises en favori par un utilisateur donné.
     * Triées par date d'ajout décroissante (les plus récents d'abord).
     *
     * On charge la relation `resource` avec un JOIN pour éviter le problème N+1 :
     * si on avait 50 favoris et qu'on accédait à $favorite->getResource() dans
     * la vue Twig, Doctrine ferait 50 requêtes SQL séparées. Le addSelect('r')
     * réduit cela à 1 requête.
     *
     * @return ResourceFavorite[]
     */
    public function findFavoritesByUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.resource', 'r')->addSelect('r')
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            ->leftJoin('r.organization', 'org')->addSelect('org')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne un tableau des IDs des ressources favorites d'un utilisateur.
     *
     * Pourquoi un tableau d'IDs plutôt que d'entités complètes ?
     * Sur la page liste des ressources (resource/index), on affiche potentiellement
     * des dizaines de ressources. Pour chacune, on veut savoir si elle est en favori.
     * Plutôt que de faire N requêtes ("est-ce que cette ressource est favorite ?"),
     * on charge tous les IDs en une seule requête, et on utilise `in_array()` en Twig.
     * C'est un pattern classique de performance : "load all IDs, check in PHP".
     *
     * @return int[] Tableau d'identifiants (ex: [3, 7, 42])
     */
    public function findFavoriteResourceIds(User $user): array
    {
        // SCALAR : on récupère des valeurs brutes (int), pas des entités.
        // C'est plus léger que de charger des objets Resource complets.
        $result = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.resource) AS resource_id')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        // getScalarResult() retourne [['resource_id' => 3], ['resource_id' => 7], ...]
        // On aplatit le tableau en [3, 7, ...] pour un usage simple dans Twig (in_array).
        return array_map(
            static fn (array $row): int => (int) $row['resource_id'],
            $result,
        );
    }

    /**
     * Compte le nombre total de favoris pour une ressource.
     * Utilisé pour afficher le compteur côté "coeur".
     */
    public function countByResource(Resource $resource): int
    {
        // COUNT retourne un int via getSingleScalarResult()
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.resource = :resource')
            ->setParameter('resource', $resource)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
