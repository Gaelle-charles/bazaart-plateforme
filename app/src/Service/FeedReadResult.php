<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;

/**
 * FeedReadResult — Résultat d'un appel à FeedReaderService::readWithResult().
 *
 * Ce DTO fait la distinction cruciale entre :
 *   - un ÉCHEC de fetch (HTTP non-200, timeout, XML invalide)  → success = false
 *   - un SUCCÈS avec 0 item (flux vide ou aucun item ne matche les mots-clés) → success = true
 *
 * ── POURQUOI distinguer ces deux cas ? ─────────────────────────────────────
 * ReadFeedsCommand (WS3) doit mettre à jour le compteur consecutiveFailures de
 * la source :
 *   - success = false → incrementConsecutiveFailures() → auto-désactivation à 5
 *   - success = true + items = [] → resetConsecutiveFailures() → la source fonctionne
 *
 * Sans cette distinction, un flux RSS vide serait traité comme une erreur et
 * désactiverait la source après 5 runs vides — comportement faux-positif.
 *
 * ── DESIGN RÉTRO-COMPATIBLE ─────────────────────────────────────────────────
 * FeedReaderService::read() est conservée telle quelle pour les appelants existants.
 * La nouvelle FeedReaderService::readWithResult() est la méthode recommandée.
 * read() délègue désormais à readWithResult() pour éviter la duplication de code.
 *
 * ── READONLY PHP 8.1 ────────────────────────────────────────────────────────
 * Les propriétés readonly sont immuables après construction — approprié ici car
 * ce DTO représente un fait passé (le résultat d'une opération terminée).
 */
final readonly class FeedReadResult
{
    /**
     * @param bool                     $success      true = fetch + parse réussis (même si items = [])
     *                                               false = erreur (HTTP non-200, timeout, XML invalide)
     * @param list<ScrapedOpportunity> $items        Opportunités filtrées et nettoyées ([] si aucune ou si échec).
     *                                               L'annotation `list<ScrapedOpportunity>` (tableau indexé séquentiellement)
     *                                               permet à PHPStan niveau 6 de connaître le type des éléments et
     *                                               d'attraper toute incohérence dans les appelants (ex: passage d'un
     *                                               tableau d'entités Doctrine à la place de DTOs).
     * @param string|null              $errorMessage Message d'erreur si success = false, null sinon
     */
    public function __construct(
        public bool $success,
        /** @var list<ScrapedOpportunity> Tableau séquentiel de DTOs opportunité — jamais d'autre type d'objet */
        public array $items,
        public ?string $errorMessage = null,
    ) {
    }

    /**
     * Crée un résultat de succès avec les items trouvés.
     *
     * Méthode factory statique — alternative au constructeur pour plus de lisibilité :
     *   FeedReadResult::ok([...items...])   vs   new FeedReadResult(true, [...items...])
     *
     * @param ScrapedOpportunity[] $items Opportunités trouvées (peut être vide)
     */
    public static function ok(array $items): self
    {
        return new self(success: true, items: $items);
    }

    /**
     * Crée un résultat d'échec sans items.
     *
     * @param string $errorMessage Raison de l'échec (loggée et affichée dans l'admin)
     */
    public static function failure(string $errorMessage): self
    {
        return new self(success: false, items: [], errorMessage: $errorMessage);
    }
}
