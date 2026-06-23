<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CreatorPayoutProfile;
use App\Entity\User;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\CreatorPayoutProfileRepository;
use App\Repository\ForumReplyRepository;
use App\Repository\ForumThreadRepository;
use App\Repository\LessonProgressRepository;
use App\Repository\LiveAttendeeRepository;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Repository\PostRepository;
use App\Repository\ResourceRepository;
use App\Service\CreatorDocumentStorage;
use App\Service\RgpdService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RgpdServiceTest — Tests unitaires du service RGPD (droit à l'effacement, art. 17).
 *
 * On cible principalement anonymizeUser() et la bonne prise en charge du
 * CreatorPayoutProfile (données bancaires + pièce d'identité) introduit par ADR-0027.
 *
 * STRATÉGIE :
 *   - Tests UNITAIRES : pas de BDD, pas de kernel Symfony, tout est mocké.
 *   - On vérifie que la logique de suppression (fichier + entité) est appelée
 *     dans les bons scénarios.
 *   - On ne teste PAS le hash bcrypt (logique interne du PasswordHasher mocké),
 *     ni les assertions sur User::setEmail() — ces comportements sont des
 *     responsabilités d'autres composants.
 *
 * SCÉNARIOS COUVERTS :
 *   1. anonymizeUser() sans profil de versement → flush() appelé, delete() NOT appelé
 *   2. anonymizeUser() avec profil + document → delete() appelé, remove() appelé
 *   3. anonymizeUser() avec profil SANS document → delete() NOT appelé, remove() appelé
 *   4. anonymizeUser() sans id → LogicException levée avant toute opération
 */
#[CoversClass(RgpdService::class)]
class RgpdServiceTest extends TestCase
{
    // ─── Mocks des dépendances ────────────────────────────────────────────────

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var UserPasswordHasherInterface&MockObject */
    private UserPasswordHasherInterface $passwordHasher;

    /** @var ResourceRepository&MockObject */
    private ResourceRepository $resourceRepository;

    /** @var PostRepository&MockObject */
    private PostRepository $postRepository;

    /** @var MessageRepository&MockObject */
    private MessageRepository $messageRepository;

    /** @var ForumThreadRepository&MockObject */
    private ForumThreadRepository $forumThreadRepository;

    /** @var ForumReplyRepository&MockObject */
    private ForumReplyRepository $forumReplyRepository;

    /** @var NotificationRepository&MockObject */
    private NotificationRepository $notificationRepository;

    /** @var LiveAttendeeRepository&MockObject */
    private LiveAttendeeRepository $liveAttendeeRepository;

    /** @var CourseEnrollmentRepository&MockObject */
    private CourseEnrollmentRepository $courseEnrollmentRepository;

    /** @var LessonProgressRepository&MockObject */
    private LessonProgressRepository $lessonProgressRepository;

    /** @var CreatorPayoutProfileRepository&MockObject */
    private CreatorPayoutProfileRepository $creatorPayoutProfileRepository;

    /** @var CreatorDocumentStorage&MockObject */
    private CreatorDocumentStorage $creatorDocumentStorage;

    /** Service testé (recréé avant chaque test) */
    private RgpdService $service;

    // ─── setUp ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        // On crée des mocks PHPUnit pour toutes les dépendances du service.
        // createMock() génère un objet qui répond null à toutes les méthodes par défaut,
        // et qu'on programme avec ->method()->willReturn() / ->expects() au cas par cas.
        $this->entityManager              = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher             = $this->createMock(UserPasswordHasherInterface::class);
        $this->resourceRepository         = $this->createMock(ResourceRepository::class);
        $this->postRepository             = $this->createMock(PostRepository::class);
        $this->messageRepository          = $this->createMock(MessageRepository::class);
        $this->forumThreadRepository      = $this->createMock(ForumThreadRepository::class);
        $this->forumReplyRepository       = $this->createMock(ForumReplyRepository::class);
        $this->notificationRepository     = $this->createMock(NotificationRepository::class);
        $this->liveAttendeeRepository     = $this->createMock(LiveAttendeeRepository::class);
        $this->courseEnrollmentRepository = $this->createMock(CourseEnrollmentRepository::class);
        $this->lessonProgressRepository   = $this->createMock(LessonProgressRepository::class);

        // Les deux nouvelles dépendances RGPD ajoutées pour ADR-0027 :
        $this->creatorPayoutProfileRepository = $this->createMock(CreatorPayoutProfileRepository::class);
        $this->creatorDocumentStorage         = $this->createMock(CreatorDocumentStorage::class);

        // Le PasswordHasher doit retourner un hash pour que setPassword() puisse être appelé.
        // On retourne une string fictive — on ne teste pas la logique bcrypt ici.
        $this->passwordHasher
            ->method('hashPassword')
            ->willReturn('$2y$10$fakehashfakehashfakehashfakehashfakehashfakehashfakeha');

        // L'EntityManager doit accepter flush() sans erreur dans tous les tests.
        // flush() a un type de retour void : on ne configure pas willReturn() ici
        // (PHPUnit 12 lèverait IncompatibleReturnValueException pour les méthodes void).
        // Les tests qui vérifient que flush() est appelé utilisent expects($this->once()).

        // Instanciation du service avec toutes ses dépendances.
        $this->service = new RgpdService(
            $this->entityManager,
            $this->passwordHasher,
            $this->resourceRepository,
            $this->postRepository,
            $this->messageRepository,
            $this->forumThreadRepository,
            $this->forumReplyRepository,
            $this->notificationRepository,
            $this->liveAttendeeRepository,
            $this->courseEnrollmentRepository,
            $this->lessonProgressRepository,
            $this->creatorPayoutProfileRepository,
            $this->creatorDocumentStorage,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : anonymizeUser() — gestion du CreatorPayoutProfile
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Cas 1 : l'utilisateur n'a PAS de profil de versement.
     *
     * Comportement attendu :
     *   - findByUser() retourne null → pas de suppression
     *   - CreatorDocumentStorage::delete() NE doit PAS être appelé
     *   - EntityManager::remove() NE doit PAS être appelé
     *   - flush() est bien appelé (l'anonymisation User se fait quand même)
     */
    public function testAnonymizeUser_SansProfilVersement_NeSupprimePasDocument(): void
    {
        // Aucun profil de versement pour cet utilisateur
        $this->creatorPayoutProfileRepository
            ->method('findByUser')
            ->willReturn(null);

        // On VÉRIFIE que delete() n'est PAS appelé (pas de fichier à supprimer)
        $this->creatorDocumentStorage
            ->expects($this->never())
            ->method('delete');

        // On VÉRIFIE que remove() n'est PAS appelé (rien à supprimer en BDD)
        $this->entityManager
            ->expects($this->never())
            ->method('remove');

        // On VÉRIFIE que flush() est bien appelé malgré l'absence de profil
        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $user = $this->makeUser();
        $this->service->anonymizeUser($user);
        // Si on arrive ici sans exception, le test est réussi.
        $this->addToAssertionCount(1); // compteur pour phpunit (pas d'assertion explicite)
    }

    /**
     * Cas 2 : l'utilisateur a un profil de versement ET une pièce d'identité.
     *
     * Comportement attendu :
     *   - findByUser() retourne un CreatorPayoutProfile avec document
     *   - CreatorDocumentStorage::delete('creator-docs/xxx.pdf') est appelé UNE FOIS
     *   - EntityManager::remove($profile) est appelé UNE FOIS
     *   - flush() est appelé UNE FOIS (englobe le DELETE + les UPDATE User)
     *
     * C'est le scénario RGPD critique : s'assurer qu'aucune donnée bancaire
     * ni pièce d'identité ne subsiste après la demande d'effacement.
     */
    public function testAnonymizeUser_AvecProfilEtDocument_SupprimeFichierEtEntite(): void
    {
        $documentPath = 'creator-docs/user42_abc123.pdf';

        // Crée un mock de CreatorPayoutProfile avec un document renseigné
        $profile = $this->createMock(CreatorPayoutProfile::class);
        $profile->method('getIdentityDocumentPath')->willReturn($documentPath);

        // Le repository retourne ce profil pour notre utilisateur
        $this->creatorPayoutProfileRepository
            ->method('findByUser')
            ->willReturn($profile);

        // ASSERTION PRINCIPALE : delete() doit être appelé UNE FOIS avec le bon chemin
        // C'est ce test qui garantit que la pièce d'identité est bien effacée du disque.
        $this->creatorDocumentStorage
            ->expects($this->once())
            ->method('delete')
            ->with($documentPath); // on vérifie que le bon chemin est passé

        // ASSERTION PRINCIPALE : remove() doit être appelé UNE FOIS avec le bon profil
        // C'est ce test qui garantit que l'IBAN/BIC/SIRET sont bien supprimés de la BDD.
        $this->entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($profile); // on vérifie que c'est bien ce profil qui est supprimé

        // flush() doit être appelé UNE FOIS (transaction atomique)
        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $user = $this->makeUser();
        $this->service->anonymizeUser($user);
    }

    /**
     * Cas 3 : l'utilisateur a un profil de versement SANS pièce d'identité.
     *
     * Certains utilisateurs peuvent avoir renseigné leurs coordonnées bancaires
     * sans avoir encore uploadé leur pièce d'identité (brouillon).
     *
     * Comportement attendu :
     *   - getIdentityDocumentPath() retourne null → delete() NE doit PAS être appelé
     *   - remove() doit quand même être appelé (les données bancaires restantes sont supprimées)
     *   - flush() est appelé
     */
    public function testAnonymizeUser_AvecProfilSansDocument_NeSupprimeQueLEntite(): void
    {
        // Profil sans document (champ identityDocumentPath = null)
        $profile = $this->createMock(CreatorPayoutProfile::class);
        $profile->method('getIdentityDocumentPath')->willReturn(null);

        $this->creatorPayoutProfileRepository
            ->method('findByUser')
            ->willReturn($profile);

        // Pas de fichier → delete() NE doit PAS être appelé
        $this->creatorDocumentStorage
            ->expects($this->never())
            ->method('delete');

        // Mais l'entité doit quand même être supprimée (IBAN, SIRET en BDD)
        $this->entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($profile);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $user = $this->makeUser();
        $this->service->anonymizeUser($user);
    }

    /**
     * Cas 4 : utilisateur sans identifiant BDD (jamais persisté).
     *
     * anonymizeUser() doit lever une LogicException si l'utilisateur n'a pas d'id.
     * Cela protège contre l'anonymisation d'un objet User non persisté, ce qui
     * produirait un email non-unique ("anonymise_@bazaart-deleted.fr").
     *
     * En pratique ce cas ne devrait jamais arriver (l'utilisateur est chargé depuis
     * la BDD avant d'appeler cette méthode), mais le guard est là pour la robustesse.
     */
    public function testAnonymizeUser_SansId_LeveLogicException(): void
    {
        // Un User sans id (getId() retourne null) — simule un objet non persisté
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(null);

        // Ni delete(), ni remove(), ni flush() ne doivent être appelés
        // (l'exception est levée avant)
        $this->creatorDocumentStorage->expects($this->never())->method('delete');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        // On attend une LogicException
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/sans identifiant/i');

        $this->service->anonymizeUser($user);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crée un mock User minimal avec un id valide.
     *
     * On utilise createMock(User::class) plutôt que new User() car les propriétés
     * typées non initialisées de User (email, password, etc.) provoqueraient des
     * erreurs "typed property not initialized" si on appelait des setters dessus.
     * Avec le mock, toutes les méthodes sont disponibles sans état initial.
     *
     * @return User&MockObject
     */
    private function makeUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getEmail')->willReturn('artiste@test.bazaart.fr');
        return $user;
    }
}
