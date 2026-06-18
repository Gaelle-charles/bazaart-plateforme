<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;
use App\Entity\CourseEnrollment;
use App\Entity\User;
use App\Enum\CourseEventMode;
use App\Enum\CourseType;
use App\Repository\CourseEnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * EventRegistrationService — Logique métier des inscriptions aux formations-événements.
 *
 * Ce service centralise toutes les opérations nécessaires pour inscrire
 * un membre à un événement (type EVENEMENT) :
 *
 *   1. Contrôle de capacité (COUNT SQL via CourseEnrollmentRepository)
 *   2. Anti-double-inscription (vérification avant INSERT)
 *   3. Création de l'entité CourseEnrollment
 *   4. Email de confirmation à l'utilisateur (sans révéler le lien visio/adresse)
 *
 * Ce service est utilisé par :
 *   - CourseController::enroll() pour les événements GRATUITS
 *   - StripeWebhookController::handleCoursePaymentCheckoutCompleted() pour les événements PAYANTS
 *     (après confirmation du paiement Stripe)
 *
 * Pourquoi un service dédié plutôt que réutiliser CourseService::enrollUser() ?
 *   CourseService::enrollUser() ne vérifie pas la capacité — il est conçu pour
 *   les formations CONTENU (pas de places limitées). Ajouter le contrôle de
 *   capacité dans CourseService aurait compliqué son interface. Principe SRP :
 *   la logique événement a ses propres invariants (capacité, email spécifique).
 *
 * Principe thin controller : le controller ne fait qu'appeler ce service
 * et gérer la réponse HTTP (flash messages, redirections).
 *
 * PHPStan niveau 6 : toutes les méthodes sont typées, les nullables explicites.
 */
class EventRegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly CourseEnrollmentRepository  $enrollmentRepository,
        private readonly MailerInterface             $mailer,
        private readonly UrlGeneratorInterface       $urlGenerator,
        // Adresse de l'équipe Bazaart (expéditeur des emails). Injectée via
        // le binding global $adminEmail dans services.yaml, elle-même liée à
        // la variable d'env ADMIN_EMAIL. Ce binding est déjà en place pour
        // CourseProposalService — on réutilise le même mécanisme.
        private readonly string $adminEmail,
        // Logger PSR-3 pour tracer les erreurs d'envoi d'email (échecs silencieux).
        // Symfony injecte automatiquement le service 'logger' via le type LoggerInterface.
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Exceptions métier ────────────────────────────────────────────────────

    /**
     * Exception levée si l'événement est complet au moment de l'inscription.
     *
     * Le controller catch cette exception et affiche un flash 'error'.
     * Elle est distincte de \LogicException (doublon) pour permettre des
     * messages différenciés à l'utilisateur.
     */
    public function createEventFullException(Course $event): \RuntimeException
    {
        return new \RuntimeException(
            sprintf(
                'L\'événement "%s" est complet. Il ne reste plus de place disponible.',
                $event->getTitle(),
            )
        );
    }

    // ─── Contrôle de capacité ─────────────────────────────────────────────────

    /**
     * Vérifie si un événement a encore des places disponibles.
     *
     * Utilise un COUNT SQL (via CourseEnrollmentRepository::countActiveByCourse)
     * pour éviter le chargement de toute la collection en mémoire (N+1).
     *
     * Retourne true si :
     *   - L'événement n'a pas de capacité définie (capacity = null → illimité)
     *   - OU le nombre d'inscrits actifs est STRICTEMENT inférieur à la capacité
     *
     * Retourne false si l'événement est complet (inscrits >= capacité).
     *
     * Pourquoi "strictement inférieur" et pas "<=" ?
     *   On veut savoir s'il reste de la place POUR UNE INSCRIPTION SUPPLÉMENTAIRE.
     *   Si capacity = 20 et inscrits = 19, il reste 1 place → true.
     *   Si capacity = 20 et inscrits = 20, c'est complet → false.
     *
     * @param Course $event La formation-événement à vérifier
     * @return bool         true si inscription possible, false si complet
     */
    public function hasAvailableSeats(Course $event): bool
    {
        // Pas de capacité définie → places illimitées → toujours vrai
        if ($event->getCapacity() === null) {
            return true;
        }

        // Comptage SQL précis via le repository (évite le N+1)
        $currentCount = $this->enrollmentRepository->countActiveByCourse($event);

        // Il reste de la place si le compte actuel est inférieur à la capacité max
        return $currentCount < $event->getCapacity();
    }

    /**
     * Calcule le nombre de places restantes pour un événement.
     *
     * Retourne null si la capacité est illimitée.
     * Retourne 0 si l'événement est complet.
     *
     * Contrairement à Course::getSeatsRemaining() (qui se base sur la collection
     * en mémoire et peut déclencher un lazy-load), cette méthode utilise un
     * COUNT SQL précis et ne charge pas les inscriptions.
     *
     * @param Course $event La formation-événement
     * @return int|null     Places restantes, ou null si illimité
     */
    public function getSeatsRemaining(Course $event): ?int
    {
        if ($event->getCapacity() === null) {
            return null;
        }

        $currentCount = $this->enrollmentRepository->countActiveByCourse($event);

        // max(0, ...) pour éviter les valeurs négatives en cas de données incohérentes
        return max(0, $event->getCapacity() - $currentCount);
    }

    // ─── Inscription à un événement ───────────────────────────────────────────

    /**
     * Inscrit un membre à un événement après vérification de capacité et de doublon.
     *
     * Cette méthode est le point d'entrée principal pour toute inscription :
     *   1. Vérifie que la formation est bien un événement (type EVENEMENT)
     *   2. Contrôle la capacité (COUNT SQL) — anti-survente
     *   3. Vérifie l'absence de doublon (anti-double-inscription)
     *   4. Crée et persiste CourseEnrollment
     *   5. Envoie l'email de confirmation
     *
     * Transactions et concurrence :
     *   La vérification de capacité et le persist() + flush() ne sont PAS dans
     *   une transaction SQL explicite. Dans le cas extrêmement rare où deux
     *   requêtes quasi-simultanées arrivent sur le dernier siège, la contrainte
     *   UNIQUE SQL (user_id, course_id) empêchera le doublon. Pour la survente,
     *   la contrainte SQL unique ne s'applique pas (deux UTILISATEURS DIFFÉRENTS).
     *   Une solution de verrouillage (SELECT FOR UPDATE) serait nécessaire pour
     *   un site à très fort trafic — pour V1 avec quelques dizaines d'inscrits
     *   max par événement, ce niveau de protection est suffisant.
     *
     * @param Course $event La formation-événement (doit être de type EVENEMENT)
     * @param User   $user  L'utilisateur qui s'inscrit
     *
     * @return CourseEnrollment L'inscription créée
     *
     * @throws \LogicException   Si l'utilisateur est déjà inscrit
     * @throws \RuntimeException Si l'événement est complet
     * @throws \InvalidArgumentException Si la formation n'est pas un événement
     */
    public function registerForEvent(Course $event, User $user): CourseEnrollment
    {
        // ── Garde : vérification que c'est bien un événement ─────────────────
        // Ce service n'est pas censé inscrire à une formation CONTENU (CourseService
        // s'en occupe). Cette vérification est défensive.
        if (!$event->isEvent()) {
            throw new \InvalidArgumentException(
                sprintf(
                    'La formation "%s" n\'est pas un événement. Utilisez CourseService::enrollUser() '
                    . 'pour les formations de type CONTENU.',
                    $event->getTitle(),
                )
            );
        }

        // ── Contrôle de capacité (critique : anti-survente) ──────────────────
        // On vérifie la capacité AVANT de vérifier le doublon intentionnellement :
        //   - Si l'événement est complet, inutile d'aller plus loin
        //   - La vérification doublon est moins coûteuse en dernier recours
        if (!$this->hasAvailableSeats($event)) {
            throw $this->createEventFullException($event);
        }

        // ── Contrôle anti-double-inscription ─────────────────────────────────
        // La contrainte SQL UNIQUE sur (user_id, course_id) est le filet ultime,
        // mais on vérifie en PHP pour un message d'erreur lisible.
        $existing = $this->enrollmentRepository->findByUserAndCourse($user, $event);
        if ($existing !== null) {
            throw new \LogicException(
                sprintf(
                    'Vous êtes déjà inscrit(e) à l\'événement "%s".',
                    $event->getTitle(),
                )
            );
        }

        // ── Création de l'inscription ─────────────────────────────────────────
        $enrollment = new CourseEnrollment();
        $enrollment->setUser($user);
        $enrollment->setCourse($event);
        // progressPercent = 0 par défaut (défini dans CourseEnrollment)
        // Pour un événement, ce champ n'a pas de sens métier (pas de leçons)
        // mais on le conserve pour l'unicité structurelle de l'entité.

        $this->em->persist($enrollment);
        $this->em->flush();

        // ── Email de confirmation ─────────────────────────────────────────────
        // Envoyé APRÈS le flush (inscription confirmée en base).
        // Si l'email échoue, l'inscription est quand même créée — l'utilisateur
        // reste inscrit et peut retrouver l'accès via son dashboard.
        // En V2, on pourrait utiliser Messenger pour l'envoi asynchrone.
        try {
            $this->sendConfirmationEmail($enrollment);
        } catch (\Throwable $e) {
            // On ne fait PAS échouer l'inscription si l'email plante.
            // L'utilisateur reste inscrit et peut retrouver l'accès via son dashboard.
            // On log l'erreur pour que l'équipe Bazaart puisse identifier les cas
            // où des inscrits n'ont pas reçu leur email de confirmation.
            $this->logger->warning('Envoi email confirmation événement échoué.', [
                'course_id'  => $enrollment->getCourse()->getId(),
                'user_email' => $enrollment->getUser()->getEmail(),
                'error'      => $e->getMessage(),
            ]);
        }

        return $enrollment;
    }

    // ─── Email de confirmation ────────────────────────────────────────────────

    /**
     * Envoie l'email de confirmation d'inscription à un événement.
     *
     * Décision ADR-0014 §4 :
     *   L'email NE contient PAS le lien visio / l'adresse du lieu directement.
     *   Objectif : ramener le membre sur la plateforme (dashboard) pour retrouver
     *   l'accès. Cela augmente l'engagement et permet à Bazaart de contrôler
     *   l'expérience d'accès (ex : rappel, message de préparation).
     *
     * L'email contient :
     *   - Titre et date de l'événement
     *   - Mode (visio ou présentiel) + indication où trouver l'accès
     *   - Lien vers le dashboard (https://bazaart.fr/dashboard ou localhost équivalent)
     *
     * Configuration de l'expéditeur :
     *   On réutilise 'noreply@bazaart.fr' comme dans CourseProposalService.
     *   La variable $adminEmail (ADMIN_EMAIL dans .env) est utilisée en reply-to
     *   pour que l'utilisateur puisse répondre à l'équipe si besoin.
     *
     * @param CourseEnrollment $enrollment L'inscription fraîchement créée
     */
    public function sendConfirmationEmail(CourseEnrollment $enrollment): void
    {
        $event  = $enrollment->getCourse();
        $user   = $enrollment->getUser();

        // ── Construction de la ligne "quand" ─────────────────────────────────
        // Format lisible pour la France : "samedi 12 juillet 2025 à 14h00"
        // On utilise strftime-like via IntlDateFormatter si disponible, sinon
        // format PHP simple.
        $dateStr = $this->formatEventDate($event);

        // ── Construction du mode d'accès ──────────────────────────────────────
        // On indique le MODE mais PAS l'accès concret (lien/adresse) → dashboard.
        $modeLabel = match ($event->getEventMode()) {
            CourseEventMode::VISIO       => 'en ligne (visio)',
            CourseEventMode::PRESENTIEL  => 'en présentiel',
            null                          => 'format à confirmer',
        };

        // ── URL du dashboard ──────────────────────────────────────────────────
        // generateUrl() produit une URL absolue grâce à UrlGeneratorInterface::ABSOLUTE_URL.
        // C'est nécessaire pour les emails (les liens relatifs ne fonctionnent pas).
        $dashboardUrl = $this->urlGenerator->generate(
            'app_dashboard',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // ── Corps de l'email ──────────────────────────────────────────────────
        $body = implode("\n\n", [
            sprintf(
                'Bonjour,',
            ),
            sprintf(
                'Votre inscription à l\'événement « %s » a bien été enregistrée.',
                $event->getTitle(),
            ),
            sprintf(
                'Quand : %s',
                $dateStr,
            ),
            sprintf(
                'Format : %s',
                $modeLabel,
            ),
            // Section la plus importante : indiquer où trouver l'accès (sans le révéler ici)
            'Pour accéder au lien de connexion (visio) ou à l\'adresse du lieu (présentiel), '
            . 'rendez-vous sur votre tableau de bord :',
            $dashboardUrl,
            'L\'accès sera disponible dans la section « Mes formations & événements à venir » '
            . 'de votre tableau de bord.',
            'À très bientôt,',
            'L\'équipe Bazaart',
        ]);

        $email = (new Email())
            // Expéditeur standard du projet (cf. CourseProposalService).
            // On utilise Address() pour inclure un nom d'affichage lisible :
            // sans ça, certains clients mail affichent "noreply@bazaart.fr" brut
            // au lieu de "Bazaart", ce qui nuit à la crédibilité des emails.
            ->from(new Address('noreply@bazaart.fr', 'Bazaart'))
            // Reply-To = adresse admin pour que l'utilisateur puisse poser des questions
            ->replyTo($this->adminEmail)
            ->to($user->getEmail())
            ->subject(sprintf('[Bazaart] Inscription confirmée — %s', $event->getTitle()))
            ->text($body);

        $this->mailer->send($email);
    }

    // ─── Méthodes utilitaires ─────────────────────────────────────────────────

    /**
     * Formate la date d'un événement de façon lisible en français.
     *
     * Exemple : "samedi 12 juillet 2025 à 14h00"
     * Si eventStartAt est null (événement mal configuré), retourne "date à confirmer".
     *
     * On utilise la méthode PHP date() avec un format manuel pour éviter une dépendance
     * à l'extension intl (non garantie dans tous les environments Docker).
     *
     * @param Course $event La formation-événement
     * @return string       La date formatée en français
     */
    private function formatEventDate(Course $event): string
    {
        $startAt = $event->getEventStartAt();

        if ($startAt === null) {
            return 'date à confirmer';
        }

        // Tableau de traduction des jours et mois en français
        // (remplacement de setlocale qui n'est pas fiable dans Docker)
        $joursMap = [
            'Monday'    => 'lundi',
            'Tuesday'   => 'mardi',
            'Wednesday' => 'mercredi',
            'Thursday'  => 'jeudi',
            'Friday'    => 'vendredi',
            'Saturday'  => 'samedi',
            'Sunday'    => 'dimanche',
        ];

        $moisMap = [
            'January'   => 'janvier',
            'February'  => 'février',
            'March'     => 'mars',
            'April'     => 'avril',
            'May'       => 'mai',
            'June'      => 'juin',
            'July'      => 'juillet',
            'August'    => 'août',
            'September' => 'septembre',
            'October'   => 'octobre',
            'November'  => 'novembre',
            'December'  => 'décembre',
        ];

        // On construit la chaîne en anglais puis on traduit les mots
        $jourEn  = $startAt->format('l');       // ex: "Saturday"
        $moisEn  = $startAt->format('F');       // ex: "July"
        $jour    = $joursMap[$jourEn]  ?? $jourEn;
        $mois    = $moisMap[$moisEn]   ?? $moisEn;
        $jour_num = $startAt->format('j');       // ex: "12" (sans zéro devant)
        $annee   = $startAt->format('Y');        // ex: "2025"
        $heure   = $startAt->format('H\hi');     // ex: "14h00"

        return sprintf('%s %s %s %s à %s', $jour, $jour_num, $mois, $annee, $heure);
    }
}
