<?php

declare(strict_types=1);

namespace App\Service;

/**
 * DeadlineParserService — Convertit une deadline texte en \DateTimeImmutable.
 *
 * Ce service centralise la logique de parsing qui existait auparavant dans
 * ScrapedResourceRepository::archiveExpired(). En l'extrayant ici, on peut
 * l'appeler depuis l'EventListener (prePersist/preUpdate) ET depuis la commande
 * de rétro-remplissage, sans dupliquer le code.
 *
 * FORMATS SUPPORTÉS (dans l'ordre de tentative) :
 *   1. ISO 8601 court :  "2026-05-31"
 *   2. Français court :  "31/05/2026" ou "1/5/2026"  ← format le plus fréquent en base
 *   3. Français long  :  "31 mai 2026" ou "15 décembre 2026"
 *
 * MÉTHODES PUBLIQUES :
 *   - parse(string)           : attend une chaîne ENTIÈREMENT date (un seul token)
 *   - extractFromText(string) : SCANNE un texte libre pour y trouver une date limite
 *
 * CONTRAT COMMUN :
 *   - Ne lève JAMAIS d'exception — retourne null en cas d'échec
 *   - Les cas triviaux (vide, tiret, em-dash) retournent null immédiatement
 *   - Un format non reconnu retourne null (pas d'exception, pas de log)
 */
class DeadlineParserService
{
    /**
     * Noms des mois français → numéros (pour le format "31 mai 2026").
     * Constante de classe : construite une seule fois, partagée entre toutes les instances.
     *
     * @var array<string, string>
     */
    private const MONTHS_FR = [
        'janvier'   => '01',
        'février'   => '02',
        'mars'      => '03',
        'avril'     => '04',
        'mai'       => '05',
        'juin'      => '06',
        'juillet'   => '07',
        'août'      => '08',
        'septembre' => '09',
        'octobre'   => '10',
        'novembre'  => '11',
        'décembre'  => '12',
    ];

    /**
     * Mots-clés qui signalent qu'une date est une date LIMITE de candidature.
     *
     * Ces mots-clés sont cherchés AVANT chaque token date dans le texte.
     * Si un de ces mots apparaît dans la même "phrase" (séquence de mots sans
     * ponctuation forte) qu'un token date, ce token est retenu en priorité.
     *
     * Liste construite à partir des formulations françaises les plus courantes
     * dans les annonces d'appels à projets culturels :
     *   - "date limite de candidature : 31 mai 2026"
     *   - "dépôt des dossiers avant le 31/05/2026"
     *   - "candidatures jusqu'au 2026-05-31"
     *   - "clôture des inscriptions : 15 décembre 2026"
     *
     * @var string[]
     */
    private const DEADLINE_CUES = [
        'clôture',
        'cloture',       // variante sans accent (certains sites)
        'date limite',
        'deadline',
        "jusqu'au",
        "jusqu'à",
        'avant le',
        'avant le ',
        'candidatures avant',
        'candidatures jusqu',
        'dépôt',
        'depot',         // variante sans accent
        'limite de',
        'échéance',
        'echeance',      // variante sans accent
        'fermeture',
        'inscriptions avant',
        'soumission avant',
    ];

    /**
     * Tente de convertir une deadline texte en DateTimeImmutable.
     *
     * Cette méthode attend une chaîne qui EST ENTIÈREMENT une date (un seul token).
     * Elle ne scanne pas du texte libre — pour cela, utiliser extractFromText().
     *
     * Appelée par ScrapedResourceListener (prePersist/preUpdate) et les scrapers CSS.
     *
     * @param string $rawDeadline Valeur brute du champ deadline (peut être vide ou invalide)
     * @return \DateTimeImmutable|null Date parsée, ou null si non parseable
     */
    public function parse(string $rawDeadline): ?\DateTimeImmutable
    {
        // ── Cas triviaux : valeurs non informatives ───────────────────────────
        // On filtre d'abord les valeurs connues comme "pas de deadline" pour éviter
        // de tenter des regex inutiles. On utilise trim() car certains scrapers
        // peuvent insérer des espaces parasites.
        $deadline = trim($rawDeadline);

        if ($deadline === '' || $deadline === '-' || $deadline === '—') {
            return null; // Pas de deadline renseignée — comportement normal, pas une erreur
        }

        // Délégation à la méthode privée partagée avec extractFromText().
        // On passe le token brut : parse() attend un token date unique.
        return $this->parseDateToken($deadline);
    }

    /**
     * Scanne un texte libre (titre + description) pour y extraire une date limite.
     *
     * RÈGLE FONDAMENTALE — MOTS-CUES OBLIGATOIRES :
     *   Une date n'est retenue comme deadline QUE si elle est précédée d'un mot-cue
     *   de DEADLINE_CUES dans la même clause de phrase.
     *
     *   POURQUOI PAS DE FALLBACK ?
     *   L'ancienne version (avant ce commit) comportait une étape 3 « fallback » qui
     *   retenait la première date trouvée dans le texte si aucun cue n'était détecté.
     *   Ce comportement produisait des FAUX POSITIFS graves :
     *     - Exemple réel : "Aide à la création - Mai 2026" → deadline_date = 2026-05-13
     *       (date tirée du titre de l'annonce, sans rapport avec une vraie date limite).
     *     - Conséquence : la ressource devenait archivable automatiquement dès le lendemain,
     *       et disparaissait de la file de modération sans qu'aucun admin l'ait validée.
     *
     *   COMPORTEMENT CORRECT :
     *     - Date avec cue → deadline_date renseignée → archivage auto possible.
     *     - Date SANS cue (= date d'événement, de publication, de mention...) → null
     *       → deadline_date restera null → la ressource n'est pas archivable auto
     *       → elle attend tranquillement la modération humaine.
     *
     * ALGORITHME (deux étapes) :
     *
     *   Étape 1 — Extraction de tous les tokens date du texte.
     *     On cherche les 3 formats supportés via preg_match_all avec PREG_OFFSET_CAPTURE
     *     (position de chaque token dans le texte, indispensable pour l'étape 2) :
     *       - ISO 8601 court  : "2026-05-31"
     *       - Français court  : "31/05/2026" ou "1/5/2026"
     *       - Français long   : "31 mai 2026" ou "15 décembre 2026"
     *
     *   Étape 2 — Retenir UNIQUEMENT les tokens précédés d'un cue de deadline.
     *     Pour chaque token date trouvé, on regarde si un mot-clé de DEADLINE_CUES
     *     apparaît AVANT lui dans le même "segment" de phrase (≤ 80 caractères
     *     en arrière, sans point/point-virgule intermédiaire).
     *     Si oui → token retenu et retourné immédiatement.
     *     Si aucun token n'est associé à un cue → retour null (pas de fallback).
     *
     * @param string $text Texte libre combinant titre et description de l'opportunité
     * @return \DateTimeImmutable|null Date limite détectée (cue requis), ou null si aucune
     */
    public function extractFromText(string $text): ?\DateTimeImmutable
    {
        // ── Cas trivial : texte vide ──────────────────────────────────────────
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // ── Étape 1 : trouver tous les tokens date dans le texte ─────────────
        // On combine les 3 patterns en un seul preg_match_all avec offset capture
        // (PREG_OFFSET_CAPTURE) pour connaître la POSITION de chaque token dans
        // le texte original — indispensable pour l'analyse de contexte (étape 2).
        //
        // Patterns (dans l'ordre, du plus spécifique au plus large) :
        //   1. ISO  : 4 chiffres - 2 chiffres - 2 chiffres
        //   2. FR long : 1-2 chiffres + espace + nom de mois FR + espace + 4 chiffres
        //   3. FR court : 1-2 chiffres / 1-2 chiffres / 4 chiffres
        $monthNames = implode('|', array_keys(self::MONTHS_FR));
        $pattern    = '/(\d{4}-\d{2}-\d{2}|\d{1,2}\s+(?:' . $monthNames . ')\s+\d{4}|\d{1,2}\/\d{1,2}\/\d{4})/ui';

        // $matches est typé implicitement par preg_match_all avec PREG_OFFSET_CAPTURE :
        // chaque entrée $matches[n] est un tableau de [string, int] (token + offset).
        // On déclare $matches avant l'appel pour que PHPStan sache qu'il existe après.
        $matches = [];
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            // Aucune date trouvée dans le texte → null (pas de deadline détectable)
            return null;
        }

        // Liste des tokens avec leur position dans le texte original
        // Structure : [['token' => '31 mai 2026', 'offset' => 42], ...]
        /** @var array<int, array{token: string, offset: int}> $tokens */
        $tokens = [];
        foreach ($matches[0] as [$token, $offset]) {
            $tokens[] = ['token' => $token, 'offset' => $offset];
        }

        // ── Étape 2 : retenir UNIQUEMENT les tokens précédés d'un cue ────────
        // Pour chaque token, on extrait le contexte gauche (jusqu'à 80 caractères
        // en arrière dans la même "phrase", i.e. sans point/;/? intermédiaire).
        // Si un cue de DEADLINE_CUES est présent dans ce contexte → date retenue.
        // Si AUCUN token n'est précédé d'un cue → on retourne null.
        //
        // Rappel : PAS DE FALLBACK. Une date sans cue n'est pas une deadline.
        // Les articles de blog, annonces et actualités contiennent régulièrement
        // des dates de mention, d'événement ou de publication qui ne sont PAS des
        // dates limites. Sans cue, les retenir provoque des archivages prématurés.
        foreach ($tokens as $tokenData) {
            $offset = $tokenData['offset'];
            $token  = $tokenData['token'];

            // Fenêtre de contexte gauche : 80 caractères avant le token
            // On s'arrête à un signe de ponctuation forte (., ;, ?, !) pour rester
            // dans la même "clause" de phrase et éviter les faux-positifs inter-phrases.
            $contextStart = max(0, $offset - 80);
            $leftContext  = mb_strtolower(mb_substr($text, $contextStart, $offset - $contextStart));

            // Supprimer le contexte avant une ponctuation forte (on ne veut que
            // la dernière clause avant le token)
            if (preg_match('/[.;?!]\s*(.*)$/us', $leftContext, $clauseMatches)) {
                $leftContext = $clauseMatches[1];
            }

            // Chercher si un cue de deadline est présent dans ce contexte gauche
            foreach (self::DEADLINE_CUES as $cue) {
                if (str_contains($leftContext, $cue)) {
                    // Cue trouvé → ce token est probablement une vraie deadline.
                    // On tente de le parser et on retourne immédiatement si valide.
                    $parsed = $this->parseDateToken($token);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                    // Token non parseable malgré le cue → on continue (autre token)
                    break;
                }
            }
        }

        // ── Aucun token associé à un cue → null ──────────────────────────────
        // PAS DE FALLBACK — c'est intentionnel et documenté dans le docblock.
        // Si ce retour null vous surprend, relisez l'explication en haut de la méthode :
        // un faux deadline_date cause un archivage prématuré qui masque la ressource
        // à la modération — comportement strictement interdit par la cheffe de projet.
        return null;
    }

    /**
     * Convertit un token date (chaîne courte) en DateTimeImmutable.
     *
     * Méthode privée partagée entre parse() et extractFromText() pour éviter
     * la duplication de la logique de conversion.
     *
     * Supporte les 3 mêmes formats que parse() :
     *   - ISO 8601 court  : "2026-05-31"
     *   - Français court  : "31/05/2026" (avec ou sans zéros de padding)
     *   - Français long   : "31 mai 2026"
     *
     * @param string $token Un token date (chaîne courte, déjà trimée)
     * @return \DateTimeImmutable|null Date parsée à minuit, ou null si format inconnu
     */
    private function parseDateToken(string $token): ?\DateTimeImmutable
    {
        $token = trim($token);

        // ── Format 1 : ISO 8601 court (YYYY-MM-DD) ───────────────────────────
        // Exemple : "2026-05-31"
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $token);
            if ($parsed !== false) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        // ── Format 2 : français court (JJ/MM/AAAA) ───────────────────────────
        // Exemple : "31/05/2026" ou "1/5/2026" (jour/mois peuvent être sans zéro)
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $token)) {
            $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', $token);
            if ($parsed !== false) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        // ── Format 3 : français long ("JJ mois AAAA") ────────────────────────
        // Exemple : "31 mai 2026" ou "15 décembre 2026"
        // On capture les 3 groupes : jour, nom du mois, année.
        if (preg_match('/^(\d{1,2})\s+(\w+)\s+(\d{4})$/iu', $token, $matches)) {
            $monthStr = mb_strtolower($matches[2]);

            if (isset(self::MONTHS_FR[$monthStr])) {
                // Reconstruction en format JJ/MM/AAAA pour createFromFormat
                $normalized = sprintf('%02d/%s/%s', (int) $matches[1], self::MONTHS_FR[$monthStr], $matches[3]);
                $parsed     = \DateTimeImmutable::createFromFormat('d/m/Y', $normalized);
                if ($parsed !== false) {
                    return $parsed->setTime(0, 0, 0);
                }
            }
        }

        // ── Format inconnu ────────────────────────────────────────────────────
        return null;
    }
}
