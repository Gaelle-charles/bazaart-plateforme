<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectAttachment;
use App\Entity\ProjectTask;
use App\Entity\User;
use App\Enum\ProjectTaskPriority;
use App\Enum\ProjectTaskStatus;
use Symfony\Component\DomCrawler\Crawler;

/**
 * ProjectImportAndProfileTest — retours de l'équipe après la mise en service (ADR-0037).
 *
 * Couvre :
 *   - le prénom / nom modifiables dans « Équipe et réglages » (et l'étape de checklist) ;
 *   - le lien « Espace projets » dans le menu du site, réservé à l'équipe ;
 *   - l'import de tâches depuis un CSV : aperçu sans enregistrement, confirmation,
 *     projets créés ou retrouvés, personnes, dates, sous-tâches, liens, doublons,
 *     fichiers refusés (Excel, colonne « Tâche » absente, import expiré).
 */
class ProjectImportAndProfileTest extends AbstractE2ETestCase
{
    private User $gaelle;
    private User $wendie;

    /** @var list<string> fichiers temporaires à supprimer en fin de test */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();

        // Comme en production : comptes créés par la commande, sans prénom.
        $this->gaelle = $this->createTestUser('hello@gaellecode.test', 'TestPass12!', ['ROLE_PROJECT']);
        $this->wendie = $this->createTestUser('zahibowendie@test.fr', 'TestPass12!', ['ROLE_PROJECT']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Prénom et nom
    // ═════════════════════════════════════════════════════════════════════

    public function testMemberCanSetHerNameFromPreferences(): void
    {
        $this->loginAs($this->gaelle);
        $crawler = $this->client->request('GET', '/admin/projets');
        self::assertFalse($this->checklistStep($crawler, 'Indiquer mon prénom')['done']);

        $crawler = $this->client->request('GET', '/admin/projets/equipe');
        self::assertStringContainsString('Tu apparais pour l\'instant comme Hello', $crawler->filter('#pm-me')->text());

        $form = $crawler->filter('#pm-me form')->form([
            'firstName' => '  Gaëlle ',
            'lastName'  => 'Charles-Belamour',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/projets/equipe');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($this->gaelle->getId());
        self::assertSame('Gaëlle', $user?->getFirstName());
        self::assertSame('Charles-Belamour', $user?->getLastName());

        $crawler = $this->client->request('GET', '/admin/projets/equipe');
        self::assertStringContainsString('Gaëlle Charles-Belamour', $crawler->filter('#pm-root')->text());
        self::assertCount(0, $crawler->filter('#pm-me .pm-note-info'), 'Le rappel disparaît une fois le prénom indiqué.');

        $crawler = $this->client->request('GET', '/admin/projets');
        self::assertCount(7, $crawler->filter('.pm-step'), 'La checklist « Bien démarrer » compte 7 étapes.');
        self::assertTrue($this->checklistStep($crawler, 'Indiquer mon prénom')['done']);
    }

    public function testEmptyFirstNameIsRejected(): void
    {
        $this->loginAs($this->gaelle);
        $crawler = $this->client->request('GET', '/admin/projets/equipe');
        $form    = $crawler->filter('#pm-me form')->form(['firstName' => '   ', 'lastName' => 'Test']);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/projets/equipe#pm-me');

        $this->client->followRedirect();
        self::assertStringContainsString('Indique au moins ton prénom.', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($this->gaelle->getId())?->getLastName());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Retour vers l'Espace projets depuis le site
    // ═════════════════════════════════════════════════════════════════════

    public function testSiteMenuLinksBackToProjectSpaceOnlyForTheTeam(): void
    {
        $this->loginAs($this->wendie);
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#user-menu a[href="/admin/projets"]');
        $this->assertSelectorExists('#nav-mobile a[href="/admin/projets"]');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.sd-nav a[href="/admin/projets"]');

        $this->loginAs($this->createArtistUser());
        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('a[href="/admin/projets"]');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Import de tâches
    // ═════════════════════════════════════════════════════════════════════

    public function testImportPreviewsThenCreatesTasksProjectsAndLinks(): void
    {
        // Déjà dans l'outil : un projet (retrouvé malgré la casse) et une tâche (doublon).
        $admin = (new Project())->setName('Administratif')->setCreatedBy($this->gaelle);
        $this->em->persist($admin);
        $this->em->persist((new ProjectTask())->setTitle('Réorganiser le Drive')->setProject($admin));
        $this->em->flush();

        // Même disposition que l'export CSV de « Toutes mes tâches » (Google Sheets).
        $csv = $this->csv([
            ['ID', 'Tâche', 'Projet', 'Sous-projet', 'Priorité', 'Statut', 'Échéance', 'Date prévue', 'Notes', 'Doc (URL)', '📄', 'Par', 'Assignée à', 'Sous-tâches'],
            ['T-0001', 'Faire un PV pour l\'AG du 18/09', 'administratif', '', 'Moyenne', 'À faire', '', '21/09/2026', '', '', '', 'Gaëlle', 'Gaëlle', "Envoyer à la banque\n[x] Retirer ses accès"],
            ['T-0002', 'Réorganiser le Drive', 'Administratif', '', 'Moyenne', 'À faire', '', '23/09/2026', '', '', '', 'Gaëlle', 'Gaëlle', ''],
            ['T-0005', 'Finaliser note de projet et note d’intention', 'Zagaza', '', 'Haute', 'En cours', '21/09/2026', '', '', '', '', 'Wendie', 'Wendie', ''],
            ['T-0006', 'Envoyer explication à Odile', 'Candidatures', '', 'Moyenne', 'Terminée', '', '', "Note de réponse\n\n1. Principe général", '', '', 'Wendie', 'zahibowendie@test.fr', ''],
            ['T-0011', 'Finaliser candidature "Impact Grant 2026"', 'Partenariat', '', 'Urgente', 'À faire', '30/09/2026', '', '', 'https://docs.google.com/document/d/1Jx4/edit?tab=t.0', '📄', 'Wendie', 'Wendie, Inconnue', ''],
            ['T-0012', 'Envoyer notes de projets aux institutions', 'Partenariat', '', 'Moyenne', 'Bizarre', '30/10/2026', '05/10/2026', '', 'javascript:alert(1)', '', 'Wendie', '', ''],
            ['', '', 'Institutions', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $this->loginAs($this->gaelle);
        $crawler = $this->uploadCsv($csv);
        $this->assertResponseIsSuccessful();

        // ── Aperçu : rien n'est encore enregistré ───────────────────────────
        $page = $crawler->filter('#pm-root')->text();
        self::assertStringContainsString('5 tâches à importer', $page);
        self::assertStringContainsString('4 projets à créer (Zagaza, Candidatures, Partenariat, Institutions)', $page);
        self::assertStringContainsString('1 déjà présente', $page);
        self::assertStringContainsString('« Inconnue » ne correspond à aucune membre', $page);
        self::assertStringContainsString('statut « Bizarre » inconnu', $page);
        self::assertStringContainsString('n\'est pas un lien web', $page);
        self::assertCount(5, $crawler->filter('.pm-table tbody tr'));
        self::assertSame(1, $this->em->getRepository(ProjectTask::class)->count([]), 'L\'aperçu n\'enregistre rien.');

        // ── Confirmation ────────────────────────────────────────────────────
        $this->client->submit($crawler->filter('form[action="/admin/projets/importer/confirmer"]')->form());
        $this->assertResponseRedirects('/admin/projets/taches?view=list');
        self::assertEmailCount(0, message: 'Aucun email pour des tâches importées.');
        $this->client->followRedirect();
        self::assertStringContainsString('5 tâches importées, 4 projets créés', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $tasks    = $this->em->getRepository(ProjectTask::class);
        $projects = $this->em->getRepository(Project::class);
        self::assertSame(6, $tasks->count([]));
        self::assertSame(5, $projects->count([]), 'Administratif est retrouvé, pas recréé.');
        self::assertNotNull($projects->findOneBy(['name' => 'Institutions']), 'Ligne « projet seul » : projet créé.');

        $pv = $tasks->findOneBy(['title' => 'Faire un PV pour l\'AG du 18/09']);
        self::assertSame('Administratif', $pv?->getProject()?->getName());
        self::assertSame('2026-09-21', $pv?->getDueDate()?->format('Y-m-d'), 'Date prévue = échéance quand il n\'y en a pas.');
        self::assertSame([$this->gaelle->getId()], $this->ids($pv?->getAssignees()->toArray() ?? []), '« Gaëlle » retrouvée par son email hello@gaellecode.');
        $subtasks = $pv?->getSubtasks()->toArray() ?? [];
        self::assertCount(2, $subtasks);
        self::assertSame(['Envoyer à la banque', 'Retirer ses accès'], array_map(static fn ($s): string => $s->getTitle(), $subtasks));
        self::assertTrue($subtasks[1]->isDone(), '« [x] » = sous-tâche déjà faite.');

        $note = $tasks->findOneBy(['title' => 'Finaliser note de projet et note d’intention']);
        self::assertSame(ProjectTaskStatus::InProgress, $note?->getStatus());
        self::assertSame(ProjectTaskPriority::High, $note?->getPriority());
        self::assertSame($this->wendie->getId(), $note?->getCreatedBy()?->getId(), 'Colonne « Par » : autrice d\'origine.');
        self::assertSame([$this->wendie->getId()], $this->ids($note?->getAssignees()->toArray() ?? []));

        $done = $tasks->findOneBy(['title' => 'Envoyer explication à Odile']);
        self::assertSame(ProjectTaskStatus::Done, $done?->getStatus());
        self::assertNotNull($done?->getCompletedAt());
        self::assertSame("Note de réponse\n\n1. Principe général", $done?->getDescription(), 'Les retours à la ligne des notes sont gardés.');

        $grant = $tasks->findOneBy(['title' => 'Finaliser candidature "Impact Grant 2026"']);
        self::assertSame(ProjectTaskPriority::Urgent, $grant?->getPriority());
        self::assertSame([$this->wendie->getId()], $this->ids($grant?->getAssignees()->toArray() ?? []), 'La personne inconnue est ignorée.');
        $links = $this->em->getRepository(ProjectAttachment::class)->findBy(['task' => $grant]);
        self::assertCount(1, $links);
        self::assertSame('Google Docs', $links[0]->getName());

        $later = $tasks->findOneBy(['title' => 'Envoyer notes de projets aux institutions']);
        self::assertSame(ProjectTaskStatus::Todo, $later?->getStatus());
        self::assertSame('2026-10-05', $later?->getStartDate()?->format('Y-m-d'));
        self::assertSame('2026-10-30', $later?->getDueDate()?->format('Y-m-d'));
        self::assertCount(0, $this->em->getRepository(ProjectAttachment::class)->findBy(['task' => $later]), 'Lien javascript: refusé.');

        self::assertSame(1, $this->em->getRepository(ProjectActivity::class)->count(['action' => ProjectActivity::TASKS_IMPORTED]));
        self::assertSame(0, $this->em->getRepository(ProjectActivity::class)->count(['action' => ProjectActivity::TASK_CREATED]), 'Une seule ligne d\'activité, pas une par tâche.');

        // ── Réimporter le même fichier ne crée aucun doublon ────────────────
        $crawler = $this->uploadCsv($csv);
        self::assertStringContainsString('Rien à importer', $crawler->filter('#pm-root')->text());
        self::assertCount(0, $crawler->filter('form[action="/admin/projets/importer/confirmer"]'));
    }

    public function testImportRefusesExcelFilesAndFilesWithoutTaskColumn(): void
    {
        $this->loginAs($this->wendie);

        $crawler = $this->uploadCsv("PK\x03\x04excel-binaire", 'Tableau_de_bord.xlsx');
        self::assertStringContainsString('C\'est un fichier Excel (.xlsx)', $crawler->filter('#pm-root')->text());

        $crawler = $this->uploadCsv($this->csv([['Projet', 'Statut'], ['Zagaza', 'À faire']]));
        self::assertStringContainsString('Colonne « Tâche » introuvable', $crawler->filter('#pm-root')->text());
        self::assertSame(0, $this->em->getRepository(Project::class)->count([]));
    }

    public function testImportConfirmationExpiresWithAnotherFileHash(): void
    {
        $this->loginAs($this->wendie);
        $crawler = $this->uploadCsv($this->csv([['Tâche', 'Projet'], ['Appeler la région', 'Plateforme']]));

        $form = $crawler->filter('form[action="/admin/projets/importer/confirmer"]')->form();
        $form['hash']->setValue(str_repeat('0', 64));
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/projets/importer');
        $this->client->followRedirect();
        self::assertStringContainsString('Import expiré', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(ProjectTask::class)->count([]));
    }

    public function testTemplateCanBeDownloadedAndImportedAsIs(): void
    {
        $this->loginAs($this->wendie);
        $this->client->request('GET', '/admin/projets/importer/modele.csv');
        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $template = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBFTâche;Projet;Statut", $template);

        // Le modèle (séparateur « ; », BOM Excel) est lui-même importable.
        $crawler = $this->uploadCsv($template, 'modele-import-taches.csv');
        self::assertStringContainsString('2 tâches à importer', $crawler->filter('#pm-root')->text());
        self::assertCount(2, $crawler->filter('.pm-table tbody tr'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Outils
    // ═════════════════════════════════════════════════════════════════════

    /** Envoie un fichier via le formulaire de la page d'import et renvoie la page d'aperçu. */
    private function uploadCsv(string $content, string $name = 'taches.csv'): Crawler
    {
        $path = tempnam(sys_get_temp_dir(), 'pm-import');
        self::assertIsString($path);
        $this->tmpFiles[] = $path;
        // Le nom côté client doit garder son extension : on renomme le fichier temporaire.
        $named = $path . '-' . $name;
        file_put_contents($named, $content);
        $this->tmpFiles[] = $named;

        $crawler = $this->client->request('GET', '/admin/projets/importer');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/admin/projets/importer"]')->form();
        $form['file']->upload($named);

        return $this->client->submit($form);
    }

    /** @param list<list<string>> $rows CSV « à la Google Sheets » (virgules, guillemets doublés). */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @param list<User> $users
     *
     * @return list<int|null>
     */
    private function ids(array $users): array
    {
        return array_map(static fn (User $u): ?int => $u->getId(), $users);
    }

    /** @return array{done: bool} */
    private function checklistStep(Crawler $crawler, string $label): array
    {
        $step = $crawler->filter('.pm-step')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), $label));
        self::assertCount(1, $step, sprintf('Étape « %s » présente dans la checklist.', $label));

        return ['done' => str_contains((string) $step->attr('class'), 'is-done') || str_contains((string) $step->attr('class'), '--done')];
    }
}
