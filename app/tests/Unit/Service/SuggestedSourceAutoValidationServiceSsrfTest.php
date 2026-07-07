<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SuggestedSource;
use App\Enum\ScrapingSourceType;
use App\Enum\SuggestedSourceStatus;
use App\Repository\ScrapingSourceRepository;
use App\Service\FeedDetectorService;
use App\Service\SsrfGuard;
use App\Service\SuggestedSourceAutoValidationService;
use App\Service\SuggestedSourcePromotionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SuggestedSourceAutoValidationServiceSsrfTest — Tests unitaires du garde SSRF
 * ajouté en correctif critique (relecture ADR-0034).
 *
 * ── POURQUOI CE TEST ? ───────────────────────────────────────────────────────
 * Avant ce correctif, tryAutoValidate() passait l'URL d'une SuggestedSource
 * (extraite d'un HTML tiers par le LLM, JAMAIS revue par un humain) directement
 * à FeedDetectorService::detect() sans aucune vérification d'hôte. Une URL
 * interne (localhost, IP privée, métadonnées cloud) aurait pu déclencher un
 * fetch réseau interne et, dans le pire cas, la création automatique d'une
 * ScrapingSource active fetchée toutes les 6h par le cron.
 *
 * Ce test vérifie que :
 *   1. FeedDetectorService::detect() n'est JAMAIS appelé pour une URL non sûre
 *      (garde AVANT tout appel réseau, pas juste un filtrage a posteriori).
 *   2. Le résultat retourné est RESULT_MANUAL (pas de blocage silencieux — la
 *      suggestion reste visible pour un humain sur /admin/suggested-sources).
 *   3. Aucune ScrapingSource n'est créée (createScrapingSourceFromSuggestion
 *      n'est jamais appelé).
 *
 * Classe testée : App\Service\SuggestedSourceAutoValidationService::tryAutoValidate()
 * Type de test  : Unitaire (FeedDetectorService, ScrapingSourceRepository et
 *                 SuggestedSourcePromotionService sont mockés — aucun réseau,
 *                 aucune BDD).
 */
class SuggestedSourceAutoValidationServiceSsrfTest extends TestCase
{
    /**
     * Construit une SuggestedSource minimale pour les tests, avec l'URL donnée.
     */
    private function buildSuggestion(string $url): SuggestedSource
    {
        $suggestion = new SuggestedSource();
        $suggestion->setNomOrganisme('Organisme de test');
        $suggestion->setUrl($url);

        return $suggestion;
    }

    /**
     * @return array<string, array{0: string}> URLs internes/non sûres à tester
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            'localhost'          => ['http://localhost/feed'],
            'loopback IPv4'      => ['http://127.0.0.1/feed'],
            'métadonnées cloud'  => ['http://169.254.169.254/latest/meta-data/'],
            'RFC1918 classe A'   => ['http://10.0.0.1/feed'],
            'RFC1918 classe C'   => ['http://192.168.1.1/feed'],
        ];
    }

    #[DataProvider('unsafeUrlProvider')]
    public function testUnsafeUrlIsRejectedBeforeFeedDetectorIsCalled(string $unsafeUrl): void
    {
        $suggestion = $this->buildSuggestion($unsafeUrl);

        // FeedDetectorService NE DOIT JAMAIS être appelé — la garde SSRF doit
        // intercepter l'URL avant tout fetch réseau.
        $feedDetector = $this->createMock(FeedDetectorService::class);
        $feedDetector->expects($this->never())->method('detect');

        // Idem : aucune promotion en ScrapingSource ne doit avoir lieu.
        $promotionService = $this->createMock(SuggestedSourcePromotionService::class);
        $promotionService->expects($this->never())->method('createScrapingSourceFromSuggestion');

        $scrapingSourceRepository = $this->createStub(ScrapingSourceRepository::class);

        $service = new SuggestedSourceAutoValidationService(
            $feedDetector,
            $scrapingSourceRepository,
            $promotionService,
            new SsrfGuard(), // vraie instance : c'est le garde qu'on teste
            new NullLogger(),
        );

        $result = $service->tryAutoValidate($suggestion);

        $this->assertSame(SuggestedSourceAutoValidationService::RESULT_MANUAL, $result);

        // La suggestion reste visible pour un humain : typePressenti est renseigné
        // à HtmlLlm (un hôte non sûr ne peut jamais être auto-validé en RSS), mais
        // le statut n'est PAS modifié par ce chemin (reste au défaut de l'entité :
        // AValider — cf. constructeur SuggestedSource).
        $this->assertSame(ScrapingSourceType::HtmlLlm->value, $suggestion->getTypePressenti());
        $this->assertSame(SuggestedSourceStatus::AValider, $suggestion->getStatut());
    }

    /**
     * Cas de contrôle : une URL publique sûre doit bien passer la garde et
     * atteindre FeedDetectorService::detect() (comportement inchangé).
     */
    public function testSafeUrlReachesFeedDetector(): void
    {
        $suggestion = $this->buildSuggestion('https://www.africanartists.org/feed');

        $feedDetector = $this->createMock(FeedDetectorService::class);
        $feedDetector->expects($this->once())
            ->method('detect')
            ->with('https://www.africanartists.org/feed')
            ->willReturn(['type' => 'html_llm', 'feed_url' => null, 'message' => 'ok']);

        $promotionService = $this->createMock(SuggestedSourcePromotionService::class);
        $promotionService->expects($this->never())->method('createScrapingSourceFromSuggestion');

        $scrapingSourceRepository = $this->createStub(ScrapingSourceRepository::class);

        $service = new SuggestedSourceAutoValidationService(
            $feedDetector,
            $scrapingSourceRepository,
            $promotionService,
            new SsrfGuard(),
            new NullLogger(),
        );

        $result = $service->tryAutoValidate($suggestion);

        $this->assertSame(SuggestedSourceAutoValidationService::RESULT_MANUAL, $result);
    }
}
