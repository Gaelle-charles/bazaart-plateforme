<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\AssociationMatch;
use App\Entity\Resource;
use App\Enum\BazaartAssociation;

/**
 * AssociationOpportunityMatcher — une opportunité correspond-elle à nos associations ? (ADR-0038)
 *
 * La plateforme recense des opportunités (aides, bourses, appels à projets,
 * résidences…) surtout pensées pour des ARTISTES. L'onglet « Opportunités » de
 * l'Espace projets ne doit montrer que celles auxquelles BazaArt Guadeloupe ou
 * BazaArt Paris peuvent candidater EN TANT QU'ASSOCIATION.
 *
 * ─── MÉTHODE ─────────────────────────────────────────────────────────────────
 *
 * On lit le texte de l'opportunité (titre, description, modalités, type d'aide,
 * lieu), normalisé (minuscules, sans accents), et on cherche des MOTS ENTIERS.
 *
 * 1. EXCLUSIONS (l'opportunité n'est pas affichée pour cette association) :
 *    - le texte dit explicitement qu'elle est réservée aux personnes physiques
 *      (« réservé aux artistes individuels », « les associations ne sont pas éligibles »…) ;
 *    - elle est rattachée à une VILLE qui n'est pas sur le territoire de
 *      l'association, et le texte ne mentionne jamais ce territoire
 *      (ex. une aide de la ville de Lyon n'est pas pour BazaArt Paris) ;
 *    - le texte cite le territoire de l'AUTRE association mais jamais le nôtre,
 *      sans dire que l'appel est national (ex. « acteurs d'Île-de-France »
 *      n'est pas pour BazaArt Guadeloupe).
 *
 * 2. SCORE (0 à 100) :
 *    - ouverte aux structures / associations / collectifs ...... 40 pts
 *    - territoire de l'association mentionné .................... 35 pts
 *      (sinon, appel national en France ......................... 10 pts)
 *    - type « aide, bourse, appel, résidence, prix… » ........... 10 pts
 *    - lien avec les cultures afro-diasporiques ................. 15 pts
 *
 * 3. AFFICHAGE : non exclue ET (ouverte aux structures OU sur notre territoire)
 *    ET score ≥ 40. Ce seuil écarte par exemple une simple formation parisienne
 *    pour artistes (territoire seul = 35 pts, sans signal « structure »).
 *
 * Les RAISONS sont renvoyées pour être affichées : l'équipe voit POURQUOI une
 * opportunité lui est proposée et peut juger elle-même (puis « Écarter »).
 *
 * Service sans base de données ni HTTP : facile à tester (AssociationOpportunityMatcherTest).
 */
class AssociationOpportunityMatcher
{
    public const int SCORE_STRUCTURE = 40;
    public const int SCORE_TERRITORY = 35;
    public const int SCORE_NATIONAL  = 10;
    public const int SCORE_TYPE      = 10;
    public const int SCORE_DIASPORA  = 15;

    /** Score minimal pour qu'une opportunité soit affichée. */
    public const int THRESHOLD = 40;

    /** Mots indiquant qu'une structure (association, collectif…) peut candidater. */
    private const array STRUCTURE_KEYWORDS = [
        'association', 'associations', 'associatif', 'associative', 'associatives', 'loi 1901',
        'structure', 'structures', 'personne morale', 'personnes morales',
        'collectif', 'collectifs', 'compagnie', 'compagnies', 'equipe artistique', 'equipes artistiques',
        'organisme', 'organismes', 'operateur culturel', 'operateurs culturels', 'acteurs culturels',
        'lieu culturel', 'lieux culturels', 'tiers-lieu', 'tiers-lieux', 'tiers lieux',
        'porteurs de projets collectifs', 'economie sociale et solidaire',
    ];

    /** Formules qui réservent explicitement l'opportunité aux personnes physiques. */
    private const array INDIVIDUAL_ONLY_PHRASES = [
        'artistes individuels uniquement', 'reserve aux artistes individuels', 'reservee aux artistes individuels',
        'personnes physiques uniquement', 'reserve aux personnes physiques', 'reservee aux personnes physiques',
        'a titre individuel uniquement', 'uniquement a titre individuel',
        'associations ne sont pas eligibles', 'structures ne sont pas eligibles',
        'les personnes morales ne sont pas eligibles', 'hors associations',
    ];

    /** Mots du type de ressource qui désignent un financement ou un appel à candidater. */
    private const array FUNDING_TYPE_KEYWORDS = [
        'aide', 'aides', 'bourse', 'bourses', 'subvention', 'subventions', 'financement', 'financements',
        'fonds', 'appel', 'appels', 'residence', 'residences', 'prix', 'concours', 'grant', 'projet', 'projets',
    ];

    /** Mots liés aux cultures afro-diasporiques (cœur du projet Bazaart). */
    private const array DIASPORA_KEYWORDS = [
        'afro', 'afrodescendant', 'afrodescendants', 'afrodescendante', 'afrodescendantes', 'afrodiaspora',
        'diaspora', 'diasporas', 'diasporique', 'diasporiques', 'afrique', 'africain', 'africaine', 'africains', 'africaines',
        'panafricain', 'panafricaine', 'creole', 'creoles', 'kreyol', 'caribeen', 'caribeenne', 'caraibe', 'caraibes', 'antilles', 'outre-mer',
    ];

    /** Mots qui signalent un appel ouvert à toute la France. */
    private const array NATIONAL_KEYWORDS = [
        'national', 'nationale', 'nationaux', 'toute la france', 'tout le territoire', 'territoire national',
        'france entiere', 'partout en france', 'hexagone et outre-mer',
    ];

    /** Pays considérés comme « France » (appel national). */
    private const array FRANCE_NAMES = ['france', 'fr', 'france metropolitaine'];

    /**
     * Évalue une opportunité pour les deux associations.
     *
     * @return array<string, AssociationMatch> indexé par la valeur de l'association ('guadeloupe', 'paris')
     */
    public function evaluate(Resource $resource): array
    {
        $results = [];
        foreach (BazaartAssociation::cases() as $association) {
            $results[$association->value] = $this->evaluateFor($resource, $association);
        }

        return $results;
    }

    public function evaluateFor(Resource $resource, BazaartAssociation $association): AssociationMatch
    {
        $text = self::normalize(implode(' ', array_filter([
            $resource->getTitle(),
            $resource->getDescription(),
            $resource->getHowToApply(),
            $resource->getFundingType(),
            $resource->getLocation(),
            $resource->getCity(),
        ], static fn (?string $part): bool => $part !== null && $part !== '')));

        // ── 1. Exclusions ────────────────────────────────────────────────────
        foreach (self::INDIVIDUAL_ONLY_PHRASES as $phrase) {
            if (str_contains($text, $phrase)) {
                return new AssociationMatch($association, 0, false, [], 'Réservée aux personnes physiques');
            }
        }

        $territoryMentioned = self::containsAny($text, $association->territoryKeywords());
        $city = self::normalize((string) $resource->getCity());
        if ($city !== '' && !$territoryMentioned && !self::containsAny($city, $association->territoryKeywords())) {
            return new AssociationMatch($association, 0, false, [], sprintf('Réservée à un autre territoire (%s)', $resource->getCity()));
        }

        if (!$territoryMentioned && !self::containsAny($text, self::NATIONAL_KEYWORDS)) {
            foreach (BazaartAssociation::cases() as $other) {
                if ($other !== $association && self::containsAny($text, $other->territoryKeywords())) {
                    return new AssociationMatch($association, 0, false, [], sprintf('Réservée à un autre territoire (%s)', $other->territoryLabel()));
                }
            }
        }

        // ── 2. Score ─────────────────────────────────────────────────────────
        $score   = 0;
        $reasons = [];

        $forStructures = self::containsAny($text, self::STRUCTURE_KEYWORDS);
        if ($forStructures) {
            $score    += self::SCORE_STRUCTURE;
            $reasons[] = 'Ouverte aux associations / structures';
        }

        if ($territoryMentioned) {
            $score    += self::SCORE_TERRITORY;
            $reasons[] = 'Territoire : ' . $association->territoryLabel();
        } elseif ($city === '' && in_array(self::normalize((string) $resource->getCountry()), self::FRANCE_NAMES, true)) {
            $score    += self::SCORE_NATIONAL;
            $reasons[] = 'Appel national (France)';
        }

        if (self::containsAny(self::normalize($resource->getResourceType()->getName()), self::FUNDING_TYPE_KEYWORDS)) {
            $score    += self::SCORE_TYPE;
            $reasons[] = $resource->getResourceType()->getName();
        }

        if (self::containsAny($text, self::DIASPORA_KEYWORDS)) {
            $score    += self::SCORE_DIASPORA;
            $reasons[] = 'Lien avec les cultures afro-diasporiques';
        }

        $score   = min(100, $score);
        $matches = ($forStructures || $territoryMentioned) && $score >= self::THRESHOLD;

        return new AssociationMatch($association, $score, $matches, $reasons);
    }

    /**
     * Minuscules, sans accents, apostrophes typographiques unifiées, espaces simplifiés.
     * Même principe que DisciplineMapperService::normalizeText().
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace(['’', 'ʼ'], "'", $text), 'UTF-8');
        $decomposed = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_D) : false;
        if (is_string($decomposed)) {
            $text = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Le texte contient-il au moins un de ces mots / expressions, en MOT ENTIER ?
     *
     * (?<![\p{L}\p{N}]) et (?![\p{L}\p{N}]) : pas de lettre ni de chiffre juste
     * avant / après. Ainsi « paris » est trouvé dans « à Paris, » mais pas dans
     * « comparaison », et « aide » n'est pas trouvé dans « aider ».
     *
     * @param list<string> $keywords
     */
    private static function containsAny(string $text, array $keywords): bool
    {
        if ($text === '') {
            return false;
        }
        foreach ($keywords as $keyword) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
