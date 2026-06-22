<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeadlineParserService;
use PHPUnit\Framework\TestCase;

/**
 * DeadlineParserServiceTest — Tests unitaires de DeadlineParserService.
 *
 * Ce fichier couvre DEUX méthodes publiques de DeadlineParserService :
 *
 * ── parse() (ADR-0024) ───────────────────────────────────────────────────────
 *   Convertit une chaîne deadline en DateTimeImmutable.
 *   Tests des 3 formats reconnus + cas non parsables (vide, tiret, charabia).
 *   But : être utilisée comme source de vérité pour la clé de contenu de dédup.
 *
 * ── extractFromText() (comportement anti-fallback) ───────────────────────────
 *   Scanne un texte libre pour y trouver une deadline avec mot-cue obligatoire.
 *   Tests du cas avec cue (date extraite) et sans cue (null — pas de fallback).
 *   Le guard anti-fallback protège contre les archivages prématurés.
 *
 * Classe testée : App\Service\DeadlineParserService
 * Type de test : Unitaire (pas de Kernel, pas de BDD — DeadlineParserService
 *               n'a aucune dépendance injectée → instanciation directe)
 */
class DeadlineParserServiceTest extends TestCase
{
    // Service instancié dans setUp() — partagé entre tous les tests de cette classe
    private DeadlineParserService $parser;

    protected function setUp(): void
    {
        // Aucune dépendance injectée → instanciation directe, pas de mock nécessaire.
        $this->parser = new DeadlineParserService();
    }

    // =========================================================================
    // SECTION 1 — Tests de parse() (ADR-0024)
    //
    // parse() attend une chaîne qui EST entièrement une date.
    // Elle est utilisée par ScrapedResourcePersister pour construire la clé de
    // contenu de déduplication (deadline canonique en Y-m-d au lieu du texte brut).
    // =========================================================================

    /**
     * Test parse-1 — Format ISO 8601 court "YYYY-MM-DD" → date correcte.
     *
     * C'est le format le plus propre, produit par les scrapers CSS et le LLM
     * quand ils extraient une deadline structurée.
     * Exemple réel : "2026-05-31"
     */
    public function testParse_FormatIso_RetourneLaDate(): void
    {
        $result = $this->parser->parse('2026-05-31');

        // On s'assure que la date a été reconnue et parsée
        $this->assertNotNull(
            $result,
            "parse() doit reconnaître le format ISO 8601 court YYYY-MM-DD"
        );
        $this->assertSame(
            '2026-05-31',
            $result->format('Y-m-d'),
            "La date parsée doit être 2026-05-31"
        );
    }

    /**
     * Test parse-2 — Format français court "JJ/MM/AAAA" (avec zéros de padding) → date correcte.
     *
     * Format très fréquent dans les bases existantes (sites francophones).
     * Exemple réel : "31/05/2026"
     */
    public function testParse_FormatFrancaisCourtAvecZeros_RetourneLaDate(): void
    {
        $result = $this->parser->parse('31/05/2026');

        $this->assertNotNull(
            $result,
            "parse() doit reconnaître le format français court JJ/MM/AAAA avec zéros de padding"
        );
        $this->assertSame(
            '2026-05-31',
            $result->format('Y-m-d'),
            "31/05/2026 doit être parsé en 2026-05-31"
        );
    }

    /**
     * Test parse-3 — Format français court "J/M/AAAA" (sans zéros de padding) → date correcte.
     *
     * Certains sites omettent le zéro de padding pour le jour et le mois.
     * Exemple réel : "1/5/2026"
     * Ce test vérifie que le pattern `\d{1,2}` de la regex gère bien ce cas.
     */
    public function testParse_FormatFrancaisCourtSansZeros_RetourneLaDate(): void
    {
        $result = $this->parser->parse('1/5/2026');

        $this->assertNotNull(
            $result,
            "parse() doit reconnaître le format français court J/M/AAAA sans zéros de padding"
        );
        $this->assertSame(
            '2026-05-01',
            $result->format('Y-m-d'),
            "1/5/2026 doit être parsé en 2026-05-01"
        );
    }

    /**
     * Test parse-4 — Format français long "JJ mois AAAA" → date correcte.
     *
     * Format souvent produit par les LLM et présent dans les textes d'annonces.
     * C'est ce format qui créait des DOUBLONS avant ADR-0024 : "31 mai 2026" et
     * "31/05/2026" étaient considérés comme deux deadlines différentes.
     * Avec la date canonique, les deux produisent "2026-05-31" → doublon détecté.
     *
     * Exemple réel : "31 mai 2026"
     */
    public function testParse_FormatFrancaisLong_RetourneLaDate(): void
    {
        $result = $this->parser->parse('31 mai 2026');

        $this->assertNotNull(
            $result,
            "parse() doit reconnaître le format français long 'JJ mois AAAA'"
        );
        $this->assertSame(
            '2026-05-31',
            $result->format('Y-m-d'),
            "'31 mai 2026' doit être parsé en 2026-05-31"
        );
    }

    /**
     * Test parse-5 — Format français long avec mois accentué → date correcte.
     *
     * Vérifie que les mois avec accents (août, décembre, février) sont bien
     * reconnus. La comparaison est insensible à la casse (mb_strtolower).
     */
    public function testParse_FormatFrancaisLongMoisAccentue_RetourneLaDate(): void
    {
        // "15 décembre 2026" — mois avec accent grave
        $result = $this->parser->parse('15 décembre 2026');

        $this->assertNotNull($result, "parse() doit reconnaître les mois accentués comme 'décembre'");
        $this->assertSame('2026-12-15', $result->format('Y-m-d'));

        // "10 août 2026" — mois avec accent circonflexe
        $resultAout = $this->parser->parse('10 août 2026');
        $this->assertNotNull($resultAout, "parse() doit reconnaître 'août' (accent circonflexe)");
        $this->assertSame('2026-08-10', $resultAout->format('Y-m-d'));
    }

    /**
     * Test parse-6 — Chaîne vide → null (pas de deadline renseignée).
     *
     * Cas trivial : le champ deadline n'a pas été renseigné.
     * Contrat : parse() ne lève jamais d'exception, retourne null.
     */
    public function testParse_ChaineVide_RetourneNull(): void
    {
        $this->assertNull(
            $this->parser->parse(''),
            "parse() doit retourner null pour une chaîne vide"
        );
    }

    /**
     * Test parse-7 — Tiret simple "-" → null.
     *
     * Convention fréquente dans les bases pour signifier "pas de deadline connue".
     * Même comportement que chaîne vide.
     */
    public function testParse_TiretSimple_RetourneNull(): void
    {
        $this->assertNull(
            $this->parser->parse('-'),
            "parse() doit retourner null pour le tiret simple '-'"
        );
    }

    /**
     * Test parse-8 — Em-dash "—" → null.
     *
     * Les LLM utilisent parfois le cadratin (em-dash U+2014) pour "pas de deadline".
     * parse() doit le reconnaître comme valeur non informative et retourner null.
     */
    public function testParse_EmDash_RetourneNull(): void
    {
        $this->assertNull(
            $this->parser->parse('—'),
            "parse() doit retourner null pour l'em-dash '—'"
        );
    }

    /**
     * Test parse-9 — Charabia non reconnu → null (pas d'exception).
     *
     * Garantit que le contrat "jamais d'exception" est respecté.
     * Un format totalement inconnu doit retourner null silencieusement.
     */
    public function testParse_Charabia_RetourneNull(): void
    {
        $this->assertNull(
            $this->parser->parse('ouvert toute l\'année'),
            "parse() doit retourner null pour un format non reconnu (pas d'exception)"
        );

        $this->assertNull(
            $this->parser->parse('fin juin 2026'),
            "parse() doit retourner null pour 'fin juin 2026' (pas un token date valide)"
        );

        $this->assertNull(
            $this->parser->parse('TBD'),
            "parse() doit retourner null pour 'TBD'"
        );
    }

    /**
     * Test parse-10 — Chaîne avec espaces parasites → null OU date selon contenu.
     *
     * parse() fait un trim() en entrée : "  2026-05-31  " doit être parsé.
     * Cela couvre le cas où un scraper insère des espaces parasites.
     */
    public function testParse_EspacesParasites_SontIgnores(): void
    {
        $result = $this->parser->parse('  2026-05-31  ');

        $this->assertNotNull(
            $result,
            "parse() doit ignorer les espaces parasites (trim en début de méthode)"
        );
        $this->assertSame('2026-05-31', $result->format('Y-m-d'));
    }

    /**
     * TEST FONDAMENTAL ADR-0024 — Deux formats différents pour la même date → même résultat.
     *
     * C'est le cas central de l'ADR-0024 : "31/05/2026" et "31 mai 2026" représentent
     * la même date mais sous des formats différents.
     * AVANT ADR-0024 : clés de contenu différentes → doublon non détecté.
     * APRÈS ADR-0024  : les deux sont parsés en "2026-05-31" → même clé → doublon détecté.
     *
     * Ce test vérifie que parse() produit le même résultat Y-m-d pour les deux formats.
     * La dédup effective est testée dans ScrapedResourcePersisterDedupTest.
     */
    public function testParse_MemeDate_SousFormatsDifferents_ProduisantMemeResultat(): void
    {
        // "31/05/2026" (format français court)
        $dateFormatCourt = $this->parser->parse('31/05/2026');
        // "31 mai 2026" (format français long)
        $dateFormatLong  = $this->parser->parse('31 mai 2026');

        // Les deux doivent être non nulles
        $this->assertNotNull($dateFormatCourt, "'31/05/2026' doit être parsable");
        $this->assertNotNull($dateFormatLong,  "'31 mai 2026' doit être parsable");

        // Et produire la même représentation canonique Y-m-d
        $this->assertSame(
            $dateFormatCourt->format('Y-m-d'),
            $dateFormatLong->format('Y-m-d'),
            "Les formats '31/05/2026' et '31 mai 2026' doivent produire la même date canonique "
            . "(base de la déduplication cross-sources décrite dans ADR-0024)"
        );

        // La date canonique attendue est 2026-05-31
        $this->assertSame(
            '2026-05-31',
            $dateFormatCourt->format('Y-m-d'),
            "La date canonique doit être 2026-05-31"
        );
    }

    // =========================================================================
    // SECTION 2 — Tests de extractFromText() (comportement anti-fallback)
    //
    // extractFromText() scanne du texte libre pour y trouver une deadline.
    // Elle exige un mot-cue ("jusqu'au", "date limite"...) avant la date.
    // Sans cue, retourne null pour éviter les archivages prématurés.
    // =========================================================================

    /**
     * Test extract-1 — Texte AVEC mot-cue → la date doit être extraite.
     *
     * Cas représentatif : "Candidatures jusqu'au 31 mai 2026."
     * Le cue "jusqu'au" est dans DEADLINE_CUES, la date "31 mai 2026" est
     * immédiatement après → doit retourner 2026-05-31 à minuit.
     */
    public function testExtractFromText_AvecCue_RetourneLaDate(): void
    {
        $text = "Résidence artistique 2026. Candidatures jusqu'au 31 mai 2026. Envoyez votre dossier complet.";

        $result = $this->parser->extractFromText($text);

        $this->assertNotNull(
            $result,
            "extractFromText() doit retourner une date quand un cue de deadline est présent"
        );
        $this->assertSame(
            '2026-05-31',
            $result->format('Y-m-d'),
            "La date extraite doit être 2026-05-31"
        );
    }

    /**
     * Test extract-2 — Texte SANS mot-cue → doit retourner null (PAS de fallback).
     *
     * Cas problématique historique : "Lauréats de mai 2026" ou
     * "Découvrez le palmarès de l'Aide à la création - Mai 2026".
     * Ces textes contiennent des dates mais PAS de mot-cue de deadline.
     * L'ancien code retournait une date (faux positif) → archivage prématuré.
     * Le nouveau code doit retourner null → resource non archivable auto.
     */
    public function testExtractFromText_SansCue_RetourneNull(): void
    {
        $text = "Découvrez le palmarès de l'Aide à la création - Mai 2026. Retrouvez les lauréats sélectionnés.";

        $result = $this->parser->extractFromText($text);

        $this->assertNull(
            $result,
            "extractFromText() DOIT retourner null quand aucun mot-cue de deadline n'est présent. "
            . "Un faux deadline_date provoque un archivage prématuré qui masque la ressource à la modération."
        );
    }

    /**
     * Test extract-3 — Texte vide → null immédiat (cas trivial).
     */
    public function testExtractFromText_TexteVide_RetourneNull(): void
    {
        $this->assertNull($this->parser->extractFromText(''));
        $this->assertNull($this->parser->extractFromText('   '));
    }

    /**
     * Test extract-4 — Variante ISO avec cue "date limite" → doit fonctionner.
     */
    public function testExtractFromText_AvecCueDateLimiteEtIso_RetourneLaDate(): void
    {
        $text = "Appel à projets ouvert. Date limite de candidature : 2026-09-30. Bonne chance.";

        $result = $this->parser->extractFromText($text);

        $this->assertNotNull($result);
        $this->assertSame('2026-09-30', $result->format('Y-m-d'));
    }

    /**
     * Test extract-5 — Date mentionnée comme date d'événement passé → null (pas un cue).
     *
     * "Prix remis le 15 juin 2026" n'est pas une deadline de candidature.
     * Sans cue, ces dates doivent être ignorées.
     */
    public function testExtractFromText_DateEvenementSansCue_RetourneNull(): void
    {
        $text = "Prix remis le 15 juin 2026 lors de la cérémonie annuelle à Paris.";

        $result = $this->parser->extractFromText($text);

        $this->assertNull(
            $result,
            "Une date d'événement sans cue de deadline ne doit PAS être retenue comme deadline_date"
        );
    }
}
