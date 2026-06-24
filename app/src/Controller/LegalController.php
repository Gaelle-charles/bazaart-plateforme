<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LegalController — pages légales publiques de la plateforme Bazaart.
 *
 * Ces trois pages sont PUBLIQUES : elles ne nécessitent aucune authentification.
 * Elles étendent base_app.html.twig (layout pages publiques avec nav Street).
 *
 * Routes :
 *   GET /confidentialite   → app_legal_privacy   (politique de confidentialité)
 *   GET /cgu               → app_legal_cgu        (conditions générales d'utilisation + CGV article 11)
 *   GET /cgv               → app_legal_cgv        (301 → /cgu, CGV intégrées article 11)
 *   GET /mentions-legales  → app_legal_mentions   (mentions légales)
 *
 * Ces routes doivent être ajoutées dans access_control de security.yaml en PUBLIC_ACCESS.
 * Pour l'instant elles passent sous la règle "^/" qui demande ROLE_USER — à corriger
 * en ajoutant les entrées explicites PUBLIC_ACCESS dans security.yaml.
 *
 * Convention CDC V3 §4 : URLs en kebab-case.
 */
class LegalController extends AbstractController
{
    /**
     * Page de politique de confidentialité.
     *
     * Obligatoire RGPD (article 13-14 du RGPD) : l'utilisateur doit pouvoir
     * consulter cette page AVANT de créer son compte, donc accès public.
     */
    #[Route('/confidentialite', name: 'app_legal_privacy')]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    /**
     * Conditions Générales d'Utilisation.
     *
     * Présentées lors de l'inscription (lien dans le formulaire).
     * Accès public pour que l'utilisateur puisse les lire sans compte.
     */
    #[Route('/cgu', name: 'app_legal_cgu')]
    public function cgu(): Response
    {
        return $this->render('legal/cgu.html.twig');
    }

    /**
     * Mentions légales.
     *
     * Obligation légale française (LCEN art. 6-III) pour tout site web
     * publiant des informations en ligne. Accès public obligatoire.
     */
    #[Route('/mentions-legales', name: 'app_legal_mentions')]
    public function mentions(): Response
    {
        return $this->render('legal/mentions.html.twig');
    }

    /**
     * Redirection permanente /cgv → /cgu (Article 11).
     *
     * Les Conditions Générales de Vente (CGV) sont intégrées directement
     * dans les CGU à l'Article 11 "Conditions de vente et abonnements".
     * Cette route redirige en 301 (permanent) pour :
     *   - Permettre aux utilisateurs de trouver les CGV via /cgv
     *   - Indiquer aux moteurs de recherche que l'URL canonique est /cgu
     *
     * Obligation légale : les CGV doivent être accessibles avant tout achat.
     * Elles sont affichées sur la page /tarifs (lien) et ici via /cgu#cgu-11.
     */
    #[Route('/cgv', name: 'app_legal_cgv')]
    public function cgv(): Response
    {
        // Redirection 301 (Moved Permanently) vers les CGU qui contiennent l'Article 11 (CGV)
        return $this->redirect($this->generateUrl('app_legal_cgu'), Response::HTTP_MOVED_PERMANENTLY);
    }
}
