<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use App\DTO\AssociationProject\AssociationProjectBlockData;
use App\Entity\AssociationProject;
use App\Entity\AssociationProjectBlock;
use App\Repository\AssociationProjectBlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectBlockService : blocs de présentation d'une fiche (ADR-0039).
 *
 * Ajouter, modifier, monter / descendre, supprimer un bloc. Comme pour les fiches,
 * create() et update() renvoient soit le bloc enregistré, soit un message d'erreur
 * (image refusée) ; dans ce cas rien n'est enregistré.
 */
final class AssociationProjectBlockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssociationProjectBlockRepository $repository,
        private readonly AssociationProjectImageStorage $imageStorage,
    ) {
    }

    /** Ajoute un bloc à la fin de la présentation. */
    public function create(AssociationProject $project, AssociationProjectBlockData $data, ?UploadedFile $image): AssociationProjectBlock|string
    {
        $block = new AssociationProjectBlock($project);

        $imageError = $this->applyImage($block, $data, $image);
        if ($imageError !== null) {
            return $imageError;
        }

        $this->applyFields($block, $data);
        $block->setPosition($this->repository->nextPosition($project));
        $project->getBlocks()->add($block);

        $this->em->persist($block);
        $this->em->flush();

        return $block;
    }

    public function update(AssociationProjectBlock $block, AssociationProjectBlockData $data, ?UploadedFile $image): AssociationProjectBlock|string
    {
        $imageError = $this->applyImage($block, $data, $image);
        if ($imageError !== null) {
            return $imageError;
        }

        $this->applyFields($block, $data);
        $this->em->flush();

        return $block;
    }

    /** Supprime un bloc et son image. */
    public function delete(AssociationProjectBlock $block): void
    {
        $this->imageStorage->delete($block->getImagePath());
        $block->getProject()->getBlocks()->removeElement($block);
        $this->em->remove($block);
        $this->em->flush();
    }

    /**
     * Monte (-1) ou descend (+1) un bloc d'un cran.
     *
     * Méthode : on renumérote d'abord tous les blocs du projet (10, 20, 30…) pour
     * repartir d'un ordre propre, puis on échange la position du bloc avec sa voisine.
     *
     * @return bool false si le bloc est déjà tout en haut (ou tout en bas)
     */
    public function move(AssociationProjectBlock $block, int $direction): bool
    {
        /** @var list<AssociationProjectBlock> $blocks */
        $blocks = $this->repository->findBy(['project' => $block->getProject()], ['position' => 'ASC', 'id' => 'ASC']);

        $index = array_search($block, $blocks, true);
        $target = is_int($index) ? $index + ($direction < 0 ? -1 : 1) : -1;
        if (!isset($blocks[$target])) {
            return false;
        }

        // Échange dans la liste, puis renumérotation de 10 en 10.
        [$blocks[$index], $blocks[$target]] = [$blocks[$target], $blocks[$index]];
        foreach ($blocks as $i => $item) {
            $item->setPosition(($i + 1) * 10);
        }

        $this->em->flush();

        return true;
    }

    private function applyFields(AssociationProjectBlock $block, AssociationProjectBlockData $data): void
    {
        $block
            ->setHeading($data->heading)
            ->setBody($data->body)
            ->setImageAlt($data->imageAlt)
            ->setImageCaption($data->imageCaption);
    }

    /** @return string|null message d'erreur, ou null si tout va bien */
    private function applyImage(AssociationProjectBlock $block, AssociationProjectBlockData $data, ?UploadedFile $image): ?string
    {
        if ($image !== null) {
            $stored = $this->imageStorage->store($image);
            if (is_string($stored)) {
                return 'Image : ' . $stored;
            }
            // L'ancienne image n'est effacée qu'une fois la nouvelle bien enregistrée.
            $this->imageStorage->delete($block->getImagePath());
            $block->setImagePath($stored->path)->setImageSize($stored->width, $stored->height);

            return null;
        }

        if ($data->removeImage && $block->getImagePath() !== null) {
            $this->imageStorage->delete($block->getImagePath());
            $block->setImagePath(null)->setImageSize(null, null);
        }

        return null;
    }
}
