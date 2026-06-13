<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationProfile;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ResourceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
     * Construit le QueryBuilder de base pour les ressources publiées avec filtres optionnels.
     *
     * Cette méthode privée centralise la logique de filtrage partagée entre :
     *   - findPublished()    → qui charge les entités complètes (liste publique)
     *   - countPublished()   → qui ne fait qu'un COUNT (pagination)
     *
     * Pourquoi factoriser ? Si on duplique le code de filtrage dans les deux méthodes,
     * une modification future (nouveau filtre, changement de condition) risque d'être
     * appliquée dans l'une mais oubliée dans l'autre → bugs subtils de pagination
     * (compteur incohérent avec la liste réelle). Un QueryBuilder partagé = source unique de vérité.
     *
     * Note : le QueryBuilder ne fait PAS de JOIN ici — les JOINs "chargement de relations"
     * (leftJoin + addSelect) sont ajoutés uniquement dans findPublished() car ils ne servent
     * à rien dans un COUNT (ils n'affectent pas le nombre de lignes grâce au DISTINCT).
     *
     * ⚠️  IMPORTANT : ce QueryBuilder ne contient VOLONTAIREMENT aucun ORDER BY.
     *     Raison : il est réutilisé par countPublished() qui fait un SELECT COUNT(r.id).
     *     PostgreSQL (contrairement à MySQL) rejette un ORDER BY sur une colonne non agrégée
     *     dans une requête COUNT sans GROUP BY → erreur "must appear in GROUP BY clause".
     *     Le tri est donc appliqué UNIQUEMENT dans findPublished(), qui en a réellement besoin.
     */
    private function buildPublishedQueryBuilder(
        ?int $typeId = null,
        ?int $disciplineId = null,
        ?string $search = null
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('r')
            // Filtre principal : seulement les ressources avec le statut "publié"
            ->where('r.status = :status')
            ->setParameter('status', ResourceStatus::Published);

        // Filtre par type de ressource.
        //
        // Pourquoi IDENTITY(r.resourceType) plutôt qu'un JOIN ?
        //   Dans findPublished(), on fait déjà un leftJoin('r.resourceType', 'rt') pour le
        //   chargement EAGER des entités. Si on ajoutait ici un second leftJoin sur la même
        //   association avec un alias différent ('rt_filter'), Doctrine génèrerait deux JOIN
        //   sur la même table avec des alias distincts → comportement indéfini / erreur.
        //
        //   La fonction DQL IDENTITY() est la solution idiomatique : elle accède à la valeur
        //   de la clé étrangère (resource_type_id) SANS faire de JOIN supplémentaire.
        //   C'est plus efficace et évite tout conflit d'alias.
        //
        //   Syntaxe DQL : IDENTITY(r.resourceType) retourne l'ID du ResourceType lié.
        if ($typeId !== null) {
            $qb->andWhere('IDENTITY(r.resourceType) = :typeId')
               ->setParameter('typeId', $typeId);
        }

        // Filtre par discipline : "MEMBER OF" est l'opérateur DQL pour les relations ManyToMany.
        // Il génère un EXISTS (sous-requête) qui vérifie si l'ID donné est dans la collection.
        // Avantage sur le JOIN : pas de doublons si la ressource a plusieurs disciplines qui matchent.
        if ($disciplineId !== null) {
            $qb->andWhere(':disciplineId MEMBER OF r.disciplines')
               ->setParameter('disciplineId', $disciplineId);
        }

        // Recherche textuelle (insensible à la casse avec LOWER)
        // On cherche à la fois dans le titre ET la description
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(r.title) LIKE LOWER(:search) OR LOWER(r.description) LIKE LOWER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb;
    }

    /**
     * Retourne toutes les ressources publiées, avec filtres optionnels et pagination optionnelle.
     * Utilisé pour la page liste publique (/resources).
     *
     * ── Rétro-compatibilité ──────────────────────────────────────────────────
     * Les deux derniers paramètres ($page et $limit) ont des valeurs par défaut
     * qui préservent le comportement ORIGINAL de la méthode :
     *
     *   - $page = null  → PAS de LIMIT ni de OFFSET → retourne TOUTES les ressources
     *     (comportement historique attendu par DashboardController, AdminController, etc.)
     *
     *   - $page = 1, 2, 3… → applique la pagination avec $limit résultats par page
     *     (nouveau comportement utilisé par ResourceController::index())
     *
     * Ainsi, tous les appels existants sans les nouveaux paramètres continuent de
     * fonctionner sans aucune modification.
     *
     * ── Pourquoi le Paginator Doctrine (et pas setFirstResult/setMaxResults seuls) ──
     *
     * Le problème du fetch-join sur collection ManyToMany :
     *
     *   On charge r.disciplines avec addSelect('d') (un fetch-join) pour éviter le
     *   problème N+1 (sans ça, chaque resource.getDisciplines() déclencherait une
     *   requête supplémentaire). C'est indispensable pour les performances.
     *
     *   Mais ce join sur une collection ManyToMany MULTIPLIE les lignes SQL :
     *   une ressource ayant 3 disciplines génère 3 lignes dans le résultat brut.
     *   Avec setMaxResults(12) directement sur la query DQL, Doctrine limiterait à
     *   12 LIGNES SQL, pas 12 ENTITÉS. Si les premières ressources ont chacune
     *   3 disciplines, la page n'afficherait que 4 ressources au lieu de 12.
     *   La navigation de pages serait incohérente (Doctrine émet même un warning).
     *
     *   Solution : Doctrine\ORM\Tools\Pagination\Paginator avec fetchJoinCollection: true.
     *   Fonctionnement en 2 temps :
     *     1. Une sous-requête SELECT r.id avec LIMIT/OFFSET → récupère exactement
     *        $limit IDs de Resource distincts pour la page demandée.
     *     2. Une seconde requête charge ces entités complètes avec leurs JOINs
     *        (disciplines, type, org) → les données d'affichage, sans aucun LIMIT
     *        parasite sur les lignes jointes.
     *   Résultat : exactement $limit entités Resource par page, quelle que soit
     *   leur nombre de disciplines. C'est le seul moyen correct de paginer un
     *   fetch-join sur collection en Doctrine ORM.
     *
     * @param int|null    $typeId       Filtre sur l'ID du ResourceType (null = tous les types)
     * @param int|null    $disciplineId Filtre sur l'ID d'une Discipline (null = toutes)
     * @param string|null $search       Recherche textuelle dans le titre et la description
     * @param int|null    $page         Page courante (1-based). null = pas de pagination.
     * @param int         $limit        Nombre de résultats par page (défaut : 12)
     * @return Resource[]
     */
    public function findPublished(
        ?int $typeId = null,
        ?int $disciplineId = null,
        ?string $search = null,
        ?int $page = null,
        int $limit = 12,
    ): array {
        // On part du QueryBuilder commun (filtres partagés avec countPublished)
        $qb = $this->buildPublishedQueryBuilder($typeId, $disciplineId, $search);

        // Tri par date de création décroissante (les plus récentes en premier).
        // Ce tri est ici — et non dans buildPublishedQueryBuilder() — parce que
        // countPublished() réutilise le même QB pour un COUNT : PostgreSQL refuse
        // un ORDER BY sur une colonne non agrégée dans une requête COUNT sans GROUP BY.
        // En plaçant le tri ici, après l'appel commun, les deux chemins (avec et sans
        // pagination) bénéficient du tri, tandis que countPublished() reste propre.
        $qb->orderBy('r.createdAt', 'DESC');

        // On charge les relations en une seule requête pour éviter le problème N+1.
        // Ces JOINs sont ici (et pas dans buildPublishedQueryBuilder) car ils sont
        // inutiles pour le COUNT — ils n'affectent pas le résultat mais alourdiraient la requête.
        //
        // ⚠️  ATTENTION : ces addSelect créent des "fetch-joins" sur des collections.
        //     leftJoin('r.disciplines', 'd')->addSelect('d') est un fetch-join sur une
        //     relation ManyToMany (collection). C'est justement pourquoi, ci-dessous,
        //     on passe par le Paginator Doctrine quand $page !== null (voir le docblock).
        $qb->leftJoin('r.resourceType', 'rt')->addSelect('rt')
           ->leftJoin('r.organization', 'org')->addSelect('org')
           ->leftJoin('r.disciplines', 'd')->addSelect('d');

        // ── Cas 1 : sans pagination (rétro-compat) ─────────────────────────
        // Quand $page est null, pas de LIMIT ni d'OFFSET.
        // Ce chemin est emprunté par DashboardController, AdminController, etc.
        // Le fetch-join sur disciplines ne pose pas de problème ici car on ne borne
        // pas le nombre de lignes : Doctrine récupère tout et dédoublonne en mémoire.
        if ($page === null) {
            return $qb->getQuery()->getResult();
        }

        // ── Cas 2 : avec pagination → Paginator Doctrine obligatoire ────────
        //
        // On applique LIMIT et OFFSET sur la Query (pas sur le QueryBuilder, pour que
        // le Paginator puisse introspectir la Query et la modifier en sous-requête).
        $query = $qb->getQuery();
        $query->setFirstResult(($page - 1) * $limit)
              ->setMaxResults($limit);

        // fetchJoinCollection: true indique au Paginator que la query contient un
        // fetch-join sur une collection (r.disciplines), ce qui active la stratégie
        // en 2 temps décrite dans le docblock.
        $paginator = new Paginator($query, fetchJoinCollection: true);

        // iterator_to_array() matérialise le Paginator en tableau PHP de Resource[].
        // On préserve les keys (false) pour obtenir un tableau indexé à partir de 0,
        // compatible avec le type de retour Resource[].
        return array_values(iterator_to_array($paginator->getIterator()));
    }

    /**
     * Compte le nombre total de ressources publiées correspondant aux filtres donnés.
     *
     * Utilisé par ResourceController::index() pour calculer le nombre de pages.
     * On passe exactement les mêmes paramètres que findPublished() pour que le
     * compteur soit toujours cohérent avec la liste affichée.
     *
     * Pourquoi un COUNT séparé plutôt que count(findPublished()) ?
     * count(findPublished()) chargerait TOUTES les entités en mémoire juste pour les compter.
     * Un SELECT COUNT en SQL ne charge aucune entité — c'est beaucoup plus efficace,
     * surtout quand le catalogue contient des centaines de ressources.
     *
     * @param int|null    $typeId       Même filtre que findPublished()
     * @param int|null    $disciplineId Même filtre que findPublished()
     * @param string|null $search       Même filtre que findPublished()
     */
    public function countPublished(
        ?int $typeId = null,
        ?int $disciplineId = null,
        ?string $search = null,
    ): int {
        // On réutilise exactement le même QueryBuilder que findPublished() (sans les JOINs de chargement)
        // pour garantir que les filtres appliqués sont identiques.
        $qb = $this->buildPublishedQueryBuilder($typeId, $disciplineId, $search);

        // On remplace le SELECT * par un SELECT COUNT(r.id)
        // getSingleScalarResult() retourne directement la valeur scalaire (un entier en string)
        // qu'on cast en int pour le typage strict.
        return (int) $qb->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
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
