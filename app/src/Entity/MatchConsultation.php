<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MatchConsultationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * MatchConsultation — Enregistrement d'une consultation de match par un utilisateur.
 *
 * Cette entité sert de compteur pour le paywall freemium (ADR-0022, Lot D) :
 * les utilisateurs non abonnés ont droit à 3 consultations de matchs par semaine.
 * Chaque consultation d'une carte (swipe gauche OU droit) crée un enregistrement ici.
 *
 * DÉFINITION D'UNE "CONSULTATION" :
 *   Une consultation = affichage d'une carte de matching.
 *   On incrémente le compteur quand le serveur expose une nouvelle carte
 *   (via POST /swipe/record-view ou à l'initialisation de la section swipe).
 *   Swiper gauche ou droite ne crée qu'UNE SEULE consultation (pas deux).
 *
 * FENÊTRE HEBDOMADAIRE :
 *   On utilise la semaine ISO calendaire (lundi–dimanche).
 *   Reset le lundi à 00:00:00 UTC.
 *   Exemple : si la semaine en cours va du 16/06 au 22/06/2026,
 *   les consultations du 15/06 (semaine précédente) ne comptent pas.
 *
 * STRUCTURE :
 *   - user      : FK vers l'utilisateur (cascade DELETE si le compte est supprimé)
 *   - resource  : FK nullable vers la ressource consultée (null si ressource supprimée)
 *   - viewedAt  : date/heure de la consultation (UTC)
 *
 * ON N'ÉVITE PAS LES DOUBLONS par ressource :
 *   L'ADR-0022 dit "3 ressources consultées par semaine", pas "3 ressources distinctes".
 *   Si l'utilisateur recharge la page, on recompte. C'est simple et pas contournable.
 *
 * PERFORMANCE :
 *   - Index sur (user_id, viewed_at) pour accélérer le COUNT hebdomadaire.
 *   - On ne garde PAS d'historique long : une tâche cron peut purger
 *     les enregistrements de plus de 30 jours (non implémentée en V1).
 */
#[ORM\Entity(repositoryClass: MatchConsultationRepository::class)]
#[ORM\Table(name: 'match_consultations')]
// Index composite (user_id, viewed_at) : utilisé par countForUserThisWeek()
// PostgreSQL peut filtrer sur les deux colonnes en un seul scan d'index.
#[ORM\Index(name: 'idx_match_consultations_user_date', columns: ['user_id', 'viewed_at'])]
class MatchConsultation
{
    // ─── Identifiant ──────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // ─── Relation utilisateur ──────────────────────────────────────────────────

    /**
     * L'utilisateur qui a consulté un match.
     *
     * nullable: false → toujours lié à un utilisateur réel.
     * onDelete: 'CASCADE' → si le compte est supprimé (RGPD), ses consultations le sont aussi.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * La ressource qui a été consultée (carte de matching affichée).
     *
     * nullable: true → si la ressource est supprimée en BDD, on garde l'historique
     * des consultations de l'utilisateur (pour le compteur) mais sans référence cassée.
     * onDelete: 'SET NULL' → Doctrine met resource_id à NULL si la ressource est supprimée.
     */
    #[ORM\ManyToOne(targetEntity: Resource::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Resource $resource;

    // ─── Horodatage de la consultation ────────────────────────────────────────

    /**
     * Date et heure de la consultation en UTC.
     * Initialisée à now() à la création, jamais modifiée ensuite.
     * Utilisée pour délimiter la fenêtre hebdomadaire (semaine ISO).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $viewedAt;

    // ─── Constructeur ─────────────────────────────────────────────────────────

    /**
     * Initialise viewedAt à maintenant (UTC) à la création.
     * L'utilisation du constructeur évite d'oublier d'initialiser ce champ.
     */
    public function __construct(User $user, ?Resource $resource = null)
    {
        $this->user     = $user;
        $this->resource = $resource;
        // new DateTimeImmutable() crée la date dans le fuseau système.
        // En production (UTC), c'est correct. En dev, attention au TZ serveur.
        $this->viewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getResource(): ?Resource
    {
        return $this->resource;
    }

    public function getViewedAt(): \DateTimeImmutable
    {
        return $this->viewedAt;
    }
}
