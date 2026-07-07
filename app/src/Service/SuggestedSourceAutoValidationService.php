<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SuggestedSource;
use App\Enum\ScrapingSourceType;
use App\Enum\SuggestedSourceStatus;
use App\Repository\ScrapingSourceRepository;
use Psr\Log\LoggerInterface;

/**
 * SuggestedSourceAutoValidationService — Auto-validation des suggestions RSS confirmées (ADR-0034).
 *
 * ── CONTEXTE ─────────────────────────────────────────────────────────────────
 * Avant ADR-0034, TOUTE SuggestedSource créée par app:discover-sources restait
 * bloquée en statut AValider jusqu'à ce qu'un admin la valide manuellement
 * (AdminSuggestedSourceController::validate()). Gaëlle a demandé que ce dernier
 * maillon manuel disparaisse pour les sources RSS dont le flux est confirmé
 * fonctionnel — le déclenchement (cron) était déjà automatique, seule la
 * promotion suggestion → source active restait humaine.
 *
 * ── POURQUOI ON NE SE FIE PAS À SuggestedSource::$typePressenti ────────────────
 * Ce champ existe dans l'entité mais n'est actuellement JAMAIS renseigné par
 * LlmExtractorService::discoverSources() (le prompt de découverte ne demande
 * pas de "type" — voir le JSON attendu : nom/url/pays_zone/discipline/raison).
 * On ne peut donc pas filtrer "les suggestions de type RSS" à partir d'un champ
 * qui vaut toujours null aujourd'hui. Le VRAI garde-fou qualité demandé par
 * l'ADR est FeedDetectorService::detect() : c'est lui qui, pour une URL donnée,
 * détermine dynamiquement s'il s'agit d'un flux RSS/Atom réellement lisible
 * (pas juste un HTTP 200). C'est ce service qui fait foi ici — PAS un champ
 * pré-rempli par le LLM.
 * En bonus, ce service RENSEIGNE typePressenti (RSS ou HtmlLlm) sur la
 * suggestion à chaque passage, même quand l'auto-validation échoue : cela
 * pré-sélectionne le bon type dans le dropdown de /admin/suggested-sources
 * pour la validation manuelle restante (HTML/LLM).
 *
 * ── GARDE-FOUS QUALITÉ (ADR-0034) ─────────────────────────────────────────────
 *   1. FeedDetectorService confirme un flux RSS/Atom réellement lisible.
 *   2. Déduplication stricte par URL (ScrapingSourceRepository::findByUrl) —
 *      idempotence : relancer la découverte ne crée jamais de doublon.
 *   3. Traçabilité : chaque auto-validation est logguée (INFO) et la suggestion
 *      passe à un statut distinct (AutoValidee), visible dans
 *      /admin/suggested-sources pour audit.
 *   4. Les suggestions non-RSS restent INCHANGÉES en AValider — validation
 *      manuelle intacte, ce service ne fait qu'AJOUTER un chemin automatique.
 *
 * ── CE QUE CE SERVICE NE FAIT PAS ─────────────────────────────────────────────
 *   Il ne flush PAS l'EntityManager (la persistance de la ScrapingSource passe
 *   par SuggestedSourcePromotionService::createScrapingSourceFromSuggestion(),
 *   qui persist() sans flush — le flush reste centralisé dans l'appelant, en
 *   général app:discover-sources, pour une seule transaction par run).
 *
 * ── CORRECTIF SSRF CRITIQUE (relecture ADR-0034) ──────────────────────────────
 * L'URL de la suggestion vient d'un HTML tiers extrait par le LLM, JAMAIS revue
 * par un humain. Avant ce correctif, elle était passée directement à
 * FeedDetectorService::detect() sans aucune vérification d'hôte : une URL
 * pointant vers localhost, une IP privée (RFC1918) ou les métadonnées cloud
 * (169.254.169.254) aurait pu déclencher un fetch interne, et — si un flux RSS
 * y était détecté (peu probable mais pas impossible sur un service interne mal
 * configuré) — la création d'une ScrapingSource active fetchée toutes les 6h
 * par le cron. On vérifie donc désormais SsrfGuard::isSafeHost($url) AVANT tout
 * appel à detect(). Un hôte non sûr ne bloque JAMAIS silencieusement la
 * suggestion : elle reste simplement en file de validation manuelle
 * (RESULT_MANUAL), visible par un humain sur /admin/suggested-sources — jamais
 * de ScrapingSource créée pour un hôte non sûr.
 */
class SuggestedSourceAutoValidationService
{
    /**
     * Résultats possibles de tryAutoValidate(), utilisés pour le reporting appelant.
     */
    public const RESULT_AUTO_VALIDATED = 'auto_validee';
    public const RESULT_DUPLICATE      = 'doublon';
    public const RESULT_MANUAL         = 'manuel';
    public const RESULT_NO_URL         = 'sans_url';

    public function __construct(
        // Confirme (ou infirme) qu'une URL est un vrai flux RSS/Atom lisible — le garde-fou qualité.
        private readonly FeedDetectorService $feedDetector,
        // Vérifie qu'aucune ScrapingSource n'a déjà cette URL (déduplication).
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // Factorise la création de la ScrapingSource (logique partagée avec le controller admin).
        private readonly SuggestedSourcePromotionService $promotionService,
        // SsrfGuard — garde-fou SSRF partagé (correctif critique ADR-0034, cf. doc de classe).
        // Vérifié AVANT tout appel à feedDetector->detect() : l'URL vient d'un LLM,
        // jamais revue par un humain, donc potentiellement malveillante.
        private readonly SsrfGuard $ssrfGuard,
        // Logger PSR-3 — traçabilité obligatoire (ADR-0034) de chaque auto-validation.
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Tente d'auto-valider une suggestion RSS confirmée fonctionnelle.
     *
     * Modifie potentiellement l'entité passée en paramètre (typePressenti, statut)
     * et persiste une nouvelle ScrapingSource si l'auto-validation réussit — mais
     * NE FLUSH JAMAIS (voir note de classe). L'appelant doit flush après l'appel
     * (ou après une boucle d'appels, comme dans app:discover-sources).
     *
     * @param SuggestedSource $suggestion Suggestion à tester (doit être en statut AValider)
     *
     * @return string Une des constantes RESULT_* ci-dessus, pour le reporting appelant :
     *   - RESULT_NO_URL         : pas d'URL, aucune vérification possible, reste AValider
     *   - RESULT_MANUAL         : URL testée mais pas de flux RSS confirmé, reste AValider
     *                             (typePressenti est tout de même renseigné à HtmlLlm) —
     *                             ou hôte rejeté par SsrfGuard (correctif critique ADR-0034,
     *                             cf. doc de classe) : reste AValider, PAS de detect() appelé.
     *   - RESULT_DUPLICATE      : flux RSS confirmé MAIS une ScrapingSource existe déjà
     *                             avec cette URL → aucune recréation, statut passé à Validee
     *                             (le résultat final "une source active existe" est atteint,
     *                             juste pas via CE process — évite de re-tester indéfiniment)
     *   - RESULT_AUTO_VALIDATED : flux RSS confirmé et nouveau → ScrapingSource créée
     *                             (persist, pas flush), statut passé à AutoValidee
     */
    public function tryAutoValidate(SuggestedSource $suggestion): string
    {
        $url = $suggestion->getUrl();

        // ── Pré-condition : une URL est indispensable pour tester quoi que ce soit ──
        if (empty($url)) {
            return self::RESULT_NO_URL;
        }

        // ── Garde SSRF (correctif critique ADR-0034) : AVANT tout appel réseau ──
        // L'URL vient d'un HTML tiers extrait par le LLM — jamais revue par un humain.
        // On refuse de la passer à feedDetector->detect() si l'hôte est localhost,
        // une IP privée (RFC1918) ou les métadonnées cloud (169.254.169.254).
        // Pas de blocage silencieux : la suggestion reste visible en validation
        // manuelle (RESULT_MANUAL) pour qu'un humain puisse l'examiner et la rejeter.
        if (!$this->ssrfGuard->isSafeHost($url)) {
            $this->logger->warning(
                '[AutoValidationRSS] SSRF bloqué : hôte non sûr, suggestion laissée en validation manuelle.',
                ['nom' => $suggestion->getNomOrganisme(), 'url' => $url]
            );

            // On renseigne quand même typePressenti à HtmlLlm par cohérence avec le
            // comportement existant (pré-sélection du dropdown de validation manuelle) —
            // un hôte non sûr ne peut de toute façon jamais être auto-validé en RSS.
            $suggestion->setTypePressenti(ScrapingSourceType::HtmlLlm->value);

            return self::RESULT_MANUAL;
        }

        // ── Garde-fou qualité : FeedDetectorService confirme (ou non) un flux réel ──
        // detect() ne lève jamais d'exception (voir sa doc de classe) — appel sûr.
        $detection = $this->feedDetector->detect($url);

        // Bonus traçabilité : on renseigne typePressenti dans TOUS les cas (RSS ou non).
        // Utile pour pré-sélectionner le bon type dans le dropdown de validation manuelle
        // restante (/admin/suggested-sources) même quand l'auto-validation n'aboutit pas.
        $suggestion->setTypePressenti(
            $detection['type'] === 'rss'
                ? ScrapingSourceType::RSS->value
                : ScrapingSourceType::HtmlLlm->value
        );

        if ($detection['type'] !== 'rss') {
            // Pas de flux RSS/Atom confirmé → comportement INCHANGÉ : reste en file
            // de validation manuelle (AValider). C'est le cœur de la décision ADR-0034 :
            // seul le RSS confirmé est automatisé, le HTML/LLM reste supervisé par un humain.
            return self::RESULT_MANUAL;
        }

        // ── Déduplication stricte par URL ────────────────────────────────────────
        // Cas rare mais possible : une ScrapingSource avec cette URL a été créée entre
        // la découverte de la suggestion et cet appel (ex : ajout manuel admin en parallèle,
        // ou ré-exécution de app:discover-sources sur une suggestion déjà en attente).
        // On ne recrée JAMAIS de doublon — la déduplication est un garde-fou explicite
        // de l'ADR-0034 et une garantie d'idempotence.
        if ($this->scrapingSourceRepository->findByUrl($url) !== null) {
            // On marque quand même la suggestion Validee (et non AutoValidee) : l'issue
            // finale — "une ScrapingSource active existe pour cette URL" — est bien
            // atteinte, mais pas via CE process d'auto-validation. On évite ainsi de
            // re-tester cette même URL à chaque run futur.
            $suggestion->setStatut(SuggestedSourceStatus::Validee);

            $this->logger->info(
                '[AutoValidationRSS] Doublon détecté — ScrapingSource déjà existante, '
                . 'suggestion marquée Validee sans recréation.',
                ['nom' => $suggestion->getNomOrganisme(), 'url' => $url]
            );

            return self::RESULT_DUPLICATE;
        }

        // ── Promotion : création de la ScrapingSource active ─────────────────────
        // feed_url peut être non-null si FeedDetectorService a trouvé le flux sur une
        // variante (ex : /feed/) plutôt que sur l'URL principale — voir la doc de
        // SuggestedSourcePromotionService::createScrapingSourceFromSuggestion().
        $feedUrl = $detection['feed_url'];

        $this->promotionService->createScrapingSourceFromSuggestion(
            $suggestion,
            ScrapingSourceType::RSS,
            $feedUrl
        );

        $suggestion->setStatut(SuggestedSourceStatus::AutoValidee);

        // Traçabilité obligatoire (ADR-0034) : chaque auto-validation est logguée.
        $this->logger->info(
            '[AutoValidationRSS] Source RSS auto-validée sans intervention admin (ADR-0034).',
            [
                'nom'      => $suggestion->getNomOrganisme(),
                'url'      => $url,
                'feed_url' => $feedUrl,
            ]
        );

        return self::RESULT_AUTO_VALIDATED;
    }
}
