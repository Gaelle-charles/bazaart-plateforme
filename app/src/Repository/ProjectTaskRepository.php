<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\Project\ProjectTaskFilter;
use App\Entity\ProjectAttachment;
use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use App\Entity\ProjectTaskComment;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes Doctrine sur les tâches de l'Espace projets (ADR-0037).
 *
 * Le tri « métier » (priorité, retard…) est fait en PHP dans ProjectTaskService :
 * trier sur un enum en SQL demanderait des CASE WHEN fragiles, et le volume
 * (quelques centaines de tâches pour 3 personnes) le permet sans souci.
 *
 * @extends ServiceEntityRepository<ProjectTask>
 */
class ProjectTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectTask::class);
    }

    /**
     * Toutes les tâches correspondant au filtre, avec projet, personnes et
     * étiquettes chargés en une seule requête (JOIN FETCH → pas de N+1 sur les cartes).
     *
     * @return list<ProjectTask>
     */
    public function findByFilter(ProjectTaskFilter $filter, User $me, \DateTimeImmutable $today): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.project', 'p')->addSelect('p')
            ->leftJoin('t.assignees', 'a')->addSelect('a')
            ->leftJoin('t.labels', 'l')->addSelect('l')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        $this->applyFilter($qb, $filter, $me, $today);

        /** @var list<ProjectTask> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Traduit le DTO de filtre en clauses WHERE.
     *
     * IMPORTANT — pourquoi « MEMBER OF » et pas un simple JOIN + WHERE a.id = :x ?
     *   Si on filtrait sur l'alias `a` (déjà utilisé pour le JOIN FETCH), Doctrine
     *   n'hydraterait QUE la personne filtrée dans la collection assignees : une
     *   tâche partagée par 2 personnes n'afficherait plus qu'un seul avatar.
     *   « :user MEMBER OF t.assignees » génère une sous-requête EXISTS indépendante.
     */
    private function applyFilter(QueryBuilder $qb, ProjectTaskFilter $filter, User $me, \DateTimeImmutable $today): void
    {
        // ── Projet ────────────────────────────────────────────────────────────
        if ($filter->withoutProject) {
            $qb->andWhere('t.project IS NULL');
        } elseif ($filter->projectId !== null) {
            $qb->andWhere('p.id = :projectId')->setParameter('projectId', $filter->projectId);
        } else {
            // Par défaut, les tâches des projets archivés sont masquées.
            $qb->andWhere('p.id IS NULL OR p.status <> :archivedProject')
                ->setParameter('archivedProject', ProjectStatus::Archived);
        }

        // ── Recherche plein texte (insensible à la casse) ─────────────────────
        if ($filter->search !== null) {
            // addcslashes échappe % et _ : taper « 100% » ne doit pas matcher tout.
            $like = '%' . mb_strtolower(addcslashes($filter->search, '%_\\')) . '%';
            $qb->andWhere('LOWER(t.title) LIKE :search OR LOWER(COALESCE(t.description, \'\')) LIKE :search')
                ->setParameter('search', $like);
        }

        // ── Personne ──────────────────────────────────────────────────────────
        if ($filter->assignee === 'me') {
            $qb->andWhere(':me MEMBER OF t.assignees')->setParameter('me', $me);
        } elseif ($filter->assignee === 'none') {
            $qb->andWhere('t.assignees IS EMPTY');
        } elseif ($filter->assignee !== null) {
            $qb->andWhere(':assigneeId MEMBER OF t.assignees')->setParameter('assigneeId', (int) $filter->assignee);
        }

        if ($filter->priority !== null) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $filter->priority);
        }

        if ($filter->status !== null) {
            $qb->andWhere('t.status = :status')->setParameter('status', $filter->status);
        } elseif ($filter->hideDone) {
            $qb->andWhere('t.status <> :doneStatus')->setParameter('doneStatus', ProjectTaskStatus::Done);
        }

        if ($filter->labelId !== null) {
            $qb->andWhere(':labelId MEMBER OF t.labels')->setParameter('labelId', $filter->labelId);
        }

        // ── Échéance (raccourcis) ─────────────────────────────────────────────
        switch ($filter->due) {
            case 'overdue':
                $qb->andWhere('t.dueDate < :dueToday AND t.status <> :dueDone')
                    ->setParameter('dueToday', $today, Types::DATE_IMMUTABLE)
                    ->setParameter('dueDone', ProjectTaskStatus::Done);
                break;
            case 'today':
                $qb->andWhere('t.dueDate = :dueToday')->setParameter('dueToday', $today, Types::DATE_IMMUTABLE);
                break;
            case 'week':
            case 'month':
                $days = $filter->due === 'week' ? 6 : 29;
                $qb->andWhere('t.dueDate BETWEEN :dueStart AND :dueEnd')
                    ->setParameter('dueStart', $today, Types::DATE_IMMUTABLE)
                    ->setParameter('dueEnd', $today->modify(sprintf('+%d days', $days)), Types::DATE_IMMUTABLE);
                break;
            case 'none':
                $qb->andWhere('t.dueDate IS NULL');
                break;
        }

        // ── Bornes techniques (vue calendrier) ────────────────────────────────
        if ($filter->onlyWithDueDate) {
            $qb->andWhere('t.dueDate IS NOT NULL');
        }
        if ($filter->dueFrom !== null) {
            $qb->andWhere('t.dueDate >= :rangeFrom')->setParameter('rangeFrom', $filter->dueFrom, Types::DATE_IMMUTABLE);
        }
        if ($filter->dueTo !== null) {
            $qb->andWhere('t.dueDate <= :rangeTo')->setParameter('rangeTo', $filter->dueTo, Types::DATE_IMMUTABLE);
        }
    }

    /**
     * Tâches ouvertes assignées à une personne (vue d'ensemble « Mes tâches »).
     *
     * @return list<ProjectTask>
     */
    public function findOpenAssignedTo(User $user): array
    {
        /** @var list<ProjectTask> $result */
        $result = $this->createQueryBuilder('t')
            ->leftJoin('t.project', 'p')->addSelect('p')
            ->andWhere(':user MEMBER OF t.assignees')
            ->andWhere('t.status <> :done')
            ->andWhere('p.id IS NULL OR p.status <> :archived')
            ->setParameter('user', $user)
            ->setParameter('done', ProjectTaskStatus::Done)
            ->setParameter('archived', ProjectStatus::Archived)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Indicateurs par tâche pour les cartes (sous-tâches x/y, commentaires,
     * pièces jointes) en 3 requêtes groupées au lieu d'une par carte.
     *
     * @param list<int> $taskIds
     *
     * @return array<int, array{subtasksDone: int, subtasksTotal: int, comments: int, attachments: int}>
     */
    public function fetchCardStats(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $em    = $this->getEntityManager();
        $stats = [];
        foreach ($taskIds as $id) {
            $stats[$id] = ['subtasksDone' => 0, 'subtasksTotal' => 0, 'comments' => 0, 'attachments' => 0];
        }

        /** @var list<array{tid: int|string, total: int|string, doneCount: int|string|null}> $rows */
        $rows = $em->createQuery(
            'SELECT IDENTITY(s.task) AS tid, COUNT(s.id) AS total, SUM(CASE WHEN s.done = true THEN 1 ELSE 0 END) AS doneCount
             FROM ' . ProjectSubtask::class . ' s WHERE s.task IN (:ids) GROUP BY s.task'
        )->setParameter('ids', $taskIds)->getArrayResult();
        foreach ($rows as $row) {
            $stats[(int) $row['tid']]['subtasksTotal'] = (int) $row['total'];
            $stats[(int) $row['tid']]['subtasksDone']  = (int) $row['doneCount'];
        }

        /** @var list<array{tid: int|string, total: int|string}> $rows */
        $rows = $em->createQuery(
            'SELECT IDENTITY(c.task) AS tid, COUNT(c.id) AS total FROM ' . ProjectTaskComment::class . ' c
             WHERE c.task IN (:ids) GROUP BY c.task'
        )->setParameter('ids', $taskIds)->getArrayResult();
        foreach ($rows as $row) {
            $stats[(int) $row['tid']]['comments'] = (int) $row['total'];
        }

        /** @var list<array{tid: int|string, total: int|string}> $rows */
        $rows = $em->createQuery(
            'SELECT IDENTITY(at.task) AS tid, COUNT(at.id) AS total FROM ' . ProjectAttachment::class . ' at
             WHERE at.task IN (:ids) GROUP BY at.task'
        )->setParameter('ids', $taskIds)->getArrayResult();
        foreach ($rows as $row) {
            $stats[(int) $row['tid']]['attachments'] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * Avancement de chaque projet : total, terminées, en retard.
     *
     * @return array<int, array{total: int, done: int, overdue: int}> indexé par ID de projet
     */
    public function countStatsByProject(\DateTimeImmutable $today): array
    {
        /** @var list<array{pid: int|string, total: int|string, doneCount: int|string|null, overdue: int|string|null}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(t.project) AS pid, COUNT(t.id) AS total,
                    SUM(CASE WHEN t.status = :done THEN 1 ELSE 0 END) AS doneCount,
                    SUM(CASE WHEN t.status <> :done AND t.dueDate < :today THEN 1 ELSE 0 END) AS overdue
             FROM ' . ProjectTask::class . ' t
             WHERE t.project IS NOT NULL
             GROUP BY t.project'
        )
            ->setParameter('done', ProjectTaskStatus::Done)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['pid']] = [
                'total'   => (int) $row['total'],
                'done'    => (int) $row['doneCount'],
                'overdue' => (int) $row['overdue'],
            ];
        }

        return $stats;
    }

    /**
     * Charge de travail par personne (vue « Équipe » et en-têtes « Par personne »).
     *
     * @return array<int, array{open: int, overdue: int, urgent: int, doneRecently: int}> indexé par ID utilisateur
     */
    public function countWorkloadByAssignee(\DateTimeImmutable $today, \DateTimeImmutable $doneSince): array
    {
        /** @var list<array{uid: int|string, openCount: int|string|null, overdue: int|string|null, urgent: int|string|null, doneRecently: int|string|null}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT a.id AS uid,
                    SUM(CASE WHEN t.status <> :done THEN 1 ELSE 0 END) AS openCount,
                    SUM(CASE WHEN t.status <> :done AND t.dueDate < :today THEN 1 ELSE 0 END) AS overdue,
                    SUM(CASE WHEN t.status <> :done AND t.priority = :urgent THEN 1 ELSE 0 END) AS urgent,
                    SUM(CASE WHEN t.status = :done AND t.completedAt >= :since THEN 1 ELSE 0 END) AS doneRecently
             FROM ' . ProjectTask::class . ' t
             JOIN t.assignees a
             LEFT JOIN t.project p
             WHERE p.id IS NULL OR p.status <> :archived
             GROUP BY a.id'
        )
            ->setParameter('done', ProjectTaskStatus::Done)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->setParameter('urgent', ProjectTaskPriority::Urgent)
            ->setParameter('since', $doneSince, Types::DATETIME_IMMUTABLE)
            ->setParameter('archived', ProjectStatus::Archived)
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['uid']] = [
                'open'         => (int) $row['openCount'],
                'overdue'      => (int) $row['overdue'],
                'urgent'       => (int) $row['urgent'],
                'doneRecently' => (int) $row['doneRecently'],
            ];
        }

        return $stats;
    }

    /** Position à donner à une nouvelle carte pour qu'elle arrive en bas de sa colonne. */
    public function nextPosition(ProjectTaskStatus $status): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.position)')
            ->andWhere('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /** Tâches créées par $user ET assignées à au moins une personne (onboarding). */
    public function countCreatedAndAssignedBy(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.createdBy = :user')
            ->andWhere('t.assignees IS NOT EMPTY')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
