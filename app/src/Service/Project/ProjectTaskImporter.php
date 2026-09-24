<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\ProjectImportReport;
use App\DTO\Project\ProjectImportRow;
use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectAttachment;
use App\Entity\ProjectLabel;
use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use App\Entity\User;
use App\Enum\ProjectAttachmentSource;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use App\Repository\ProjectLabelRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\String\u;

/**
 * ProjectTaskImporter — importe des tâches depuis un tableur CSV (ADR-0037).
 *
 * Pour reprendre les tâches suivies jusque-là dans Google Sheets ou Excel :
 * Fichier > Télécharger > CSV, puis « Importer » dans l'Espace projets.
 *
 * DEUX TEMPS :
 *   1. analyze() lit le fichier et prépare un rapport, SANS RIEN ENREGISTRER
 *      (aperçu : l'équipe vérifie les projets, personnes et dates reconnus) ;
 *   2. import() refait la même analyse puis enregistre tout en une transaction.
 *
 * SOUPLESSE : les en-têtes sont reconnus sans tenir compte des accents, de la
 * casse ni de la ponctuation (« Échéance », « echeance », « ÉCHÉANCE : »…), avec
 * plusieurs synonymes par colonne. Les personnes sont retrouvées par email,
 * prénom, nom complet ou morceau d'email (« Wendie » → zahibowendie@gmail.com).
 *
 * SÛRETÉ :
 *   - réimporter le même fichier ne crée pas de doublons (même titre dans le même
 *     projet = ignoré) ;
 *   - aucune notification email (une rafale d'emails pour des tâches déjà connues
 *     serait du bruit) ; une seule ligne dans le journal d'activité ;
 *   - seuls les liens http(s) sont joints ; seules des membres peuvent être assignées ;
 *   - tailles bornées (1 Mo, 500 lignes, longueurs des colonnes en base).
 */
class ProjectTaskImporter
{
    public const int MAX_BYTES = 1_048_576;
    public const int MAX_ROWS  = 500;

    private const int MAX_SUBTASKS    = 50;
    private const int MAX_LABELS      = 10;
    private const int MAX_DESCRIPTION = 20000;

    /** Libellé de chaque champ (aperçu « colonnes reconnues », modèle CSV). */
    public const array FIELD_LABELS = [
        'title'       => 'Tâche',
        'project'     => 'Projet',
        'status'      => 'Statut',
        'priority'    => 'Priorité',
        'dueDate'     => 'Échéance',
        'plannedDate' => 'Date prévue',
        'assignees'   => 'Assignée à',
        'description' => 'Notes',
        'labels'      => 'Étiquettes',
        'link'        => 'Lien',
        'subtasks'    => 'Sous-tâches',
        'createdBy'   => 'Par',
    ];

    /**
     * En-têtes acceptés pour chaque champ, déjà « normalisés » (voir key()) :
     * minuscules, sans accents, sans espaces ni ponctuation.
     */
    private const array HEADER_ALIASES = [
        'title'       => ['tache', 'taches', 'titre', 'intitule', 'libelle', 'task', 'title', 'nom', 'action'],
        'project'     => ['projet', 'project', 'dossier'],
        'status'      => ['statut', 'status', 'etat'],
        'priority'    => ['priorite', 'priority', 'urgence'],
        'dueDate'     => ['echeance', 'datelimite', 'deadline', 'duedate', 'pourle', 'datebutoir'],
        'plannedDate' => ['dateprevue', 'prevue', 'prevule', 'date', 'debut', 'datedebut', 'startdate', 'planifiee'],
        'assignees'   => ['assigneea', 'assignea', 'assignee', 'assignees', 'responsable', 'responsables', 'qui', 'pourqui', 'personne', 'personnes', 'membre', 'membres'],
        'description' => ['notes', 'note', 'description', 'details', 'detail', 'commentaire', 'commentaires', 'remarques'],
        'labels'      => ['etiquettes', 'etiquette', 'labels', 'label', 'tags', 'tag', 'categorie'],
        'link'        => ['lien', 'liens', 'docurl', 'doc', 'document', 'url', 'link'],
        'subtasks'    => ['soustaches', 'soustache', 'checklist', 'etapes'],
        'createdBy'   => ['par', 'creepar', 'creeepar', 'auteur', 'autrice', 'createdby'],
    ];

    /** Valeurs de statut reconnues (normalisées). Vide = « À faire ». */
    private const array STATUS_ALIASES = [
        'todo'        => ['', 'afaire', 'todo', 'nouveau', 'nouvelle', 'aplanifier', 'pascommence', 'noncommence'],
        'in_progress' => ['encours', 'inprogress', 'commence', 'commencee', 'demarre', 'demarree', 'entrain'],
        'waiting'     => ['enattente', 'attente', 'bloque', 'bloquee', 'waiting', 'enpause', 'pause', 'suspendu', 'suspendue'],
        'review'      => ['avalider', 'averifier', 'arelire', 'review', 'validation', 'relecture'],
        'done'        => ['termine', 'terminee', 'fait', 'faite', 'done', 'fini', 'finie', 'ok', 'clos', 'close', 'cloture', 'cloturee', 'valide', 'validee', 'x', 'oui', 'true', 'vrai'],
    ];

    /** Valeurs de priorité reconnues (normalisées). Vide = « Normale ». */
    private const array PRIORITY_ALIASES = [
        'urgent' => ['urgente', 'urgent', 'critique', 'tresurgent', 'tresurgente', 'asap'],
        'high'   => ['haute', 'haut', 'elevee', 'eleve', 'importante', 'important', 'high', 'forte', 'fort'],
        'medium' => ['', 'moyenne', 'moyen', 'normale', 'normal', 'medium', 'standard'],
        'low'    => ['basse', 'bas', 'faible', 'low', 'mineure', 'mineur'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectTaskRepository $taskRepository,
        private readonly ProjectLabelRepository $labelRepository,
        private readonly ProjectMemberService $memberService,
        private readonly ProjectActivityLogger $activityLogger,
        private readonly ProjectClock $clock,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // API publique
    // ═════════════════════════════════════════════════════════════════════════

    /** Lit le CSV et prépare l'import, sans rien enregistrer (aperçu). */
    public function analyze(string $content, User $actor): ProjectImportReport
    {
        $report  = new ProjectImportReport();
        $records = $this->readCsv($content, $report);
        if ($records === null) {
            return $report;
        }

        // ── En-tête : première ligne non vide ─────────────────────────────────
        $headerLine = 0;
        $header     = [];
        foreach ($records as $index => $record) {
            if (implode('', $record) !== '') {
                $headerLine = $index;
                $header     = $record;
                break;
            }
        }
        $map = $this->mapColumns($header, $report);
        if (!isset($map['title'])) {
            $report->error = sprintf(
                'Colonne « Tâche » introuvable. La première ligne du fichier doit contenir les titres des colonnes (Tâche, Projet, Statut…). Colonnes lues : %s.',
                $header === [] ? 'aucune' : implode(', ', array_map(static fn (string $h): string => $h === '' ? '(vide)' : '« ' . $h . ' »', $header)),
            );

            return $report;
        }

        // ── Données déjà en base (projets par nom, titres pour les doublons) ──
        $projectsByKey = [];
        foreach ($this->projectRepository->findBy([], ['id' => 'ASC']) as $project) {
            $projectsByKey[self::key($project->getName())] ??= $project;
        }
        $seen = [];
        foreach ($this->taskRepository->findAllTitles() as $existing) {
            $seen[($existing['projectId'] ?? 0) . '|' . self::key($existing['title'])] = true;
        }
        $newProjects = [];
        $members     = $this->memberService->getMembers();

        // ── Lignes de données ────────────────────────────────────────────────
        $dataRows = 0;
        foreach (array_slice($records, $headerLine + 1, null, true) as $index => $record) {
            $cell = static fn (string $field): string => isset($map[$field]) ? ($record[$map[$field]] ?? '') : '';
            $line = $index + 1;

            $title       = self::clean($cell('title'));
            $projectName = mb_substr(self::clean($cell('project')), 0, 150);
            if ($title === '' && $projectName === '') {
                continue; // ligne vide
            }

            // ── Projet : existant (même nom, sans accents ni casse) ou à créer ──
            $project    = null;
            $projectKey = self::key($projectName);
            if ($projectKey !== '') {
                $project = $projectsByKey[$projectKey] ?? null;
                if ($project === null && !isset($newProjects[$projectKey])) {
                    $newProjects[$projectKey] = $projectName;
                }
            }

            // Ligne « projet seul » (sans tâche) : le projet est créé, rien d'autre.
            if ($title === '') {
                continue;
            }

            if (++$dataRows > self::MAX_ROWS) {
                $report->warnings[] = sprintf('Seules les %d premières tâches sont importées : découpe le fichier pour la suite.', self::MAX_ROWS);
                break;
            }

            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 254) . '…';
                $report->warnings[] = sprintf('Ligne %d : titre raccourci à 255 caractères.', $line);
            }

            // ── Doublon : même titre dans le même projet (en base ou plus haut dans le fichier) ──
            $dupKey = ($project !== null ? (string) $project->getId() : ($projectKey !== '' ? 'new:' . $projectKey : '0')) . '|' . self::key($title);
            if (isset($seen[$dupKey])) {
                ++$report->duplicates;
                continue;
            }
            $seen[$dupKey] = true;

            $row                 = new ProjectImportRow();
            $row->line           = $line;
            $row->title          = $title;
            $row->project        = $project;
            $row->newProjectName = $project === null && $projectKey !== '' ? $newProjects[$projectKey] : null;
            $row->status         = $this->parseStatus($cell('status'), $line, $report);
            $row->priority       = $this->parsePriority($cell('priority'), $line, $report);

            $description = trim(str_replace("\r\n", "\n", $cell('description')));
            if (mb_strlen($description) > self::MAX_DESCRIPTION) {
                $description = mb_substr($description, 0, self::MAX_DESCRIPTION);
                $report->warnings[] = sprintf('Ligne %d : notes raccourcies à %d caractères.', $line, self::MAX_DESCRIPTION);
            }
            $row->description = $description !== '' ? $description : null;

            // ── Dates : « Date prévue » sert d'échéance quand il n'y en a pas ──
            $due     = $this->parseDate($cell('dueDate'), $line, 'échéance', $report);
            $planned = $this->parseDate($cell('plannedDate'), $line, 'date prévue', $report);
            $row->dueDate   = $due ?? $planned;
            $row->startDate = ($due !== null && $planned !== null && $planned <= $due) ? $planned : null;
            // Même règle que le formulaire de tâche (le début ne peut pas suivre l'échéance) :
            // on garde l'échéance et on le signale, plutôt que de perdre la date en silence.
            if ($due !== null && $planned !== null && $planned > $due) {
                $report->warnings[] = sprintf('Ligne %d : date prévue (%s) après l\'échéance (%s), seule l\'échéance est gardée.', $line, $planned->format('d/m/Y'), $due->format('d/m/Y'));
            }

            // ── Personnes ────────────────────────────────────────────────────
            foreach (self::splitList($cell('assignees'), '/\s*(?:[,;\/+&\n]|\set\s)\s*/iu') as $name) {
                $member = $this->matchMember($name, $members, $actor);
                if ($member === null) {
                    $report->warnings[] = sprintf('Ligne %d : « %s » ne correspond à aucune membre de l\'équipe (tâche laissée sans cette personne).', $line, $name);
                } elseif (!in_array($member, $row->assignees, true)) {
                    $row->assignees[] = $member;
                }
            }
            $createdBy      = self::clean($cell('createdBy'));
            $row->createdBy = $createdBy !== '' ? ($this->matchMember($createdBy, $members, $actor) ?? $actor) : $actor;

            // ── Étiquettes ───────────────────────────────────────────────────
            foreach (self::splitList($cell('labels'), '/\s*[,;\n]\s*/u') as $label) {
                $label = mb_substr($label, 0, 40);
                if (count($row->labels) < self::MAX_LABELS && !in_array(self::key($label), array_map(self::key(...), $row->labels), true)) {
                    $row->labels[] = $label;
                }
            }

            // ── Liens (plusieurs possibles, séparés par des espaces ou retours à la ligne) ──
            foreach (self::splitList($cell('link'), '/\s+/u') as $url) {
                if (ProjectAttachmentService::isSafeLinkUrl($url)) {
                    $row->links[] = $url;
                } else {
                    $report->warnings[] = sprintf('Ligne %d : « %s » n\'est pas un lien web (http:// ou https://), il n\'est pas joint.', $line, mb_strimwidth($url, 0, 60, '…'));
                }
            }

            // ── Sous-tâches : une par ligne (ou séparées par « | »), « [x] » = faite ──
            foreach (self::splitList($cell('subtasks'), '/\s*(?:\n|\|)\s*/u') as $item) {
                if (count($row->subtasks) >= self::MAX_SUBTASKS) {
                    $report->warnings[] = sprintf('Ligne %d : seules les %d premières sous-tâches sont gardées.', $line, self::MAX_SUBTASKS);
                    break;
                }
                $done  = preg_match('/^(?:\[[xX✓]\]|[✓✔☑])\s*/u', $item) === 1;
                $item  = trim((string) preg_replace('/^(?:\[[ xX✓]?\]|[✓✔☑☐•\-*])\s*/u', '', $item));
                if ($item !== '') {
                    $row->subtasks[] = ['title' => mb_substr($item, 0, 255), 'done' => $done];
                }
            }

            $report->rows[] = $row;
        }

        $report->newProjects = array_values($newProjects);
        if ($report->rows === [] && $report->newProjects === [] && $report->duplicates === 0) {
            $report->error = 'Aucune tâche trouvée sous la ligne des titres de colonnes.';
        }

        return $report;
    }

    /**
     * Analyse puis enregistre (projets, tâches, personnes, étiquettes, sous-tâches,
     * liens) en une seule transaction. Rien n'est enregistré si le rapport a une erreur.
     */
    public function import(string $content, User $actor): ProjectImportReport
    {
        $report = $this->analyze($content, $actor);
        if (!$report->isImportable()) {
            return $report;
        }

        $this->em->wrapInTransaction(function () use ($report, $actor): void {
            $this->persist($report, $actor);
        });

        return $report;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Enregistrement
    // ═════════════════════════════════════════════════════════════════════════

    private function persist(ProjectImportReport $report, User $actor): void
    {
        // ── Nouveaux projets (couleurs de la palette, à tour de rôle) ─────────
        $created    = [];
        $colorIndex = $this->projectRepository->count([]);
        foreach ($report->newProjects as $name) {
            $project = (new Project())
                ->setName($name)
                ->setColor(ProjectService::COLORS[$colorIndex++ % count(ProjectService::COLORS)])
                ->setOwner($actor)
                ->setCreatedBy($actor);
            $this->em->persist($project);
            $created[self::key($name)] = $project;
            $this->activityLogger->log(ProjectActivity::PROJECT_CREATED, sprintf('a créé le projet %s (import)', ProjectActivityLogger::quote($name)), $actor, $project);
        }

        // ── Tâches ───────────────────────────────────────────────────────────
        $positions = [];
        $labels    = [];
        foreach ($report->rows as $row) {
            $status = $row->status;
            $positions[$status->value] ??= $this->taskRepository->nextPosition($status);

            $task = (new ProjectTask())
                ->setTitle($row->title)
                ->setDescription($row->description)
                ->setStatus($status)
                ->setPriority($row->priority)
                ->setStartDate($row->startDate)
                ->setDueDate($row->dueDate)
                ->setPosition($positions[$status->value]++)
                ->setCreatedBy($row->createdBy ?? $actor)
                ->setProject($row->project ?? ($row->newProjectName !== null ? $created[self::key($row->newProjectName)] : null));

            foreach ($row->assignees as $member) {
                $task->addAssignee($member);
            }
            foreach ($row->labels as $name) {
                $task->addLabel($labels[self::key($name)] ??= $this->findOrCreateLabel($name, count($labels)));
            }
            foreach ($row->subtasks as $i => $item) {
                $task->addSubtask((new ProjectSubtask())->setTitle($item['title'])->setPosition($i)->setDone($item['done']));
            }
            foreach ($row->links as $url) {
                $this->em->persist((new ProjectAttachment())
                    ->setSource(ProjectAttachmentSource::Link)
                    ->setName(self::linkName($url))
                    ->setUrl($url)
                    ->setAddedBy($actor)
                    ->setTask($task));
            }

            $this->em->persist($task);
        }

        // Une seule ligne dans le fil d'activité (pas une par tâche).
        $message = sprintf('a importé %d tâche%s depuis un tableur', count($report->rows), count($report->rows) > 1 ? 's' : '');
        if ($report->newProjects !== []) {
            $message .= sprintf(' (%d projet%s créé%s)', count($report->newProjects), count($report->newProjects) > 1 ? 's' : '', count($report->newProjects) > 1 ? 's' : '');
        }
        $this->activityLogger->log(ProjectActivity::TASKS_IMPORTED, $message, $actor);
    }

    /** Étiquette existante (même nom, sans casse) ou nouvelle, colorée à tour de rôle. */
    private function findOrCreateLabel(string $name, int $offset): ProjectLabel
    {
        $existing = $this->labelRepository->findOneByNameInsensitive($name);
        if ($existing !== null) {
            return $existing;
        }

        $color = ProjectService::COLORS[($this->labelRepository->count([]) + $offset) % count(ProjectService::COLORS)];
        $label = (new ProjectLabel())->setName($name)->setColor($color);
        $this->em->persist($label);

        return $label;
    }

    /** Nom lisible d'un lien joint : « Google Docs », « Google Sheets »… sinon le domaine. */
    private static function linkName(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            $host === 'docs.google.com' && str_starts_with($path, '/document')     => 'Google Docs',
            $host === 'docs.google.com' && str_starts_with($path, '/spreadsheets') => 'Google Sheets',
            $host === 'docs.google.com' && str_starts_with($path, '/presentation') => 'Google Slides',
            $host === 'docs.google.com' && str_starts_with($path, '/forms')        => 'Google Forms',
            $host === 'drive.google.com'                                           => 'Google Drive',
            default                                                                => $host !== '' ? $host : 'Lien',
        };
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Lecture du fichier
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Découpe le CSV en lignes de cellules (déjà « trimées »).
     * Accepte « , » (Google Sheets), « ; » (Excel en français) et la tabulation.
     *
     * @return list<list<string>>|null null si le fichier est illisible (erreur dans $report)
     */
    private function readCsv(string $content, ProjectImportReport $report): ?array
    {
        if (str_starts_with($content, "PK\x03\x04")) {
            $report->error = 'C\'est un fichier Excel (.xlsx). Ouvre-le dans Google Sheets ou Excel, va sur l\'onglet des tâches, puis Fichier > Télécharger > CSV, et importe ce fichier CSV.';

            return null;
        }
        if (strlen($content) > self::MAX_BYTES) {
            $report->error = 'Fichier trop lourd (1 Mo maximum).';

            return null;
        }

        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM UTF-8 (Excel)
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Ancien Excel Windows : on convertit plutôt que d'afficher des « Ã© ».
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        if (trim($content) === '') {
            $report->error = 'Le fichier est vide.';

            return null;
        }

        // Séparateur : celui qui apparaît le plus sur la première ligne.
        // (Le contenu n'est pas vide après trim() : strtok() trouve forcément une ligne.)
        $firstLine = strtok($content, "\n");
        $counts    = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $report->error = 'Lecture du fichier impossible.';

            return null;
        }
        fwrite($stream, $content);
        rewind($stream);

        $records = [];
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $records[] = array_map(static fn (?string $value): string => trim((string) $value), $record);
            // Garde-fou : un fichier de plus de 5 000 lignes n'est pas un suivi de tâches.
            if (count($records) > 5000) {
                break;
            }
        }
        fclose($stream);

        return $records;
    }

    /**
     * Associe chaque champ à l'index de sa colonne (la première colonne reconnue gagne).
     *
     * @param list<string> $header
     *
     * @return array<string, int>
     */
    private function mapColumns(array $header, ProjectImportReport $report): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $key   = self::key($name);
            $field = null;
            foreach (self::HEADER_ALIASES as $candidate => $aliases) {
                if (!isset($map[$candidate]) && in_array($key, $aliases, true)) {
                    $field = $candidate;
                    break;
                }
            }

            if ($field !== null) {
                $map[$field]            = $index;
                $report->columns[$name] = self::FIELD_LABELS[$field];
            } elseif ($key !== '') {
                $report->ignoredColumns[] = $name;
            }
        }

        return $map;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Traduction des valeurs
    // ═════════════════════════════════════════════════════════════════════════

    private function parseStatus(string $value, int $line, ProjectImportReport $report): ProjectTaskStatus
    {
        $key = self::key($value);
        foreach (self::STATUS_ALIASES as $status => $aliases) {
            if (in_array($key, $aliases, true)) {
                return ProjectTaskStatus::from($status);
            }
        }
        $report->warnings[] = sprintf('Ligne %d : statut « %s » inconnu, la tâche est mise en « À faire ».', $line, $value);

        return ProjectTaskStatus::Todo;
    }

    private function parsePriority(string $value, int $line, ProjectImportReport $report): ProjectTaskPriority
    {
        $key = self::key($value);
        foreach (self::PRIORITY_ALIASES as $priority => $aliases) {
            if (in_array($key, $aliases, true)) {
                return ProjectTaskPriority::from($priority);
            }
        }
        $report->warnings[] = sprintf('Ligne %d : priorité « %s » inconnue, la tâche est mise en « Normale ».', $line, $value);

        return ProjectTaskPriority::Medium;
    }

    /**
     * Dates acceptées : 21/09/2026, 21/09/26, 21-09-2026, 21.09.2026, 21/09 (année
     * en cours), 2026-09-21 ou 2026/09/21 (avec ou sans heure), et numéro de série Excel (46286).
     */
    private function parseDate(string $value, int $line, string $label, ProjectImportReport $report): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ymd = null; // [année, mois, jour]
        if (preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})(?:[ T].*)?$/', $value, $m) === 1) {
            $ymd = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2}|\d{4}))?(?:\s.*)?$/', $value, $m) === 1) {
            $year = isset($m[3]) ? (int) $m[3] : (int) $this->clock->today()->format('Y');
            $ymd  = [$year < 100 ? $year + 2000 : $year, (int) $m[2], (int) $m[1]];
        } elseif (preg_match('/^\d{5}(?:[.,]\d+)?$/', $value) === 1 && (int) $value > 20000 && (int) $value < 80000) {
            // Excel compte les jours depuis le 30/12/1899.
            return (new \DateTimeImmutable('1899-12-30'))->modify(sprintf('+%d days', (int) $value));
        }

        if ($ymd === null || !checkdate($ymd[1], $ymd[2], $ymd[0])) {
            $report->warnings[] = sprintf('Ligne %d : %s « %s » non reconnue (format attendu : 21/09/2026), laissée vide.', $line, $label, $value);

            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', ...$ymd));
    }

    /**
     * Retrouve une membre : email exact, prénom, nom complet ou début d'email ;
     * à défaut, un morceau d'au moins 4 lettres de l'email (« Gaëlle » → hello@gaellecode.fr).
     * « moi » désigne la personne qui importe. Ambigu (plusieurs membres) = null.
     *
     * @param list<User> $members
     */
    private function matchMember(string $name, array $members, User $actor): ?User
    {
        $key = self::key($name);
        if ($key === '') {
            return null;
        }
        if ($key === 'moi') {
            return $actor;
        }

        $exact   = [];
        $partial = [];
        foreach ($members as $member) {
            $names = array_filter([
                self::key((string) $member->getFirstName()),
                self::key($member->getFullName()),
                self::key(explode('@', $member->getEmail())[0]),
                self::key($member->getEmail()),
            ]);
            if (in_array($key, $names, true)) {
                $exact[] = $member;
            } elseif (strlen($key) >= 4 && array_filter($names, static fn (string $n): bool => str_contains($n, $key)) !== []) {
                $partial[] = $member;
            }
        }

        if (count($exact) === 1) {
            return $exact[0];
        }

        return $exact === [] && count($partial) === 1 ? $partial[0] : null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Outils texte
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Forme « normalisée » pour comparer sans se soucier des accents, de la casse,
     * des espaces ni de la ponctuation : « Échéance : » → « echeance ».
     */
    public static function key(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', u($value)->ascii()->lower()->toString());
    }

    /** Texte sur une ligne : retours à la ligne et espaces multiples réduits. */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $value));
    }

    /** @return list<string> éléments non vides d'une cellule « liste » */
    private static function splitList(string $value, string $pattern): array
    {
        $value = str_replace("\r\n", "\n", trim($value));
        if ($value === '') {
            return [];
        }
        $parts = preg_split($pattern, $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }
}
