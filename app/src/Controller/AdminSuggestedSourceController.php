<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScrapingSource;
use App\Enum\ScrapingSourceType;
use App\Enum\SuggestedSourceStatus;
use App\Repository\ScrapingSourceRepository;
use App\Repository\SuggestedSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminSuggestedSourceController — Interface de validation des sources suggérées.
 *
 * Ce controller gère les actions admin sur les SuggestedSource créées par
 * la commande app:discover-sources. L'admin peut :
 *   - Valider une suggestion → crée une ScrapingSource et marque la suggestion Validee
 *   - Rejeter une suggestion → marque la suggestion Rejetee (aucune source créée)
 *
 * Conformément à CLAUDE.md §4 :
 *   - Aucune logique métier dans ce controller (déléguée à l'EntityManager directement
 *     car la logique est simple — pas besoin d'un service dédié)
 *   - CSRF sur tous les POST
 *   - ROLE_ADMIN requis sur toute la classe
 *
 * Routes :
 *   GET  /admin/suggested-sources                 → index()    : liste toutes les suggestions
 *   POST /admin/suggested-sources/{id}/validate   → validate() : valide + crée ScrapingSource
 *   POST /admin/suggested-sources/{id}/reject      → reject()   : rejette
 */
#[Route('/admin/suggested-sources', name: 'app_admin_suggested_sources_')]
#[IsGranted('ROLE_ADMIN')]
class AdminSuggestedSourceController extends AbstractController
{
    public function __construct(
        // Repository des suggestions — pour charger et compter les suggestions
        private readonly SuggestedSourceRepository $suggestedSourceRepository,
        // Repository des sources existantes — pour vérifier les doublons lors de la validation
        private readonly ScrapingSourceRepository $scrapingSourceRepository,
        // EntityManager — pour persister les modifications
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Liste toutes les suggestions, groupées par statut.
     *
     * Affiche 3 sections dans le template :
     *   1. "À valider" (en premier — action requise de l'admin)
     *   2. "Validées" (historique des suggestions acceptées)
     *   3. "Rejetées" (historique des suggestions refusées)
     *
     * La variable pendingCount permet d'afficher un badge dans la sidebar
     * sans re-calculer la longueur du tableau côté template.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // Chargement groupé par statut — tri dateDecouverte DESC dans le repository
        $pending   = $this->suggestedSourceRepository->findAllByStatut(SuggestedSourceStatus::AValider);
        $validated = $this->suggestedSourceRepository->findAllByStatut(SuggestedSourceStatus::Validee);
        $rejected  = $this->suggestedSourceRepository->findAllByStatut(SuggestedSourceStatus::Rejetee);

        return $this->render('admin/suggested_sources.html.twig', [
            'pending'      => $pending,
            'validated'    => $validated,
            'rejected'     => $rejected,
            // Badge numérique pour l'en-tête de page (évite de compter dans Twig)
            'pendingCount' => count($pending),
            // Types disponibles pour le formulaire de validation (dropdown)
            // On n'inclut pas HtmlCss car les sources suggérées n'ont pas de classe PHP dédiée
            'types'        => [ScrapingSourceType::RSS, ScrapingSourceType::HtmlLlm],
        ]);
    }

    /**
     * Valide une suggestion et crée une ScrapingSource correspondante.
     *
     * Pré-conditions vérifiées :
     *   1. Token CSRF valide
     *   2. Suggestion trouvée en BDD
     *   3. Suggestion en statut AValider (idempotence)
     *   4. URL présente (obligatoire pour créer une ScrapingSource)
     *   5. URL pas déjà dans scraping_sources (déduplication)
     *   6. Type sélectionné valide (RSS ou HtmlLlm)
     *
     * Effet de bord :
     *   - Crée une ScrapingSource (actif = true, estAgregateur = false)
     *   - Marque la SuggestedSource comme Validee
     *   - Flush en fin
     *
     * @param int     $id      Identifiant de la SuggestedSource à valider
     * @param Request $request Requête HTTP (token CSRF + champ type_pressenti)
     */
    #[Route('/{id}/validate', name: 'validate', methods: ['POST'])]
    public function validate(int $id, Request $request): Response
    {
        // ── Vérification CSRF ────────────────────────────────────────────────
        // Token nommé 'suggested_source_{id}' — spécifique à cette suggestion pour éviter
        // qu'un token valide pour une suggestion serve à en valider une autre.
        if (!$this->isCsrfTokenValid('suggested_source_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Rechargez la page et réessayez.');
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Chargement de la suggestion ──────────────────────────────────────
        $suggestion = $this->suggestedSourceRepository->find($id);

        if ($suggestion === null) {
            $this->addFlash('error', sprintf('Suggestion #%d introuvable.', $id));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Vérification du statut (idempotence) ─────────────────────────────
        // Si la suggestion est déjà validée ou rejetée, on n'agit pas.
        // Cela évite de créer deux ScrapingSource si le formulaire est soumis deux fois.
        if (!$suggestion->isAValider()) {
            $this->addFlash('error', sprintf(
                'La suggestion "%s" n\'est plus en attente de validation (statut : %s).',
                $suggestion->getNomOrganisme(),
                $suggestion->getStatut()->label()
            ));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Vérification de l'URL ─────────────────────────────────────────────
        $url = $suggestion->getUrl();
        if (empty($url)) {
            $this->addFlash('error', sprintf(
                'Impossible de valider "%s" : aucune URL disponible. '
                . 'Corrigez l\'URL manuellement ou rejetez cette suggestion.',
                $suggestion->getNomOrganisme()
            ));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Déduplication par URL ─────────────────────────────────────────────
        // On vérifie qu'aucune ScrapingSource n'a déjà cette URL.
        // (une vérification similaire est faite dans app:discover-sources, mais on double-check
        // ici pour la robustesse — une source peut avoir été ajoutée entre les deux)
        if ($this->scrapingSourceRepository->findByUrl($url) !== null) {
            $this->addFlash('error', sprintf(
                'Une source avec l\'URL "%s" existe déjà dans les sources de scraping.',
                $url
            ));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Validation du type sélectionné ───────────────────────────────────
        // L'admin choisit le type via un dropdown dans le formulaire.
        // Seuls RSS et HtmlLlm sont autorisés (pas HtmlCss, qui nécessite une classe PHP).
        $typeStr      = trim((string) $request->request->get('type_pressenti', ''));
        $allowedTypes = [ScrapingSourceType::RSS->value, ScrapingSourceType::HtmlLlm->value];

        if (!in_array($typeStr, $allowedTypes, true)) {
            $this->addFlash('error', sprintf(
                'Type invalide "%s". Seuls RSS et HTML → LLM sont autorisés.',
                $typeStr
            ));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        $type = ScrapingSourceType::from($typeStr);

        // ── Création de la ScrapingSource ────────────────────────────────────
        // On copie les métadonnées de la suggestion vers la nouvelle source.
        // L'admin pourra ajuster les détails depuis /admin/scraping-sources.
        $source = new ScrapingSource();
        $source->setNom($suggestion->getNomOrganisme());
        $source->setUrl($url);
        $source->setType($type);
        // scraperSlug = null → GenericScraper prendra le relais (pas de classe PHP dédiée)
        // pour les sources découvertes automatiquement.
        $source->setScraperSlug(null);
        $source->setDisciplinePrincipale($suggestion->getDisciplinePressentie());
        $source->setPaysZone($suggestion->getPaysZone());
        // Activée par défaut — l'admin peut désactiver depuis /admin/scraping-sources si besoin
        $source->setActif(true);
        // Les sources découvertes via agrégateurs ne sont pas elles-mêmes des agrégateurs
        // (sauf cas particulier, que l'admin peut corriger manuellement)
        $source->setEstAgregateur(false);

        $this->em->persist($source);

        // ── Mise à jour du statut de la suggestion ───────────────────────────
        $suggestion->setStatut(SuggestedSourceStatus::Validee);
        // Pas besoin de persist() — suggestion est déjà gérée par Doctrine (entity already managed)

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Source "%s" créée et activée avec succès (type : %s).',
            $suggestion->getNomOrganisme(),
            $type->label()
        ));

        return $this->redirectToRoute('app_admin_suggested_sources_index');
    }

    /**
     * Rejette une suggestion (aucune ScrapingSource créée).
     *
     * La suggestion est marquée Rejetee et conservée en historique.
     * Elle ne sera plus re-suggérée par app:discover-sources (déduplication par URL).
     *
     * @param int     $id      Identifiant de la SuggestedSource à rejeter
     * @param Request $request Requête HTTP (token CSRF)
     */
    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        // ── Vérification CSRF ────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('suggested_source_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Rechargez la page et réessayez.');
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Chargement et validation de la suggestion ────────────────────────
        $suggestion = $this->suggestedSourceRepository->find($id);

        if ($suggestion === null) {
            $this->addFlash('error', sprintf('Suggestion #%d introuvable.', $id));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        if (!$suggestion->isAValider()) {
            $this->addFlash('error', sprintf(
                'La suggestion "%s" n\'est plus en attente (statut : %s).',
                $suggestion->getNomOrganisme(),
                $suggestion->getStatut()->label()
            ));
            return $this->redirectToRoute('app_admin_suggested_sources_index');
        }

        // ── Mise à jour du statut ────────────────────────────────────────────
        $suggestion->setStatut(SuggestedSourceStatus::Rejetee);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '"%s" rejeté. Cette URL ne sera plus re-suggérée.',
            $suggestion->getNomOrganisme()
        ));

        return $this->redirectToRoute('app_admin_suggested_sources_index');
    }
}
