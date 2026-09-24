<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Outils partagés par les contrôleurs de l'Espace projets (ADR-0037).
 *
 * Un TRAIT PHP permet de réutiliser des méthodes dans plusieurs classes sans
 * héritage : chaque contrôleur étend toujours AbstractController et « inclut »
 * ces méthodes avec `use ProjectControllerTrait;`.
 */
trait ProjectControllerTrait
{
    /** Identifiant CSRF commun aux appels JavaScript (fetch) du module. */
    private const string AJAX_CSRF_ID = 'pm_ajax';

    /**
     * L'utilisatrice connectée, typée User (getUser() renvoie une interface).
     * L'accès au module exige déjà d'être connectée : le cas null ne se produit pas,
     * mais on le traite proprement pour PHPStan et par sécurité.
     */
    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * Redirige vers la page d'origine (champ caché « _back »), sinon vers $fallbackRoute.
     *
     * SÉCURITÉ (redirection ouverte) : on n'accepte qu'un CHEMIN interne qui commence
     * par /admin/projets. Une valeur comme « https://site-malveillant.fr » ou
     * « //site-malveillant.fr » est ignorée.
     *
     * @param array<string, mixed> $fallbackParams
     */
    private function redirectBack(Request $request, string $fallbackRoute, array $fallbackParams = []): RedirectResponse
    {
        $back = (string) $request->request->get('_back', '');
        if (str_starts_with($back, '/admin/projets') && !str_contains($back, '//') && !str_contains($back, '\\')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute($fallbackRoute, $fallbackParams);
    }

    /** Vérifie le jeton CSRF envoyé par fetch() dans l'en-tête X-CSRF-Token. */
    private function isAjaxCsrfValid(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::AJAX_CSRF_ID, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    /**
     * Message affiché quand un jeton CSRF est invalide (page restée ouverte trop
     * longtemps, session expirée…) : l'action est bloquée, on explique pourquoi.
     */
    private function flashInvalidToken(): void
    {
        $this->addFlash('error', 'Jeton de sécurité expiré : recharge la page puis réessaie.');
    }

    private function jsonError(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
