<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Enum\ProjectTaskPriority;

/**
 * ProjectTemplateCatalog — modèles de projets prêts à l'emploi (ADR-0037).
 *
 * Bazaart monte souvent les mêmes types de projets (événements, formations Studio,
 * candidatures du Lab, campagnes de communication). Plutôt que de ressaisir à
 * chaque fois les mêmes tâches, on choisit un modèle à la création du projet :
 * les tâches sont générées automatiquement.
 *
 * `offset` = nombre de jours par rapport à l'ÉCHÉANCE du projet :
 *   -30 = « J-30 », 0 = le jour J, +7 = une semaine après.
 * Si le projet n'a pas d'échéance, les tâches sont créées sans date.
 *
 * Pour ajouter / modifier un modèle : il suffit d'éditer la constante TEMPLATES,
 * aucune migration n'est nécessaire (les modèles ne sont pas stockés en base).
 */
final class ProjectTemplateCatalog
{
    /**
     * @var array<string, array{label: string, description: string, tasks: list<array{title: string, offset: int, priority: ProjectTaskPriority, subtasks?: list<string>}>}>
     */
    private const array TEMPLATES = [
        'evenement' => [
            'label'       => 'Événement',
            'description' => 'Festival, exposition, soirée, marché d\'artistes…',
            'tasks'       => [
                ['title' => 'Définir le concept, le public et le budget prévisionnel', 'offset' => -90, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Réserver le lieu et fixer la date', 'offset' => -75, 'priority' => ProjectTaskPriority::Urgent, 'subtasks' => ['Visiter les lieux', 'Signer la convention', 'Vérifier l\'assurance']],
                ['title' => 'Programmation : appel à artistes et sélection', 'offset' => -60, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Demandes de subventions et de partenariats', 'offset' => -60, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Plan de communication', 'offset' => -45, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Ouvrir la billetterie ou les inscriptions', 'offset' => -30, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Logistique : technique, matériel, catering', 'offset' => -21, 'priority' => ProjectTaskPriority::Medium, 'subtasks' => ['Fiche technique', 'Location du matériel', 'Traiteur']],
                ['title' => 'Visuels et publications réseaux sociaux', 'offset' => -14, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Brief de l\'équipe et des bénévoles', 'offset' => -3, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Jour J : installation et accueil', 'offset' => 0, 'priority' => ProjectTaskPriority::Urgent],
                ['title' => 'Bilan, photos et remerciements aux partenaires', 'offset' => 7, 'priority' => ProjectTaskPriority::Medium],
            ],
        ],
        'formation' => [
            'label'       => 'Formation (Studio)',
            'description' => 'Atelier, masterclass ou parcours de formation.',
            'tasks'       => [
                ['title' => 'Définir les objectifs pédagogiques et le programme', 'offset' => -60, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Contacter et contractualiser les intervenant·es', 'offset' => -50, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Préparer les supports de cours', 'offset' => -30, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Publier la formation sur app.bazaart.fr', 'offset' => -30, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Communication et relances des inscriptions', 'offset' => -14, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Préparer la salle ou le lien de visio', 'offset' => -2, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Session de formation', 'offset' => 0, 'priority' => ProjectTaskPriority::Urgent],
                ['title' => 'Questionnaire de satisfaction et attestations', 'offset' => 3, 'priority' => ProjectTaskPriority::Medium],
            ],
        ],
        'candidature' => [
            'label'       => 'Candidature / appel à projets',
            'description' => 'Subvention, résidence, appel à projets du Lab.',
            'tasks'       => [
                ['title' => 'Lire le règlement et vérifier l\'éligibilité', 'offset' => -30, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Rassembler les pièces administratives', 'offset' => -21, 'priority' => ProjectTaskPriority::Medium, 'subtasks' => ['Statuts', 'Kbis ou avis SIRENE', 'RIB', 'Bilan de l\'année précédente']],
                ['title' => 'Rédiger la note d\'intention', 'offset' => -14, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Construire le budget prévisionnel', 'offset' => -14, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Relecture croisée du dossier', 'offset' => -5, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Déposer le dossier', 'offset' => -1, 'priority' => ProjectTaskPriority::Urgent],
                ['title' => 'Suivre la réponse', 'offset' => 30, 'priority' => ProjectTaskPriority::Low],
            ],
        ],
        'campagne' => [
            'label'       => 'Campagne de communication',
            'description' => 'Lancement, appel à participation, temps fort sur les réseaux.',
            'tasks'       => [
                ['title' => 'Brief : objectifs, cibles et messages clés', 'offset' => -21, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Calendrier éditorial', 'offset' => -18, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Création des visuels', 'offset' => -10, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Rédaction des textes et légendes', 'offset' => -10, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Validation collective', 'offset' => -7, 'priority' => ProjectTaskPriority::High],
                ['title' => 'Programmation des publications', 'offset' => -3, 'priority' => ProjectTaskPriority::Medium],
                ['title' => 'Lancement', 'offset' => 0, 'priority' => ProjectTaskPriority::Urgent],
                ['title' => 'Bilan des statistiques', 'offset' => 14, 'priority' => ProjectTaskPriority::Low],
            ],
        ],
    ];

    /**
     * Liste pour le formulaire de création : clé → [libellé, description, nb de tâches].
     *
     * @return array<string, array{label: string, description: string, count: int}>
     */
    public function all(): array
    {
        $list = [];
        foreach (self::TEMPLATES as $key => $template) {
            $list[$key] = [
                'label'       => $template['label'],
                'description' => $template['description'],
                'count'       => count($template['tasks']),
            ];
        }

        return $list;
    }

    public function has(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    public function label(string $key): string
    {
        return self::TEMPLATES[$key]['label'] ?? $key;
    }

    /**
     * Tâches à créer pour un modèle, avec leur échéance calculée.
     *
     * @return list<array{title: string, dueDate: \DateTimeImmutable|null, priority: ProjectTaskPriority, subtasks: list<string>}>
     */
    public function buildTasks(string $key, ?\DateTimeImmutable $projectDueDate): array
    {
        if (!$this->has($key)) {
            return [];
        }

        $tasks = [];
        foreach (self::TEMPLATES[$key]['tasks'] as $task) {
            // modify('+0 days') / ('-30 days') : DateTimeImmutable renvoie une NOUVELLE date.
            $due = $projectDueDate?->modify(sprintf('%+d days', $task['offset']));
            $tasks[] = [
                'title'    => $task['title'],
                'dueDate'  => $due,
                'priority' => $task['priority'],
                'subtasks' => $task['subtasks'] ?? [],
            ];
        }

        return $tasks;
    }
}
