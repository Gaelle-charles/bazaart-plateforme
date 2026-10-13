<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * Résultat de l'import pour UNE entrée du fichier projets.json (ADR-0039).
 *
 * CONFIDENTIALITÉ : on ne garde ici que des titres et des NOMS de champs, jamais
 * leur contenu. Le compte rendu peut ainsi être copié-collé (dans une conversation,
 * un ticket) sans exposer les textes des fiches.
 */
final class AssociationProjectImportEntry
{
    /** La fiche a reçu au moins un champ (en essai : la recevrait). */
    public const STATUS_UPDATED = 'updated';
    /** Fiche trouvée, mais rien à remplir (cas normal d'une 2e exécution). */
    public const STATUS_UNCHANGED = 'unchanged';
    /** Aucun titre identique en base : rien n'est créé. */
    public const STATUS_NOT_FOUND = 'not_found';
    /** Titre présent deux fois (dans le JSON ou en base) : on ne devine pas, on ne touche à rien. */
    public const STATUS_DUPLICATE = 'duplicate';
    /** Fiche déjà publiée : pas de contenu non relu visible des partenaires. */
    public const STATUS_PUBLISHED = 'published';
    /** Fiche exclue de l'import par consigne (« Formations »). */
    public const STATUS_EXCLUDED = 'excluded';
    /** Entrée inutilisable (pas un objet, titre manquant…). */
    public const STATUS_INVALID = 'invalid';

    public string $status = self::STATUS_UNCHANGED;

    /** @var list<string> champs remplis (le visuel compte pour un champ) */
    public array $filled = [];

    /** @var list<string> champs déjà remplis avec la même valeur que le JSON */
    public array $alreadySame = [];

    /** @var list<string> champs déjà remplis avec une AUTRE valeur : non écrasés */
    public array $alreadyDifferent = [];

    /** @var list<string> champs remplis par un import précédent puis vidés : non re-remplis */
    public array $notRefilled = [];

    /** @var list<string> champs à null dans le JSON : non touchés */
    public array $nullInJson = [];

    /** @var list<string> « type » et/ou « état » différents du JSON : jamais modifiés */
    public array $typeStateDifferences = [];

    /** @var list<string> */
    public array $errors = [];

    public function __construct(
        /** Position dans le JSON (1 = première fiche), pour retrouver une entrée sans titre. */
        public readonly int $index,
        public readonly string $title,
    ) {
    }

    /** Vrai si cette entrée doit faire échouer la commande (code de sortie non nul). */
    public function hasProblem(): bool
    {
        return $this->errors !== [] || !in_array($this->status, [self::STATUS_UPDATED, self::STATUS_UNCHANGED], true);
    }
}
