<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ArtistLookingFor;
use App\Enum\LegalStatus;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * MatchingFormSessionService — Gestion de la persistance session du formulaire matching home.
 *
 * Ce service centralise toute la logique de lecture/écriture des réponses du
 * formulaire multi-étapes de matching dans la session Symfony.
 *
 * CLÉ DE SESSION UTILISÉE : 'matching_form'
 *
 * STRUCTURE DE LA CLEF SESSION :
 * [
 *   'discipline_ids'     => [3, 7, 12],   // IDs (int) des disciplines sélectionnées
 *   'looking_for'        => ['formations', 'ressources_appels'],  // valeurs ArtistLookingFor
 *   'looking_for_other'  => 'Résidences au Sénégal',  // null si case "autre" non cochée
 *   'legal_status'       => 'artiste_auteur',  // null si non renseigné (optionnel)
 * ]
 *
 * SÉCURITÉ ET VALIDATION :
 *   - Les valeurs sont validées contre les enums pour éviter l'injection de données
 *     arbitraires (ex: un attaquant qui enverrait une valeur de discipline inconnue).
 *   - Les IDs de disciplines sont castés en int (pas de strings parasites).
 *   - Les valeurs ArtistLookingFor sont vérifiées contre l'enum (tryFrom).
 *   - La valeur LegalStatus est vérifiée contre l'enum (tryFrom).
 *
 * LIMITE CONNUE (signalée en open question) :
 *   La session peut être perdue si l'utilisateur confirme son email dans un autre
 *   navigateur que celui où il a rempli le formulaire. C'est le comportement normal
 *   des sessions HTTP (cookies de session = par navigateur).
 *   Solution durable (non implémentée en V1) : stocker les réponses dans un cookie
 *   signé ou dans un enregistrement temporaire en BDD.
 */
final class MatchingFormSessionService
{
    /**
     * Clé unique de session pour les données du formulaire matching.
     * Préfixe "bazaart_" pour éviter toute collision avec d'autres clés de session.
     */
    public const SESSION_KEY = 'bazaart_matching_form';

    // ─────────────────────────────────────────────────────────────────────────
    // LECTURE DES DONNÉES EN SESSION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne toutes les données du formulaire matching depuis la session.
     *
     * Si la clé n'existe pas (premier passage), retourne un tableau vide.
     * Cette méthode est utilisée par HomeController pour pré-remplir le formulaire
     * et par OnboardingController pour pré-remplir l'onboarding.
     *
     * @return array{
     *   discipline_ids: list<int>,
     *   looking_for: list<string>,
     *   looking_for_other: string|null,
     *   legal_status: string|null
     * }
     */
    public function getSessionData(SessionInterface $session): array
    {
        // get() avec un tableau vide comme défaut : si la clé n'existe pas,
        // on retourne une structure cohérente (pas null).
        $raw = $session->get(self::SESSION_KEY, []);

        // On s'assure que $raw est bien un tableau (protection contre une session corrompue)
        if (!is_array($raw)) {
            return $this->emptyData();
        }

        // Normalisation des types (protection contre des données mal typées)
        return [
            'discipline_ids'    => $this->normalizeIntArray($raw['discipline_ids'] ?? []),
            'looking_for'       => $this->normalizeStringArray($raw['looking_for'] ?? []),
            'looking_for_other' => isset($raw['looking_for_other']) && $raw['looking_for_other'] !== ''
                ? (string) $raw['looking_for_other']
                : null,
            'legal_status'      => isset($raw['legal_status']) && $raw['legal_status'] !== ''
                ? (string) $raw['legal_status']
                : null,
        ];
    }

    /**
     * Vérifie si la session contient des données de formulaire matching.
     * Utile pour savoir si un utilisateur vient du formulaire home avant de s'inscrire.
     */
    public function hasSessionData(SessionInterface $session): bool
    {
        // La session a des données si la clé existe ET n'est pas vide
        $data = $session->get(self::SESSION_KEY);

        return is_array($data) && !empty($data);
    }

    /**
     * Retourne uniquement les IDs de disciplines sauvegardés en session.
     * Raccourci utilisé dans les templates pour pré-remplir les checkboxes.
     *
     * @return list<int>
     */
    public function getSavedDisciplineIds(SessionInterface $session): array
    {
        $data = $this->getSessionData($session);

        return $data['discipline_ids'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉCRITURE DES DONNÉES EN SESSION (étape par étape)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sauvegarde les données d'une étape spécifique en session.
     *
     * Appelé par MatchingFormController::saveStep() après chaque "Continuer".
     * On fusionne les nouvelles données avec les données existantes en session
     * (merge partiel : on n'écrase pas les autres étapes).
     *
     * @param SessionInterface     $session  Session Symfony
     * @param int                  $step     Numéro de l'étape (1, 2, 3)
     * @param array<string, mixed> $data     Données brutes du formulaire POST
     *
     * @return string|null Null si succès, message d'erreur si validation échoue
     */
    public function saveStepToSession(SessionInterface $session, int $step, array $data): ?string
    {
        // Récupère les données existantes en session (merge partiel)
        $existing = $this->getSessionData($session);

        // saveStep3 est void (aucune validation bloquante sur le statut juridique optionnel)
        if ($step === 3) {
            $this->saveStep3($session, $existing, $data);
            return null;
        }

        return match ($step) {
            1       => $this->saveStep1($session, $existing, $data),
            2       => $this->saveStep2($session, $existing, $data),
            default => 'Étape invalide.',
        };
    }

    /**
     * Sauvegarde toutes les étapes en une seule fois (cas "sans JS" / soumission globale).
     *
     * Appelé par MatchingFormController::submit() pour gérer le cas où l'utilisateur
     * soumet tout le formulaire d'un coup (sans avoir passé par les saveStep AJAX).
     *
     * @param SessionInterface     $session Session Symfony
     * @param array<string, mixed> $data    Données brutes complètes du formulaire POST
     */
    public function saveAllStepsToSession(SessionInterface $session, array $data): void
    {
        // On sauvegarde les 3 étapes en séquence.
        // Les erreurs sont ignorées ici (la validation complète est faite dans
        // MatchingFormController::submit() via les DTOs d'onboarding).
        $existing = $this->getSessionData($session);
        $this->saveStep1($session, $existing, $data);

        // Relit pour merger proprement (saveStep1 a mis à jour la session)
        $existing = $this->getSessionData($session);
        $this->saveStep2($session, $existing, $data);

        $existing = $this->getSessionData($session);
        $this->saveStep3($session, $existing, $data);
    }

    /**
     * Vide les données du formulaire matching de la session.
     * Appelé après une sauvegarde réussie (Flux B) ou après l'onboarding (carryover).
     */
    public function clearSession(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTHODES PRIVÉES : sauvegarde et validation par étape
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 1 : Disciplines artistiques
     * Validation : au moins une discipline sélectionnée.
     *
     * @param array<string, mixed>    $existing Données existantes en session
     * @param array<string, mixed>    $data     Données POST de l'étape
     */
    private function saveStep1(SessionInterface $session, array $existing, array $data): ?string
    {
        // Extraction et cast en tableau d'entiers
        $rawDisciplines = $data['disciplines'] ?? [];
        $disciplineIds  = is_array($rawDisciplines)
            ? $this->normalizeIntArray($rawDisciplines)
            : [];

        // Validation : au moins une discipline requise
        if (empty($disciplineIds)) {
            return 'Choisis au moins une discipline artistique.';
        }

        // Merge et persistance en session
        $existing['discipline_ids'] = $disciplineIds;
        $session->set(self::SESSION_KEY, $existing);

        return null; // Succès
    }

    /**
     * Étape 2 : lookingFor + lookingForOther
     * Validation : au moins un objectif sélectionné + texte si "autre" coché.
     *
     * @param array<string, mixed> $existing Données existantes en session
     * @param array<string, mixed> $data     Données POST de l'étape
     */
    private function saveStep2(SessionInterface $session, array $existing, array $data): ?string
    {
        // Extraction des valeurs cochées
        $rawValues  = $data['looking_for'] ?? [];
        $rawValues  = is_array($rawValues) ? $rawValues : [];
        // Validation contre l'enum (on ne garde que les valeurs valides)
        $validValues = array_column(ArtistLookingFor::cases(), 'value');
        $lookingFor  = array_values(array_filter(
            array_map('strval', $rawValues),
            static fn (string $v) => in_array($v, $validValues, true)
        ));

        // Validation : au moins 1 option cochée
        if (empty($lookingFor)) {
            return 'Choisis au moins une option pour continuer.';
        }

        // Champ libre "Autre"
        $other = trim((string) ($data['looking_for_other'] ?? ''));

        // Validation croisée : si "autre" est coché, le texte est requis
        if (in_array(ArtistLookingFor::AUTRE->value, $lookingFor, true) && $other === '') {
            return 'Précise ce que tu recherches d\'autre dans le champ texte.';
        }

        $existing['looking_for']       = $lookingFor;
        $existing['looking_for_other'] = $other !== '' ? $other : null;
        $session->set(self::SESSION_KEY, $existing);

        return null; // Succès
    }

    /**
     * Étape 3 : Statut juridique (optionnel)
     * Pas de validation bloquante — null est une valeur valide.
     * La méthode est void : on retourne toujours succès pour cette étape.
     *
     * @param array<string, mixed> $existing Données existantes en session
     * @param array<string, mixed> $data     Données POST de l'étape
     */
    private function saveStep3(SessionInterface $session, array $existing, array $data): void
    {
        $rawStatus = trim((string) ($data['legal_status'] ?? ''));

        // Validation contre l'enum (protection contre les valeurs injectées)
        $legalStatusValue = null;
        if ($rawStatus !== '' && LegalStatus::tryFrom($rawStatus) !== null) {
            $legalStatusValue = $rawStatus;
        }

        $existing['legal_status'] = $legalStatusValue;
        $session->set(self::SESSION_KEY, $existing);
        // Pas de return : méthode void (statut optionnel, aucune validation bloquante)
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UTILITAIRES PRIVÉS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne la structure de données vide (utilisée comme valeur par défaut).
     *
     * @return array{
     *   discipline_ids: list<int>,
     *   looking_for: list<string>,
     *   looking_for_other: null,
     *   legal_status: null
     * }
     */
    private function emptyData(): array
    {
        return [
            'discipline_ids'    => [],
            'looking_for'       => [],
            'looking_for_other' => null,
            'legal_status'      => null,
        ];
    }

    /**
     * Normalise un tableau en liste d'entiers strictement positifs.
     * Retire les valeurs nulles, vides et les non-entiers.
     *
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizeIntArray(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $raw),
            static fn (int $v) => $v > 0  // on ne garde que les IDs positifs
        ));
    }

    /**
     * Normalise un tableau en liste de chaînes non vides.
     *
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeStringArray(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $raw),
            static fn (string $v) => $v !== ''
        ));
    }
}
