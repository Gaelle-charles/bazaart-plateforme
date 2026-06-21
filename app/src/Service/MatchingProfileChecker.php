<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * MatchingProfileChecker — Source de vérité unique pour la complétude du profil matching.
 *
 * POURQUOI UN SERVICE DÉDIÉ ET NON UNE MÉTHODE SUR L'ENTITÉ ?
 * ─────────────────────────────────────────────────────────────
 * On aurait pu mettre isCompleteForMatching() directement sur User ou ArtistProfile.
 * C'est une option valide mais elle a un défaut : la règle de "complétude pour le matching"
 * est une règle MÉTIER qui peut évoluer (on ajoute location en Option B, on ajoutera peut-être
 * d'autres critères en V2). Mettre cette règle dans l'entité la couple au domaine métier
 * matching, alors que les entités doivent rester "neutres" (juste des conteneurs de données).
 *
 * Un service dédié, en revanche :
 *   1. Suit le principe de responsabilité unique (SRP) : une seule raison de changer.
 *   2. Est plus facile à tester unitairement (pas de setup Doctrine lourd).
 *   3. Peut être étendu sans toucher aux entités (ajouter une méthode getIncompleteReasons()
 *      pour afficher un message ciblé, par exemple).
 *
 * CRITÈRES DE COMPLÉTUDE (Option B validée le 20 juin 2026) :
 * ─────────────────────────────────────────────────────────────
 * Tous les critères suivants DOIVENT être satisfaits pour qu'un profil soit "complet" :
 *
 *   1. ArtistProfile non null
 *      → Sans profil artiste, l'utilisateur n'est même pas artiste.
 *
 *   2. displayName non vide (après trim)
 *      → C'est le nom public de l'artiste, affiché sur les cartes de matching.
 *        Sans nom, l'expérience utilisateur est dégradée et le profil est clairement incomplet.
 *
 *   3. location non vide (après trim)  ← NOUVEAU critère Option B
 *      → La localisation est un critère clé du scoring (composante "territoire", 20 pts max).
 *        Un profil sans location obtiendra toujours 0 sur cette composante, ce qui fausse
 *        le classement. De plus, c'est une information attendue d'un profil artiste sérieux.
 *
 *   4. Au moins 1 discipline renseignée
 *      → Les disciplines sont la composante principale du scoring (40 pts max).
 *        Sans discipline, les matchs retournés sont quasi aléatoires.
 *
 *   5. lookingFor non vide (sur User)
 *      → Ce que l'artiste recherche (résidence, financement, etc.) alimente
 *        la composante "type de ressource recherché" (30 pts max).
 *        Sans ce champ, le matching ne sait pas quoi proposer.
 *
 * CHAMPS INTENTIONNELLEMENT EXCLUS :
 * ─────────────────────────────────────
 *   - bio : optionnelle, n'influe pas sur le scoring, pas bloquante.
 *   - avatarPath : cosmétique, pas d'impact sur la pertinence des matchs.
 *   - websiteUrl / portfolioUrl / socialLinks : idem, informatifs uniquement.
 *   - legalStatus : utile pour le scoring "expérience" (V2), mais pas bloquant en V1.
 *
 * UTILISATION :
 * ─────────────
 *   $checker = new MatchingProfileChecker(); // via autowiring Symfony
 *   if (!$checker->isComplete($user)) {
 *       // rediriger vers l'onboarding ou afficher l'invitation à compléter le profil
 *   }
 */
final class MatchingProfileChecker
{
    /**
     * Vérifie si le profil artiste est suffisamment rempli pour que le matching soit utile.
     *
     * Cette méthode est la SOURCE DE VÉRITÉ UNIQUE pour cette règle.
     * Elle remplace les deux vérifications dupliquées qui existaient dans
     * HomeController et MatchingController (ADR-0021, refactoring Option B).
     *
     * @param User $user L'utilisateur connecté (doit avoir ROLE_ARTIST, vérifié en amont par le voter)
     * @return bool true si tous les critères sont satisfaits, false dès qu'un critère manque
     */
    public function isComplete(User $user): bool
    {
        // ── Critère 1 : l'ArtistProfile doit exister ────────────────────────────
        // Un utilisateur peut avoir ROLE_ARTIST sans ArtistProfile si le profil
        // n'a pas encore été créé (cas rare mais possible en dev ou lors d'import).
        $profile = $user->getArtistProfile();
        if ($profile === null) {
            return false;
        }

        // ── Critère 2 : displayName non vide ────────────────────────────────────
        // displayName est non-nullable en BDD (string, pas ?string), mais peut
        // contenir une chaîne vide ou des espaces si l'onboarding a été contourné.
        // trim() + === '' couvre les deux cas.
        if (trim($profile->getDisplayName()) === '') {
            return false;
        }

        // ── Critère 3 : location non vide (NOUVEAU critère Option B) ─────────────
        // location est nullable (?string) en BDD → on vérifie null ET chaîne vide.
        // Sans localisation, la composante "territoire" du scoring sera toujours 0.
        $location = $profile->getLocation();
        if ($location === null || trim($location) === '') {
            return false;
        }

        // ── Critère 4 : au moins une discipline renseignée ───────────────────────
        // getDisciplines() retourne une Collection Doctrine.
        // isEmpty() est plus idiomatique que count() === 0 sur une Collection.
        if ($profile->getDisciplines()->isEmpty()) {
            return false;
        }

        // ── Critère 5 : lookingFor non vide (sur User, pas sur ArtistProfile) ────
        // getLookingFor() retourne null si jamais renseigné, ou array (potentiellement vide).
        // empty() gère les deux cas : empty(null) = true, empty([]) = true.
        if (empty($user->getLookingFor())) {
            return false;
        }

        // Tous les critères sont satisfaits : le profil est complet pour le matching.
        return true;
    }

    /**
     * Retourne la liste des raisons pour lesquelles le profil est incomplet.
     *
     * Utile pour afficher un message ciblé à l'utilisateur (ex: "Il manque ta localisation").
     * Cette méthode est prévue pour une utilisation future (formulaire de complétion ciblé)
     * mais n'est pas encore utilisée en V1 — on l'inclut car elle coûte peu à écrire
     * maintenant que la logique est centralisée ici.
     *
     * @param User $user L'utilisateur à vérifier
     * @return list<string> Liste de clés de raisons manquantes (chaînes d'identification)
     *                      Ex: ['artist_profile', 'display_name', 'location', 'disciplines', 'looking_for']
     */
    public function getMissingFields(User $user): array
    {
        /** @var list<string> $missing */
        $missing = [];

        $profile = $user->getArtistProfile();

        // Profil artiste absent : tous les autres critères sont implicitement manquants
        if ($profile === null) {
            $missing[] = 'artist_profile';
            // On retourne immédiatement : inutile de tester les sous-critères
            return $missing;
        }

        if (trim($profile->getDisplayName()) === '') {
            $missing[] = 'display_name';
        }

        $location = $profile->getLocation();
        if ($location === null || trim($location) === '') {
            $missing[] = 'location';
        }

        if ($profile->getDisciplines()->isEmpty()) {
            $missing[] = 'disciplines';
        }

        if (empty($user->getLookingFor())) {
            $missing[] = 'looking_for';
        }

        return $missing;
    }
}
