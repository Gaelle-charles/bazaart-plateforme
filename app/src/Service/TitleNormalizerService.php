<?php

declare(strict_types=1);

namespace App\Service;

/**
 * TitleNormalizerService — Normalise un titre pour la déduplication par contenu.
 *
 * Ce service centralise l'algorithme de normalisation qui était auparavant
 * copié-collé dans trois classes différentes :
 *   - ScrapedResourcePersister::normalizeTitle()      (génération de la clé intra-lot)
 *   - ScrapedResourceRepository::normalizeTitle()     (comparaison PHP en findByContentKey)
 *   - ResourceRepository::normalizeTitle()            (comparaison PHP en findPublishedByContentKey)
 *
 * ── POURQUOI UN SERVICE DÉDIÉ ? ──────────────────────────────────────────────
 * Les trois copies devaient rester STRICTEMENT identiques pour que la déduplication
 * fonctionne (le persister génère la clé, le repository la compare).
 * Une divergence (même mineure) entre les copies produisait de faux doublons ou des
 * doublons non détectés → comportement impossible à déboguer visuellement.
 *
 * Un seul service = une seule source de vérité. Toute modification de l'algorithme
 * se propage automatiquement aux trois points d'utilisation.
 *
 * ── ALGORITHME ───────────────────────────────────────────────────────────────
 *   1. Translitération Unicode via ICU (NFD → diacritiques retirés → NFC → Lower)
 *      → "Résidence" → "residence", "Création" → "creation", "MÉDICIS" → "medicis"
 *   2. Suppression de la ponctuation (ne garde que a-z, 0-9, espace)
 *   3. Compression des espaces multiples en un seul
 *   4. Trim
 *
 * ── EXEMPLE ─────────────────────────────────────────────────────────────────
 *   "Bourse « Création » — Résidence 2026" → "bourse creation residence 2026"
 *   "Prix Révélations Afrique !"           → "prix revelations afrique"
 */
class TitleNormalizerService
{
    /**
     * Normalise un titre pour la déduplication par contenu.
     *
     * @param string $title Titre brut (peut contenir accents, ponctuation, majuscules)
     * @return string       Titre normalisé (minuscules, sans accents, sans ponctuation, espaces compressés)
     */
    public function normalize(string $title): string
    {
        // ── Étape 1 : Translitération Unicode + minuscules via ICU (extension intl) ──
        //
        // NFD = Canonical Decomposition : sépare les caractères composés en base + marque.
        //   Ex : é (U+00E9) → e (U+0065) + ́ (U+0301, combining acute accent)
        // [:Nonspacing Mark:] Remove : supprime toutes les marques diacritiques (accents).
        // NFC = Canonical Composition : recompose les caractères (sans diacritiques, donc sans changement).
        // Lower() : convertit en minuscules.
        //
        // Pourquoi ICU plutôt qu'iconv//TRANSLIT ?
        //   iconv//TRANSLIT produit "e'" pour é sur certains systèmes (Linux/macOS),
        //   ce qui insère une apostrophe entre les lettres → "cr e ation" après nettoyage.
        //   ICU/Transliterator ne produit que le caractère de base → résultat propre.
        //
        // Transliterator::create() retourne null si l'extension intl est absente
        // (très improbable — intl est chargé, vérifié au démarrage du container).
        $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()');

        if ($transliterator !== null) {
            $result = $transliterator->transliterate($title);
            // transliterate() peut retourner false sur erreur d'encodage (ex: séquence UTF-8 invalide)
            if ($result !== false) {
                $title = $result;
            }
        } else {
            // Fallback iconv si l'extension intl n'est pas disponible (environnement dégradé).
            // Moins fiable (produit parfois "e'" pour é) mais préférable à rien.
            $title = mb_strtolower($title, 'UTF-8');
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if ($transliterated !== false) {
                $title = $transliterated;
            }
        }

        // ── Étape 2 : ne garder que lettres ASCII a-z, chiffres 0-9 et espaces ──
        // Tout le reste (ponctuation, guillemets, tirets, symboles) → espace.
        $title = (string) preg_replace('/[^a-z0-9 ]/', ' ', $title);

        // ── Étape 3 : compresser les espaces multiples en un seul ──────────────
        $title = (string) preg_replace('/\s+/', ' ', $title);

        // ── Étape 4 : retirer les espaces extrêmes ─────────────────────────────
        return trim($title);
    }
}
