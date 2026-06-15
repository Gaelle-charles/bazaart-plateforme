<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * OpportunityEnrichment — Résultat de l'enrichissement IA d'une opportunité.
 *
 * Ce DTO encapsule la sortie d'OpportunityEnrichmentService::enrich().
 * Il transporte le titre, la description et les disciplines artistiques produits
 * par Mistral à partir du contenu de la page d'origine de l'opportunité.
 *
 * POURQUOI UN DTO SÉPARÉ DE ScrapedOpportunity ?
 *   - ScrapedOpportunity représente une opportunité EXTRAITE (lors du scraping initial).
 *   - OpportunityEnrichment représente uniquement l'ENRICHISSEMENT DIFFÉRÉ d'une
 *     opportunité déjà en BDD (titre retravaillé + description + disciplines produits
 *     à partir de la page).
 *   - Bien séparer les deux évite de mélanger deux responsabilités très différentes.
 *
 * PROPRIÉTÉS NULLABLE :
 *   Les trois champs sont nullable car Mistral peut échouer ou renvoyer une valeur vide.
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
         * Description structuree en HTML produite par le LLM.
         *
         * Contient du HTML avec UNIQUEMENT les balises : <p>, <ul>, <li>, <strong>.
         * Structure type : une phrase d'intro (<p>), puis une liste (<ul>) avec
         * les sections "Pour qui", "Montant / Dotation", "Conditions", "Date limite"
         * (seules les sections presentes dans le texte source sont incluses).
         *
         * Maximum 1 200 caracteres HTML. Le LLM est instruite de ne rien inventer.
         *
         * IMPORTANT — affichage :
         *   - Page de detail (show.html.twig) : utiliser |raw (HTML interprete)
         *   - Previews, emails : utiliser |striptags avant toute troncation
         *
         * Null si :
         *   - Le LLM a retourne une chaine vide (texte insuffisant)
         *   - Une erreur s'est produite
         *
         * Une chaine vide "" n'est jamais stockee ici — c'est null dans ce cas.
         */
        public ?string $description,

        /**
         * Disciplines artistiques détectées par le LLM.
         *
         * Valeurs choisies depuis une liste fermée (Musique, Arts visuels, Danse…)
         * et séparées par des virgules. Exemple : "Musique, Danse".
         * Si l'opportunité est pluridisciplinaire : "Pluridisciplinaire".
         *
         * Null si :
         *   - Le LLM n'a pas pu identifier la discipline depuis le texte
         *   - Le LLM a retourné une chaîne vide
         *   - Une erreur s'est produite
         *
         * Max 150 caractères (tronqué dans le service si dépassé).
         */
        public ?string $disciplines = null,
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
     *   - title = null ET description = null ET disciplines = null (ou vide) → vide (rien à écrire)
     *   - title non null OU description non null OU disciplines non null → au moins un enrichissement
     *
     * Exemple d'usage :
     *   if ($enrichment->isEmpty()) {
     *       $io->writeln('  Aucun enrichissement produit, on ignore.');
     *       continue;
     *   }
     */
    public function isEmpty(): bool
    {
        // Les TROIS champs doivent être null (ou vides) pour considérer le DTO vide.
        // Si au moins un champ est renseigné, il y a quelque chose à persister en BDD.
        // Une chaîne vide "" n'apporte rien — on la traite comme null ici.
        return $this->title === null
            && ($this->description === null || $this->description === '')
            && ($this->disciplines === null || $this->disciplines === '');
    }
}
