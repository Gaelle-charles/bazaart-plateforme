<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BazaartAssociation;
use App\Repository\AssociationProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * AssociationProfile — fiche d'une de nos associations (ADR-0038).
 *
 * Une fiche par association (BazaArt Guadeloupe, BazaArt Paris), remplie par
 * l'équipe dans l'Espace projets (« Opportunités › Nos associations »).
 *
 * Deux usages :
 *   1. AFFINER LE TRI des opportunités (AssociationOpportunityMatcher) :
 *      territoire, thèmes / publics, disciplines, types d'opportunités
 *      recherchés, mots à exclure ;
 *   2. SERVIR DE FICHE D'IDENTITÉ au moment de candidater : objet, publics,
 *      activités, SIRET, année de création, budget… sont recopiés dans le
 *      projet de candidature (utile pour la note d'intention).
 *
 * Les listes de mots-clés sont stockées en texte (une entrée par ligne ou
 * séparées par des virgules) : simple à modifier, sans table supplémentaire.
 */
#[ORM\Entity(repositoryClass: AssociationProfileRepository::class)]
#[ORM\Table(name: 'project_association_profiles')]
class AssociationProfile
{
    /** Types d'opportunités qu'une association peut rechercher (clé => libellé). */
    public const array OPPORTUNITY_TYPES = [
        'aides'      => 'Aides, subventions, bourses, fonds',
        'appels'     => 'Appels à projets, prix, concours',
        'residences' => 'Résidences',
        'formations' => 'Formations, ateliers, accompagnement',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20, unique: true, enumType: BazaartAssociation::class)]
    private BazaartAssociation $association;

    // ── Identité (fiche recopiée dans les candidatures) ──────────────────────

    /** Objet de l'association (tel que dans les statuts) et objectifs. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mission = null;

    /** Publics visés (jeunes, artistes émergents, habitants des quartiers…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $publics = null;

    /** Activités principales (festival, ateliers, expositions, accompagnement…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $activities = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $foundedYear = null;

    /** Budget annuel (texte libre : « ≈ 25 000 € »). */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $annualBudget = null;

    /** Moyens humains (texte libre : « 3 bénévoles, 1 service civique »). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $team = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    // ── Critères du tri des opportunités ─────────────────────────────────────

    /** Lieux qui désignent notre territoire (villes, départements, régions…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $territoryKeywords = null;

    /** Thèmes, publics, champs d'action : chaque mot trouvé dans une opportunité la rend plus pertinente. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $themeKeywords = null;

    /** Mots qui disqualifient une opportunité (ex. « doctorant », « entreprise »). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $excludedKeywords = null;

    /** @var list<string> clés de OPPORTUNITY_TYPES */
    #[ORM\Column(type: 'json')]
    private array $soughtTypes = ['aides', 'appels', 'residences'];

    /**
     * Disciplines artistiques portées par l'association. Vide = pluridisciplinaire
     * (aucune opportunité n'est alors écartée pour une question de discipline).
     *
     * @var Collection<int, Discipline>
     */
    #[ORM\ManyToMany(targetEntity: Discipline::class)]
    #[ORM\JoinTable(name: 'project_association_profile_disciplines')]
    #[ORM\JoinColumn(name: 'association_profile_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'discipline_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $disciplines;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Fiche neuve, pré-remplie avec des valeurs de départ raisonnables
     * (territoire de l'association, thèmes afro-diasporiques de Bazaart).
     */
    public function __construct(BazaartAssociation $association)
    {
        $this->association       = $association;
        $this->disciplines       = new ArrayCollection();
        $this->territoryKeywords = implode(', ', $association->territoryKeywords());
        $this->themeKeywords     = implode(', ', BazaartAssociation::defaultThemeKeywords());
    }

    /**
     * Découpe un texte « mot1, mot2 ; mot3 (retour à la ligne) mot4 » en liste propre :
     * sans doublons, sans entrées vides, limitée (60 entrées de 80 caractères).
     *
     * @return list<string>
     */
    public static function splitKeywords(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        $items = array_map(static fn (string $s): string => mb_substr(trim($s), 0, 80), preg_split('/[,;\n\r]+/u', $text) ?: []);

        return array_slice(array_values(array_unique(array_filter($items, static fn (string $s): bool => $s !== ''))), 0, 60);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): BazaartAssociation
    {
        return $this->association;
    }

    public function getMission(): ?string
    {
        return $this->mission;
    }

    public function setMission(?string $v): static
    {
        $this->mission = $v;

        return $this;
    }

    public function getPublics(): ?string
    {
        return $this->publics;
    }

    public function setPublics(?string $v): static
    {
        $this->publics = $v;

        return $this;
    }

    public function getActivities(): ?string
    {
        return $this->activities;
    }

    public function setActivities(?string $v): static
    {
        $this->activities = $v;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $v): static
    {
        $this->siret = $v;

        return $this;
    }

    public function getFoundedYear(): ?int
    {
        return $this->foundedYear;
    }

    public function setFoundedYear(?int $v): static
    {
        $this->foundedYear = $v;

        return $this;
    }

    public function getAnnualBudget(): ?string
    {
        return $this->annualBudget;
    }

    public function setAnnualBudget(?string $v): static
    {
        $this->annualBudget = $v;

        return $this;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    public function setTeam(?string $v): static
    {
        $this->team = $v;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $v): static
    {
        $this->websiteUrl = $v;

        return $this;
    }

    public function getTerritoryKeywords(): ?string
    {
        return $this->territoryKeywords;
    }

    public function setTerritoryKeywords(?string $v): static
    {
        $this->territoryKeywords = $v;

        return $this;
    }

    public function getThemeKeywords(): ?string
    {
        return $this->themeKeywords;
    }

    public function setThemeKeywords(?string $v): static
    {
        $this->themeKeywords = $v;

        return $this;
    }

    public function getExcludedKeywords(): ?string
    {
        return $this->excludedKeywords;
    }

    public function setExcludedKeywords(?string $v): static
    {
        $this->excludedKeywords = $v;

        return $this;
    }

    /** @return list<string> */
    public function getSoughtTypes(): array
    {
        return $this->soughtTypes;
    }

    /** @param list<string> $types seules les clés connues de OPPORTUNITY_TYPES sont gardées */
    public function setSoughtTypes(array $types): static
    {
        $this->soughtTypes = array_values(array_intersect(array_keys(self::OPPORTUNITY_TYPES), $types));

        return $this;
    }

    /** @return Collection<int, Discipline> */
    public function getDisciplines(): Collection
    {
        return $this->disciplines;
    }

    /** @param iterable<Discipline> $disciplines remplace la liste */
    public function replaceDisciplines(iterable $disciplines): static
    {
        $this->disciplines->clear();
        foreach ($disciplines as $discipline) {
            $this->disciplines->add($discipline);
        }

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(User $by): static
    {
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Part des champs remplis (0 à 100), affichée pour inciter à compléter la fiche.
     */
    public function completion(): int
    {
        $fields = [$this->mission, $this->publics, $this->activities, $this->siret, $this->foundedYear, $this->annualBudget, $this->team, $this->territoryKeywords, $this->themeKeywords];
        $filled = count(array_filter($fields, static fn (mixed $v): bool => $v !== null && $v !== ''));
        $filled += $this->disciplines->count() > 0 ? 1 : 0;

        return (int) round($filled / (count($fields) + 1) * 100);
    }
}
