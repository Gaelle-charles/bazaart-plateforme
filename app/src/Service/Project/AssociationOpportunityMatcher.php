<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\DTO\Project\AssociationMatch;
use App\Entity\AssociationProfile;
use App\Entity\Discipline;
use App\Entity\Resource;

/**
 * AssociationOpportunityMatcher — une opportunité correspond-elle à nos associations ? (ADR-0038)
 *
 * La plateforme recense des opportunités (aides, bourses, appels à projets,
 * résidences…) surtout pensées pour des ARTISTES. L'onglet « Opportunités » de
 * l'Espace projets ne doit montrer que celles qui collent réellement à BazaArt
 * Guadeloupe ou BazaArt Paris. Les critères viennent de la FICHE de chaque
 * association (AssociationProfile, remplie par l'équipe dans « Nos associations »).
 *
 * ─── MÉTHODE ─────────────────────────────────────────────────────────────────
 *
 * On lit le texte de l'opportunité (titre, description, modalités, type d'aide,
 * lieu), normalisé (minuscules, sans accents), et on cherche des MOTS ENTIERS.
 * Les mots-clés de la fiche sont normalisés de la même façon : on peut les
 * saisir avec ou sans accents.
 *
 * 1. EXCLUSIONS (l'opportunité n'est pas affichée pour cette association) :
 *    - réservée aux personnes physiques (« réservé aux artistes individuels »…) ;
 *    - contient un des « mots à exclure » de la fiche ;
 *    - son TYPE (formation, résidence…) ne fait pas partie des types recherchés ;
 *    - ses disciplines et celles de l'association n'ont rien en commun
 *      (si les deux en ont ; une fiche sans discipline = pluridisciplinaire) ;
 *    - rattachée à une VILLE hors de notre territoire, sans le citer ;
 *    - cite le territoire de l'AUTRE association mais jamais le nôtre, sans
 *      dire que l'appel est national.
 *
 * 2. SCORE (0 à 100) :
 *    - ouverte aux structures / associations / collectifs ....... 30 pts
 *    - territoire de la fiche mentionné ......................... 30 pts
 *      (sinon, appel national en France ......................... 10 pts)
 *    - thèmes / publics de la fiche : 10 pts par mot trouvé ..... 20 pts max
 *    - discipline en commun ..................................... 10 pts
 *      (opportunité ouverte à toutes les disciplines ............  5 pts)
 *    - type recherché par l'association ......................... 10 pts
 *
 * 3. AFFICHAGE : non exclue ET (ouverte aux structures OU sur notre territoire)
 *    ET score ≥ 40.
 *
 * Les RAISONS sont renvoyées pour être affichées : l'équipe voit POURQUOI une
 * opportunité lui est proposée, et peut ajuster la fiche si le tri se trompe.
 */
class AssociationOpportunityMatcher
{
    public const int SCORE_STRUCTURE      = 30;
    public const int SCORE_TERRITORY      = 30;
    public const int SCORE_NATIONAL       = 10;
    public const int SCORE_THEME_EACH     = 10;
    public const int SCORE_THEME_MAX      = 20;
    public const int SCORE_DISCIPLINE     = 10;
    public const int SCORE_ALL_DISCIPLINES = 5;
    public const int SCORE_TYPE           = 10;

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

    /**
     * Mots du NOM DU TYPE de ressource → catégorie de AssociationProfile::OPPORTUNITY_TYPES.
     * Ordre important : « Appel à résidence » est d'abord une résidence.
     */
    private const array TYPE_CATEGORIES = [
        'residences' => ['residence', 'residences'],
        'formations' => ['formation', 'formations', 'atelier', 'ateliers', 'workshop', 'stage', 'stages', 'cours', 'master', 'accompagnement', 'incubateur', 'incubation'],
        'aides'      => ['aide', 'aides', 'bourse', 'bourses', 'subvention', 'subventions', 'financement', 'financements', 'fonds', 'grant', 'mecenat'],
        'appels'     => ['appel', 'appels', 'projet', 'projets', 'prix', 'concours', 'commande', 'commandes'],
    ];

    /** Mots qui signalent un appel ouvert à toute la France. */
    private const array NATIONAL_KEYWORDS = [
        'national', 'nationale', 'nationaux', 'toute la france', 'tout le territoire', 'territoire national',
        'france entiere', 'partout en france', 'hexagone et outre-mer',
    ];

    /** Pays considérés comme « France » (appel national). */
    private const array FRANCE_NAMES = ['france', 'fr', 'france metropolitaine'];

    /**
     * Évalue une opportunité pour chaque association.
     *
     * @param array<string, AssociationProfile> $profiles fiches indexées par association
     *
     * @return array<string, AssociationMatch>
     */
    public function evaluate(Resource $resource, array $profiles): array
    {
        $results = [];
        foreach ($profiles as $key => $profile) {
            $results[$key] = $this->evaluateFor($resource, $profile, $profiles);
        }

        return $results;
    }

    /**
     * @param array<string, AssociationProfile> $allProfiles toutes les fiches (pour la règle « territoire de l'autre association »)
     */
    public function evaluateFor(Resource $resource, AssociationProfile $profile, array $allProfiles = []): AssociationMatch
    {
        $association = $profile->getAssociation();
        $text = self::normalize(implode(' ', array_filter([
            $resource->getTitle(),
            $resource->getDescription(),
            $resource->getHowToApply(),
            $resource->getFundingType(),
            $resource->getLocation(),
            $resource->getCity(),
        ], static fn (?string $part): bool => $part !== null && $part !== '')));

        $exclude = static fn (string $why): AssociationMatch => new AssociationMatch($association, 0, false, [], $why);

        // ── 1. Exclusions ────────────────────────────────────────────────────
        foreach (self::INDIVIDUAL_ONLY_PHRASES as $phrase) {
            if (str_contains($text, $phrase)) {
                return $exclude('Réservée aux personnes physiques');
            }
        }

        $excludedWord = self::firstFound($text, self::keywords($profile->getExcludedKeywords()));
        if ($excludedWord !== null) {
            return $exclude(sprintf('Contient un mot exclu (« %s »)', $excludedWord));
        }

        $typeName     = $resource->getResourceType()->getName();
        $typeCategory = self::typeCategory($typeName);
        if ($typeCategory !== null && !in_array($typeCategory, $profile->getSoughtTypes(), true)) {
            return $exclude(sprintf('Type non recherché (%s)', $typeName));
        }

        $profileDisciplines  = array_map(static fn (Discipline $d): ?int => $d->getId(), $profile->getDisciplines()->toArray());
        $resourceDisciplines = [];
        foreach ($resource->getDisciplines() as $discipline) {
            $resourceDisciplines[(int) $discipline->getId()] = $discipline->getName();
        }
        $commonDisciplines = array_intersect_key($resourceDisciplines, array_flip(array_filter($profileDisciplines, 'is_int')));
        if ($profileDisciplines !== [] && $resourceDisciplines !== [] && $commonDisciplines === []) {
            return $exclude('Aucune discipline en commun');
        }

        $territory          = self::keywords($profile->getTerritoryKeywords());
        $territoryMentioned = self::firstFound($text, $territory) !== null;
        $city               = self::normalize((string) $resource->getCity());
        if ($city !== '' && !$territoryMentioned && self::firstFound($city, $territory) === null) {
            return $exclude(sprintf('Réservée à un autre territoire (%s)', $resource->getCity()));
        }

        if (!$territoryMentioned && self::firstFound($text, self::NATIONAL_KEYWORDS) === null) {
            foreach ($allProfiles as $other) {
                if ($other->getAssociation() !== $association && self::firstFound($text, self::keywords($other->getTerritoryKeywords())) !== null) {
                    return $exclude(sprintf('Réservée à un autre territoire (%s)', $other->getAssociation()->territoryLabel()));
                }
            }
        }

        // ── 2. Score ─────────────────────────────────────────────────────────
        $score   = 0;
        $reasons = [];

        $forStructures = self::firstFound($text, self::STRUCTURE_KEYWORDS) !== null;
        if ($forStructures) {
            $score    += self::SCORE_STRUCTURE;
            $reasons[] = 'Ouverte aux associations / structures';
        }

        if ($territoryMentioned) {
            $score    += self::SCORE_TERRITORY;
            $reasons[] = 'Notre territoire';
        } elseif ($city === '' && in_array(self::normalize((string) $resource->getCountry()), self::FRANCE_NAMES, true)) {
            $score    += self::SCORE_NATIONAL;
            $reasons[] = 'Appel national (France)';
        }

        $themes = self::allFound($text, self::keywords($profile->getThemeKeywords()));
        if ($themes !== []) {
            $score    += min(self::SCORE_THEME_MAX, count($themes) * self::SCORE_THEME_EACH);
            $reasons[] = 'Thèmes : ' . implode(', ', array_slice($themes, 0, 4));
        }

        if ($commonDisciplines !== []) {
            $score    += self::SCORE_DISCIPLINE;
            $reasons[] = 'Discipline : ' . implode(', ', $commonDisciplines);
        } elseif ($resourceDisciplines === []) {
            $score += self::SCORE_ALL_DISCIPLINES;
        }

        if ($typeCategory !== null) {
            $score    += self::SCORE_TYPE;
            $reasons[] = $typeName;
        }

        $score   = min(100, $score);
        $matches = ($forStructures || $territoryMentioned) && $score >= self::THRESHOLD;

        return new AssociationMatch($association, $score, $matches, $reasons);
    }

    /** Catégorie (aides, appels, residences, formations) d'un nom de type, ou null si inconnu. */
    public static function typeCategory(string $typeName): ?string
    {
        $name = self::normalize($typeName);
        foreach (self::TYPE_CATEGORIES as $category => $words) {
            if (self::firstFound($name, $words) !== null) {
                return $category;
            }
        }

        return null;
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
     * Mots-clés d'un champ de la fiche, normalisés comme le texte des opportunités.
     *
     * @return list<string>
     */
    private static function keywords(?string $field): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $k): string => self::normalize($k),
            AssociationProfile::splitKeywords($field),
        ), static fn (string $k): bool => mb_strlen($k) >= 2)));
    }

    /**
     * Premier mot / expression trouvé en MOT ENTIER, ou null.
     *
     * (?<![\p{L}\p{N}]) et (?![\p{L}\p{N}]) : pas de lettre ni de chiffre juste
     * avant / après. Ainsi « paris » est trouvé dans « à Paris, » mais pas dans
     * « comparaison », et « aide » n'est pas trouvé dans « aider ».
     *
     * @param list<string> $keywords
     */
    private static function firstFound(string $text, array $keywords): ?string
    {
        if ($text === '') {
            return null;
        }
        foreach ($keywords as $keyword) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * @param list<string> $keywords
     *
     * @return list<string>
     */
    private static function allFound(string $text, array $keywords): array
    {
        $found = [];
        foreach ($keywords as $keyword) {
            if (self::firstFound($text, [$keyword]) !== null) {
                $found[] = $keyword;
            }
        }

        return $found;
    }
}
