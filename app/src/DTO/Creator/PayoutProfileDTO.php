<?php

declare(strict_types=1);

namespace App\DTO\Creator;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * PayoutProfileDTO — données du formulaire de coordonnées de versement créateur.
 *
 * QU'EST-CE QU'UN DTO ?
 * Un DTO (Data Transfer Object) est un objet "tampon" entre le formulaire HTML et la base
 * de données. Il porte les données saisies par l'utilisateur et sert de couche de validation
 * avant la persistance en BDD.
 *
 * Ce DTO couvre deux types de données :
 *   1. Champs scalaires (IBAN, BIC, SIRET, nom du titulaire).
 *   2. Fichier d'identité (UploadedFile) — géré séparément.
 *
 * NOTE TECHNIQUE : symfony/validator n'est pas installé dans ce projet.
 * La validation est donc faite manuellement dans CreatorPayoutService::validateDto()
 * (même approche que CourseProposalService, ForumService, etc.).
 * Le DTO est ici un simple conteneur de données — sans annotations #[Assert\...].
 *
 * Rempli par : CreatorPayoutController::payout() depuis le POST du formulaire.
 * Validé par : CreatorPayoutService::validateDto().
 * Utilisé par : CreatorPayoutService::save() pour créer ou mettre à jour CreatorPayoutProfile.
 */
class PayoutProfileDTO
{
    /**
     * IBAN du créateur.
     *
     * Validation : non vide + algorithme MOD-97 (ISO 13616) dans CreatorPayoutService.
     * Les espaces sont ignorés lors de la validation et supprimés avant persistance.
     * Longueur max en BDD : 34 caractères (IBAN le plus long, ex : Malte).
     */
    public ?string $iban = null;

    /**
     * BIC/SWIFT du compte bancaire (optionnel).
     *
     * Non obligatoire pour les virements SEPA intra-zone euro depuis 2016.
     * Si fourni, validation regex /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/ dans le service.
     * 8 ou 11 caractères alphanumériques.
     */
    public ?string $bic = null;

    /**
     * SIRET de l'entreprise ou de l'auto-entrepreneur (14 chiffres).
     *
     * Obligatoire pour les virements soumis à déclaration fiscale.
     * Validation : regex /^\d{14}$/ dans CreatorPayoutService::validateDto().
     *
     * Explication du SIRET :
     *   - 9 premiers chiffres = SIREN (identifiant de l'entreprise)
     *   - 5 derniers chiffres = NIC (numéro interne de classement de l'établissement)
     */
    public ?string $siret = null;

    /**
     * Nom du titulaire du compte bancaire tel qu'indiqué sur le RIB.
     *
     * Peut différer du nom de l'utilisateur Bazaart (structure tierce, etc.).
     * Obligatoire pour le rapprochement bancaire lors du virement.
     * Max 255 chars (même limite que la colonne BDD).
     */
    public ?string $accountHolderName = null;

    /**
     * Fichier de pièce d'identité uploadé (optionnel à la soumission, requis pour la validation).
     *
     * null → pas de nouveau fichier uploadé à cette soumission (le fichier existant est conservé).
     *
     * Validation dans CreatorDocumentStorage::store() :
     *   - Types autorisés : image/jpeg, image/png, application/pdf
     *   - Taille max : 5 Mo (5 242 880 octets)
     *   - Le type MIME est vérifié via finfo (octets réels du fichier), pas via l'extension.
     *
     * POURQUOI PAS DANS LE DTO ?
     * La validation de fichiers (type MIME, taille) est déléguée à CreatorDocumentStorage
     * pour centraliser la logique de sécurité au même endroit que le stockage.
     */
    public ?UploadedFile $identityDocument = null;
}
