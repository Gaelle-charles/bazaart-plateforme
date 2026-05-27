<?php

namespace App\Service;

use App\DTO\ScrapedOpportunity;

/**
 * GoogleSheetsService — Écrit les opportunités scrapées dans un Google Sheet.
 *
 * Ce service utilise l'API Google Sheets v4 pour ajouter des lignes
 * dans un tableur Google. Il sert d'étape de contrôle intermédiaire :
 * les opportunités sont visibles dans le Sheet avant d'être importées en BDD.
 *
 * Configuration requise dans .env :
 *   GOOGLE_SHEETS_CREDENTIALS_PATH=/chemin/vers/service-account.json
 *   GOOGLE_SHEETS_ID=1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms  (l'ID du sheet)
 *
 * Pour configurer Google Sheets API → voir README ou CLAUDE.md
 *
 * @deprecated Service Google Sheets abandonné — le scraping écrit directement en BDD.
 *   Le passage de Google Sheets → BDD a été fait en mai 2026 (décision de la dev).
 *   Ce service est conservé pour ne pas perdre l'accès aux données historiques éventuelles.
 *   À supprimer en V2 après vérification qu'aucune donnée Google Sheets n'est manquante en BDD.
 *   Référence : tâche "Abandon Google Sheets" du 25 mai 2026.
 */
class GoogleSheetsService
{
    // Nom de la feuille (onglet) dans le Google Sheet
    private const SHEET_NAME = 'Opportunités scrapées';

    // En-têtes des colonnes (première ligne du tableur)
    // IMPORTANT : doit toujours correspondre à l'ordre des valeurs dans ScrapedOpportunity::toSheetRow()
    private const HEADERS = [
        'Titre',
        'Type',
        'Date limite',
        'Disciplines',
        'Description',
        'URL source',
        'Site',
        'Documents',           // URLs de documents PDF ou téléchargeables
        'Score Afrodiaspora',  // Score de pertinence Afrodiaspora (0 à 5 étoiles)
        'Date scraping',
        'Statut',
    ];

    public function __construct(
        // Chemin vers le fichier JSON du compte de service Google
        private readonly string $credentialsPath,
        // Identifiant du Google Sheet (dans l'URL : /spreadsheets/d/[ID]/edit)
        private readonly string $spreadsheetId,
    ) {
    }

    /**
     * Lit toutes les lignes de la feuille "Opportunités scrapées" et les retourne
     * sous forme de tableau de tableaux associatifs.
     *
     * La première ligne (en-têtes) est ignorée.
     * Si le fichier de credentials est absent ou si la feuille est vide, retourne [].
     *
     * Clés retournées pour chaque ligne :
     *   - titre        : titre de l'opportunité
     *   - type         : type (bourse, résidence, appel à projets…)
     *   - deadline     : date limite (format texte tel que stocké dans le sheet)
     *   - disciplines  : disciplines artistiques concernées
     *   - description  : description courte
     *   - url          : URL source de l'opportunité
     *   - site         : nom du site scraped
     *   - date_scraping: date à laquelle l'opportunité a été collectée
     *   - statut       : statut de validation (ex: "À valider", "Validée", "Rejetée")
     *
     * @return array<int, array<string, string>> Tableau de lignes, chaque ligne est un tableau associatif
     */
    public function readOpportunities(): array
    {
        // Si le fichier de credentials n'existe pas, on ne peut pas se connecter à Google
        // On retourne un tableau vide plutôt que de lever une exception (cas normal en dev)
        if (!file_exists($this->credentialsPath)) {
            return [];
        }

        try {
            $client  = $this->createGoogleClient();
            $service = new \Google\Service\Sheets($client);

            // Plage de lecture : toute la feuille (A1:K = 11 colonnes = les 11 en-têtes définis)
            // On lit jusqu'à la ligne 10000 pour couvrir tous les cas réalistes
            $range    = self::SHEET_NAME . '!A1:K10000';
            $response = $service->spreadsheets_values->get($this->spreadsheetId, $range);
            $values   = $response->getValues();

            // Si la feuille est vide ou n'a que l'en-tête, on retourne un tableau vide
            if (empty($values) || count($values) <= 1) {
                return [];
            }

            // La première ligne est l'en-tête → on commence à l'index 1
            $rows = [];
            for ($i = 1; $i < count($values); $i++) {
                $row = $values[$i];

                // On normalise : chaque colonne a un nom lisible
                // Si une cellule est vide, Google Sheets ne renvoie pas la valeur → on utilise ''
                $rows[] = [
                    'titre'              => $row[0] ?? '',
                    'type'               => $row[1] ?? '',
                    'deadline'           => $row[2] ?? '',
                    'disciplines'        => $row[3] ?? '',
                    'description'        => $row[4] ?? '',
                    'url'                => $row[5] ?? '',
                    'site'               => $row[6] ?? '',
                    'documents'          => $row[7] ?? '',   // URLs de documents PDF
                    'score_afrodiaspora' => $row[8] ?? '',   // Score en étoiles (ex: "★★★☆☆")
                    'date_scraping'      => $row[9] ?? '',
                    'statut'             => $row[10] ?? 'À vérifier',
                ];
            }

            return $rows;

        } catch (\Exception $e) {
            // En cas d'erreur API (quota dépassé, sheet introuvable, etc.)
            // On retourne un tableau vide pour ne pas bloquer l'interface admin
            return [];
        }
    }

    /**
     * Ajoute une liste d'opportunités dans Google Sheets.
     *
     * @param ScrapedOpportunity[] $opportunities Liste des opportunités à ajouter
     * @return int Nombre de lignes ajoutées
     *
     * @throws \RuntimeException Si la connexion à Google Sheets échoue
     */
    public function appendOpportunities(array $opportunities): int
    {
        if (empty($opportunities)) {
            return 0;
        }

        // Crée le client Google avec les credentials du compte de service
        $client = $this->createGoogleClient();
        $service = new \Google\Service\Sheets($client);

        // S'assure que la feuille existe et a les bons en-têtes
        $this->ensureSheetExists($service);

        // Prépare les lignes à insérer
        $rows = [];
        foreach ($opportunities as $opportunity) {
            $rows[] = $opportunity->toSheetRow();
        }

        // Appel API : ajoute les lignes à la suite des données existantes
        // "USER_ENTERED" signifie que Google interprète les valeurs (dates, nombres...)
        $body = new \Google\Service\Sheets\ValueRange([
            'values' => $rows,
        ]);

        $service->spreadsheets_values->append(
            $this->spreadsheetId,
            self::SHEET_NAME . '!A1',  // Démarre depuis A1, Google trouve la première ligne vide
            $body,
            ['valueInputOption' => 'USER_ENTERED']
        );

        return count($rows);
    }

    /**
     * Vérifie si la feuille "Opportunités scrapées" existe.
     * Si non, la crée. Si oui et qu'elle est vide, ajoute les en-têtes.
     * Dans les deux cas, applique la mise en forme (gras + dropdown Statut).
     */
    private function ensureSheetExists(\Google\Service\Sheets $service): void
    {
        // Récupère les métadonnées du tableur pour voir les feuilles existantes
        $spreadsheet = $service->spreadsheets->get($this->spreadsheetId);
        $sheets      = $spreadsheet->getSheets();

        $sheetExists = false;
        $sheetId     = null; // Identifiant numérique interne de la feuille (≠ nom)

        // On parcourt les feuilles pour trouver celle qui nous intéresse
        // et récupérer son identifiant numérique (nécessaire pour batchUpdate)
        foreach ($sheets as $sheet) {
            if ($sheet->getProperties()->getTitle() === self::SHEET_NAME) {
                $sheetExists = true;
                $sheetId     = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        // Si la feuille n'existe pas, on la crée
        if (!$sheetExists) {
            $addSheetRequest = new \Google\Service\Sheets\AddSheetRequest([
                'properties' => new \Google\Service\Sheets\SheetProperties([
                    'title' => self::SHEET_NAME,
                ]),
            ]);

            $batchRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => [new \Google\Service\Sheets\Request([
                    'addSheet' => $addSheetRequest,
                ])],
            ]);

            // On exécute la création et on récupère l'identifiant numérique
            // attribué automatiquement par Google à la nouvelle feuille
            $response = $service->spreadsheets->batchUpdate($this->spreadsheetId, $batchRequest);
            $sheetId  = $response->getReplies()[0]->getAddSheet()->getProperties()->getSheetId();

            // Ajoute les en-têtes sur la première ligne
            $this->writeHeaders($service);

            // Applique la mise en forme (gras des en-têtes + dropdown Statut)
            $this->applySheetFormatting($service, $sheetId);
        } else {
            // Vérifie si la feuille est vide (pas encore d'en-têtes)
            // On vérifie la plage A1:K1 qui correspond aux 11 colonnes définies
            $range    = self::SHEET_NAME . '!A1:K1';
            $response = $service->spreadsheets_values->get($this->spreadsheetId, $range);
            $values   = $response->getValues();

            if (empty($values)) {
                // Feuille vide : on ajoute les en-têtes et on formate
                $this->writeHeaders($service);
                $this->applySheetFormatting($service, $sheetId);
            }
        }
    }

    /**
     * Écrit la ligne d'en-têtes (première ligne du tableur).
     * La mise en forme (gras + dropdown) est appliquée séparément via applySheetFormatting().
     */
    private function writeHeaders(\Google\Service\Sheets $service): void
    {
        $body = new \Google\Service\Sheets\ValueRange([
            'values' => [self::HEADERS],
        ]);

        $service->spreadsheets_values->update(
            $this->spreadsheetId,
            self::SHEET_NAME . '!A1',
            $body,
            ['valueInputOption' => 'RAW']
        );
    }

    /**
     * Applique la mise en forme sur la feuille via l'API batchUpdate :
     *   1. Met les en-têtes (ligne 0) en gras
     *   2. Ajoute une validation de données dropdown sur la colonne "Statut" (index 10)
     *
     * Cette méthode utilise le sheetId numérique interne (pas le nom de la feuille),
     * car les requêtes batchUpdate ciblent les feuilles par leur identifiant numérique.
     *
     * @param \Google\Service\Sheets $service Client API Google Sheets
     * @param int                    $sheetId Identifiant numérique interne de la feuille
     */
    private function applySheetFormatting(\Google\Service\Sheets $service, int $sheetId): void
    {
        // ── Requête 1 : mettre les en-têtes en gras ──────────────────────────────
        // RepeatCellRequest applique un format à une plage rectangulaire de cellules.
        // On cible la ligne 0 (startRowIndex: 0, endRowIndex: 1), toutes les colonnes
        // (startColumnIndex: 0, endColumnIndex: 11 pour nos 11 colonnes).
        $boldRequest = new \Google\Service\Sheets\RepeatCellRequest([
            'range' => new \Google\Service\Sheets\GridRange([
                'sheetId'          => $sheetId,
                'startRowIndex'    => 0,  // Ligne 1 dans l'interface (index 0)
                'endRowIndex'      => 1,  // Exclusif : ne concerne que la ligne 0
                'startColumnIndex' => 0,
                'endColumnIndex'   => 11, // 11 colonnes (A à K)
            ]),
            'cell'   => new \Google\Service\Sheets\CellData([
                'userEnteredFormat' => new \Google\Service\Sheets\CellFormat([
                    'textFormat' => new \Google\Service\Sheets\TextFormat([
                        'bold' => true, // Activer le gras
                    ]),
                ]),
            ]),
            // "fields" précise quels champs mettre à jour (évite d'écraser les autres formats)
            'fields' => 'userEnteredFormat.textFormat.bold',
        ]);

        // ── Requête 2 : dropdown de validation sur la colonne "Statut" ────────────
        // La colonne Statut est à l'index 10 (11ème colonne, de A=0 à K=10).
        // On applique la validation aux lignes 1 à 1000 (en excluant la ligne d'en-tête).
        // ONE_OF_LIST impose que la valeur saisie soit dans la liste fournie.
        $dropdownValues = ['À vérifier', 'Pertinent', 'À contacter', 'Importé en BDD', 'Rejeté'];

        // Chaque valeur du dropdown est encapsulée dans une ConditionValue
        $conditionValues = array_map(
            fn (string $val) => new \Google\Service\Sheets\ConditionValue(['userEnteredValue' => $val]),
            $dropdownValues
        );

        $dropdownRequest = new \Google\Service\Sheets\SetDataValidationRequest([
            'range' => new \Google\Service\Sheets\GridRange([
                'sheetId'          => $sheetId,
                'startRowIndex'    => 1,    // Commence à la ligne 2 (index 1) pour ne pas toucher l'en-tête
                'endRowIndex'      => 1000, // Couvre les 999 premières lignes de données
                'startColumnIndex' => 10,   // Colonne K (index 10) = "Statut"
                'endColumnIndex'   => 11,   // Exclusif : ne concerne que la colonne 10
            ]),
            'rule' => new \Google\Service\Sheets\DataValidationRule([
                'condition' => new \Google\Service\Sheets\BooleanCondition([
                    'type'   => 'ONE_OF_LIST', // L'utilisateur doit choisir dans la liste
                    'values' => $conditionValues,
                ]),
                'showCustomUi' => true,  // Affiche un vrai menu déroulant dans Sheets
                'strict'       => false, // Avertit si valeur incorrecte, mais ne bloque pas
            ]),
        ]);

        // ── Envoi des deux requêtes en un seul appel batchUpdate ──────────────────
        // batchUpdate est plus efficace qu'envoyer deux requêtes séparées
        $batchRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
            'requests' => [
                new \Google\Service\Sheets\Request(['repeatCell'       => $boldRequest]),
                new \Google\Service\Sheets\Request(['setDataValidation' => $dropdownRequest]),
            ],
        ]);

        $service->spreadsheets->batchUpdate($this->spreadsheetId, $batchRequest);
    }

    /**
     * Applique la mise en forme (en-têtes gras + dropdown Statut) sur la feuille existante.
     * À appeler manuellement via la commande app:sheets-format quand le sheet existe déjà.
     *
     * @throws \RuntimeException Si les credentials sont absents ou si la feuille est introuvable
     */
    public function formatExistingSheet(): void
    {
        $client  = $this->createGoogleClient();
        $service = new \Google\Service\Sheets($client);

        // Récupère les métadonnées pour trouver le sheetId numérique de la feuille
        $spreadsheet = $service->spreadsheets->get($this->spreadsheetId);
        $sheetId     = null;

        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === self::SHEET_NAME) {
                $sheetId = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if ($sheetId === null) {
            throw new \RuntimeException(sprintf(
                'Feuille "%s" introuvable dans le spreadsheet. Lance d\'abord un scraping.',
                self::SHEET_NAME
            ));
        }

        $this->applySheetFormatting($service, $sheetId);
    }

    /**
     * Crée et configure le client Google avec l'authentification par compte de service.
     *
     * Un "compte de service" est un compte Google technique (pas un vrai utilisateur)
     * qui a accès au Sheet. C'est la méthode recommandée pour les serveurs.
     *
     * @throws \RuntimeException Si le fichier de credentials n'existe pas
     */
    private function createGoogleClient(): \Google\Client
    {
        if (!file_exists($this->credentialsPath)) {
            throw new \RuntimeException(sprintf(
                'Fichier de credentials Google introuvable : %s. ' .
                'Télécharge le JSON du compte de service depuis Google Cloud Console.',
                $this->credentialsPath
            ));
        }

        $client = new \Google\Client();
        $client->setApplicationName('BazaArt Scraper');

        // Authentification par fichier JSON du compte de service
        $client->setAuthConfig($this->credentialsPath);

        // Scope = permission d'écriture dans Google Sheets
        $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);

        return $client;
    }
}
