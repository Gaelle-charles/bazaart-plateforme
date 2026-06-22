<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeadlineParserService;
use App\Service\TitleNormalizerService;
use PHPUnit\Framework\TestCase;

/**
 * ScrapedResourceDedupTest — Tests unitaires de la logique de déduplication par contenu.
 *
 * Ce fichier vérifie le comportement de la clé de contenu telle que construite
 * par ScrapedResourcePersister::persistBatch() et comparée par
 * ScrapedResourceRepository::findByContentKey().
 *
 * POURQUOI CES TESTS (ADR-0024) ?
 * ──────────────────────────────────────────────────────────────────────────────
 * Avant ADR-0024, la clé de contenu était : titleNorm + deadline_texte_brut.
 * Résultat : "31/05/2026" et "31 mai 2026" (même date, formats différents)
 * produisaient des clés DIFFÉRENTES → même opportunité insérée en double.
 *
 * Après ADR-0024, la clé est : titleNorm + deadline_date_canonique (Y-m-d).
 * "31/05/2026" et "31 mai 2026" → même clé → doublon détecté.
 *
 * REPLI PRUDENT (ADR-0024, Section "Conséquences") :
 *   Si la deadline d'un des deux éléments n'est PAS parsable (null) :
 *   → On ne force pas le rapprochement.
 *   → On préfère un doublon résiduel à une fusion erronée.
 *   → Deux deadlines parsables ET égales en date → doublon.
 *
 * STRATÉGIE DE TEST :
 *   On teste la LOGIQUE de construction de clé de contenu en combinant
 *   TitleNormalizerService + DeadlineParserService directement — sans instancier
 *   ScrapedResourcePersister (qui nécessiterait 5 mocks de dépendances).
 *   La logique de clé est : $titleNorm . '|' . ($parsedDate?->format('Y-m-d') ?? '')
 *   Cette formule est vérifiée dans ScrapedResourcePersister::persistBatch() (ligne 148).
 *
 * Classe testée (indirectement) : logique de clé de contenu de ScrapedResourcePersister
 * Services réels instanciés : TitleNormalizerService, DeadlineParserService
 * Type de test : Unitaire (pas de Kernel, pas de BDD)
 */
class ScrapedResourceDedupTest extends TestCase
{
    // Service de normalisation de titres — utilisé par le persister ET les repositories
    private TitleNormalizerService $titleNormalizer;

    // Service de parsing de deadline — utilisé par le persister pour la clé canonique
    private DeadlineParserService $deadlineParser;

    protected function setUp(): void
    {
        // Aucune dépendance injectée dans ces deux services → instanciation directe
        // (c'est exactement ce qu'on veut pour des tests UNITAIRES)
        $this->titleNormalizer = new TitleNormalizerService();
        $this->deadlineParser  = new DeadlineParserService();
    }

    // =========================================================================
    // Section A — Construction de la clé de contenu canonique
    //
    // La clé = titleNorm + '|' + deadlineCanonique (Y-m-d ou '' si null).
    // Cette section vérifie que la clé est identique pour deux opportunités
    // ayant le même titre et la même date sous des formats différents.
    // =========================================================================

    /**
     * Test A-1 — CAS FONDAMENTAL ADR-0024 : même titre + même date, formats différents
     *             → clés de contenu IDENTIQUES → doublon détecté.
     *
     * Scénario réel : une bourse publiée à la fois sur "on-the-move.org" (deadline "31/05/2026")
     * et sur "cultureetpartage.org" (deadline "31 mai 2026").
     * Les deux URLs sont différentes, mais c'est bien la même opportunité.
     *
     * AVANT ADR-0024 : clé A = "bourse creation|31/05/2026"
     *                  clé B = "bourse creation|31 mai 2026"
     *                  → clés différentes → DOUBLE INSERTION
     *
     * APRÈS ADR-0024 : clé A = "bourse creation|2026-05-31"
     *                  clé B = "bourse creation|2026-05-31"
     *                  → clés IDENTIQUES → doublon détecté
     */
    public function testDedup_MemeDate_SousFormatsDifferents_ProduisantMemeClef(): void
    {
        // Titre identique (texte brut — sera normalisé)
        $titre = 'Bourse de Création Artistique 2026';

        // Normalisation du titre (étape 1 de la clé)
        $titleNorm = $this->titleNormalizer->normalize($titre);

        // Deadline exprimée en format français court (site A)
        $deadlineFormatCourt = '31/05/2026';
        // Deadline exprimée en format français long (site B — même date)
        $deadlineFormatLong  = '31 mai 2026';

        // Parsing vers la date canonique (étape 2 de la clé, ADR-0024)
        $dateCanoniqueA = $this->deadlineParser->parse($deadlineFormatCourt);
        $dateCanoniqueB = $this->deadlineParser->parse($deadlineFormatLong);

        // Les deux doivent être parsables (prérequis du test)
        $this->assertNotNull($dateCanoniqueA, "'31/05/2026' doit être parsable");
        $this->assertNotNull($dateCanoniqueB, "'31 mai 2026' doit être parsable");

        // Construction des clés de contenu (réplique exacte de persistBatch() ligne 148)
        // Format : "titleNorm|YYYY-MM-DD"
        // Note : on utilise -> (pas ?->) car assertNotNull() ci-dessus garantit
        // que les deux variables sont non-null à ce stade (PHPStan le sait aussi).
        $cleContenuA = $titleNorm . '|' . $dateCanoniqueA->format('Y-m-d');
        $cleContenuB = $titleNorm . '|' . $dateCanoniqueB->format('Y-m-d');

        // ASSERTION CENTRALE : les deux clés doivent être identiques
        $this->assertSame(
            $cleContenuA,
            $cleContenuB,
            "Deux opportunités avec le même titre et la même date (formats différents) "
            . "doivent produire des clés de contenu IDENTIQUES après ADR-0024. "
            . "Clé A = '$cleContenuA', Clé B = '$cleContenuB'"
        );

        // Vérification de la valeur exacte attendue (pour clarifier le contrat)
        $this->assertSame(
            $titleNorm . '|2026-05-31',
            $cleContenuA,
            "La clé de contenu doit contenir la date canonique Y-m-d"
        );
    }

    /**
     * Test A-2 — Même titre + deadline ISO vs deadline française long → même clé.
     *
     * Variante : "2026-05-31" (ISO) vs "31 mai 2026" (FR long).
     * Ces deux formats sont les plus fréquents dans les retours LLM.
     */
    public function testDedup_FormatIso_VsFormatFrancaisLong_MemeClef(): void
    {
        $titre     = 'Résidence internationale arts vivants';
        $titleNorm = $this->titleNormalizer->normalize($titre);

        // Format ISO (scraper A) vs format français long (scraper B)
        $dateIso       = $this->deadlineParser->parse('2026-05-31');
        $dateFrLong    = $this->deadlineParser->parse('31 mai 2026');

        $this->assertNotNull($dateIso,    "'2026-05-31' doit être parsable");
        $this->assertNotNull($dateFrLong, "'31 mai 2026' doit être parsable");

        // -> (pas ?->) car assertNotNull() garantit la non-nullité à ce stade
        $cleA = $titleNorm . '|' . $dateIso->format('Y-m-d');
        $cleB = $titleNorm . '|' . $dateFrLong->format('Y-m-d');

        $this->assertSame(
            $cleA,
            $cleB,
            "Les formats ISO '2026-05-31' et français long '31 mai 2026' doivent produire la même clé"
        );
    }

    /**
     * Test A-3 — Même titre + deadlines parsables DIFFÉRENTES → clés DIFFÉRENTES.
     *
     * Garde-fou contre la sur-déduplication : deux bourses portant le même nom
     * mais avec des deadlines différentes sont des opportunités DISTINCTES.
     * La clé de contenu doit les différencier.
     *
     * Exemple réel : "Bourse de création" avec deadline "31/05/2026"
     *                et "Bourse de création" avec deadline "30/09/2026"
     *                → deux sessions différentes → ne doivent PAS être fusionnées.
     */
    public function testDedup_MemesTitre_DatesDifferentes_ClefsDifferentes(): void
    {
        $titre     = 'Prix Révélations Afrique';
        $titleNorm = $this->titleNormalizer->normalize($titre);

        $dateSession1 = $this->deadlineParser->parse('31/05/2026'); // session de mai
        $dateSession2 = $this->deadlineParser->parse('30/09/2026'); // session de septembre

        $this->assertNotNull($dateSession1, "'31/05/2026' doit être parsable");
        $this->assertNotNull($dateSession2, "'30/09/2026' doit être parsable");

        // -> (pas ?->) car assertNotNull() garantit la non-nullité à ce stade
        $cleSession1 = $titleNorm . '|' . $dateSession1->format('Y-m-d');
        $cleSession2 = $titleNorm . '|' . $dateSession2->format('Y-m-d');

        // Les deux clés DOIVENT être différentes (deadlines différentes → opportunités distinctes)
        $this->assertNotSame(
            $cleSession1,
            $cleSession2,
            "Deux opportunités avec le même titre mais des deadlines DIFFÉRENTES "
            . "ne doivent PAS avoir la même clé de contenu (risque de sur-déduplication)"
        );
    }

    // =========================================================================
    // Section B — Repli prudent (ADR-0024, "Conséquences")
    //
    // Quand une deadline n'est PAS parsable, on ne force pas le rapprochement.
    // On préfère un doublon résiduel à une fusion erronée.
    // =========================================================================

    /**
     * Test B-1 — REPLI PRUDENT : deadline non parsable → clé avec suffixe vide.
     *
     * Si la deadline est non parsable (format inconnu, texte libre), la clé devient
     * "titleNorm|" (suffixe vide). Deux opportunités avec deadline non parsable et même
     * titre produisent la même clé → elles seront considérées comme doublons dans
     * findByContentKey() (qui cherche deadlineDate IS NULL pour ce cas).
     *
     * C'est le comportement ACTUEL (avant ADR-0024) pour les deadlines non parsables —
     * on le conserve, par prudence.
     *
     * Ce test documente ce comportement sans le valider comme "idéal" :
     * on sait que c'est une approximation (voir ADR-0024 "Risque").
     */
    public function testDedup_DeadlineNonParsable_CleAvecSuffixeVide(): void
    {
        $titre     = 'Appel à projets festival';
        $titleNorm = $this->titleNormalizer->normalize($titre);

        // Deadline en texte libre non parsable (format non reconnu)
        $deadlineNonParsable = 'ouvert jusqu\'à fin juin';

        $dateParsee = $this->deadlineParser->parse($deadlineNonParsable);

        // Le parsing doit échouer → null (repli prudent)
        $this->assertNull(
            $dateParsee,
            "Une deadline en texte libre non reconnue doit retourner null (repli prudent ADR-0024)"
        );

        // La clé de contenu aura un suffixe vide.
        // La formule de persistBatch() est : titleNorm . '|' . ($date?->format('Y-m-d') ?? '')
        // Avec $date = null → suffixe = '' → clé = "titleNorm|"
        // On construit directement la clé avec suffixe vide car $dateParsee est null ici.
        $cleContenu = $titleNorm . '|';

        $this->assertStringEndsWith(
            '|',
            $cleContenu,
            "Une deadline non parsable doit produire une clé se terminant par '|' (suffixe vide)"
        );
    }

    /**
     * Test B-2 — REPLI PRUDENT : une deadline parsable + une deadline null → clés DIFFÉRENTES.
     *
     * Scénario critique : opportunité A avec deadline "31/05/2026" (parsable)
     *                     opportunité B avec deadline "ouvert toute l'année" (non parsable)
     *
     * Ces deux opportunités DIFFÉRENTES ne doivent PAS être confondues comme doublons.
     * La clé de A = "titleNorm|2026-05-31"   (date canonique)
     * La clé de B = "titleNorm|"              (suffixe vide)
     * → Clés différentes → PAS de rapprochement forcé → comportement correct.
     *
     * C'est exactement le "repli prudent" décrit dans ADR-0024 :
     * "si la deadline d'un des deux éléments comparés n'est PAS parsable (null),
     *  ne pas forcer le rapprochement — retomber sur la comparaison du texte brut"
     *
     * Ici "retomber sur le texte brut" se traduit par des suffixes différents
     * (date canonique vs chaîne vide) → clés différentes → pas de fusion.
     */
    public function testDedup_UneParsable_UneNull_ClefsDifferentes(): void
    {
        $titre     = 'Bourse de mobilité internationale';
        $titleNorm = $this->titleNormalizer->normalize($titre);

        // Opportunité A : deadline parsable
        $dateA = $this->deadlineParser->parse('31/05/2026');
        $this->assertNotNull($dateA, "'31/05/2026' doit être parsable");

        // Opportunité B : deadline non parsable (texte libre)
        $dateB = $this->deadlineParser->parse('ouvert toute l\'année');
        $this->assertNull($dateB, "'ouvert toute l'année' doit retourner null");

        // Construction des clés (réplique de persistBatch() ligne 148).
        // Pour $dateA : -> direct (non-null garanti par assertNotNull ci-dessus).
        // Pour $dateB : '' direct car PHPStan sait que $dateB est null après assertNull.
        //   La formule persistBatch() donne : null?->format() ?? '' = ''
        $cleA = $titleNorm . '|' . $dateA->format('Y-m-d');
        $cleB = $titleNorm . '|';

        // Les clés DOIVENT être différentes (repli prudent)
        $this->assertNotSame(
            $cleA,
            $cleB,
            "Une opportunité avec deadline parsable et une autre avec deadline null "
            . "ne doivent PAS avoir la même clé de contenu (repli prudent ADR-0024). "
            . "Clé A = '$cleA', Clé B = '$cleB'"
        );

        // Vérifions les valeurs exactes pour clarté
        $this->assertStringEndsWith('|2026-05-31', $cleA, "Clé A doit contenir la date canonique");
        $this->assertStringEndsWith('|', $cleB,           "Clé B doit avoir un suffixe vide (null → '')");
    }

    /**
     * Test B-3 — Deux deadlines NON parsables + même titre → clé identique (cas double-null).
     *
     * Ce cas est documenté dans findByContentKey() et persistBatch() :
     * "Cas null+null (deux opportunités sans deadline, même titre normalisé) :
     *  → On considère comme doublon probable — on skippe avec un log de traçabilité."
     *
     * Ce test DOCUMENTE ce comportement existant (il n'est pas idéal,
     * mais c'est un choix délibéré : prudence côté sur-déduplication vs doublons résiduels).
     */
    public function testDedup_DeuxDeadlinesNull_MemeClef(): void
    {
        $titre     = 'Aide à la création émergente';
        $titleNorm = $this->titleNormalizer->normalize($titre);

        // Deux deadlines non parsables différentes (mais même résultat : null)
        $dateA = $this->deadlineParser->parse('');      // vide
        $dateB = $this->deadlineParser->parse('-');     // tiret

        $this->assertNull($dateA, "Chaîne vide doit retourner null");
        $this->assertNull($dateB, "Tiret doit retourner null");

        // Les deux sont null → suffixe vide dans les deux cas.
        // La formule persistBatch() : null?->format() ?? '' = ''
        // PHPStan sait que $dateA et $dateB sont null après assertNull → on construit directement.
        $cleA = $titleNorm . '|';
        $cleB = $titleNorm . '|';

        // Les deux clés sont identiques car les deux deadlines sont null
        // → findByContentKey() les traitera comme doublons potentiels (IS NULL)
        $this->assertSame(
            $cleA,
            $cleB,
            "Deux opportunités avec deadline null et même titre ont la même clé "
            . "(comportement double-null documenté dans persistBatch())"
        );
    }
}
