<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * CourseProposalController — DÉSACTIVÉ (ADR-0012 révisé, juin 2026).
 *
 * La fonctionnalité « proposer une formation » depuis l'espace membre a été retirée
 * par décision d'architecture : la gestion des formations reste 100 % admin.
 *
 * Ce fichier est conservé (approche non destructive en production) mais ne déclare
 * plus AUCUNE route. La table `course_proposals` et les propositions déjà reçues
 * restent consultables via l'interface admin (/admin/formations/propositions).
 *
 * Si un jour on réouvre cette fonctionnalité, ce fichier est le bon endroit
 * pour la réimplémenter (garder le template course/proposal_new.html.twig également).
 */
// `final` est intentionnel : empêche toute extension accidentelle de cette classe archivée.
// Si la fonctionnalité est réactivée en V2, créer un nouveau controller plutôt qu'hériter.
final class CourseProposalController
{
    // Classe intentionnellement vide — aucune route exposée aux membres.
}

