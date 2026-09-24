<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * ProjectDateFormatter — dates en français pour l'Espace projets (ADR-0037).
 *
 * POURQUOI pas le filtre Twig format_date ?
 *   Il nécessite le paquet twig/intl-extra, non installé. Plutôt que d'ajouter une
 *   dépendance Composer pour quelques libellés, on garde de petites tables de
 *   traduction : résultat identique, zéro dépendance, utilisable aussi côté PHP
 *   (messages du journal d'activité, emails).
 */
final class ProjectDateFormatter
{
    private const array MONTHS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    private const array MONTHS_SHORT = [
        1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
        'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
    ];

    /** Index = format('N') : 1 = lundi … 7 = dimanche. */
    private const array DAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    private const array DAYS_SHORT = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

    /** « 3 oct. » (+ l'année si ce n'est pas l'année en cours). */
    public static function short(\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $label = (int) $date->format('j') . ' ' . self::MONTHS_SHORT[(int) $date->format('n')];

        return $date->format('Y') !== $now->format('Y') ? $label . ' ' . $date->format('Y') : $label;
    }

    /** « jeudi 24 septembre 2026 ». */
    public static function long(\DateTimeInterface $date): string
    {
        return self::DAYS[(int) $date->format('N')] . ' ' . (int) $date->format('j') . ' '
            . self::MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    /** « 24 sept. 2026 à 14h05 » (signature des notes et commentaires). */
    public static function dateTime(\DateTimeInterface $date): string
    {
        return (int) $date->format('j') . ' ' . self::MONTHS_SHORT[(int) $date->format('n')] . ' '
            . $date->format('Y') . ' à ' . $date->format('G\hi');
    }

    /** « septembre 2026 » (titre du calendrier). */
    public static function monthYear(\DateTimeInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    /** « oct. 26 » (en-têtes compacts de la chronologie). */
    public static function monthLabel(\DateTimeInterface $date): string
    {
        return self::MONTHS_SHORT[(int) $date->format('n')] . ' ' . $date->format('y');
    }

    /** « lun. » */
    public static function dayShort(\DateTimeInterface $date): string
    {
        return self::DAYS_SHORT[(int) $date->format('N')];
    }

    /**
     * Échéance relative, pensée pour les cartes de tâches :
     * « Aujourd'hui », « Demain », « Hier », « Dans 3 j », « Il y a 5 j », sinon « 3 oct. ».
     */
    public static function due(\DateTimeInterface $due, \DateTimeImmutable $today): string
    {
        $dueDay = \DateTimeImmutable::createFromInterface($due)->setTime(0, 0);
        $diff   = (int) $today->setTime(0, 0)->diff($dueDay)->format('%r%a');

        return match (true) {
            $diff === 0                 => 'Aujourd\'hui',
            $diff === 1                 => 'Demain',
            $diff === -1                => 'Hier',
            $diff > 1 && $diff <= 6     => sprintf('Dans %d j', $diff),
            $diff < -1 && $diff >= -30  => sprintf('Il y a %d j', -$diff),
            default                     => self::short($due, $today),
        };
    }

    /** Temps écoulé : « à l'instant », « il y a 5 min », « il y a 3 h », « hier », sinon la date. */
    public static function ago(\DateTimeInterface $date, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $seconds = $now->getTimestamp() - $date->getTimestamp();

        return match (true) {
            $seconds < 60     => 'à l\'instant',
            $seconds < 3600   => sprintf('il y a %d min', intdiv($seconds, 60)),
            $seconds < 86400  => sprintf('il y a %d h', intdiv($seconds, 3600)),
            $seconds < 172800 => 'hier',
            $seconds < 604800 => sprintf('il y a %d jours', intdiv($seconds, 86400)),
            default           => self::short($date, $now),
        };
    }
}
