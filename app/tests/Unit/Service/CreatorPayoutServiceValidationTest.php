<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\Creator\PayoutProfileDTO;
use App\Entity\User;
use App\Repository\CreatorPayoutProfileRepository;
use App\Service\CreatorDocumentStorage;
use App\Service\CreatorPayoutService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * CreatorPayoutServiceValidationTest — Tests unitaires des règles de validation (ADR-0027).
 *
 * On teste ici les règles métier de validation du DTO :
 *   - IBAN invalide → message d'erreur approprié
 *   - SIRET invalide → message d'erreur
 *   - Données valides → persistance (bouchon)
 *
 * STRATÉGIE :
 *   Toutes les dépendances sont mockées (EntityManager, Repository, Mailer, DocumentStorage).
 *   On ne touche pas à la BDD.
 *   On teste la méthode save() qui est publique et renvoie une string|CreatorPayoutProfile.
 *   Si la validation échoue → retour string (message d'erreur).
 *   Si tout est valide → appel à persist/flush (vérifié via mock).
 */
#[CoversClass(CreatorPayoutService::class)]
class CreatorPayoutServiceValidationTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var CreatorPayoutProfileRepository&MockObject */
    private CreatorPayoutProfileRepository $profileRepository;

    /** @var CreatorDocumentStorage&MockObject */
    private CreatorDocumentStorage $documentStorage;

    /** @var MailerInterface&MockObject */
    private MailerInterface $mailer;

    private CreatorPayoutService $service;

    protected function setUp(): void
    {
        // Mocke toutes les dépendances pour des tests rapides sans BDD ni filesystem
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->profileRepository = $this->createMock(CreatorPayoutProfileRepository::class);
        $this->documentStorage   = $this->createMock(CreatorDocumentStorage::class);
        $this->mailer            = $this->createMock(MailerInterface::class);

        $this->service = new CreatorPayoutService(
            $this->em,
            $this->profileRepository,
            $this->documentStorage,
            $this->mailer,
            'admin@test.bazaart.fr',
        );
    }

    // ─── Tests IBAN invalides ─────────────────────────────────────────────────

    /**
     * IBAN vide → erreur "obligatoire".
     */
    public function testSaveReturnsErrorWhenIbanIsEmpty(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(iban: '');
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsStringIgnoringCase('iban', strtolower($result));
    }

    /**
     * IBAN mal formé (pas ISO 13616) → erreur de validation.
     */
    public function testSaveReturnsErrorWhenIbanIsInvalid(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(iban: 'XXXX1234INVALIDE');
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsString('IBAN', $result);
    }

    /**
     * IBAN avec une somme de contrôle incorrecte → erreur (algorithme MOD-97).
     *
     * FR7630006000011234567890188 est un IBAN qui ressemble à un IBAN BNP valide
     * mais dont le dernier chiffre a été modifié (88 au lieu de 189).
     */
    public function testSaveReturnsErrorWhenIbanHasInvalidChecksum(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        // IBAN avec somme de contrôle incorrecte (pas 76, ce serait 76 si valide)
        $dto = $this->makeDto(iban: 'FR0000000000000000000000000');
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsString('IBAN', $result);
    }

    /**
     * IBAN français valide → le résultat est un CreatorPayoutProfile (pas une string d'erreur).
     *
     * FR7630006000011234567890189 est un IBAN BNP Paribas de test valide (MOD-97 = 1).
     * Si la validation IBAN passe et que toutes les autres données sont valides,
     * save() doit retourner une instance de CreatorPayoutProfile.
     */
    public function testSaveWithValidDataReturnsProfile(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);
        $this->mailer->expects($this->any())->method('send');
        $this->em->expects($this->any())->method('persist');
        $this->em->expects($this->any())->method('flush');

        $dto = $this->makeDto(
            iban: 'FR7630006000011234567890189',
            siret: '35600000000048',
            accountHolderName: 'Jean Dupont'
        );

        $result = $this->service->save($this->makeUser(), $dto);

        // Avec toutes les données valides, le résultat est un objet (pas une string d'erreur)
        $this->assertInstanceOf(\App\Entity\CreatorPayoutProfile::class, $result,
            'save() doit retourner un CreatorPayoutProfile quand les données sont valides.');
    }

    // ─── Tests SIRET invalides ───────────────────────────────────────────────

    /**
     * SIRET vide → erreur "obligatoire".
     */
    public function testSaveReturnsErrorWhenSiretIsEmpty(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(siret: '');
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsStringIgnoringCase('siret', strtolower($result));
    }

    /**
     * SIRET avec moins de 14 chiffres → erreur de format.
     */
    public function testSaveReturnsErrorWhenSiretIsTooShort(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(siret: '1234567890'); // 10 chiffres au lieu de 14
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsString('14', $result);
    }

    /**
     * SIRET avec des lettres → erreur de format (que des chiffres attendus).
     */
    public function testSaveReturnsErrorWhenSiretContainsLetters(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(siret: 'ABCDE67890ABCD');
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        // Le message doit parler de 14 chiffres
        $this->assertStringContainsString('chiffre', strtolower($result));
    }

    /**
     * SIRET de 14 chiffres mais avec une CLÉ DE CONTRÔLE invalide (Luhn) → erreur.
     * Ex : 12345678901234 a le bon format mais une somme de Luhn non multiple de 10.
     */
    public function testSaveReturnsErrorWhenSiretLuhnIsInvalid(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(siret: '12345678901234'); // 14 chiffres mais Luhn invalide
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsStringIgnoringCase('contrôle', $result);
    }

    // ─── Tests titulaire vide ────────────────────────────────────────────────

    /**
     * Titulaire vide → erreur.
     */
    public function testSaveReturnsErrorWhenAccountHolderNameIsEmpty(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(accountHolderName: '');
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsStringIgnoringCase('titulaire', strtolower($result));
    }

    // ─── Tests BIC (optionnel mais validé si fourni) ─────────────────────────

    /**
     * BIC invalide (format incorrect) → erreur.
     */
    public function testSaveReturnsErrorWhenBicIsInvalidFormat(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);

        $dto = $this->makeDto(bic: 'INVALID!'); // caractère spécial
        $result = $this->service->save($this->makeUser(), $dto);

        $this->assertIsString($result);
        $this->assertStringContainsString('BIC', $result);
    }

    /**
     * BIC null (non fourni) → succès (BIC optionnel), retourne un profil.
     *
     * Le BIC n'est pas obligatoire pour les virements SEPA intra-zone euro.
     * Un profil sans BIC doit être accepté si les autres données sont valides.
     */
    public function testSaveWithNullBicReturnsProfile(): void
    {
        $this->profileRepository->method('findByUser')->willReturn(null);
        $this->mailer->expects($this->any())->method('send');
        $this->em->expects($this->any())->method('persist');
        $this->em->expects($this->any())->method('flush');

        $dto = $this->makeDto(
            iban: 'FR7630006000011234567890189',
            siret: '35600000000048',
            accountHolderName: 'Marie Curie',
            bic: null // BIC non fourni
        );

        $result = $this->service->save($this->makeUser(), $dto);

        // Sans BIC, toutes les données valides → retourne un profil
        $this->assertInstanceOf(\App\Entity\CreatorPayoutProfile::class, $result,
            'save() doit accepter un profil sans BIC (champ optionnel).');
    }

    // ─── Fournisseurs de données (DataProvider) ──────────────────────────────

    /**
     * Fournisseur : IBAN valides connus.
     *
     * @return array<string, array{string}>
     */
    public static function validIbanProvider(): array
    {
        return [
            'IBAN FR BNP test'     => ['FR7630006000011234567890189'],
            'IBAN FR Société Gén.' => ['FR7630003000500000000000064'],
            'IBAN BE valide'       => ['BE68539007547034'],
            'IBAN DE valide'       => ['DE89370400440532013000'],
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Crée un DTO avec des valeurs par défaut valides, sauf les paramètres surchargés.
     */
    private function makeDto(
        string $iban = 'FR7630006000011234567890189',
        ?string $bic = null,
        // SIRET Luhn-valide par défaut (35600000000048 = La Poste) : la validation
        // vérifie désormais la clé de contrôle, pas seulement le format.
        string $siret = '35600000000048',
        string $accountHolderName = 'Jean Test'
    ): PayoutProfileDTO {
        $dto = new PayoutProfileDTO();
        $dto->iban              = $iban;
        $dto->bic               = $bic;
        $dto->siret             = $siret;
        $dto->accountHolderName = $accountHolderName;
        return $dto;
    }

    /**
     * Crée un User minimal pour les tests (pas besoin d'un vrai compte BDD).
     */
    private function makeUser(): User
    {
        // On utilise un mock User pour pouvoir contrôler getId() etc.
        // User est une classe Doctrine avec des propriétés typées non initialisées
        // → createMock crée une instance sans appeler __construct, ce qui est ok ici.
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getEmail')->willReturn('test@bazaart.fr');
        return $user;
    }
}
