<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeadlineParserService;
use PHPUnit\Framework\TestCase;

/**
 * DeadlineParserServiceTest — Tests unitaires de DeadlineParserService.
 *
 * Ces tests vérifient deux comportements fondamentaux de extractFromText() :
 *
 *   1. Avec mot-cue (ex: "jusqu'au") → la date est bien extraite.
 *   2. Sans mot-cue → null est retourné (PAS de fallback).
 *
 * Pourquoi ce test est important :
 *   Le commit "fix(scraping): extractFromText sans fallback" a supprimé l'étape 3
 *   qui retenait la première date du texte même sans mot-cue de deadline.
 *   Ces tests servent de garde-fous : si quelqu'un réintroduit un fallback,
 *   le cas "sans cue → null" échouera et alertera immédiatement.
 *
 * Classe testée : App\Service\DeadlineParserService::extractFromText()
 * Type de test : Unitaire (pas besoin du container Symfony — aucune dépendance)
 */
class DeadlineParserServiceTest extends TestCase
{
    // Service instancié dans setUp() — partagé entre tous les tests de cette classe
    private DeadlineParserService $parser;

    protected function setUp(): void
    {
        // DeadlineParserService n'a aucune dépendance injectée → instanciation directe
        // C'est exactement ce qu'on veut pour un test UNITAIRE : pas de Kernel, pas de BDD.
        $this->parser = new DeadlineParserService();
    }

    /**
     * Test 1 — Texte AVEC mot-cue → la date doit être extraite.
     *
     * Cas représentatif : "Candidatures jusqu'au 31 mai 2026."
     * Le cue "jusqu'au" est dans DEADLINE_CUES, la date "31 mai 2026" est
     * immédiatement après → doit retourner 2026-05-31 à minuit.
     */
    public function testExtractFromText_AvecCue_RetourneLaDate(): void
    {
        // Texte typique d'un appel à candidatures avec un vrai cue de deadline
        $text = "Résidence artistique 2026. Candidatures jusqu'au 31 mai 2026. Envoyez votre dossier complet.";

        $result = $this->parser->extractFromText($text);

        // On s'assure qu'une date a été trouvée (pas null)
        $this->assertNotNull(
            $result,
            "extractFromText() doit retourner une date quand un cue de deadline est présent"
        );

        // On vérifie que c'est bien le 31 mai 2026 qui a été parsé
        $this->assertSame(
            '2026-05-31',
            $result->format('Y-m-d'),
            "La date extraite doit être 2026-05-31"
        );
    }

    /**
     * Test 2 — Texte SANS mot-cue → doit retourner null (PAS de fallback).
     *
     * Cas problématique historique : "Lauréats de mai 2026" ou
     * "Découvrez le palmarès de l'Aide à la création - Mai 2026".
     * Ces textes contiennent des dates mais PAS de mot-cue de deadline.
     * L'ancien code retournait une date (faux positif) → archivage prématuré.
     * Le nouveau code doit retourner null → resource non archivable auto.
     */
    public function testExtractFromText_SansCue_RetourneNull(): void
    {
        // Texte du faux positif réel observé sur artcena.fr (avant le correctif)
        $text = "Découvrez le palmarès de l'Aide à la création - Mai 2026. Retrouvez les lauréats sélectionnés.";

        $result = $this->parser->extractFromText($text);

        // COMPORTEMENT ATTENDU APRÈS LE CORRECTIF : null (pas de fallback)
        // Si ce test échoue, quelqu'un a réintroduit le fallback — c'est interdit.
        $this->assertNull(
            $result,
            "extractFromText() DOIT retourner null quand aucun mot-cue de deadline n'est présent. "
            . "Un faux deadline_date provoque un archivage prématuré qui masque la ressource à la modération."
        );
    }

    /**
     * Test 3 — Texte vide → null immédiat (cas trivial).
     *
     * Vérifie le guard en début de méthode.
     */
    public function testExtractFromText_TexteVide_RetourneNull(): void
    {
        $this->assertNull($this->parser->extractFromText(''));
        $this->assertNull($this->parser->extractFromText('   ')); // espaces uniquement
    }

    /**
     * Test 4 — Variante ISO avec cue "date limite" → doit fonctionner.
     *
     * Vérifie qu'un cue en deux mots et une date ISO sont bien détectés.
     */
    public function testExtractFromText_AvecCueDateLimiteEtIso_RetourneLaDate(): void
    {
        $text = "Appel à projets ouvert. Date limite de candidature : 2026-09-30. Bonne chance.";

        $result = $this->parser->extractFromText($text);

        $this->assertNotNull($result);
        $this->assertSame('2026-09-30', $result->format('Y-m-d'));
    }

    /**
     * Test 5 — Date mentionnée comme date d'événement passé → null (pas un cue).
     *
     * "Lauréats 2026" ou "Prix remis le 15 juin 2026" ne sont pas des deadlines.
     * Sans cue, ces dates doivent être ignorées.
     */
    public function testExtractFromText_DateEvenementSansCue_RetourneNull(): void
    {
        // Texte simulant une annonce de résultats (pas un appel à candidatures)
        $text = "Prix remis le 15 juin 2026 lors de la cérémonie annuelle à Paris.";

        $result = $this->parser->extractFromText($text);

        $this->assertNull(
            $result,
            "Une date d'événement sans cue de deadline ne doit PAS être retenue comme deadline_date"
        );
    }
}
