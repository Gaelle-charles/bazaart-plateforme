<?php

declare(strict_types=1);

namespace App\DTO\AssociationProject;

use App\Entity\AssociationProjectBlock;
use Symfony\Component\HttpFoundation\Request;

/**
 * AssociationProjectBlockData : données d'un bloc de présentation (ADR-0039).
 *
 * Même principe que AssociationProjectData : lecture et nettoyage du POST, puis
 * validate() renvoie les erreurs en français. Les retours à la ligne « \r\n »
 * envoyés par le navigateur sont convertis en « \n » (un seul caractère).
 */
final class AssociationProjectBlockData
{
    public const HEADING_MAX = 150;
    public const BODY_MAX = 10000;
    public const ALT_MAX = 200;
    public const CAPTION_MAX = 255;

    public ?string $heading = null;
    public ?string $body = null;
    public ?string $imageAlt = null;
    public ?string $imageCaption = null;

    /** Case « Retirer l'image » (modification seulement). */
    public bool $removeImage = false;

    public static function fromEntity(AssociationProjectBlock $block): self
    {
        $data = new self();
        $data->heading      = $block->getHeading();
        $data->body         = $block->getBody();
        $data->imageAlt     = $block->getImageAlt();
        $data->imageCaption = $block->getImageCaption();

        return $data;
    }

    public static function fromRequest(Request $request): self
    {
        $post = $request->request;
        $data = new self();

        $data->heading      = self::nullable(trim((string) $post->get('heading', '')));
        $data->body         = self::nullable(trim(str_replace(["\r\n", "\r"], "\n", (string) $post->get('body', ''))));
        $data->imageAlt     = self::nullable(trim((string) $post->get('imageAlt', '')));
        $data->imageCaption = self::nullable(trim((string) $post->get('imageCaption', '')));
        $data->removeImage  = $post->getBoolean('removeImage');

        return $data;
    }

    /**
     * @param bool $willHaveImage le bloc aura-t-il une image une fois enregistré ?
     *                            (image déjà présente et non retirée, ou nouvelle image envoyée)
     *
     * @return list<string>
     */
    public function validate(bool $willHaveImage): array
    {
        $errors = [];

        // Règle métier : un bloc vide n'a pas de sens sur la fiche.
        if ($this->body === null && !$willHaveImage) {
            $errors[] = 'Un bloc doit contenir du texte ou une image.';
        }

        $limits = [
            'Intertitre'        => [$this->heading, self::HEADING_MAX],
            'Texte'             => [$this->body, self::BODY_MAX],
            'Texte alternatif'  => [$this->imageAlt, self::ALT_MAX],
            'Légende'           => [$this->imageCaption, self::CAPTION_MAX],
        ];
        foreach ($limits as $label => [$value, $max]) {
            if ($value !== null && mb_strlen($value) > $max) {
                $errors[] = sprintf('%s : %d caractères maximum.', $label, $max);
            }
        }

        return $errors;
    }

    private static function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
