<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * OpportunityEnrichment — Résultat de l'enrichissement IA d'une opportunité.
 *
 * Ce DTO encapsule la sortie d'OpportunityEnrichmentService::enrich().
 * Il transporte le titre, la description, les disciplines, la ville, le pays
 * et le niveau d'expérience produits par Mistral à partir du contenu de la page.
 *
 * HISTORIQUE :
 *   - v1 (initial) : title, description, disciplines (texte libre)
 *   - v2 (ADR-0016 Lot 2 correctif) : ajout city, country, experienceLevel,
 *     disciplinesLabels (tableau contraint à la liste BDD)
 *
 * POURQUOI UN DTO SÉPARÉ DE ScrapedOpportunity ?
 *   - ScrapedOpportunity représente une opportunité EXTRAITE (lors du scraping initial).
 *   - OpportunityEnrichment représente uniquement l'ENRICHISSEMENT DIFFÉRÉ d'une
 *     opportunité déjà en BDD (titre retravaillé + description + disciplines produits
 *     à partir de la page).
 *   - Bien séparer les deux évite de mélanger deux responsabilités très différentes.
 *
 * PROPRIÉTÉS NULLABLE :
 *   Tous les champs sont nullable car Mistral peut échouer ou renvoyer une valeur vide.
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
    /**
     * @param string[]|null $disciplinesLabels Libellés contraints à la liste BDD (ex: ["Musique", "Danse"])
     */
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
         * Disciplines artistiques — texte CSV (compatibilité rétrograde).
         *
         * Valeurs choisies depuis une liste fermée (Musique, Arts visuels, Danse…)
         * et séparées par des virgules. Exemple : "Musique, Danse".
         *
         * Null si :
         *   - Le LLM n'a pas pu identifier la discipline depuis le texte
         *   - Le LLM a retourné une chaîne vide
         *   - Une erreur s'est produite
         *
         * Max 150 caractères (tronqué dans le service si dépassé).
         *
         * PRÉFÉRER disciplinesLabels (tableau) pour les nouvelles utilisations.
         */
        public ?string $disciplines = null,

        /**
         * Ville où se déroule l'opportunité (extraite par le LLM).
         *
         * Exemples : "Paris", "Bruxelles", "Dakar"
         * Null si le LLM ne l'a pas détectée depuis le texte de la page.
         * Max 150 caractères (limite colonne BDD).
         */
        public ?string $city = null,

        /**
         * Pays de l'opportunité, nom en clair (extrait par le LLM).
         *
         * Exemples : "France", "Belgique", "Sénégal"
         * Null si le LLM ne l'a pas détecté depuis le texte de la page.
         * Max 100 caractères (limite colonne BDD).
         *
         * RÈGLE : ne pas écraser un country issu du CSV (champ WHERE) si déjà renseigné.
         * La commande ImportGrantCsvCommand applique cette règle avant de persister.
         */
        public ?string $country = null,

        /**
         * Niveau d'expérience requis (extrait par le LLM).
         *
         * Valeurs attendues : "beginner", "intermediate", "experienced" ou null.
         * Null = tous niveaux (aucune restriction).
         * Toute autre valeur retournée par le LLM est ignorée (validée dans le service).
         */
        public ?string $experienceLevel = null,

        /**
         * Disciplines artistiques contraintes — tableau de libellés BDD exacts.
         *
         * Ce champ remplace $disciplines pour les nouvelles utilisations.
         * Le LLM choisit parmi la liste exacte des Discipline en BDD, donc ces
         * libellés sont directement mappables via DisciplineMapperService.
         *
         * Exemples : ["Musique", "Arts visuels"], ["Danse"]
         * Null ou tableau vide si aucune discipline détectée.
         */
        public ?array $disciplinesLabels = null,
    ) {
    }

    /**
     * Vrai si le DTO ne contient aucun enrichissement utilisable.
     *
     * Un DTO "vide" est retourné par OpportunityEnrichmentService en cas d'échec
     * (erreur réseau, clé manquante, JSON invalide, texte trop court…).
     * Le service appelant (EnrichOpportunitiesCommand, ImportGrantCsvCommand)
     * doit vérifier isEmpty() avant d'écrire en BDD pour éviter d'écraser
     * des valeurs existantes avec null.
     *
     * LOGIQUE :
     *   - Tous les champs null ou vides → vide (rien à écrire)
     *   - Au moins un champ non null et non vide → enrichissement utilisable
     */
    public function isEmpty(): bool
    {
        // On considère le DTO vide uniquement si TOUS les champs sont nuls/vides.
        // Une description vide + une ville renseignée → on a quand même quelque chose.
        return $this->title === null
            && ($this->description === null || $this->description === '')
            && ($this->disciplines === null || $this->disciplines === '')
            && ($this->city === null || $this->city === '')
            && ($this->country === null || $this->country === '')
            && $this->experienceLevel === null
            && ($this->disciplinesLabels === null || $this->disciplinesLabels === []);
    }
}
