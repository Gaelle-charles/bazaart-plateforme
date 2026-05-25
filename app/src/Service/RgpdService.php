<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\CourseEnrollmentRepository;
use App\Repository\ForumReplyRepository;
use App\Repository\ForumThreadRepository;
use App\Repository\LessonProgressRepository;
use App\Repository\LiveAttendeeRepository;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Repository\PostRepository;
use App\Repository\ResourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RgpdService — gestion des droits RGPD des utilisateurs.
 *
 * Ce service centralise TOUTE la logique RGPD (conformément à la règle du projet :
 * "pas de logique métier dans les controllers"). Il expose deux opérations :
 *
 *   1. exportUserData(User) → array
 *      Collecte toutes les données personnelles de l'utilisateur depuis les différents
 *      repositories et les agrège dans un tableau structuré prêt à être sérialisé en JSON.
 *      Principe RGPD : droit d'accès (art. 15) + droit à la portabilité (art. 20).
 *
 *   2. anonymizeUser(User) → void
 *      Anonymise le compte sans le supprimer pour préserver l'intégrité référentielle.
 *      Principe RGPD : droit à l'effacement (art. 17).
 *      Les posts, ressources et messages restent en BDD mais ne sont plus rattachés
 *      à une identité réelle — seul un pseudonyme anonyme subsiste.
 *
 * Pourquoi anonymiser plutôt que supprimer ?
 *   La suppression brutale d'un utilisateur provoquerait des violations de contrainte
 *   de clé étrangère dans les tables (posts, resources, messages, forum_threads...).
 *   L'anonymisation préserve la cohérence des données tout en effaçant l'identité
 *   de la personne, ce qui satisfait pleinement l'article 17 du RGPD.
 */
class RgpdService
{
    /**
     * Injection par constructeur (convention projet : pas de setters, pas de propriétés publiques).
     *
     * On injecte tous les repositories dont on a besoin pour l'export.
     * EntityManagerInterface est nécessaire pour l'anonymisation (flush).
     * UserPasswordHasherInterface permet de générer un hash de mot de passe aléatoire
     * lors de l'anonymisation (le compte devient inutilisable).
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        // Note : ArtistProfileRepository supprimé — le profil artiste est accessible
        // via la relation lazy $user->getArtistProfile(), pas besoin d'un repository.
        private readonly ResourceRepository $resourceRepository,
        private readonly PostRepository $postRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ForumThreadRepository $forumThreadRepository,
        private readonly ForumReplyRepository $forumReplyRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly LiveAttendeeRepository $liveAttendeeRepository,
        // Repositories formation — ajoutés pour l'export RGPD (droit à la portabilité)
        private readonly CourseEnrollmentRepository $courseEnrollmentRepository,
        private readonly LessonProgressRepository $lessonProgressRepository,
    ) {}

    /**
     * Exporte toutes les données personnelles d'un utilisateur.
     *
     * Ce tableau sera sérialisé en JSON et téléchargé par l'utilisateur.
     * Il couvre l'ensemble des données collectées par Bazaart conformément
     * à l'article 20 du RGPD (droit à la portabilité).
     *
     * Chaque section est optionnelle (null si aucune donnée associée).
     * Les dates sont formatées en ISO 8601 (norme internationale).
     *
     * @return array<string, mixed> Structure exportable en JSON
     */
    public function exportUserData(User $user): array
    {
        $profile = $user->getArtistProfile();

        // ── Profil artiste ─────────────────────────────────────────────────────
        // L'ArtistProfile est accessible via la relation ORM (déjà chargé).
        // On n'injecte pas ArtistProfileRepository ici car la relation lazy est suffisante.
        $artistProfileData = null;
        if ($profile !== null) {
            // ArtistProfile n'a pas de champs city/country/instagram séparés :
            // la localisation est dans getLocation() (champ texte libre)
            // et les réseaux sociaux dans getSocialLinks() (JSON / tableau).
            $artistProfileData = [
                'displayName'  => $profile->getDisplayName(),
                'bio'          => $profile->getBio(),
                'location'     => $profile->getLocation(),
                'websiteUrl'   => $profile->getWebsiteUrl(),
                'portfolioUrl' => $profile->getPortfolioUrl(),
                'socialLinks'  => $profile->getSocialLinks(),
                'avatarPath'   => $profile->getAvatarPath(),
            ];
        }

        // ── Ressources soumises ────────────────────────────────────────────────
        // On cherche les ressources soumises par cet utilisateur en tant qu'artiste.
        // La méthode findBySubmitter() est définie dans ResourceRepository.
        $resourcesData = [];
        foreach ($this->resourceRepository->findBy(['submittedBy' => $user]) as $resource) {
            $resourcesData[] = [
                'id'        => $resource->getId(),
                'title'     => $resource->getTitle(),
                'status'    => $resource->getStatus()?->value,
                'createdAt' => $resource->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Posts communautaires ───────────────────────────────────────────────
        $postsData = [];
        foreach ($this->postRepository->findBy(['author' => $user]) as $post) {
            $postsData[] = [
                'id'        => $post->getId(),
                'content'   => $post->getContent(),
                'createdAt' => $post->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Messages privés envoyés ────────────────────────────────────────────
        // On récupère les messages dont l'auteur est cet utilisateur.
        $messagesData = [];
        foreach ($this->messageRepository->findBy(['author' => $user]) as $msg) {
            $messagesData[] = [
                'id'             => $msg->getId(),
                'content'        => $msg->getContent(),
                'conversationId' => $msg->getConversation()?->getId(),
                // Message utilise getCreatedAt() (lifecycle callback) et non getSentAt()
                'sentAt'         => $msg->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Threads forum créés ────────────────────────────────────────────────
        $threadsData = [];
        foreach ($this->forumThreadRepository->findBy(['author' => $user]) as $thread) {
            $threadsData[] = [
                'id'        => $thread->getId(),
                'title'     => $thread->getTitle(),
                'createdAt' => $thread->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Réponses forum ─────────────────────────────────────────────────────
        $repliesData = [];
        foreach ($this->forumReplyRepository->findBy(['author' => $user]) as $reply) {
            $repliesData[] = [
                'id'        => $reply->getId(),
                'content'   => $reply->getContent(),
                'threadId'  => $reply->getThread()?->getId(),
                'createdAt' => $reply->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Notifications reçues ───────────────────────────────────────────────
        $notifData = [];
        foreach ($this->notificationRepository->findBy(['recipient' => $user]) as $notif) {
            $notifData[] = [
                'id'        => $notif->getId(),
                'type'      => $notif->getType()?->value,
                'isRead'    => $notif->isRead(),
                'createdAt' => $notif->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Inscriptions aux lives ─────────────────────────────────────────────
        $livesData = [];
        foreach ($this->liveAttendeeRepository->findBy(['user' => $user]) as $attendee) {
            $livesData[] = [
                'liveId'       => $attendee->getLive()?->getId(),
                'liveTitle'    => $attendee->getLive()?->getTitle(),
                'registeredAt' => $attendee->getRegisteredAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Inscriptions aux formations ────────────────────────────────────────
        // Droit à la portabilité (RGPD art. 20) : inclure les données de formation
        // car elles révèlent des comportements d'apprentissage de l'utilisateur.
        // On utilise findByUserWithCourse() pour charger Course en une seule requête
        // (évite N+1 sur le titre de la formation).
        //
        // IMPORTANT : on charge les inscriptions UNE SEULE FOIS dans $enrollments
        // et on réutilise ce tableau pour les deux boucles (enrollmentsData + lessonProgressData).
        // Sans cette mise en cache locale, findByUserWithCourse() serait appelé deux fois
        // → deux requêtes SQL identiques pour le même résultat.
        $enrollments = $this->courseEnrollmentRepository->findByUserWithCourse($user);

        $enrollmentsData = [];
        foreach ($enrollments as $enrollment) {
            $enrollmentsData[] = [
                'courseId'        => $enrollment->getCourse()->getId(),
                'courseTitle'     => $enrollment->getCourse()->getTitle(),
                'enrolledAt'      => $enrollment->getEnrolledAt()->format(\DateTimeInterface::ATOM),
                'progressPercent' => $enrollment->getProgressPercent(),
                'completedAt'     => $enrollment->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // ── Progressions de leçons ─────────────────────────────────────────────
        // On regroupe toutes les progressions de leçons pour cet utilisateur.
        // Note : LessonProgress est lié à CourseEnrollment (pas à User directement).
        // On réutilise $enrollments (déjà chargé ci-dessus) pour éviter une 2ème requête SQL.
        $lessonProgressData = [];
        foreach ($enrollments as $enrollment) {
            // findByEnrollmentWithLesson() fait un FETCH JOIN sur la leçon → pas de N+1
            foreach ($this->lessonProgressRepository->findByEnrollmentWithLesson($enrollment) as $progress) {
                $lessonProgressData[] = [
                    'courseId'            => $enrollment->getCourse()->getId(),
                    'lessonId'            => $progress->getLesson()->getId(),
                    'lessonTitle'         => $progress->getLesson()->getTitle(),
                    'startedAt'           => $progress->getStartedAt()?->format(\DateTimeInterface::ATOM),
                    'completedAt'         => $progress->getCompletedAt()?->format(\DateTimeInterface::ATOM),
                    'lastPositionSeconds' => $progress->getLastPositionSeconds(),
                ];
            }
        }

        // ── Assemblage final ───────────────────────────────────────────────────
        // Structure JSON conforme aux recommandations CNIL pour l'export de données.
        return [
            'export_date'         => (new \DateTime())->format(\DateTimeInterface::ATOM),
            'platform'            => 'Bazaart — bazaart.fr',
            'user'                => [
                'id'          => $user->getId(),
                'email'       => $user->getEmail(),
                'roles'       => $user->getRoles(),
                'isVerified'  => $user->isVerified(),
                'createdAt'   => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'artist_profile'      => $artistProfileData,
            'resources_submitted' => $resourcesData,
            'posts'               => $postsData,
            'messages_sent'       => $messagesData,
            'forum_threads'       => $threadsData,
            'forum_replies'       => $repliesData,
            'notifications'        => $notifData,
            'live_registrations'   => $livesData,
            // Données de formation — ajoutées conformément au droit à la portabilité
            'course_enrollments'   => $enrollmentsData,
            'lesson_progress'      => $lessonProgressData,
        ];
    }

    /**
     * Anonymise un compte utilisateur (droit à l'effacement — RGPD art. 17).
     *
     * Opérations effectuées :
     *   1. Remplace l'email par un pseudonyme non identifiant
     *   2. Invalide le mot de passe (hash d'une chaîne aléatoire → compte inutilisable)
     *   3. Réinitialise les rôles à ROLE_USER (retire les privilèges éventuels)
     *   4. Marque le compte comme non vérifié
     *   5. Enregistre la date d'anonymisation (traçabilité RGPD)
     *   6. Persiste les changements en base de données
     *
     * Ce que cette méthode NE fait PAS :
     *   - Ne supprime pas les posts, ressources, messages (intégrité référentielle)
     *   - Ne supprime pas les notifications (elles se nettoieront naturellement)
     *   - Ne touche pas aux entités liées (ArtistProfile etc. — décision scope V1)
     *
     * Sécurité du hash de remplacement :
     *   bin2hex(random_bytes(32)) génère 64 caractères hexadécimaux aléatoires
     *   (256 bits d'entropie). Hashé avec bcrypt, ce mot de passe est
     *   cryptographiquement inutilisable — personne ne peut retrouver la valeur
     *   d'origine ni se connecter avec.
     *
     * @param User $user L'utilisateur à anonymiser
     */
    public function anonymizeUser(User $user): void
    {
        // Guard null : getId() retourne ?int car Doctrine initialise l'id à null
        // avant que l'entité soit persistée (INSERT). Si l'entité n'est pas encore
        // en base, sprintf() génèrerait "anonymise_@bazaart-deleted.fr" (id vide),
        // ce qui violerait la contrainte UNIQUE de la colonne email.
        // On lève une LogicException car c'est une erreur de programmation,
        // pas une erreur utilisateur — un utilisateur anonymisable doit avoir un id.
        $userId = $user->getId() ?? throw new \LogicException(
            'Impossible d\'anonymiser un utilisateur sans identifiant persisté en base.'
        );

        // ── Remplacement de l'email ────────────────────────────────────────────
        // Format : "anonymise_{id}@bazaart-deleted.fr"
        // - Unique grâce à l'id (évite les conflits d'unicité en BDD)
        // - Clairement identifiable comme anonymisé (facilite la maintenance)
        // - Domaine factice (@bazaart-deleted.fr) qui n'existe pas → pas d'email
        //   accidentellement envoyé vers une adresse réelle
        $user->setEmail(sprintf('anonymise_%d@bazaart-deleted.fr', $userId));

        // ── Invalidation du mot de passe ───────────────────────────────────────
        // On hache une chaîne aléatoire de 32 octets (256 bits d'entropie).
        // Le résultat est un hash bcrypt valide mais dont l'entrée est inconnue :
        // personne (y compris l'équipe Bazaart) ne peut retrouver la valeur originale.
        $randomPassword = bin2hex(random_bytes(32));
        $hashedPassword = $this->passwordHasher->hashPassword($user, $randomPassword);
        $user->setPassword($hashedPassword);

        // ── Réinitialisation des rôles ─────────────────────────────────────────
        // On retire ROLE_ARTIST, ROLE_STRUCTURE, etc. pour éviter qu'un compte
        // anonymisé ait des privilèges résiduels si la session n'était pas fermée.
        $user->setRoles(['ROLE_USER']);

        // ── Désactivation de la vérification ──────────────────────────────────
        // isVerified = false bloque une éventuelle reconnexion via "compte vérifié".
        $user->setIsVerified(false);

        // ── Horodatage de l'anonymisation ──────────────────────────────────────
        // Conserve la date pour les audits RGPD (registre des traitements).
        // C'est la seule donnée "personnelle" restante — mais une date n'identifie pas.
        $user->setAnonymizedAt(new \DateTime());

        // ── Persistance en base de données ─────────────────────────────────────
        // flush() envoie les modifications SQL immédiatement.
        // Pas besoin de persist() car l'entité est déjà managée (chargée depuis le contexte).
        $this->entityManager->flush();
    }
}
