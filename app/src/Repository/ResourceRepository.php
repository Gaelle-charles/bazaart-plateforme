<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationProfile;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ResourceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Resource>
 */
class ResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Resource::class);
    }

    /**
     * Retourne toutes les ressources publiées, avec filtres optionnels.
     * Utilisé pour la page liste publique.
     *
     * @param int|null    $typeId       Filtre sur l'ID du ResourceType
     * @param int|null    $disciplineId Filtre sur l'ID d'une Discipline
     * @param string|null $search       Recherche textuelle dans le titre
     * @return Resource[]
     */
    public function findPublished(?int $typeId = null, ?int $disciplineId = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('r')
            // On charge les relations en une seule requête (évite le problème N+1)
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            ->leftJoin('r.organization', 'org')->addSelect('org')
            ->leftJoin('r.disciplines', 'd')->addSelect('d')
            ->where('r.status = :status')
            ->setParameter('status', ResourceStatus::Published)
            ->orderBy('r.createdAt', 'DESC');

        // Filtre par type de ressource
        if ($typeId !== null) {
            $qb->andWhere('rt.id = :typeId')
               ->setParameter('typeId', $typeId);
        }

        // Filtre par discipline (JOIN sur la table de jointure)
        if ($disciplineId !== null) {
            $qb->andWhere(':disciplineId MEMBER OF r.disciplines')
               ->setParameter('disciplineId', $disciplineId);
        }

        // Recherche textuelle (insensible à la casse avec LOWER)
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(r.title) LIKE LOWER(:search) OR LOWER(r.description) LIKE LOWER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retourne les ressources soumises par un utilisateur donné.
     * Utilisé dans le dashboard utilisateur pour suivre ses soumissions.
     *
     * @return Resource[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            ->where('r.submittedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les ressources soumises par un utilisateur, avec filtre optionnel sur le statut.
     *
     * Utilisé par la page "Mes ressources" (/resources/my) dont les onglets permettent
     * de filtrer par statut (Toutes / Publiées / En attente / Rejetées...).
     *
     * Pourquoi ne pas réutiliser findByUser() + filtrage PHP en mémoire ?
     * Avec potentiellement des centaines de ressources par utilisateur, charger
     * toute la liste pour n'en afficher qu'une fraction serait peu efficace.
     * On filtre directement en SQL, ce qui est plus propre et scalable.
     *
     * @param User                $user       L'utilisateur connecté (obligatoire)
     * @param ResourceStatus|null $status     Filtre de statut. Null = toutes les ressources.
     * @return Resource[]
     */
    public function findByUserWithStatusFilter(User $user, ?ResourceStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('r')
            // On charge resourceType en même temps pour éviter le N+1 dans le template
            // (chaque $resource->getResourceType() serait sinon une requête séparée).
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            ->where('r.submittedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC');

        // Si un filtre de statut est fourni, on ajoute la condition WHERE sur le statut.
        // On passe l'objet enum directement : Doctrine sait le convertir en valeur SQL
        // grâce à la configuration `enumType: ResourceStatus::class` sur la colonne.
        if ($status !== null) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retourne les ressources soumises par une organisation donnée.
     *
     * @return Resource[]
     */
    public function findByOrganization(OrganizationProfile $org): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            ->where('r.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les ressources publiées depuis une date donnée, avec filtres optionnels
     * sur les disciplines et les types de ressources.
     *
     * Utilisé par le job d'alertes (SendResourceAlertsCommand + ResourceAlertService)
     * pour trouver les nouvelles opportunités correspondant aux préférences d'un utilisateur.
     *
     * Logique SQL générée :
     *   SELECT DISTINCT r FROM resources r
     *   [INNER JOIN r.disciplines d]  ← uniquement si $disciplineIds non vide
     *   WHERE r.status = 'published'
     *   AND r.publishedAt >= :since
     *   [AND d.id IN (:disciplineIds)]
     *   [AND r.resourceType IN (:typeIds)]
     *
     * Pourquoi DISTINCT ? Si une ressource a plusieurs disciplines qui matchent
     * les filtres, elle apparaîtrait plusieurs fois dans le résultat sans DISTINCT.
     *
     * @param \DateTimeInterface $since         Ne retourner que les ressources publiées après cette date
     * @param int[]              $disciplineIds Si vide, pas de filtre discipline (toutes acceptées)
     * @param int[]              $typeIds        Si vide, pas de filtre type (tous acceptés)
     * @return Resource[]
     */
    public function findPublishedSince(
        \DateTimeInterface $since,
        array $disciplineIds = [],
        array $typeIds = [],
    ): array {
        $qb = $this->createQueryBuilder('r')
            // On charge le type de ressource pour l'affichage dans l'email (évite N+1)
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            // Filtre principal : uniquement les ressources publiées depuis la date donnée
            ->where('r.status = :status')
            ->andWhere('r.publishedAt >= :since')
            ->setParameter('status', ResourceStatus::Published)
            ->setParameter('since', $since)
            ->orderBy('r.publishedAt', 'DESC');

        // Filtre sur les disciplines : si la liste est non vide, on fait un INNER JOIN
        // et on filtre sur les IDs. On utilise DISTINCT pour éviter les doublons
        // (une ressource avec 2 disciplines matchant apparaîtrait sinon 2 fois).
        if (!empty($disciplineIds)) {
            $qb->innerJoin('r.disciplines', 'd')
               ->andWhere('d.id IN (:disciplineIds)')
               ->setParameter('disciplineIds', $disciplineIds)
               ->distinct();
        }

        // Filtre sur les types de ressources : simple condition IN sur la FK
        if (!empty($typeIds)) {
            $qb->andWhere('r.resourceType IN (:typeIds)')
               ->setParameter('typeIds', $typeIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retourne toutes les ressources en attente de validation.
     * Utilisé dans l'interface d'administration.
     *
     * @return Resource[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.resourceType', 'rt')->addSelect('rt')
            ->leftJoin('r.organization', 'org')->addSelect('org')
            ->leftJoin('r.submittedBy', 'u')->addSelect('u')
            ->where('r.status = :status')
            ->setParameter('status', ResourceStatus::PendingValidation)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
