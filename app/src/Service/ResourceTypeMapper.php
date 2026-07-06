<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ResourceType;
use App\Repository\ResourceTypeRepository;
use Psr\Log\LoggerInterface;

/**
 * ResourceTypeMapper — Convertit un libellé de type texte libre (LLM, scraper, CSV)
 * vers l'entité ResourceType canonique correspondante en BDD.
 *
 * ── PROBLÈME RÉSOLU (bug remonté par une utilisatrice) ───────────────────────
 * Avant ce service, AdminController::verifyScrapedOpportunity() faisait :
 *
 *     $resourceType = $this->resourceTypeRepository->findOneBy(['name' => $scraped->getType()])
 *                  ?? $this->resourceTypeRepository->findOneBy(['name' => 'Autre'])
 *                  ?? $this->resourceTypeRepository->findAll()[0]
 *                  ?? null;
 *
 * Le champ ScrapedResource::$type est rempli par LlmExtractorService::normalizeType()
 * qui produit des libellés comme "Bourse", "Résidence", "Prix", "Concours",
 * "Financement", "Mentorat"... Ces libellés ne correspondent JAMAIS exactement
 * (comparaison stricte findOneBy) aux noms des ResourceType réels ("Bourse & Financement",
 * "Résidence artistique", "Prix & Concours"...). Le premier findOneBy échouait donc
 * presque systématiquement. Pire : AUCUN ResourceType "Autre" n'existait en base
 * (seulement dans LoadInitialDataCommand, pas dans AppFixtures) → le 2e findOneBy
 * échouait aussi → on retombait sur findAll()[0], un type COMPLÈTEMENT ARBITRAIRE
 * (le premier de la table, sans aucun rapport avec l'opportunité réelle).
 *
 * ── SOLUTION ──────────────────────────────────────────────────────────────────
 * Ce service centralise :
 *   1. CANONICAL_TYPES : LE référentiel unique des types de ressources (nom + icône),
 *      partagé par AppFixtures ET LoadInitialDataCommand (avant ce commit, les deux
 *      définissaient des listes divergentes — cause racine du bug ci-dessus).
 *   2. Une table de synonymes (libellés LLM/scrapers connus → nom canonique).
 *   3. Un algorithme de repli en 3 étapes (comme DisciplineMapperService) :
 *        a. Correspondance exacte (après normalisation casse/accents/ponctuation)
 *        b. Table de synonymes
 *        c. Sous-chaîne bidirectionnelle (dernier recours avant "Autre")
 *   4. Un fallback GARANTI vers le type "Autre" (jamais de findAll()[0] arbitraire),
 *      puisque "Autre" fait maintenant partie du référentiel canonique unifié.
 *
 * ── DÉCISION D'ARCHITECTURE : Mentorat / Tutorat / Accompagnement ────────────
 * Aucun des 9 types canoniques ne correspond vraiment à ces 3 libellés (ce ne sont
 * ni des aides financières, ni des appels à candidature classiques). Choix conservateur
 * retenu ici : les orienter vers "Autre" plutôt que de forcer un mauvais classement
 * dans "Formation" ou "Bourse & Financement". ⚠️ Décision arbitraire — à valider
 * avec Gaëlle si le volume de ce type d'opportunités devient significatif
 * (voir "Open questions" du lot de corrections qui a introduit ce service).
 */
class ResourceTypeMapper
{
    /**
     * Référentiel UNIQUE des types de ressources de la Ressourcerie (nom => icône).
     *
     * Utilisé par :
     *   - App\Command\LoadInitialDataCommand (seed idempotent, utilisé en prod)
     *   - App\DataFixtures\AppFixtures (seed de démo, dev/test)
     *   - Ce service (fallback "Autre" garanti d'exister)
     *
     * "Autre" est INDISPENSABLE : c'est le filet de sécurité final de mapLabelToType().
     * "Documentation" est utilisé par AdminController::documentationScrapedOpportunity().
     *
     * @var array<string, string>
     */
    public const array CANONICAL_TYPES = [
        'Appel à projets'        => '📢',
        'Résidence artistique'   => '🏠',
        'Bourse & Financement'   => '💰',
        'Formation'              => '🎓',
        'Prix & Concours'        => '🏆',
        'Diffusion & exposition' => '🖼️',
        'Emploi & stage'         => '💼',
        'Documentation'          => '📄',
        // Filet de sécurité final : garanti d'exister, jamais un type arbitraire.
        'Autre'                  => '📌',
    ];

    /**
     * Table de synonymes : libellé normalisé (minuscules, sans accents, sans
     * ponctuation — via TitleNormalizerService) → nom CANONIQUE (non normalisé,
     * tel qu'il doit apparaître dans CANONICAL_TYPES ci-dessus).
     *
     * Couvre en priorité le vocabulaire produit par LlmExtractorService::normalizeType()
     * ("Résidence", "Bourse", "Appel à projets", "Appel à candidatures", "Prix",
     * "Financement", "Concours", "Mentorat", "Tutorat", "Accompagnement", "Formation")
     * ainsi que quelques variantes supplémentaires rencontrées dans les scrapers CSS/RSS.
     *
     * @var array<string, string>
     */
    private const array SYNONYMS = [
        // Résidence artistique
        'residence'              => 'Résidence artistique',

        // Bourse & Financement
        'bourse'                 => 'Bourse & Financement',
        'financement'            => 'Bourse & Financement',
        'aide'                   => 'Bourse & Financement',
        'subvention'             => 'Bourse & Financement',
        'fonds'                  => 'Bourse & Financement',
        'grant'                  => 'Bourse & Financement',

        // Appel à projets
        'appel a projets'        => 'Appel à projets',
        'appel a candidatures'   => 'Appel à projets',
        'appel'                  => 'Appel à projets',
        'candidature'            => 'Appel à projets',
        'call'                   => 'Appel à projets',

        // Prix & Concours
        'prix'                   => 'Prix & Concours',
        'concours'               => 'Prix & Concours',
        'award'                  => 'Prix & Concours',
        'competition'            => 'Prix & Concours',

        // Formation
        'formation'              => 'Formation',
        'atelier'                => 'Formation',
        'workshop'               => 'Formation',

        // Diffusion & exposition
        'diffusion'              => 'Diffusion & exposition',
        'exposition'             => 'Diffusion & exposition',

        // Emploi & stage
        'emploi'                 => 'Emploi & stage',
        'stage'                  => 'Emploi & stage',

        // Documentation
        'documentation'          => 'Documentation',

        // Autre (explicite)
        'autre'                  => 'Autre',

        // ── Mentorat / Tutorat / Accompagnement ──────────────────────────────
        // Voir le docblock de classe : aucune catégorie canonique ne correspond
        // vraiment. Choix conservateur : "Autre". À arbitrer si le volume grossit.
        'mentorat'               => 'Autre',
        'mentor'                 => 'Autre',
        'tutorat'                => 'Autre',
        'tuteur'                 => 'Autre',
        'accompagnement'         => 'Autre',
        'coaching'               => 'Autre',
    ];

    /**
     * Cache en mémoire des ResourceType BDD, indexés par nom normalisé.
     * Rempli au premier appel de mapLabelToType() (lazy loading), comme
     * DisciplineMapperService::$normalizedCache.
     *
     * @var array<string, ResourceType>|null
     */
    private ?array $normalizedCache = null;

    public function __construct(
        private readonly ResourceTypeRepository $resourceTypeRepository,
        // Réutilise l'algorithme de normalisation déjà centralisé pour la dédup
        // de titres (minuscules, sans accents, sans ponctuation) — on ne veut pas
        // dupliquer une 2e fois cette logique (cf. DisciplineMapperService qui,
        // lui, avait sa propre copie avant que TitleNormalizerService n'existe).
        private readonly TitleNormalizerService $normalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Convertit un libellé de type texte libre en entité ResourceType existante.
     *
     * Ne retourne JAMAIS null : si aucune correspondance n'est trouvée, retombe
     * sur le type "Autre" (garanti présent en BDD via le référentiel unifié).
     *
     * @param string|null $rawLabel Libellé brut (ex: ScrapedResource::getType())
     */
    public function mapLabelToType(?string $rawLabel): ResourceType
    {
        $this->buildCache();

        $normalized = $rawLabel !== null ? trim($this->normalizer->normalize($rawLabel)) : '';

        if ($normalized !== '') {
            // ── Étape 1 : correspondance exacte contre les noms BDD normalisés ──
            // Couvre le cas où $rawLabel EST DÉJÀ un nom canonique (ex: GrantCsvImporter
            // qui produit directement "Bourse & Financement").
            if (isset($this->normalizedCache[$normalized])) {
                return $this->normalizedCache[$normalized];
            }

            // ── Étape 2 : table de synonymes ─────────────────────────────────────
            // Couvre le vocabulaire LLM ("Bourse", "Résidence", "Financement"...).
            if (isset(self::SYNONYMS[$normalized])) {
                $canonicalNormalized = $this->normalizer->normalize(self::SYNONYMS[$normalized]);
                if (isset($this->normalizedCache[$canonicalNormalized])) {
                    return $this->normalizedCache[$canonicalNormalized];
                }
            }

            // ── Étape 3 : sous-chaîne bidirectionnelle (dernier recours) ─────────
            // Insensible à la casse/accents (déjà géré par la normalisation ci-dessus).
            // Guard >= 4 caractères : évite les faux positifs sur des libellés trop
            // courts (même logique que DisciplineMapperService::mapLabelsToEntities()).
            if (mb_strlen($normalized) >= 4) {
                foreach ($this->normalizedCache as $dbKey => $dbType) {
                    if (str_contains($normalized, $dbKey) || str_contains($dbKey, $normalized)) {
                        return $dbType;
                    }
                }
            }
        }

        // ── Aucune correspondance trouvée → fallback "Autre" ─────────────────────
        $this->logger->info('[ResourceTypeMapper] Libellé non mappé : "{label}" → fallback "Autre"', [
            'label' => $rawLabel,
        ]);

        return $this->getFallbackType();
    }

    /**
     * Retourne le type "Autre", garanti présent en BDD après unification du
     * référentiel (CANONICAL_TYPES ci-dessus).
     *
     * Filet de sécurité ultime si "Autre" est malgré tout absent (BDD non seedée
     * via app:load-initial-data) : on log en critical et on retombe sur le premier
     * type disponible plutôt que de planter le flux admin. Ce cas ne devrait
     * jamais survenir en usage normal.
     */
    private function getFallbackType(): ResourceType
    {
        $autreNormalized = $this->normalizer->normalize('Autre');
        if (isset($this->normalizedCache[$autreNormalized])) {
            return $this->normalizedCache[$autreNormalized];
        }

        $this->logger->critical(
            '[ResourceTypeMapper] Aucun ResourceType "Autre" en BDD — as-tu lancé '
            . 'app:load-initial-data ? Fallback arbitraire sur le premier type disponible.'
        );

        $all = $this->resourceTypeRepository->findAllOrdered();
        if (empty($all)) {
            throw new \RuntimeException(
                'Aucun ResourceType en base. Lancez `php bin/console app:load-initial-data` avant de vérifier des opportunités.'
            );
        }

        return $all[0];
    }

    /**
     * Construit le cache BDD normalisé (une seule requête, réutilisée pour toute
     * la durée de vie de la requête HTTP — le service est shared par défaut).
     */
    private function buildCache(): void
    {
        if ($this->normalizedCache !== null) {
            return;
        }

        $this->normalizedCache = [];
        foreach ($this->resourceTypeRepository->findAllOrdered() as $type) {
            $key = $this->normalizer->normalize($type->getName());
            $this->normalizedCache[$key] = $type;
        }
    }
}
