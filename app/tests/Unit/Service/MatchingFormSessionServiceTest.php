<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\ArtistLookingFor;
use App\Enum\LegalStatus;
use App\Service\MatchingFormSessionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * MatchingFormSessionServiceTest — Tests unitaires du service de session du formulaire matching.
 *
 * STRATÉGIE :
 *   Tests UNITAIRES (sans BDD, sans container Symfony).
 *   On mocke SessionInterface pour contrôler les données lues/écrites.
 *
 * CE QU'ON TESTE :
 *   - saveStepToSession() pour chaque étape (1, 2, 3)
 *   - Validation : refus si étape 1 vide, étape 2 vide
 *   - Sécurité : les valeurs enum invalides sont rejetées
 *   - getSessionData() retourne une structure normalisée
 *   - hasSessionData() retourne true/false selon la présence en session
 *   - clearSession() appelle session->remove() avec la bonne clé
 *   - saveAllStepsToSession() sauvegarde les 3 étapes
 */
final class MatchingFormSessionServiceTest extends TestCase
{
    private MatchingFormSessionService $service;

    /**
     * Mock de la session Symfony.
     * PHPUnit génère un mock capable de vérifier les appels set()/get()/remove().
     */
    private MockObject&SessionInterface $session;

    protected function setUp(): void
    {
        $this->service = new MatchingFormSessionService();
        $this->session = $this->createMock(SessionInterface::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : getSessionData()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Quand la session est vide, getSessionData() retourne la structure vide normalisée.
     */
    public function testGetSessionDataReturnsEmptyStructureWhenNoDataInSession(): void
    {
        // La session ne contient rien pour la clé bazaart_matching_form
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $data = $this->service->getSessionData($this->session);

        $this->assertSame([], $data['discipline_ids']);
        $this->assertSame([], $data['looking_for']);
        $this->assertNull($data['looking_for_other']);
        $this->assertNull($data['legal_status']);
    }

    /**
     * getSessionData() normalise correctement les types (int, string, null).
     */
    public function testGetSessionDataNormalizesTypes(): void
    {
        // Simulation de données en session (potentiellement mal typées)
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([
                'discipline_ids'    => ['3', '7', '12'],  // strings au lieu d'int
                'looking_for'       => ['formations', 'residences'],
                'looking_for_other' => 'Résidences',
                'legal_status'      => 'artiste_auteur',
            ]);

        $data = $this->service->getSessionData($this->session);

        // Les IDs doivent être des entiers
        $this->assertSame([3, 7, 12], $data['discipline_ids']);
        $this->assertSame(['formations', 'residences'], $data['looking_for']);
        $this->assertSame('Résidences', $data['looking_for_other']);
        $this->assertSame('artiste_auteur', $data['legal_status']);
    }

    /**
     * getSessionData() filtre les IDs de disciplines <= 0 (invalides).
     */
    public function testGetSessionDataFiltersNonPositiveIds(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([
                'discipline_ids' => [0, -1, 5, 'abc', 3],
            ]);

        $data = $this->service->getSessionData($this->session);

        // Seuls les IDs > 0 sont conservés
        $this->assertSame([5, 3], $data['discipline_ids']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : hasSessionData()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * hasSessionData() retourne false quand la session est vide.
     */
    public function testHasSessionDataReturnsFalseWhenEmpty(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY)
            ->willReturn(null);

        $this->assertFalse($this->service->hasSessionData($this->session));
    }

    /**
     * hasSessionData() retourne true quand des données existent en session.
     */
    public function testHasSessionDataReturnsTrueWhenDataPresent(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY)
            ->willReturn(['discipline_ids' => [3, 7]]);

        $this->assertTrue($this->service->hasSessionData($this->session));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : saveStepToSession() — ÉTAPE 1
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 1 : retourne une erreur si aucune discipline n'est sélectionnée.
     */
    public function testSaveStep1ReturnsErrorWhenNoDisciplineSelected(): void
    {
        // Session vide en lecture
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    1,
            data:    ['disciplines' => []],
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('discipline', strtolower($error));
    }

    /**
     * Étape 1 : sauvegarde les disciplines valides et retourne null (succès).
     */
    public function testSaveStep1SavesDisciplinesOnSuccess(): void
    {
        // Session initialement vide
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        // On attend un appel à set() avec les disciplines
        $this->session->expects($this->once())
            ->method('set')
            ->with(
                MatchingFormSessionService::SESSION_KEY,
                $this->callback(function (array $data): bool {
                    return $data['discipline_ids'] === [3, 7, 12];
                })
            );

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    1,
            data:    ['disciplines' => ['3', '7', '12']],
        );

        $this->assertNull($error, 'Aucune erreur attendue avec des disciplines valides');
    }

    /**
     * Étape 1 : ignore les IDs de disciplines invalides (0, négatifs).
     * Si le résultat filtré est vide → erreur.
     */
    public function testSaveStep1RejectsInvalidDisciplineIds(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        // Uniquement des IDs invalides
        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    1,
            data:    ['disciplines' => ['0', '-1', 'abc']],
        );

        $this->assertNotNull($error, 'Une erreur doit être retournée si aucun ID valide');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : saveStepToSession() — ÉTAPE 2
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 2 : retourne une erreur si aucune option lookingFor n'est cochée.
     */
    public function testSaveStep2ReturnsErrorWhenNoLookingForSelected(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    2,
            data:    ['looking_for' => []],
        );

        $this->assertNotNull($error);
    }

    /**
     * Étape 2 : valeurs enum invalides sont rejetées (protection anti-injection).
     * Si seules des valeurs invalides sont soumises → erreur.
     */
    public function testSaveStep2RejectsInvalidEnumValues(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    2,
            data:    ['looking_for' => ['valeur_inconnue', 'hack_injection']],
        );

        // Les valeurs invalides sont filtrées → tableau vide → erreur
        $this->assertNotNull($error, 'Des valeurs enum invalides doivent mener à une erreur');
    }

    /**
     * Étape 2 : "autre" coché sans texte libre → erreur.
     */
    public function testSaveStep2ReturnsErrorWhenAutreCheckedWithoutText(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    2,
            data:    [
                'looking_for'       => [ArtistLookingFor::AUTRE->value],
                'looking_for_other' => '',  // champ vide alors que "autre" est coché
            ],
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('autre', strtolower($error));
    }

    /**
     * Étape 2 : sauvegarde valide avec enum valide.
     */
    public function testSaveStep2SavesValidLookingForValues(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $this->session->expects($this->once())
            ->method('set')
            ->with(
                MatchingFormSessionService::SESSION_KEY,
                $this->callback(function (array $data): bool {
                    return in_array(ArtistLookingFor::FORMATIONS->value, $data['looking_for'], true);
                })
            );

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    2,
            data:    ['looking_for' => [ArtistLookingFor::FORMATIONS->value]],
        );

        $this->assertNull($error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : saveStepToSession() — ÉTAPE 3
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 3 : toujours succès (champ optionnel), même avec une valeur vide.
     */
    public function testSaveStep3AlwaysSucceedsWithEmptyLegalStatus(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $this->session->expects($this->once())
            ->method('set')
            ->with(
                MatchingFormSessionService::SESSION_KEY,
                $this->callback(function (array $data): bool {
                    return $data['legal_status'] === null;
                })
            );

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    3,
            data:    ['legal_status' => ''],  // vide → null
        );

        $this->assertNull($error);
    }

    /**
     * Étape 3 : valeur LegalStatus invalide → null (pas d'erreur, juste ignorée).
     */
    public function testSaveStep3IgnoresInvalidLegalStatusValue(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $this->session->expects($this->once())
            ->method('set')
            ->with(
                MatchingFormSessionService::SESSION_KEY,
                $this->callback(function (array $data): bool {
                    // La valeur invalide doit être ignorée → null en session
                    return $data['legal_status'] === null;
                })
            );

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    3,
            data:    ['legal_status' => 'valeur_injectee'],
        );

        $this->assertNull($error);
    }

    /**
     * Étape 3 : valeur LegalStatus valide → sauvegardée correctement.
     */
    public function testSaveStep3SavesValidLegalStatus(): void
    {
        $validStatus = LegalStatus::ARTISTE_AUTEUR;

        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $this->session->expects($this->once())
            ->method('set')
            ->with(
                MatchingFormSessionService::SESSION_KEY,
                $this->callback(function (array $data) use ($validStatus): bool {
                    return $data['legal_status'] === $validStatus->value;
                })
            );

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    3,
            data:    ['legal_status' => $validStatus->value],
        );

        $this->assertNull($error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : saveStepToSession() — cas spéciaux
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape inconnue (ex : 99) → retourne une erreur.
     */
    public function testSaveStepReturnsErrorForUnknownStep(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $error = $this->service->saveStepToSession(
            session: $this->session,
            step:    99,
            data:    [],
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('invalide', strtolower($error));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : clearSession()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * clearSession() appelle session->remove() avec la bonne clé.
     */
    public function testClearSessionRemovesSessionKey(): void
    {
        $this->session->expects($this->once())
            ->method('remove')
            ->with(MatchingFormSessionService::SESSION_KEY);

        $this->service->clearSession($this->session);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS : getSavedDisciplineIds()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getSavedDisciplineIds() retourne le tableau d'IDs entiers depuis la session.
     */
    public function testGetSavedDisciplineIdsReturnsIntArray(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn(['discipline_ids' => [5, 10]]);

        $ids = $this->service->getSavedDisciplineIds($this->session);

        $this->assertSame([5, 10], $ids);
    }

    /**
     * getSavedDisciplineIds() retourne un tableau vide si pas de disciplines.
     */
    public function testGetSavedDisciplineIdsReturnsEmptyArrayWhenNoData(): void
    {
        $this->session->method('get')
            ->with(MatchingFormSessionService::SESSION_KEY, [])
            ->willReturn([]);

        $ids = $this->service->getSavedDisciplineIds($this->session);

        $this->assertSame([], $ids);
    }
}
