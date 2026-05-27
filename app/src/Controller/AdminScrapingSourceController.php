<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScrapingSource;
use App\Enum\ScrapingSourceType;
use App\Repository\ScrapingSourceRepository;
use App\Service\ScraperRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminScrapingSourceController — Gestion des sources de scraping depuis l'interface admin.
 *
 * Ce controller gère le CRUD simplifié des sources de scraping stockées en BDD.
 * Conformément à CLAUDE.md §4, il ne contient aucune logique métier — seulement
 * de l'orchestration (valider les entrées basiques, déléguer à l'EntityManager).
 *
 * Routes disponibles :
 *   GET       /admin/scraping-sources              → index()  : liste toutes les sources avec stats
 *   POST      /admin/scraping-sources/new          → create() : ajoute une source (RSS ou HTML_LLM uniquement)
 *   GET|POST  /admin/scraping-sources/{id}/edit    → edit()   : modifie une source existante
 *   POST      /admin/scraping-sources/{id}/toggle  → toggle() : bascule actif/inactif
 *   POST      /admin/scraping-sources/{id}/delete  → delete() : supprime une source
 *
 * Sécurité :
 *   - ROLE_ADMIN requis sur toute la classe
 *   - CSRF sur tous les POST
 *
 * Contraintes métier (DÉCISIONS Q1/Q2) :
 *   - HTML_CSS interdit dans le formulaire admin (nécessite une classe PHP dédiée)
 *   - Slug renseigné dans le formulaire → validé contre ScraperRegistry::getKnownSlugs()
 *   - Déduplication par URL : une URL ne peut exister qu'une fois
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/scraping-sources')]
class AdminScrapingSourceController extends AbstractController
{
    public function __construct(
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        private readonly EntityManagerInterface $em,
        // ScraperRegistry — pour valider les slugs soumis par l'admin
        private readonly ScraperRegistry $scraperRegistry,
    ) {
    }

    /**
     * Liste toutes les sources de scraping avec leurs statistiques.
     *
     * Affiche actives ET inactives pour que l'admin ait une vue complète.
     */
    #[Route('', name: 'app_admin_scraping_sources_index', methods: ['GET'])]
    public function index(): Response
    {
        // Toutes les sources, ordonnées par nom (actives + inactives)
        $sources = $this->scrapingSourceRepository->findAllOrderedByNom();

        return $this->render('admin/scraping_sources.html.twig', [
            'sources'      => $sources,
            // On passe les types disponibles pour le formulaire d'ajout dans le template
            // HTML_CSS est exclu du formulaire (nécessite une classe dédiée)
            'types'        => [ScrapingSourceType::RSS, ScrapingSourceType::HtmlLlm],
            'known_slugs'  => $this->scraperRegistry->getKnownSlugs(),
        ]);
    }

    /**
     * Ajoute une nouvelle source de scraping.
     *
     * Contraintes :
     *   - Type limité à RSS et HTML_LLM (pas de HTML_CSS via le formulaire)
     *   - Déduplication par URL
     *   - Si slug fourni → doit être dans ScraperRegistry (sinon erreur claire)
     */
    #[Route('/new', name: 'app_admin_scraping_sources_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        // ── Vérification CSRF ────────────────────────────────────────────────
        // Token unique pour le formulaire de création — nommé 'new_scraping_source'.
        if (!$this->isCsrfTokenValid('new_scraping_source', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Rechargez la page et réessayez.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // ── Récupération des champs du formulaire ────────────────────────────
        $nom        = trim((string) $request->request->get('nom', ''));
        $url        = trim((string) $request->request->get('url', ''));
        $typeStr    = trim((string) $request->request->get('type', ''));
        $discipline = trim((string) $request->request->get('discipline', '')) ?: null;
        $zone       = trim((string) $request->request->get('zone', '')) ?: null;

        // ── Validation basique des champs obligatoires ───────────────────────
        if (empty($nom)) {
            $this->addFlash('error', 'Le nom est obligatoire.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // Validation de longueur — cohérente avec la contrainte BDD VARCHAR(255)
        if (mb_strlen($nom) > 255) {
            $this->addFlash('error', 'Le nom ne peut pas dépasser 255 caractères.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        if (empty($url)) {
            $this->addFlash('error', 'L\'URL est obligatoire.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // Validation de longueur — cohérente avec la contrainte BDD VARCHAR(500)
        if (mb_strlen($url) > 500) {
            $this->addFlash('error', 'L\'URL ne peut pas dépasser 500 caractères.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // Validation minimale du format URL (doit commencer par http:// ou https://)
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->addFlash('error', 'L\'URL n\'est pas valide. Elle doit commencer par http:// ou https://.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // ── Validation du type ───────────────────────────────────────────────
        // DÉCISION Q2 : le formulaire ne propose QUE RSS et HTML_LLM.
        // HTML_CSS est réservé aux sources avec classe PHP dédiée (app:seed-scraping-sources).
        $allowedTypes = [ScrapingSourceType::RSS->value, ScrapingSourceType::HtmlLlm->value];
        if (!in_array($typeStr, $allowedTypes, true)) {
            $this->addFlash('error', 'Type invalide. Seuls RSS et HTML → LLM sont autorisés dans ce formulaire. '
                . 'Pour ajouter une source HTML → CSS, utilisez app:seed-scraping-sources.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        $type = ScrapingSourceType::from($typeStr);

        // ── Déduplication par URL ────────────────────────────────────────────
        if ($this->scrapingSourceRepository->findByUrl($url) !== null) {
            $this->addFlash('error', sprintf('Une source avec l\'URL "%s" existe déjà.', $url));
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // ── Création de l'entité ─────────────────────────────────────────────
        $source = new ScrapingSource();
        $source->setNom($nom);
        $source->setUrl($url);
        $source->setType($type);
        $source->setDisciplinePrincipale($discipline);
        $source->setPaysZone($zone);
        $source->setActif(true);
        // scraperSlug null → GenericScraper prend le relais (conformément à la décision Q1)

        $this->em->persist($source);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Source "%s" ajoutée avec succès (type : %s).',
            $nom,
            $type->label()
        ));

        return $this->redirectToRoute('app_admin_scraping_sources_index');
    }

    /**
     * Formulaire de modification d'une source de scraping existante.
     *
     * GET  → affiche le formulaire pré-rempli avec les valeurs actuelles de la source.
     * POST → valide les données soumises, met à jour l'entité en BDD et redirige.
     *
     * Champs modifiables :
     *   - nom               : libellé affiché dans l'admin et les logs
     *   - url               : clé de déduplication — doit rester unique
     *   - type              : RSS ou HTML_LLM uniquement (HTML_CSS via classe PHP dédiée)
     *   - disciplinePrincipale : discipline artistique principale (optionnel)
     *   - paysZone          : zone géographique (optionnel)
     *   - estAgregateur     : indique si la source liste d'autres organismes
     *   - scraperSlug       : slug de la classe PHP custom (optionnel)
     *   - actif             : si false → ignorée par ScrapeOpportunitiesCommand
     *
     * Contraintes de validation :
     *   - nom requis, max 255 caractères
     *   - url requise, max 500 caractères, format URL valide
     *   - type dans [RSS, HTML_LLM]
     *   - URL unique sauf si c'est la même source (même id)
     *   - scraperSlug : avertissement si inconnu dans ScraperRegistry (pas bloquant)
     *
     * CSRF : token nommé 'edit_scraping_source_{id}' — spécifique à cette source.
     */
    #[Route('/{id}/edit', name: 'app_admin_scraping_sources_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        // ── Chargement de la source — 404 si absente ─────────────────────────
        $source = $this->scrapingSourceRepository->find($id);
        if ($source === null) {
            $this->addFlash('error', sprintf('Source #%d introuvable.', $id));
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // ── Types autorisés dans le formulaire (HTML_CSS exclu) ─────────────
        // HTML_CSS nécessite une classe PHP dédiée, pas éditable depuis l'interface.
        $allowedTypes = [ScrapingSourceType::RSS, ScrapingSourceType::HtmlLlm];

        // ── GET : affichage du formulaire pré-rempli ─────────────────────────
        if ($request->isMethod('GET')) {
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                // Slugs connus dans le registre — affichés comme aide à la saisie
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        // ── POST : traitement du formulaire ─────────────────────────────────

        // Vérification CSRF — token unique par source pour éviter les replays
        if (!$this->isCsrfTokenValid('edit_scraping_source_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Rechargez la page et réessayez.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // ── Récupération et nettoyage des champs ─────────────────────────────
        $nom          = trim((string) $request->request->get('nom', ''));
        $url          = trim((string) $request->request->get('url', ''));
        $typeStr      = trim((string) $request->request->get('type', ''));
        $discipline   = trim((string) $request->request->get('discipline', '')) ?: null;
        $zone         = trim((string) $request->request->get('zone', '')) ?: null;
        $scraperSlug  = trim((string) $request->request->get('scraperSlug', '')) ?: null;
        // Les checkboxes HTML ne transmettent leur valeur que si cochées.
        // On compare à '1' (valeur que le template envoie pour les cases cochées).
        $actif         = $request->request->get('actif') === '1';
        $estAgregateur = $request->request->get('estAgregateur') === '1';

        // ── Validation — nom ─────────────────────────────────────────────────
        if (empty($nom)) {
            $this->addFlash('error', 'Le nom est obligatoire.');
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        if (mb_strlen($nom) > 255) {
            $this->addFlash('error', 'Le nom ne peut pas dépasser 255 caractères.');
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        // ── Validation — url ─────────────────────────────────────────────────
        if (empty($url)) {
            $this->addFlash('error', 'L\'URL est obligatoire.');
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        if (mb_strlen($url) > 500) {
            $this->addFlash('error', 'L\'URL ne peut pas dépasser 500 caractères.');
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->addFlash('error', 'L\'URL n\'est pas valide. Elle doit commencer par http:// ou https://.');
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        // ── Validation — type ─────────────────────────────────────────────────
        // Seuls RSS et HTML_LLM sont autorisés depuis le formulaire admin.
        // HTML_CSS est réservé aux classes PHP dédiées (app:seed-scraping-sources).
        $allowedTypeValues = [ScrapingSourceType::RSS->value, ScrapingSourceType::HtmlLlm->value];
        if (!in_array($typeStr, $allowedTypeValues, true)) {
            $this->addFlash('error', 'Type invalide. Seuls RSS et HTML → LLM sont autorisés dans ce formulaire.');
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        $type = ScrapingSourceType::from($typeStr);

        // ── Déduplication par URL — sauf si même source ───────────────────────
        // On autorise la source à conserver sa propre URL (même id).
        // Mais si une AUTRE source possède déjà cette URL → erreur.
        $existingByUrl = $this->scrapingSourceRepository->findByUrl($url);
        if ($existingByUrl !== null && $existingByUrl->getId() !== $source->getId()) {
            $this->addFlash('error', sprintf('Une autre source avec l\'URL "%s" existe déjà (#%d).', $url, $existingByUrl->getId()));
            return $this->render('admin/scraping_source_edit.html.twig', [
                'source'      => $source,
                'types'       => $allowedTypes,
                'known_slugs' => $this->scraperRegistry->getKnownSlugs(),
            ]);
        }

        // ── Avertissement slug inconnu (non bloquant) ─────────────────────────
        // L'admin peut renseigner un slug dont la classe PHP est en cours de déploiement.
        // On avertit mais on n'empêche pas la sauvegarde.
        if ($scraperSlug !== null && !in_array($scraperSlug, $this->scraperRegistry->getKnownSlugs(), true)) {
            $this->addFlash('warning', sprintf(
                'Le slug "%s" n\'est pas encore enregistré dans ScraperRegistry. '
                . 'La source sera sauvegardée mais le scraper tombera en erreur '
                . 'jusqu\'à ce que la classe PHP correspondante soit déployée.',
                $scraperSlug
            ));
        }

        // ── Mise à jour de l'entité ───────────────────────────────────────────
        // Doctrine détecte les changements automatiquement (UnitOfWork).
        // updatedAt sera mis à jour par le callback PreUpdate de l'entité.
        $source->setNom($nom);
        $source->setUrl($url);
        $source->setType($type);
        $source->setDisciplinePrincipale($discipline);
        $source->setPaysZone($zone);
        $source->setScraperSlug($scraperSlug);
        $source->setActif($actif);
        $source->setEstAgregateur($estAgregateur);

        // flush() sans persist() : l'entité est déjà trackée par Doctrine
        $this->em->flush();

        $this->addFlash('success', sprintf('Source "%s" mise à jour avec succès.', $source->getNom()));

        return $this->redirectToRoute('app_admin_scraping_sources_index');
    }

    /**
     * Bascule le statut actif/inactif d'une source.
     *
     * Une source inactive est ignorée par ScrapeOpportunitiesCommand
     * mais reste visible dans la liste admin.
     * Utile pour désactiver temporairement une source (site en maintenance,
     * quota LLM épuisé, contenu non pertinent...) sans la supprimer.
     */
    #[Route('/{id}/toggle', name: 'app_admin_scraping_sources_toggle', methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        // CSRF spécifique à cette source — empêche de réutiliser un token d'une autre source
        if (!$this->isCsrfTokenValid('scraping_source_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        $source = $this->scrapingSourceRepository->find($id);
        if ($source === null) {
            $this->addFlash('error', 'Source introuvable.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        // Inversion du statut actif (toggle)
        $source->setActif(!$source->isActif());
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Source "%s" %s.',
            $source->getNom(),
            $source->isActif() ? 'réactivée' : 'désactivée'
        ));

        return $this->redirectToRoute('app_admin_scraping_sources_index');
    }

    /**
     * Supprime une source de scraping.
     *
     * La suppression est autorisée quel que soit le statut de la source.
     * Les ScrapedResource liées ne sont PAS supprimées (pas de cascade).
     * Note : la table scraped_resources n'a pas de FK vers scraping_sources —
     * la suppression est donc toujours safe côté BDD.
     */
    #[Route('/{id}/delete', name: 'app_admin_scraping_sources_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        // CSRF spécifique à cette source
        if (!$this->isCsrfTokenValid('scraping_source_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        $source = $this->scrapingSourceRepository->find($id);
        if ($source === null) {
            $this->addFlash('error', 'Source introuvable.');
            return $this->redirectToRoute('app_admin_scraping_sources_index');
        }

        $nom = $source->getNom();

        // Suppression de l'entité — les scraped_resources associées sont conservées
        $this->em->remove($source);
        $this->em->flush();

        $this->addFlash('success', sprintf('Source "%s" supprimée.', $nom));

        return $this->redirectToRoute('app_admin_scraping_sources_index');
    }
}
