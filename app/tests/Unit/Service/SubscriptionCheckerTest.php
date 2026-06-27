<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\MatchConsultationRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * SubscriptionCheckerTest — Tests unitaires du service de vérification d'abonnement.
 *
 * Ce service est le cœur du paywall freemium (ADR-0022 + ADR-0028).
 * On teste les quatre acteurs :
 *   1. ROLE_ADMIN → toujours abonné (jamais bloqué), consultations illimitées
 *   2. Essai gratuit en cours (trialEndsAt dans le futur) → accès premium (ADR-0028)
 *   3. Abonné actif (Subscription::isActive() = true) → accès illimité
 *   4. Utilisateur gratuit → limité à FREE_DAILY_MATCH_LIMIT consultations par jour
 *
 * STRATÉGIE DE TEST :
 *   Tests UNITAIRES : pas de BDD, pas de kernel Symfony.
 *   Les dépendances (SubscriptionRepository, MatchConsultationRepository, Security)
 *   sont mockées via PHPUnit MockObject.
 *
 * CONVENTIONS :
 *   Méthodes de test : test<Acteur>_<Condition>_<ResultatAttendu>()
 *   Les mocks sont recréés dans setUp() pour l'isolation des tests.
 */
// L'attribut #[CoversClass] indique à PHPUnit quelle classe est testée ici.
// Cela permet au rapport de couverture d'associer ces tests à SubscriptionChecker
// et évite les "PHPUnit Notices" sur les tests sans attribution de couverture.
#[CoversClass(SubscriptionChecker::class)]
class SubscriptionCheckerTest extends TestCase
{
    // ─── Mocks des dépendances ────────────────────────────────────────────────

    /** @var SubscriptionRepository&MockObject */
    private SubscriptionRepository $subscriptionRepo;

    /** @var MatchConsultationRepository&MockObject */
    private MatchConsultationRepository $consultationRepo;

    /** @var Security&MockObject */
    private Security $security;

    /** Service testé (recréé avant chaque test) */
    private SubscriptionChecker $checker;

    // ─── setUp ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        // On crée des mocks PHPUnit pour les trois dépendances.
        // createMock() crée un objet qui répond à toutes les méthodes avec null
        // par défaut, et qu'on peut programmer avec ->method()->willReturn().
        $this->subscriptionRepo = $this->createMock(SubscriptionRepository::class);
        $this->consultationRepo = $this->createMock(MatchConsultationRepository::class);
        $this->security         = $this->createMock(Security::class);

        // Recréé avant chaque test pour garantir l'isolation des états.
        $this->checker = new SubscriptionChecker(
            $this->subscriptionRepo,
            $this->consultationRepo,
            $this->security,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : isSubscribed()
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un ROLE_ADMIN est considéré comme abonné (jamais bloqué par le paywall).
     *
     * Règle ADR-0022 : "ROLE_ADMIN n'est JAMAIS bloqué".
     * On ne doit PAS appeler findActiveByUser pour un admin
     * (optimisation : pas de requête BDD inutile).
     */
    public function testIsSubscribed_Admin_RetourneTrue(): void
    {
        // Le Security component répond "true" à isGranted('ROLE_ADMIN')
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(true);

        // Un admin n'a pas besoin de subscription Stripe : on vérifie que
        // findActiveByUser n'est PAS appelé (optimisation + isolation).
        $this->subscriptionRepo->expects($this->never())
            ->method('findActiveByUser');

        $user = new User();
        $result = $this->checker->isSubscribed($user);

        $this->assertTrue($result, 'Un admin doit toujours être considéré comme abonné.');
    }

    /**
     * Un utilisateur avec un abonnement Stripe actif est considéré comme abonné.
     *
     * Condition : Security::isGranted('ROLE_ADMIN') = false
     *             SubscriptionRepository::findActiveByUser() = Subscription(isActive=true)
     */
    public function testIsSubscribed_UtilisateurAvecAbonnementActif_RetourneTrue(): void
    {
        // Pas admin
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // Crée un mock de Subscription avec isActive() = true
        $subscription = $this->createMock(Subscription::class);
        $subscription->method('isActive')->willReturn(true);

        // Le repository retourne cet abonnement actif
        $this->subscriptionRepo->method('findActiveByUser')
            ->willReturn($subscription);

        $user = new User();
        $result = $this->checker->isSubscribed($user);

        $this->assertTrue($result, 'Un utilisateur avec abonnement Stripe actif doit être abonné.');
    }

    /**
     * Un utilisateur dont l'abonnement Stripe est expiré / annulé n'est PAS abonné.
     *
     * Condition : findActiveByUser() retourne un Subscription avec isActive() = false.
     * Cela peut arriver si le webhook Stripe est en retard mais que la période est expirée.
     */
    public function testIsSubscribed_AbonnementExpire_RetourneFalse(): void
    {
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // Abonnement présent en BDD mais expiré (isActive = false)
        $subscription = $this->createMock(Subscription::class);
        $subscription->method('isActive')->willReturn(false);

        $this->subscriptionRepo->method('findActiveByUser')
            ->willReturn($subscription);

        $user = new User();
        $result = $this->checker->isSubscribed($user);

        $this->assertFalse($result, 'Un abonnement expiré ne doit pas donner accès premium.');
    }

    /**
     * Un utilisateur sans aucun abonnement Stripe n'est pas abonné.
     *
     * Condition : findActiveByUser() retourne null.
     */
    public function testIsSubscribed_SansAbonnement_RetourneFalse(): void
    {
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // Aucun abonnement en BDD
        $this->subscriptionRepo->method('findActiveByUser')
            ->willReturn(null);

        $user = new User();
        $result = $this->checker->isSubscribed($user);

        $this->assertFalse($result, 'Un utilisateur sans abonnement doit être utilisateur gratuit.');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : getRemainingMatchViews()
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un abonné actif a des consultations illimitées (PHP_INT_MAX).
     */
    public function testGetRemainingMatchViews_Abonne_RetourneIllimite(): void
    {
        // Simule un utilisateur abonné
        $this->security->method('isGranted')->willReturn(false);
        $subscription = $this->createMock(Subscription::class);
        $subscription->method('isActive')->willReturn(true);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn($subscription);

        // Le repository de consultations ne doit PAS être appelé pour un abonné
        $this->consultationRepo->expects($this->never())
            ->method('countForUserToday');

        $user = new User();
        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(PHP_INT_MAX, $remaining,
            'Un abonné doit avoir PHP_INT_MAX consultations restantes (illimité).'
        );
    }

    /**
     * Un admin a des consultations illimitées (PHP_INT_MAX).
     */
    public function testGetRemainingMatchViews_Admin_RetourneIllimite(): void
    {
        // Admin : isGranted('ROLE_ADMIN') = true
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(true);

        // Pas d'appel au repository de consultations pour un admin
        $this->consultationRepo->expects($this->never())
            ->method('countForUserToday');

        $user = new User();
        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(PHP_INT_MAX, $remaining,
            'Un admin doit avoir PHP_INT_MAX consultations restantes.'
        );
    }

    /**
     * Un utilisateur gratuit qui n'a pas encore consulté de matchs cette semaine
     * a le maximum de consultations disponibles (FREE_DAILY_MATCH_LIMIT = 3).
     */
    public function testGetRemainingMatchViews_GratuitSansConsultation_RetourneMax(): void
    {
        // Utilisateur gratuit (pas admin, pas abonné)
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // 0 consultations utilisées cette semaine
        $this->consultationRepo->method('countForUserToday')
            ->willReturn(0);

        $user = new User();
        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(
            SubscriptionChecker::FREE_DAILY_MATCH_LIMIT,
            $remaining,
            'Un utilisateur gratuit sans consultation doit avoir 3 vues disponibles.'
        );
    }

    /**
     * Un utilisateur gratuit ayant utilisé 2 consultations en a encore 1 restante.
     */
    public function testGetRemainingMatchViews_GratuitAvec2Consultations_Retourne1(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // 2 consultations utilisées cette semaine
        $this->consultationRepo->method('countForUserToday')
            ->willReturn(2);

        $user = new User();
        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(1, $remaining,
            'Après 2 consultations sur 3, il doit rester 1 consultation.'
        );
    }

    /**
     * Un utilisateur gratuit ayant atteint sa limite hebdomadaire a 0 consultations restantes.
     *
     * C'est le cas qui déclenche l'affichage de l'encart tarifs dans l'UI.
     */
    public function testGetRemainingMatchViews_GratuitLimiteAtteinte_Retourne0(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // Limite atteinte : 3 consultations utilisées (= FREE_DAILY_MATCH_LIMIT)
        $this->consultationRepo->method('countForUserToday')
            ->willReturn(SubscriptionChecker::FREE_DAILY_MATCH_LIMIT);

        $user = new User();
        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(0, $remaining,
            'Un utilisateur ayant atteint sa limite hebdomadaire doit avoir 0 consultations restantes.'
        );
    }

    /**
     * Si le compteur dépasse la limite (bug ou race condition), on retourne 0 et pas un négatif.
     *
     * Ex : si un bug crée 4 entrées pour un utilisateur qui devait être limité à 3,
     * on ne retourne pas -1 (ce qui serait confus).
     */
    public function testGetRemainingMatchViews_CompteurDepasseLimite_Retourne0(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // Plus de consultations que la limite (ne devrait pas arriver mais sécurité)
        $this->consultationRepo->method('countForUserToday')
            ->willReturn(SubscriptionChecker::FREE_DAILY_MATCH_LIMIT + 5);

        $user = new User();
        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(0, $remaining,
            'getRemainingMatchViews() ne doit jamais retourner un nombre négatif.'
        );
        $this->assertGreaterThanOrEqual(0, $remaining,
            'Le nombre de consultations restantes doit toujours être >= 0.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : canViewMoreMatches()
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * canViewMoreMatches() retourne true si l'utilisateur peut encore voir un match.
     *
     * Raccourci booléen de getRemainingMatchViews() > 0.
     */
    public function testCanViewMoreMatches_GratuitAvecConsultationDisponible_RetourneTrue(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // 1 consultation utilisée sur 3 → 2 restantes
        $this->consultationRepo->method('countForUserToday')->willReturn(1);

        $user = new User();
        $canView = $this->checker->canViewMoreMatches($user);

        $this->assertTrue($canView,
            'Un utilisateur ayant encore des consultations disponibles peut voir plus de matchs.'
        );
    }

    /**
     * canViewMoreMatches() retourne false si la limite est atteinte.
     *
     * C'est la condition qui déclenche l'affichage du paywall dans HomeController
     * et dans swipe.js.
     */
    public function testCanViewMoreMatches_GratuitLimiteAtteinte_RetourneFalse(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // Limite atteinte
        $this->consultationRepo->method('countForUserToday')
            ->willReturn(SubscriptionChecker::FREE_DAILY_MATCH_LIMIT);

        $user = new User();
        $canView = $this->checker->canViewMoreMatches($user);

        $this->assertFalse($canView,
            'Un utilisateur ayant atteint sa limite ne peut plus voir de matchs.'
        );
    }

    /**
     * canViewMoreMatches() retourne toujours true pour un abonné actif.
     */
    public function testCanViewMoreMatches_Abonne_RetourneToujours(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $subscription = $this->createMock(Subscription::class);
        $subscription->method('isActive')->willReturn(true);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn($subscription);

        $user = new User();
        $canView = $this->checker->canViewMoreMatches($user);

        $this->assertTrue($canView,
            'Un abonné peut toujours voir plus de matchs (illimité).'
        );
    }

    /**
     * La 4e consultation d'un utilisateur gratuit (après 3) doit être bloquée.
     *
     * Scénario concret : l'utilisateur a vu 3 matchs, il essaie d'en voir un 4e.
     * getRemainingMatchViews() = 0 → canViewMoreMatches() = false → paywall s'affiche.
     */
    public function testCanViewMoreMatches_GratuitQuatriemeConsultation_RetourneFalse(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // L'utilisateur a déjà consommé ses 3 consultations
        $this->consultationRepo->method('countForUserToday')
            ->willReturn(3); // = FREE_DAILY_MATCH_LIMIT

        $user = new User();

        // La 4e consultation est bloquée
        $canView = $this->checker->canViewMoreMatches($user);

        $this->assertFalse($canView,
            'La 4e consultation d\'un utilisateur gratuit doit être bloquée (limite = 3/jour).'
        );
    }

    /**
     * Vérifie que la constante FREE_DAILY_MATCH_LIMIT vaut bien 3.
     *
     * Ce test protège contre une modification accidentelle de la constante.
     * Si on change la limite, ce test échoue explicitement et force une décision consciente.
     * (ADR-0022 mis à jour juin 2026 : limite passée de hebdomadaire → quotidienne)
     */
    public function testConstante_DailyLimit_VautTrois(): void
    {
        $this->assertSame(
            3,
            SubscriptionChecker::FREE_DAILY_MATCH_LIMIT,
            'La limite quotidienne doit être de 3 consultations (ADR-0022).'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : Essai gratuit d'1 mois (ADR-0028)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un utilisateur dont l'essai est en cours est considéré comme abonné.
     *
     * Condition : User::isInTrial() = true (trialEndsAt dans le futur)
     *             Pas d'abonnement Stripe.
     *
     * L'essai doit court-circuiter la vérification Stripe :
     * findActiveByUser() ne doit PAS être appelé (optimisation).
     *
     * ADR-0028 : "branché à isSubscribed() pour tout débloquer d'un coup".
     */
    public function testIsSubscribed_EssaiEnCours_RetourneTrue(): void
    {
        // Pas admin
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // On vérifie que Stripe n'est PAS interrogé pendant un essai actif
        // (court-circuit ADR-0028 : l'essai passe avant le check Stripe)
        $this->subscriptionRepo->expects($this->never())
            ->method('findActiveByUser');

        // Crée un User avec un essai qui expire dans 15 jours
        $user = new User();
        // On injecte directement trialEndsAt via le setter (l'essai est en cours)
        $user->setTrialEndsAt(new \DateTime('+15 days'));

        $result = $this->checker->isSubscribed($user);

        $this->assertTrue($result,
            'Un utilisateur dont l\'essai est en cours doit être considéré comme abonné (ADR-0028).'
        );
    }

    /**
     * Un utilisateur dont l'essai est en cours a des consultations illimitées.
     *
     * isSubscribed() = true → getRemainingMatchViews() = PHP_INT_MAX.
     * Ce test vérifie que le court-circuit de l'essai remonte bien jusqu'au compteur.
     */
    public function testGetRemainingMatchViews_EssaiEnCours_RetourneIllimite(): void
    {
        // Pas admin, pas d'abonnement Stripe
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->expects($this->never())
            ->method('findActiveByUser');
        // Le repository de consultations ne doit pas être appelé non plus
        $this->consultationRepo->expects($this->never())
            ->method('countForUserToday');

        $user = new User();
        $user->setTrialEndsAt(new \DateTime('+15 days'));

        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(PHP_INT_MAX, $remaining,
            'Un utilisateur en essai doit avoir PHP_INT_MAX consultations restantes (illimité).'
        );
    }

    /**
     * Un utilisateur dont l'essai est EXPIRÉ et sans abonnement n'est PAS abonné.
     *
     * Condition : trialEndsAt dans le passé, pas d'abonnement Stripe.
     *
     * C'est le cas normal 30 jours après l'inscription : retour au mode gratuit.
     * L'utilisateur repasse à 3 consultations/jour.
     */
    public function testIsSubscribed_EssaiExpire_SansAbonnement_RetourneFalse(): void
    {
        // Pas admin
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // Aucun abonnement Stripe actif
        $this->subscriptionRepo->method('findActiveByUser')
            ->willReturn(null);

        // Essai expiré (trialEndsAt dans le passé)
        $user = new User();
        $user->setTrialEndsAt(new \DateTime('-1 day'));

        $result = $this->checker->isSubscribed($user);

        $this->assertFalse($result,
            'Un utilisateur dont l\'essai est expiré et sans abonnement doit être en mode gratuit.'
        );
    }

    /**
     * Après expiration de l'essai, l'utilisateur est limité à FREE_DAILY_MATCH_LIMIT.
     *
     * Scénario : essai expiré hier, 0 consultations aujourd'hui.
     * Résultat attendu : getRemainingMatchViews() = FREE_DAILY_MATCH_LIMIT (3).
     */
    public function testGetRemainingMatchViews_EssaiExpire_RetourneLimiteQuotidienne(): void
    {
        // Pas admin, pas d'abonnement Stripe
        $this->security->method('isGranted')->willReturn(false);
        $this->subscriptionRepo->method('findActiveByUser')->willReturn(null);

        // 0 consultations utilisées aujourd'hui
        $this->consultationRepo->method('countForUserToday')->willReturn(0);

        // Essai expiré : l'utilisateur est maintenant en mode gratuit
        $user = new User();
        $user->setTrialEndsAt(new \DateTime('-1 day'));

        $remaining = $this->checker->getRemainingMatchViews($user);

        $this->assertSame(
            SubscriptionChecker::FREE_DAILY_MATCH_LIMIT,
            $remaining,
            'Après expiration de l\'essai, l\'utilisateur retrouve la limite quotidienne gratuite (3/jour).'
        );
    }
}
