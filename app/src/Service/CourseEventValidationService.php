<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;
use App\Enum\CourseEventMode;
use App\Enum\CourseType;

/**
 * CourseEventValidationService — Validation métier des formations de type EVENEMENT.
 *
 * Responsabilité unique : vérifier la cohérence des champs événement d'un Course
 * AVANT de persister en base. Le controller appelle ce service et affiche les
 * messages d'erreur via addFlash() en cas d'échec.
 *
 * Règles métier appliquées :
 *
 *   1. Type EVENEMENT → eventMode obligatoire (VISIO ou PRESENTIEL)
 *   2. eventMode = VISIO     → eventExternalUrl obligatoire ET syntaxiquement valide
 *   3. eventMode = PRESENTIEL → eventLocation obligatoire
 *   4. eventStartAt obligatoire pour les événements
 *   5. eventEndAt doit être postérieur à eventStartAt (si les deux sont renseignés)
 *   6. eventStartAt doit être dans le futur (avertissement non bloquant en édition)
 *
 * Ce service ne fait PAS de flush() — il retourne seulement des messages d'erreur.
 * La persistance reste la responsabilité du controller.
 *
 * Principe thin controller : toute cette logique de validation aurait pu être
 * dans le controller, mais la séparer ici la rend réutilisable et testable unitairement.
 */
class CourseEventValidationService
{
    /**
     * Valide les champs événement d'un Course.
     *
     * Retourne un tableau de messages d'erreur (vide si tout est valide).
     * Le controller parcourt ce tableau pour afficher les flash messages.
     *
     * @param Course $course La formation à valider (après hydratation des setters)
     * @return string[]      Liste des erreurs (vide = valide)
     */
    public function validate(Course $course): array
    {
        // Si la formation est de type CONTENU, aucune validation événement n'est nécessaire.
        // On retourne immédiatement un tableau vide = pas d'erreur.
        if ($course->getType() !== CourseType::EVENEMENT) {
            return [];
        }

        $errors = [];

        // ── Règle 1 : mode obligatoire ────────────────────────────────────────
        // Un événement sans mode (VISIO / PRESENTIEL) n'est pas complet.
        if ($course->getEventMode() === null) {
            $errors[] = 'Le mode de l\'événement (visio ou présentiel) est obligatoire.';
            // On continue la validation pour remonter toutes les erreurs à la fois
        }

        // ── Règle 2 : VISIO → lien externe obligatoire ────────────────────────
        if ($course->getEventMode() === CourseEventMode::VISIO) {
            $url = $course->getEventExternalUrl();

            if ($url === null || trim($url) === '') {
                $errors[] = 'Un événement en visio doit avoir un lien de connexion (URL).';
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                // FILTER_VALIDATE_URL vérifie la syntaxe de l'URL (RFC 2396)
                // On n'applique PAS la liste blanche isAllowedVideoUrl() ici
                // car ce n'est pas une iframe — c'est un lien de redirection.
                $errors[] = sprintf(
                    'Le lien de connexion n\'est pas une URL valide : "%s".',
                    htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }
        }

        // ── Règle 3 : PRESENTIEL → adresse obligatoire ───────────────────────
        if ($course->getEventMode() === CourseEventMode::PRESENTIEL) {
            $location = $course->getEventLocation();

            if ($location === null || trim($location) === '') {
                $errors[] = 'Un événement présentiel doit avoir une adresse (lieu de l\'événement).';
            }
        }

        // ── Règle 4 : date de début obligatoire ───────────────────────────────
        if ($course->getEventStartAt() === null) {
            $errors[] = 'La date et heure de début de l\'événement sont obligatoires.';
        }

        // ── Règle 5 : cohérence début/fin ─────────────────────────────────────
        // On ne vérifie cette règle que si les deux dates sont présentes
        // pour ne pas cumuler des erreurs redondantes avec la règle 4.
        if (
            $course->getEventStartAt() !== null
            && $course->getEventEndAt() !== null
            && $course->getEventEndAt() <= $course->getEventStartAt()
        ) {
            $errors[] = 'La date de fin doit être postérieure à la date de début.';
        }

        // ── Règle 6 : avertissement date passée (non bloquant) ───────────────
        //
        // On ne bloque PAS la publication pour une date passée car l'admin peut
        // avoir des raisons légitimes (republication d'un événement récurrent,
        // correction d'une typo, test en local, etc.).
        // On remonte un avertissement préfixé pour que l'admin puisse
        // ignorer consciemment ou corriger la date.
        //
        // Note phase 2 : quand les inscriptions seront ouvertes, un événement
        // passé ne doit plus accepter de nouvelles inscriptions. Cette règle sera
        // alors portée dans le service d'inscription, pas ici.
        if (
            $course->getEventStartAt() !== null
            && $course->getEventStartAt() < new \DateTime()
        ) {
            // Préfixe [Avertissement] pour distinguer des erreurs bloquantes
            // dans le même tableau — le controller et le template peuvent
            // tester ce préfixe s'ils veulent styler différemment en phase 2.
            $errors[] = '[Avertissement] La date de début est dans le passé — vérifiez avant de publier.';
        }

        return $errors;
    }
}
