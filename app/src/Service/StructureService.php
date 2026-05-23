<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrganizationProfile;
use App\Entity\User;
use App\Repository\OrganizationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * StructureService — logique métier du workflow Compte Structure.
 *
 * Ce service gère les trois transitions du cycle de vie d'une candidature :
 *   1. applyAsStructure()         → L'org soumet sa candidature
 *   2. activateStructure()        → L'admin accepte (active le compte)
 *   3. rejectStructureApplication() → L'admin refuse (annule la candidature)
 *
 * PRINCIPE : aucune logique métier dans les controllers — tout ici.
 * Les controllers ne font qu'appeler les méthodes de ce service
 * et rediriger / afficher les messages flash.
 */
class StructureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationProfileRepository $orgRepository,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // 1. SOUMISSION DE CANDIDATURE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enregistre la candidature d'un utilisateur au statut Structure.
     *
     * Ce que fait cette méthode :
     *   - Récupère le profil organisation existant de l'utilisateur, ou en crée un nouveau.
     *   - Valide les champs obligatoires : name, contactEmail (format), siret (si fourni).
     *   - Renseigne structureApplicationAt = now() si l'org n'a pas déjà candidaté.
     *   - Sauvegarde le profil en base.
     *
     * Ce qu'elle ne fait PAS :
     *   - Ne modifie pas isStructurePartner (reste false jusqu'à validation admin).
     *   - Ne modifie pas les rôles du User.
     *   - N'envoie pas d'email (à ajouter via MailerInterface dans une prochaine itération).
     *
     * @param User  $user L'utilisateur connecté qui soumet la candidature
     * @param array<string, string> $data Données du formulaire (name, contactEmail, siret, description, websiteUrl, location)
     * @return OrganizationProfile|string Le profil sauvegardé, ou un message d'erreur si validation échoue
     */
    public function applyAsStructure(User $user, array $data): OrganizationProfile|string
    {
        // ── Guard : déjà une structure active ────────────────────────────────
        // Sécurité défensive : même si le controller effectue déjà ce guard en GET,
        // un utilisateur pourrait soumettre directement un POST à cette route.
        // On rejette ici pour éviter de modifier le profil d'une structure déjà validée.
        $existingProfile = $this->orgRepository->findByUser($user);
        if ($existingProfile !== null && $existingProfile->isStructurePartner()) {
            return 'Votre compte est déjà une structure partenaire active.';
        }

        // ── Validation du nom (obligatoire) ───────────────────────────────────
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            // On retourne une string d'erreur que le controller affichera en flash
            return 'Le nom de la structure est obligatoire.';
        }

        // ── Validation de l'email de contact (obligatoire) ───────────────────
        $contactEmail = trim($data['contactEmail'] ?? '');
        if ($contactEmail === '') {
            return 'L\'adresse email de contact est obligatoire.';
        }
        // filter_var() est la méthode PHP native pour valider les emails (RFC 5321)
        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return 'L\'adresse email de contact n\'est pas valide.';
        }

        // ── Validation du SIRET (optionnel mais si renseigné, 14 chiffres) ────
        $siret = trim($data['siret'] ?? '');
        if ($siret !== '') {
            // On nettoie d'abord les espaces et tirets (saisie humaine type "123 456 789 01234")
            $siretClean = preg_replace('/\D/', '', $siret);
            // Même regex que OrganizationProfileService::isValidSiret()
            if (strlen($siretClean) !== 14) {
                return 'Le numéro SIRET doit contenir exactement 14 chiffres.';
            }
        }

        // ── Récupère ou crée le profil organisation ───────────────────────────
        // Un utilisateur peut avoir rempli son profil organisation avant de candidater.
        // On réutilise le profil existant (déjà chargé via $existingProfile en tête de méthode).
        $profile = $existingProfile ?? new OrganizationProfile();

        // Si le profil est nouveau (pas encore en BDD), on lui associe l'utilisateur
        if ($profile->getId() === null) {
            $profile->setUser($user);
        }

        // ── Mise à jour des champs du profil ──────────────────────────────────
        $profile->setName($name);
        $profile->setContactEmail($contactEmail);

        // SIRET : on le nettoie (sans espaces/tirets) ou on met null si vide
        $profile->setSiret($siret !== '' ? $siret : null);

        // Champs optionnels — on normalise les chaînes vides en null pour la BDD
        $description = trim($data['description'] ?? '');
        $profile->setDescription($description !== '' ? $description : null);

        $websiteUrl = trim($data['websiteUrl'] ?? '');
        $profile->setWebsiteUrl($websiteUrl !== '' ? $websiteUrl : null);

        $location = trim($data['location'] ?? '');
        $profile->setLocation($location !== '' ? $location : null);

        // ── Enregistre la date de candidature (si pas déjà candidaté) ─────────
        // On ne réinitialise pas la date si l'org avait déjà candidaté et candidaté
        // à nouveau (mise à jour du profil en attente). Cela conserve la date
        // initiale de la demande — important pour le tri FIFO côté admin.
        if ($profile->getStructureApplicationAt() === null) {
            $profile->setStructureApplicationAt(new \DateTime());
        }

        // Persist + flush — Doctrine gère automatiquement INSERT ou UPDATE
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. ACTIVATION PAR L'ADMIN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Active un compte Structure après validation par un admin.
     *
     * Ce que fait cette méthode :
     *   - isStructurePartner → true
     *   - structureActivatedAt → maintenant
     *   - structureActivationValidatedBy → l'admin qui valide
     *   - Ajoute ROLE_STRUCTURE aux rôles du User (sans écraser les rôles existants)
     *   - Persist + flush
     *
     * Pourquoi on persiste le User séparément ?
     * La relation User → OrganizationProfile a cascade:['persist'] côté User,
     * mais pas l'inverse. On doit donc persister les deux explicitement.
     * Doctrine les regroupe dans une seule transaction (un seul flush).
     *
     * @param OrganizationProfile $orgProfile Le profil à activer
     * @param User                $admin      L'admin connecté qui valide la demande
     */
    public function activateStructure(OrganizationProfile $orgProfile, User $admin): void
    {
        // ── Mise à jour du profil organisation ────────────────────────────────
        $orgProfile->setIsStructurePartner(true);
        $orgProfile->setStructureActivatedAt(new \DateTime());
        $orgProfile->setStructureActivationValidatedBy($admin);
        // Note : on ne remet pas structureApplicationAt à null — on conserve l'historique

        // ── Mise à jour des rôles de l'utilisateur ────────────────────────────
        $user = $orgProfile->getUser();

        // getRoles() retourne les rôles bruts + ROLE_USER (ajouté automatiquement)
        $roles = $user->getRoles();

        // On retire ROLE_USER car il est ajouté dynamiquement par getRoles() et ne
        // doit pas être stocké en doublon dans le champ JSON "roles" en BDD.
        // Autrement on aurait ["ROLE_USER", "ROLE_STRUCTURE", "ROLE_USER"] etc.
        $roles = array_filter($roles, fn (string $r) => $r !== 'ROLE_USER');

        // Ajout de ROLE_STRUCTURE avec déduplification
        $roles[] = 'ROLE_STRUCTURE';
        $user->setRoles(array_values(array_unique($roles)));

        // ── Sauvegarde en base ────────────────────────────────────────────────
        // Un seul flush = une seule transaction SQL : atomique et cohérent.
        $this->em->persist($orgProfile);
        $this->em->persist($user);
        $this->em->flush();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. REJET PAR L'ADMIN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Rejette une candidature Structure (appelé par un admin).
     *
     * Ce que fait cette méthode :
     *   - structureApplicationAt → null (annule la candidature)
     *   - isStructurePartner reste false (n'a jamais été true dans ce cas)
     *   - Les rôles du User ne changent pas (il n'a pas ROLE_STRUCTURE)
     *   - Persist + flush
     *
     * Pourquoi remettre structureApplicationAt à null ?
     * C'est notre signal de "pas candidaté". En le remettant à null,
     * on permet à l'organisation de re-candidater plus tard (après correction
     * de son dossier par exemple). Si on laissait la date, l'org resterait
     * bloquée dans la liste "en attente" pour l'admin même après le rejet.
     *
     * @param OrganizationProfile $orgProfile Le profil dont la candidature est rejetée
     */
    public function rejectStructureApplication(OrganizationProfile $orgProfile): void
    {
        // Annulation de la candidature : l'org peut re-candidater
        $orgProfile->setStructureApplicationAt(null);

        // isStructurePartner reste false — on s'assure juste de la cohérence
        // (cas où un admin activerait et désactiverait rapidement : ce n'est
        // pas prévu mais on reste défensif)
        $orgProfile->setIsStructurePartner(false);

        $this->em->persist($orgProfile);
        $this->em->flush();
    }
}
