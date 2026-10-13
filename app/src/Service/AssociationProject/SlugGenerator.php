<?php

declare(strict_types=1);

namespace App\Service\AssociationProject;

/**
 * SlugGenerator : transforme un titre en identifiant d'URL (ADR-0039).
 *
 * Exemples :
 *   « An Ba Lanmè, chapitre 2 »              → an-ba-lanme-chapitre-2
 *   « Coopération Guadeloupe-Côte d'Ivoire » → cooperation-guadeloupe-cote-d-ivoire
 *   « Zagaza · Maison Verveau »              → zagaza-maison-verveau
 *
 * POURQUOI PAS iconv('ASCII//TRANSLIT') comme ArticleService ?
 *   Dans l'image Docker de production (Alpine), iconv écrit les accents à côté
 *   de la lettre au lieu de les retirer : « Lanmè » devient « Lanm`e », donc le
 *   slug « lanm-e ». Vérifié dans l'image php:8.3-fpm-alpine.
 *
 * MÉTHODE (sans dépendance, identique sur Alpine et Ubuntu) :
 *   1. table de correspondance explicite pour les lettres du français et des
 *      créoles (é → e, ç → c, œ → oe…) : résultat garanti ;
 *   2. pour toute autre lettre accentuée, décomposition Unicode (Normalizer)
 *      puis suppression des accents isolés ;
 *   3. minuscules, tout le reste devient un tiret.
 */
final class SlugGenerator
{
    /** Longueur maximale d'un slug (la colonne fait 160, on garde de la marge pour « -2 »). */
    public const MAX_LENGTH = 150;

    /** @var array<string, string> lettres accentuées ou liées → équivalent ASCII */
    private const MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Á' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'ç' => 'c', 'Ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'Î' => 'i', 'Ï' => 'i', 'Í' => 'i', 'Ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o',
        'Ô' => 'o', 'Ö' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ø' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ú' => 'u',
        'ÿ' => 'y', 'ý' => 'y', 'Ÿ' => 'y', 'Ý' => 'y',
        'ñ' => 'n', 'Ñ' => 'n',
        'œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss',
    ];

    public static function slugify(string $text): string
    {
        // 1. Lettres connues : correspondance directe.
        $text = strtr($text, self::MAP);

        // 2. Autres lettres accentuées : « é » se décompose en « e » + accent isolé
        //    (forme NFD), puis on supprime les accents isolés (\p{Mn}).
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        // 3. Minuscules, puis tout ce qui n'est pas a-z ou 0-9 devient un tiret
        //    (espaces, apostrophes, points, « · », « : »…). Les tirets en double
        //    sont fusionnés par le « + » de l'expression régulière.
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        // 4. Longueur maximale, sans finir sur un tiret.
        if (strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(substr($text, 0, self::MAX_LENGTH), '-');
        }

        // Titre fait uniquement de symboles : on garde une valeur utilisable.
        return $text !== '' ? $text : 'projet';
    }
}
