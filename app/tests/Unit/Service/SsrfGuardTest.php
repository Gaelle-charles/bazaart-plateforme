<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * SsrfGuardTest — Tests unitaires de SsrfGuard::isSafeHost().
 *
 * ── POURQUOI CES TESTS ? ─────────────────────────────────────────────────────
 * SsrfGuard est le garde-fou anti-SSRF utilisé par tous les services qui font
 * des requêtes HTTP vers des URLs fournies par un tiers (LLM, HTML scrapé, CSV
 * externe) : ListingUrlDiscoverer, FeedDetectorService,
 * SuggestedSourceAutoValidationService, LogoFetcherService, GenericScraper,
 * OpportunityEnrichmentService. Une régression ici ouvrirait une faille SSRF
 * critique — capacité de faire requêter par le serveur des ressources internes
 * (localhost, métadonnées cloud, réseau privé Docker) via une URL forgée.
 *
 * Ces tests couvrent les cas explicitement demandés en relecture (correctif
 * critique ADR-0034) : localhost, loopback IPv4, métadonnées cloud
 * (169.254.169.254), et les trois plages RFC1918, ainsi qu'un cas nominal
 * (URL publique valide) pour vérifier l'absence de faux positif.
 *
 * Classe testée : App\Service\SsrfGuard::isSafeHost()
 * Type de test  : Unitaire pur (aucune dépendance, aucun réseau, aucune BDD)
 */
class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    /**
     * Cas nominal : une URL publique valide (http ou https) doit être acceptée.
     */
    public function testAcceptsPublicHttpsUrl(): void
    {
        $this->assertTrue($this->guard->isSafeHost('https://www.africanartists.org/feed'));
    }

    /**
     * Cas nominal bis : http:// est également accepté (pas seulement https://).
     */
    public function testAcceptsPublicHttpUrl(): void
    {
        $this->assertTrue($this->guard->isSafeHost('http://www.africanartists.org/feed'));
    }

    /**
     * "localhost" doit être bloqué, quel que soit le chemin ou le port.
     */
    public function testRejectsLocalhost(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://localhost'));
        $this->assertFalse($this->guard->isSafeHost('http://localhost/admin'));
        $this->assertFalse($this->guard->isSafeHost('http://localhost:8080/feed'));
    }

    /**
     * 127.0.0.1 (loopback IPv4) doit être bloqué — vecteur SSRF classique
     * pour atteindre des services internes non exposés publiquement.
     */
    public function testRejectsLoopbackIpv4(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://127.0.0.1'));
        $this->assertFalse($this->guard->isSafeHost('http://127.0.0.1/feed.xml'));
    }

    /**
     * 169.254.169.254 : adresse de métadonnées cloud (AWS, DigitalOcean, GCP,
     * Azure). C'est LE vecteur SSRF le plus critique sur un droplet DigitalOcean
     * (exfiltration de clés API / credentials via l'API de métadonnées interne).
     */
    public function testRejectsCloudMetadataAddress(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://169.254.169.254'));
        $this->assertFalse($this->guard->isSafeHost('http://169.254.169.254/latest/meta-data/'));
    }

    /**
     * 10.0.0.0/8 — RFC1918 classe A (réseau privé).
     */
    public function testRejectsRfc1918ClassA(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://10.0.0.1'));
    }

    /**
     * 172.16.0.0/12 — RFC1918 classe B (réseau privé, utilisé par défaut par Docker).
     */
    public function testRejectsRfc1918ClassB(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://172.17.0.1'));
    }

    /**
     * 192.168.0.0/16 — RFC1918 classe C (réseau privé).
     */
    public function testRejectsRfc1918ClassC(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://192.168.1.1'));
    }

    /**
     * Les schémas non-http(s) doivent être bloqués (file://, ftp://, data://...)
     * pour empêcher la lecture de fichiers locaux ou d'autres protocoles dangereux.
     */
    public function testRejectsNonHttpSchemes(): void
    {
        $this->assertFalse($this->guard->isSafeHost('file:///etc/passwd'));
        $this->assertFalse($this->guard->isSafeHost('ftp://example.com/file'));
        $this->assertFalse($this->guard->isSafeHost('data://text/plain;base64,SGVsbG8='));
    }

    /**
     * Une URL sans hôte ou totalement malformée doit être rejetée par précaution.
     */
    public function testRejectsMalformedOrHostlessUrl(): void
    {
        $this->assertFalse($this->guard->isSafeHost(''));
        $this->assertFalse($this->guard->isSafeHost('not-a-url'));
    }

    /**
     * ::1 (loopback IPv6) doit être bloqué au même titre que 127.0.0.1.
     */
    public function testRejectsIpv6Loopback(): void
    {
        $this->assertFalse($this->guard->isSafeHost('http://[::1]'));
    }
}
