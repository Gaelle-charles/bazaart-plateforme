<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Command\ImportAssociationProjectsCommand;
use App\Entity\AssociationProject;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use App\Service\AssociationProject\AssociationProjectImageStorage;
use App\Service\AssociationProject\AssociationProjectImporter;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AssociationProjectImportTest : commande app:import-association-projects (ADR-0039).
 *
 * Couvre les règles demandées par l'association :
 *   - rattachement par titre exact, rien n'est jamais créé ;
 *   - seuls les champs vides sont remplis, une saisie n'est jamais écrasée ;
 *   - « publié » jamais modifié, fiche publiée et fiche « Formations » non touchées ;
 *   - visuel envoyé par le stockage privé, texte alternatif = titre ;
 *   - une 2e exécution ne modifie rien ; un champ vidé par l'équipe n'est pas re-rempli ;
 *   - essai (--dry-run) sans aucune écriture ; retour arrière complet en cas d'échec ;
 *   - refus de tourner en root en production.
 *
 * DONNÉES FACTICES UNIQUEMENT, créées dans un dossier temporaire puis effacées :
 * les vrais contenus des fiches ne doivent jamais être commités (dépôt public).
 */
class AssociationProjectImportTest extends AbstractE2ETestCase
{
    /** PNG de 1 × 1 pixel : une vraie image, minuscule. */
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Dossier temporaire de données du test (projets.json + images/). */
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();

        // Préfixe différent de celui des copies temporaires de l'import (asso-import-) :
        // le test qui vérifie qu'aucune copie ne traîne ne doit pas compter ce dossier.
        $this->dir = sys_get_temp_dir() . '/ap-import-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/images', 0777, true);
    }

    protected function tearDown(): void
    {
        $storage = static::getContainer()->get(AssociationProjectImageStorage::class);
        $this->em->clear();
        foreach ($this->em->getRepository(AssociationProject::class)->findAll() as $project) {
            $storage->delete($project->getImagePath());
        }
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Remplissage
    // ═════════════════════════════════════════════════════════════════════

    public function testFillsOnlyEmptyFieldsAndSendsTheVisual(): void
    {
        $zagaza = $this->createProject('Zagaza · Maison Verveau', 'zagaza-maison-verveau');
        $zagaza->setPeriod("Saisie de l'équipe");
        $this->createProject('Formations', 'formations', AssociationProjectType::Training);
        $this->em->flush();
        // Relue en base : la colonne est à la seconde près, l'objet en mémoire non.
        $formationsUpdatedAt = $this->reload('formations')->getUpdatedAt();

        $this->writeImage('zagaza.png');
        $this->writeJson([$this->item('Zagaza · Maison Verveau', [
            'dates'       => 'Valeur-factice-dates',
            'lieux'       => 'Valeur-factice-lieux',
            // Forme NFD (« é » = e + accent combinant), comme peut l'écrire un Mac.
            'description' => "Re\u{0301}sidence factice",
            'visuel'      => 'zagaza.png',
        ])]);

        [$code, $output] = $this->runImport();

        self::assertSame(Command::SUCCESS, $code, $output);
        $zagaza = $this->reload('zagaza-maison-verveau');
        // Saisie existante : jamais écrasée, et listée dans le compte rendu.
        self::assertSame("Saisie de l'équipe", $zagaza->getPeriod());
        self::assertStringContainsString('Déjà remplis avec une autre valeur, NON écrasés (1) : dates', $output);
        // Champs vides : remplis (texte normalisé en NFC).
        self::assertSame('Valeur-factice-lieux', $zagaza->getPlaces());
        self::assertSame("R\u{00E9}sidence factice", $zagaza->getDescription());
        self::assertNull($zagaza->getLeadName(), 'null dans le JSON = champ laissé vide');
        // Visuel : stockage privé, dimensions mesurées, texte alternatif = titre.
        self::assertNotNull(static::getContainer()->get(AssociationProjectImageStorage::class)->absolutePath($zagaza->getImagePath()));
        self::assertSame([1, 1], [$zagaza->getImageWidth(), $zagaza->getImageHeight()]);
        self::assertSame('Zagaza · Maison Verveau', $zagaza->getImageAlt());
        // Jamais publiée par l'import ; Formations (absente du JSON) intacte.
        self::assertFalse($zagaza->isPublished());
        $formations = $this->reload('formations');
        self::assertEquals($formationsUpdatedAt, $formations->getUpdatedAt());
        self::assertNull($formations->getPlaces());
        // Le fichier source n'a pas été déplacé (store() reçoit une copie).
        self::assertFileExists($this->dir . '/images/zagaza.png');
        // Compteurs.
        self::assertMatchesRegularExpression('/Champs remplis \(visuel compris\)\s+3\b/', $output);
        self::assertMatchesRegularExpression('/Champs ignorés car déjà remplis\s+1\b/', $output);
        self::assertMatchesRegularExpression('/Visuels envoyés\s+1\b/', $output);
        // Confidentialité : aucun contenu dans le compte rendu.
        self::assertStringNotContainsString('Valeur-factice', $output);
        self::assertStringNotContainsString("Saisie de l'équipe", $output);
        // Journal écrit à côté des données.
        self::assertFileExists($this->dir . '/' . AssociationProjectImporter::JOURNAL_FILE);
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->createProject('An Ba Lanmè, chapitre 2', 'an-ba-lanme-chapitre-2');
        $this->em->flush();
        $this->writeImage('an-ba.png');
        $this->writeJson([$this->item('An Ba Lanmè, chapitre 2', ['lieux' => 'Lieu factice', 'visuel' => 'an-ba.png'])]);

        [$firstCode] = $this->runImport();
        self::assertSame(Command::SUCCESS, $firstCode);
        $afterFirst = $this->reload('an-ba-lanme-chapitre-2');
        $updatedAt = $afterFirst->getUpdatedAt();
        $imagePath = $afterFirst->getImagePath();
        $filesBefore = $this->storedFileCount();

        [$code, $output] = $this->runImport();

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('rien à remplir', $output);
        self::assertMatchesRegularExpression('/Champs remplis \(visuel compris\)\s+0\b/', $output);
        self::assertMatchesRegularExpression('/dont identiques au JSON\s+2\b/', $output);
        self::assertMatchesRegularExpression('/Visuels envoyés\s+0\b/', $output);
        $afterSecond = $this->reload('an-ba-lanme-chapitre-2');
        self::assertEquals($updatedAt, $afterSecond->getUpdatedAt(), 'aucune écriture en base');
        self::assertSame($imagePath, $afterSecond->getImagePath());
        self::assertSame($filesBefore, $this->storedFileCount(), 'aucune nouvelle image');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Fiches non touchées
    // ═════════════════════════════════════════════════════════════════════

    public function testUnknownTitleCreatesNothingAndSuggestsTheNearTitle(): void
    {
        $this->createProject('Tiers-lieu, phase 1 : équipement', 'tiers-lieu-phase-1-equipement');
        $this->em->flush();
        $this->writeJson([
            // Espace insécable avant « : » : titre différent, au caractère près.
            $this->item("Tiers-lieu, phase 1\u{00A0}: équipement", ['lieux' => 'Lieu factice']),
            $this->item('Projet qui n’existe pas', ['lieux' => 'Lieu factice']),
        ]);

        [$code, $output] = $this->runImport();

        self::assertSame(Command::FAILURE, $code);
        self::assertSame(1, $this->em->getRepository(AssociationProject::class)->count([]), 'aucune fiche créée');
        self::assertNull($this->reload('tiers-lieu-phase-1-equipement')->getPlaces());
        self::assertStringContainsString('TITRE INTROUVABLE', $output);
        self::assertStringContainsString('titre presque identique (« Tiers-lieu, phase 1 : équipement »)', $output);
        self::assertMatchesRegularExpression('/Titres introuvables \(rien créé\)\s+2\b/', $output);
    }

    public function testPublishedExcludedAndDuplicatedFichesAreNotTouched(): void
    {
        $published = $this->createProject('Média BazaArt', 'media-bazaart');
        $published->setIsPublished(true);
        $this->createProject('Formations', 'formations', AssociationProjectType::Training);
        $this->createProject('masonn · Bolivie', 'masonn-bolivie');
        $this->em->flush();
        $this->writeJson([
            $this->item('Média BazaArt', ['lieux' => 'Lieu factice']),
            $this->item('Formations', ['lieux' => 'Lieu factice']),
            $this->item('masonn · Bolivie', ['lieux' => 'Lieu factice']),
            $this->item('masonn · Bolivie', ['dates' => 'Dates factices']),
        ]);

        [$code, $output] = $this->runImport();

        self::assertSame(Command::FAILURE, $code);
        foreach (['media-bazaart', 'formations', 'masonn-bolivie'] as $slug) {
            $project = $this->reload($slug);
            self::assertNull($project->getPlaces(), $slug);
            self::assertNull($project->getPeriod(), $slug);
        }
        self::assertTrue($this->reload('media-bazaart')->isPublished(), '« publié » jamais modifié');
        self::assertFalse($this->reload('masonn-bolivie')->isPublished());
        self::assertStringContainsString('NON TOUCHÉE (déjà publiée)', $output);
        self::assertStringContainsString('NON TOUCHÉE (exclue)', $output);
        self::assertStringContainsString('NON TOUCHÉE (titre en double)', $output);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Essai, journal, retour arrière
    // ═════════════════════════════════════════════════════════════════════

    public function testDryRunWritesNothing(): void
    {
        $this->createProject('Plateforme app.bazaart.fr', 'plateforme-app-bazaart-fr');
        $this->em->flush();
        $this->writeImage('plateforme.png');
        $this->writeJson([$this->item('Plateforme app.bazaart.fr', ['lieux' => 'Lieu factice', 'visuel' => 'plateforme.png'])]);
        $filesBefore = $this->storedFileCount();

        [$code, $output] = $this->runImport(dryRun: true);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString("Essai (--dry-run) : rien n'a été enregistré.", $output);
        self::assertStringContainsString('À remplir (2) : lieux, visuel', $output);
        $project = $this->reload('plateforme-app-bazaart-fr');
        self::assertNull($project->getPlaces());
        self::assertNull($project->getImagePath());
        self::assertSame($filesBefore, $this->storedFileCount());
        self::assertFileDoesNotExist($this->dir . '/' . AssociationProjectImporter::JOURNAL_FILE);
    }

    public function testFieldsEmptiedByTheTeamAreNotRefilled(): void
    {
        $this->createProject('Groupes de travail ICC Guadeloupe', 'groupes-de-travail-icc-guadeloupe');
        $this->em->flush();
        $this->writeImage('icc.png');
        $this->writeJson([$this->item('Groupes de travail ICC Guadeloupe', [
            'lieux' => 'Lieu factice', 'dates' => 'Dates factices', 'visuel' => 'icc.png',
        ])]);
        $this->runImport();

        // L'équipe relit : elle vide « lieux » et retire le visuel dans le back-office.
        $project = $this->reload('groupes-de-travail-icc-guadeloupe');
        static::getContainer()->get(AssociationProjectImageStorage::class)->delete($project->getImagePath());
        $project->setPlaces(null)->setImagePath(null)->setImageSize(null, null);
        $this->em->flush();

        [$code, $output] = $this->runImport();

        self::assertSame(Command::SUCCESS, $code, $output);
        $project = $this->reload('groupes-de-travail-icc-guadeloupe');
        self::assertNull($project->getPlaces(), 'champ vidé par l\'équipe : non re-rempli');
        self::assertNull($project->getImagePath(), 'visuel retiré par l\'équipe : non renvoyé');
        self::assertSame('Dates factices', $project->getPeriod());
        self::assertStringContainsString('Vidés depuis un import précédent, non re-remplis (2) : lieux, visuel', $output);
    }

    public function testFailureRollsBackEverything(): void
    {
        $this->createProject('Coopération Guadeloupe-Côte d\'Ivoire', 'cooperation-guadeloupe-cote-d-ivoire');
        $this->em->flush();
        $this->writeImage('cooperation.png');
        $this->writeJson([$this->item('Coopération Guadeloupe-Côte d\'Ivoire', ['lieux' => 'Lieu factice', 'visuel' => 'cooperation.png'])]);
        $filesBefore = $this->storedFileCount();

        // Panne simulée au moment d'enregistrer en base (après l'envoi du visuel).
        $failure = new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('panne simulée');
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener([Events::onFlush], $failure);
        try {
            [$code, $output] = $this->runImport();
        } finally {
            $events->removeEventListener([Events::onFlush], $failure);
        }

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString("Import annulé : rien n'a été enregistré", $output);
        $project = $this->reload('cooperation-guadeloupe-cote-d-ivoire');
        self::assertNull($project->getPlaces());
        self::assertNull($project->getImagePath());
        self::assertSame($filesBefore, $this->storedFileCount(), 'image de cette exécution supprimée');
        self::assertFileDoesNotExist($this->dir . '/' . AssociationProjectImporter::JOURNAL_FILE);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Valeurs refusées
    // ═════════════════════════════════════════════════════════════════════

    public function testInvalidValuesAreReportedAndNotImported(): void
    {
        $this->createProject('Zagaza · Maison Verveau', 'zagaza-maison-verveau');
        $this->createProject('masonn · Bolivie', 'masonn-bolivie');
        $this->em->flush();
        file_put_contents($this->dir . '/images/pas-une-image.png', 'ceci est du texte');
        $first = $this->item('Zagaza · Maison Verveau', [
            'description'       => str_repeat('a', 601),
            'lieux'             => ['liste', 'non acceptée'],
            'referente'         => 'prenom@exemple.fr',
            'apport_partenaire' => 'Apport factice',
            'visuel'            => '../projets.json',
            'type'              => 'Type inconnu',
        ]);
        unset($first['dates']);
        $first['artiste'] = 'clé mal orthographiée';
        $second = $this->item('masonn · Bolivie', ['visuel' => 'pas-une-image.png']);
        $this->writeJson([$first, $second]);
        $temporaryBefore = count(glob(sys_get_temp_dir() . '/asso-import-*') ?: []);

        [$code, $output] = $this->runImport();

        self::assertSame(Command::FAILURE, $code);
        $zagaza = $this->reload('zagaza-maison-verveau');
        self::assertNull($zagaza->getDescription());
        self::assertNull($zagaza->getPlaces());
        self::assertNull($zagaza->getLeadName());
        self::assertNull($zagaza->getImagePath());
        self::assertSame('Apport factice', $zagaza->getPartnerContribution(), 'les champs valides sont remplis');
        self::assertNull($this->reload('masonn-bolivie')->getImagePath());
        foreach ([
            'La description fait 601 caractères : 600 maximum. Champ non rempli.',
            'lieux : valeur de type array, texte attendu. Champ non rempli.',
            'Référente : indique seulement le prénom et le nom',
            'Clé inconnue « artiste » : ignorée.',
            'Clé « dates » absente : traitée comme null.',
            'nom de fichier refusé',
            'type : valeur absente de la liste du back-office',
            'Format non accepté',
        ] as $expected) {
            self::assertStringContainsString($expected, $output);
        }
        // Aucune copie temporaire d'image ne traîne, même pour l'image refusée.
        self::assertCount($temporaryBefore, glob(sys_get_temp_dir() . '/asso-import-*') ?: []);
    }

    public function testFindsAVisualWhoseFileNameIsInNfdForm(): void
    {
        $this->createProject('Média BazaArt', 'media-bazaart');
        $this->em->flush();
        // Nom de fichier écrit en NFD sur le disque, nom du JSON en NFC.
        $this->writeImage("fe\u{0302}te.png");
        $this->writeJson([$this->item('Média BazaArt', ['visuel' => "f\u{00EA}te.png"])]);

        [$code, $output] = $this->runImport();

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertNotNull($this->reload('media-bazaart')->getImagePath());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Production : pas en root
    // ═════════════════════════════════════════════════════════════════════

    public function testRefusesToRunAsRootInProduction(): void
    {
        $this->createProject('Média BazaArt', 'media-bazaart');
        $this->em->flush();
        $this->writeJson([$this->item('Média BazaArt', ['lieux' => 'Lieu factice'])]);

        $command = new class(static::getContainer()->get(AssociationProjectImporter::class), 'prod') extends ImportAssociationProjectsCommand {
            protected function effectiveUserId(): int
            {
                return 0; // root
            }
        };
        $tester = new CommandTester($command);
        $code = $tester->execute(['dossier' => $this->dir]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('-u www-data', $tester->getDisplay());
        self::assertNull($this->reload('media-bazaart')->getPlaces());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Outils
    // ═════════════════════════════════════════════════════════════════════

    private function createProject(
        string $title,
        string $slug,
        AssociationProjectType $type = AssociationProjectType::Residency,
    ): AssociationProject {
        $project = (new AssociationProject())
            ->setTitle($title)
            ->setSlug($slug)
            ->setType($type)
            ->setState(AssociationProjectState::InProgress);
        $this->em->persist($project);

        return $project;
    }

    /**
     * Une fiche du JSON au format fourni par l'association : toutes les clés, null par défaut.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function item(string $title, array $values): array
    {
        $item = array_fill_keys(AssociationProjectImporter::KNOWN_KEYS, null);
        $item['titre'] = $title;
        $item['type'] = 'Résidence';
        $item['etat'] = 'En construction';

        return array_merge($item, $values);
    }

    /** @param list<array<string, mixed>> $items */
    private function writeJson(array $items): void
    {
        file_put_contents(
            $this->dir . '/projets.json',
            json_encode(['projets' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }

    private function writeImage(string $name): void
    {
        file_put_contents($this->dir . '/images/' . $name, base64_decode(self::TINY_PNG, true));
    }

    /** @return array{0: int, 1: string} [code de sortie, texte affiché] */
    private function runImport(bool $dryRun = false): array
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:import-association-projects'));
        $arguments = ['dossier' => $this->dir];
        if ($dryRun) {
            $arguments['--dry-run'] = true;
        }
        $code = $tester->execute($arguments);
        // Relire la base comme le ferait une autre requête.
        $this->em->clear();

        return [$code, $tester->getDisplay()];
    }

    private function reload(string $slug): AssociationProject
    {
        $this->em->clear();
        $project = $this->em->getRepository(AssociationProject::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($project, $slug);

        return $project;
    }

    private function storedFileCount(): int
    {
        $dir = static::getContainer()->getParameter('association_projects.storage_dir');
        self::assertIsString($dir);

        return count(glob($dir . '/*') ?: []);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
