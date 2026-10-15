<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * AssociationProjectRichText : texte enrichi « Le projet en détail » des fiches
 * « Projets en cours » (ADR-0039, complément d'octobre 2026).
 *
 * Le back-office envoie du HTML (éditeur maison, sans dépendance). Ce HTML n'est
 * JAMAIS affiché tel quel : il passe par une LISTE BLANCHE stricte, construite
 * comme HtmlSanitizerService::sanitizeRichText() (DOMDocument, extension native de
 * PHP), mais plus large : intertitres, italique, listes numérotées, citations et
 * images. On ne modifie pas HtmlSanitizerService : il sert aux ressources, une
 * autre page.
 *
 * Deux moments :
 *   1. sanitize() à l'ENREGISTREMENT : ne garde que les balises et attributs prévus.
 *      Les images ne gardent PAS d'adresse (src) : seulement leur nom de fichier
 *      dans data-ap-image. L'adresse dépend de qui regarde (back-office ou page
 *      partenaires avec jeton), elle est donc ajoutée à l'affichage.
 *   2. render() à l'AFFICHAGE : nettoie une seconde fois (défense en profondeur :
 *      rien de ce qui sort de la base n'est cru sur parole), ajoute l'adresse de
 *      chaque image et ouvre les liens dans un nouvel onglet.
 *
 * Balises gardées : p, br, strong, em, h2, h3, ul, ol, li, blockquote, a, img.
 * Balises renommées : b → strong, i → em, h1 → h2, h4/h5/h6 → h3, div → p
 * (le texte collé depuis Word ou Google Docs garde ainsi sa structure).
 * Balises supprimées AVEC leur contenu : script, style, iframe, formulaires…
 * Toutes les autres (span, font, table…) disparaissent, leur texte reste.
 */
final class AssociationProjectRichText
{
    /** Longueur maximale du texte VISIBLE (hors balises). */
    public const TEXT_MAX_LENGTH = 50000;

    /**
     * Plafond du HTML reçu, AVANT analyse (garde-fou contre un envoi démesuré,
     * comme dans HtmlSanitizerService). Au-delà, le formulaire est refusé.
     */
    public const INPUT_MAX_LENGTH = 300000;

    /** Nom de fichier d'une image du texte : celui produit par AssociationProjectImageStorage. */
    public const IMAGE_NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    /** Longueur maximale du texte alternatif d'une image (comme celui du visuel). */
    public const ALT_MAX_LENGTH = 200;

    private const KEPT_TAGS = ['p', 'br', 'strong', 'em', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a', 'img'];

    /** @var array<string, string> */
    private const RENAMED_TAGS = [
        'b'   => 'strong',
        'i'   => 'em',
        'h1'  => 'h2',
        'h4'  => 'h3',
        'h5'  => 'h3',
        'h6'  => 'h3',
        'div' => 'p',
    ];

    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math',
        'form', 'input', 'button', 'select', 'textarea', 'head', 'title', 'meta', 'link',
        'video', 'audio', 'canvas', 'frame', 'frameset', 'applet', 'base',
    ];

    /** Blocs : tout le reste est « en ligne » et, au premier niveau, regroupé dans un paragraphe. */
    private const BLOCK_TAGS = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'];

    /** Blocs retirés quand ils sont vides (paragraphes vides laissés par l'éditeur). */
    private const REMOVED_WHEN_EMPTY = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'strong', 'em', 'a'];

    /**
     * Nettoie le HTML envoyé par le formulaire. Renvoie null si, une fois nettoyé,
     * il ne reste ni texte ni image (la section n'est alors pas affichée).
     */
    public function sanitize(?string $html): ?string
    {
        $root = $this->sanitizedTree($html);
        if ($root === null) {
            return null;
        }

        return $this->serialize($root);
    }

    /**
     * HTML prêt à afficher : nettoyé une seconde fois, adresse de chaque image
     * fournie par $imageUrl (nom de fichier → adresse), liens externes en nouvel onglet.
     *
     * $shiftHeadings : sur la fiche, le texte est sous le titre de section « Le projet
     * en détail » (un <h2>) ; ses intertitres deviennent donc <h3> et <h4>, pour que
     * les lecteurs d'écran retrouvent une hiérarchie de titres cohérente.
     *
     * @param callable(string): string $imageUrl
     */
    public function render(?string $html, callable $imageUrl, bool $shiftHeadings = false): ?string
    {
        $root = $this->sanitizedTree($html);
        if ($root === null) {
            return null;
        }

        if ($shiftHeadings) {
            // h3 d'abord, sinon les h2 devenus h3 seraient décalés deux fois.
            foreach ($this->elements($root, 'h3') as $heading) {
                $this->rename($heading, 'h4');
            }
            foreach ($this->elements($root, 'h2') as $heading) {
                $this->rename($heading, 'h3');
            }
        }

        foreach ($this->elements($root, 'img') as $img) {
            $img->setAttribute('src', $imageUrl($img->getAttribute('data-ap-image')));
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('decoding', 'async');
            if (!$img->hasAttribute('alt')) {
                $img->setAttribute('alt', '');
            }
        }
        foreach ($this->elements($root, 'a') as $link) {
            if (preg_match('#^https?://#i', $link->getAttribute('href')) === 1) {
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener noreferrer');
            }
        }

        return $this->serialize($root);
    }

    /**
     * Noms de fichiers des images citées dans le texte (sans doublon).
     * Sert à vérifier qu'une image demandée appartient bien au texte de la fiche,
     * et à effacer du disque les images retirées du texte.
     *
     * @return list<string>
     */
    public function imageNames(?string $html): array
    {
        $root = $this->sanitizedTree($html);
        if ($root === null) {
            return [];
        }

        $names = [];
        foreach ($this->elements($root, 'img') as $img) {
            $names[$img->getAttribute('data-ap-image')] = true;
        }

        return array_keys($names);
    }

    /**
     * Garde uniquement les images dont $keep renvoie vrai (ex. : celles dont le
     * fichier existe dans le dossier de la fiche). Une image collée depuis une
     * autre fiche disparaît ainsi au lieu de pointer vers un fichier qui n'est pas
     * le sien.
     *
     * @param callable(string): bool $keep
     */
    public function filterImages(?string $html, callable $keep): ?string
    {
        $root = $this->sanitizedTree($html);
        if ($root === null) {
            return null;
        }

        foreach ($this->elements($root, 'img') as $img) {
            if (!$keep($img->getAttribute('data-ap-image'))) {
                $img->parentNode?->removeChild($img);
            }
        }
        $this->removeEmptyBlocks($root);

        return $this->serialize($root);
    }

    /** Longueur du texte visible (hors balises), pour la limite du formulaire. */
    public function textLength(?string $html): int
    {
        $root = $this->sanitizedTree($html);
        if ($root === null) {
            return 0;
        }

        return mb_strlen($root->textContent);
    }

    /**
     * Analyse le HTML et applique la liste blanche. Renvoie le conteneur technique
     * (<div>) dont les enfants forment le texte nettoyé, ou null s'il ne reste rien.
     */
    private function sanitizedTree(?string $html): ?\DOMElement
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }
        // Le formulaire refuse déjà au-delà (AssociationProjectData) ; ceci protège
        // les autres appels (relecture en base).
        if (strlen($html) > self::INPUT_MAX_LENGTH * 4) {
            $html = mb_substr($html, 0, self::INPUT_MAX_LENGTH);
        }

        // Même astuce que HtmlSanitizerService : un prologue XML factice force
        // libxml à lire le texte en UTF-8 (sinon « é » devient « Ã© »).
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return null;
        }

        $this->cleanChildren($root);
        $this->wrapLooseInline($root);
        $this->removeEmptyBlocks($root);

        if ($this->elements($root, 'img') === [] && self::isBlank($root->textContent)) {
            return null;
        }

        return $root;
    }

    private function cleanChildren(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                // Commentaires, instructions de traitement… : retirés.
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            // Un <div> qui contient déjà des blocs (paragraphes, listes…) est un simple
            // conteneur : on le retire. Sinon c'est un paragraphe (Google Docs, Word).
            if ($tag === 'div' && $this->containsBlock($child)) {
                $this->cleanChildren($child);
                $this->unwrap($child);
                continue;
            }
            // Élément de liste hors d'une liste : devient un paragraphe.
            if ($tag === 'li' && !in_array(strtolower($node->nodeName), ['ul', 'ol'], true)) {
                $child = $this->rename($child, 'p');
                $tag = 'p';
            }
            if (isset(self::RENAMED_TAGS[$tag])) {
                $child = $this->rename($child, self::RENAMED_TAGS[$tag]);
                $tag = self::RENAMED_TAGS[$tag];
            }

            if (!in_array($tag, self::KEPT_TAGS, true)) {
                // Balise inconnue mais inoffensive : on la retire en gardant son contenu.
                $this->cleanChildren($child);
                $this->unwrap($child);
                continue;
            }

            if (!$this->cleanAttributes($child, $tag)) {
                // Lien sans adresse acceptable : on garde son texte, pas le lien.
                // Image sans nom de fichier valable : retirée.
                if ($tag === 'a') {
                    $this->cleanChildren($child);
                    $this->unwrap($child);
                } else {
                    $node->removeChild($child);
                }
                continue;
            }

            if ($tag === 'img' || $tag === 'br') {
                // Balises vides : rien à l'intérieur.
                while ($child->firstChild !== null) {
                    $child->removeChild($child->firstChild);
                }
                continue;
            }

            $this->cleanChildren($child);
        }
    }

    /**
     * Ne garde que les attributs prévus. Renvoie false si la balise doit
     * disparaître faute d'attribut valable (lien sans adresse sûre, image sans nom).
     */
    private function cleanAttributes(\DOMElement $element, string $tag): bool
    {
        $kept = [];
        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));
            // Liens web et e-mail seulement : ni javascript:, ni data:, ni adresse relative.
            if ($href === '' || preg_match('#^(https?://|mailto:)#i', $href) !== 1) {
                return false;
            }
            $kept['href'] = $href;
        }
        if ($tag === 'img') {
            $name = $element->getAttribute('data-ap-image');
            if (preg_match(self::IMAGE_NAME_PATTERN, $name) !== 1) {
                return false;
            }
            $kept['data-ap-image'] = $name;
            $kept['alt'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $element->getAttribute('alt')) ?? ''), 0, self::ALT_MAX_LENGTH);
            foreach (['width', 'height'] as $dimension) {
                $value = $element->getAttribute($dimension);
                if (preg_match('/^\d{1,5}$/', $value) === 1 && (int) $value >= 1 && (int) $value <= 10000) {
                    $kept[$dimension] = (string) (int) $value;
                }
            }
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->nodeName);
        }
        foreach ($kept as $name => $value) {
            $element->setAttribute($name, $value);
        }

        return true;
    }

    /** Retire les blocs vides (sans texte ni image), en partant des plus profonds. */
    private function removeEmptyBlocks(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $this->removeEmptyBlocks($child);

            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::REMOVED_WHEN_EMPTY, true)) {
                continue;
            }
            if ($child->getElementsByTagName('img')->length === 0 && self::isBlank($child->textContent)) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Au premier niveau, le texte et les balises « en ligne » (gras, lien, image…)
     * qui ne sont dans aucun bloc sont regroupés dans un paragraphe : la mise en
     * page de l'article (marges entre paragraphes) s'applique alors partout.
     */
    private function wrapLooseInline(\DOMElement $root): void
    {
        $document = $root->ownerDocument;
        if ($document === null) {
            return;
        }
        $paragraph = null;
        foreach (iterator_to_array($root->childNodes) as $child) {
            $isBlock = $child instanceof \DOMElement && in_array(strtolower($child->tagName), self::BLOCK_TAGS, true);
            if ($isBlock) {
                $paragraph = null;
                continue;
            }
            if ($paragraph === null) {
                if ($child instanceof \DOMText && self::isBlank($child->data)) {
                    // Espaces entre deux blocs : inutiles.
                    $root->removeChild($child);
                    continue;
                }
                $paragraph = $document->createElement('p');
                $root->insertBefore($paragraph, $child);
            }
            $paragraph->appendChild($child);
        }
    }

    private function containsBlock(\DOMElement $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array(strtolower($descendant->tagName), [...self::BLOCK_TAGS, 'div', 'h1', 'h4', 'h5', 'h6'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Vide, ou seulement des espaces (espaces insécables compris). */
    private static function isBlank(string $text): bool
    {
        return preg_replace('/[\s\x{A0}]+/u', '', $text) === '';
    }

    private function rename(\DOMElement $element, string $newTag): \DOMElement
    {
        $document = $element->ownerDocument;
        if ($document === null || $element->parentNode === null) {
            return $element;
        }
        $replacement = $document->createElement($newTag);
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $replacement->setAttribute($attribute->nodeName, (string) $attribute->nodeValue);
        }
        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }
        $element->parentNode->replaceChild($replacement, $element);

        return $replacement;
    }

    private function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMElement $root, string $tag): array
    {
        $found = [];
        foreach ($root->getElementsByTagName($tag) as $element) {
            $found[] = $element;
        }

        return $found;
    }

    private function serialize(\DOMElement $root): ?string
    {
        $document = $root->ownerDocument;
        if ($document === null) {
            return null;
        }
        $html = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $html .= $document->saveHTML($child);
        }
        $html = trim($html);

        return $html !== '' ? $html : null;
    }
}
