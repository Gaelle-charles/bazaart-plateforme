<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ScrapedOpportunity;
use App\Repository\ResourceTypeRepository;
use Psr\Log\LoggerInterface;

/**
 * GrantCsvImporter — Importe un CSV de grants/opportunités en ScrapedResource.
 *
 * Ce service est la logique métier de la commande `app:import-grant-csv`.
 * Il gère :
 *   1. Lecture et parsing du CSV (colonnes : OPEN CALLS, DEADLINE, CATEGORY, $, WHERE, NOTES, LINK)
 *   2. Extraction d'une URL propre depuis le champ LINK (souvent "Texte | ... (domaine.com)" ou URL complète)
 *   3. Mapping CATEGORY → libellé ResourceType existant en BDD
 *   4. Parsing de la DEADLINE au format MM/DD (ambiguïté : interprété comme mois/jour, année courante ou suivante)
 *   5. Conversion du code pays (ex: "FR" → "France")
 *   6. Déduplication par URL (idempotent, relançable sans doublons)
 *   7. Rapport des entrées ignorées (sans URL exploitable)
 *
 * PHILOSOPHIE :
 *   → Aucune création de ResourceType ou Discipline inconnu — on se contente de ce qui est en BDD.
 *   → Si une ligne n'a pas d'URL, elle est ignorée et ajoutée au rapport.
 *   → Idempotent : relancer la commande ne crée pas de doublons (déduplication par URL).
 *   → Robuste : ne plante jamais sur une ligne mal formée — elle est ignorée avec un message.
 */
class GrantCsvImporter
{
    /**
     * Mapping CATEGORY CSV → libellé de ResourceType BDD.
     *
     * Les clés sont en MAJUSCULES (on normalise la casse avant comparaison).
     * Les catégories non reconnues tombent dans le type par défaut "Appel à projets".
     *
     * Logique de mapping :
     *   AWARD / PRIZE / CONTEST / COMPETITION → "Prix & Concours"
     *   GRANT / GRANTS / FELLOWSHIP / COMMISSION / TRAVEL → "Bourse & Financement"
     *   RESIDENCY → "Résidence artistique"
     *   Tout le reste (FESTIVAL, ART FAIR, OPEN CALLS, vide...) → "Appel à projets" (défaut)
     *
     * @var array<string, string>
     */
    private const CATEGORY_MAP = [
        // Prix & Concours
        'AWARD'         => 'Prix & Concours',
        'PRIZE'         => 'Prix & Concours',
        'CONTEST'       => 'Prix & Concours',
        'COMPETITION'   => 'Prix & Concours',

        // Bourse & Financement
        'GRANT'         => 'Bourse & Financement',
        'GRANTS'        => 'Bourse & Financement',
        'FELLOWSHIP'    => 'Bourse & Financement',
        'COMMISSION'    => 'Bourse & Financement',
        'TRAVEL'        => 'Bourse & Financement',

        // Résidence artistique
        'RESIDENCY'     => 'Résidence artistique',
        'RESIDENCIES'   => 'Résidence artistique',

        // Appel à projets = défaut, donc on n'a pas besoin de tout lister ici,
        // mais on peut expliciter les catégories qui y tombent clairement :
        'FESTIVAL'          => 'Appel à projets',
        'ART FAIR'          => 'Appel à projets',
        'EXHIBITION'        => 'Appel à projets',
        'GALLERY'           => 'Appel à projets',
        'BOOK PUBLISHING'   => 'Appel à projets',
        'PUBLICATION'       => 'Appel à projets',
        'MAGAZINE'          => 'Appel à projets',
        'OPEN CALLS'        => 'Appel à projets',
        'OPEN CALL'         => 'Appel à projets',
        'CURATORIAL'        => 'Appel à projets',
        'PORTFOLIO REVIEW'  => 'Appel à projets',
    ];

    /**
     * Type par défaut si la CATEGORY ne correspond à aucune entrée du mapping.
     * On utilise "Appel à projets" car c'est la catégorie la plus générique.
     */
    private const DEFAULT_TYPE = 'Appel à projets';

    /**
     * Mapping des codes pays ISO 2 lettres vers les noms en clair (français).
     *
     * Cette liste couvre les codes qui apparaissent fréquemment dans le CSV Gaëlle.
     * Si le code n'est pas trouvé, on retourne le code brut (mieux que rien).
     *
     * @var array<string, string>
     */
    private const COUNTRY_CODES = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'CH' => 'Suisse',
        'CA' => 'Canada',
        'US' => 'États-Unis',
        'UK' => 'Royaume-Uni',
        'GB' => 'Royaume-Uni',
        'DE' => 'Allemagne',
        'NL' => 'Pays-Bas',
        'ES' => 'Espagne',
        'IT' => 'Italie',
        'PT' => 'Portugal',
        'SE' => 'Suède',
        'NO' => 'Norvège',
        'DK' => 'Danemark',
        'FI' => 'Finlande',
        'AT' => 'Autriche',
        'PL' => 'Pologne',
        'IE' => 'Irlande',
        'GR' => 'Grèce',
        'CZ' => 'République tchèque',
        'RO' => 'Roumanie',
        'HU' => 'Hongrie',
        'SN' => 'Sénégal',
        'CI' => "Côte d'Ivoire",
        'CM' => 'Cameroun',
        'MA' => 'Maroc',
        'TN' => 'Tunisie',
        'DZ' => 'Algérie',
        'NG' => 'Nigeria',
        'GH' => 'Ghana',
        'KE' => 'Kenya',
        'ZA' => 'Afrique du Sud',
        'CD' => 'RD Congo',
        'JP' => 'Japon',
        'CN' => 'Chine',
        'AU' => 'Australie',
        'BR' => 'Brésil',
        'MX' => 'Mexique',
        'AR' => 'Argentine',
        'EU' => 'Europe',
    ];

    /**
     * Cache des libellés de ResourceType existants en BDD.
     *
     * Rempli au premier appel de resolveResourceTypeLabel() (lazy loading).
     * On ne stocke que les NOMS (string) — pas les entités — car ScrapedResource::type
     * est un champ string (libellé libre), pas une FK vers resource_types.
     *
     * @var string[]|null
     */
    private ?array $existingTypeLabels = null;

    public function __construct(
        // Repository pour vérifier les libellés de types existants en BDD
        private readonly ResourceTypeRepository $resourceTypeRepository,
        // Logger pour tracer les avertissements de parsing
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lit le CSV et retourne la liste des ScrapedOpportunity prêts à être persistés,
     * plus un rapport des lignes ignorées.
     *
     * Cette méthode est le cœur du service. Elle parcourt chaque ligne du CSV,
     * applique les transformations (URL, category, deadline, country) et décide
     * si la ligne est importable ou ignorée.
     *
     * IDEMPOTENCE :
     *   Contrairement à ScrapedResourcePersister (qui déduplique au niveau BDD),
     *   ce service déduplique aussi en MÉMOIRE intra-lot via un set d'URLs vues.
     *   La déduplication BDD est toujours faite par ScrapedResourcePersister.
     *
     * @param string    $csvPath  Chemin absolu vers le fichier CSV
     * @param int|null  $limit    Nombre max de lignes valides à traiter (null = tout)
     * @param bool      $isDryRun Si true, on ne prépare que le rapport (utile pour dry-run)
     * @return ImportResult Opportunités à persister + rapport des ignorées
     *
     * @throws \InvalidArgumentException Si le fichier n'existe pas ou n'est pas lisible
     * @throws \RuntimeException Si le CSV ne peut pas être ouvert
     */
    public function parseFile(string $csvPath, ?int $limit = null, bool $isDryRun = false): ImportResult
    {
        // ── Validation du fichier ──────────────────────────────────────────────
        if (!file_exists($csvPath)) {
            throw new \InvalidArgumentException(sprintf(
                'Le fichier CSV n\'existe pas : %s',
                $csvPath
            ));
        }

        if (!is_readable($csvPath)) {
            throw new \InvalidArgumentException(sprintf(
                'Le fichier CSV n\'est pas lisible (permissions ?) : %s',
                $csvPath
            ));
        }

        // ── Ouverture du fichier CSV ───────────────────────────────────────────
        // SplFileObject est la façon idiomatique PHP de lire un CSV ligne par ligne.
        // Elle gère l'échappement des guillemets, les séparateurs dans les champs, etc.
        $file = new \SplFileObject($csvPath, 'r');
        $file->setFlags(
            \SplFileObject::READ_CSV           // Active le parsing CSV automatique
            | \SplFileObject::SKIP_EMPTY       // Ignore les lignes entièrement vides
            | \SplFileObject::READ_AHEAD       // Lecture en avance (performances)
            | \SplFileObject::DROP_NEW_LINE    // Supprime les \n en fin de champ
        );
        $file->setCsvControl(',', '"', '\\'); // Séparateur virgule, délimiteur guillemets

        // ── Lecture de l'en-tête ───────────────────────────────────────────────
        // La première ligne contient les noms de colonnes.
        // On les normalise (trim + majuscules) pour un accès robuste.
        $rawHeader = $file->current();
        if (!is_array($rawHeader)) {
            throw new \RuntimeException('Le CSV est vide ou son en-tête n\'est pas lisible.');
        }

        // ── Suppression du BOM UTF-8 (AV-1) ───────────────────────────────────
        // Google Sheets et Excel exportent parfois un BOM (Byte Order Mark) en tête
        // de fichier UTF-8 : les 3 octets \xEF\xBB\xBF, invisibles à l'œil nu.
        // Sans ce nettoyage, la première colonne devient "\xEF\xBB\xBFOPEN CALLS"
        // au lieu de "OPEN CALLS" → le mapping ne la trouve pas → TOUTES les lignes
        // sont ignorées (rawTitle = '').
        // ltrim() avec les 3 octets BOM supprime le préfixe si présent, sans rien
        // faire si absent (idempotent).
        if (isset($rawHeader[0])) {
            $rawHeader[0] = ltrim($rawHeader[0], "\xEF\xBB\xBF");
        }

        // Normalisation des noms de colonnes : trim + majuscules pour la comparaison
        // Ex: " Open Calls " → "OPEN CALLS", "link" → "LINK"
        $header = array_map(
            static fn (string $col): string => strtoupper(trim($col)),
            $rawHeader
        );

        $file->next(); // Passer à la première ligne de données

        // ── Variables de tracking ──────────────────────────────────────────────
        /** @var ScrapedOpportunity[] $opportunities */
        $opportunities = [];

        /** @var array<int, array{title: string, reason: string}> $ignoredLines */
        $ignoredLines = [];

        /** @var array<string, true> $seenUrls */
        $seenUrls = []; // Guard intra-lot : évite les doublons dans le même CSV

        $lineNumber    = 1; // Compteur de lignes de données (hors en-tête)
        $validCount    = 0; // Nombre de lignes valides traitées (pour --limit)

        // ── Boucle principale ─────────────────────────────────────────────────
        while ($file->valid()) {
            // Arrêt si --limit atteint
            if ($limit !== null && $validCount >= $limit) {
                break;
            }

            /** @var array<int, string>|null $rawRow */
            $rawRow = $file->current();
            $file->next();

            // Une ligne vide (SKIP_EMPTY ne suffit pas toujours) — on ignore
            if (!is_array($rawRow) || count($rawRow) < 2) {
                $lineNumber++;
                continue;
            }

            // ── Mapping en-tête → valeur ────────────────────────────────────────
            // On crée un tableau associatif nom_colonne → valeur pour chaque ligne.
            // Les colonnes supplémentaires (ou manquantes) sont gérées proprement.
            /** @var array<string, string> $row */
            $row = [];
            foreach ($header as $idx => $colName) {
                $row[$colName] = isset($rawRow[$idx]) ? trim($rawRow[$idx]) : '';
            }

            // ── Extraction des valeurs ──────────────────────────────────────────
            // On accède aux colonnes par leur nom normalisé.
            $rawTitle    = $row['OPEN CALLS'] ?? '';
            $rawDeadline = $row['DEADLINE']   ?? '';
            $rawCategory = $row['CATEGORY']   ?? '';
            $rawCountry  = $row['WHERE']       ?? '';
            $rawNotes    = $row['NOTES']       ?? '';
            $rawLink     = $row['LINK']        ?? '';

            $lineNumber++;

            // Titre obligatoire — une ligne sans titre n'est pas importable
            $title = trim($rawTitle);
            if ($title === '') {
                $ignoredLines[] = [
                    'title'  => "(ligne $lineNumber — titre vide)",
                    'reason' => 'Titre (OPEN CALLS) vide',
                ];
                continue;
            }

            // ── Extraction de l'URL depuis le champ LINK ───────────────────────
            // Le champ LINK peut avoir plusieurs formats :
            //   1. URL complète : "https://www.example.com/apply"
            //   2. Texte avec domaine entre parenthèses : "Candidater ici (example.com)"
            //   3. Format pipe : "Texte | Description | (domaine.com)"
            //   4. Vide ou "-" : aucune URL → ligne ignorée
            $url = $this->extractUrl($rawLink);

            if ($url === null) {
                // Aucune URL exploitable → ligne ignorée, ajoutée au rapport
                $ignoredLines[] = [
                    'title'  => $title,
                    'reason' => sprintf(
                        'Aucune URL exploitable (LINK brut : "%s")',
                        mb_substr($rawLink, 0, 80)
                    ),
                ];
                continue;
            }

            // ── Déduplication intra-lot ─────────────────────────────────────────
            // Si la même URL apparaît deux fois dans le CSV, on n'importe que la première.
            if (isset($seenUrls[$url])) {
                $ignoredLines[] = [
                    'title'  => $title,
                    'reason' => sprintf('URL en doublon dans le CSV (déjà vue : %s)', $url),
                ];
                continue;
            }
            $seenUrls[$url] = true;

            // ── Mapping CATEGORY → type de ResourceType ─────────────────────────
            // On normalise la casse et on cherche dans CATEGORY_MAP.
            // Si la catégorie est inconnue → type par défaut "Appel à projets".
            $typeLabel = $this->mapCategoryToType($rawCategory);

            // ── Vérification que le type existe en BDD ──────────────────────────
            // On ne crée JAMAIS un ResourceType inconnu (contrainte du projet).
            // Si le libellé n'existe pas en BDD, on tombe sur le type par défaut.
            $typeLabel = $this->resolveResourceTypeLabel($typeLabel);

            // ── Parsing de la deadline ──────────────────────────────────────────
            // Format CSV : "MM/DD" (mois/jour, SANS l'année)
            // On interprète l'année comme l'année courante, ou l'année suivante
            // si la date est déjà passée.
            $deadlineStr = $this->parseDeadlineFromCsv($rawDeadline);

            // ── Conversion du code pays ─────────────────────────────────────────
            // "FR" → "France", "BE" → "Belgique", etc.
            // Si le code est inconnu, on retourne le code brut (mieux que vide).
            $country = $this->resolveCountry($rawCountry);

            // ── Extraction du sourceSite (domaine) depuis l'URL ─────────────────
            // Ex: "https://www.fondation-example.com/apply" → "fondation-example.com"
            $sourceSite = $this->extractDomain($url);

            // ── Construction du ScrapedOpportunity ─────────────────────────────
            // On utilise le DTO existant qui est ensuite passé à ScrapedResourcePersister.
            // Les champs city et experienceLevel sont laissés vides (seront remplis par --enrich).
            $opportunity = new ScrapedOpportunity(
                title: $title,
                type: $typeLabel,
                url: $url,
                source: $sourceSite,
                description: $rawNotes !== '' ? mb_substr($rawNotes, 0, 200) : '',
                deadline: $deadlineStr,
                disciplines: '',  // Rempli par enrichissement LLM si --enrich
                documents: '',
                relevanceScore: 0,
                publishedAt: null,
                city: '',
                country: $country,
                experienceLevel: '',
                disciplinesLabels: [],
            );

            $opportunities[] = $opportunity;
            $validCount++;
        }

        return new ImportResult(
            opportunities: $opportunities,
            ignoredLines: $ignoredLines,
        );
    }

    /**
     * Extrait une URL propre depuis le contenu du champ LINK.
     *
     * Le CSV de Gaëlle contient des formats très variés dans ce champ :
     *   - URL complète          : "https://www.example.com/call-for-artists"
     *   - Domaine seul          : "example.com"
     *   - Texte (domaine.com)   : "Candidater ici (example.com)"
     *   - Pipe + domaine        : "Titre | Description (example.com)"
     *   - Vide ou "-"           : aucune URL
     *
     * STRATÉGIE DE PARSING (dans l'ordre) :
     *   1. Si vide ou "-" → null (aucune URL)
     *   2. Si commence par "http://" ou "https://" → URL complète, retourner telle quelle
     *   3. Chercher une URL complète (http/https) dans le texte
     *   4. Chercher un domaine entre parenthèses "(example.com)" → construire https://example.com
     *   5. Chercher un domaine nu après le dernier "|" (format pipe)
     *   6. Tester si le texte brut ressemble à un domaine (example.com)
     *
     * @param string $rawLink Valeur brute du champ LINK
     * @return string|null URL propre en https://, ou null si aucune URL trouvable
     */
    public function extractUrl(string $rawLink): ?string
    {
        $link = trim($rawLink);

        // ── Cas trivial : champ vide ou tiret ────────────────────────────────
        if ($link === '' || $link === '-' || $link === '—') {
            return null;
        }

        // ── Cas 1 : URL complète http/https ───────────────────────────────────
        // Si le lien commence directement par http(s)://, on valide et retourne
        if (preg_match('#^https?://\S+#i', $link, $matches)) {
            $url = rtrim($matches[0], '.,)'); // Nettoyer les ponctuations parasites en fin
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                return $url;
            }
        }

        // ── Cas 2 : URL http/https cachée dans le texte ───────────────────────
        // Ex: "Voir l'appel (https://example.com/apply)"
        if (preg_match('#https?://[^\s\)\'"]+#i', $link, $matches)) {
            $url = rtrim($matches[0], '.,)');
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                return $url;
            }
        }

        // ── Cas 3 : domaine entre parenthèses "(example.com)" ────────────────
        // Format très courant dans le CSV : "Candidater | More info (open.spotify.com)"
        // Regex : cherche (domaine.tld) avec ou sans sous-domaine
        if (preg_match('/\(([a-z0-9](?:[a-z0-9\-\.]{1,61}[a-z0-9])?\.[a-z]{2,}(?:\/[^\)]*)?)\)/i', $link, $matches)) {
            $candidate = trim($matches[1]);
            // On ajoute le schéma https:// si absent
            if (!str_starts_with($candidate, 'http')) {
                $candidate = 'https://' . $candidate;
            }
            if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                return $candidate;
            }
        }

        // ── Cas 4 : format "Texte | Texte | Texte (domaine.com)" ─────────────
        // On prend la dernière partie après le dernier "|" et on cherche un domaine
        if (str_contains($link, '|')) {
            $parts    = explode('|', $link);
            $lastPart = trim(end($parts));

            // Chercher un domaine entre parenthèses dans la dernière partie
            if (preg_match('/\(([a-z0-9][a-z0-9\-\.]*\.[a-z]{2,}(?:\/[^\)]*)?)\)/i', $lastPart, $m)) {
                $candidate = 'https://' . trim($m[1]);
                if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                    return $candidate;
                }
            }

            // Chercher un domaine nu dans la dernière partie (ex: "example.com")
            if (preg_match('/^([a-z0-9][a-z0-9\-\.]+\.[a-z]{2,})\s*$/i', $lastPart, $m)) {
                $candidate = 'https://' . trim($m[1]);
                if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                    return $candidate;
                }
            }
        }

        // ── Cas 5 : le champ est lui-même un domaine nu ───────────────────────
        // Ex: "fondation-example.com" (sans protocole)
        if (preg_match('/^([a-z0-9][a-z0-9\-\.]{2,61}\.[a-z]{2,})\s*$/i', $link, $matches)) {
            $candidate = 'https://' . $matches[1];
            if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                return $candidate;
            }
        }

        // ── Aucun pattern ne correspond ───────────────────────────────────────
        // On log en debug pour aider au diagnostic (pas de warning — c'est normal pour
        // certaines lignes du CSV qui n'ont vraiment aucune URL).
        $this->logger->debug('[GrantCsvImporter] Aucune URL trouvée dans le champ LINK : "{link}"', [
            'link' => mb_substr($link, 0, 100),
        ]);

        return null;
    }

    /**
     * Mappe une CATEGORY CSV vers un libellé de type ResourceType.
     *
     * La casse du CSV est incohérente ("GRANT", "grant", "Grant", "Grants").
     * On normalise en majuscules avant de consulter CATEGORY_MAP.
     *
     * Si la catégorie est vide ou inconnue → type par défaut DEFAULT_TYPE.
     *
     * @param string $rawCategory Valeur brute de la colonne CATEGORY
     * @return string Libellé de type (ex: "Bourse & Financement", "Résidence artistique")
     */
    public function mapCategoryToType(string $rawCategory): string
    {
        $normalized = strtoupper(trim($rawCategory));

        if ($normalized === '') {
            return self::DEFAULT_TYPE;
        }

        // Correspondance exacte d'abord
        if (isset(self::CATEGORY_MAP[$normalized])) {
            return self::CATEGORY_MAP[$normalized];
        }

        // ── Correspondance mot-entier (AV-3) ──────────────────────────────────
        // On cherche si un des patterns du CATEGORY_MAP est présent dans la catégorie
        // normalisée, mais UNIQUEMENT en tant que MOT ENTIER (pas sous-chaîne).
        //
        // POURQUOI preg_match et pas str_contains ?
        //   str_contains("IMMIGRANT", "GRANT") → true → faux positif !
        //   "IMMIGRANT" serait mappé en "Bourse & Financement" alors qu'il devrait
        //   tomber dans le type par défaut.
        //
        // Le pattern (?<![A-Z]) et (?![A-Z]) vérifient qu'il n'y a pas de lettre
        // majuscule immédiatement avant ou après le mot, ce qui garantit que le
        // pattern n'est pas un fragment d'un mot plus long.
        // Le flag /i rend la correspondance insensible à la casse (redondant avec la
        // normalisation strtoupper, mais plus robuste en cas d'évolution).
        //
        // Ex: "ART FAIR/FESTIVAL" → "ART FAIR" matchée mot entier → "Appel à projets" ✓
        //     "IMMIGRANT GRANT"   → "GRANT" matchée mot entier → "Bourse & Financement" ✓
        //     "IMMIGRANT"         → "GRANT" sous-chaîne, pas mot entier → SKIP ✓
        foreach (self::CATEGORY_MAP as $pattern => $type) {
            if (preg_match('/(?<![A-Z])' . preg_quote($pattern, '/') . '(?![A-Z])/i', $normalized)) {
                return $type;
            }
        }

        // Catégorie inconnue → type par défaut
        $this->logger->debug('[GrantCsvImporter] Catégorie non mappée "{cat}" → type par défaut', [
            'cat' => $rawCategory,
        ]);

        return self::DEFAULT_TYPE;
    }

    /**
     * Vérifie que le libellé de type existe en BDD et retourne le libellé confirmé.
     *
     * Charge la liste des types BDD en cache au premier appel (lazy loading).
     * Si le libellé n'existe pas en BDD → on retourne DEFAULT_TYPE à la place.
     *
     * Pourquoi cette vérification ?
     *   ScrapedResource::type est un champ string libre, mais les libellés doivent
     *   correspondre aux entrées de resource_types pour que l'admin puisse filtrer.
     *   On s'assure que les libellés du CSV correspondent à ce qui est en BDD.
     *
     * @param string $typeLabel Libellé proposé (issu de mapCategoryToType)
     * @return string Libellé confirmé (existe en BDD) ou DEFAULT_TYPE
     */
    private function resolveResourceTypeLabel(string $typeLabel): string
    {
        // Charger le cache des types BDD au premier appel
        if ($this->existingTypeLabels === null) {
            $types = $this->resourceTypeRepository->findAllOrdered();
            $this->existingTypeLabels = array_map(
                static fn ($rt) => $rt->getName(),
                $types
            );
        }

        // Vérifier que le libellé existe bien en BDD
        if (in_array($typeLabel, $this->existingTypeLabels, true)) {
            return $typeLabel;
        }

        // Le libellé n'existe pas en BDD — on utilise le type par défaut
        // On log en info pour aider Gaëlle à détecter si les fixtures sont incomplètes
        $this->logger->info('[GrantCsvImporter] Type "{type}" absent en BDD → fallback sur "{default}"', [
            'type'    => $typeLabel,
            'default' => self::DEFAULT_TYPE,
        ]);

        // Si même le type par défaut n'existe pas (BDD vide de fixtures) → retour du libellé brut
        // plutôt que de planter (le champ est nullable en pratique)
        return in_array(self::DEFAULT_TYPE, $this->existingTypeLabels, true)
            ? self::DEFAULT_TYPE
            : $typeLabel;
    }

    /**
     * Parse la deadline au format "MM/DD" (mois/jour sans année) du CSV.
     *
     * Le CSV de Gaëlle utilise ce format ambigu : "02/28" signifie le 28 février.
     *
     * STRATÉGIE D'INTERPRÉTATION :
     *   1. Extraire mois (MM) et jour (DD)
     *   2. Construire la date avec l'année COURANTE
     *   3. Si la date est déjà passée (< aujourd'hui) → utiliser l'année SUIVANTE
     *      (ex: si on est en juin 2026 et la deadline est "02/28" → 28/02/2027)
     *   4. Si le format est invalide ou vide → retourner chaîne vide (pas d'erreur)
     *
     * NOTE : On ne réutilise pas DeadlineParserService car ce service attend
     *   des formats différents (YYYY-MM-DD, DD/MM/YYYY, "31 mai 2026").
     *   Le format MM/DD du CSV est spécifique à cet import.
     *
     * @param string $rawDeadline Valeur brute (ex: "02/28", "12/31", "", "TBD")
     * @return string Deadline au format "YYYY-MM-DD" pour ScrapedResource::deadline, ou ""
     */
    public function parseDeadlineFromCsv(string $rawDeadline): string
    {
        $deadline = trim($rawDeadline);

        // Valeurs vides ou non informatives
        if ($deadline === '' || $deadline === '-' || $deadline === '—' || strtoupper($deadline) === 'TBD') {
            return '';
        }

        // Format attendu : MM/DD (ex: "02/28", "12/31")
        // On supporte aussi M/D (ex: "2/4" pour le 4 février)
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})$/', $deadline, $matches)) {
            // Format non reconnu (ex: "Spring 2026", "Ongoing")
            $this->logger->debug('[GrantCsvImporter] Deadline format non reconnu : "{d}"', [
                'd' => $deadline,
            ]);
            return '';
        }

        $month = (int) $matches[1];
        $day   = (int) $matches[2];

        // Validation basique du mois et du jour
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            $this->logger->debug('[GrantCsvImporter] Deadline valeurs invalides M={m} D={d}', [
                'm' => $month,
                'd' => $day,
            ]);
            return '';
        }

        // Construire la date avec l'année courante
        $currentYear = (int) date('Y');
        $dateStr     = sprintf('%04d-%02d-%02d', $currentYear, $month, $day);

        // Vérifier si la date est valide (ex: 02/30 n'existe pas)
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($parsed === false || $parsed->format('Y-m-d') !== $dateStr) {
            // Date invalide (ex: 30 février)
            $this->logger->debug('[GrantCsvImporter] Deadline date invalide : "{d}"', ['d' => $dateStr]);
            return '';
        }

        // Si la date est déjà passée → passer à l'année suivante
        // On compare uniquement les dates (pas les heures) pour éviter les edge cases
        $today = new \DateTimeImmutable('today');
        if ($parsed < $today) {
            $nextYear = $currentYear + 1;
            $dateStr  = sprintf('%04d-%02d-%02d', $nextYear, $month, $day);
            // Revalider avec la nouvelle année (ex: 29 fév peut être invalide)
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
            if ($parsed === false || $parsed->format('Y-m-d') !== $dateStr) {
                // 29 fév dans une année non bissextile — on retourne vide plutôt que planter
                return '';
            }
        }

        return $dateStr; // Format "YYYY-MM-DD" — compatible avec DeadlineParserService
    }

    /**
     * Convertit un code pays ISO 2 lettres en nom en clair (français).
     *
     * Si le code n'est pas reconnu, retourne le code tel quel (mieux que vide).
     * Si le champ WHERE est vide, retourne chaîne vide.
     *
     * @param string $rawCountry Valeur brute de la colonne WHERE (ex: "FR", "BE", "")
     * @return string Nom du pays en français, ou le code brut si inconnu, ou ""
     */
    public function resolveCountry(string $rawCountry): string
    {
        $code = strtoupper(trim($rawCountry));

        if ($code === '' || $code === '-') {
            return '';
        }

        return self::COUNTRY_CODES[$code] ?? $code;
    }

    /**
     * Extrait le domaine d'une URL complète.
     *
     * Utilisé pour remplir ScrapedResource::sourceSite.
     * Ex: "https://www.fondation-example.com/apply" → "fondation-example.com"
     *
     * @param string $url URL complète
     * @return string Domaine sans www., ou chaîne vide si l'URL n'est pas parseable
     */
    public function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return '';
        }

        // Supprimer le préfixe "www." pour avoir le domaine canonique
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return strtolower($host);
    }
}
