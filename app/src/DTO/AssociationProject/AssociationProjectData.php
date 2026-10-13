<?php

declare(strict_types=1);

namespace App\DTO\AssociationProject;

use App\Entity\AssociationProject;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use Symfony\Component\HttpFoundation\Request;

/**
 * AssociationProjectData : données du formulaire « fiche projet » du back-office (ADR-0039).
 *
 * Même approche que les autres DTO du projet (ex. DTO/Project/ProjectData, car
 * symfony/validator n'est pas installé) :
 *   1. fromRequest() lit et NETTOIE le POST (trim, retours à la ligne, types) ;
 *   2. validate() renvoie la liste des erreurs en français (vide = tout va bien) ;
 *   3. le service applique ensuite les données à l'entité.
 *
 * RETOURS À LA LIGNE : les navigateurs envoient les retours à la ligne d'un
 * <textarea> sous la forme « \r\n » (2 caractères), alors que le compteur du
 * formulaire les compte pour 1. On convertit tout en « \n » AVANT de mesurer :
 * sinon une description de 595 caractères avec 6 retours à la ligne serait
 * refusée alors que le compteur affiche 595 / 600.
 */
final class AssociationProjectData
{
    /** Longueurs maximales (alignées sur les colonnes de la table). */
    public const TITLE_MAX = 150;
    public const PERIOD_MAX = 120;
    public const PLACES_MAX = 255;
    public const ARTISTS_MAX = 255;
    public const LEAD_MAX = 120;
    public const ALT_MAX = 200;
    /** Champs « texte » longs : limite de confort (pas de limite en BDD). */
    public const LONG_TEXT_MAX = 5000;

    public string $title = '';
    public ?AssociationProjectType $type = AssociationProjectType::Residency;
    public ?AssociationProjectState $state = AssociationProjectState::Idea;
    public ?string $period = null;
    public ?string $places = null;
    public ?string $artists = null;
    public ?string $description = null;
    public ?string $confirmedPartners = null;
    public ?string $prospectivePartners = null;
    public ?string $solicitedFunders = null;
    public ?string $partnerContribution = null;
    public ?string $leadName = null;
    public ?string $imageAlt = null;
    public ?int $position = null;
    public bool $isPublished = false;

    /** Case « Retirer le visuel » (édition seulement). */
    public bool $removeImage = false;

    /** @var list<string> erreurs détectées dès la lecture (ordre illisible…) */
    private array $parseErrors = [];

    /** Pré-remplit le formulaire d'édition avec la fiche existante. */
    public static function fromEntity(AssociationProject $project): self
    {
        $data = new self();
        $data->title               = $project->getTitle();
        $data->type                = $project->getType();
        $data->state               = $project->getState();
        $data->period              = $project->getPeriod();
        $data->places              = $project->getPlaces();
        $data->artists             = $project->getArtists();
        $data->description         = $project->getDescription();
        $data->confirmedPartners   = $project->getConfirmedPartners();
        $data->prospectivePartners = $project->getProspectivePartners();
        $data->solicitedFunders    = $project->getSolicitedFunders();
        $data->partnerContribution = $project->getPartnerContribution();
        $data->leadName            = $project->getLeadName();
        $data->imageAlt            = $project->getImageAlt();
        $data->position            = $project->getPosition();
        $data->isPublished         = $project->isPublished();

        return $data;
    }

    public static function fromRequest(Request $request): self
    {
        $post = $request->request;
        $data = new self();

        $data->title               = self::line($post->get('title'));
        $data->type                = AssociationProjectType::tryFrom((string) $post->get('type', ''));
        $data->state               = AssociationProjectState::tryFrom((string) $post->get('state', ''));
        $data->period              = self::nullable(self::line($post->get('period')));
        $data->places              = self::nullable(self::line($post->get('places')));
        $data->artists             = self::nullable(self::line($post->get('artists')));
        $data->description         = self::nullable(self::text($post->get('description')));
        $data->confirmedPartners   = self::nullable(self::text($post->get('confirmedPartners')));
        $data->prospectivePartners = self::nullable(self::text($post->get('prospectivePartners')));
        $data->solicitedFunders    = self::nullable(self::text($post->get('solicitedFunders')));
        $data->partnerContribution = self::nullable(self::text($post->get('partnerContribution')));
        $data->leadName            = self::nullable(self::line($post->get('leadName')));
        $data->imageAlt            = self::nullable(self::line($post->get('imageAlt')));
        $data->isPublished         = $post->getBoolean('isPublished');
        $data->removeImage         = $post->getBoolean('removeImage');

        // Ordre : vide = « à la fin de la liste » (calculé par le service).
        $position = trim((string) $post->get('position', ''));
        if ($position !== '') {
            if (preg_match('/^-?\d{1,6}$/', $position) === 1) {
                $data->position = (int) $position;
            } else {
                $data->parseErrors[] = "L'ordre d'affichage doit être un nombre entier (ex. 10, 20, 30).";
            }
        }

        return $data;
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = $this->parseErrors;

        $titleLength = mb_strlen($this->title);
        if ($titleLength < 2 || $titleLength > self::TITLE_MAX) {
            $errors[] = sprintf('Le titre est obligatoire (entre 2 et %d caractères).', self::TITLE_MAX);
        }
        if ($this->type === null) {
            $errors[] = 'Choisis un type dans la liste.';
        }
        if ($this->state === null) {
            $errors[] = 'Choisis un état dans la liste.';
        }

        $descriptionLength = mb_strlen($this->description ?? '');
        if ($descriptionLength > AssociationProject::DESCRIPTION_MAX_LENGTH) {
            $errors[] = sprintf(
                'La description fait %d caractères : %d maximum.',
                $descriptionLength,
                AssociationProject::DESCRIPTION_MAX_LENGTH,
            );
        }

        $limits = [
            'dates'                          => [$this->period, self::PERIOD_MAX],
            'lieux'                          => [$this->places, self::PLACES_MAX],
            'artistes concernés'             => [$this->artists, self::ARTISTS_MAX],
            'référente'                      => [$this->leadName, self::LEAD_MAX],
            'texte alternatif du visuel'     => [$this->imageAlt, self::ALT_MAX],
            'partenaires confirmés'          => [$this->confirmedPartners, self::LONG_TEXT_MAX],
            'partenaires pressentis'         => [$this->prospectivePartners, self::LONG_TEXT_MAX],
            'financeurs sollicités'          => [$this->solicitedFunders, self::LONG_TEXT_MAX],
            "ce qu'un partenaire peut apporter" => [$this->partnerContribution, self::LONG_TEXT_MAX],
        ];
        foreach ($limits as $label => [$value, $max]) {
            if ($value !== null && mb_strlen($value) > $max) {
                $errors[] = sprintf('Le champ « %s » ne peut pas dépasser %d caractères.', $label, $max);
            }
        }

        // La référente : prénom et nom seulement, jamais d'adresse e-mail personnelle
        // (la page est envoyée à l'extérieur ; le contact public est bonjourbazaart@gmail.com).
        if ($this->leadName !== null && str_contains($this->leadName, '@')) {
            $errors[] = "Référente : indique seulement le prénom et le nom, sans adresse e-mail.";
        }

        return $errors;
    }

    /** Texte sur une ligne : espaces de début et de fin retirés. */
    private static function line(mixed $value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    /** Texte sur plusieurs lignes : retours à la ligne unifiés en « \n », puis trim. */
    private static function text(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
