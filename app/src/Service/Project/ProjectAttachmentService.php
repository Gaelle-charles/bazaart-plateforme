<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectActivity;
use App\Entity\ProjectAttachment;
use App\Entity\ProjectTask;
use App\Entity\User;
use App\Enum\ProjectAttachmentSource;
use App\Exception\GoogleDriveException;
use App\Repository\ProjectAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ProjectAttachmentService — fichiers Drive et liens joints aux projets / tâches (ADR-0037).
 *
 * Trois façons de joindre :
 *   1. choisir un fichier ou dossier existant dans le Drive (sélecteur) → attachDriveFile()
 *   2. téléverser un fichier de l'ordinateur VERS le Drive puis le joindre → uploadAndAttach()
 *   3. coller un simple lien web → attachLink()
 *
 * Dans tous les cas, on ne stocke qu'une RÉFÉRENCE : le fichier vit dans le Drive.
 * Retirer une pièce jointe ne supprime rien dans le Drive.
 */
class ProjectAttachmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectAttachmentRepository $attachmentRepository,
        private readonly GoogleDriveService $driveService,
        private readonly ProjectActivityLogger $activityLogger,
    ) {}

    /**
     * Joint un fichier/dossier du Drive. Les métadonnées sont RELUES chez Google
     * (on ne fait pas confiance au nom ou au lien envoyés par le navigateur).
     *
     * @throws GoogleDriveException
     */
    public function attachDriveFile(Project|ProjectTask $parent, string $driveFileId, User $actor): ProjectAttachment
    {
        $file = $this->driveService->getFile($driveFileId);

        // Déjà joint ? On renvoie la pièce jointe existante plutôt qu'un doublon.
        $criteria = ['driveFileId' => (string) $file['id']];
        $criteria += $parent instanceof Project ? ['project' => $parent] : ['task' => $parent];
        $existing = $this->attachmentRepository->findOneBy($criteria);
        if ($existing !== null) {
            return $existing;
        }

        return $this->persist($parent, $actor, ProjectAttachmentSource::Drive, (string) $file['name'], (string) $file['webViewLink'], is_string($file['mimeType']) ? $file['mimeType'] : null, (string) $file['id']);
    }

    /**
     * Téléverse dans le Drive (dossier du projet si défini, sinon « Mon Drive ») puis joint.
     *
     * @throws GoogleDriveException
     */
    public function uploadAndAttach(Project|ProjectTask $parent, UploadedFile $file, User $actor): ProjectAttachment
    {
        $project  = $parent instanceof Project ? $parent : $parent->getProject();
        $uploaded = $this->driveService->upload($file, $project?->getDriveFolderId());

        return $this->persist($parent, $actor, ProjectAttachmentSource::Drive, (string) $uploaded['name'], (string) $uploaded['webViewLink'], is_string($uploaded['mimeType']) ? $uploaded['mimeType'] : null, (string) $uploaded['id']);
    }

    /**
     * Joint un lien web (Canva, Notion, site d'un partenaire…).
     *
     * @return string|null message d'erreur, ou null si le lien est joint
     */
    public function attachLink(Project|ProjectTask $parent, string $url, string $name, User $actor): ?string
    {
        $url  = trim($url);
        $name = trim($name);

        // Seuls http(s) sont acceptés : un lien « javascript:… » dans un href
        // serait une faille XSS.
        if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1 || mb_strlen($url) > 1024) {
            return 'Le lien doit être une adresse web valide commençant par http:// ou https://.';
        }
        if ($name === '') {
            $name = (string) (parse_url($url, PHP_URL_HOST) ?? $url);
        }

        $this->persist($parent, $actor, ProjectAttachmentSource::Link, mb_substr($name, 0, 255), $url, null, null);

        return null;
    }

    public function remove(ProjectAttachment $attachment): void
    {
        $this->em->remove($attachment);
        $this->em->flush();
    }

    private function persist(Project|ProjectTask $parent, User $actor, ProjectAttachmentSource $source, string $name, string $url, ?string $mimeType, ?string $driveFileId): ProjectAttachment
    {
        $attachment = (new ProjectAttachment())
            ->setSource($source)
            ->setName(mb_substr($name, 0, 255))
            ->setUrl($url)
            ->setMimeType($mimeType !== null ? mb_substr($mimeType, 0, 150) : null)
            ->setDriveFileId($driveFileId)
            ->setAddedBy($actor);

        if ($parent instanceof Project) {
            $attachment->setProject($parent);
            $this->activityLogger->log(ProjectActivity::ATTACHMENT_ADDED, sprintf('a joint %s au projet', ProjectActivityLogger::quote($name)), $actor, $parent);
        } else {
            $attachment->setTask($parent);
            $this->activityLogger->log(ProjectActivity::ATTACHMENT_ADDED, sprintf('a joint %s à %s', ProjectActivityLogger::quote($name), ProjectActivityLogger::quote($parent->getTitle())), $actor, task: $parent);
        }

        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }
}
