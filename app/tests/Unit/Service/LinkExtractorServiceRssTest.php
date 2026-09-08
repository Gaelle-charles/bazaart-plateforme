<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LinkExtractorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * LinkExtractorServiceRssTest — Tests unitaires du support RSS/Atom (ADR-0036, point A).
 *
 * ── POURQUOI CE TEST ? ───────────────────────────────────────────────────────
 * Avant ADR-0036, extractAndFilter() utilisait DomCrawler sur le contenu brut
 * de la page, ce qui ne trouve AUCUN <a href> sur un flux RSS/Atom (les liens
 * utiles y sont dans le TEXTE des <description>/<content:encoded>, souvent
 * échappés en entités HTML). Ce test vérifie que :
 *   1. Un flux RSS 2.0 est détecté et ses items sont bien parsés.
 *   2. Les liens présents dans <description> (échappés en entités HTML) sont
 *      extraits même s'ils ne sont pas dans une balise <a> brute du flux XML.
 *   3. Un flux Atom est également supporté.
 *   4. Un flux malformé ne lève jamais d'exception (retourne []).
 *   5. filterCandidates() applique bien le pipeline de filtrage (bruit, dédup,
 *      domaines connus, plafond) à une liste de candidats déjà construite,
 *      indépendamment de tout HTML/flux (réutilisation par le gisement
 *      "opportunités déjà collectées", ADR-0036 point B).
 *
 * Classe testée : App\Service\LinkExtractorService
 * Type de test  : Unitaire (aucune dépendance réseau — flux fournis en dur)
 */
class LinkExtractorServiceRssTest extends TestCase
{
    private LinkExtractorService $service;

    protected function setUp(): void
    {
        $this->service = new LinkExtractorService(new NullLogger());
    }

    /**
     * Un flux RSS 2.0 dont la <description> contient un lien HTML échappé en
     * entités doit produire un candidat exploitable après extractAndFilter().
     */
    public function testRssFeedWithEscapedLinkInDescriptionIsExtracted(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
    <title>Agrégateur Test</title>
    <link>https://aggregator.example.org</link>
    <description>Flux de test</description>
    <item>
        <title>Bourse Fondation Exemple 2026</title>
        <link>https://aggregator.example.org/actu/bourse-fondation-exemple</link>
        <description>&lt;p&gt;La &lt;a href="https://fondation-exemple.org/bourses"&gt;Fondation Exemple&lt;/a&gt; ouvre ses candidatures.&lt;/p&gt;</description>
    </item>
</channel>
</rss>
XML;

        $candidates = $this->service->extractAndFilter($rss, 'https://aggregator.example.org/feed/', []);

        $urls = array_column($candidates, 'url');
        $this->assertContains(
            'https://fondation-exemple.org/bourses',
            $urls,
            'Le lien externe échappé dans <description> doit être extrait du flux RSS.'
        );

        // Le <link> de l'item pointe vers le domaine de l'agrégateur lui-même :
        // il doit être filtré par filterInternalLinks() (même domaine que la page).
        $this->assertNotContains(
            'https://aggregator.example.org/actu/bourse-fondation-exemple',
            $urls,
            'Le <link> interne à l\'agrégateur ne doit pas être proposé comme candidat externe.'
        );
    }

    /**
     * content:encoded (namespace "content") doit également être exploré, pas
     * seulement <description> — certains flux WordPress y mettent le HTML complet.
     */
    public function testRssFeedWithContentEncodedIsExtracted(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
    <title>Agrégateur Test</title>
    <link>https://aggregator.example.org</link>
    <item>
        <title>Résidence Villa Exemple</title>
        <link>https://aggregator.example.org/actu/residence-villa</link>
        <description>Résumé court sans lien.</description>
        <content:encoded><![CDATA[<p>Candidatez sur <a href="https://villa-exemple.org/candidater">le site de la Villa Exemple</a>.</p>]]></content:encoded>
    </item>
</channel>
</rss>
XML;

        $candidates = $this->service->extractAndFilter($rss, 'https://aggregator.example.org/feed/', []);
        $urls = array_column($candidates, 'url');

        $this->assertContains('https://villa-exemple.org/candidater', $urls);
    }

    /**
     * Un flux Atom (structure différente du RSS 2.0 : <entry>, <link href="...">)
     * doit également être supporté.
     */
    public function testAtomFeedIsExtracted(): void
    {
        $atom = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>Agrégateur Atom Test</title>
    <link href="https://aggregator-atom.example.org" rel="alternate" />
    <entry>
        <title>Appel à projets Fonds Exemple</title>
        <link href="https://aggregator-atom.example.org/entries/1" rel="alternate" />
        <summary>Découvrez le &lt;a href="https://fonds-exemple.org/appel"&gt;Fonds Exemple&lt;/a&gt;.</summary>
    </entry>
</feed>
XML;

        $candidates = $this->service->extractAndFilter($atom, 'https://aggregator-atom.example.org/feed/', []);
        $urls = array_column($candidates, 'url');

        $this->assertContains('https://fonds-exemple.org/appel', $urls);
    }

    /**
     * Un flux RSS totalement malformé (XML invalide) ne doit jamais lever
     * d'exception — extractAndFilter() doit retourner un tableau (vide ou non),
     * jamais planter la commande appelante.
     */
    public function testMalformedFeedDoesNotThrow(): void
    {
        $malformed = "<?xml version=\"1.0\"?>\n<rss version=\"2.0\"><channel><title>Cassé<item><title>Oups";

        $candidates = $this->service->extractAndFilter($malformed, 'https://broken.example.org/feed/', []);

        $this->assertIsArray($candidates);
    }

    /**
     * Un contenu HTML classique (pas un flux) continue de fonctionner comme avant
     * — non-régression du chemin DomCrawler existant.
     */
    public function testRegularHtmlStillWorksAsBefore(): void
    {
        $html = '<html><body>'
            . '<a href="https://autre-site.example.org/page">Un organisme externe</a>'
            . '<a href="/interne">Page interne</a>'
            . '</body></html>';

        $candidates = $this->service->extractAndFilter($html, 'https://aggregator.example.org/page', []);
        $urls = array_column($candidates, 'url');

        $this->assertContains('https://autre-site.example.org/page', $urls);
        $this->assertNotContains('https://aggregator.example.org/interne', $urls);
    }

    /**
     * filterCandidates() (ADR-0036, point B) doit appliquer le pipeline de
     * filtrage (bruit, dédup par domaine, domaines connus, plafond) à une liste
     * de candidats déjà construite — sans dépendre d'un HTML/flux source, et
     * sans filtrage "interne" quand aucun host de référence n'est fourni.
     */
    public function testFilterCandidatesAppliesCommonPipelineWithoutBaseHost(): void
    {
        $candidates = [
            ['text' => 'Fondation A', 'url' => 'https://fondation-a.example.org/appel'],
            ['text' => 'Réseau social (bruit)', 'url' => 'https://www.facebook.com/fondation-a'],
            ['text' => 'Déjà connu', 'url' => 'https://connu.example.org/page'],
        ];

        $filtered = $this->service->filterCandidates($candidates, '', ['connu.example.org']);
        $urls = array_column($filtered, 'url');

        $this->assertContains('https://fondation-a.example.org/appel', $urls);
        $this->assertNotContains('https://www.facebook.com/fondation-a', $urls, 'Le bruit (réseaux sociaux) doit être filtré.');
        $this->assertNotContains('https://connu.example.org/page', $urls, 'Un domaine déjà connu doit être filtré.');
    }
}
