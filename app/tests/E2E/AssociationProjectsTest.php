<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\AssociationProjectPublicController;
use App\Entity\AssociationProject;
use App\Entity\AssociationProjectBlock;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use App\Service\AssociationProject\StoredImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RouterInterface;

/**
 * AssociationProjectsTest : page « Projets en cours » de l'association (ADR-0039).
 *
 * Couvre les exigences de l'association :
 *   - accès SANS connexion, protégé par le jeton secret de l'adresse ;
 *   - jeton absent ou faux → 404 (pas 403), sur la page, la fiche, l'API et les images ;
 *   - une fiche NON publiée n'apparaît nulle part (page, fiche, API, visuel, image de bloc),
 *     y compris en passant par l'adresse d'un projet publié ;
 *   - l'API ne renvoie ni dates de création ni dates de mise à jour ;
 *   - en-têtes X-Robots-Tag et Referrer-Policy, balise meta robots ;
 *   - état vide, échappement du HTML saisi ;
 *   - back-office réservé à ROLE_ADMIN (création, validation, aperçu) ;
 *   - aucune route privée sous /projets/ (règle PUBLIC_ACCESS de security.yaml),
 *     aucun lien vers la page dans le reste du site.
 *
 * Le jeton de test est défini dans .env.test (PROJETS_PARTENAIRES_TOKEN).
 */
class AssociationProjectsTest extends AbstractE2ETestCase
{
    private const TOKEN = 'jeton-de-test-projets-association-0123456789abcdef';

    /** PNG de 1 × 1 pixel : une vraie image, minuscule, pour les tests de visuels. */
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private AssociationProject $published;
    private AssociationProject $draft;
    private AssociationProjectBlock $publishedImageBlock;
    private AssociationProjectBlock $draftImageBlock;

    /** @var list<string> fichiers créés pendant le test (effacés à la fin) */
    private array $storedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();

        // Une fiche PUBLIÉE avec visuel, texte piégé et deux blocs (texte, image).
        $this->published = $this->createProject('An Ba Lanmè, chapitre 2', 'an-ba-lanme-chapitre-2', true, 10);
        $this->published
            ->setDescription('Résidence de création.')
            ->setPartnerContribution("Un lieu d'accueil\n<script>alert(1)</script>")
            ->setLeadName('Prénom Nom');
        $this->attachImage($this->published);
        $textBlock = new AssociationProjectBlock($this->published);
        $textBlock->setHeading('Le projet')->setBody("Paragraphe un.\n\nParagraphe deux.")->setPosition(10);
        $this->em->persist($textBlock);
        $this->publishedImageBlock = $this->createImageBlock($this->published, 20);

        // Une fiche NON PUBLIÉE, elle aussi avec visuel et bloc image.
        $this->draft = $this->createProject('Média BazaArt', 'media-bazaart', false, 20);
        $this->attachImage($this->draft);
        $this->draftImageBlock = $this->createImageBlock($this->draft, 10);

        $this->em->flush();
        // On vide la mémoire de Doctrine : chaque requête du test relit la base,
        // comme une vraie requête HTTP (sinon les fiches restées en mémoire
        // n'auraient pas leurs blocs, ajoutés après coup).
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $storage = static::getContainer()->get(AssociationProjectImageStorage::class);
        foreach ($this->storedFiles as $file) {
            $storage->delete($file);
        }
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Jeton : absent ou faux → 404
    // ═════════════════════════════════════════════════════════════════════

    public function testWrongTokenReturns404Everywhere(): void
    {
        $slug = $this->published->getSlug();
        foreach ([
            '/projets/mauvais-jeton',
            '/projets/mauvais-jeton/api',
            '/projets/mauvais-jeton/' . $slug,
            '/projets/mauvais-jeton/' . $slug . '/visuel',
            '/projets/mauvais-jeton/' . $slug . '/image/' . $this->publishedImageBlock->getId(),
            '/projets/' . strtoupper(self::TOKEN),   // la casse compte
            '/projets/' . self::TOKEN . 'x',         // un caractère de trop
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testMissingTokenReturns404(): void
    {
        foreach (['/projets', '/projets/'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Page : sans connexion, publiés seulement, en-têtes
    // ═════════════════════════════════════════════════════════════════════

    public function testPageIsVisibleWithoutLoginAndListsOnlyPublishedProjects(): void
    {
        $crawler = $this->client->request('GET', '/projets/' . self::TOKEN);

        // 200 directement : pas de redirection vers /login, aucun compte nécessaire.
        self::assertResponseStatusCodeSame(200);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('An Ba Lanmè, chapitre 2', $html);
        self::assertStringNotContainsString('Média BazaArt', $html, 'une fiche non publiée ne doit pas apparaître');
        self::assertCount(1, $crawler->filter('.ap-card'));

        $this->assertPrivacyHeaders();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('no-referrer', $crawler->filter('meta[name="referrer"]')->attr('content'));
        // Page autonome : ni navigation du site, ni bandeau cookies (donc ni Google Analytics).
        self::assertCount(0, $crawler->filter('nav.nav, #cookie-banner, #bzrt-preloader'));
        // Aucune session ouverte pour un visiteur : pas de cookie.
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    public function testEmptyStateWhenNothingIsPublished(): void
    {
        $published = $this->em->find(AssociationProject::class, $this->published->getId());
        self::assertNotNull($published);
        $published->setIsPublished(false);
        $this->em->flush();

        $this->client->request('GET', '/projets/' . self::TOKEN);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ap-empty', "Aucun projet n'est présenté pour le moment.");

        $this->client->request('GET', '/projets/' . self::TOKEN . '/api');
        self::assertSame([], $this->apiJson()['projects']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // API
    // ═════════════════════════════════════════════════════════════════════

    public function testApiReturnsOnlyPublishedProjectsWithoutTimestamps(): void
    {
        $this->client->request('GET', '/projets/' . self::TOKEN . '/api');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertPrivacyHeaders();

        $data = $this->apiJson();
        self::assertSame(['An Ba Lanmè, chapitre 2'], array_column($data['projects'], 'title'));
        self::assertSame(AssociationProjectPublicController::CONTACT_EMAIL, $data['contact']);

        // Aucune date technique, aucun identifiant interne, aucun chemin de stockage, nulle part.
        $keys = $this->allKeys($data);
        foreach (['createdAt', 'updatedAt', 'created_at', 'updated_at', 'id', 'isPublished', 'imagePath'] as $forbidden) {
            self::assertNotContains($forbidden, $keys, $forbidden . ' ne doit pas sortir dans l\'API');
        }
        self::assertCount(2, $data['projects'][0]['blocks']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Fiches et images non publiées
    // ═════════════════════════════════════════════════════════════════════

    public function testUnpublishedProjectIsNotReachable(): void
    {
        $base = '/projets/' . self::TOKEN . '/' . $this->draft->getSlug();
        foreach ([$base, $base . '/visuel', $base . '/image/' . $this->draftImageBlock->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testDraftBlockImageCannotLeakThroughAPublishedSlug(): void
    {
        // Adresse d'un projet PUBLIÉ + numéro d'un bloc d'un projet NON publié : 404.
        $this->client->request('GET', '/projets/' . self::TOKEN . '/' . $this->published->getSlug() . '/image/' . $this->draftImageBlock->getId());
        self::assertResponseStatusCodeSame(404);

        // Le bloc image du projet publié, lui, est bien servi.
        $this->client->request('GET', '/projets/' . self::TOKEN . '/' . $this->published->getSlug() . '/image/' . $this->publishedImageBlock->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        $this->assertPrivacyHeaders();
    }

    public function testPublishedFicheShowsSectionsAndEscapesHtml(): void
    {
        $crawler = $this->client->request('GET', '/projets/' . self::TOKEN . '/' . $this->published->getSlug());

        self::assertResponseIsSuccessful();
        $this->assertPrivacyHeaders();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertSelectorTextContains('.ap-contribution__title', "Ce qu'un partenaire peut apporter");
        self::assertSelectorTextContains('.ap-contact', 'bonjourbazaart@gmail.com');
        self::assertSelectorTextContains('.ap-contact', 'Prénom Nom');
        self::assertCount(2, $crawler->filter('.ap-block__text p'), 'une ligne vide sépare deux paragraphes');
        self::assertStringEndsWith('#projet-an-ba-lanme-chapitre-2', (string) $crawler->filter('.ap-back')->attr('href'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Back-office
    // ═════════════════════════════════════════════════════════════════════

    public function testBackOfficeIsReservedToAdmins(): void
    {
        $this->client->request('GET', '/admin/fiches-projets');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->loginAs($this->createArtistUser());
        foreach (['/admin/fiches-projets', '/admin/fiches-projets/' . $this->draft->getId() . '/apercu'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAdminPagesRenderAndPreviewShowsDraft(): void
    {
        $this->loginAs($this->createAdminUser());
        $id = $this->draft->getId();

        foreach ([
            '/admin/fiches-projets',
            '/admin/fiches-projets/nouvelle',
            '/admin/fiches-projets/' . $id . '/modifier',
            '/admin/fiches-projets/' . $id . '/presentation',
            '/admin/fiches-projets/' . $id . '/visuel',
            '/admin/fiches-projets/blocs/' . $this->draftImageBlock->getId() . '/image',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        // Aperçu d'une fiche non publiée : visible par l'admin, avec le bandeau.
        $this->client->request('GET', '/admin/fiches-projets/' . $id . '/apercu');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ap-preview', "n'est PAS publiée");
    }

    public function testShareLinkIsShownToAdmins(): void
    {
        $this->loginAs($this->createAdminUser());
        $crawler = $this->client->request('GET', '/admin/fiches-projets');

        self::assertStringEndsWith('/projets/' . self::TOKEN, (string) $crawler->filter('#adm-ap-share-url')->attr('value'));
    }

    public function testAdminCreatesFicheWithUniqueSlugAndServerValidation(): void
    {
        $this->loginAs($this->createAdminUser());

        // 1. Erreurs serveur : description de 601 caractères (retours à la ligne en \r\n)
        //    et adresse e-mail dans la référente.
        $crawler = $this->client->request('GET', '/admin/fiches-projets/nouvelle');
        $form = $crawler->filter('form[enctype="multipart/form-data"]')->form([
            'title'       => 'Formations',
            'type'        => AssociationProjectType::Training->value,
            'state'       => AssociationProjectState::InProgress->value,
            'description' => str_repeat('a', 599) . "\r\n" . 'b',   // 601 caractères une fois « \r\n » compté pour 1
            'leadName'    => 'prenom@gmail.com',
        ]);
        $this->client->submit($form);
        self::assertResponseIsSuccessful(); // formulaire ré-affiché
        $errors = implode(' | ', $this->client->getCrawler()->filter('.flash--error')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('601 caractères', $errors);
        self::assertStringContainsString('sans adresse e-mail', $errors);
        self::assertSame(2, $this->countProjects(), 'rien ne doit être enregistré');

        // 2. 600 caractères exactement, dont un retour à la ligne : accepté.
        $crawler = $this->client->request('GET', '/admin/fiches-projets/nouvelle');
        $form = $crawler->filter('form[enctype="multipart/form-data"]')->form([
            'title'       => 'Média BazaArt',  // titre déjà pris par une autre fiche
            'type'        => AssociationProjectType::PlatformMedia->value,
            'state'       => AssociationProjectState::Idea->value,
            'description' => str_repeat('a', 598) . "\r\n" . 'b',
            'leadName'    => 'Prénom Nom',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $created = $this->em->getRepository(AssociationProject::class)->findOneBy(['title' => 'Média BazaArt', 'isPublished' => false], ['id' => 'DESC']);
        self::assertNotNull($created);
        self::assertSame('media-bazaart-2', $created->getSlug(), 'slug déjà pris : suffixe -2');
        self::assertSame(600, mb_strlen((string) $created->getDescription()));
        self::assertFalse($created->isPublished(), 'non publiée par défaut');
    }

    public function testEmptyOrderOnEditMovesFicheToTheEnd(): void
    {
        $this->loginAs($this->createAdminUser());
        $id = $this->published->getId();   // ordre 10 ; l'autre fiche a l'ordre 20

        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $id . '/modifier');
        $form = $crawler->filter('form[enctype="multipart/form-data"]')->form(['position' => '']);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $project = $this->em->find(AssociationProject::class, $id);
        self::assertNotNull($project);
        self::assertSame(30, $project->getPosition(), 'ordre vidé = à la fin (20 + 10)');
        self::assertSame('an-ba-lanme-chapitre-2', $project->getSlug(), 'le slug ne change pas');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Garde-fous
    // ═════════════════════════════════════════════════════════════════════

    /**
     * security.yaml rend PUBLIC tout ce qui commence par /projets/ : seul le contrôleur
     * protégé par le jeton a le droit d'y déclarer des routes.
     */
    public function testOnlyTokenProtectedRoutesLiveUnderProjets(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $publicRoutes = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/projets')) {
                continue;
            }
            ++$publicRoutes;
            self::assertStringStartsWith(
                AssociationProjectPublicController::class . '::',
                (string) $route->getDefault('_controller'),
                sprintf('La route %s (%s) est sous /projets/ mais hors du contrôleur protégé par le jeton.', $name, $route->getPath()),
            );
            self::assertStringStartsWith('/projets/{token}', $route->getPath(), $name . ' doit exiger le jeton');
        }
        // page, api, fiche, visuel, image de bloc, image du texte, document PDF
        self::assertSame(7, $publicRoutes);
    }

    /** La page n'est liée nulle part ailleurs dans le site (navigation, pied de page…). */
    public function testPublicPageIsNotLinkedFromOtherTemplates(): void
    {
        $templatesDir = dirname(__DIR__, 2) . '/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = str_replace($templatesDir . '/', '', $file->getPathname());
            if (str_starts_with($path, 'association_projects/') || str_starts_with($path, 'admin/association_projects/')) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('app_asso_projects_', $content, $path . ' ne doit pas pointer vers la page partenaires');
        }

        // Ni robots.txt ni sitemap ne sont servis par la plateforme.
        foreach (['/robots.txt', '/sitemap.xml'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createProject(string $title, string $slug, bool $published, int $position): AssociationProject
    {
        $project = (new AssociationProject())
            ->setTitle($title)
            ->setSlug($slug)
            ->setType(AssociationProjectType::Residency)
            ->setState(AssociationProjectState::Funding)
            ->setPeriod('mai-juin 2027')
            ->setPosition($position)
            ->setIsPublished($published);
        $this->em->persist($project);

        return $project;
    }

    private function createImageBlock(AssociationProject $project, int $position): AssociationProjectBlock
    {
        $stored = $this->storeTinyPng();
        $block = (new AssociationProjectBlock($project))
            ->setPosition($position)
            ->setImagePath($stored->path)
            ->setImageAlt('Image de test')
            ->setImageSize($stored->width, $stored->height);
        $this->em->persist($block);

        return $block;
    }

    private function attachImage(AssociationProject $project): void
    {
        $stored = $this->storeTinyPng();
        $project->setImagePath($stored->path)->setImageSize($stored->width, $stored->height);
    }

    /** Enregistre une vraie (petite) image avec le service de stockage de l'application. */
    private function storeTinyPng(): StoredImage
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ap-test-');
        file_put_contents($tmp, base64_decode(self::TINY_PNG, true));
        $stored = static::getContainer()->get(AssociationProjectImageStorage::class)
            ->store(new UploadedFile($tmp, 'test.png', 'image/png', null, true));
        self::assertInstanceOf(StoredImage::class, $stored);
        $this->storedFiles[] = $stored->path;

        return $stored;
    }

    private function assertPrivacyHeaders(): void
    {
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    /** @return array{projects: list<array<string, mixed>>, contact: string} */
    private function apiJson(): array
    {
        /** @var array{projects: list<array<string, mixed>>, contact: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Toutes les clés d'un tableau, à toutes les profondeurs.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function allKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = array_merge($keys, $this->allKeys($value));
            }
        }

        return $keys;
    }

    private function countProjects(): int
    {
        return $this->em->getRepository(AssociationProject::class)->count([]);
    }
}
