<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use App\Entity\ScrapedResource;
use App\Enum\ScrapedResourceStatus;
use App\Repository\ScrapedResourceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ScrapedResourcePersister — Déduplication et persistance des opportunités scrapées.
 *
 * Ce service factorise la logique de déduplication/persistance qui était
 * inline dans ScrapeOpportunitiesCommand (lignes ~248-350).
 *
 * ── POURQUOI factoriser ici ? ────────────────────────────────────────────────
 * En WS2, le pipeline RSS (FeedReaderService) produit des ScrapedOpportunity[]
 * exactement comme l'ancien pipeline LLM/CSS. Plutôt que de dupliquer 80 lignes
 * de logique délicate dans chaque pipeline, on centralise ici.
 * Un seul endroit de vérité → une seule correction si un bug de dédup est trouvé.
 *
 * ── LES 5 CAS DE DÉDUPLICATION ──────────────────────────────────────────────
 *   1. URL inconnue en BDD         → INSERT, status = pending
 *   2. URL connue + status archived → réactivation en pending + reset scrapedAt
 *   3. URL connue + status rejected → mise à jour des champs, status inchangé
 *   4. URL connue + status pending  → mise à jour des champs, status inchangé
 *   5. URL connue + status verified → skip complet (travail de modération protégé)
 *
 * ── GUARD INTRA-LOT ─────────────────────────────────────────────────────────
 * Le LLM ou le parseur RSS peut retourner deux fois la même URL dans le même lot.
 * Sans guard, le second passage ne trouve rien en BDD (pas encore flushé) et tente
 * un INSERT → violation de contrainte UNIQUE. Le set $seenUrls déduplique en mémoire
 * AVANT la vérification BDD, évitant cette collision.
 *
 * ── FLUSH UNIQUE ────────────────────────────────────────────────────────────
 * Un seul flush() à la fin du batch. Doctrine regroupe toutes les opérations en
 * une seule transaction → bien plus performant qu'un flush par item.
 */
class ScrapedResourcePersister
{
    public function __construct(
        // EntityManager pour persister les nouvelles ScrapedResource
        private readonly EntityManagerInterface $em,
        // Repository pour vérifier les doublons (déduplication par URL)
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
    ) {
    }

    /**
     * Persiste un lot d'opportunités en BDD avec déduplication complète.
     *
     * C'est la méthode principale de ce service. Elle reçoit les opportunités
     * brutes depuis n'importe quel pipeline (LLM, CSS, RSS) et applique
     * systématiquement la même logique de dédup/persistance.
     *
     * @param ScrapedOpportunity[] $opportunities Liste d'opportunités à persister
     *
     * @return PersistResult Compteurs : inserted / reactivated / updated / skipped
     */
    public function persistBatch(array $opportunities): PersistResult
    {
        // Compteurs pour les logs et l'affichage dans la commande
        $inserted    = 0;
        $reactivated = 0;
        $updated     = 0;
        $skipped     = 0;

        // ── Guard en mémoire contre les doublons INTRA-LOT ───────────────────
        // Sans ce set, deux occurrences de la même URL dans le même batch
        // (cas fréquent avec les retours LLM) provoqueraient une violation
        // de contrainte UNIQUE sur scraped_resources.url au moment du flush.
        // On garde une trace de chaque URL déjà traitée dans ce batch.
        /** @var array<string, true> $seenUrls */
        $seenUrls = [];

        foreach ($opportunities as $opp) {
            // ── Déduplication intra-lot ───────────────────────────────────────
            // Si la même URL apparaît deux fois dans ce batch, on ignore les
            // occurrences après la première.
            if ($opp->url !== null && $opp->url !== '') {
                if (isset($seenUrls[$opp->url])) {
                    $skipped++;
                    continue;
                }
                $seenUrls[$opp->url] = true;
            }

            // ── Recherche en BDD (déduplication inter-lots) ──────────────────
            // findByUrl() fait un SELECT sur la contrainte UNIQUE url.
            // Si null → URL jamais vue → cas 1 (INSERT).
            // Sinon → on applique le cas approprié selon le statut.
            $existing = $opp->url ? $this->scrapedResourceRepository->findByUrl($opp->url) : null;

            if ($existing !== null) {
                // ── Cas 5 : déjà vérifiée par un admin → intouchable ─────────
                // Un admin a validé cette opportunité. On préserve son travail
                // de modération : ni mise à jour, ni réinsertion.
                if ($existing->isVerified()) {
                    $skipped++;
                    continue;
                }

                // ── Cas 2 : archivée → réactivation en pending ───────────────
                // L'opportunité avait expiré (deadline passée) ou avait été
                // archivée manuellement. Le scraper la retrouve sur le site
                // → elle est probablement de nouveau valide.
                // On remet scrapedAt à "maintenant" pour que archiveExpired()
                // ne la ré-archive pas immédiatement (protection 48h).
                if ($existing->isArchived()) {
                    $existing->setTitle($opp->title);
                    $existing->setDescription($opp->description ?: null);
                    $existing->setType($opp->type ?: null);
                    $existing->setDeadline($opp->deadline ?: null);
                    $existing->setRelevanceScore($opp->relevanceScore);
                    $existing->setDisciplines($opp->disciplines ?: null);
                    $existing->setStatus(ScrapedResourceStatus::Pending); // réactivation
                    $existing->setScrapedAt(new \DateTime());              // reset grâce 48h
                    $reactivated++;
                    continue;
                }

                // ── Cas 3 & 4 : rejected ou pending → rafraîchir les données ─
                // On ne change PAS le statut : si un admin a rejeté l'opportunité,
                // elle reste rejetée. On met à jour les données (titre, desc, etc.)
                // au cas où le site a modifié l'annonce entre deux runs.
                // Doctrine détecte les changements via le Unit of Work — pas besoin
                // d'appeler persist() explicitement sur une entité déjà managée.
                $existing->setTitle($opp->title);
                $existing->setDescription($opp->description ?: null);
                $existing->setType($opp->type ?: null);
                $existing->setDeadline($opp->deadline ?: null);
                $existing->setRelevanceScore($opp->relevanceScore);
                $existing->setDisciplines($opp->disciplines ?: null);
                $updated++;
                continue;
            }

            // ── Cas 1 : nouvelle URL → INSERT en BDD ─────────────────────────
            // Status par défaut : pending (l'admin valide depuis /admin/scraped-opportunities)
            $scraped = new ScrapedResource();
            $scraped->setTitle($opp->title);
            $scraped->setDescription($opp->description ?: null);
            $scraped->setUrl($opp->url ?: null);
            $scraped->setType($opp->type ?: null);
            $scraped->setSourceSite($opp->source ?: null);
            $scraped->setDeadline($opp->deadline ?: null);
            $scraped->setRelevanceScore($opp->relevanceScore);
            $scraped->setDocuments($opp->documents ?: null);
            $scraped->setDisciplines($opp->disciplines ?: null);
            // Status = pending par défaut (valeur initiale définie dans ScrapedResource)

            // persist() ajoute l'entité dans l'Unit of Work de Doctrine.
            // L'INSERT SQL réel est exécuté lors du flush() à la fin du batch.
            $this->em->persist($scraped);
            $inserted++;
        }

        // ── Flush unique ──────────────────────────────────────────────────────
        // Toutes les insertions, réactivations et mises à jour sont envoyées
        // en une seule transaction. Plus efficace et plus sûr qu'un flush par item
        // (si un item échoue, toute la transaction est rollbackée).
        $this->em->flush();

        return new PersistResult(
            inserted: $inserted,
            reactivated: $reactivated,
            updated: $updated,
            skipped: $skipped,
        );
    }
}
