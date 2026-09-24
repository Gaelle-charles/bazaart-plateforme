<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Security\Voter\ProjectVoter;
use App\Service\Project\ProjectTaskImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminProjectImportController — importer des tâches depuis un tableur (ADR-0037).
 *
 * Parcours en deux temps, pour ne jamais importer « à l'aveugle » :
 *   1. GET  /admin/projets/importer            formulaire d'envoi du CSV
 *   2. POST /admin/projets/importer            aperçu : ce qui sera créé, avertissements
 *                                              (le fichier est gardé en session)
 *   3. POST /admin/projets/importer/confirmer  enregistrement, puis liste des tâches
 *
 * Le fichier est gardé en session entre l'aperçu et la confirmation : pas besoin de
 * le renvoyer, et on importe exactement ce qui a été vérifié (empreinte SHA-256).
 */
#[Route('/admin/projets', name: 'app_admin_pm_')]
#[IsGranted(ProjectVoter::ACCESS)]
class AdminProjectImportController extends AbstractController
{
    use ProjectControllerTrait;

    private const string SESSION_KEY = 'pm_import_csv';

    public function __construct(
        private readonly ProjectTaskImporter $importer,
    ) {}

    /** GET /admin/projets/importer — explications et envoi du fichier. */
    #[Route('/importer', name: 'import', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->render('admin/projects/import.html.twig', [
            'report' => null,
            'hash'   => null,
            'fields' => ProjectTaskImporter::FIELD_LABELS,
        ]);
    }

    /** POST /admin/projets/importer — lit le fichier et affiche l'aperçu (rien n'est enregistré). */
    #[Route('/importer', name: 'import_check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_import', (string) $request->request->get('_token'))) {
            $this->flashInvalidToken();

            return $this->redirectToRoute('app_admin_pm_import');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Choisis un fichier CSV (1 Mo maximum).');

            return $this->redirectToRoute('app_admin_pm_import');
        }
        if ($file->getSize() > ProjectTaskImporter::MAX_BYTES) {
            $this->addFlash('error', 'Fichier trop lourd (1 Mo maximum).');

            return $this->redirectToRoute('app_admin_pm_import');
        }

        $content = (string) file_get_contents($file->getPathname());
        $report  = $this->importer->analyze($content, $this->currentUser());

        $hash = null;
        if ($report->isImportable()) {
            $hash = hash('sha256', $content);
            $request->getSession()->set(self::SESSION_KEY, ['hash' => $hash, 'content' => $content, 'name' => $file->getClientOriginalName()]);
        } else {
            $request->getSession()->remove(self::SESSION_KEY);
        }

        return $this->render('admin/projects/import.html.twig', [
            'report'   => $report,
            'hash'     => $hash,
            'fileName' => $file->getClientOriginalName(),
            'fields'   => ProjectTaskImporter::FIELD_LABELS,
        ]);
    }

    /** POST /admin/projets/importer/confirmer — enregistre le fichier vérifié. */
    #[Route('/importer/confirmer', name: 'import_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pm_import_run', (string) $request->request->get('_token'))) {
            $this->flashInvalidToken();

            return $this->redirectToRoute('app_admin_pm_import');
        }

        $session = $request->getSession();
        $stored  = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);
        if (!is_array($stored) || !is_string($stored['content'] ?? null)
            || !hash_equals((string) ($stored['hash'] ?? ''), (string) $request->request->get('hash', ''))) {
            $this->addFlash('error', 'Import expiré : renvoie le fichier pour le vérifier à nouveau.');

            return $this->redirectToRoute('app_admin_pm_import');
        }

        $report = $this->importer->import($stored['content'], $this->currentUser());
        if (!$report->isImportable()) {
            $this->addFlash('error', $report->error ?? 'Rien à importer : toutes les tâches du fichier sont déjà présentes.');

            return $this->redirectToRoute('app_admin_pm_import');
        }

        $message = sprintf('%d tâche%s importée%s', count($report->rows), count($report->rows) > 1 ? 's' : '', count($report->rows) > 1 ? 's' : '');
        if ($report->newProjects !== []) {
            $message .= sprintf(', %d projet%s créé%s (%s)', count($report->newProjects), count($report->newProjects) > 1 ? 's' : '', count($report->newProjects) > 1 ? 's' : '', implode(', ', $report->newProjects));
        }
        if ($report->duplicates > 0) {
            $message .= sprintf(', %d déjà présente%s ignorée%s', $report->duplicates, $report->duplicates > 1 ? 's' : '', $report->duplicates > 1 ? 's' : '');
        }
        $this->addFlash('success', $message . '.');

        return $this->redirectToRoute('app_admin_pm_tasks', ['view' => 'list']);
    }

    /** GET /admin/projets/importer/modele.csv — modèle à remplir (s'ouvre dans Excel ou Google Sheets). */
    #[Route('/importer/modele.csv', name: 'import_template', methods: ['GET'])]
    public function template(): Response
    {
        $rows = [
            ['Tâche', 'Projet', 'Statut', 'Priorité', 'Échéance', 'Date prévue', 'Assignée à', 'Notes', 'Étiquettes', 'Lien', 'Sous-tâches'],
            ['Réserver la salle', 'Festival 2027', 'À faire', 'Haute', '15/01/2027', '', 'Wendie', 'Demander le devis', 'Budget', '', "Appeler la mairie\n[x] Envoyer le mail"],
            ['Envoyer le dossier de subvention', 'Candidatures', 'En cours', 'Urgente', '30/11/2026', '20/11/2026', 'Gaëlle, Wendie', '', '', 'https://docs.google.com/document/d/ID-DU-DOCUMENT/edit', ''],
        ];

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        // BOM UTF-8 : Excel affiche alors correctement les accents.
        return new Response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-import-taches.csv"',
        ]);
    }
}
