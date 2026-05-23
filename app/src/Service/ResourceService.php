<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Discipline;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ResourceStatus;
use App\Enum\SubmitterRole;
use App\Repository\DisciplineRepository;
use App\Repository\OrganizationProfileRepository;
use App\Repository\ResourceRepository;
use App\Repository\ResourceTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Logique métier pour les ressources.
 * Gère la validation, la création et la mise à jour des ressources.
 */
class ResourceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResourceRepository $resourceRepository,
        private readonly ResourceTypeRepository $typeRepository,
        private readonly DisciplineRepository $disciplineRepository,
        private readonly OrganizationProfileRepository $orgRepository,
        // Security est injecté pour pouvoir vérifier les rôles de l'utilisateur
        // sans coupler la logique métier au Request ou au Controller.
        private readonly Security $security,
    ) {}

    /**
     * Crée une nouvelle ressource à partir des données du formulaire.
     * Retourne la ressource créée, ou un message d'erreur sous forme de chaîne.
     *
     * @param array $data Données POST du formulaire
     * @return Resource|string La ressource si succès, un message d'erreur sinon
     */
    public function createResource(User $user, array $data): Resource|string
    {
        // --- Validation ---

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return 'Le titre est obligatoire.';
        }

        $description = trim($data['description'] ?? '');
        if ($description === '') {
            return 'La description est obligatoire.';
        }

        // Le type de ressource doit exister en base
        $typeId = (int) ($data['resourceTypeId'] ?? 0);
        $resourceType = $this->typeRepository->find($typeId);
        if ($resourceType === null) {
            return 'Veuillez sélectionner un type de ressource valide.';
        }

        // En V1, le profil organisation est facultatif.
        // Les artistes sans organisation peuvent soumettre (le champ devient nullable).
        // Seules les structures validées et les admins obtiennent l'auto-publication.
        $organization = $this->orgRepository->findByUser($user);

        // Validation optionnelle de l'URL externe
        $externalUrl = trim($data['externalUrl'] ?? '');
        if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
            return 'L\'URL externe n\'est pas valide. Elle doit commencer par https:// ou http://.';
        }

        // Validation optionnelle de la date limite
        $deadline = null;
        $deadlineStr = trim($data['deadline'] ?? '');
        if ($deadlineStr !== '') {
            $deadline = \DateTime::createFromFormat('Y-m-d', $deadlineStr);
            if ($deadline === false) {
                return 'La date limite n\'est pas au format valide (AAAA-MM-JJ).';
            }
        }

        // --- Construction de l'entité ---

        $resource = new Resource();
        $resource->setTitle($title);
        $resource->setDescription($description);
        $resource->setResourceType($resourceType);
        // organization est nullable : un artiste sans profil org peut soumettre
        $resource->setOrganization($organization);
        $resource->setSubmittedBy($user);
        $resource->setExternalUrl($externalUrl !== '' ? $externalUrl : null);
        $resource->setDeadline($deadline);
        $resource->setLocation(trim($data['location'] ?? '') ?: null);

        // ─── Logique CDC §5.2 : statut selon le rôle du soumetteur ───────────
        //
        // Règle : un admin ou une structure validée peut publier directement.
        // Un artiste ou un membre simple doit attendre la validation admin.
        //
        // Ordre de priorité : ROLE_ADMIN > ROLE_STRUCTURE (validé) > défaut artiste.
        // On utilise $this->security->isGranted() plutôt que de lire getRoles()
        // directement, car isGranted() tient compte de la hiérarchie des rôles Symfony.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            // L'admin publie directement — pas de cycle de modération
            $resource->setSubmitterRole(SubmitterRole::Admin);
            $resource->setStatus(ResourceStatus::Published);
            $resource->setAutoPublished(true);
            $resource->setPublishedAt(new \DateTime());
        } elseif (
            $this->security->isGranted('ROLE_STRUCTURE')
            && $organization !== null
            && $organization->isStructurePartner() === true
        ) {
            // Structure validée → auto-publication sans validation admin
            // On vérifie en plus isStructurePartner() pour se prémunir d'un
            // ROLE_STRUCTURE révoqué mais non retiré de l'objet session.
            $resource->setSubmitterRole(SubmitterRole::Structure);
            $resource->setStatus(ResourceStatus::Published);
            $resource->setAutoPublished(true);
            $resource->setPublishedAt(new \DateTime());
        } else {
            // Cas par défaut : artiste ou membre → soumission en attente de validation
            // Les valeurs défaut de l'entité sont déjà correctes (Artist, PendingValidation,
            // autoPublished = false), mais on les pose explicitement pour la lisibilité.
            $resource->setSubmitterRole(SubmitterRole::Artist);
            $resource->setStatus(ResourceStatus::PendingValidation);
            $resource->setAutoPublished(false);
        }

        // Ajout des disciplines sélectionnées (tableau de IDs depuis les checkboxes)
        $disciplineIds = $data['disciplines'] ?? [];
        if (is_array($disciplineIds)) {
            foreach ($disciplineIds as $disciplineId) {
                $discipline = $this->disciplineRepository->find((int) $disciplineId);
                if ($discipline !== null) {
                    $resource->addDiscipline($discipline);
                }
            }
        }

        $this->em->persist($resource);
        $this->em->flush();

        return $resource;
    }
}
