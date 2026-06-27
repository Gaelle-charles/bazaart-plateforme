<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ArtistProfile;
use App\Entity\Discipline;
use App\Entity\MatchConsultation;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\SubscriptionChecker;

/**
 * PaywallLotDTest — Tests fonctionnels E2E pour le paywall freemium (ADR-0022, Lot D).
 *
 * Ce fichier teste :
 *   1. La home retourne 200 pour un artiste gratuit (limite non atteinte)
 *   2. La home retourne 200 pour un artiste gratuit (limite atteinte → encart paywall affiché)
 *   3. La home retourne 200 pour un abonné (section swipe normale, pas de paywall)
 *   4. La home retourne 200 pour un admin (aucun paywall)
 *
 * Gating des fonctionnalités premium :
 *   5. /resources redirige vers /tarifs pour un utilisateur gratuit
 *   6. /resources retourne 200 pour un abonné
 *   7. /resources retourne 200 pour un admin
 *   8. /resources/alerts redirige vers /tarifs pour un utilisateur gratuit
 *   9. /profile/artist/directory redirige vers /tarifs pour un utilisateur gratuit
 *  10. /messages redirige vers /tarifs pour un utilisateur gratuit
 *  11. /messages retourne 200 pour un abonné
 *
 * Endpoint compteur :
 *  12. POST /swipe/record-view incrémente le compteur pour un utilisateur gratuit
 *  13. POST /swipe/record-view retourne 403 si la limite est déjà atteinte
 *  14. POST /swipe/record-view retourne "subscribed: true" pour un abonné (pas d'incrémentation)
 *
 * @group e2e
 * @group paywall
 */
class PaywallLotDTest extends AbstractE2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();

        // On purge aussi les tables spécifiques au paywall Lot D
        // (absentes du purgeDatabase() de base car ajoutées dans ce lot)
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE match_consultations, subscriptions RESTART IDENTITY CASCADE'
        );
        $this->em->clear();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : PAGE D'ACCUEIL — états paywall de la section swipe
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * La home retourne 200 pour un artiste gratuit n'ayant pas atteint sa limite.
     *
     * État attendu : la section swipe s'affiche normalement (pas d'encart paywall).
     * La variable swipeLimitReached = false dans le template.
     */
    public function testHome_GratuitSansLimiteAtteinte_Retourne200(): void
    {
        $user = $this->createArtistUser('gratuit-ok@test.fr');
        $this->loginAs($user);

        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit retourner 200 pour un artiste gratuit n\'ayant pas atteint sa limite.'
        );

        // L'encart paywall NE doit PAS être affiché
        $this->assertSelectorNotExists('.swipe-paywall',
            'L\'encart paywall ne doit pas s\'afficher si la limite n\'est pas atteinte.'
        );
    }

    /**
     * La home affiche l'encart paywall quand un artiste gratuit a atteint sa limite journalière.
     *
     * On pré-crée FREE_DAILY_MATCH_LIMIT enregistrements MatchConsultation
     * pour simuler la limite atteinte.
     */
    public function testHome_GratuitLimiteAtteinte_AffichePaywall(): void
    {
        $user = $this->createArtistUser('gratuit-limite@test.fr');

        // Crée le nombre maximum de consultations pour le jour en cours
        $this->creerConsultations($user, SubscriptionChecker::FREE_DAILY_MATCH_LIMIT);

        $this->loginAs($user);
        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit retourner 200 même si la limite paywall est atteinte.'
        );

        // L'encart paywall DOIT être affiché
        $this->assertSelectorExists('.swipe-paywall',
            'L\'encart paywall doit s\'afficher quand la limite journalière est atteinte.'
        );
    }

    /**
     * La home ne bloque pas un abonné actif (section swipe normale).
     */
    public function testHome_Abonne_PasDePaywall(): void
    {
        $user = $this->createArtistUser('abonne@test.fr');
        $this->creerAbonnementActif($user);

        // On crée aussi des consultations pour vérifier qu'elles sont ignorées pour un abonné
        $this->creerConsultations($user, SubscriptionChecker::FREE_DAILY_MATCH_LIMIT + 1);

        $this->loginAs($user);
        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit retourner 200 pour un abonné actif.'
        );

        $this->assertSelectorNotExists('.swipe-paywall',
            'L\'encart paywall ne doit jamais s\'afficher pour un abonné.'
        );
    }

    /**
     * La home ne bloque jamais un admin.
     */
    public function testHome_Admin_PasDePaywall(): void
    {
        $admin = $this->createAdminUser('admin-paywall@test.fr');

        // On crée plein de consultations, l'admin ne doit jamais être bloqué
        $this->creerConsultations($admin, 100);

        $this->loginAs($admin);
        $this->client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200,
            'La home doit retourner 200 pour un admin.'
        );

        $this->assertSelectorNotExists('.swipe-paywall',
            'L\'encart paywall ne doit jamais s\'afficher pour un admin.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : GATING DU CATALOGUE /resources
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un utilisateur gratuit est redirigé vers /tarifs quand il accède au catalogue.
     */
    public function testCatalogue_Gratuit_RedirigeTarifs(): void
    {
        $user = $this->createRegularUser('gratuit-catalogue@test.fr');
        $this->loginAs($user);

        $this->client->request('GET', '/resources');

        // assertResponseRedirects(location, code) — la description est dans le nom de méthode
        $this->assertResponseRedirects('/tarifs');
        $this->assertResponseStatusCodeSame(302,
            'Un utilisateur gratuit doit être redirigé (302) vers /tarifs depuis le catalogue.'
        );
    }

    /**
     * Un abonné actif peut accéder au catalogue des ressources.
     */
    public function testCatalogue_Abonne_Retourne200(): void
    {
        $user = $this->createRegularUser('abonne-catalogue@test.fr');
        $this->creerAbonnementActif($user);

        $this->loginAs($user);
        $this->client->followRedirects(true); // On suit les redirections pour avoir le contenu
        $this->client->request('GET', '/resources');

        $this->assertResponseStatusCodeSame(200,
            'Un abonné doit pouvoir accéder au catalogue complet.'
        );
    }

    /**
     * Un admin peut toujours accéder au catalogue.
     */
    public function testCatalogue_Admin_Retourne200(): void
    {
        $admin = $this->createAdminUser('admin-catalogue@test.fr');

        $this->loginAs($admin);
        $this->client->followRedirects(true);
        $this->client->request('GET', '/resources');

        $this->assertResponseStatusCodeSame(200,
            'Un admin doit toujours pouvoir accéder au catalogue.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : GATING DES ALERTES /resources/alerts
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un utilisateur gratuit est redirigé vers /tarifs depuis la page alertes.
     */
    public function testAlertes_Gratuit_RedirigeTarifs(): void
    {
        $user = $this->createRegularUser('gratuit-alertes@test.fr');
        $this->loginAs($user);

        $this->client->request('GET', '/resources/alerts');

        $this->assertResponseRedirects('/tarifs');
        $this->assertResponseStatusCodeSame(302,
            'Un utilisateur gratuit doit être redirigé (302) vers /tarifs depuis /resources/alerts.'
        );
    }

    /**
     * La route alias /resources/mes-alertes redirige aussi vers /tarifs pour un utilisateur gratuit.
     * Note : le chemin est /resources/mes-alertes (sous le préfixe /resources du controller).
     */
    public function testMesAlertes_Gratuit_RedirigeTarifs(): void
    {
        $user = $this->createRegularUser('gratuit-mes-alertes@test.fr');
        $this->loginAs($user);

        // La route est /resources/mes-alertes (définie avec le préfixe /resources du controller)
        $this->client->request('GET', '/resources/mes-alertes');

        $this->assertResponseRedirects('/tarifs');
        $this->assertResponseStatusCodeSame(302,
            'Un utilisateur gratuit doit être redirigé (302) vers /tarifs depuis /resources/mes-alertes.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : GATING DE L'ANNUAIRE ARTISTES /profile/artist/directory
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un utilisateur gratuit est redirigé vers /tarifs depuis l'annuaire artistes.
     */
    public function testAnnuaireArtistes_Gratuit_RedirigeTarifs(): void
    {
        $user = $this->createRegularUser('gratuit-annuaire@test.fr');
        $this->loginAs($user);

        $this->client->request('GET', '/profile/artist/directory');

        $this->assertResponseRedirects('/tarifs');
        $this->assertResponseStatusCodeSame(302,
            'Un utilisateur gratuit doit être redirigé (302) vers /tarifs depuis l\'annuaire artistes.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : GATING DE LA MESSAGERIE /messages
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Un utilisateur gratuit est redirigé vers /tarifs depuis la messagerie.
     */
    public function testMessagerie_Gratuit_RedirigeTarifs(): void
    {
        $user = $this->createRegularUser('gratuit-messages@test.fr');
        $this->loginAs($user);

        $this->client->request('GET', '/messages');

        $this->assertResponseRedirects('/tarifs');
        $this->assertResponseStatusCodeSame(302,
            'Un utilisateur gratuit doit être redirigé (302) vers /tarifs depuis /messages.'
        );
    }

    /**
     * Un abonné peut accéder à la messagerie.
     */
    public function testMessagerie_Abonne_Retourne200(): void
    {
        $user = $this->createRegularUser('abonne-messages@test.fr');
        $this->creerAbonnementActif($user);

        $this->loginAs($user);
        $this->client->followRedirects(true);
        $this->client->request('GET', '/messages');

        $this->assertResponseStatusCodeSame(200,
            'Un abonné doit pouvoir accéder à la messagerie.'
        );
    }

    /**
     * Un admin peut toujours accéder à la messagerie.
     */
    public function testMessagerie_Admin_Retourne200(): void
    {
        $admin = $this->createAdminUser('admin-messages@test.fr');

        $this->loginAs($admin);
        $this->client->followRedirects(true);
        $this->client->request('GET', '/messages');

        $this->assertResponseStatusCodeSame(200,
            'Un admin doit toujours pouvoir accéder à la messagerie.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS : ENDPOINT POST /swipe/record-view (compteur de consultations)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * POST /swipe/record-view incrémente le compteur pour un utilisateur gratuit.
     *
     * Condition : artiste gratuit, 0 consultations aujourd'hui.
     * Résultat : 200 + remaining = FREE_DAILY_MATCH_LIMIT - 1.
     */
    public function testRecordView_GratuitPremierAppel_IncrementeCompteur(): void
    {
        $user = $this->createArtistUser('artiste-record@test.fr');
        $this->loginAs($user);

        // ── Récupération du token CSRF depuis le HTML ──────────────────────────
        // En Symfony 7, le CsrfTokenManager basé session n'est disponible que
        // pendant une requête HTTP active (RequestStack non vide). Après la fin
        // d'une requête, la RequestStack est vidée et un appel au service depuis
        // le container lancerait SessionNotFoundException.
        //
        // Solution : faire un GET pour que Twig génère le token et l'insérer
        // en data-attribute dans le HTML. On l'extrait ensuite du DOM.
        $this->client->request('GET', '/');

        // Extraction du token depuis data-record-view-csrf de la section swipe.
        // Ce token est généré par $this->csrfTokenManager->getToken('swipe_record_view')
        // dans HomeController pendant la requête GET ci-dessus.
        $token = $this->getCsrfTokenFromDataAttribute('#swipe-section', 'record-view-csrf');

        $this->client->request(
            'POST',
            '/swipe/record-view',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $this->assertResponseStatusCodeSame(200,
            'POST /swipe/record-view doit retourner 200 si la limite n\'est pas atteinte.'
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        $this->assertIsInt($data['remaining'] ?? null,
            'La réponse doit contenir un champ remaining entier.'
        );
        $this->assertSame(
            SubscriptionChecker::FREE_DAILY_MATCH_LIMIT - 1,
            $data['remaining'],
            'Après le 1er enregistrement, remaining doit valoir ' . (SubscriptionChecker::FREE_DAILY_MATCH_LIMIT - 1) . '.'
        );
        $this->assertFalse($data['subscribed'] ?? true,
            'L\'utilisateur gratuit doit avoir subscribed = false.'
        );

        // Vérifie en BDD qu'une consultation a bien été créée
        $count = $this->em->getRepository(MatchConsultation::class)
            ->createQueryBuilder('mc')
            ->select('COUNT(mc.id)')
            ->where('mc.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, (int) $count,
            'Un enregistrement MatchConsultation doit exister en BDD après l\'appel.'
        );
    }

    /**
     * POST /swipe/record-view retourne 403 quand la limite est déjà atteinte.
     *
     * Condition : FREE_DAILY_MATCH_LIMIT consultations déjà enregistrées aujourd'hui.
     * Résultat : 403 Forbidden + champ pricing_url dans la réponse.
     */
    public function testRecordView_LimiteAtteinte_Retourne403(): void
    {
        $user = $this->createArtistUser('artiste-limite@test.fr');

        // On pré-remplit le compteur jusqu'à la limite journalière
        $this->creerConsultations($user, SubscriptionChecker::FREE_DAILY_MATCH_LIMIT);

        $this->loginAs($user);

        // GET / pour obtenir le token CSRF depuis le data-attribute (cf. commentaire
        // dans testRecordView_GratuitPremierAppel_IncrementeCompteur pour l'explication)
        $this->client->request('GET', '/');
        $token = $this->getCsrfTokenFromDataAttribute('#swipe-section', 'record-view-csrf');

        $this->client->request(
            'POST',
            '/swipe/record-view',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $this->assertResponseStatusCodeSame(403,
            'POST /swipe/record-view doit retourner 403 si la limite est atteinte.'
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        $this->assertSame(0, $data['remaining'] ?? -1,
            'La réponse doit indiquer remaining = 0.'
        );
        $this->assertArrayHasKey('pricing_url', $data,
            'La réponse doit contenir pricing_url pour rediriger vers /tarifs.'
        );
    }

    /**
     * POST /swipe/record-view retourne "subscribed: true" pour un abonné.
     * Aucun enregistrement en BDD ne doit être créé (pas de gaspillage).
     */
    public function testRecordView_Abonne_RetourneSubscribedTrue(): void
    {
        $user = $this->createArtistUser('abonne-record@test.fr');
        $this->creerAbonnementActif($user);

        $this->loginAs($user);

        // GET / pour obtenir le token CSRF depuis le data-attribute (cf. commentaire
        // dans testRecordView_GratuitPremierAppel_IncrementeCompteur pour l'explication)
        $this->client->request('GET', '/');
        $token = $this->getCsrfTokenFromDataAttribute('#swipe-section', 'record-view-csrf');

        $this->client->request(
            'POST',
            '/swipe/record-view',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $this->assertResponseStatusCodeSame(200,
            'POST /swipe/record-view doit retourner 200 pour un abonné.'
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        $this->assertTrue($data['subscribed'] ?? false,
            'La réponse doit indiquer subscribed = true pour un abonné.'
        );
        // Note : on utilise array_key_exists() plutôt que ?? car null ?? 'default' retourne
        // 'default' en PHP — le null coalescing vérifie si la valeur est NULL, pas seulement
        // si la clé est absente. On doit d'abord vérifier que la clé existe, PUIS asserter null.
        $this->assertArrayHasKey('remaining', $data,
            'La réponse doit contenir la clé remaining (même si sa valeur est null).'
        );
        $this->assertNull($data['remaining'],
            'Un abonné doit avoir remaining = null (illimité, pas de limite à afficher).'
        );

        // Aucun enregistrement MatchConsultation ne doit être créé pour un abonné
        $count = $this->em->getRepository(MatchConsultation::class)
            ->createQueryBuilder('mc')
            ->select('COUNT(mc.id)')
            ->where('mc.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(0, (int) $count,
            'Aucun MatchConsultation ne doit être créé pour un abonné (illimité = pas de comptage).'
        );
    }

    /**
     * POST /swipe/record-view retourne 403 si le token CSRF est invalide.
     */
    public function testRecordView_CsrfInvalide_Retourne403(): void
    {
        $user = $this->createArtistUser('artiste-csrf-rv@test.fr');
        $this->loginAs($user);

        $this->client->request(
            'POST',
            '/swipe/record-view',
            ['_token' => 'token-invalide-forgé'],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $this->assertResponseStatusCodeSame(403,
            'POST /swipe/record-view avec CSRF invalide doit retourner 403.'
        );
    }

    /**
     * Vérifie que le reset quotidien fonctionne :
     * des consultations d'un jour PRÉCÉDENT ne comptent pas.
     *
     * On simule des consultations d'avant-hier en manipulant
     * directement la date viewedAt via Doctrine (bypass constructeur).
     */
    public function testRecordView_ConsultationsAnciennesSemaine_NePasCompter(): void
    {
        $user = $this->createArtistUser('artiste-reset@test.fr');

        // Crée des consultations datées d'avant-hier (hors de la fenêtre du jour en cours)
        $this->creerConsultationsAnciennes($user, SubscriptionChecker::FREE_DAILY_MATCH_LIMIT);

        $this->loginAs($user);

        // GET / pour obtenir le token CSRF depuis le data-attribute (cf. commentaire
        // dans testRecordView_GratuitPremierAppel_IncrementeCompteur pour l'explication)
        $this->client->request('GET', '/');
        $token = $this->getCsrfTokenFromDataAttribute('#swipe-section', 'record-view-csrf');

        $this->client->request(
            'POST',
            '/swipe/record-view',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        // Doit retourner 200 (pas 403) : les consultations d'avant-hier
        // ne comptent pas dans le compteur du jour en cours.
        $this->assertResponseStatusCodeSame(200,
            'Des consultations d\'un jour précédent ne doivent pas bloquer l\'utilisateur.'
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), associative: true);
        $this->assertSame(
            SubscriptionChecker::FREE_DAILY_MATCH_LIMIT - 1,
            $data['remaining'] ?? -1,
            'Après le reset quotidien, l\'utilisateur doit retrouver ses ' . SubscriptionChecker::FREE_DAILY_MATCH_LIMIT . ' consultations.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crée N enregistrements MatchConsultation pour l'utilisateur,
     * datés d'AUJOURD'HUI (jour en cours, fenêtre minuit UTC → minuit UTC).
     *
     * @param User $user  L'utilisateur pour lequel créer les consultations
     * @param int  $count Nombre de consultations à créer
     */
    private function creerConsultations(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $consultation = new MatchConsultation($user, null);
            $this->em->persist($consultation);
        }
        $this->em->flush();
    }

    /**
     * Crée N enregistrements MatchConsultation datés d'AVANT-HIER (hors du jour en cours).
     *
     * Permet de tester le reset quotidien : ces consultations ne doivent PAS
     * être comptées dans le compteur du jour en cours (minuit UTC → minuit UTC).
     *
     * On injecte viewedAt via ReflectionClass car il est readonly dans le constructeur.
     *
     * @param User $user  L'utilisateur
     * @param int  $count Nombre de consultations à créer
     */
    private function creerConsultationsAnciennes(User $user, int $count): void
    {
        // Avant-hier en UTC : garantit d'être hors de la fenêtre du jour en cours,
        // quelle que soit l'heure à laquelle le test s'exécute.
        $lastWeek = new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC'));

        // Utilise la réflexion pour injecter une date passée dans viewedAt (readonly).
        // ReflectionClass est l'approche standard pour les tests d'entités Doctrine
        // dont les propriétés ne sont pas modifiables après la construction.
        $reflection = new \ReflectionClass(MatchConsultation::class);
        $viewedAtProp = $reflection->getProperty('viewedAt');
        $viewedAtProp->setAccessible(true);

        for ($i = 0; $i < $count; $i++) {
            $consultation = new MatchConsultation($user, null);
            // Écrase la date initialisée dans le constructeur avec la date d'avant-hier
            $viewedAtProp->setValue($consultation, $lastWeek);
            $this->em->persist($consultation);
        }
        $this->em->flush();
    }

    /**
     * Crée un abonnement Stripe actif pour l'utilisateur donné.
     *
     * L'abonnement a :
     *   - status = 'active'
     *   - currentPeriodEnd = dans 30 jours (abonnement non expiré)
     *
     * C'est le minimum requis pour que Subscription::isActive() retourne true.
     *
     * @param User $user L'utilisateur à abonner
     * @return Subscription L'abonnement créé
     */
    private function creerAbonnementActif(User $user): Subscription
    {
        $subscription = new Subscription();
        $subscription
            ->setUser($user)
            ->setStripeSubscriptionId('sub_test_' . uniqid())
            ->setStripeCustomerId('cus_test_' . uniqid())
            ->setPlan('monthly')
            ->setStatus('active')
            // currentPeriodEnd dans 30 jours : abonnement actif
            ->setCurrentPeriodEnd(new \DateTime('+30 days'));

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
