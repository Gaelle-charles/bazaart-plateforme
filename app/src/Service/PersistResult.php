<?php

declare(strict_types=1);

namespace App\Service;

/**
 * PersistResult — Résultat d'un appel à ScrapedResourcePersister::persistBatch().
 *
 * Ce petit DTO (Data Transfer Object) porte les compteurs de déduplication
 * renvoyés par le persister. Il remplace le retour de plusieurs variables
 * scalaires par un objet typé, plus clair et plus extensible.
 *
 * ── POURQUOI une classe dédiée et pas un tableau ? ───────────────────────────
 * PHP ne peut pas typer les clés d'un tableau (`array{inserted: int, ...}`).
 * Une classe readonly permet à PHPStan de vérifier les types sans ambiguïté,
 * et à l'IDE d'auto-compléter les propriétés → moins d'erreurs.
 *
 * ── READONLY ────────────────────────────────────────────────────────────────
 * Les propriétés readonly PHP 8.1 sont initialisées dans le constructeur
 * et ne peuvent plus être modifiées ensuite. C'est approprié ici car les
 * compteurs sont des faits immuables (ils décrivent ce QUI S'EST PASSÉ,
 * pas un état en cours).
 *
 * ── CORRECTION AV-4 (ADR-0016 Lot 2) ───────────────────────────────────────
 * Ajout de $insertedUrls : liste des URLs réellement insérées en BDD (Cas 1 du
 * persister). Permet à ImportGrantCsvCommand de n'enrichir QUE les opportunités
 * nouvelles, et non celles déjà en BDD (qui auraient sinon régénéré un appel
 * Mistral inutile et coûteux à chaque ré-import).
 */
final readonly class PersistResult
{
    public function __construct(
        // Nouvelles URLs jamais vues → INSERT avec status pending
        public int $inserted,

        // URLs connues qui étaient archivées → réactivées en pending
        public int $reactivated,

        // URLs connues (pending ou rejected) → données rafraîchies, statut inchangé
        public int $updated,

        // URLs ignorées : déjà vérifiées par un admin, ou doublons intra-lot (URL)
        public int $skipped,

        /**
         * Liste des URLs effectivement insérées (Cas 1 — nouvelles en BDD).
         * Utilisé par ImportGrantCsvCommand (--enrich) pour ne Mistraliser que les
         * nouvelles entrées, pas les URL déjà connues (updated/skipped/reactivated).
         *
         * @var string[]
         */
        public array $insertedUrls = [],

        /**
         * Nombre d'opportunités ignorées parce qu'un doublon DE CONTENU existait déjà
         * (même titre normalisé + même deadline) dans scraped_resources OU dans resources.
         *
         * C'est le compteur de la DÉDUPLICATION PAR CONTENU, ajoutée en ADR-0016 Lot 2.
         * Elle complète la déduplication par URL (comptabilisée dans $skipped).
         *
         * Exposé séparément de $skipped pour faciliter le diagnostic :
         *   - $skipped      → doublons URL (même lien) + déjà vérifiés par admin
         *   - $contentDedup → doublons contenu (même titre+deadline, URLs différentes)
         *
         * Exemple d'affichage dans les logs de commande :
         *   "3 doublons contenu ignorés (même titre+deadline, URLs différentes)"
         */
        public int $contentDedup = 0,
    ) {
    }

    /**
     * Nombre total d'opportunités traitées (toutes décisions confondues).
     *
     * ── INCLUT contentDedup (S2) ──────────────────────────────────────────────
     * contentDedup représente des opportunités reçues par le pipeline (chaque
     * ScrapedOpportunity passe dans la boucle persistBatch et est comptée comme
     * "traitée") mais ignorées pour doublon de contenu.
     * Les inclure dans total() donne le chiffre cohérent avec le nombre d'entrées
     * reçues par le pipeline — utile pour valider que le batch a bien été traité en entier.
     *
     * Avant S2 : total() = inserted + reactivated + updated + skipped
     *   → Ne comptait PAS les doublons contenu → total() < nombre d'opportunités reçues
     *   → Incohérence silencieuse dans les logs de commande.
     *
     * Après S2 : total() = inserted + reactivated + updated + skipped + contentDedup
     *   → Cohérent avec le nombre d'entrées reçues par le pipeline.
     *
     * Note : les doublons URL intra-lot (incrémentés dans $skipped) sont déjà inclus
     * via $skipped. Les doublons de contenu intra-lot (incrémentés dans $contentDedup)
     * sont maintenant également comptés.
     *
     * Utile pour les logs : "X opportunités traitées" sans détailler les cas.
     */
    public function total(): int
    {
        return $this->inserted + $this->reactivated + $this->updated + $this->skipped + $this->contentDedup;
    }
}
