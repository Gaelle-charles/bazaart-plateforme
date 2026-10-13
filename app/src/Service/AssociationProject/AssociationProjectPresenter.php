<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use App\Entity\AssociationProject;
use App\Entity\AssociationProjectBlock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * AssociationProjectPresenter : transforme une fiche en données affichables (ADR-0039).
 *
 * UNE SEULE SOURCE DE VÉRITÉ : l'API JSON et les pages (carrousel, fiche) utilisent
 * exactement les mêmes données, produites ici. Ce qui n'est pas dans ce tableau
 * n'est montré nulle part côté partenaires.
 *
 * VOLONTAIREMENT ABSENTS (exigences de l'association) :
 *   - les dates de création et de mise à jour ;
 *   - l'identifiant interne (id) et le statut « publié » (tout ce qui sort ici est publié) ;
 *   - les chemins de stockage des images (on donne une URL protégée par le jeton).
 *
 * Les URL des images contiennent un numéro de version (?v=…) tiré du nom du fichier :
 * quand l'équipe remplace une image, l'URL change, donc aucun navigateur ne garde
 * l'ancienne image en cache.
 */
final class AssociationProjectPresenter
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{
     *     slug: string,
     *     title: string,
     *     type: array{value: string, label: string},
     *     state: array{value: string, label: string},
     *     period: string|null,
     *     places: string|null,
     *     artists: string|null,
     *     description: string|null,
     *     confirmedPartners: string|null,
     *     prospectivePartners: string|null,
     *     solicitedFunders: string|null,
     *     partnerContribution: string|null,
     *     leadName: string|null,
     *     position: int,
     *     url: string,
     *     image: array{url: string, alt: string, width: int|null, height: int|null}|null,
     *     blocks: list<array{heading: string|null, body: string|null, image: array{url: string, alt: string, caption: string|null, width: int|null, height: int|null}|null}>
     * }
     */
    public function present(AssociationProject $project, string $token): array
    {
        $slug = $project->getSlug();

        $image = null;
        if ($project->getImagePath() !== null) {
            $image = [
                'url'    => $this->urlGenerator->generate('app_asso_projects_image', [
                    'token' => $token,
                    'slug'  => $slug,
                    'v'     => $this->version($project->getImagePath()),
                ]),
                // Texte alternatif : celui saisi par l'équipe, sinon le titre du projet.
                'alt'    => $project->getImageAlt() ?? $project->getTitle(),
                'width'  => $project->getImageWidth(),
                'height' => $project->getImageHeight(),
            ];
        }

        $blocks = [];
        foreach ($project->getBlocks() as $block) {
            $blocks[] = $this->presentBlock($block, $token, $slug);
        }

        return [
            'slug'                => $slug,
            'title'               => $project->getTitle(),
            'type'                => ['value' => $project->getType()->value, 'label' => $project->getType()->label()],
            'state'               => ['value' => $project->getState()->value, 'label' => $project->getState()->label()],
            'period'              => $project->getPeriod(),
            'places'              => $project->getPlaces(),
            'artists'             => $project->getArtists(),
            'description'         => $project->getDescription(),
            'confirmedPartners'   => $project->getConfirmedPartners(),
            'prospectivePartners' => $project->getProspectivePartners(),
            'solicitedFunders'    => $project->getSolicitedFunders(),
            'partnerContribution' => $project->getPartnerContribution(),
            'leadName'            => $project->getLeadName(),
            'position'            => $project->getPosition(),
            'url'                 => $this->urlGenerator->generate('app_asso_projects_show', ['token' => $token, 'slug' => $slug]),
            'image'               => $image,
            'blocks'              => $blocks,
        ];
    }

    /**
     * @return array{heading: string|null, body: string|null, image: array{url: string, alt: string, caption: string|null, width: int|null, height: int|null}|null}
     */
    private function presentBlock(AssociationProjectBlock $block, string $token, string $slug): array
    {
        $image = null;
        if ($block->getImagePath() !== null) {
            $image = [
                'url'     => $this->urlGenerator->generate('app_asso_projects_block_image', [
                    'token'   => $token,
                    'slug'    => $slug,
                    'blockId' => $block->getId(),
                    'v'       => $this->version($block->getImagePath()),
                ]),
                // Pas de texte alternatif saisi : on prend la légende, sinon rien de mieux
                // que le titre du bloc ou « Image » (une image sans alt serait pire).
                'alt'     => $block->getImageAlt() ?? $block->getImageCaption() ?? $block->getHeading() ?? 'Image',
                'caption' => $block->getImageCaption(),
                'width'   => $block->getImageWidth(),
                'height'  => $block->getImageHeight(),
            ];
        }

        return [
            'heading' => $block->getHeading(),
            'body'    => $block->getBody(),
            'image'   => $image,
        ];
    }

    /** Numéro de version court, qui change quand le fichier image change. */
    private function version(string $imagePath): string
    {
        return substr(hash('sha256', $imagePath), 0, 10);
    }
}
