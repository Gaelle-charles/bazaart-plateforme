<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ResourceAlert;
use App\Entity\User;
use App\Enum\AlertFrequency;
use App\Repository\ResourceAlertRepository;
use App\Security\Voter\MatchingVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SwipeController — Endpoints AJAX dédiés à l'UI swipe de la home (ADR-0021, Lot C).
 *
 * CE CONTROLLER NE GÈRE QUE LES ACTIONS AJAX DU SWIPE.
 * Il ne gère PAS la page elle-même (c'est HomeController qui la rend).
 *
 * ROUTES EXPOSÉES :
 *   POST /swipe/alert  → crée ou active une alerte de matching (avec consentement)
 *
 * NOTE SUR LES FAVORIS :
 *   Le toggle favori (swipe droite = "intéressé(e)") réutilise la route existante :
 *     POST /resources/{id}/favorite  (app_resource_favorite_toggle)
 *   Elle retourne déjà du JSON quand X-Requested-With: XMLHttpRequest est envoyé.
 *   On ne la reduplique pas ici : la logique est déjà testée et stable.
 *
 * SÉCURITÉ :
 *   Toutes les routes vérifient MATCHING_VIEW via MatchingVoter (= ROLE_ARTIST requis).
 *   Chaque POST porte un token CSRF vérifié côté serveur.
 *
 * PHILOSOPHIE "THIN CONTROLLER" :
 *   La logique de création/mise à jour de l'alerte est ici dans le controller
 *   car elle est suffisamment simple (3 étapes : trouver, créer si absent, flush).
 *   Si elle devenait plus complexe (règles métier, envoi email…), on l'extrairait
 *   dans un SwipeService dédié.
 */
#[Route('/swipe', name: 'app_swipe_')]
final class SwipeController extends AbstractController
{
    public function __construct(
        // Repository d'alertes : lecture (findByUser) et écriture (persist via EM)
        private readonly ResourceAlertRepository  $alertRepository,
        // EntityManager : pour persist + flush de la nouvelle alerte
        private readonly EntityManagerInterface   $entityManager,
    ) {}

    /**
     * Crée ou active une alerte de matching avec consentement explicite.
     *
     * Route : POST /swipe/alert
     * Nom   : app_swipe_alert
     *
     * PARAMÈTRES POST attendus :
     *   _token    : token CSRF (nom 'swipe_alert')
     *   consent   : '1' si l'utilisateur a coché la case de consentement
     *
     * COMPORTEMENT :
     *   - Si consent != '1' : on retourne une erreur 400 (pas de consentement = rien à faire).
     *     L'ADR-0021 est clair : "avec l'accord explicite de l'utilisateur".
     *   - Si l'utilisateur a déjà une alerte active : on répond 200 "déjà actif"
     *     (pas de doublon, l'idempotence est gérée proprement).
     *   - Si l'utilisateur n'a pas d'alerte : on en crée une avec frequency=Daily
     *     et notifyOnNewResource=true (sans filtre de discipline ni de type = toutes).
     *     Ces réglages fins restent disponibles via /resources/alerts ou /mes-alertes.
     *
     * RÉPONSE JSON :
     *   { "success": true, "message": "...", "already_active": false }
     *   { "error": "...", "code": 400 }   si consentement absent ou CSRF invalide
     */
    #[Route('/alert', name: 'alert', methods: ['POST'])]
    public function createAlert(Request $request): Response
    {
        // ── Vérification ROLE_ARTIST via MatchingVoter ──────────────────────────
        // On réutilise le voter du Lot B : évite de dupliquer la règle "artiste connecté".
        // Si l'utilisateur n'est pas connecté ou pas ROLE_ARTIST → AccessDeniedException
        // qui sera catchée par Symfony et retournée en JSON 403 (firewall configuré
        // pour retourner du JSON sur /swipe/* si la négociation de contenu est faite,
        // sinon c'est géré par le bloc catch ci-dessous).
        try {
            $this->denyAccessUnlessGranted(MatchingVoter::MATCHING_VIEW);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            // En AJAX : retour JSON cohérent (pas de redirection /login HTML).
            // Sans JS : laisse Symfony gérer la redirection vers /login normalement.
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(
                    ['error' => 'Vous devez être connecté(e) en tant qu\'artiste.'],
                    Response::HTTP_FORBIDDEN
                );
            }
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
        }

        // ── Validation du token CSRF ────────────────────────────────────────────
        // Le nom 'swipe_alert' est spécifique à cet endpoint pour isoler les tokens.
        // Le front doit générer ce token via csrf_token('swipe_alert') dans Twig.
        $csrfToken = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('swipe_alert', $csrfToken)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(
                    ['error' => 'Token de sécurité invalide. Rechargez la page.'],
                    Response::HTTP_FORBIDDEN
                );
            }
            // Sans JS : flash d'erreur + retour home (le formulaire recharge un nouveau token)
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');

            return $this->redirectToRoute('app_home');
        }

        // ── Progressive enhancement : branchement AJAX / non-AJAX ─────────────
        // Après la vérif CSRF (commune aux deux chemins), on détermine si la requête
        // vient d'un formulaire HTML classique ou d'un appel fetch() JavaScript.
        // isXmlHttpRequest() détecte l'en-tête "X-Requested-With: XMLHttpRequest"
        // envoyé par le JS dans initAlertButton() (swipe.js).
        $isAjax = $request->isXmlHttpRequest();

        // ── Vérification du consentement explicite ─────────────────────────────
        // Le consentement n'est JAMAIS coché par défaut (ADR-0021 §4).
        // Si l'utilisateur n'a pas coché la case → on refuse poliment.
        // '1' est la valeur envoyée par une checkbox HTML cochée (value="1").
        $consentGiven = $request->request->get('consent') === '1';
        if (!$consentGiven) {
            if ($isAjax) {
                return new JsonResponse(
                    ['error' => 'Consentement requis pour activer les alertes.'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $this->addFlash('error', 'Veuillez cocher la case pour activer les alertes.');

            return $this->redirectToRoute('app_home');
        }

        // ── Récupération de l'utilisateur connecté ─────────────────────────────
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Ne devrait pas arriver (denyAccessUnlessGranted l'a déjà vérifié)
            // mais PHPStan exige la garde de type explicite.
            if ($isAjax) {
                return new JsonResponse(
                    ['error' => 'Utilisateur non authentifié.'],
                    Response::HTTP_UNAUTHORIZED
                );
            }

            return $this->redirectToRoute('app_login');
        }

        // ── Idempotence : l'utilisateur a-t-il déjà une alerte active ? ────────
        // Un utilisateur peut activer l'alerte depuis plusieurs endroits
        // (swipe home + /mes-alertes). On vérifie l'existence avant de créer.
        $existingAlert = $this->alertRepository->findByUser($user);

        if ($existingAlert !== null && $existingAlert->isNotifyOnNewResource()) {
            // L'alerte existe déjà et est active : on informe sans erreur.
            if ($isAjax) {
                // already_active = true permet au front de différencier "créé" de "déjà là".
                return new JsonResponse([
                    'success'        => true,
                    'message'        => 'Vos alertes de matching sont déjà actives.',
                    'already_active' => true,
                ]);
            }
            $this->addFlash('success', 'Vos alertes de matching sont déjà actives.');

            return $this->redirectToRoute('app_home');
        }

        if ($existingAlert !== null) {
            // L'alerte existe mais était désactivée → on la réactive.
            // On ne crée pas de doublon : on met juste notifyOnNewResource à true.
            $existingAlert->setNotifyOnNewResource(true);
            // updatedAt est rafraîchi automatiquement par le PreUpdate de l'entité
            $this->entityManager->flush();

            if ($isAjax) {
                return new JsonResponse([
                    'success'        => true,
                    'message'        => 'Vos alertes de matching ont été réactivées.',
                    'already_active' => false,
                ]);
            }
            $this->addFlash('success', 'Alertes de matching réactivées avec succès.');

            return $this->redirectToRoute('app_home');
        }

        // ── Création d'une nouvelle alerte ─────────────────────────────────────
        // On crée un profil d'alertes minimal :
        //   - notifyOnNewResource = true (activé)
        //   - frequency = Daily (bon défaut, pas trop intrusif)
        //   - filterDisciplines vide = toutes les disciplines
        //   - filterResourceTypes vide = tous les types
        // L'utilisateur peut affiner via /mes-alertes plus tard.
        $alert = new ResourceAlert();
        $alert->setUser($user);
        $alert->setNotifyOnNewResource(true);
        $alert->setFrequency(AlertFrequency::Daily);
        // Les collections de filtres sont déjà initialisées vides dans le constructeur
        // de ResourceAlert — pas besoin de les toucher ici.

        $this->entityManager->persist($alert);
        $this->entityManager->flush();

        if ($isAjax) {
            return new JsonResponse([
                'success'        => true,
                'message'        => 'Alerte activée. Vous serez notifié(e) des prochaines opportunités qui matchent votre profil.',
                'already_active' => false,
            ], Response::HTTP_CREATED);
        }

        // Sans JS : flash de succès + redirection vers la home
        // Le flash est affiché par base.html.twig dans le bloc notifications.
        $this->addFlash('success', 'Alerte activée. Vous serez notifié(e) des prochaines opportunités qui correspondent à votre profil.');

        return $this->redirectToRoute('app_home');
    }
}
