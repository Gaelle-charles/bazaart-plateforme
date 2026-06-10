<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * ScrapedOpportunity — Structure de données pour une opportunité scrapée.
 *
 * Ce DTO (Data Transfer Object) représente une opportunité artistique
 * récupérée depuis un site web externe. Il sert de "conteneur" pour
 * transporter les données entre le scraper et le service Google Sheets.
 *
 * Un DTO n'a pas de logique métier, il stocke juste des données.
 */
class ScrapedOpportunity
{
    public function __construct(
        // Titre de l'opportunité (ex: "Appel à projets - Résidence 2025")
        public readonly string $title,

        // Type : "Résidence", "Appel à projets", "Bourse", "Financement", etc.
        public readonly string $type,

        // URL de la page source (lien direct vers l'opportunité)
        public readonly string $url,

        // Nom du site source (ex: "cnap.fr", "artcena.fr")
        public readonly string $source,

        // Description courte si disponible (peut être vide)
        public readonly string $description = '',

        // Date limite de candidature si trouvée (format "JJ/MM/AAAA" ou texte brut)
        public readonly string $deadline = '',

        // Disciplines concernées si mentionnées (ex: "Arts plastiques, Photographie")
        public readonly string $disciplines = '',

        // URLs de documents téléchargeables trouvés sur la page, séparées par des virgules
        // (ex: liens PDF de règlement, dossier de candidature, etc.)
        public readonly string $documents = '',

        // Score de pertinence Afrodiaspora de 0 à 5, calculé par AfrodiasporaRelevanceScorer
        // 0 = aucun lien avec l'Afrodiaspora, 5 = très fortement lié
        public readonly int $relevanceScore = 0,

        // Date de publication originale de l'annonce sur la source (flux RSS pubDate / Atom published).
        //
        // RÈGLE MÉTIER FONDAMENTALE :
        //   Cette date NE DOIT JAMAIS être placée dans le champ `deadline`.
        //   Elle est ici dans publishedAt — champ dédié — et sera mappée vers
        //   ScrapedResource::publishedAt par ScrapedResourcePersister.
        //
        // Null pour les opportunités issues de scrapers CSS ou LLM
        // (pas de date de publication structurée disponible dans ce cas).
        public readonly ?\DateTimeImmutable $publishedAt = null,
    ) {
    }

    /**
     * Convertit l'opportunité en tableau pour l'écriture dans Google Sheets.
     * Chaque valeur correspond à une colonne dans le tableur.
     *
     * IMPORTANT : ce tableau doit contenir exactement 11 valeurs,
     * dans le même ordre que GoogleSheetsService::HEADERS.
     *
     * @return array<string|int> Tableau de valeurs dans l'ordre des colonnes
     *
     * @deprecated Le scraping écrit désormais directement en BDD (table scraped_resources).
     *   Cette méthode est conservée pour compatibilité avec l'historique Google Sheets.
     *   À supprimer en V2 avec GoogleSheetsService et FormatSheetsCommand.
     */
    public function toSheetRow(): array
    {
        return [
            $this->title,       // Titre
            $this->type,        // Type
            $this->deadline,    // Date limite
            $this->disciplines, // Disciplines
            $this->description, // Description
            $this->url,         // URL source
            $this->source,      // Site
            $this->documents,   // Documents (liens PDF séparés par des virgules)

            // Score Afrodiaspora : affiché en étoiles (ex: "★★★☆☆" pour 3/5)
            // Si le score est 0, on affiche 5 étoiles vides pour bien montrer l'absence de pertinence
            $this->relevanceScore > 0
                ? str_repeat('★', $this->relevanceScore) . str_repeat('☆', 5 - $this->relevanceScore)
                : '☆☆☆☆☆',

            // Date et heure du scraping (pour savoir quand c'a été collecté)
            (new \DateTime())->format('d/m/Y H:i'),

            // Statut initial : "À vérifier" — l'admin doit valider dans Sheets
            'À vérifier',
        ];
    }
}
