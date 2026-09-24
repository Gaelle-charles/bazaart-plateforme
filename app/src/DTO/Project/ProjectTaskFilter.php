<?php

declare(strict_types=1);

namespace App\DTO\Project;

use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * ProjectTaskFilter — critères de filtre des tâches de l'Espace projets (ADR-0037).
 *
 * POURQUOI un DTO plutôt que de lire $request->query partout ?
 *   - Une seule fonction (fromRequest) nettoie et VALIDE les paramètres d'URL :
 *     une valeur inconnue (?priority=nimportequoi) est simplement ignorée,
 *     jamais transmise telle quelle à la requête SQL.
 *   - Le repository reçoit un objet typé (PHPStan niveau 6 peut vérifier les types).
 *   - toQuery() reconstruit les paramètres d'URL : on conserve les filtres quand
 *     on passe d'une vue à l'autre (Kanban → Calendrier…).
 *
 * Les filtres sont les mêmes pour TOUTES les vues : c'est ce qui rend l'outil
 * cohérent (« mes tâches urgentes » en Kanban comme en calendrier).
 */
final class ProjectTaskFilter
{
    /** Valeurs autorisées pour le filtre d'échéance. */
    public const array DUE_OPTIONS = [
        'overdue' => 'En retard',
        'today'   => 'Aujourd\'hui',
        'week'    => '7 prochains jours',
        'month'   => '30 prochains jours',
        'none'    => 'Sans échéance',
    ];

    /** Recherche plein texte (titre + description). */
    public ?string $search = null;

    /** ID du projet, ou null = tous les projets. */
    public ?int $projectId = null;

    /** true = uniquement les tâches SANS projet (?project=none). */
    public bool $withoutProject = false;

    /** 'me', 'none' (non assignées) ou l'ID (string numérique) d'une membre. */
    public ?string $assignee = null;

    public ?ProjectTaskPriority $priority = null;

    public ?ProjectTaskStatus $status = null;

    public ?int $labelId = null;

    /** Une des clés de DUE_OPTIONS, ou null. */
    public ?string $due = null;

    /** Masquer les tâches terminées. */
    public bool $hideDone = false;

    /**
     * Bornes de dates d'échéance (inclusives) — posées par le CONTROLLER pour
     * la vue calendrier (le mois affiché), jamais lues depuis l'URL.
     */
    public ?\DateTimeImmutable $dueFrom = null;
    public ?\DateTimeImmutable $dueTo = null;

    /** true = n'inclure QUE des tâches avec une échéance (vue calendrier). */
    public bool $onlyWithDueDate = false;

    /**
     * Construit le filtre depuis la query string, en ignorant toute valeur invalide.
     */
    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $query  = $request->query;

        // ── Recherche : on coupe à 100 caractères (une recherche plus longue n'a pas de sens)
        $search = trim((string) $query->get('q', ''));
        $filter->search = $search !== '' ? mb_substr($search, 0, 100) : null;

        // ── Projet : un entier positif, ou 'none'
        $project = (string) $query->get('project', '');
        if ($project === 'none') {
            $filter->withoutProject = true;
        } elseif (ctype_digit($project) && (int) $project > 0) {
            $filter->projectId = (int) $project;
        }

        // ── Personne : 'me', 'none' ou un ID numérique
        $assignee = (string) $query->get('assignee', '');
        if (in_array($assignee, ['me', 'none'], true) || (ctype_digit($assignee) && (int) $assignee > 0)) {
            $filter->assignee = $assignee;
        }

        // ── Enums : tryFrom() renvoie null si la valeur n'existe pas (pas d'exception)
        $filter->priority = ProjectTaskPriority::tryFrom((string) $query->get('priority', ''));
        $filter->status   = ProjectTaskStatus::tryFrom((string) $query->get('status', ''));

        $label = (string) $query->get('label', '');
        if (ctype_digit($label) && (int) $label > 0) {
            $filter->labelId = (int) $label;
        }

        $due = (string) $query->get('due', '');
        if (array_key_exists($due, self::DUE_OPTIONS)) {
            $filter->due = $due;
        }

        $filter->hideDone = $query->get('done') === 'hide';

        return $filter;
    }

    /**
     * Paramètres d'URL représentant ce filtre (sans les valeurs vides).
     * Utilisé par Twig pour construire les liens des vues et des raccourcis.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $params = [
            'q'        => $this->search,
            'project'  => $this->withoutProject ? 'none' : ($this->projectId !== null ? (string) $this->projectId : null),
            'assignee' => $this->assignee,
            'priority' => $this->priority?->value,
            'status'   => $this->status?->value,
            'label'    => $this->labelId !== null ? (string) $this->labelId : null,
            'due'      => $this->due,
            'done'     => $this->hideDone ? 'hide' : null,
        ];

        // array_filter retire les null ; on garde des chaînes non vides uniquement.
        return array_filter($params, static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /** Au moins un critère est-il actif ? (affiche le bouton « Réinitialiser »). */
    public function isActive(): bool
    {
        return $this->toQuery() !== [];
    }

    /** Nombre de critères actifs (badge sur le bouton « Filtres » en mobile). */
    public function countActive(): int
    {
        return count($this->toQuery());
    }
}
