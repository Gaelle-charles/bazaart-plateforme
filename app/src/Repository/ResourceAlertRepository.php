<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Resource;
use App\Entity\ResourceAlert;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité ResourceAlert (préférences d'alertes email).
 *
 * @extends ServiceEntityRepository<ResourceAlert>
 */
class ResourceAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceAlert::class);
    }

    /**
     * Trouve le profil d'alertes d'un utilisateur donné.
     * Retourne null si l'utilisateur n'a jamais configuré ses alertes.
     *
     * Utilisé dans ResourceController::alerts() pour pré-remplir le formulaire.
     * On charge les relations filterDisciplines et filterResourceTypes en JOIN
     * pour éviter le problème N+1 lors du rendu du formulaire (checkboxes pré-cochées).
     */
    public function findByUser(User $user): ?ResourceAlert
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.filterDisciplines', 'd')->addSelect('d')
            ->leftJoin('a.filterResourceTypes', 'rt')->addSelect('rt')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve les alertes actives dont les préférences correspondent à une ressource.
     *
     * Logique de matching V1 :
     *   - L'alerte doit être active (notifyOnNewResource = true)
     *   - Si l'alerte a des filtres de disciplines → au moins une doit matcher la ressource
     *   - Si l'alerte a des filtres de types       → le type de la ressource doit être inclus
     *   - Si l'alerte n'a pas de filtre            → elle matche toujours (filtre vide = "tout")
     *
     * Pourquoi cette approche en deux étapes (findAllActive + filtre PHP) plutôt qu'un DQL complexe ?
     *   → En V1, le nombre d'alertes actives est faible (< 100 utilisateurs).
     *   → Un filtre DQL avec deux LEFT JOIN + OR EMPTY_COLLECTION serait moins lisible.
     *   → Si la volumétrie augmente en V2, on pourra écrire un DQL optimisé avec EXISTS.
     *
     * @return ResourceAlert[]
     */
    public function findMatchingForResource(Resource $resource): array
    {
        // On charge toutes les alertes actives avec leurs filtres (disciplines + types)
        // en une seule requête grâce aux LEFT JOIN → pas de N+1
        $activeAlerts = $this->findAllActive();

        // On récupère les IDs des disciplines et du type de la ressource pour la comparaison
        $resourceDisciplineIds = $resource->getDisciplines()
            ->map(fn ($d) => $d->getId())
            ->toArray();

        $resourceTypeId = $resource->getResourceType()->getId();

        // Filtre côté PHP : on ne garde que les alertes qui matchent la ressource
        return array_values(array_filter($activeAlerts, function (ResourceAlert $alert) use ($resourceDisciplineIds, $resourceTypeId): bool {

            // ── Filtre par type de ressource ─────────────────────────────────
            // Si l'alerte a des filtres de types ET que le type de la ressource n'en fait pas partie
            // → l'alerte ne matche pas
            $filterTypeIds = $alert->getFilterResourceTypes()
                ->map(fn ($t) => $t->getId())
                ->toArray();

            if (!empty($filterTypeIds) && !in_array($resourceTypeId, $filterTypeIds, true)) {
                // Le type de la ressource n'est pas dans les préférences de l'alerte → pas de match
                return false;
            }

            // ── Filtre par disciplines ────────────────────────────────────────
            // Si l'alerte a des filtres de disciplines ET qu'aucune ne correspond
            // à celles de la ressource → l'alerte ne matche pas
            $filterDisciplineIds = $alert->getFilterDisciplines()
                ->map(fn ($d) => $d->getId())
                ->toArray();

            if (!empty($filterDisciplineIds)) {
                // On cherche au moins une discipline commune entre l'alerte et la ressource
                $intersection = array_intersect($filterDisciplineIds, $resourceDisciplineIds);
                if (empty($intersection)) {
                    // Aucune discipline commune → pas de match
                    return false;
                }
            }

            // Tous les filtres sont satisfaits → cette alerte matche la ressource
            return true;
        }));
    }

    /**
     * Retourne tous les profils d'alertes actifs (notifyOnNewResource = true).
     *
     * Utilisé par le job d'envoi d'alertes (n8n ou Messenger) pour savoir
     * quels utilisateurs ont activé les notifications.
     * On charge aussi l'utilisateur (pour l'email) et les filtres (disciplines, types)
     * en une seule requête pour éviter le N+1 lors du traitement batch.
     *
     * @return ResourceAlert[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->leftJoin('a.filterDisciplines', 'd')->addSelect('d')
            ->leftJoin('a.filterResourceTypes', 'rt')->addSelect('rt')
            ->where('a.notifyOnNewResource = true')
            // Pas d'orderBy ici : un CASE WHEN SQL dans orderBy() n'est pas du DQL valide
            // et son comportement varie selon les versions de Doctrine.
            // Le tri par fréquence (immediate → daily → weekly) est effectué
            // côté PHP dans SendResourceAlertsCommand via usort(), ce qui est
            // plus sûr et plus lisible.
            ->getQuery()
            ->getResult();
    }
}
