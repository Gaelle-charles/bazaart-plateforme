<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ScrapedResource;
use App\Service\DeadlineParserService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

/**
 * ScrapedResourceListener — Normalisation automatique avant toute écriture en BDD.
 *
 * Ce listener s'applique à CHAQUE prePersist et preUpdate sur ScrapedResource,
 * quel que soit l'appelant (ScrapeOpportunitiesCommand, AdminController, fixtures…).
 * Cela garantit que les règles de nettoyage/parsing sont centralisées ici et ne
 * peuvent pas être oubliées dans un nouveau scraper ou contrôleur.
 *
 * RÈGLES APPLIQUÉES (dans l'ordre) :
 *   1. html_entity_decode sur title   → supprime &#8211;, &rsquo;, &amp;, etc.
 *   2. html_entity_decode sur description (si non null)
 *   3. Parsing deadline (string) → deadlineDate (\DateTimeImmutable|null)
 *
 * POURQUOI html_entity_decode ICI et pas dans les scrapers ?
 *   Les scrapers CSS dédiés (CnapScraper, AdagpScraper, etc.) extraient le HTML brut
 *   sans décoder les entités. Plutôt que de patcher chaque scraper (risque d'oubli
 *   sur les futurs), on centralise le décodage en sortie, juste avant la persistance.
 *
 * PIÈGE preUpdate + Doctrine :
 *   Dans preUpdate, si on modifie l'entité directement, Doctrine ne détecte PAS
 *   automatiquement le changement de deadlineDate dans son changeset interne.
 *   Il faut appeler recomputeSingleEntityChangeSet() pour forcer la réévaluation.
 *   Sans ça, les modifications seraient silencieusement ignorées lors du flush.
 */
#[AsEntityListener(event: Events::prePersist, entity: ScrapedResource::class)]
#[AsEntityListener(event: Events::preUpdate, entity: ScrapedResource::class)]
class ScrapedResourceListener
{
    public function __construct(
        // Service de parsing des deadlines — injecté par autowiring
        private readonly DeadlineParserService $deadlineParser,
    ) {
    }

    /**
     * Appelé juste avant la première insertion en BDD (INSERT).
     *
     * On peut modifier l'entité librement ici — Doctrine lira les valeurs finales
     * juste après ce callback pour construire le INSERT SQL.
     */
    public function prePersist(ScrapedResource $resource, PrePersistEventArgs $args): void
    {
        $this->normalize($resource);
    }

    /**
     * Appelé juste avant une mise à jour en BDD (UPDATE).
     *
     * ATTENTION : dans preUpdate, Doctrine a déjà calculé le changeset (diff entre
     * l'état "snapshot" et l'état actuel). Toute modification faite ICI sur l'entité
     * ne sera prise en compte que si on appelle recomputeSingleEntityChangeSet().
     * Sans cet appel, deadlineDate serait modifiée en mémoire mais pas sauvegardée.
     */
    public function preUpdate(ScrapedResource $resource, PreUpdateEventArgs $args): void
    {
        $this->normalize($resource);

        // Force Doctrine à recalculer le changeset avec les nouvelles valeurs.
        // Obligatoire dans preUpdate — sans ça, les modifications ci-dessus sont perdues.
        $em       = $args->getObjectManager();
        $uow      = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ScrapedResource::class);
        $uow->recomputeSingleEntityChangeSet($metadata, $resource);
    }

    /**
     * Applique toutes les règles de normalisation sur une ScrapedResource.
     *
     * Méthode privée partagée entre prePersist et preUpdate pour éviter la duplication.
     *
     * @param ScrapedResource $resource L'entité à normaliser (modifiée en place)
     */
    private function normalize(ScrapedResource $resource): void
    {
        // ── Règle 1 : décodage des entités HTML dans le titre ─────────────────
        // Certains scrapers CSS extraient le HTML brut sans décoder les entités.
        // Exemple avant : "Prix Jean–Luc Godard" (avec &#8211; = tiret long)
        // Exemple après : "Prix Jean–Luc Godard"
        // ENT_QUOTES | ENT_HTML5 : décode &rsquo; ('), &ldquo; ("), &amp; (&), etc.
        $resource->setTitle(
            html_entity_decode($resource->getTitle(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );

        // ── Règle 2 : décodage des entités HTML dans la description ───────────
        // Même logique que le titre — on ne décode que si la description existe.
        if ($resource->getDescription() !== null) {
            $resource->setDescription(
                html_entity_decode($resource->getDescription(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

        // ── Règle 3 : parsing deadline (string) → deadlineDate (datetime) ─────
        // Si deadline est null ou vide, deadlineDate = null (pas de date connue).
        // Si deadline est non vide, on tente les 3 formats via DeadlineParserService.
        // Le parser retourne null si aucun format ne correspond — jamais d'exception.
        $deadlineStr = $resource->getDeadline();
        $resource->setDeadlineDate(
            $deadlineStr !== null && $deadlineStr !== ''
                ? $this->deadlineParser->parse($deadlineStr)
                : null
        );
    }
}
