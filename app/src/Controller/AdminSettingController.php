<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AppSettingRepository;
use App\Service\LlmExtractorService;
use App\Service\SettingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminSettingController — Gestion des paramètres applicatifs depuis l'interface admin.
 *
 * Routes disponibles :
 *   GET  /admin/settings         → Liste tous les settings avec leurs valeurs
 *   POST /admin/settings/{key}   → Met à jour la valeur d'un setting
 *   POST /admin/settings/test-anthropic → Teste la connexion à l'API Anthropic
 *
 * Sécurité : toutes les routes de ce controller sont réservées aux admins.
 * Le CSRF est vérifié sur toutes les actions POST.
 *
 * Design : thème Street (noir/blanc, border-radius: 0, variables CSS --ink/--paper/--accent)
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings')]
class AdminSettingController extends AbstractController
{
    public function __construct(
        private readonly AppSettingRepository $settingRepository,
        private readonly SettingService $settingService,
        // LlmExtractorService injecté pour déléguer le test de connexion Anthropic.
        // Le controller ne contient aucune logique d'appel HTTP — c'est le service qui s'en charge.
        private readonly LlmExtractorService $llmExtractorService,
    ) {
    }

    /**
     * Page principale : liste tous les paramètres configurables.
     *
     * Pour les settings isSecret = true, la valeur réelle n'est PAS transmise au template.
     * On transmet seulement une indication "configuré / non configuré".
     * C'est une mesure de sécurité : la clé API ne doit pas apparaître dans la page HTML.
     */
    #[Route('', name: 'app_admin_settings', methods: ['GET'])]
    public function index(): Response
    {
        // Récupère tous les settings dans l'ordre alphabétique de leur clé
        $settings = $this->settingRepository->findBy([], ['settingKey' => 'ASC']);

        return $this->render('admin/settings.html.twig', [
            'settings' => $settings,
        ]);
    }

    /**
     * Met à jour la valeur d'un paramètre.
     *
     * Sécurité CSRF : le token est nommé 'update_setting_{key}' — spécifique à chaque setting.
     * Cela empêche de réutiliser un token volé d'un autre formulaire.
     *
     * Pour les champs vides (chaîne vide ou null), on stocke null en BDD
     * pour distinguer "non configuré" de "configuré à une valeur vide".
     */
    #[Route('/{key}', name: 'app_admin_settings_update', methods: ['POST'], requirements: ['key' => '[a-z0-9_]+'])]
    public function update(string $key, Request $request): Response
    {
        // Vérification du token CSRF anti-CSRF
        // Le token est généré par Twig avec csrf_token('update_setting_' ~ key)
        $tokenId = 'update_setting_' . $key;
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Rechargez la page et réessayez.');
            return $this->redirectToRoute('app_admin_settings');
        }

        // Vérifier que le setting existe bien en BDD (évite les injections de clés inconnues)
        $setting = $this->settingRepository->findByKey($key);
        if ($setting === null) {
            $this->addFlash('error', sprintf('Paramètre "%s" introuvable.', $key));
            return $this->redirectToRoute('app_admin_settings');
        }

        // Récupérer la nouvelle valeur depuis le formulaire POST
        // trim() pour éviter les espaces parasites, ou null si le champ est vide
        $rawValue    = $request->request->get('setting_value', '');
        $newValue    = trim((string) $rawValue);
        $valueToSave = $newValue !== '' ? $newValue : null;

        // ── Protection des paramètres secrets ──────────────────────────────────
        // Si le setting est un secret (clé API, mot de passe, token...) ET que l'admin
        // a soumis le formulaire avec un champ vide, on interprète cela comme :
        // "je ne veux pas changer la valeur actuelle".
        //
        // POURQUOI : sans cette protection, laisser le champ vide écraserait la clé API
        // existante avec null — la perdant définitivement sans confirmation explicite.
        // Ce comportement non intentionnel casserait silencieusement les scrapers LLM.
        //
        // Comportement attendu :
        //   - Champ vide + setting secret → message info + pas de modification
        //   - Champ rempli + setting secret → mise à jour normale
        //   - Champ vide + setting non-secret → stocke null (comportement intentionnel = "effacer")
        if ($setting->isSecret() && ($valueToSave === null || $valueToSave === '')) {
            $this->addFlash('info', sprintf(
                'Paramètre "%s" inchangé (champ vide ignoré pour les valeurs secrètes).',
                $setting->getLabel()
            ));
            return $this->redirectToRoute('app_admin_settings');
        }

        try {
            $this->settingService->set($key, $valueToSave);

            // Message de succès adapté selon le type de setting
            if ($setting->isSecret()) {
                // Pour les settings secrets, ne pas confirmer la valeur dans le message flash
                // (évite d'afficher "valeur = sk-ant-..." dans le HTML)
                $this->addFlash('success', sprintf(
                    'Paramètre "%s" mis à jour.',
                    $setting->getLabel()
                ));
            } else {
                $this->addFlash('success', sprintf(
                    'Paramètre "%s" mis à jour : %s',
                    $setting->getLabel(),
                    $valueToSave ?? '(vide)'
                ));
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Erreur lors de la sauvegarde : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_settings');
    }

    /**
     * Teste la connexion à l'API Anthropic.
     *
     * Utilisé par le bouton "Tester la connexion Anthropic" de la page settings.
     * L'appel est fait via AJAX (fetch JavaScript) et retourne du JSON.
     *
     * @return JsonResponse {ok: bool, message: string}
     */
    #[Route('/test-anthropic', name: 'app_admin_settings_test_anthropic', methods: ['POST'])]
    public function testAnthropic(Request $request): JsonResponse
    {
        // Vérification CSRF même pour les appels AJAX
        if (!$this->isCsrfTokenValid('test_anthropic', $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'message' => 'Token de sécurité invalide.'], 403);
        }

        // Délégation au service — le controller ne contient aucune logique métier
        $result = $this->llmExtractorService->testConnection();
        return new JsonResponse($result);
    }

    /**
     * Teste la connexion à l'API Mistral.
     *
     * Ajouté dans le cadre du support multi-provider LLM (PHASE 5 — refonte scraping).
     * Utilisé par le bouton "Tester connexion Mistral" sur la page settings.
     * L'appel est fait via AJAX (fetch JavaScript) et retourne du JSON.
     *
     * Conformément à CLAUDE.md §4 : le controller ne contient aucune logique métier.
     * Tout est délégué à LlmExtractorService::testMistralConnection().
     *
     * @return JsonResponse {ok: bool, message: string}
     */
    #[Route('/test-mistral', name: 'app_admin_settings_test_mistral', methods: ['POST'])]
    public function testMistral(Request $request): JsonResponse
    {
        // Vérification CSRF pour les appels AJAX
        // Le token est généré dans le template avec csrf_token('test_mistral')
        if (!$this->isCsrfTokenValid('test_mistral', $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'message' => 'Token invalide.'], 403);
        }

        // Délégation au service — lecture de la clé, appel HTTP, interprétation des codes retour
        $result = $this->llmExtractorService->testMistralConnection();
        return new JsonResponse($result);
    }
}
