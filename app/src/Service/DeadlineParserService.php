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
 * CONTRAT :
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
     * Tente de convertir une deadline texte en DateTimeImmutable.
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

        // ── Tentative 1 : format ISO 8601 court (YYYY-MM-DD) ─────────────────
        // Exemple : "2026-05-31"
        // preg_match vérifie la structure avant createFromFormat pour éviter
        // que PHP accepte des dates partiellement invalides (ex: "2026-13-99").
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
            if ($parsed !== false) {
                // setTime(0,0,0) : on veut minuit, pas l'heure courante injectée par PHP
                return $parsed->setTime(0, 0, 0);
            }
        }

        // ── Tentative 2 : format français court (JJ/MM/AAAA) ─────────────────
        // Exemple : "31/05/2026" ou "1/5/2026" (le jour/mois peut être sans zéro)
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $deadline)) {
            $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', $deadline);
            if ($parsed !== false) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        // ── Tentative 3 : format français long ("JJ mois AAAA") ──────────────
        // Exemple : "31 mai 2026" ou "15 décembre 2026"
        // On capture les 3 groupes : jour, nom du mois, année.
        if (preg_match('/^(\d{1,2})\s+(\w+)\s+(\d{4})$/i', $deadline, $matches)) {
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

        // ── Aucun format reconnu ──────────────────────────────────────────────
        // On retourne null sans lever d'exception — la politique du service est
        // de ne jamais planter, même face à une valeur imprévue ("Rolling basis",
        // "À confirmer", etc.).
        return null;
    }
}
