<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Onboarding\OnboardingStep2DTO;
use App\DTO\Onboarding\OnboardingStep3DTO;
use App\DTO\Onboarding\OnboardingStep4MatchingDTO;
use App\Entity\ArtistProfile;
use App\Entity\User;
use App\Enum\ArtistLookingFor;
use App\Repository\DisciplineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * OnboardingService — Logique métier du parcours d'onboarding artiste (reformulé Lot A matching).
 *
 * Ce service centralise toutes les opérations du parcours en 4 étapes :
 *   - Étape 1 : aiguillage artiste/structure (pas de traitement ici, juste navigation)
 *   - Étape 2 : création / mise à jour du profil ArtistProfile (nom + discipline + localisation)
 *   - Étape 3 : enregistrement de lookingFor + lookingForOther sur User ("que recherches-tu ?")
 *   - Étape 4 : statut juridique + finalisation (marque l'onboarding complété)
 *
 * Changements Lot A (ADR-0021/0022) :
 *   - L'ancienne étape 4 "alertes" est SUPPRIMÉE de l'onboarding.
 *     La configuration des alertes deviendra un opt-in avec consentement dans le
 *     module matching (Lot C). On ne crée plus de ResourceAlert automatiquement.
 *   - La nouvelle étape 4 collecte le statut juridique (LegalStatus) pour le matching.
 *   - L'email de bienvenue est envoyé à la fin de la nouvelle étape 4.
 *
 * Convention de code :
 *   - Toute la logique Doctrine est ici, jamais dans les controllers.
 *   - Les controllers appellent les méthodes de ce service et se contentent
 *     de rediriger ou de rendre un template selon le résultat.
 *   - Les erreurs de validation retournent une string (message d'erreur),
 *     les succès retournent null.
 */
final class OnboardingService
{
    /** Adresse expéditrice des emails transactionnels */
    private const FROM_EMAIL = 'noreply@bazaart.fr';
    private const FROM_NAME  = 'Bazaart';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisciplineRepository   $disciplineRepository,
        private readonly MailerInterface        $mailer,
        private readonly LoggerInterface        $logger,
        // Router injecté pour générer l'URL absolue du dashboard dans l'email de bienvenue.
        // On évite le hardcode 'https://bazaart.fr/dashboard' qui casse en dev/staging.
        private readonly UrlGeneratorInterface  $router,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // ÉTAPE 2 — Profil artiste (nom, discipline, localisation)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Crée ou met à jour le profil ArtistProfile à partir du DTO de l'étape 2.
     *
     * Si l'utilisateur n'a pas encore de profil artiste, on en crée un nouveau.
     * Si il en a déjà un (retour en arrière dans l'onboarding), on met à jour.
     *
     * Champs obligatoires pour le matching : displayName + au moins une discipline.
     * La localisation est collectée ici mais reste optionnelle.
     *
     * @return string|null Null si succès, message d'erreur si échec validation
     */
    public function saveStep2(User $user, OnboardingStep2DTO $dto): ?string
    {
        // ── Validation manuelle des champs obligatoires ───────────────────────
        // (Le Validator Symfony ne valide pas les DTOs automatiquement ici ;
        //  on le fait manuellement pour rester cohérent avec le reste du projet
        //  qui utilise des messages d'erreur inline plutôt que des flash bags.)

        if (trim($dto->displayName) === '') {
            return 'Le nom d\'affichage est obligatoire.';
        }

        // mb_strlen() pour compter correctement les caractères Unicode
        // (accents, caractères arabes, etc. comptent chacun pour 1 caractère)
        if (mb_strlen($dto->displayName) > 100) {
            return 'Le nom d\'affichage ne peut pas dépasser 100 caractères.';
        }

        // Au moins une discipline est obligatoire pour le matching (Lot A)
        if (empty($dto->disciplineIds)) {
            return 'Choisis au moins une discipline artistique.';
        }

        // ── Récupérer ou créer le profil artiste ─────────────────────────────
        $profile = $user->getArtistProfile();

        if ($profile === null) {
            // Premier passage à l'étape 2 : création d'un nouveau profil
            $profile = new ArtistProfile();
            // setUser() synchronise les deux côtés de la relation OneToOne.
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
        //
        // getRoles() ajoute dynamiquement ROLE_USER, mais les roles stockés
        // en BDD ne contiennent que les rôles supplémentaires.
        // On ne stocke PAS ROLE_USER dans le tableau (il est ajouté automatiquement).
        $roles = $user->getRoles();
        if (!in_array('ROLE_ARTIST', $roles, true)) {
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
     * Enregistre les objectifs de l'artiste (lookingFor + lookingForOther) sur User.
     *
     * Ces données alimentent le moteur de matching (Lot C) pour personnaliser
     * les suggestions d'opportunités selon les besoins déclarés.
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

        // Validation des valeurs : chaque valeur doit correspondre à l'enum
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
    // ÉTAPE 4 — Statut juridique + finalisation (Lot A matching)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Enregistre le statut juridique de l'artiste et finalise l'onboarding.
     *
     * Appelé à l'étape 4 (nouvelle). Après succès :
     *   - ArtistProfile::legalStatus mis à jour (nullable, l'artiste peut passer)
     *   - User::onboardingCompleted = true
     *   - Email de bienvenue envoyé
     *
     * NOTE : L'ancienne étape 4 "alertes" (ResourceAlert) est supprimée de l'onboarding.
     * La configuration des alertes deviendra un opt-in explicite avec consentement
     * dans le module matching (Lot C). On ne crée donc plus de ResourceAlert ici.
     *
     * Le statut juridique est optionnel (l'artiste peut finaliser sans le renseigner).
     * Cette méthode retourne toujours null (pas de validation bloquante).
     *
     * @return null Toujours null pour cohérence de signature avec saveStep2/saveStep3
     */
    public function saveStep4AndComplete(User $user, OnboardingStep4MatchingDTO $dto): null
    {
        // ── Mettre à jour le statut juridique sur le profil artiste ──────────
        //
        // Le profil doit exister (l'étape 2 l'a créé) — on vérifie par sécurité.
        // Si le profil est null (cas improbable : saut d'étape par URL directe),
        // on finalise quand même l'onboarding sans planter.
        $profile = $user->getArtistProfile();

        if ($profile !== null) {
            // getLegalStatusEnum() retourne null si non renseigné ou valeur invalide
            // → null est une valeur valide pour ce champ (optionnel)
            $profile->setLegalStatus($dto->getLegalStatusEnum());
            $this->em->persist($profile);
        }

        // ── Finalisation de l'onboarding ─────────────────────────────────────
        //
        // On marque l'onboarding comme complété. Le gating (OnboardingGatingListener)
        // est désactivé en Lot A, mais ce flag reste utile pour :
        //   - La bannière "complète ton profil" dans le dashboard
        //   - Le module matching (Lot C) qui vérifie si le profil est complet
        //
        // On capture l'état AVANT de marquer l'onboarding complété : depuis le
        // nouveau parcours matching (ADR-0026), cette méthode est appelée à chaque
        // soumission du formulaire de matching par un utilisateur connecté. Sans
        // cette garde, un re-remplissage renverrait un email de bienvenue à chaque
        // fois. On ne l'envoie donc qu'au PREMIER achèvement de l'onboarding.
        $wasAlreadyCompleted = $user->isOnboardingCompleted();

        $user->setOnboardingCompleted(true);

        $this->em->persist($user);
        $this->em->flush();

        // ── Email de bienvenue (uniquement au premier achèvement) ──────────────
        // Envoyé APRÈS le flush pour garantir que l'onboarding est bien persisté
        // même si l'email échoue. La garde évite les emails en double sur
        // re-soumission du formulaire de matching (cf. ADR-0026).
        if (!$wasAlreadyCompleted) {
            $this->sendWelcomeEmail($user);
        }

        return null; // Succès (signature cohérente avec les autres méthodes)
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
        // Récupère le nom d'affichage — fallback sur la partie locale de l'email si pas de profil
        $displayName = $user->getArtistProfile()?->getDisplayName()
            ?? explode('@', $user->getEmail())[0];

        try {
            // Génération de l'URL absolue du dashboard.
            // ABSOLUTE_URL produit 'https://bazaart.fr/dashboard' en prod,
            // 'http://localhost:8080/dashboard' en dev.
            $dashboardUrl = $this->router->generate(
                'app_dashboard',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $email = (new TemplatedEmail())
                ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
                ->to($user->getEmail())
                ->subject('[Bazaart] Bienvenue dans la communauté !')
                ->htmlTemplate('emails/welcome_onboarding.html.twig')
                ->textTemplate('emails/welcome_onboarding.txt.twig')
                ->context([
                    'user'         => $user,
                    'displayName'  => $displayName,
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
            // L'utilisateur peut toujours accéder au site — ce n'est pas bloquant.
            $this->logger->error(
                sprintf('Echec envoi email bienvenue onboarding a %s : %s', $user->getEmail(), $e->getMessage()),
                ['user_id' => $user->getId(), 'exception' => $e]
            );
        }
    }
}
