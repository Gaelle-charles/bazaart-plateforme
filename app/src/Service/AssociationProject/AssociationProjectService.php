<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use App\DTO\AssociationProject\AssociationProjectData;
use App\Entity\AssociationProject;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
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
        if ($data->position !== null) {
            $project->setPosition($data->position);
        }

        $this->em->flush();

        return $project;
    }

    /**
     * Supprime une fiche, ses blocs et TOUS les fichiers images associés.
     * Les lignes des blocs partent avec la fiche (ON DELETE CASCADE en BDD) ;
     * les fichiers, eux, doivent être effacés à la main AVANT.
     */
    public function delete(AssociationProject $project): void
    {
        $this->imageStorage->delete($project->getImagePath());
        foreach ($project->getBlocks() as $block) {
            $this->imageStorage->delete($block->getImagePath());
        }

        $this->em->remove($project);
        $this->em->flush();
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
            ->setPartnerContribution($data->partnerContribution)
            ->setLeadName($data->leadName)
            ->setImageAlt($data->imageAlt)
            ->setIsPublished($data->isPublished);
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
