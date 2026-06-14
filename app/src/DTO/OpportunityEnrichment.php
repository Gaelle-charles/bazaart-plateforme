<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * OpportunityEnrichment — Résultat de l'enrichissement IA d'une opportunité.
 *
 * Ce DTO encapsule la sortie d'OpportunityEnrichmentService::enrich().
 * Il transporte le titre et la description produits par Mistral à partir
 * du contenu de la page d'origine de l'opportunité.
 *
 * POURQUOI UN DTO SÉPARÉ DE ScrapedOpportunity ?
 *   - ScrapedOpportunity représente une opportunité EXTRAITE (lors du scraping initial).
 *   - OpportunityEnrichment représente uniquement l'ENRICHISSEMENT DIFFÉRÉ d'une
 *     opportunité déjà en BDD (titre retravaillé + description produite à partir de la page).
 *   - Bien séparer les deux évite de mélanger deux responsabilités très différentes.
 *
 * PROPRIÉTÉS NULLABLE :
 *   Les deux champs sont nullable car Mistral peut échouer ou renvoyer une valeur vide.
 *   Dans ce cas, le service appelant ignore l'enrichissement pour ce champ précis
 *   et conserve la valeur existante en BDD.
 *
 * UTILISATION TYPIQUE :
 *   $enrichment = $this->enrichmentService->enrich($scraped->getUrl());
 *   if (!$enrichment->isEmpty()) {
 *       // Au moins un champ utile → on met à jour la BDD
 *   }
 */
final readonly class OpportunityEnrichment
{
    public function __construct(
        /**
         * Titre reformulé en français par le LLM.
         *
         * Null si :
         *   - Le LLM n'a pas pu produire de titre (page insuffisante)
         *   - Une erreur réseau ou API s'est produite
         *   - La sortie JSON du LLM ne contenait pas la clé "titre"
         *
         * Max ~80 caractères (tronqué dans le service si dépassé).
         */
        public ?string $title,

        /**
         * Description fidèle en français produite par le LLM.
         *
         * Résumé de 2 à 4 phrases (max ~400 caractères) basé UNIQUEMENT
         * sur le texte de la page. Le LLM est instruit de ne rien inventer.
         *
         * Null si :
         *   - Le LLM a retourné une chaîne vide (texte insuffisant)
         *   - Une erreur s'est produite
         *
         * Une chaîne vide "" n'est jamais stockée ici — c'est null dans ce cas.
         */
        public ?string $description,
    ) {
    }

    /**
     * Vrai si le DTO ne contient aucun enrichissement utilisable.
     *
     * Un DTO "vide" est retourné par OpportunityEnrichmentService en cas d'échec
     * (erreur réseau, clé manquante, JSON invalide, texte trop court…).
     * Le service appelant (EnrichOpportunitiesCommand) doit vérifier isEmpty()
     * avant d'écrire en BDD pour éviter d'écraser des valeurs existantes avec null.
     *
     * LOGIQUE :
     *   - title = null ET description = null → vide (rien à écrire)
     *   - title non null OU description non null → au moins un enrichissement
     *
     * Exemple d'usage :
     *   if ($enrichment->isEmpty()) {
     *       $io->writeln('  Aucun enrichissement produit, on ignore.');
     *       continue;
     *   }
     */
    public function isEmpty(): bool
    {
        // Les deux champs doivent être null (ou description = "") pour considérer le DTO vide.
        // Une description vide "" n'apporte rien → on la traite comme null ici.
        return $this->title === null && ($this->description === null || $this->description === '');
    }
}
