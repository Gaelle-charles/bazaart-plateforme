<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use App\DTO\AssociationProject\AssociationProjectData;
use App\Entity\AssociationProject;
use App\Entity\AssociationProjectDocument;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Repository\AssociationProjectDocumentRepository;
use App\Repository\AssociationProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectService : logique métier des fiches « Projets en cours » (ADR-0039).
 *
 * Le contrôleur reste fin (convention CLAUDE.md) : il lit la requête, vérifie le
 * jeton CSRF, puis délègue ici création, modification, suppression et ordre.
 *
 * Contrat de retour de create() / update() :
 *   - AssociationProject → enregistré ;
 *   - string             → message d'erreur (image refusée) : RIEN n'est enregistré.
 *
 * « LE PROJET EN DÉTAIL » (texte enrichi) : nettoyé ICI, juste avant l'enregistrement
 * (AssociationProjectRichText). Une image n'y est gardée que si son fichier est dans
 * le dossier de la fiche ; les images que le texte ne cite plus sont effacées du disque.
 */
final class AssociationProjectService
{
    /**
     * Slugs interdits : ils entreraient en conflit avec une adresse de la page
     * partenaires (/projets/{jeton}/api est l'API JSON).
     */
    private const RESERVED_SLUGS = ['api'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssociationProjectRepository $repository,
        private readonly AssociationProjectImageStorage $imageStorage,
        private readonly AssociationProjectRichText $richText,
        private readonly AssociationProjectTextImages $textImages,
        private readonly AssociationProjectDocumentStorage $documentStorage,
        private readonly AssociationProjectDocumentRepository $documentRepository,
    ) {
    }

    /** Crée une fiche à partir du formulaire (le slug est calculé une fois pour toutes). */
    public function create(AssociationProjectData $data, ?UploadedFile $image): AssociationProject|string
    {
        $project = new AssociationProject();

        // L'image d'abord : si elle est refusée, on s'arrête avant d'enregistrer quoi que ce soit.
        $imageError = $this->applyImage($project, $data, $image);
        if ($imageError !== null) {
            return $imageError;
        }

        $this->applyFields($project, $data);
        // Pas encore de numéro, donc pas encore de dossier d'images : le texte d'une
        // nouvelle fiche ne peut pas contenir d'image (l'éditeur le dit).
        $project->setDetails($this->richText->filterImages(
            $this->richText->sanitize($data->details),
            static fn (string $name): bool => false,
        ));
        $project->setSlug($this->uniqueSlug($data->title));
        $project->setPosition($data->position ?? $this->repository->nextPosition());

        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * Met à jour une fiche. Le slug ne change PAS, même si le titre change :
     * les liens déjà envoyés aux partenaires restent valables.
     */
    public function update(AssociationProject $project, AssociationProjectData $data, ?UploadedFile $image): AssociationProject|string
    {
        $imageError = $this->applyImage($project, $data, $image);
        if ($imageError !== null) {
            return $imageError;
        }

        $this->applyFields($project, $data);
        // Champ « ordre » vidé = « à la fin de la liste », comme à la création.
        $project->setPosition($data->position ?? $this->repository->nextPosition());

        $imagesBefore = $this->richText->imageNames($project->getDetails());
        $project->setDetails($this->richText->filterImages(
            $this->richText->sanitize($data->details),
            fn (string $name): bool => $this->textImages->absolutePath($project, $name) !== null,
        ));

        $this->em->flush();

        // Fichiers effacés seulement APRÈS l'enregistrement réussi.
        $this->textImages->deleteUnused($project, $imagesBefore, $this->richText->imageNames($project->getDetails()));

        return $project;
    }

    /**
     * Supprime une fiche, ses blocs, ses documents et TOUS les fichiers associés
     * (visuel, images des blocs et du texte, PDF).
     * Les lignes des blocs et des documents partent avec la fiche (ON DELETE CASCADE
     * en BDD) ; les fichiers, eux, doivent être effacés à la main AVANT.
     */
    public function delete(AssociationProject $project): void
    {
        // On note d'abord tout ce qu'il faudra effacer : les fichiers ne partent
        // qu'APRÈS la suppression en base. Si celle-ci échoue, la fiche reste
        // complète (sinon : fiche encore en ligne, avec des images et des PDF perdus).
        $projectId = $project->getId();
        $images = [$project->getImagePath()];
        foreach ($project->getBlocks() as $block) {
            $images[] = $block->getImagePath();
        }
        $documents = [];
        foreach ($project->getDocuments() as $document) {
            $documents[] = $document->getPath();
        }

        $this->em->remove($project);
        $this->em->flush();

        foreach ($images as $path) {
            $this->imageStorage->delete($path);
        }
        foreach ($documents as $path) {
            $this->documentStorage->delete($path);
        }
        if ($projectId !== null) {
            $this->textImages->deleteAllForProjectId($projectId);
        }
    }

    /**
     * Enregistre l'ordre saisi dans la liste du back-office.
     *
     * @param array<mixed> $positions [id de la fiche => ordre saisi], tel que reçu du formulaire
     *
     * @return int nombre de fiches dont l'ordre a changé
     */
    public function reorder(array $positions): int
    {
        $changed = 0;
        foreach ($this->repository->findAllOrdered() as $project) {
            $raw = $positions[(string) $project->getId()] ?? $positions[$project->getId()] ?? null;
            // On n'accepte qu'un entier (positif ou négatif, 6 chiffres max) : le reste est ignoré.
            if (!is_string($raw) || preg_match('/^-?\d{1,6}$/', trim($raw)) !== 1) {
                continue;
            }
            $position = (int) trim($raw);
            if ($position !== $project->getPosition()) {
                $project->setPosition($position);
                ++$changed;
            }
        }

        if ($changed > 0) {
            $this->em->flush();
        }

        return $changed;
    }

    /**
     * Ajoute un document PDF à la fiche, à la fin de la liste.
     * Intitulé vide = nom du fichier envoyé (sans « .pdf », tirets remplacés par des espaces).
     *
     * @return AssociationProjectDocument|string le document, ou un message d'erreur en français
     */
    public function addDocument(AssociationProject $project, UploadedFile $file, ?string $label): AssociationProjectDocument|string
    {
        $label = $this->documentLabel($label, $file->getClientOriginalName());
        if (mb_strlen($label) > AssociationProjectDocument::LABEL_MAX_LENGTH) {
            return sprintf("L'intitulé ne peut pas dépasser %d caractères.", AssociationProjectDocument::LABEL_MAX_LENGTH);
        }

        $stored = $this->documentStorage->store($file);
        if (is_string($stored)) {
            return $stored;
        }

        $document = (new AssociationProjectDocument($project))
            ->setLabel($label)
            ->setPath($stored->path)
            ->setSizeBytes($stored->sizeBytes)
            ->setPosition($this->documentRepository->nextPosition($project));
        $project->getDocuments()->add($document);

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /** @return string|null message d'erreur, ou null si l'intitulé est enregistré */
    public function renameDocument(AssociationProjectDocument $document, ?string $label): ?string
    {
        $label = trim(preg_replace('/\s+/u', ' ', (string) $label) ?? '');
        if ($label === '') {
            return "L'intitulé du document est obligatoire.";
        }
        if (mb_strlen($label) > AssociationProjectDocument::LABEL_MAX_LENGTH) {
            return sprintf("L'intitulé ne peut pas dépasser %d caractères.", AssociationProjectDocument::LABEL_MAX_LENGTH);
        }

        $document->setLabel($label);
        $this->em->flush();

        return null;
    }

    /** Supprime un document : la ligne en base, puis le fichier. */
    public function deleteDocument(AssociationProjectDocument $document): void
    {
        $path = $document->getPath();
        $document->getProject()->getDocuments()->removeElement($document);
        $this->em->remove($document);
        $this->em->flush();

        $this->documentStorage->delete($path);
    }

    /**
     * Monte (-1) ou descend (+1) un document d'un cran dans la liste.
     * Les positions sont renumérotées 10, 20, 30… pour rester simples à lire.
     */
    public function moveDocument(AssociationProjectDocument $document, int $direction): void
    {
        $documents = array_values($document->getProject()->getDocuments()->toArray());
        $index = array_search($document, $documents, true);
        $target = is_int($index) ? $index + ($direction < 0 ? -1 : 1) : -1;
        if (!is_int($index) || $target < 0 || $target >= count($documents)) {
            return;
        }

        [$documents[$index], $documents[$target]] = [$documents[$target], $documents[$index]];
        foreach ($documents as $i => $item) {
            $item->setPosition(($i + 1) * 10);
        }
        $this->em->flush();
    }

    /** Image insérée dans le texte « Le projet en détail » (envoyée par l'éditeur). */
    public function storeTextImage(AssociationProject $project, UploadedFile $file): StoredImage|string
    {
        return $this->textImages->store($project, $file);
    }

    /**
     * Slug unique : « formations », puis « formations-2 », « formations-3 »…
     * Un mot réservé (« api ») reçoit aussi un suffixe.
     */
    public function uniqueSlug(string $title): string
    {
        $base = SlugGenerator::slugify($title);
        $slug = $base;
        $suffix = 2;

        while (in_array($slug, self::RESERVED_SLUGS, true) || $this->repository->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            ++$suffix;
        }

        return $slug;
    }

    private function applyFields(AssociationProject $project, AssociationProjectData $data): void
    {
        $project
            ->setTitle($data->title)
            ->setType($data->type ?? AssociationProjectType::Residency)
            ->setState($data->state ?? AssociationProjectState::Idea)
            ->setPeriod($data->period)
            ->setPlaces($data->places)
            ->setArtists($data->artists)
            ->setDescription($data->description)
            ->setConfirmedPartners($data->confirmedPartners)
            ->setProspectivePartners($data->prospectivePartners)
            ->setSolicitedFunders($data->solicitedFunders)
            ->setBudget($data->budget)
            ->setPartnerContribution($data->partnerContribution)
            ->setLeadName($data->leadName)
            ->setImageAlt($data->imageAlt)
            ->setIsPublished($data->isPublished);
    }

    /** Intitulé saisi, ou à défaut le nom du fichier envoyé, rendu lisible. */
    private function documentLabel(?string $label, string $originalName): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', (string) $label) ?? '');
        if ($label !== '') {
            return $label;
        }

        $name = preg_replace('/\.pdf$/i', '', basename($originalName)) ?? '';
        $name = trim(preg_replace('/[\s_-]+/u', ' ', $name) ?? '');

        return $name !== '' ? mb_substr($name, 0, AssociationProjectDocument::LABEL_MAX_LENGTH) : 'Document';
    }

    /**
     * Visuel : remplacement par une nouvelle image, ou retrait.
     *
     * @return string|null message d'erreur, ou null si tout va bien
     */
    private function applyImage(AssociationProject $project, AssociationProjectData $data, ?UploadedFile $image): ?string
    {
        if ($image !== null) {
            $stored = $this->imageStorage->store($image);
            if (is_string($stored)) {
                return 'Visuel : ' . $stored;
            }
            // L'ancienne image n'est effacée qu'une fois la nouvelle bien enregistrée.
            $this->imageStorage->delete($project->getImagePath());
            $project->setImagePath($stored->path)->setImageSize($stored->width, $stored->height);

            return null;
        }

        if ($data->removeImage && $project->getImagePath() !== null) {
            $this->imageStorage->delete($project->getImagePath());
            $project->setImagePath(null)->setImageSize(null, null);
        }

        return null;
    }
}
