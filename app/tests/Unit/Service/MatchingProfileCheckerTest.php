<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ArtistProfile;
use App\Entity\Discipline;
use App\Entity\User;
use App\Service\MatchingProfileChecker;
use PHPUnit\Framework\TestCase;

/**
 * MatchingProfileCheckerTest — Tests unitaires de la règle de complétude du profil matching.
 *
 * Ce fichier de test vérifie que MatchingProfileChecker::isComplete() respecte
 * exactement les 5 critères définis dans l'Option B (validée le 20 juin 2026) :
 *
 *   1. ArtistProfile non null
 *   2. displayName non vide (trim)
 *   3. location non vide (trim)           ← NOUVEAU critère Option B
 *   4. Au moins 1 discipline
 *   5. lookingFor non vide (sur User)
 *
 * STRATÉGIE DE TEST : approche "un critère = un test manquant + un test présent"
 *   On part d'un profil COMPLET (tous les critères satisfaits) et on retire
 *   un critère à la fois pour vérifier que isComplete() retourne false.
 *   Cela garantit qu'aucun critère ne peut être "court-circuité" par un autre.
 *
 * Ces tests sont PUREMENT UNITAIRES : pas de BDD, pas de container Symfony.
 * On instancie directement MatchingProfileChecker (pas de dépendances externes).
 */
class MatchingProfileCheckerTest extends TestCase
{
    // Le service à tester — sans dépendances (classe finale sans constructeur injecté)
    private MatchingProfileChecker $checker;

    protected function setUp(): void
    {
        // Instanciation directe : MatchingProfileChecker n'a pas de dépendances
        $this->checker = new MatchingProfileChecker();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CAS NOMINAL : profil complet
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * CAS DE BASE : tous les critères satisfaits → isComplete() doit retourner true.
     * Ce test sert d'ancrage : si tous les autres "retirent un critère", celui-ci
     * valide que le profil de référence est bien considéré complet.
     */
    public function testIsComplete_ProfilComplet_RetourneTrue(): void
    {
        $user = $this->makeCompleteUser();

        $this->assertTrue(
            $this->checker->isComplete($user),
            "Un profil avec tous les critères satisfaits doit être considéré complet"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CRITÈRE 1 : ArtistProfile non null
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Si l'utilisateur n'a pas du tout d'ArtistProfile → incomplet.
     * Cas : utilisateur inscrit mais onboarding jamais démarré.
     */
    public function testIsComplete_SansArtistProfile_RetourneFalse(): void
    {
        $user = new User();
        $this->setPrivateProperty($user, 'email', 'artiste@test.fr');
        $this->setPrivateProperty($user, 'roles', ['ROLE_ARTIST']);
        // artistProfile reste null (valeur par défaut de l'entité User)

        $this->assertFalse(
            $this->checker->isComplete($user),
            "Sans ArtistProfile, isComplete() doit retourner false"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CRITÈRE 2 : displayName non vide
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * displayName = chaîne vide '' → incomplet.
     * Cas : onboarding contourné ou bug formulaire.
     */
    public function testIsComplete_DisplayNameVide_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        // On écrase le displayName avec une chaîne vide
        $user->getArtistProfile()?->setDisplayName('');

        $this->assertFalse(
            $this->checker->isComplete($user),
            "displayName vide ('') doit rendre le profil incomplet"
        );
    }

    /**
     * displayName = espaces seulement → incomplet (trim() donne '').
     * Cas : formulaire soumis avec des espaces, pas de trim côté form.
     */
    public function testIsComplete_DisplayNameEspacesSeuls_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        $user->getArtistProfile()?->setDisplayName('   ');

        $this->assertFalse(
            $this->checker->isComplete($user),
            "displayName contenant uniquement des espaces doit rendre le profil incomplet"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CRITÈRE 3 : location non vide (NOUVEAU Option B)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * location = null → incomplet.
     * Cas : artiste créé avant l'Option B, ou onboarding incomplet.
     */
    public function testIsComplete_LocationNull_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        $user->getArtistProfile()?->setLocation(null);

        $this->assertFalse(
            $this->checker->isComplete($user),
            "location null doit rendre le profil incomplet (nouveau critère Option B)"
        );
    }

    /**
     * location = chaîne vide '' → incomplet.
     * Cas : champ soumis vide.
     */
    public function testIsComplete_LocationChaineVide_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        $user->getArtistProfile()?->setLocation('');

        $this->assertFalse(
            $this->checker->isComplete($user),
            "location vide ('') doit rendre le profil incomplet"
        );
    }

    /**
     * location = espaces seulement → incomplet (trim() donne '').
     */
    public function testIsComplete_LocationEspacesSeuls_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        $user->getArtistProfile()?->setLocation('   ');

        $this->assertFalse(
            $this->checker->isComplete($user),
            "location contenant uniquement des espaces doit rendre le profil incomplet"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CRITÈRE 4 : au moins 1 discipline
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Aucune discipline renseignée → incomplet.
     * Cas : profil créé mais étape "disciplines" non complétée.
     */
    public function testIsComplete_AucuneDiscipline_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();

        // On retire toutes les disciplines en injectant une collection vide
        // (removeDiscipline() est disponible mais on utilise la réflexion pour
        // garantir une collection vide, même si le profil de référence en avait plusieurs)
        $profile = $user->getArtistProfile();
        if ($profile !== null) {
            // On utilise un nouveau ArtistProfile sans discipline plutôt que de
            // manipuler la collection de l'existant — plus propre et plus lisible.
            $this->setPrivateProperty($profile, 'disciplines', new \Doctrine\Common\Collections\ArrayCollection());
        }

        $this->assertFalse(
            $this->checker->isComplete($user),
            "Sans discipline, isComplete() doit retourner false"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CRITÈRE 5 : lookingFor non vide (sur User)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * lookingFor = null → incomplet.
     * Cas : utilisateur créé avant la mise en place du champ, ou step 3 non complétée.
     */
    public function testIsComplete_LookingForNull_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        $user->setLookingFor(null);

        $this->assertFalse(
            $this->checker->isComplete($user),
            "lookingFor null doit rendre le profil incomplet"
        );
    }

    /**
     * lookingFor = tableau vide [] → incomplet.
     * Cas : tableau soumis mais sans sélection.
     */
    public function testIsComplete_LookingForTableauVide_RetourneFalse(): void
    {
        $user = $this->makeCompleteUser();
        $user->setLookingFor([]);

        $this->assertFalse(
            $this->checker->isComplete($user),
            "lookingFor tableau vide doit rendre le profil incomplet"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TESTS DE getMissingFields()
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * getMissingFields() sur profil complet → tableau vide.
     */
    public function testGetMissingFields_ProfilComplet_RetourneTableauVide(): void
    {
        $user = $this->makeCompleteUser();

        $missing = $this->checker->getMissingFields($user);

        $this->assertSame(
            [],
            $missing,
            "getMissingFields() doit retourner [] pour un profil complet"
        );
    }

    /**
     * getMissingFields() sans ArtistProfile → retourne ['artist_profile'] uniquement.
     * Les sous-critères ne sont pas listés (on ne peut pas les vérifier sans profil).
     */
    public function testGetMissingFields_SansArtistProfile_RetourneArtistProfile(): void
    {
        $user = new User();
        $this->setPrivateProperty($user, 'email', 'test@test.fr');
        $this->setPrivateProperty($user, 'roles', ['ROLE_ARTIST']);
        $user->setLookingFor(['formations']);

        $missing = $this->checker->getMissingFields($user);

        $this->assertSame(['artist_profile'], $missing,
            "Sans ArtistProfile, seul 'artist_profile' doit être signalé (pas les sous-critères)"
        );
    }

    /**
     * getMissingFields() avec plusieurs critères manquants → tous listés.
     * Ici : location manquante + lookingFor vide → 2 champs signalés.
     */
    public function testGetMissingFields_PlusieursCritèresManquants_TousListés(): void
    {
        $user = $this->makeCompleteUser();
        $user->getArtistProfile()?->setLocation(null);
        $user->setLookingFor(null);

        $missing = $this->checker->getMissingFields($user);

        $this->assertContains('location', $missing,
            "'location' doit apparaître dans les champs manquants"
        );
        $this->assertContains('looking_for', $missing,
            "'looking_for' doit apparaître dans les champs manquants"
        );
        // display_name et disciplines ne sont pas manquants
        $this->assertNotContains('display_name', $missing);
        $this->assertNotContains('disciplines', $missing);
        $this->assertCount(2, $missing,
            "Exactement 2 champs manquants doivent être signalés"
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS — Constructeurs d'entités en mémoire
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Construit un utilisateur avec un profil artiste COMPLET (tous critères satisfaits).
     *
     * Ce profil est le "profil de référence" : on part de lui et on retire
     * un critère à la fois dans chaque test pour vérifier qu'isComplete() = false.
     */
    private function makeCompleteUser(): User
    {
        // ── Utilisateur de base ──────────────────────────────────────────────
        $user = new User();
        $this->setPrivateProperty($user, 'email', 'artiste@bazaart.fr');
        $this->setPrivateProperty($user, 'roles', ['ROLE_ARTIST']);

        // Critère 5 : lookingFor non vide
        $user->setLookingFor(['formations', 'residences']);

        // ── Profil artiste complet ───────────────────────────────────────────
        $profile = new ArtistProfile();
        $profile->setUser($user);

        // Critère 2 : displayName non vide
        $profile->setDisplayName('Marie Dupont');

        // Critère 3 : location non vide (NOUVEAU Option B)
        $profile->setLocation('Paris, France');

        // Critère 4 : au moins 1 discipline
        $discipline = new Discipline();
        $discipline->setName('Musique');
        $this->setPrivateProperty($discipline, 'id', 1);
        $profile->addDiscipline($discipline);

        // Timestamps obligatoires (colonnes NOT NULL en BDD)
        $this->setPrivateProperty($profile, 'createdAt', new \DateTime());
        $this->setPrivateProperty($profile, 'updatedAt', new \DateTime());

        // Critère 1 : attache le profil à l'utilisateur
        $this->setPrivateProperty($user, 'artistProfile', $profile);

        return $user;
    }

    /**
     * Helper générique pour injecter une valeur dans une propriété privée via ReflectionClass.
     *
     * Indispensable pour les entités Doctrine qui n'ont pas de setter sur toutes
     * leurs propriétés ($id, $createdAt, $artistProfile sur User, etc.).
     * Copié depuis MatchingServiceTest pour cohérence entre les tests unitaires.
     */
    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $ref = new \ReflectionClass($object);

        // Cherche la propriété dans la classe ET ses classes parentes (héritage)
        while (!$ref->hasProperty($propertyName) && $ref->getParentClass() !== false) {
            $ref = $ref->getParentClass();
        }

        if (!$ref->hasProperty($propertyName)) {
            throw new \InvalidArgumentException(sprintf(
                "La propriété '%s' n'existe pas dans %s ni ses parents.",
                $propertyName,
                get_class($object)
            ));
        }

        $prop = $ref->getProperty($propertyName);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
