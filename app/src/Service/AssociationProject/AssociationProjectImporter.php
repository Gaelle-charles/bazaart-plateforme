<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

use App\DTO\AssociationProject\AssociationProjectData;
use App\Entity\AssociationProject;
use App\Enum\AssociationProjectState;
use App\Enum\AssociationProjectType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AssociationProjectImporter : remplit les fiches « Projets en cours » EXISTANTES à
 * partir d'un dossier projets.json + images/ (commande app:import-association-projects,
 * ADR-0039, procédure dans docs/projets-association.md).
 *
 * RÈGLES (demandées par l'association) :
 *   - Rattachement par TITRE EXACT. Titre inconnu → rien n'est créé, c'est signalé.
 *   - Seuls les champs VIDES sont remplis : une saisie du back-office n'est jamais écrasée.
 *   - « Publié » n'est jamais lu pour décider ni écrit ; une fiche DÉJÀ publiée n'est pas
 *     remplie du tout (pas de contenu non relu visible des partenaires).
 *   - La fiche « Formations » est exclue (repérée par son slug, figé à la création).
 *   - Type et état ne sont jamais vides (squelette) : jamais écrits, différences signalées.
 *   - Visuels : envoyés par AssociationProjectImageStorage::store(), exactement comme un
 *     envoi depuis le back-office (vrai format, 5 Mo, 8 000 px, nom aléatoire, stockage
 *     privé). Texte alternatif = titre de la fiche.
 *   - Relançable : un JOURNAL (.journal-import.json, à côté de projets.json, donc hors
 *     git) retient chaque champ rempli. Un champ vidé ensuite par l'équipe pendant sa
 *     relecture n'est JAMAIS re-rempli, et un visuel retiré n'est jamais renvoyé.
 *
 * SÛRETÉ :
 *   - Tout se passe dans UNE transaction ; chaque fiche est verrouillée (SELECT … FOR
 *     UPDATE) puis relue avant d'être comparée : une saisie simultanée n'est pas écrasée.
 *     En cas d'échec : retour arrière en base ET suppression des images déjà stockées.
 *   - Les mêmes règles de longueur et de contenu que le formulaire (AssociationProjectData
 *     ::validate()), réutilisées et non recopiées.
 *   - Textes normalisés en Unicode NFC : un Mac peut écrire « é » en deux caractères
 *     (forme NFD), ce qui fausserait les longueurs et les comparaisons sur le serveur.
 *   - En essai (--dry-run), les images passent les mêmes contrôles dans un dossier
 *     temporaire, effacé ensuite ; rien n'est écrit en base.
 *
 * CONFIDENTIALITÉ : le compte rendu ne contient que des titres et des noms de champs,
 * jamais leur contenu (cf. AssociationProjectImportEntry).
 */
final class AssociationProjectImporter
{
    public const DATA_FILE = 'projets.json';
    public const IMAGES_DIR = 'images';
    public const JOURNAL_FILE = '.journal-import.json';
    public const IMAGE_LABEL = 'visuel';

    /** Fiches jamais modifiées par l'import, repérées par leur slug (figé à la création). */
    public const EXCLUDED_SLUGS = ['formations'];

    /** Champs texte : clé du JSON => libellé du compte rendu (mêmes mots que le formulaire). */
    public const TEXT_FIELDS = [
        'dates'                 => 'dates',
        'lieux'                 => 'lieux',
        'artistes_concernes'    => 'artistes concernés',
        'description'           => 'description',
        'partenaires_confirmes' => 'partenaires confirmés',
        'partenaires_pressentis' => 'partenaires pressentis',
        'financeurs_sollicites' => 'financeurs sollicités',
        'apport_partenaire'     => "ce qu'un partenaire peut apporter",
        'referente'             => 'référente',
    ];

    /** Toutes les clés attendues dans chaque fiche du JSON. */
    public const KNOWN_KEYS = [
        'titre', 'type', 'etat', 'dates', 'lieux', 'artistes_concernes', 'description',
        'partenaires_confirmes', 'partenaires_pressentis', 'financeurs_sollicites',
        'apport_partenaire', 'referente', 'visuel',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssociationProjectImageStorage $imageStorage,
        /** Dossier privé des images (même paramètre que AssociationProjectImageStorage). */
        private readonly string $storageDir,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Lance l'import (ou l'essai, si $dryRun) du dossier donné.
     *
     * @throws \Throwable si l'enregistrement échoue : rien n'est alors enregistré
     *                    (retour arrière en base, images de cette exécution supprimées)
     */
    public function import(string $directory, bool $dryRun): AssociationProjectImportReport
    {
        $dir = $this->resolveDirectory($directory);
        $report = new AssociationProjectImportReport($dryRun, $dir);

        $items = $this->readItems($dir, $report);
        if ($items === null) {
            return $report;
        }

        $journalPath = $dir . DIRECTORY_SEPARATOR . self::JOURNAL_FILE;
        $journal = $this->readJournal($journalPath, $report);
        $this->checkWritable($dir, $journalPath, $report);

        // On n'écrit que si ce n'est pas un essai ET qu'aucune erreur générale n'est apparue.
        $canWrite = !$dryRun && $report->globalErrors === [];

        $titleCounts = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['titre'] ?? null)) {
                $titleCounts[$item['titre']] = ($titleCounts[$item['titre']] ?? 0) + 1;
            }
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $storedPaths = [];
        $trialDir = null;

        try {
            // ── 1. Analyse de chaque fiche (lecture seule, fiches verrouillées) ──
            /** @var list<array{entry: AssociationProjectImportEntry, project: AssociationProject, texts: array<string, string>, imageSource: ?string, storedImage: ?StoredImage}> $plans */
            $plans = [];
            foreach ($items as $index => $item) {
                $title = is_array($item) && is_string($item['titre'] ?? null) ? $item['titre'] : '';
                $entry = new AssociationProjectImportEntry($index + 1, $title);
                $report->entries[] = $entry;

                $plan = $this->planEntry($entry, $item, $titleCounts, $journal, $dir . DIRECTORY_SEPARATOR . self::IMAGES_DIR, $canWrite);
                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }

            // ── 2. Visuels : mêmes contrôles qu'un envoi depuis le back-office ──
            // Essai (ou import bloqué) : dans un dossier temporaire, effacé à la fin.
            $storage = $this->imageStorage;
            if (!$canWrite) {
                $trialDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'asso-import-essai-' . bin2hex(random_bytes(8));
                $storage = new AssociationProjectImageStorage($trialDir);
            }
            foreach ($plans as $key => $plan) {
                if ($plan['imageSource'] === null) {
                    continue;
                }
                $result = $this->storeImage($storage, $plan['imageSource']);
                if (is_string($result)) {
                    $plan['entry']->errors[] = 'visuel : ' . $result . ' Visuel non envoyé.';
                    continue;
                }
                $plan['entry']->filled[] = self::IMAGE_LABEL;
                if ($canWrite) {
                    $storedPaths[] = $result->path;
                    $plans[$key]['storedImage'] = $result;
                }
            }

            foreach ($plans as $plan) {
                if ($plan['entry']->filled !== []) {
                    $plan['entry']->status = AssociationProjectImportEntry::STATUS_UPDATED;
                }
            }

            if (!$canWrite) {
                $connection->rollBack();

                return $report;
            }

            // ── 3. Enregistrement ─────────────────────────────────────────────
            foreach ($plans as $plan) {
                $project = $plan['project'];
                foreach ($plan['texts'] as $jsonKey => $value) {
                    $this->setValue($project, $jsonKey, $value);
                }
                $image = $plan['storedImage'];
                if ($image !== null) {
                    $project->setImagePath($image->path)->setImageSize($image->width, $image->height);
                    if (self::isBlank($project->getImageAlt())) {
                        $project->setImageAlt($project->getTitle());
                    }
                }
            }

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            foreach ($storedPaths as $path) {
                $this->imageStorage->delete($path);
            }
            // Les entités modifiées en mémoire ne correspondent plus à la base.
            $this->em->clear();

            throw $exception;
        } finally {
            if ($trialDir !== null) {
                self::removeDirectory($trialDir);
            }
        }

        $report->written = true;
        $report->storedImages = $storedPaths;
        $this->writeJournal($journalPath, $journal, $plans, $report);

        return $report;
    }

    /**
     * Analyse une entrée du JSON et prépare ce qu'il faut remplir.
     *
     * @param array<string, int>          $titleCounts nombre d'apparitions de chaque titre dans le JSON
     * @param array<string, list<string>> $journal     id de fiche => clés déjà remplies par un import
     *
     * @return array{entry: AssociationProjectImportEntry, project: AssociationProject, texts: array<string, string>, imageSource: ?string, storedImage: ?StoredImage}|null
     */
    private function planEntry(
        AssociationProjectImportEntry $entry,
        mixed $item,
        array $titleCounts,
        array $journal,
        string $imagesDir,
        bool $lock,
    ): ?array {
        // json_decode(…, true) : un objet JSON devient un tableau associatif.
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            $entry->status = AssociationProjectImportEntry::STATUS_INVALID;
            $entry->errors[] = "Cette entrée n'est pas un objet JSON : ignorée.";

            return null;
        }

        $title = $item['titre'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $entry->status = AssociationProjectImportEntry::STATUS_INVALID;
            $entry->errors[] = 'Titre absent ou vide : entrée ignorée.';

            return null;
        }

        foreach (array_keys($item) as $key) {
            if (!in_array((string) $key, self::KNOWN_KEYS, true)) {
                $entry->errors[] = sprintf('Clé inconnue « %s » : ignorée.', $key);
            }
        }
        foreach (self::KNOWN_KEYS as $key) {
            if (!array_key_exists($key, $item)) {
                $entry->errors[] = sprintf('Clé « %s » absente : traitée comme null.', $key);
            }
        }

        if (($titleCounts[$title] ?? 0) > 1) {
            $entry->status = AssociationProjectImportEntry::STATUS_DUPLICATE;
            $entry->errors[] = sprintf('Ce titre apparaît %d fois dans le JSON : fiche non touchée.', $titleCounts[$title]);

            return null;
        }

        // Égalité STRICTE du titre (consigne). La fiche est verrouillée jusqu'à la fin de
        // la transaction et RELUE (HINT_REFRESH) : on compare avec l'état réel de la base.
        $query = $this->em->createQueryBuilder()
            ->select('p')
            ->from(AssociationProject::class, 'p')
            ->where('p.title = :title')
            ->setParameter('title', $title)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        /** @var list<AssociationProject> $found */
        $found = $query->getResult();

        if ($found === []) {
            $entry->status = AssociationProjectImportEntry::STATUS_NOT_FOUND;
            $message = "Aucune fiche n'a exactement ce titre : rien n'est créé.";
            $similar = $this->similarTitle($title);
            if ($similar !== null) {
                $message .= sprintf(
                    ' Une fiche a un titre presque identique (« %s ») : vérifiez les espaces, apostrophes, accents ou majuscules dans le JSON.',
                    $similar,
                );
            }
            $entry->errors[] = $message;

            return null;
        }
        if (count($found) > 1) {
            $entry->status = AssociationProjectImportEntry::STATUS_DUPLICATE;
            $entry->errors[] = sprintf('%d fiches portent ce titre en base : aucune n\'est touchée.', count($found));

            return null;
        }

        $project = $found[0];
        if (in_array($project->getSlug(), self::EXCLUDED_SLUGS, true)) {
            $entry->status = AssociationProjectImportEntry::STATUS_EXCLUDED;
            $entry->errors[] = "Fiche exclue de l'import (consigne) : non touchée.";

            return null;
        }
        if ($project->isPublished()) {
            $entry->status = AssociationProjectImportEntry::STATUS_PUBLISHED;
            $entry->errors[] = 'Fiche déjà publiée : non remplie, pour que rien de non relu ne soit visible des partenaires.';

            return null;
        }

        $alreadyImported = $journal[(string) $project->getId()] ?? [];

        $this->compareEnum($item['type'] ?? null, AssociationProjectType::cases(), $project->getType(), 'type', $entry);
        $this->compareEnum($item['etat'] ?? null, AssociationProjectState::cases(), $project->getState(), 'état', $entry);

        // ── Champs texte ──────────────────────────────────────────────────────
        $baselineErrors = AssociationProjectData::fromEntity($project)->validate();
        $texts = [];
        foreach (self::TEXT_FIELDS as $jsonKey => $label) {
            $raw = $item[$jsonKey] ?? null;
            if ($raw !== null && !is_string($raw)) {
                $entry->errors[] = sprintf('%s : valeur de type %s, texte attendu. Champ non rempli.', $label, get_debug_type($raw));
                continue;
            }
            $value = $raw === null ? null : self::normalize($raw);
            if ($value === null) {
                $entry->nullInJson[] = $label;
                continue;
            }

            $current = $this->getValue($project, $jsonKey);
            if (!self::isBlank($current)) {
                if (self::normalize((string) $current) === $value) {
                    $entry->alreadySame[] = $label;
                } else {
                    $entry->alreadyDifferent[] = $label;
                }
                continue;
            }
            if (in_array($jsonKey, $alreadyImported, true)) {
                $entry->notRefilled[] = $label;
                continue;
            }

            $fieldErrors = $this->validateField($project, $jsonKey, $value, $baselineErrors);
            if ($fieldErrors !== []) {
                foreach ($fieldErrors as $error) {
                    $entry->errors[] = $error . ' Champ non rempli.';
                }
                continue;
            }

            $texts[$jsonKey] = $value;
            $entry->filled[] = $label;
        }

        // ── Visuel ────────────────────────────────────────────────────────────
        $imageSource = null;
        $rawImage = $item['visuel'] ?? null;
        if ($rawImage !== null && !is_string($rawImage)) {
            $entry->errors[] = sprintf('visuel : valeur de type %s, nom de fichier attendu. Visuel non envoyé.', get_debug_type($rawImage));
        } elseif ($rawImage === null || $rawImage === '') {
            $entry->nullInJson[] = self::IMAGE_LABEL;
        } elseif ($project->getImagePath() !== null) {
            // On ne peut pas comparer deux images : un visuel envoyé par un import
            // précédent compte comme « identique », un visuel de l'équipe comme « autre ».
            if (in_array(self::IMAGE_LABEL, $alreadyImported, true)) {
                $entry->alreadySame[] = self::IMAGE_LABEL;
            } else {
                $entry->alreadyDifferent[] = self::IMAGE_LABEL;
            }
        } elseif (in_array(self::IMAGE_LABEL, $alreadyImported, true)) {
            $entry->notRefilled[] = self::IMAGE_LABEL;
        } else {
            [$imageSource, $imageError] = $this->resolveImage($imagesDir, $rawImage);
            if ($imageError !== null) {
                $entry->errors[] = 'visuel : ' . $imageError . ' Visuel non envoyé.';
            }
        }

        if ($texts === [] && $imageSource === null) {
            return null;
        }

        return ['entry' => $entry, 'project' => $project, 'texts' => $texts, 'imageSource' => $imageSource, 'storedImage' => null];
    }

    /**
     * Type et état : jamais écrits (ils ne sont jamais vides), seulement comparés.
     * Le JSON peut donner le code (« residency ») ou le libellé du back-office (« Résidence »).
     *
     * @param list<AssociationProjectType>|list<AssociationProjectState> $cases
     */
    private function compareEnum(
        mixed $raw,
        array $cases,
        AssociationProjectType|AssociationProjectState $current,
        string $label,
        AssociationProjectImportEntry $entry,
    ): void {
        if ($raw === null) {
            return;
        }
        if (!is_string($raw)) {
            $entry->errors[] = sprintf('%s : valeur de type %s, texte attendu.', $label, get_debug_type($raw));

            return;
        }
        $value = self::normalize($raw);
        if ($value === null) {
            return;
        }
        foreach ($cases as $case) {
            if ($case->value === $value || $case->label() === $value) {
                if ($case !== $current) {
                    $entry->typeStateDifferences[] = $label;
                }

                return;
            }
        }
        $entry->errors[] = sprintf("%s : valeur absente de la liste du back-office. Rien n'est modifié.", $label);
    }

    /**
     * Applique les règles du formulaire du back-office à UN champ : on part de la fiche
     * telle qu'elle est, on y place la valeur à importer, et on ne garde que les erreurs
     * nouvelles (celles que la fiche avait déjà ne concernent pas ce champ).
     *
     * @param list<string> $baselineErrors
     *
     * @return list<string>
     */
    private function validateField(AssociationProject $project, string $jsonKey, string $value, array $baselineErrors): array
    {
        $data = AssociationProjectData::fromEntity($project);
        match ($jsonKey) {
            'dates'                  => $data->period = $value,
            'lieux'                  => $data->places = $value,
            'artistes_concernes'     => $data->artists = $value,
            'description'            => $data->description = $value,
            'partenaires_confirmes'  => $data->confirmedPartners = $value,
            'partenaires_pressentis' => $data->prospectivePartners = $value,
            'financeurs_sollicites'  => $data->solicitedFunders = $value,
            'apport_partenaire'      => $data->partnerContribution = $value,
            'referente'              => $data->leadName = $value,
            default                  => throw new \LogicException(sprintf('Champ inconnu : %s', $jsonKey)),
        };

        return array_values(array_diff($data->validate(), $baselineErrors));
    }

    private function getValue(AssociationProject $project, string $jsonKey): ?string
    {
        return match ($jsonKey) {
            'dates'                  => $project->getPeriod(),
            'lieux'                  => $project->getPlaces(),
            'artistes_concernes'     => $project->getArtists(),
            'description'            => $project->getDescription(),
            'partenaires_confirmes'  => $project->getConfirmedPartners(),
            'partenaires_pressentis' => $project->getProspectivePartners(),
            'financeurs_sollicites'  => $project->getSolicitedFunders(),
            'apport_partenaire'      => $project->getPartnerContribution(),
            'referente'              => $project->getLeadName(),
            default                  => throw new \LogicException(sprintf('Champ inconnu : %s', $jsonKey)),
        };
    }

    private function setValue(AssociationProject $project, string $jsonKey, string $value): void
    {
        match ($jsonKey) {
            'dates'                  => $project->setPeriod($value),
            'lieux'                  => $project->setPlaces($value),
            'artistes_concernes'     => $project->setArtists($value),
            'description'            => $project->setDescription($value),
            'partenaires_confirmes'  => $project->setConfirmedPartners($value),
            'partenaires_pressentis' => $project->setProspectivePartners($value),
            'financeurs_sollicites'  => $project->setSolicitedFunders($value),
            'apport_partenaire'      => $project->setPartnerContribution($value),
            'referente'              => $project->setLeadName($value),
            default                  => throw new \LogicException(sprintf('Champ inconnu : %s', $jsonKey)),
        };
    }

    /**
     * Retrouve le fichier du visuel dans images/, sans jamais pouvoir en sortir.
     *
     * Le nom doit désigner DIRECTEMENT un fichier de images/ : « ../ », sous-dossier,
     * fichier caché ou lien symbolique qui sort du dossier sont refusés (on ne tronque
     * jamais un nom, on refuse). Si le nom exact est absent, on compare les noms en
     * forme Unicode NFC : un Mac peut avoir écrit « é » en deux caractères.
     *
     * @return array{0: ?string, 1: ?string} [chemin réel du fichier, message d'erreur]
     */
    private function resolveImage(string $imagesDir, string $name): array
    {
        if ($name !== basename($name) || str_contains($name, '/') || str_contains($name, '\\')
            || str_contains($name, "\0") || str_starts_with($name, '.')) {
            return [null, 'nom de fichier refusé : il doit désigner directement un fichier du dossier images/.'];
        }

        $base = realpath($imagesDir);
        if ($base === false || !is_dir($base)) {
            return [null, 'dossier images/ introuvable.'];
        }

        $candidate = $base . DIRECTORY_SEPARATOR . $name;
        if (!is_file($candidate)) {
            $wanted = self::nfc($name);
            $matches = [];
            foreach (scandir($base) ?: [] as $file) {
                if ($file !== '.' && $file !== '..' && self::nfc($file) === $wanted) {
                    $matches[] = $file;
                }
            }
            if (count($matches) !== 1) {
                return [null, 'fichier introuvable dans images/ (attention : le serveur distingue majuscules et minuscules).'];
            }
            $candidate = $base . DIRECTORY_SEPARATOR . $matches[0];
        }

        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return [null, 'fichier refusé : il se trouve en dehors du dossier images/.'];
        }

        return [$real, null];
    }

    /**
     * Envoie un visuel par le mécanisme d'upload existant.
     *
     * store() DÉPLACE le fichier reçu (comme pour un vrai envoi de formulaire) : on lui
     * passe donc une COPIE temporaire, pour ne jamais toucher au fichier source. La copie
     * est toujours effacée à la fin, que l'image soit acceptée ou refusée.
     */
    private function storeImage(AssociationProjectImageStorage $storage, string $sourcePath): StoredImage|string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'asso-import-');
        if ($temporary === false) {
            return 'copie temporaire impossible.';
        }

        try {
            if (!copy($sourcePath, $temporary)) {
                return 'copie temporaire impossible.';
            }

            // Mode « test » d'UploadedFile : le fichier ne vient pas d'un formulaire HTTP
            // (is_uploaded_file() serait faux). Tous les contrôles de store() s'appliquent.
            return $storage->store(new UploadedFile($temporary, basename($sourcePath), null, \UPLOAD_ERR_OK, true));
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return list<mixed>|null la liste des fiches, ou null si le fichier est inutilisable */
    private function readItems(string $dir, AssociationProjectImportReport $report): ?array
    {
        if (!is_dir($dir)) {
            $report->globalErrors[] = sprintf('Dossier introuvable : %s', $dir);

            return null;
        }

        $file = $dir . DIRECTORY_SEPARATOR . self::DATA_FILE;
        $json = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($json === false) {
            $report->globalErrors[] = sprintf('Fichier introuvable ou illisible : %s', $file);

            return null;
        }
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3); // marque BOM ajoutée par certains éditeurs
        }

        try {
            $decoded = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $report->globalErrors[] = sprintf("%s n'est pas un JSON valide (%s).", self::DATA_FILE, $exception->getMessage());

            return null;
        }

        // Deux formes acceptées : une liste de fiches, ou { "projets": [ … ] }.
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }
        if (is_array($decoded) && is_array($decoded['projets'] ?? null) && array_is_list($decoded['projets'])) {
            return $decoded['projets'];
        }

        $report->globalErrors[] = sprintf('%s doit contenir une liste de fiches, ou un objet avec une clé « projets ».', self::DATA_FILE);

        return null;
    }

    /**
     * @return array<string, list<string>> id de fiche => clés JSON déjà remplies par un import
     */
    private function readJournal(string $path, AssociationProjectImportReport $report): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        try {
            $data = json_decode((string) $raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data) || !is_array($data['fiches'] ?? null)) {
            $report->globalErrors[] = sprintf(
                "Journal d'import illisible (%s) : import bloqué, pour ne pas re-remplir des champs vidés par l'équipe.",
                $path,
            );

            return [];
        }

        $journal = [];
        foreach ($data['fiches'] as $id => $fields) {
            $journal[(string) $id] = is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
        }

        return $journal;
    }

    /**
     * Ajoute au journal les champs remplis par cette exécution. Écriture atomique
     * (fichier temporaire puis renommage) : le journal n'est jamais à moitié écrit.
     *
     * @param array<string, list<string>> $journal
     * @param list<array{entry: AssociationProjectImportEntry, project: AssociationProject, texts: array<string, string>, imageSource: ?string, storedImage: ?StoredImage}> $plans
     */
    private function writeJournal(string $path, array $journal, array $plans, AssociationProjectImportReport $report): void
    {
        $changed = false;
        foreach ($plans as $plan) {
            $keys = array_keys($plan['texts']);
            if ($plan['storedImage'] !== null) {
                $keys[] = self::IMAGE_LABEL;
            }
            if ($keys === []) {
                continue;
            }
            $id = (string) $plan['project']->getId();
            $journal[$id] = array_values(array_unique([...($journal[$id] ?? []), ...$keys]));
            $changed = true;
        }
        if (!$changed) {
            return;
        }

        $payload = [
            'note'   => "Journal de la commande app:import-association-projects : champs déjà remplis par l'import, à ne jamais re-remplir. Ne pas modifier ni supprimer tant qu'un import peut être relancé.",
            'fiches' => (object) $journal,
        ];
        $temporary = $path . '.tmp';
        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            $report->globalErrors[] = sprintf(
                "L'import est enregistré, mais le journal (%s) n'a pas pu être écrit : ne relancez pas l'import avant d'avoir réglé ce problème.",
                $path,
            );
        }
    }

    /**
     * Vérifie AVANT toute écriture que les dossiers sont accessibles : sinon, en
     * production, l'erreur n'apparaîtrait qu'au milieu de l'import réel.
     */
    private function checkWritable(string $dir, string $journalPath, AssociationProjectImportReport $report): void
    {
        $user = self::currentUser();

        $storage = self::firstExistingDirectory($this->storageDir);
        if (!is_writable($storage)) {
            $report->globalErrors[] = sprintf(
                "Le dossier des images (%s) n'est pas accessible en écriture pour l'utilisateur « %s ».",
                $storage,
                $user,
            );
        }

        $journalTarget = is_file($journalPath) ? $journalPath : $dir;
        if (!is_writable($journalTarget)) {
            $report->globalErrors[] = sprintf(
                "Le journal d'import (%s) ne peut pas être écrit par l'utilisateur « %s ».",
                $journalTarget,
                $user,
            );
        }
    }

    /** Titre d'une fiche qui ne diffère que par des détails invisibles (espaces, apostrophes, accents, casse). */
    private function similarTitle(string $title): ?string
    {
        $wanted = self::looseKey($title);
        /** @var list<string> $titles */
        $titles = $this->em->createQuery('SELECT p.title FROM ' . AssociationProject::class . ' p')->getSingleColumnResult();
        foreach ($titles as $candidate) {
            if (self::looseKey($candidate) === $wanted) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveDirectory(string $directory): string
    {
        $path = str_starts_with($directory, '/') ? $directory : $this->projectDir . DIRECTORY_SEPARATOR . $directory;

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Normalisation d'un texte importé : forme Unicode NFC, retours à la ligne en « \n »,
     * espaces (y compris insécables) retirés au début et à la fin. Vide → null.
     */
    private static function normalize(string $value): ?string
    {
        $value = str_replace(["\r\n", "\r"], "\n", self::nfc($value));
        $value = preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }

    private static function isBlank(?string $value): bool
    {
        return $value === null || self::normalize($value) === null;
    }

    private static function nfc(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $value;
    }

    /** Clé de comparaison « souple », utilisée SEULEMENT pour suggérer un titre voisin. */
    private static function looseKey(string $value): string
    {
        $value = strtr(self::nfc($value), [
            "\u{2019}" => "'", "\u{2018}" => "'", "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ',
        ]);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    private static function firstExistingDirectory(string $dir): string
    {
        while (!is_dir($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return $info['name'];
            }
        }

        return get_current_user();
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
