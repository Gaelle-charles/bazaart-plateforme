<?php

declare(strict_types=1);

namespace App\Service;

/**
 * HtmlSanitizerService — Nettoyage du HTML provenant de flux RSS/Atom externes.
 *
 * Ce service est intentionnellement MINIMALISTE : il n'utilise aucune dépendance
 * externe, ne fait aucun appel réseau, et n'autorise aucun tag HTML en sortie.
 *
 * ── POURQUOI strip TOTAL des tags ? ──────────────────────────────────────────
 * Les flux RSS proviennent de sites tiers dont on ne contrôle pas le contenu.
 * Un flux malveillant ou mal configuré pourrait injecter :
 *   - des balises <script> → exécution de code JavaScript dans un contexte admin
 *   - des balises <img> avec src externe → tracking pixel, SSRF
 *   - des balises <a href="javascript:..."> → XSS
 *
 * En V1, la description n'est PAS un champ affiché tel quel dans Twig —
 * elle passe d'abord dans ce service, puis dans l'admin (contexte déjà protégé).
 * Mais surtout, elle peut alimenter un prompt LLM (LlmExtractorService).
 * Injecter du HTML brut dans un prompt = risque de prompt injection.
 * Le strip total est la seule garantie robuste.
 *
 * ── POURQUOI la troncature à 2000 caractères ? ──────────────────────────────
 * La colonne `description` de scraped_resources est un TEXT (illimité en SQL),
 * MAIS les descriptions de flux RSS ne valent pas la peine d'être stockées en
 * intégralité (elles peuvent atteindre plusieurs milliers de caractères de HTML
 * qui, une fois strippé, devient moins long mais reste parfois verbeux).
 * De plus, si la description est passée à un LLM (Mistral ou Anthropic),
 * chaque token coûte. Tronquer à 2000 caractères limite le coût API tout
 * en conservant l'essentiel du contenu informatif.
 *
 * ── CLASSE vs INTERFACE ? ────────────────────────────────────────────────────
 * On crée une classe concrète directement (pas d'interface) car :
 *   - Ce comportement n'a pas de variante : un strip total ne se remplace pas
 *     par un autre strip total avec des paramètres différents.
 *   - Le composant symfony/html-sanitizer (qui aurait une interface) est
 *     explicitement écarté (décision A1 du brief) pour garder une dépendance légère.
 */
class HtmlSanitizerService
{
    /**
     * Nettoie une chaîne HTML provenant d'une source externe.
     *
     * Étapes appliquées dans l'ordre :
     *   1. strip_tags()         → supprime TOUS les tags HTML (aucun autorisé)
     *   2. html_entity_decode() → décode &amp; → &, &lt; → <, &rsquo; → ', etc.
     *   3. Normalisation des espaces → trim + collapse des espaces multiples/sauts de ligne
     *   4. mb_substr()          → troncature multi-octets à $maxLength caractères
     *
     * @param string $html      Le HTML brut à nettoyer (peut être vide)
     * @param int    $maxLength Longueur maximale de la chaîne résultante (défaut 2000)
     *
     * @return string Le texte nettoyé, sans aucun tag HTML, prêt pour la BDD ou un LLM
     */
    public function sanitize(string $html, int $maxLength = 2000): string
    {
        // ── Étape 1 : suppression TOTALE des tags HTML ───────────────────────
        // strip_tags() sans second argument = aucun tag autorisé.
        // Contrairement à htmlspecialchars_decode(), strip_tags() supprime AUSSI
        // les attributs (href, onclick, src...) qui pourraient contenir du code.
        $text = strip_tags($html);

        // ── Étape 2 : décodage des entités HTML ──────────────────────────────
        // Après strip_tags(), le texte peut encore contenir des entités encodées :
        // "&amp;" au lieu de "&", "&lt;" au lieu de "<", "&rsquo;" au lieu de "'"
        // Ces entités viennent du HTML original et doivent être converties en
        // caractères lisibles pour un stockage propre en BDD.
        //
        // ENT_QUOTES : décode les apostrophes (&apos;, &#39;) et guillemets (&quot;)
        // ENT_HTML5  : prend en charge toutes les entités nommées HTML5 (&rsquo;, &ldquo;...)
        // 'UTF-8'    : obligatoire pour les caractères multi-octets (é, à, ç, etc.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // ── Étape 3 : normalisation des espaces ──────────────────────────────
        // strip_tags() laisse les sauts de ligne (<br> → \n) et les espaces
        // multiples qui encadraient les tags.
        // On normalise : sauts de ligne et tabulations → espace, puis collapse.
        //
        // Exemple avant : "  Titre\n  \n  Corps du texte   "
        // Exemple après : "Titre Corps du texte"
        //
        // preg_replace() en sécurité : /\s+/ avec le flag Unicode \s couvre
        // l'espace, \t, \n, \r, \f, \v — ce qui correspond à tous les séparateurs
        // qu'un flux RSS mal formaté peut injecter.
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        // ── Étape 4 : troncature multi-octets ───────────────────────────────
        // On utilise mb_substr() plutôt que substr() pour éviter de couper au
        // milieu d'un caractère multi-octets (ex: "é" = 2 octets en UTF-8).
        // substr("élan", 0, 1) retournerait un byte invalide → caractère corrompu.
        // mb_substr("élan", 0, 1) retourne correctement "é".
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }

        return $text;
    }
}
