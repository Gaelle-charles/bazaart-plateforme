<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Creator\PayoutProfileDTO;
use App\Entity\CreatorPayoutProfile;
use App\Entity\User;
use App\Enum\CreatorVerificationStatus;
use App\Repository\CreatorPayoutProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * CreatorPayoutService — logique métier du profil de versement créateur (ADR-0027, Lot 1).
 *
 * Responsabilités :
 *   - Créer ou mettre à jour le profil bancaire d'un créateur.
 *   - Gérer le stockage sécurisé de la pièce d'identité (délégué à CreatorDocumentStorage).
 *   - Repasser le statut à Pending à chaque modification (re-vérification admin).
 *   - Décisions admin : valider ou refuser avec notification email.
 *
 * Le contrôleur (CreatorPayoutController) ne contient aucune logique métier —
 * il orchestre uniquement la requête HTTP → ce service → la réponse HTTP.
 *
 * NOTE TECHNIQUE : symfony/validator n'est pas installé dans ce projet.
 * La validation est faite manuellement dans validateDto() avec des checks PHP
 * (même approche que CourseProposalService, ForumService, etc. dans ce projet).
 */
class CreatorPayoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CreatorPayoutProfileRepository $profileRepository,
        private readonly CreatorDocumentStorage $documentStorage,
        private readonly MailerInterface $mailer,
        // Adresse de l'équipe Bazaart — injectée via le bind $adminEmail dans services.yaml
        private readonly string $adminEmail,
    ) {}

    /**
     * Crée ou met à jour le profil de versement d'un créateur.
     *
     * Règles métier :
     *   - Si un profil existe, on met à jour ses données.
     *   - Si un nouveau fichier est uploadé, l'ancien est supprimé (libération disque).
     *   - TOUTE modification repasse le statut à Pending (l'admin doit re-vérifier).
     *   - Un email est envoyé à l'équipe pour signaler la (re)soumission.
     *
     * @return string|CreatorPayoutProfile L'entité sauvegardée, ou un message d'erreur.
     */
    public function save(User $user, PayoutProfileDTO $dto): string|CreatorPayoutProfile
    {
        // ── Validation du DTO ──────────────────────────────────────────────────
        // Validation manuelle (symfony/validator non installé dans ce projet).
        // On suit le même pattern que CourseProposalService, ForumService, etc.
        $error = $this->validateDto($dto);
        if ($error !== null) {
            return $error;
        }

        // ── Validation du fichier (si fourni) ─────────────────────────────────
        // La validation du fichier n'est pas dans le DTO (voir commentaire dans PayoutProfileDTO).
        // On délègue au service de stockage qui lève des \InvalidArgumentException.
        if ($dto->identityDocument !== null && !$dto->identityDocument->isValid()) {
            return 'Erreur lors de l\'upload : le fichier reçu est invalide ou corrompu.';
        }

        // ── Chargement ou création du profil ──────────────────────────────────
        $profile = $this->profileRepository->findByUser($user);
        $isNew   = ($profile === null);

        if ($isNew) {
            $profile = new CreatorPayoutProfile();
            $profile->setUser($user);
        }

        // ── Mise à jour des coordonnées bancaires ─────────────────────────────
        // L'IBAN est normalisé : on retire les espaces pour un stockage cohérent.
        // Le validateur Assert\Iban accepte les espaces, mais on les supprime avant persistance.
        $profile->setIban(str_replace(' ', '', strtoupper(trim($dto->iban ?? ''))));
        $profile->setBic($dto->bic !== null ? strtoupper(trim($dto->bic)) : null);
        $profile->setSiret(trim($dto->siret ?? ''));
        $profile->setAccountHolderName(trim($dto->accountHolderName ?? ''));

        // ── Gestion de la pièce d'identité ────────────────────────────────────
        if ($dto->identityDocument !== null) {
            // Supprime l'ancien fichier avant d'uploader le nouveau (libère de l'espace disque)
            if ($profile->getIdentityDocumentPath() !== null) {
                $this->documentStorage->delete($profile->getIdentityDocumentPath());
            }

            try {
                // store() valide le type MIME et la taille, puis déplace le fichier
                // dans le dossier sécurisé (hors public/) et retourne le chemin relatif.
                $relativePath = $this->documentStorage->store($dto->identityDocument, $user);
                $profile->setIdentityDocumentPath($relativePath);
                $profile->setIdentityDocumentOriginalName(
                    $dto->identityDocument->getClientOriginalName()
                );
            } catch (\InvalidArgumentException $e) {
                // Type MIME ou taille non autorisé → message utilisateur lisible
                return 'Fichier refusé : ' . $e->getMessage();
            } catch (\RuntimeException $e) {
                // Erreur disque ou permissions → message générique (on ne révèle pas le chemin)
                return 'Une erreur est survenue lors de l\'enregistrement du fichier. Réessaie.';
            }
        }

        // ── Repasse le statut à Pending (re-vérification admin requise) ────────
        // Règle métier ADR-0027 : toute modification des données de versement
        // invalide la vérification précédente. L'admin doit re-contrôler le dossier.
        $profile->setStatus(CreatorVerificationStatus::Pending);
        // On efface le motif de refus précédent (il ne s'applique plus aux nouvelles données)
        $profile->setRejectionReason(null);
        // On efface la validation précédente (les nouvelles données n'ont pas encore été vérifiées)
        $profile->setVerifiedBy(null);
        $profile->setVerifiedAt(null);

        // ── Persistance ───────────────────────────────────────────────────────
        if ($isNew) {
            // persist() est nécessaire uniquement pour les nouveaux objets.
            // Pour les entités déjà gérées par Doctrine (chargées depuis la BDD),
            // flush() suffit (Doctrine détecte les changements automatiquement via UnitOfWork).
            $this->em->persist($profile);
        }
        $this->em->flush();

        // ── Notification email à l'équipe Bazaart ─────────────────────────────
        $this->notifyAdminNewSubmission($profile, $isNew);

        return $profile;
    }

    /**
     * Valide un profil de versement (décision admin).
     *
     * Après validation :
     *   - Statut → Verified
     *   - verifiedBy → l'admin
     *   - verifiedAt → maintenant
     *   - rejectionReason → null (annulé si refus précédent)
     *
     * L'admin est notifié par email (optionnel selon besoins futurs).
     * Le créateur reçoit un email de confirmation.
     */
    public function verify(CreatorPayoutProfile $profile, User $admin): void
    {
        $profile->setStatus(CreatorVerificationStatus::Verified);
        $profile->setVerifiedBy($admin);
        $profile->setVerifiedAt(new \DateTime());
        $profile->setRejectionReason(null);

        $this->em->flush();

        // Email au créateur : son profil est validé
        $this->notifyCreatorVerified($profile);
    }

    /**
     * Refuse un profil de versement (décision admin).
     *
     * Après refus :
     *   - Statut → Rejected
     *   - verifiedBy → l'admin (même en cas de refus, on trace qui a décidé)
     *   - verifiedAt → maintenant (date du refus)
     *   - rejectionReason → motif fourni par l'admin
     *
     * Le créateur reçoit un email avec le motif pour qu'il puisse corriger et re-soumettre.
     */
    public function reject(CreatorPayoutProfile $profile, User $admin, string $reason): void
    {
        $profile->setStatus(CreatorVerificationStatus::Rejected);
        $profile->setVerifiedBy($admin);
        $profile->setVerifiedAt(new \DateTime());
        $profile->setRejectionReason(trim($reason));

        $this->em->flush();

        // Email au créateur : son profil est refusé avec le motif
        $this->notifyCreatorRejected($profile);
    }

    // ─── Validation manuelle du DTO ──────────────────────────────────────────

    /**
     * Valide les données du DTO de versement.
     *
     * Retourne un message d'erreur si invalide, null si tout est OK.
     *
     * Règles implémentées :
     *   - accountHolderName : non vide, max 255 chars
     *   - iban : non vide, validation de la somme de contrôle IBAN (algorithme MOD-97)
     *   - bic : si fourni, format BIC valide (8 ou 11 chars alphanumériques)
     *   - siret : non vide, exactement 14 chiffres
     *
     * L'algorithme de validation IBAN (MOD-97) est un standard international (ISO 13616).
     * Principe : on convertit les lettres en chiffres (A=10, B=11...) et on vérifie
     * que le reste de la division par 97 est égal à 1.
     */
    private function validateDto(PayoutProfileDTO $dto): ?string
    {
        // Titulaire du compte
        $accountHolderName = trim($dto->accountHolderName ?? '');
        if ($accountHolderName === '') {
            return 'Le nom du titulaire du compte est obligatoire.';
        }
        if (mb_strlen($accountHolderName) > 255) {
            return 'Le nom du titulaire ne doit pas dépasser 255 caractères.';
        }

        // IBAN
        $iban = strtoupper(str_replace(' ', '', trim($dto->iban ?? '')));
        if ($iban === '') {
            return 'L\'IBAN est obligatoire.';
        }
        if (!$this->isValidIban($iban)) {
            return 'L\'IBAN saisi n\'est pas valide. Vérifie qu\'il correspond bien à ton RIB.';
        }

        // BIC (optionnel)
        $bic = strtoupper(trim($dto->bic ?? ''));
        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            return 'Le BIC doit contenir 8 ou 11 caractères (ex : BNPAFRPP ou BNPAFRPPXXX).';
        }

        // SIRET
        $siret = trim($dto->siret ?? '');
        if ($siret === '') {
            return 'Le SIRET est obligatoire.';
        }
        if (!preg_match('/^\d{14}$/', $siret)) {
            return 'Le SIRET doit contenir exactement 14 chiffres (ex : 12345678901234).';
        }
        // Au-delà du format, on vérifie la CLÉ DE CONTRÔLE du SIRET (algorithme de
        // Luhn). Cela rejette les numéros « bien formés mais inventés » (ex :
        // 12345678901234), ce qui évite des dossiers fantaisistes et donne un
        // retour immédiat au membre. (L'admin contrôle ensuite via la pièce d'identité.)
        if (!$this->isValidSiretLuhn($siret)) {
            return 'Le SIRET saisi n\'est pas valide (clé de contrôle incorrecte).';
        }

        return null; // tout est valide
    }

    /**
     * Vérifie la clé de contrôle d'un SIRET (14 chiffres) via l'algorithme de Luhn.
     *
     * Principe : en partant du chiffre le plus à DROITE, on double un chiffre sur
     * deux ; si le résultat dépasse 9, on lui soustrait 9. La somme de tous les
     * chiffres ainsi obtenus doit être un multiple de 10 pour que le SIRET soit valide.
     *
     * @param string $siret 14 chiffres (déjà validés par la regex en amont)
     */
    private function isValidSiretLuhn(string $siret): bool
    {
        $sum = 0;
        $len = strlen($siret);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $siret[$i];
            // On double les chiffres dont la position EN PARTANT DE LA DROITE est impaire.
            // (position depuis la droite = $len - 1 - $i ; impaire ⟺ ($len - $i) pair)
            if ((($len - $i) % 2) === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return ($sum % 10) === 0;
    }

    /**
     * Valide un IBAN selon l'algorithme MOD-97 (ISO 13616).
     *
     * Algorithme en 4 étapes :
     *   1. Déplace les 4 premiers caractères à la fin (FRXX1234... → 1234...FRXX)
     *   2. Remplace chaque lettre par sa valeur numérique (A=10, B=11... Z=35)
     *   3. Calcule le reste de la division de ce grand nombre par 97 (modulo)
     *   4. Si le reste vaut 1, l'IBAN est valide
     *
     * Exemple : FR7630006000011234567890189
     *   → 30006000011234567890189FR76
     *   → 300060000112345678901891527 76   (F=15, R=27)
     *   → 300060000112345678901891527 7 6
     *   → modulo 97 → 1 → valide
     *
     * Limitation : on ne vérifie pas l'existence du compte en banque, seulement la structure.
     */
    private function isValidIban(string $iban): bool
    {
        // Étape 1 : déplace les 4 premiers caractères à la fin
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // Étape 2 : remplace les lettres par leurs valeurs numériques
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            if (ctype_alpha($char)) {
                // A=10, B=11... Z=35 (ord('A') = 65, donc ord(char) - 55 donne 10 pour A)
                $numeric .= (string) (ord($char) - 55);
            } else {
                $numeric .= $char;
            }
        }

        // Étape 3 & 4 : calcul modulo 97 sur un grand entier
        // PHP ne peut pas faire bcmod() sur un entier de 30+ chiffres → on procède par blocs de 9
        $remainder = '';
        foreach (str_split($numeric, 9) as $chunk) {
            $remainder = (string) ((int) ($remainder . $chunk) % 97);
        }

        return (int) $remainder === 1;
    }

    // ─── Notifications email ─────────────────────────────────────────────────

    /**
     * Email à l'équipe Bazaart : un créateur vient de (re)soumettre ses coordonnées.
     */
    private function notifyAdminNewSubmission(CreatorPayoutProfile $profile, bool $isNew): void
    {
        $subject = $isNew
            ? '[Versements] Nouveau dossier créateur soumis'
            : '[Versements] Dossier créateur mis à jour';

        $userEmail = $profile->getUser()->getEmail();
        // Partie locale de l'email (avant le @) pour l'affichage dans l'email admin
        $userLogin = explode('@', $userEmail)[0];

        $body = implode("\n\n", [
            $isNew
                ? 'Un nouveau dossier de versement créateur a été soumis.'
                : 'Un créateur a mis à jour son dossier de versement (re-soumission).',
            'Créateur    : ' . $userLogin . ' (' . $userEmail . ')',
            'Titulaire   : ' . $profile->getAccountHolderName(),
            'SIRET       : ' . $profile->getSiret(),
            // On NE met PAS le chemin interne du fichier dans l'email (inutile pour
            // l'admin + information sensible). On renvoie vers la route admin sécurisée.
            'Pièce d\'identité : ' . ($profile->getIdentityDocumentPath() !== null
                ? '/admin/versements/' . $profile->getId() . '/document'
                : '— aucun document —'),
            '',
            'Traite ce dossier dans le back-office : /admin/versements',
        ]);

        $email = (new Email())
            ->from('noreply@bazaart.fr')
            ->to($this->adminEmail)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }

    /**
     * Email au créateur : son dossier est vérifié et validé.
     */
    private function notifyCreatorVerified(CreatorPayoutProfile $profile): void
    {
        $email = (new Email())
            ->from('noreply@bazaart.fr')
            ->to($profile->getUser()->getEmail())
            ->subject('Ton dossier de versement a été validé !')
            ->text(implode("\n\n", [
                'Bonjour,',
                'Bonne nouvelle : ton dossier de coordonnées bancaires a été vérifié et validé par l\'équipe Bazaart.',
                'Tu pourras désormais recevoir tes versements par virement bancaire après chaque atelier.',
                'Merci pour ta confiance et bienvenue dans la communauté des créateurs Bazaart !',
                'L\'équipe Bazaart',
            ]));

        $this->mailer->send($email);
    }

    /**
     * Email au créateur : son dossier est refusé avec le motif.
     */
    private function notifyCreatorRejected(CreatorPayoutProfile $profile): void
    {
        $reason = $profile->getRejectionReason() ?? 'Motif non précisé.';

        $email = (new Email())
            ->from('noreply@bazaart.fr')
            ->to($profile->getUser()->getEmail())
            ->subject('Ton dossier de versement nécessite des corrections')
            ->text(implode("\n\n", [
                'Bonjour,',
                'Ton dossier de coordonnées bancaires a été examiné par l\'équipe Bazaart, mais ne peut pas encore être validé.',
                'Motif : ' . $reason,
                'Merci de corriger les informations et de re-soumettre ton dossier depuis ton espace membre (/compte/versement).',
                'Si tu as des questions, contacte-nous à : ' . $this->adminEmail,
                'L\'équipe Bazaart',
            ]));

        $this->mailer->send($email);
    }
}
