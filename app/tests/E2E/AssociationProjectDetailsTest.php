<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\AssociationProject;
use App\Entity\AssociationProjectDocument;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Repository\AssociationProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use App\Service\AssociationProject\AssociationProjectDocumentStorage;
use App\Service\AssociationProject\AssociationProjectTextImages;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectDetailsTest : budget, « Le projet en détail » (texte enrichi avec
 * images) et documents PDF des fiches « Projets en cours » (ADR-0039, octobre 2026).
 *
 * Couvre :
 *   - le texte enrichi est NETTOYÉ à l'enregistrement (script, onclick, javascript:,
 *     image étrangère à la fiche) et affiché entre « En pratique » et le bloc jaune ;
 *   - une image du texte n'est visible par les partenaires que si elle est citée dans
 *     le texte ENREGISTRÉ d'une fiche PUBLIÉE, et rangée dans le dossier de CETTE fiche ;
 *   - les images retirées du texte et les fichiers d'une fiche supprimée sont effacés ;
 *   - documents PDF : ajout, refus d'un faux PDF, intitulé, ordre, suppression,
 *     accès par jeton seulement, jamais via l'adresse d'une autre fiche ;
 *   - pages et envois du back-office réservés à ROLE_ADMIN.
 */
class AssociationProjectDetailsTest extends AbstractE2ETestCase
{
    private const TOKEN = 'jeton-de-test-projets-association-0123456789abcdef';

    /** PNG de 1 × 1 pixel. */
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private AssociationProject $published;
    private AssociationProject $draft;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();
        $this->tempDir = sys_get_temp_dir() . '/ap-details-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0750, true);

        $this->published = $this->createProject('An Ba Lanmè, chapitre 2', 'an-ba-lanme-chapitre-2', true, 10);
        $this->published
            ->setSolicitedFunders('DAC Guadeloupe')
            ->setPartnerContribution("Un lieu d'accueil");
        $this->draft = $this->createProject('Média BazaArt', 'media-bazaart', false, 20);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        // Fichiers créés par le test (images du texte, PDF) : effacés, fiche par fiche.
        $textImages = static::getContainer()->get(AssociationProjectTextImages::class);
        $documentStorage = static::getContainer()->get(AssociationProjectDocumentStorage::class);
        foreach ($this->em->getRepository(AssociationProject::class)->findAll() as $project) {
            foreach ($project->getDocuments() as $document) {
                $documentStorage->delete($document->getPath());
            }
            $textImages->deleteAll($project);
        }
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Budget et texte enrichi
    // ═════════════════════════════════════════════════════════════════════

    public function testAdminSavesBudgetAndCleanDetailsShownBetweenFactsAndContribution(): void
    {
        $this->loginAs($this->createAdminUser());

        $this->saveFiche($this->published, [
            'budget'  => '45 000 € (dont 20 000 € acquis)',
            'details' => '<h2>Intertitre</h2><p onclick="steal()">Texte <script>alert(1)</script><a href="javascript:alert(1)">piège</a>'
                . ' et <a href="https://bazaart.fr">lien</a></p><p><img data-ap-image="0123456789abcdef0123456789abcdef.png" src="https://evil.example/x.png" alt="x"></p>',
        ]);

        $project = $this->reload($this->published);
        self::assertSame('45 000 € (dont 20 000 € acquis)', $project->getBudget());
        // script supprimé avec son contenu, onclick retiré, lien javascript: réduit à son
        // texte, image absente du dossier de la fiche retirée.
        self::assertSame('<h2>Intertitre</h2><p>Texte piège et <a href="https://bazaart.fr">lien</a></p>', $project->getDetails());

        $this->client->request('GET', $this->ficheUrl($this->published));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $crawler = $this->client->getCrawler();

        $labels = $crawler->filter('.ap-facts__item dt')->each(static fn ($n): string => $n->text());
        self::assertSame(['Dates et lieux', 'Financeurs sollicités', 'Budget'], $labels, 'Budget juste après « Financeurs sollicités »');
        self::assertSelectorTextContains('.ap-details .ap-section-title', 'Le projet en détail');
        self::assertSelectorTextContains('.ap-details .ap-article h3', 'Intertitre');
        self::assertSame('noopener noreferrer', $crawler->filter('.ap-article a')->attr('rel'));
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('javascript:', $html);

        // Ordre : « En pratique » → « Le projet en détail » → bloc jaune.
        $facts = strpos($html, 'class="ap-facts"');
        $details = strpos($html, 'class="ap-details"');
        $contribution = strpos($html, 'class="ap-contribution"');
        self::assertIsInt($facts);
        self::assertIsInt($details);
        self::assertIsInt($contribution);
        self::assertTrue($facts < $details && $details < $contribution);
    }

    public function testEmptyDetailsShowNoSection(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->saveFiche($this->published, ['details' => '<p><br></p><p>&nbsp;</p>']);

        self::assertNull($this->reload($this->published)->getDetails());
        $this->client->request('GET', $this->ficheUrl($this->published));
        self::assertSelectorNotExists('.ap-details');
    }

    public function testDetailsTooLongAreRefused(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->saveFiche($this->published, ['details' => '<p>' . str_repeat('a', 50001) . '</p>'], expectRedirect: false);

        $errors = implode(' | ', $this->client->getCrawler()->filter('.flash--error')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('50001 caractères', $errors);
        self::assertNull($this->reload($this->published)->getDetails(), 'rien ne doit être enregistré');
    }

    public function testNewFicheCannotCarryImagesInDetails(): void
    {
        $this->loginAs($this->createAdminUser());
        $crawler = $this->client->request('GET', '/admin/fiches-projets/nouvelle');
        self::assertSame(0, $crawler->filter('#ap-details[data-upload-url]')->count(), "pas d'envoi d'images avant la création");

        $form = $crawler->filter('form[enctype="multipart/form-data"]')->form([
            'title'   => 'Nouvelle fiche',
            'type'    => AssociationProjectType::Residency->value,
            'state'   => AssociationProjectState::Idea->value,
            'details' => '<p>Texte</p><p><img data-ap-image="0123456789abcdef0123456789abcdef.jpg"></p>',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $created = $this->em->getRepository(AssociationProject::class)->findOneBy(['title' => 'Nouvelle fiche']);
        self::assertNotNull($created);
        self::assertSame('<p>Texte</p>', $created->getDetails());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Images du texte
    // ═════════════════════════════════════════════════════════════════════

    public function testTextImageIsPublicOnlyOnceSavedInPublishedText(): void
    {
        $this->loginAs($this->createAdminUser());
        $uploadUrl = '/admin/fiches-projets/' . $this->published->getId() . '/texte/image';

        // Sans jeton CSRF : refusé.
        $this->client->request('POST', $uploadUrl, [], ['image' => $this->uploadedFile('photo.png', base64_decode(self::TINY_PNG, true))]);
        self::assertResponseStatusCodeSame(403);

        $token = $this->uploadToken($this->published);

        // Un PDF dans le bouton « Image » : refusé, avec un message clair.
        $this->client->request('POST', $uploadUrl, ['_token' => $token], ['image' => $this->uploadedFile('photo.png', self::MINIMAL_PDF)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Format non accepté', $this->json()['error']);

        $name = $this->uploadTextImage($this->published);
        $hash = substr($name, 0, 32);

        // Visible dans le back-office tout de suite…
        $this->client->request('GET', '/admin/fiches-projets/' . $this->published->getId() . '/texte/' . $hash);
        self::assertResponseIsSuccessful();
        // … mais pas par les partenaires tant que le texte n'est pas enregistré.
        $this->client->request('GET', $this->ficheUrl($this->published) . '/texte/' . $hash);
        self::assertResponseStatusCodeSame(404);

        $this->saveFiche($this->published, ['details' => '<p>Avec image</p><p><img data-ap-image="' . $name . '" alt="Photo du lieu"></p>']);
        self::assertStringContainsString('data-ap-image="' . $name . '"', (string) $this->reload($this->published)->getDetails());

        $crawler = $this->client->request('GET', $this->ficheUrl($this->published));
        $img = $crawler->filter('.ap-article img');
        self::assertSame('Photo du lieu', $img->attr('alt'));
        self::assertSame($this->ficheUrl($this->published) . '/texte/' . $hash, $img->attr('src'));

        $this->client->request('GET', $this->ficheUrl($this->published) . '/texte/' . $hash);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $this->client->request('GET', '/projets/mauvais-jeton/' . $this->published->getSlug() . '/texte/' . $hash);
        self::assertResponseStatusCodeSame(404);

        // Image retirée du texte : effacée du disque, plus accessible.
        $this->saveFiche($this->published, ['details' => '<p>Sans image</p>']);
        $textImages = static::getContainer()->get(AssociationProjectTextImages::class);
        self::assertNull($textImages->absolutePath($this->reload($this->published), $name));
        $this->client->request('GET', $this->ficheUrl($this->published) . '/texte/' . $hash);
        self::assertResponseStatusCodeSame(404);
    }

    public function testImageOfAnotherFicheIsNeitherKeptNorReachable(): void
    {
        $this->loginAs($this->createAdminUser());

        // Image envoyée pour la fiche NON publiée, et enregistrée dans son texte.
        $draftImage = $this->uploadTextImage($this->draft);
        $this->saveFiche($this->draft, ['details' => '<p><img data-ap-image="' . $draftImage . '" alt=""></p>']);

        // Collée dans le texte de la fiche publiée : retirée à l'enregistrement.
        $this->saveFiche($this->published, ['details' => '<p>Texte</p><p><img data-ap-image="' . $draftImage . '" alt=""></p>']);
        self::assertSame('<p>Texte</p>', $this->reload($this->published)->getDetails());

        // Ni par l'adresse de la fiche publiée, ni par celle de la fiche non publiée.
        $hash = substr($draftImage, 0, 32);
        $this->client->request('GET', $this->ficheUrl($this->published) . '/texte/' . $hash);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->ficheUrl($this->draft) . '/texte/' . $hash);
        self::assertResponseStatusCodeSame(404);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Documents PDF
    // ═════════════════════════════════════════════════════════════════════

    public function testDocumentsLifecycleAndPublicAccess(): void
    {
        $this->loginAs($this->createAdminUser());

        // 1. PDF sans intitulé : le nom du fichier, rendu lisible.
        $this->addDocument($this->published, 'Dossier_de-presentation.pdf', self::MINIMAL_PDF, '');
        self::assertSelectorTextContains('.flash--success', 'Document « Dossier de presentation » ajouté.');

        // 2. Image renommée en .pdf : refusée.
        $this->addDocument($this->published, 'faux.pdf', (string) base64_decode(self::TINY_PNG, true), 'Faux');
        self::assertSelectorTextContains('.flash--error', 'seuls les fichiers PDF sont acceptés');

        // 3. Second document, avec intitulé.
        $this->addDocument($this->published, 'budget.pdf', self::MINIMAL_PDF, 'Budget prévisionnel');
        self::assertSame(['Dossier de presentation', 'Budget prévisionnel'], $this->documentLabels($this->published));

        // 4. Monter le second, renommer le premier.
        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $this->published->getId() . '/documents');
        $this->client->submit($crawler->filter('.adm-ap-doc')->eq(1)->selectButton('↑ Monter')->form());
        self::assertSame(['Budget prévisionnel', 'Dossier de presentation'], $this->documentLabels($this->published));
        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $this->published->getId() . '/documents');
        $this->client->submit($crawler->filter('.adm-ap-doc')->eq(1)->selectButton("Enregistrer l'intitulé")->form(['label' => 'Dossier de présentation']));
        self::assertSame(['Budget prévisionnel', 'Dossier de présentation'], $this->documentLabels($this->published));

        // 5. Sur la fiche : ligne « Documents » dans « En pratique », dans l'ordre.
        $crawler = $this->client->request('GET', $this->ficheUrl($this->published));
        self::assertSame(['Budget prévisionnel', 'Dossier de présentation'], $crawler->filter('.ap-docs__label')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('Ko', $crawler->filter('.ap-docs__size')->first()->text());
        $href = (string) $crawler->filter('.ap-docs__link')->first()->attr('href');

        $this->client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('budget-previsionnel.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('GET', str_replace(self::TOKEN, 'mauvais-jeton', $href));
        self::assertResponseStatusCodeSame(404);

        // 6. API : documents présents, sans identifiant ni chemin de stockage.
        $this->client->request('GET', '/projets/' . self::TOKEN . '/api');
        $documents = $this->json()['projects'][0]['documents'];
        self::assertCount(2, $documents);
        self::assertSame(['label', 'url', 'sizeBytes', 'sizeLabel'], array_keys($documents[0]));

        // 7. Suppression : la ligne, le fichier, et le lien public.
        $document = $this->firstDocument($this->published);
        $path = $document->getPath();
        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $this->published->getId() . '/documents');
        $this->client->submit($crawler->filter('.adm-ap-doc')->first()->selectButton('Supprimer')->form());
        self::assertSame(['Dossier de présentation'], $this->documentLabels($this->published));
        self::assertNull(static::getContainer()->get(AssociationProjectDocumentStorage::class)->absolutePath($path));
        $this->client->request('GET', $href);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDraftDocumentIsNeverReachable(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->addDocument($this->draft, 'interne.pdf', self::MINIMAL_PDF, 'Document interne');
        $documentId = $this->firstDocument($this->draft)->getId();

        // Par l'adresse de la fiche non publiée…
        $this->client->request('GET', $this->ficheUrl($this->draft) . '/document/' . $documentId);
        self::assertResponseStatusCodeSame(404);
        // … ni en changeant le numéro sous l'adresse d'une fiche publiée.
        $this->client->request('GET', $this->ficheUrl($this->published) . '/document/' . $documentId);
        self::assertResponseStatusCodeSame(404);

        // L'aperçu admin, lui, le montre (lien vers le back-office).
        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $this->draft->getId() . '/apercu');
        $href = (string) $crawler->filter('.ap-docs__link')->attr('href');
        self::assertStringStartsWith('/admin/fiches-projets/documents/', $href);
        $this->client->request('GET', $href);
        self::assertResponseIsSuccessful();
    }

    public function testDocumentActionsRequireCsrfToken(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->addDocument($this->published, 'dossier.pdf', self::MINIMAL_PDF, 'Dossier');
        $this->addDocument($this->published, 'budget.pdf', self::MINIMAL_PDF, 'Budget');
        $id = $this->firstDocument($this->published)->getId();

        foreach ([
            ['/admin/fiches-projets/documents/' . $id, ['label' => 'Piraté', '_token' => 'faux']],
            ['/admin/fiches-projets/documents/' . $id . '/deplacer', ['direction' => 'down', '_token' => 'faux']],
            ['/admin/fiches-projets/documents/' . $id . '/supprimer', ['_token' => 'faux']],
            ['/admin/fiches-projets/' . $this->published->getId() . '/documents', ['label' => 'Piraté', '_token' => 'faux']],
        ] as [$url, $post]) {
            $this->client->request('POST', $url, $post);
            self::assertResponseRedirects();
        }

        self::assertSame(['Dossier', 'Budget'], $this->documentLabels($this->published), 'rien ne doit avoir changé');
    }

    public function testPublicPagesLoadDocumentsWithoutOneQueryPerFiche(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->addDocument($this->published, 'dossier.pdf', self::MINIMAL_PDF, 'Dossier');

        // Mémoire de Doctrine vidée (celle du conteneur, utilisée par le dépôt) :
        // on part d'une lecture en base, comme une vraie requête.
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $repository = static::getContainer()->get(AssociationProjectRepository::class);
        foreach ([...$repository->findPublishedOrdered(withBlocks: true), $repository->findPublishedBySlug($this->published->getSlug())] as $project) {
            self::assertNotNull($project);
            $documents = $project->getDocuments();
            self::assertInstanceOf(PersistentCollection::class, $documents);
            self::assertTrue($documents->isInitialized(), 'documents déjà chargés, sans requête supplémentaire');
            self::assertCount(1, $documents);
        }
    }

    public function testDeletingFicheRemovesItsFiles(): void
    {
        $this->loginAs($this->createAdminUser());
        $name = $this->uploadTextImage($this->published);
        $this->saveFiche($this->published, ['details' => '<p><img data-ap-image="' . $name . '" alt=""></p>']);
        $this->addDocument($this->published, 'dossier.pdf', self::MINIMAL_PDF, 'Dossier');

        $project = $this->reload($this->published);
        $documentPath = $this->firstDocument($project)->getPath();
        $textImages = static::getContainer()->get(AssociationProjectTextImages::class);
        self::assertNotNull($textImages->absolutePath($project, $name));

        $crawler = $this->client->request('GET', '/admin/fiches-projets');
        $this->client->submit($crawler->filter('form[action$="/' . $project->getId() . '/supprimer"]')->form());
        self::assertResponseRedirects();

        self::assertNull(static::getContainer()->get(AssociationProjectDocumentStorage::class)->absolutePath($documentPath));
        self::assertNull($textImages->absolutePath($project, $name));
        self::assertSame(0, $this->em->getRepository(AssociationProjectDocument::class)->count([]));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Accès
    // ═════════════════════════════════════════════════════════════════════

    public function testBackOfficeRoutesAreReservedToAdmins(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->addDocument($this->published, 'dossier.pdf', self::MINIMAL_PDF, 'Dossier');
        $documentId = $this->firstDocument($this->published)->getId();
        $name = $this->uploadTextImage($this->published);

        $this->loginAs($this->createArtistUser());
        foreach ([
            ['GET', '/admin/fiches-projets/' . $this->published->getId() . '/documents'],
            ['GET', '/admin/fiches-projets/documents/' . $documentId . '/fichier'],
            ['GET', '/admin/fiches-projets/' . $this->published->getId() . '/texte/' . substr($name, 0, 32)],
            ['POST', '/admin/fiches-projets/' . $this->published->getId() . '/texte/image'],
            ['POST', '/admin/fiches-projets/' . $this->published->getId() . '/documents'],
            ['POST', '/admin/fiches-projets/documents/' . $documentId . '/supprimer'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $method . ' ' . $url);
        }
        self::assertCount(1, $this->documentLabels($this->published));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Outils
    // ═════════════════════════════════════════════════════════════════════

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

    /**
     * Enregistre le formulaire de la fiche avec les champs donnés (les autres
     * gardent leur valeur actuelle).
     *
     * @param array<string, string> $fields
     */
    private function saveFiche(AssociationProject $project, array $fields, bool $expectRedirect = true): void
    {
        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $project->getId() . '/modifier');
        $form = $crawler->filter('form[enctype="multipart/form-data"]')->form($fields);
        $this->client->submit($form);
        if ($expectRedirect) {
            self::assertResponseRedirects();
        } else {
            self::assertResponseIsSuccessful();
        }
    }

    private function uploadToken(AssociationProject $project): string
    {
        $this->client->request('GET', '/admin/fiches-projets/' . $project->getId() . '/modifier');
        $token = $this->getCsrfTokenFromDataAttribute('#ap-details', 'upload-token');
        self::assertNotSame('', $token);

        return $token;
    }

    /** Envoie une image comme le fait l'éditeur ; renvoie son nom de fichier. */
    private function uploadTextImage(AssociationProject $project): string
    {
        $this->client->request(
            'POST',
            '/admin/fiches-projets/' . $project->getId() . '/texte/image',
            ['_token' => $this->uploadToken($project)],
            ['image' => $this->uploadedFile('photo.png', (string) base64_decode(self::TINY_PNG, true))],
        );
        self::assertResponseStatusCodeSame(201);
        $data = $this->json();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $data['name']);
        self::assertSame('/admin/fiches-projets/' . $project->getId() . '/texte/' . substr($data['name'], 0, 32), $data['url']);

        return $data['name'];
    }

    private function addDocument(AssociationProject $project, string $filename, string $content, string $label): void
    {
        $directory = $this->tempDir . '/' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/' . $filename, $content);

        $crawler = $this->client->request('GET', '/admin/fiches-projets/' . $project->getId() . '/documents');
        $form = $crawler->selectButton('Ajouter le document')->form(['label' => $label]);
        $form['document']->upload($directory . '/' . $filename);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/fiches-projets/' . $project->getId() . '/documents');
        $this->client->followRedirect();
    }

    /** @return list<string> intitulés des documents de la fiche, dans l'ordre */
    private function documentLabels(AssociationProject $project): array
    {
        return array_values(array_map(
            static fn (AssociationProjectDocument $document): string => $document->getLabel(),
            $this->reload($project)->getDocuments()->toArray(),
        ));
    }

    private function firstDocument(AssociationProject $project): AssociationProjectDocument
    {
        $document = $this->reload($project)->getDocuments()->first();
        self::assertInstanceOf(AssociationProjectDocument::class, $document);

        return $document;
    }

    private function reload(AssociationProject $project): AssociationProject
    {
        $this->em->clear();
        $fresh = $this->em->find(AssociationProject::class, $project->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function ficheUrl(AssociationProject $project): string
    {
        return '/projets/' . self::TOKEN . '/' . $project->getSlug();
    }

    private function uploadedFile(string $name, string $content): UploadedFile
    {
        $tmp = (string) tempnam($this->tempDir, 'up-');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
