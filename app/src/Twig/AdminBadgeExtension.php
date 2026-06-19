<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\CourseProposalRepository;
use App\Repository\OrganizationProfileRepository;
use App\Repository\ResourceRepository;
use App\Repository\ScrapedResourceRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * AdminBadgeExtension — compteurs pour les badges de la sidebar admin.
 *
 * PROBLEME RESOLU :
 *   La sidebar admin est definie dans base_admin.html.twig et etendue par TOUTES
 *   les pages admin. Chaque onglet doit afficher un badge (pastille numerique)
 *   quand son file d'attente est non vide.
 *
 *   Option naive : passer ces compteurs depuis chaque controller admin.
 *   Probleme : il faudrait dupliquer le meme code dans une dizaine de controllers.
 *   Une oubli = badge absent sur certaines pages.
 *
 * SOLUTION : extension Twig avec fonctions globales.
 *   Comme NotificationExtension pour les notifs utilisateur, on expose ici
 *   des fonctions Twig appelables depuis n'importe quel template admin
 *   SANS que le controller ait a les passer explicitement.
 *
 *   Enregistrement automatique : autoconfigure: true dans services.yaml detecte
 *   que cette classe extends AbstractExtension et lui ajoute le tag twig.extension.
 *
 * COMPTEURS EXPOSES (4 fonctions) :
 *   - admin_badge_resources_pending()     : ressources a valider (Validation)
 *   - admin_badge_structures_pending()    : candidatures Structure en attente
 *   - admin_badge_proposals_pending()     : propositions de formation en attente
 *   - admin_badge_scraped_pending()       : opportunites scrapees a verifier
 *
 * PERFORMANCE :
 *   Chaque appel fait un SELECT COUNT(*) SQL (un seul scalaire).
 *   Un cache par-requete (propriete privee nullable) evite les doubles appels
 *   si la meme fonction est invoquee plusieurs fois dans le meme rendu
 *   (ex: template parent + bloc enfant).
 */
class AdminBadgeExtension extends AbstractExtension
{
    // Cache par rendu : evite plusieurs requetes SQL identiques dans la meme page.
    // null = pas encore calcule ; int = valeur memorisee pour ce rendu.
    private ?int $cachedResourcesPending  = null;
    private ?int $cachedStructuresPending = null;
    private ?int $cachedProposalsPending  = null;
    private ?int $cachedScrapedPending    = null;

    public function __construct(
        private readonly ResourceRepository              $resourceRepository,
        private readonly OrganizationProfileRepository   $orgRepository,
        private readonly CourseProposalRepository        $courseProposalRepository,
        private readonly ScrapedResourceRepository       $scrapedResourceRepository,
    ) {}

    /**
     * Declare les 4 fonctions Twig disponibles dans les templates admin.
     *
     * Convention de nommage : prefixe "admin_badge_" pour les identifier
     * clairement dans les templates et eviter toute collision avec d'autres extensions.
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            // Badge onglet "Validation" (ressources soumises en attente de moderation)
            new TwigFunction('admin_badge_resources_pending', [$this, 'getResourcesPending']),

            // Badge onglet "Structures" (candidatures non encore traitees)
            new TwigFunction('admin_badge_structures_pending', [$this, 'getStructuresPending']),

            // Badge onglet "Propositions" (propositions de formation a examiner)
            new TwigFunction('admin_badge_proposals_pending', [$this, 'getProposalsPending']),

            // Badge onglet "Opportunites scrapees" (a verifier ou rejeter)
            new TwigFunction('admin_badge_scraped_pending', [$this, 'getScrapedPending']),
        ];
    }

    /**
     * Nombre de ressources en attente de validation.
     * Badge affiché sur l'onglet "Validation" de la sidebar.
     */
    public function getResourcesPending(): int
    {
        if ($this->cachedResourcesPending === null) {
            $this->cachedResourcesPending = $this->resourceRepository->countPending();
        }

        return $this->cachedResourcesPending;
    }

    /**
     * Nombre de candidatures Structure en attente d'activation par un admin.
     * Badge affiché sur l'onglet "Structures" de la sidebar.
     */
    public function getStructuresPending(): int
    {
        if ($this->cachedStructuresPending === null) {
            $this->cachedStructuresPending = $this->orgRepository->countPendingStructureApplications();
        }

        return $this->cachedStructuresPending;
    }

    /**
     * Nombre de propositions de formation en attente de review.
     * Badge affiché sur l'onglet "Propositions" de la sidebar.
     */
    public function getProposalsPending(): int
    {
        if ($this->cachedProposalsPending === null) {
            $this->cachedProposalsPending = $this->courseProposalRepository->countPending();
        }

        return $this->cachedProposalsPending;
    }

    /**
     * Nombre d'opportunites scrapees en attente de verification.
     * Badge affiché sur l'onglet "Opportunites scrapees" de la sidebar.
     */
    public function getScrapedPending(): int
    {
        if ($this->cachedScrapedPending === null) {
            $this->cachedScrapedPending = $this->scrapedResourceRepository->countPending();
        }

        return $this->cachedScrapedPending;
    }
}
