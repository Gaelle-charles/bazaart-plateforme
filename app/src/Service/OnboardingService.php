<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Onboarding\OnboardingStep2DTO;
use App\DTO\Onboarding\OnboardingStep3DTO;
use App\DTO\Onboarding\OnboardingStep4DTO;
use App\Entity\ArtistProfile;
use App\Entity\ResourceAlert;
use App\Entity\User;
use App\Enum\ArtistLookingFor;
use App\Repository\DisciplineRepository;
use App\Repository\ResourceAlertRepository;
use App\Repository\ResourceTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * OnboardingService — Logique métier du parcours d'onboarding artiste.
 *
 * Ce service centralise toutes les opérations du parcours en 4 étapes :
 *   - Étape 1 : aiguillage artiste/structure (pas de traitement ici, juste navigation)
 *   - Étape 2 : création / mise à jour du profil ArtistProfile
 *   - Étape 3 : enregistrement de lookingFor + lookingForOther sur User
 *   - Étape 4 : création / mise à jour du ResourceAlert + finalisation
 *
 * Convention de code :
 *   - Toute la logique Doctrine est ici, jamais dans les controllers.
 *   - Les controllers appellent les méthodes de ce service et se contentent
 *     de rediriger ou de rendre un template selon le résultat.
 *   - Les erreurs de validation retournent une string (message d'erreur),
 *     les succès retournent true ou l'entité créée.
 */
final class OnboardingService
{
    /** Adresse expéditrice des emails transactionnels */
    private const FROM_EMAIL = 'noreply@bazaart.fr';
    private const FROM_NAME  = 'Bazaart';

    /**
     * Mapping : valeurs ArtistLookingFor → noms de ResourceType à pré-cocher.
     *
     * Ce mapping est utilisé à l'étape 4 pour pré-cocher les types de ressources
     * en fonction de ce que l'artiste a sélectionné à l'étape 3.
     * Les noms doivent correspondre exactement aux noms créés dans les fixtures.
     *
     * RESSOURCES_AIDES  → aides financières, donc "Bourse & Financement"
     * RESSOURCES_APPELS → opportunités de visibilité : appels, résidences, prix
     * FORMATIONS        → "Formation" (catégorie dédiée dans la Ressourcerie)
     * AUTRE             → pas de pré-sélection automatique
     */
    private const LOOKING_FOR_TO_RESOURCE_TYPES = [
        ArtistLookingFor::RESSOURCES_AIDES->value  => ['Bourse & Financement'],
        ArtistLookingFor::RESSOURCES_APPELS->value => [
            'Appel a projets',
            'Appel à projets',   // avec accent, pour robustesse
            'Résidence artistique',
            'Prix & Concours',
        ],
        ArtistLookingFor::FORMATIONS->value => ['Formation'],
        ArtistLookingFor::AUTRE->value      => [], // pas de mapping automatique
    ];

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly DisciplineRepository    $disciplineRepository,
        private readonly ResourceTypeRepository  $resourceTypeRepository,
        private readonly ResourceAlertRepository $resourceAlertRepository,
        private readonly MailerInterface         $mailer,
        private readonly LoggerInterface         $logger,
        // A4 : Router injecté pour générer l'URL absolue du dashboard dans l'email de bienvenue.
        // On évite le hardcode 'https://bazaart.fr/dashboard' qui casse en dev/staging.
        // Symfony autowire UrlGeneratorInterface vers le routeur par défaut.
        private readonly UrlGeneratorInterface   $router,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // ÉTAPE 2 — Profil artiste
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Crée ou met à jour le profil ArtistProfile à partir du DTO de l'étape 2.
     *
     * Si l'utilisateur n'a pas encore de profil artiste, on en crée un nouveau.
     * Si il en a déjà un (retour en arrière dans l'onboarding), on met à jour.
     *
     * @return string|null Null si succès, message d'erreur si échec validation
     */
    public function saveStep2(User $user, OnboardingStep2DTO $dto): ?string
    {
        // ── Validation manuelle du champ obligatoire ──────────────────────────
        // (Le Validator symfony ne valide pas les DTOs automatiquement ici ;
        //  on le fait manuellement pour rester cohérent avec le reste du projet
        //  qui utilise des messages d'erreur inline.)
        if (trim($dto->displayName) === '') {
            return 'Le nom d\'affichage est obligatoire.';
        }

        // A3 : Validation de la longueur max du displayName.
        // Sans cette vérification, un POST direct avec une valeur > 100 caractères
        // déclencherait une exception Doctrine (colonne VARCHAR(100) en BDD).
        // mb_strlen() est utilisé plutôt que strlen() pour compter les caractères Unicode
        // correctement (les accents, caractères arabes, etc. comptent chacun pour 1).
        if (mb_strlen($dto->displayName) > 100) {
            return 'Le nom d\'affichage ne peut pas dépasser 100 caractères.';
        }

        if (empty($dto->disciplineIds)) {
            return 'Choisis au moins une discipline artistique.';
        }

        // ── Récupérer ou créer le profil artiste ─────────────────────────────
        $profile = $user->getArtistProfile();

        if ($profile === null) {
            // Premier passage à l'étape 2 : création d'un nouveau profil
            $profile = new ArtistProfile();
            // setUser() sur ArtistProfile et setArtistProfile() sur User
            // synchronisent les deux côtés de la relation OneToOne.
            $user->setArtistProfile($profile);
        }

        // ── Remplir les champs du profil ─────────────────────────────────────
        $profile->setDisplayName($dto->displayName);
        $profile->setLocation($dto->location);
        $profile->setBio($dto->bio);
        $profile->setPortfolioUrl($dto->portfolioUrl);
        $profile->setWebsiteUrl($dto->websiteUrl);

        // Construction du tableau socialLinks depuis les champs individuels.
        // On ne stocke que les réseaux renseignés (pas de clé vide en JSON).
        $socialLinks = [];
        if ($dto->instagram !== null && $dto->instagram !== '') {
            // On préfixe l'URL Instagram si l'utilisateur n'a entré que le handle
            $handle = ltrim($dto->instagram, '@');
            $socialLinks['instagram'] = 'https://instagram.com/' . $handle;
        }
        $profile->setSocialLinks(!empty($socialLinks) ? $socialLinks : null);

        // ── Disciplines — remplace les disciplines existantes ─────────────────
        //
        // On retire d'abord toutes les disciplines existantes (pour gérer
        // le cas où l'utilisateur revient à l'étape 2 et change ses choix).
        // Puis on ré-attache les nouvelles.
        foreach ($profile->getDisciplines()->toArray() as $existing) {
            $profile->removeDiscipline($existing);
        }

        foreach ($dto->disciplineIds as $disciplineId) {
            $discipline = $this->disciplineRepository->find($disciplineId);
            if ($discipline !== null) {
                $profile->addDiscipline($discipline);
            }
        }

        // ── Ajout du rôle ROLE_ARTIST ─────────────────────────────────────────
        //
        // L'artiste qui complète l'étape 2 reçoit automatiquement ROLE_ARTIST.
        // On vérifie d'abord qu'il ne l'a pas déjà (pas de doublon dans le tableau).
        $roles = $user->getRoles();
        if (!in_array('ROLE_ARTIST', $roles, true)) {
            // getRoles() ajoute dynamiquement ROLE_USER, mais les roles stockés
            // en BDD ne contiennent que les rôles supplémentaires.
            // On ne stocke PAS ROLE_USER dans le tableau (il est ajouté automatiquement).
            $storedRoles = array_unique(array_filter(
                $roles,
                static fn (string $r) => $r !== 'ROLE_USER'
            ));
            $storedRoles[] = 'ROLE_ARTIST';
            $user->setRoles(array_values($storedRoles));
        }

        // ── Persistance ───────────────────────────────────────────────────────
        // persist($user) suffit car cascade: ['persist'] sur User::$artistProfile.
        // Le profil sera INSERT ou UPDATE selon qu'il était déjà managé.
        $this->em->persist($user);
        $this->em->flush();

        return null; // Succès
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ÉTAPE 3 — Que recherches-tu ?
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Enregistre les objectifs de l'artiste (lookingFor + lookingForOther).
     *
     * @return string|null Null si succès, message d'erreur si validation échoue
     */
    public function saveStep3(User $user, OnboardingStep3DTO $dto): ?string
    {
        // Validation : au moins 1 case cochée
        if (empty($dto->lookingForValues)) {
            return 'Choisis au moins une option pour continuer.';
        }

        // Validation croisée : si "autre" est coché, le champ texte est obligatoire
        if (in_array(ArtistLookingFor::AUTRE->value, $dto->lookingForValues, true)
            && ($dto->lookingForOther === null || trim($dto->lookingForOther) === '')
        ) {
            return 'Précise ce que tu recherches d\'autre dans le champ texte.';
        }

        // Validation des valeurs : on vérifie que chaque valeur correspond à l'enum
        $validValues = array_column(ArtistLookingFor::cases(), 'value');
        foreach ($dto->lookingForValues as $val) {
            if (!in_array($val, $validValues, true)) {
                return 'Une des options sélectionnées est invalide.';
            }
        }

        // Stockage sur l'entité User
        $user->setLookingFor($dto->lookingForValues);
        $user->setLookingForOther(
            // On ne stocke lookingForOther que si "autre" est coché
            in_array(ArtistLookingFor::AUTRE->value, $dto->lookingForValues, true)
                ? $dto->lookingForOther
                : null
        );

        $this->em->persist($user);
        $this->em->flush();

        return null; // Succès
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ÉTAPE 4 — Alertes ressources
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Crée ou met à jour le profil d'alertes (ResourceAlert) de l'utilisateur.
     *
     * Appelé à l'étape 4. Après succès :
     *   - ResourceAlert persisté en base
     *   - User::onboardingCompleted = true
     *   - Email de bienvenue envoyé
     *
     * L'étape 4 ne fait pas de validation bloquante (les filtres sont tous optionnels,
     * la fréquence a un fallback Daily si invalide). Elle retourne toujours null.
     * Signature cohérente avec saveStep2() et saveStep3() pour le controller.
     */
    public function saveStep4AndComplete(User $user, OnboardingStep4DTO $dto): null
    {
        // ── Récupérer ou créer le ResourceAlert ───────────────────────────────
        $alert = $this->resourceAlertRepository->findOneBy(['user' => $user]);

        if ($alert === null) {
            $alert = new ResourceAlert();
            $alert->setUser($user);
        }

        // ── Configurer les préférences d'alerte ──────────────────────────────
        $alert->setFrequency($dto->getFrequencyEnum());
        $alert->setNotifyOnNewResource(true); // Toujours actif depuis l'onboarding

        // ── Disciplines filtrées ──────────────────────────────────────────────
        //
        // On vide d'abord les disciplines existantes (au cas où l'utilisateur
        // revient à l'étape 4), puis on ré-attache celles du formulaire.
        foreach ($alert->getFilterDisciplines()->toArray() as $existing) {
            $alert->removeFilterDiscipline($existing);
        }

        foreach ($dto->disciplineIds as $disciplineId) {
            $discipline = $this->disciplineRepository->find($disciplineId);
            if ($discipline !== null) {
                $alert->addFilterDiscipline($discipline);
            }
        }

        // ── Types de ressources filtrés ───────────────────────────────────────
        foreach ($alert->getFilterResourceTypes()->toArray() as $existing) {
            $alert->removeFilterResourceType($existing);
        }

        foreach ($dto->resourceTypeIds as $typeId) {
            $type = $this->resourceTypeRepository->find($typeId);
            if ($type !== null) {
                $alert->addFilterResourceType($type);
            }
        }

        // ── Finalisation de l'onboarding ─────────────────────────────────────
        $user->setOnboardingCompleted(true);

        // On persiste explicitement le ResourceAlert (pas de cascade depuis User)
        $this->em->persist($alert);
        $this->em->persist($user);
        $this->em->flush();

        // ── Email de bienvenue ────────────────────────────────────────────────
        $this->sendWelcomeEmail($user);

        return null; // Succès
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRÉ-SÉLECTION DES TYPES DE RESSOURCES (pour l'affichage de l'étape 4)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Calcule les IDs de ResourceType à pré-cocher à l'étape 4,
     * en fonction des réponses de l'étape 3.
     *
     * Logique de mapping définie dans la constante LOOKING_FOR_TO_RESOURCE_TYPES.
     * On cherche en base les types par leur nom (insensible à la casse pour robustesse).
     *
     * @return list<int> IDs des ResourceType à pré-cocher
     */
    public function getPreselectedResourceTypeIds(User $user): array
    {
        $lookingFor = $user->getLookingFor();

        // Si l'utilisateur n'a pas encore rempli l'étape 3 → pas de pré-sélection
        if ($lookingFor === null || empty($lookingFor)) {
            return [];
        }

        // Collect les noms des types à pré-cocher selon le mapping
        $typeNamesToPreselect = [];
        foreach ($lookingFor as $value) {
            $names = self::LOOKING_FOR_TO_RESOURCE_TYPES[$value] ?? [];
            foreach ($names as $name) {
                $typeNamesToPreselect[] = $name;
            }
        }

        if (empty($typeNamesToPreselect)) {
            return [];
        }

        // Charger tous les ResourceType en base (petit nombre, pas de N+1 ici)
        $allTypes = $this->resourceTypeRepository->findAll();

        $preselectedIds = [];
        foreach ($allTypes as $type) {
            // Comparaison normalisée (minuscules, sans accents potentiels)
            // On teste si le nom du type correspond à l'un des noms à pré-cocher
            foreach ($typeNamesToPreselect as $targetName) {
                if (mb_strtolower($type->getName()) === mb_strtolower($targetName)) {
                    $preselectedIds[] = $type->getId();
                    break;
                }
            }
        }

        return array_values(array_unique($preselectedIds));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // EMAIL DE BIENVENUE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Envoie l'email de bienvenue nominatif à l'issue de l'onboarding.
     *
     * L'email est brandé Bazaart et utilise le displayName de l'artiste.
     * En cas d'erreur SMTP, on logge sans propager l'exception
     * (l'onboarding est déjà marqué comme complété — pas de rollback pour un email).
     */
    private function sendWelcomeEmail(User $user): void
    {
        // Récupère le nom d'affichage — fallback sur l'email si pas de profil artiste
        $displayName = $user->getArtistProfile()?->getDisplayName()
            ?? explode('@', $user->getEmail())[0];

        try {
            // A4 : Génération de l'URL absolue du dashboard.
            // ABSOLUTE_URL produit 'https://bazaart.fr/dashboard' en prod,
            // 'http://localhost:8080/dashboard' en dev — selon APP_URL / trusted_hosts.
            // On ne hardcode plus l'URL en dur pour éviter de casser en dev et staging.
            $dashboardUrl = $this->router->generate(
                'app_dashboard',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $email = (new TemplatedEmail())
                ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
                ->to($user->getEmail())
                ->subject('[Bazaart] Bienvenue dans la communaute !')
                ->htmlTemplate('emails/welcome_onboarding.html.twig')
                ->textTemplate('emails/welcome_onboarding.txt.twig')
                ->context([
                    'user'         => $user,
                    'displayName'  => $displayName,
                    // A4 : URL générée dynamiquement (plus de hardcode)
                    'dashboardUrl' => $dashboardUrl,
                ]);

            $this->mailer->send($email);

            $this->logger->info(
                sprintf('Email de bienvenue onboarding envoye a %s', $user->getEmail()),
                ['user_id' => $user->getId()]
            );

        } catch (\Throwable $e) {
            // On attrape toute erreur (SMTP, rendu Twig) pour ne pas faire échouer
            // l'onboarding si l'envoi de l'email échoue.
            $this->logger->error(
                sprintf('Echec envoi email bienvenue onboarding a %s : %s', $user->getEmail(), $e->getMessage()),
                ['user_id' => $user->getId(), 'exception' => $e]
            );
        }
    }
}
