<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * NotificationExtension — extension Twig pour les données de notifications.
 *
 * Pourquoi une extension Twig ?
 *   Le badge de notifications en sidebar doit être affiché sur TOUTES les pages
 *   de l'application (chaque page étend base_app.html.twig).
 *   Plutôt que de passer le count depuis chaque controller (N lignes dupliquées),
 *   on crée une fonction Twig globale appelable depuis n'importe quel template.
 *
 * Comment ça fonctionne ?
 *   - Cette classe étend AbstractExtension (Twig reconnaît automatiquement
 *     les extensions via leur type grâce à autoconfigure: true dans services.yaml)
 *   - getFunctions() déclare la fonction Twig "unread_notifications_count"
 *   - Le controller Twig appelle getUnreadCount() à chaque rendu de page
 *
 * Convention Symfony 7.x :
 *   L'enregistrement est automatique grâce à autoconfigure: true dans services.yaml.
 *   Symfony scanne src/ et détecte que la classe hérite d'AbstractExtension →
 *   l'enregistre comme service Twig.extension tag automatiquement.
 *   Pas besoin de déclarer un service manuellement dans services.yaml.
 *
 * Performance :
 *   Cette méthode fait une requête BDD à chaque rendu de page (SELECT COUNT(*)).
 *   En V1 c'est acceptable. Pour V2, on pourrait mettre en cache le count
 *   (Redis, 30 secondes) pour alléger la BDD.
 */
class NotificationExtension extends AbstractExtension
{
    /**
     * Cache du count pour le rendu en cours.
     * Evite une requête SQL supplémentaire si unread_notifications_count() est
     * appelée plusieurs fois dans le même rendu (template parent + enfant).
     * Réinitialisé à chaque nouvelle requête HTTP (l'extension est recréée).
     */
    private ?int $cachedCount = null;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        // Security de symfony/security-bundle — donne accès à l'user connecté
        private readonly Security $security,
    ) {}

    /**
     * Déclare les fonctions Twig disponibles dans les templates.
     *
     * Après enregistrement, on peut appeler dans n'importe quel template :
     *   {{ unread_notifications_count() }}
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            // Crée une fonction Twig "unread_notifications_count"
            // qui appelle getUnreadCount() sur cette instance
            new TwigFunction('unread_notifications_count', [$this, 'getUnreadCount']),
        ];
    }

    /**
     * Retourne le nombre de notifications non lues pour l'utilisateur connecté.
     *
     * Retourne 0 si :
     *   - Aucun utilisateur n'est connecté (page publique, ou erreur de session)
     *   - L'objet retourné par Security n'est pas une instance de User
     *     (peut arriver avec des utilisateurs anonymes Symfony)
     *
     * Le type de retour est toujours int (jamais null) pour simplifier
     * l'utilisation dans les templates (pas de vérification {% if count is not null %}).
     */
    public function getUnreadCount(): int
    {
        // getUser() retourne l'utilisateur connecté, ou null si personne n'est connecté
        $user = $this->security->getUser();

        // Vérification de type : s'assure que c'est bien notre entité User Bazaart
        // (pas un InMemoryUser ou autre implémentation Symfony)
        if (!$user instanceof User) {
            return 0;
        }

        // Mémoïsation : on ne fait la requête SQL qu'une fois par rendu.
        // Si unread_notifications_count() est appelée deux fois dans le même template
        // (ex: une fois dans base_app.html.twig et une fois dans un block enfant),
        // la deuxième fois retourne la valeur mise en cache sans requête supplémentaire.
        if ($this->cachedCount === null) {
            $this->cachedCount = $this->notificationRepository->countUnreadByUser($user);
        }

        return $this->cachedCount;
    }
}
