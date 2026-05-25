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
 *   GET /cgu               → app_legal_cgu        (conditions générales d'utilisation)
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
}
