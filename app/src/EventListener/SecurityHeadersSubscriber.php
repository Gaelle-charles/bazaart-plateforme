<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SecurityHeadersSubscriber — Ajoute les en-têtes HTTP de sécurité de base sur
 * CHAQUE réponse envoyée par l'application.
 *
 * CONTEXTE (audit sécurité du 5 juillet 2026, faille HAUTE) :
 *   L'audit a relevé l'absence de plusieurs en-têtes de sécurité HTTP standards.
 *   Plutôt que de dupliquer cette configuration dans les deux vhosts Nginx
 *   (app.bazaart.fr ET la vitrine), on centralise ici, côté Symfony :
 *     - un seul endroit à maintenir, versionné avec le code (donc déployé
 *       automatiquement avec chaque `git pull`, sans repasser par la config Nginx)
 *     - fonctionne de façon identique en dev (Docker) et en prod
 *
 * Pourquoi un EventSubscriber sur kernel.response plutôt qu'un EventListener
 * "bête" ? Ici les deux se ressemblent beaucoup ; on utilise l'attribut
 * #[AsEventListener] (convention déjà en place dans ce projet, voir
 * OnboardingGatingListener) qui s'enregistre nativement sans configuration
 * dans services.yaml — pas besoin d'implémenter EventSubscriberInterface.
 *
 * ⚠️ PRUDENCE : on ne pose JAMAIS un header déjà présent (voir
 * AdminCreatorPayoutController::document() qui pose déjà manuellement
 * X-Content-Type-Options sur ses réponses de téléchargement de documents
 * sensibles). Chaque ajout est protégé par un `!$response->headers->has(...)`.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
class SecurityHeadersSubscriber
{
    public function __invoke(ResponseEvent $event): void
    {
        // On ne traite que la requête "principale" (pas les sous-requêtes internes
        // type ESI/forward), conformément à la pratique standard des listeners Symfony.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;

        // ── X-Content-Type-Options ──────────────────────────────────────────
        // Empêche le navigateur de "deviner" (sniffer) le type MIME d'une
        // ressource différent de celui déclaré par Content-Type. Sans ce header,
        // un fichier uploadé malicieusement renommé (ex: .txt contenant du JS)
        // pourrait être interprété et exécuté comme du HTML/JS par certains
        // navigateurs — vecteur classique de XSS via upload de fichier.
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        // ── X-Frame-Options ──────────────────────────────────────────────────
        // SAMEORIGIN empêche un site tiers malveillant d'embarquer NOS pages
        // dans une <iframe> cachée (clickjacking : ex. superposer un bouton
        // invisible "Valider mon abonnement" sur un site piégé).
        // Ça ne bloque PAS l'inverse : nos propres pages qui embarquent des
        // iframes Bunny Stream / YouTube / Twitch (formations, lives) ne sont
        // pas concernées par ce header — c'est le rôle de `frame-src` dans la
        // CSP ci-dessous de contrôler QUI *nous* avons le droit d'embarquer.
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // ── Referrer-Policy ───────────────────────────────────────────────────
        // strict-origin-when-cross-origin : envoie l'URL complète en referrer
        // pour les requêtes same-origin (utile pour nos propres stats internes),
        // mais seulement l'origine (schéma+domaine, sans le chemin ni la query
        // string) vers un domaine tiers. Évite de fuiter des infos sensibles
        // potentiellement présentes dans l'URL (ex: tokens de reset de mot de
        // passe, identifiants dans une query string) vers un site externe lié
        // depuis nos pages.
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // ── Strict-Transport-Security (HSTS) ─────────────────────────────────
        // Force le navigateur à toujours utiliser HTTPS pour ce domaine pendant
        // 1 an (max-age en secondes), y compris pour les sous-domaines.
        // ⚠️ IMPORTANT : posé UNIQUEMENT si $request->isSecure() est vrai.
        // Raison : en dev local (Docker, http://localhost:8080), la requête
        // n'est PAS sécurisée — poser ce header forcerait quand même le
        // navigateur à mémoriser "toujours HTTPS pour ce host" pendant un an,
        // ce qui casserait l'accès en dev http:// pendant très longtemps
        // (le navigateur refuserait de repasser en http, même après avoir
        // arrêté le header). isSecure() ne vaut true qu'en prod (HTTPS réel,
        // ou HTTP détecté comme sécurisé via X-Forwarded-Proto grâce à
        // trusted_proxies/trusted_headers déjà configurés dans framework.yaml).
        if ($request->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // ── Content-Security-Policy-Report-Only ──────────────────────────────
        // Volontairement en mode REPORT-ONLY (et non le header bloquant
        // `Content-Security-Policy`) : le site utilise beaucoup de scripts et
        // styles inline (blocs <script>/<style> dans les templates, Stimulus,
        // Turbo, matching-form.js, swipe.js). Poser une CSP bloquante du jour
        // au lendemain casserait potentiellement des pages en prod sans qu'on
        // l'ait détecté en amont. Le report-only permet d'OBSERVER (dans la
        // console navigateur, ou via un futur endpoint `report-to`) ce qui
        // serait bloqué, sans rien casser, avant de durcir une future version.
        //
        // Domaines d'iframes trouvés dans le code (grep templates + Entity) :
        //   - iframe.mediadelivery.net (Bunny Stream, formations : course/lesson.html.twig)
        //   - www.youtube.com / youtube-nocookie.com (formations : trailer + leçons)
        //   - player.vimeo.com (prévu dans le TODO de course/lesson.html.twig,
        //     même si non encore branché en base — on l'autorise par anticipation)
        //   - player.twitch.tv (mentionné pour les lives, cf. Live.php / LiveStatus)
        // Les liens Zoom/Google Meet/Jitsi (Live::$streamUrl, Course visio) sont
        // de simples liens externes cliqués par l'utilisateur (<a href>), PAS des
        // <iframe> — ils n'ont donc pas besoin d'être dans frame-src.
        if (!$headers->has('Content-Security-Policy-Report-Only')) {
            $csp = implode('; ', [
                "default-src 'self'",
                // 'unsafe-inline' nécessaire en V1 à cause des nombreux blocs
                // <script>/<style> inline existants — à durcir (nonce/hash) plus tard.
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "img-src 'self' data: https:",
                "font-src 'self' data: https://fonts.gstatic.com",
                "connect-src 'self'",
                "frame-src 'self' https://iframe.mediadelivery.net https://*.mediadelivery.net "
                    . "https://www.youtube.com https://youtube-nocookie.com https://www.youtube-nocookie.com "
                    . "https://player.vimeo.com "
                    . "https://player.twitch.tv https://*.twitch.tv",
            ]);
            $headers->set('Content-Security-Policy-Report-Only', $csp);
        }
    }
}
