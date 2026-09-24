<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectNote;
use App\Entity\ProjectSubtask;
use App\Entity\ProjectTask;
use App\Entity\ProjectTaskComment;
use App\Entity\User;
use App\Enum\ProjectTaskStatus;

/**
 * ProjectSpaceTest — parcours fonctionnels de l'Espace projets (ADR-0037).
 *
 * Couvre :
 *   - les droits d'accès (anonyme, admin sans ROLE_PROJECT, membre) ;
 *   - le rendu de TOUTES les pages et de toutes les vues des tâches ;
 *   - la création de projet à partir d'un modèle ;
 *   - l'ajout rapide de tâche (et le filtrage des assignations aux seules membres) ;
 *   - le glisser-déposer (endpoint JSON) avec vérification CSRF ;
 *   - la checklist, les commentaires signés, les notes signées et leurs droits ;
 *   - l'export tableur (protection contre l'injection de formules) ;
 *   - le Drive non connecté (page + API JSON).
 */
class ProjectSpaceTest extends AbstractE2ETestCase
{
    private User $gaelle;
    private User $wendie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();

        $this->gaelle = $this->createTestUser('g.charlesbel@test.fr', 'TestPass12!', ['ROLE_ADMIN', 'ROLE_PROJECT']);
        $this->gaelle->setFirstName('Gaëlle')->setLastName('Charles');
        // Membre NON admin : elle ne doit voir que l'Espace projets.
        $this->wendie = $this->createTestUser('wendie@test.fr', 'TestPass12!', ['ROLE_PROJECT']);
        $this->wendie->setFirstName('Wendie');
        $this->em->flush();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Accès
    // ═════════════════════════════════════════════════════════════════════

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/projets');

        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAdminWithoutProjectRoleIsForbidden(): void
    {
        // ROLE_ADMIN n'hérite PAS de ROLE_PROJECT : les notes internes restent privées.
        $this->loginAs($this->createAdminUser());
        $this->client->request('GET', '/admin/projets');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonAdminMemberSeesOnlyTheProjectSpace(): void
    {
        $this->loginAs($this->wendie);
        $this->client->request('GET', '/admin/projets');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('nav[aria-label="Espace projets"]');
        // Les sections réservées aux admins ne sont pas affichées…
        $this->assertSelectorNotExists('nav[aria-label="Pilotage"]');
        // …et restent interdites si on tape l'URL.
        $this->client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Onboarding
    // ═════════════════════════════════════════════════════════════════════

    public function testGuidedTourIsShownOnceThenMarkedAsSeen(): void
    {
        $this->loginAs($this->wendie);
        $crawler = $this->client->request('GET', '/admin/projets');
        self::assertSame('1', $crawler->filter('#pm-root')->attr('data-show-tour'));
        self::assertCount(7, $crawler->filter('.pm-step'), 'La checklist « Bien démarrer » compte 7 étapes.');

        $this->postJson('/admin/projets/onboarding/visite', [], $this->ajaxToken());
        $this->assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/admin/projets');
        self::assertSame('0', $crawler->filter('#pm-root')->attr('data-show-tour'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Projets
    // ═════════════════════════════════════════════════════════════════════

    public function testCreateProjectFromTemplateGeneratesPlannedTasks(): void
    {
        $this->loginAs($this->gaelle);
        $crawler = $this->client->request('GET', '/admin/projets/projets/nouveau');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form.pm-card')->form([
            'name'     => 'Festival Bazaart 2027',
            'color'    => '#FF6B2C',
            'status'   => 'active',
            'priority' => 'high',
            'ownerId'  => (string) $this->wendie->getId(),
            'dueDate'  => '2027-06-15',
            'template' => 'evenement',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $project = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Festival Bazaart 2027']);
        self::assertNotNull($project);
        self::assertSame($this->wendie->getId(), $project->getOwner()?->getId());

        $tasks = $this->em->getRepository(ProjectTask::class)->findBy(['project' => $project]);
        self::assertCount(11, $tasks, 'Le modèle « Événement » crée 11 tâches.');
        $dueDates = array_map(static fn (ProjectTask $t): string => (string) $t->getDueDate()?->format('Y-m-d'), $tasks);
        self::assertContains('2027-06-15', $dueDates, 'La tâche « Jour J » tombe le jour de l\'échéance.');
        self::assertContains('2027-03-17', $dueDates, 'J-90 est calculé à rebours depuis l\'échéance.');
        self::assertTrue($tasks[0]->isAssignedTo($this->wendie), 'Les tâches du modèle sont confiées à la responsable.');

        // La fiche projet, la liste et la chronologie s'affichent.
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.pm-hero', 'Festival Bazaart 2027');
        foreach (['/admin/projets/projets', '/admin/projets/projets?view=timeline&from=2027-04', '/admin/projets/projets?status=all', '/admin/projets/projets/' . $project->getId() . '?view=list', '/admin/projets/projets/' . $project->getId() . '/modifier'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }
    }

    public function testOnlyCreatorOwnerOrAdminCanDeleteProject(): void
    {
        $project = $this->createProject('Projet de Gaëlle', $this->gaelle);

        // Wendie n'est ni créatrice, ni responsable, ni admin.
        $this->loginAs($this->wendie);
        $crawler = $this->client->request('GET', '/admin/projets/projets/' . $project->getId());
        $this->assertSelectorNotExists('form[action$="/supprimer"] .pm-btn--danger');
        $this->client->request('POST', '/admin/projets/projets/' . $project->getId() . '/supprimer', [
            '_token' => $this->tokenFor('pm_project_delete_' . $project->getId()),
        ]);
        $this->assertResponseStatusCodeSame(403);

        $this->loginAs($this->gaelle);
        $this->client->request('POST', '/admin/projets/projets/' . $project->getId() . '/supprimer', [
            '_token' => $this->tokenFor('pm_project_delete_' . $project->getId()),
        ]);
        $this->assertResponseRedirects('/admin/projets/projets');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Project::class)->find($project->getId()));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Tâches
    // ═════════════════════════════════════════════════════════════════════

    public function testAllTaskViewsAndFiltersRender(): void
    {
        $project = $this->createProject('Formation hiver', $this->gaelle);
        $this->createTask('Préparer les supports', $project, [$this->wendie], new \DateTimeImmutable('-3 days'));
        $this->createTask('Tâche volante', null, [], null);

        $this->loginAs($this->gaelle);
        $urls = [
            '/admin/projets/taches?view=kanban',
            '/admin/projets/taches?view=list&sort=due&dir=desc',
            '/admin/projets/taches?view=calendar',
            '/admin/projets/taches?view=calendar&month=2026-02',
            '/admin/projets/taches?view=person',
            '/admin/projets/taches?view=priority&done=show',
            '/admin/projets/taches?view=kanban&assignee=me&due=overdue&priority=urgent&q=supp',
            '/admin/projets/taches?view=list&project=none&assignee=none&status=todo&due=none&done=hide',
            // Valeurs invalides : ignorées, jamais d'erreur 500
            '/admin/projets/taches?view=nimportequoi&priority=xxx&project=abc&due=hier&month=2026-13',
            '/admin/projets',
            '/admin/projets/equipe',
            '/admin/projets/drive',
            '/admin/projets/notes',
            '/admin/projets/taches/nouvelle?dueDate=2026-10-03&status=review&assignee=' . $this->wendie->getId(),
        ];
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }

        // Le filtre « en retard » de Wendie retrouve sa tâche
        $crawler = $this->client->request('GET', '/admin/projets/taches?view=list&assignee=' . $this->wendie->getId() . '&due=overdue');
        self::assertStringContainsString('Préparer les supports', $crawler->filter('.pm-table')->text());
        self::assertStringNotContainsString('Tâche volante', $crawler->filter('.pm-table')->text());
    }

    public function testQuickAddOnlyAssignsTeamMembers(): void
    {
        $outsider = $this->createArtistUser('artiste@test.fr');
        $this->loginAs($this->gaelle);

        $this->client->request('POST', '/admin/projets/taches/nouvelle', [
            '_token'    => $this->tokenFor('pm_task_new'),
            'quick'     => '1',
            '_back'     => '/admin/projets/taches?view=kanban',
            'title'     => 'Réserver la salle',
            'status'    => 'in_progress',
            'assignees' => [(string) $this->wendie->getId(), (string) $outsider->getId()],
            'newLabels' => 'Logistique, Budget',
        ]);
        $this->assertResponseRedirects('/admin/projets/taches?view=kanban');

        $task = $this->em->getRepository(ProjectTask::class)->findOneBy(['title' => 'Réserver la salle']);
        self::assertNotNull($task);
        self::assertSame(ProjectTaskStatus::InProgress, $task->getStatus());
        self::assertCount(1, $task->getAssignees(), 'Une personne hors équipe ne peut pas être assignée.');
        self::assertTrue($task->isAssignedTo($this->wendie));
        self::assertCount(2, $task->getLabels());
    }

    public function testOpenRedirectIsBlockedOnBackParameter(): void
    {
        $this->loginAs($this->gaelle);
        $this->client->request('POST', '/admin/projets/taches/nouvelle', [
            '_token' => $this->tokenFor('pm_task_new'),
            'quick'  => '1',
            '_back'  => 'https://site-malveillant.example/phishing',
            'title'  => 'Test redirection',
        ]);

        $this->assertResponseRedirects('/admin/projets/taches');
    }

    public function testDragAndDropEndpointMovesTaskAndChecksCsrf(): void
    {
        $task  = $this->createTask('Relancer le traiteur', null, [$this->gaelle], null);
        // Une tâche déjà « À valider » : la colonne d'arrivée n'est pas vide.
        $other = $this->createTask('Autre tâche', null, [], null)->setStatus(ProjectTaskStatus::Review);
        $this->em->flush();
        $this->loginAs($this->gaelle);
        $token = $this->ajaxToken();

        // Sans jeton CSRF : refusé
        $this->postJson('/admin/projets/taches/' . $task->getId() . '/deplacer', ['field' => 'status', 'value' => 'done'], 'mauvais-jeton');
        $this->assertResponseStatusCodeSame(403);

        // Kanban : changement de statut + ordre de la colonne
        $this->postJson('/admin/projets/taches/' . $task->getId() . '/deplacer', [
            'field' => 'status', 'value' => 'review', 'orderedIds' => [$other->getId(), $task->getId()],
        ], $token);
        $this->assertResponseIsSuccessful();

        // Par personne : réassignation de Gaëlle vers Wendie
        $this->postJson('/admin/projets/taches/' . $task->getId() . '/deplacer', [
            'field' => 'assignee', 'value' => (string) $this->wendie->getId(), 'fromUserId' => $this->gaelle->getId(),
        ], $token);
        $this->assertResponseIsSuccessful();

        // Calendrier : replanification
        $this->postJson('/admin/projets/taches/' . $task->getId() . '/deplacer', ['field' => 'dueDate', 'value' => '2026-11-05'], $token);
        $this->assertResponseIsSuccessful();

        // Valeurs invalides : 400 propre
        $this->postJson('/admin/projets/taches/' . $task->getId() . '/deplacer', ['field' => 'title', 'value' => 'x'], $token);
        $this->assertResponseStatusCodeSame(400);
        $this->postJson('/admin/projets/taches/' . $task->getId() . '/deplacer', ['field' => 'dueDate', 'value' => '2026-02-31'], $token);
        $this->assertResponseStatusCodeSame(400);

        $this->em->clear();
        $task = $this->em->getRepository(ProjectTask::class)->find($task->getId());
        self::assertSame(ProjectTaskStatus::Review, $task->getStatus());
        self::assertSame(1, $task->getPosition());
        self::assertSame(0, $this->em->getRepository(ProjectTask::class)->find($other->getId())?->getPosition());
        self::assertTrue($task->isAssignedTo($this->wendie));
        self::assertFalse($task->isAssignedTo($this->gaelle));
        self::assertSame('2026-11-05', $task->getDueDate()?->format('Y-m-d'));

        // Le journal d'activité a gardé la trace du déplacement (étape d'onboarding « move »)
        self::assertGreaterThan(0, $this->em->getRepository(ProjectActivity::class)->count(['action' => ProjectActivity::TASK_MOVED]));
    }

    public function testReorderingInProjectBoardKeepsOtherProjectsInPlace(): void
    {
        // Colonne « À faire » globale : A (projet P), B (sans projet), C (projet P)
        $project = $this->createProject('Projet P', $this->gaelle);
        $a = $this->createTask('A', $project, [], null)->setPosition(0);
        $b = $this->createTask('B', null, [], null)->setPosition(1);
        $c = $this->createTask('C', $project, [], null)->setPosition(2);
        $this->em->flush();

        // Dans le Kanban de la fiche projet, on ne voit que A et C : on met C avant A.
        $this->loginAs($this->gaelle);
        $this->postJson('/admin/projets/taches/' . $c->getId() . '/deplacer', [
            'field' => 'status', 'value' => 'todo', 'orderedIds' => [$c->getId(), $a->getId()],
        ], $this->ajaxToken());
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $repo = $this->em->getRepository(ProjectTask::class);
        self::assertSame(0, $repo->find($c->getId())?->getPosition(), 'C prend la place de A');
        self::assertSame(1, $repo->find($b->getId())?->getPosition(), 'B (autre projet) ne bouge pas');
        self::assertSame(2, $repo->find($a->getId())?->getPosition(), 'A prend la place de C');
    }

    public function testTaskPageChecklistAndSignedComments(): void
    {
        $task = $this->createTask('Préparer la soirée', null, [$this->wendie], null);
        $this->loginAs($this->gaelle);

        $this->client->request('GET', '/admin/projets/taches/' . $task->getId());
        $this->assertResponseIsSuccessful();
        $taskToken = $this->getCsrfTokenFromHtml('#pm-task-form input[name="_token"]');

        // Sous-tâche + coche en JSON
        $this->client->request('POST', '/admin/projets/taches/' . $task->getId() . '/sous-taches', ['_token' => $taskToken, 'title' => 'Confirmer le DJ']);
        $subtask = $this->em->getRepository(ProjectSubtask::class)->findOneBy(['title' => 'Confirmer le DJ']);
        self::assertNotNull($subtask);
        $this->postJson('/admin/projets/sous-taches/' . $subtask->getId() . '/cocher', [], $this->ajaxToken());
        $this->assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['done']);

        // Commentaire signé ; les URL deviennent des liens, le HTML est échappé
        $this->client->request('POST', '/admin/projets/taches/' . $task->getId() . '/commentaires', [
            '_token'  => $taskToken,
            'content' => 'Voir https://exemple.org/doc <script>alert(1)</script>',
        ]);
        $crawler = $this->client->request('GET', '/admin/projets/taches/' . $task->getId());
        self::assertStringContainsString('Gaëlle Charles', $crawler->filter('.pm-comment__author')->text());
        $body = $crawler->filter('.pm-comment__body')->html();
        self::assertStringContainsString('<a href="https://exemple.org/doc"', $body);
        self::assertStringNotContainsString('<script>', $body);

        // Wendie ne peut pas supprimer le commentaire de Gaëlle
        $comment = $this->em->getRepository(ProjectTaskComment::class)->findOneBy(['task' => $task]);
        $this->loginAs($this->wendie);
        $this->client->request('GET', '/admin/projets/taches/' . $task->getId());
        $this->client->request('POST', '/admin/projets/commentaires/' . $comment->getId() . '/supprimer', [
            '_token' => $this->tokenFor('pm_comment_delete_' . $comment->getId()),
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCsvExportNeutralisesFormulas(): void
    {
        $this->createTask('=HYPERLINK("http://evil")', null, [], null);
        $this->loginAs($this->gaelle);

        $this->client->request('GET', '/admin/projets/taches/export.csv');
        $this->assertResponseIsSuccessful();
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Notes signées
    // ═════════════════════════════════════════════════════════════════════

    public function testNotesAreSignedAndOnlyEditableByAuthor(): void
    {
        $this->loginAs($this->gaelle);
        $this->client->request('GET', '/admin/projets/notes');
        $this->client->request('POST', '/admin/projets/notes', [
            '_token'  => $this->tokenFor('pm_note_new'),
            'title'   => 'Compte rendu',
            'content' => 'Réunion avec la mairie jeudi.',
            'color'   => 'blue',
        ]);
        $this->assertResponseRedirects();

        $note = $this->em->getRepository(ProjectNote::class)->findOneBy(['title' => 'Compte rendu']);
        self::assertNotNull($note);
        self::assertSame($this->gaelle->getId(), $note->getAuthor()?->getId());

        // Wendie voit la note ET son autrice…
        $this->loginAs($this->wendie);
        $crawler = $this->client->request('GET', '/admin/projets/notes');
        self::assertStringContainsString('Gaëlle Charles', $crawler->filter('#note-' . $note->getId() . ' .pm-note__sig')->text());
        // …mais n'a pas les boutons de modification
        self::assertCount(0, $crawler->filter('#note-edit-' . $note->getId()));

        $token = $this->tokenFor('pm_note_' . $note->getId());
        $this->client->request('POST', '/admin/projets/notes/' . $note->getId() . '/modifier', ['_token' => $token, 'content' => 'Piraté']);
        $this->assertResponseStatusCodeSame(403);

        // Épingler reste ouvert à toute l'équipe
        $this->client->request('POST', '/admin/projets/notes/' . $note->getId() . '/epingler', ['_token' => $token]);
        $this->assertResponseRedirects();
        $this->em->clear();
        $note = $this->em->getRepository(ProjectNote::class)->find($note->getId());
        self::assertTrue($note->isPinned());
        self::assertSame('Réunion avec la mairie jeudi.', $note->getContent());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Google Drive (non connecté)
    // ═════════════════════════════════════════════════════════════════════

    public function testDriveApiAnswersNotConnected(): void
    {
        $this->loginAs($this->gaelle);
        $this->client->request('GET', '/admin/projets/drive');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/admin/projets/drive/connexion"]');

        $this->client->request('GET', '/admin/projets/drive/api/dossier?id=root');
        $this->assertResponseStatusCodeSame(409);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
        self::assertTrue($data['notConnected']);
    }

    public function testDriveCallbackRejectsForgedState(): void
    {
        $this->loginAs($this->gaelle);
        $this->client->request('GET', '/admin/projets/drive/callback?code=abc&state=forged');

        $this->assertResponseRedirects('/admin/projets/drive');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash--error', 'Retour de Google invalide');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Outils
    // ═════════════════════════════════════════════════════════════════════

    private function createProject(string $name, User $creator): Project
    {
        $project = (new Project())->setName($name)->setCreatedBy($creator);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @param list<User> $assignees
     */
    private function createTask(string $title, ?Project $project, array $assignees, ?\DateTimeImmutable $due): ProjectTask
    {
        $task = (new ProjectTask())->setTitle($title)->setProject($project)->setDueDate($due?->setTime(0, 0));
        foreach ($assignees as $user) {
            $task->addAssignee($user);
        }
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /** Jeton CSRF des appels fetch(), lu dans la balise <meta> du layout. */
    private function ajaxToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/projets/equipe');

        return (string) $crawler->filter('meta[name="pm-csrf"]')->attr('content');
    }

    /**
     * Génère un jeton CSRF valide pour la session du navigateur de test.
     * On passe par une page du module pour initialiser la session, puis on lit
     * le gestionnaire de jetons du conteneur (même session que le client).
     */
    private function tokenFor(string $id): string
    {
        // Une requête préalable initialise la session du navigateur de test.
        $this->client->request('GET', '/admin/projets/equipe');
        $session = $this->client->getRequest()->getSession();
        $stack   = static::getContainer()->get('request_stack');
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();

        return $token;
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload, string $token): void
    {
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_ACCEPT'       => 'application/json',
        ], (string) json_encode($payload));
    }
}
