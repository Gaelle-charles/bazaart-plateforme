<?php

declare(strict_types=1);

namespace App\Service\Scraper;

/**
 * MusiquesActuellesScraper — Scrape le flux RSS de Musiques Actuelles.
 *
 * Site : https://www.musiquesactuelles.fr
 * RSS  : https://www.musiquesactuelles.fr/feed/
 *
 * "Musiques Actuelles en France" est un portail qui agrège les actualités
 * de la scène musicale française (rock, électro, hip-hop, folk, jazz...).
 * Le flux contient des sorties de disques, événements ET des appels à
 * candidatures (ex: "Prix des Musiques d'ICI").
 *
 * On filtre avec des mots-clés stricts pour ne garder que les opportunités.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * NOTE IMPORTANTE — DOMAINE POTENTIELLEMENT INDISPONIBLE (mai 2026)
 * ────────────────────────────────────────────────────────────────────────────────
 * Le domaine musiquesactuelles.fr (et .com) répond HTTP 000 depuis le container
 * Docker (timeout réseau, pas d'erreur DNS). Le site est peut-être temporairement
 * down ou a des restrictions d'accès depuis certaines IPs.
 *
 * Si le site reste inaccessible sur la durée, envisager de le remplacer par :
 *   → irma.asso.fr/feed/ (IRMA = Institut de Ressources Musicales Actuelles)
 *     Référence nationale pour les musiques actuelles, flux RSS actif.
 *     À tester : https://www.irma.asso.fr/feed/
 *
 * On laisse ce scraper en place sans modification — le domaine est peut-être
 * temporairement down. AbstractRssScraper retourne [] silencieusement en cas
 * d'erreur HTTP (règle : un scraper ne lève jamais d'exception).
 */
class MusiquesActuellesScraper extends AbstractRssScraper
{
    protected function getFeedUrl(): string
    {
        return 'https://www.musiquesactuelles.fr/feed/';
    }

    public function getName(): string
    {
        return 'Musiques Actuelles en France';
    }

    protected function getSourceName(): string
    {
        return 'musiquesactuelles.fr';
    }

    protected function getDisciplines(): string
    {
        return 'Musique';
    }

    /**
     * Filtre strict pour ne garder que les opportunités (pas les sorties d'albums).
     *
     * @return string[]
     */
    protected function getKeywords(): array
    {
        return [
            'appel', 'candidature', 'bourse', 'résidence', 'prix',
            'aide', 'subvention', 'financement', 'soutien',
        ];
    }
}
