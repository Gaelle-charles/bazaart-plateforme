<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\AssociationProfile;
use App\Entity\User;
use App\Enum\BazaartAssociation;
use App\Repository\AssociationProfileRepository;
use App\Repository\DisciplineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * AssociationProfileService — fiches « Nos associations » (ADR-0038).
 *
 * Les deux fiches sont créées automatiquement (pré-remplies) à la première
 * consultation : pas besoin de migration de données, et une fiche supprimée
 * par erreur est recréée.
 */
class AssociationProfileService
{
    public function __construct(
        private readonly AssociationProfileRepository $repository,
        private readonly DisciplineRepository $disciplineRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Les deux fiches, indexées par association ('guadeloupe', 'paris').
     *
     * @return array<string, AssociationProfile>
     */
    public function getProfiles(): array
    {
        $profiles = [];
        foreach ($this->repository->findAll() as $profile) {
            $profiles[$profile->getAssociation()->value] = $profile;
        }

        $created = false;
        foreach (BazaartAssociation::cases() as $association) {
            if (!isset($profiles[$association->value])) {
                $profiles[$association->value] = new AssociationProfile($association);
                $this->em->persist($profiles[$association->value]);
                $created = true;
            }
        }
        if ($created) {
            $this->em->flush();
        }

        // Ordre stable : celui de l'enum (Guadeloupe puis Paris).
        $ordered = [];
        foreach (BazaartAssociation::cases() as $association) {
            $ordered[$association->value] = $profiles[$association->value];
        }

        return $ordered;
    }

    public function getProfile(BazaartAssociation $association): AssociationProfile
    {
        return $this->getProfiles()[$association->value];
    }

    /**
     * Enregistre le formulaire de la fiche.
     *
     * @return list<string> erreurs (vide = enregistré)
     */
    public function updateFromRequest(AssociationProfile $profile, Request $request, User $actor): array
    {
        $post   = $request->request;
        $errors = [];

        // Champ texte nettoyé : espaces retirés, longueur plafonnée (les champs du
        // formulaire ont le même maxlength), null si vide.
        $text = static function (string $field, int $max) use ($post): ?string {
            $value = mb_substr(trim((string) $post->get($field, '')), 0, $max);

            return $value !== '' ? $value : null;
        };

        $siret = preg_replace('/\s+/', '', (string) $post->get('siret', '')) ?? '';
        if ($siret !== '' && preg_match('/^\d{14}$/', $siret) !== 1) {
            $errors[] = 'Le SIRET doit comporter 14 chiffres.';
        }

        $year = trim((string) $post->get('foundedYear', ''));
        if ($year !== '' && (!ctype_digit($year) || (int) $year < 1900 || (int) $year > (int) date('Y'))) {
            $errors[] = 'L\'année de création n\'est pas valide.';
        }

        $website = $text('websiteUrl', 255);
        if ($website !== null && !ProjectAttachmentService::isSafeLinkUrl($website)) {
            $errors[] = 'Le site web doit être une adresse commençant par http:// ou https://.';
        }

        $soughtTypes = $post->all('soughtTypes');
        $disciplineIds = array_map('intval', $post->all('disciplines'));

        if ($errors !== []) {
            return $errors;
        }

        $profile
            ->setMission($text('mission', 5000))
            ->setPublics($text('publics', 3000))
            ->setActivities($text('activities', 3000))
            ->setSiret($siret !== '' ? $siret : null)
            ->setFoundedYear($year !== '' ? (int) $year : null)
            ->setAnnualBudget($text('annualBudget', 100))
            ->setTeam($text('team', 255))
            ->setWebsiteUrl($website)
            ->setTerritoryKeywords($text('territoryKeywords', 3000))
            ->setThemeKeywords($text('themeKeywords', 5000))
            ->setExcludedKeywords($text('excludedKeywords', 3000))
            ->setSoughtTypes(array_values(array_filter($soughtTypes, 'is_string')))
            ->replaceDisciplines($disciplineIds !== [] ? $this->disciplineRepository->findBy(['id' => $disciplineIds]) : [])
            ->touch($actor);

        $this->em->flush();

        return [];
    }
}
