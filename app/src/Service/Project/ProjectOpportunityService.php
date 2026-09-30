<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\AssociationMatch;
use App\DTO\Project\ProjectData;
use App\Entity\AssociationProfile;
use App\Entity\Project;
use App\Entity\ProjectOpportunityReview;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BazaartAssociation;
use App\Enum\OpportunityReviewStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectTaskPriority;
use App\Repository\ProjectOpportunityReviewRepository;
use App\Repository\ResourceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ProjectOpportunityService — onglet « Opportunités » de l'Espace projets (ADR-0038).
 *
 * Rassemble les opportunités du catalogue (aides, bourses, appels à projets,
 * résidences…) qui correspondent à BazaArt Guadeloupe et/ou BazaArt Paris, et
 * gère les décisions de l'équipe : retenir, écarter, candidater.
 *
 * Quatre vues :
 *   a-etudier     opportunités ouvertes qui correspondent, sans décision
 *   retenues      mises de côté par l'équipe
 *   candidatures  un projet de candidature a été créé
 *   ecartees      ne nous intéressent pas (on peut les remettre « à étudier »)
 */
class ProjectOpportunityService
{
    public const array VIEWS = ['a-etudier', 'retenues', 'candidatures', 'ecartees'];

    private const array VIEW_STATUS = [
        'retenues'     => OpportunityReviewStatus::Shortlisted,
        'candidatures' => OpportunityReviewStatus::Applying,
        'ecartees'     => OpportunityReviewStatus::Dismissed,
    ];

    public function __construct(
        private readonly ResourceRepository $resourceRepository,
        private readonly ProjectOpportunityReviewRepository $reviewRepository,
        private readonly AssociationOpportunityMatcher $matcher,
        private readonly AssociationProfileService $profileService,
        private readonly ProjectService $projectService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Lignes à afficher pour une vue, et nombre d'opportunités par vue (onglets).
     *
     * @return array{
     *     rows: list<array{resource: Resource, matches: list<AssociationMatch>, score: int, review: ProjectOpportunityReview|null}>,
     *     counts: array<string, int>
     * }
     */
    public function listing(string $view, ?BazaartAssociation $association): array
    {
        $groups = $this->collect($association);
        $rows   = $view === 'a-etudier' ? $groups['a-etudier'] : ($groups[$view] ?? []);

        return [
            'rows'   => self::sortByDeadline($rows),
            'counts' => array_map('count', $groups),
        ];
    }

    /**
     * Résumé pour la vue d'ensemble de l'Espace projets (encart « Opportunités ») :
     *   - nombre d'opportunités à étudier, au total et par association ;
     *   - nombre de retenues et de candidatures en cours ;
     *   - celles qui se terminent bientôt (à étudier ou retenues, date limite
     *     dans les $soonDays jours), les plus urgentes d'abord.
     *
     * Une seule lecture du catalogue (collect()) pour tout l'encart.
     *
     * @return array{
     *     toReview: int,
     *     byAssociation: array<string, int>,
     *     shortlisted: int,
     *     applying: int,
     *     closingSoon: list<array{resource: Resource, matches: list<AssociationMatch>, score: int, review: ProjectOpportunityReview|null}>
     * }
     */
    public function summary(\DateTimeImmutable $today, int $soonDays = 21, int $limit = 4): array
    {
        $groups = $this->collect(null);

        $byAssociation = [];
        foreach (BazaartAssociation::cases() as $association) {
            $byAssociation[$association->value] = count(array_filter(
                $groups['a-etudier'],
                static fn (array $row): bool => array_filter($row['matches'], static fn (AssociationMatch $m): bool => $m->association === $association) !== [],
            ));
        }

        $limitDate   = $today->modify(sprintf('+%d days', $soonDays))->setTime(23, 59, 59);
        $closingSoon = array_filter(
            array_merge($groups['a-etudier'], $groups['retenues']),
            static function (array $row) use ($today, $limitDate): bool {
                $deadline = $row['resource']->getDeadline();

                return $deadline !== null && $deadline >= $today && $deadline <= $limitDate;
            },
        );

        return [
            'toReview'      => count($groups['a-etudier']),
            'byAssociation' => $byAssociation,
            'shortlisted'   => count($groups['retenues']),
            'applying'      => count($groups['candidatures']),
            'closingSoon'   => array_slice(self::sortByDeadline(array_values($closingSoon)), 0, $limit),
        ];
    }

    /**
     * Toutes les lignes, rangées par vue (a-etudier, retenues, candidatures, ecartees).
     *
     * @return array<string, list<array{resource: Resource, matches: list<AssociationMatch>, score: int, review: ProjectOpportunityReview|null}>>
     */
    private function collect(?BazaartAssociation $association): array
    {
        $reviews  = $this->reviewRepository->findAllIndexedByResource();
        $profiles = $this->profileService->getProfiles();
        $groups   = ['a-etudier' => [], 'retenues' => [], 'candidatures' => [], 'ecartees' => []];

        // ── Opportunités ouvertes qui correspondent, sans décision ────────────
        foreach ($this->resourceRepository->findPublishedForMatching() as $resource) {
            if (isset($reviews[(int) $resource->getId()])) {
                continue;
            }
            $row = $this->buildRow($resource, null, $association, $profiles);
            if ($row !== null) {
                $groups['a-etudier'][] = $row;
            }
        }

        // ── Opportunités avec une décision (même si l'échéance est passée :
        //    une candidature en cours doit rester visible) ─────────────────────
        foreach ($reviews as $review) {
            $key = array_search($review->getStatus(), self::VIEW_STATUS, true);
            if (!is_string($key)) {
                continue;
            }
            // Filtre association : une candidature compte pour l'association qui candidate ;
            // une opportunité retenue / écartée, pour celles auxquelles elle correspond.
            $row = $this->buildRow($review->getResource(), $review, $association, $profiles);
            if ($row !== null) {
                $groups[$key][] = $row;
            }
        }

        return $groups;
    }

    /**
     * Tri : échéance la plus proche d'abord (sans échéance à la fin), puis meilleur score.
     *
     * @param list<array{resource: Resource, matches: list<AssociationMatch>, score: int, review: ProjectOpportunityReview|null}> $rows
     *
     * @return list<array{resource: Resource, matches: list<AssociationMatch>, score: int, review: ProjectOpportunityReview|null}>
     */
    private static function sortByDeadline(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $da = $a['resource']->getDeadline()?->getTimestamp() ?? PHP_INT_MAX;
            $db = $b['resource']->getDeadline()?->getTimestamp() ?? PHP_INT_MAX;

            return [$da, -$a['score']] <=> [$db, -$b['score']];
        });

        return $rows;
    }

    /** Retenir ou écarter une opportunité (décision partagée par toute l'équipe). */
    public function decide(Resource $resource, OpportunityReviewStatus $status, User $actor): void
    {
        $review = $this->reviewRepository->findOneForResource($resource) ?? new ProjectOpportunityReview($resource);
        $review->decide($status, $actor);
        $this->em->persist($review);
        $this->em->flush();
    }

    /** Remet l'opportunité « à étudier » (supprime la décision, le projet éventuel est conservé). */
    public function reset(Resource $resource): void
    {
        $review = $this->reviewRepository->findOneForResource($resource);
        if ($review !== null) {
            $this->em->remove($review);
            $this->em->flush();
        }
    }

    /**
     * « Candidater » : crée un projet avec le modèle « Candidature / appel à projets »
     * (tâches planifiées à rebours depuis l'échéance de l'opportunité), confié à la
     * personne qui clique. Si un projet existe déjà pour cette opportunité, on le renvoie.
     */
    public function apply(Resource $resource, BazaartAssociation $association, User $actor): Project
    {
        $review = $this->reviewRepository->findOneForResource($resource) ?? new ProjectOpportunityReview($resource);
        if ($review->getProject() !== null) {
            return $review->getProject();
        }

        $data = new ProjectData();
        $data->name        = mb_substr(sprintf('Candidature %s · %s', $association === BazaartAssociation::Guadeloupe ? 'Guadeloupe' : 'Paris', $resource->getTitle()), 0, 150);
        $data->description = $this->projectDescription($resource, $association);
        $data->status      = ProjectStatus::Active;
        $data->priority    = ProjectTaskPriority::High;
        $data->ownerId     = $actor->getId();
        $data->templateKey = 'candidature';
        $deadline          = $resource->getDeadline();
        $data->dueDate     = $deadline !== null ? \DateTimeImmutable::createFromInterface($deadline)->setTime(0, 0) : null;

        $project = $this->projectService->create($data, $actor);

        $review->decide(OpportunityReviewStatus::Applying, $actor)
            ->setAssociation($association)
            ->setProject($project);
        $this->em->persist($review);
        $this->em->flush();

        return $project;
    }

    /**
     * Une ligne de la page : l'opportunité, les associations auxquelles elle
     * correspond (avec les raisons), le meilleur score et la décision éventuelle.
     * Renvoie null si la ligne ne doit pas apparaître (filtre association).
     *
     * @param array<string, AssociationProfile> $profiles
     *
     * @return array{resource: Resource, matches: list<AssociationMatch>, score: int, review: ProjectOpportunityReview|null}|null
     */
    private function buildRow(Resource $resource, ?ProjectOpportunityReview $review, ?BazaartAssociation $filter, array $profiles): ?array
    {
        $matches = array_values(array_filter(
            $this->matcher->evaluate($resource, $profiles),
            static fn (AssociationMatch $m): bool => $m->matches && ($filter === null || $m->association === $filter),
        ));

        if ($review === null) {
            // À étudier : uniquement si elle correspond (à l'association filtrée, le cas échéant).
            if ($matches === []) {
                return null;
            }
        } elseif ($filter !== null) {
            // Décision déjà prise : une candidature compte pour l'association qui candidate ;
            // une opportunité retenue / écartée, pour les associations auxquelles elle correspond.
            $candidate = $review->getAssociation();
            if ($candidate !== null ? $candidate !== $filter : $matches === []) {
                return null;
            }
        }

        return [
            'resource' => $resource,
            'matches'  => $matches,
            'score'    => $matches !== [] ? max(array_map(static fn (AssociationMatch $m): int => $m->score, $matches)) : 0,
            'review'   => $review,
        ];
    }

    private function projectDescription(Resource $resource, BazaartAssociation $association): string
    {
        $lines = [
            sprintf('Candidature de %s à l\'opportunité « %s ».', $association->label(), $resource->getTitle()),
            '',
        ];
        $link = $resource->getApplicationUrl() ?? $resource->getExternalUrl();
        if ($link !== null && $link !== '') {
            $lines[] = 'Lien : ' . $link;
        }
        if ($resource->getDeadline() !== null) {
            $lines[] = 'Date limite : ' . $resource->getDeadline()->format('d/m/Y');
        }
        if ($resource->getFundingAmount() !== null && $resource->getFundingAmount() !== '') {
            $lines[] = 'Montant : ' . $resource->getFundingAmount();
        }
        if ($resource->getHowToApply() !== null && $resource->getHowToApply() !== '') {
            $lines[] = '';
            $lines[] = 'Comment candidater : ' . $resource->getHowToApply();
        }

        // Fiche de l'association (« Nos associations ») : base de la note d'intention.
        $profile  = $this->profileService->getProfile($association);
        $identity = array_filter([
            'Objet'              => $profile->getMission(),
            'Publics'            => $profile->getPublics(),
            'Activités'          => $profile->getActivities(),
            'SIRET'              => $profile->getSiret(),
            'Année de création'  => $profile->getFoundedYear() !== null ? (string) $profile->getFoundedYear() : null,
            'Budget annuel'      => $profile->getAnnualBudget(),
            'Moyens humains'     => $profile->getTeam(),
            'Site web'           => $profile->getWebsiteUrl(),
        ], static fn (?string $v): bool => $v !== null && $v !== '');
        if ($identity !== []) {
            $lines[] = '';
            $lines[] = sprintf('— Fiche %s —', $association->label());
            foreach ($identity as $label => $value) {
                $lines[] = $label . ' : ' . $value;
            }
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
    }
}
