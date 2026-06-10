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
     * HEURISTIQUE (conservative, commentée) :
     *
     *   Étape 1 — Extraction de tous les tokens date du texte.
     *     On cherche les 3 formats supportés via preg_match_all :
     *       - ISO 8601 court  : "2026-05-31"
     *       - Français court  : "31/05/2026" ou "1/5/2026"
     *       - Français long   : "31 mai 2026" ou "15 décembre 2026"
     *
     *   Étape 2 — Priorité aux tokens précédés d'un cue de deadline.
     *     Pour chaque token date trouvé, on regarde si un mot-clé de DEADLINE_CUES
     *     apparaît AVANT lui dans le même "segment" de phrase (≤ 80 caractères
     *     en arrière, sans point/point-virgule intermédiaire).
     *     Si oui → token retenu en priorité et retourné immédiatement.
     *
     *   Étape 3 — Fallback : premier token date trouvé.
     *     ⚠️ HEURISTIQUE IMPARFAITE : sur un texte d'actualité (ex: flux RSS
     *     de blog culturel), la première date peut être la date de l'événement
     *     décrit, pas la deadline de candidature. Ce fallback est intentionnellement
     *     conservé car il vaut mieux une deadline potentiellement incorrecte
     *     (visible dans l'UI admin pour correction) que de passer à côté d'une
     *     vraie deadline. La règle métier reste : NULL si on ne trouve rien,
     *     mais on tente honnêtement avant de capituler.
     *     À réévaluer si trop de faux-positifs remontent de la modération.
     *
     * @param string $text Texte libre combinant titre et description de l'opportunité
     * @return \DateTimeImmutable|null Date limite détectée, ou null si rien de parseable
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

        /** @var array<int, array<int, array{0: string, 1: int}>> $matches */
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

        // ── Étape 2 : chercher un token précédé d'un cue de deadline ─────────
        // Pour chaque token, on extrait le contexte gauche (jusqu'à 80 caractères
        // en arrière dans la même "phrase", i.e. sans point/;/? intermédiaire).
        // Si un cue de DEADLINE_CUES est présent dans ce contexte → priorité.
        $textLower = mb_strtolower($text);
        foreach ($tokens as $tokenData) {
            $offset    = $tokenData['offset'];
            $token     = $tokenData['token'];

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
                    // Cue trouvé → ce token est probablement une vraie deadline
                    // On tente de le parser et on retourne immédiatement si valide
                    $parsed = $this->parseDateToken($token);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                    // Token non parseable malgré le cue → on continue (autre token)
                    break;
                }
            }
        }

        // ── Étape 3 : fallback — retenir la PREMIÈRE date parseable ──────────
        // ⚠️ Heuristique imparfaite (voir docblock) : sans cue, on prend la première
        // date du texte. Cela peut être une date d'événement, pas une deadline.
        // Acceptable pour V1 car l'admin valide de toute façon chaque opportunité.
        foreach ($tokens as $tokenData) {
            $parsed = $this->parseDateToken($tokenData['token']);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Aucun token n'a pu être parsé (ex: mois non reconnu, date invalide)
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
