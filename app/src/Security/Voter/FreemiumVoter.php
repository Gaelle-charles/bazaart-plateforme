<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\SubscriptionChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * FreemiumVoter — Voter d'autorisation pour les fonctionnalités réservées aux abonnés.
 *
 * Ce voter gère le paywall freemium (ADR-0022, Lot D).
 * Il centralise la règle "abonné OU admin" pour toutes les fonctionnalités payantes.
 *
 * ATTRIBUTS SUPPORTÉS (à utiliser avec denyAccessUnlessGranted ou IsGranted) :
 *   - FEATURE_CATALOGUE  : catalogue complet des ressources (/resources)
 *   - FEATURE_ALERTS     : alertes personnalisées (/resources/alerts, /mes-alertes)
 *   - FEATURE_DIRECTORY  : annuaire des artistes (/profile/artist/directory)
 *   - FEATURE_MESSAGING  : messagerie privée (/messages)
 *
 * RÈGLE COMMUNE À TOUS CES ATTRIBUTS (ADR-0022) :
 *   Accès accordé si l'utilisateur est ROLE_ADMIN OU abonné actif (Subscription::isActive()).
 *   Accès refusé si l'utilisateur est non connecté ou utilisateur gratuit.
 *
 * UTILISATION dans un controller :
 *   $this->denyAccessUnlessGranted(FreemiumVoter::FEATURE_CATALOGUE);
 *   // → lance AccessDeniedException si non abonné → à catcher pour rediriger vers /tarifs
 *
 * POURQUOI UN VOTER ET PAS DIRECTEMENT #[IsGranted] ?
 *   #[IsGranted] ne peut vérifier que des RÔLES (ROLE_*) ou des voters sur des sujets.
 *   Ici la logique "abonné = a un Subscription::isActive() en BDD" ne correspond
 *   à aucun rôle Symfony. Le voter encapsule proprement cette logique et permet de
 *   la modifier sans toucher aux controllers.
 *
 * @extends Voter<string, null>
 */
final class FreemiumVoter extends Voter
{
    /**
     * Attributs de paywall — à utiliser avec denyAccessUnlessGranted().
     * Sujet toujours null : on vote sur une action globale, pas sur une entité.
     */
    public const string FEATURE_CATALOGUE = 'FREEMIUM_CATALOGUE';
    public const string FEATURE_ALERTS    = 'FREEMIUM_ALERTS';
    public const string FEATURE_DIRECTORY = 'FREEMIUM_DIRECTORY';
    public const string FEATURE_MESSAGING = 'FREEMIUM_MESSAGING';

    /**
     * Ensemble des attributs gérés par ce voter.
     * Déclaré comme constante pour faciliter les tests et la maintenance.
     *
     * @var string[]
     */
    private const array SUPPORTED_ATTRIBUTES = [
        self::FEATURE_CATALOGUE,
        self::FEATURE_ALERTS,
        self::FEATURE_DIRECTORY,
        self::FEATURE_MESSAGING,
    ];

    /**
     * SubscriptionChecker est injecté via le constructeur (autowiring).
     * C'est lui qui contient la logique "admin → true" + "abonnement actif → true".
     */
    public function __construct(
        private readonly SubscriptionChecker $subscriptionChecker,
    ) {}

    /**
     * Ce voter gère tous les attributs FREEMIUM_*.
     * Le sujet est toujours null (pas d'entité à vérifier).
     *
     * @param string $attribute L'attribut demandé (FREEMIUM_CATALOGUE, etc.)
     * @param mixed  $subject   Toujours null pour ce voter
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        // On ne supporte que les attributs de la liste. Tout autre attribut
        // (ROLE_USER, MATCHING_VIEW, etc.) sera ignoré par ce voter.
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true);
    }

    /**
     * Accorde l'accès si l'utilisateur est abonné ou admin.
     *
     * La logique est entièrement déléguée à SubscriptionChecker::isSubscribed().
     * Si l'utilisateur est non connecté (pas une instance de User), on refuse.
     *
     * @param string         $attribute FREEMIUM_*
     * @param null           $subject   Non utilisé
     * @param TokenInterface $token     Token de sécurité Symfony
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // L'utilisateur doit être connecté (instance de notre classe User).
        // token->getUser() retourne null si non connecté, ou l'objet User si connecté.
        $user = $token->getUser();
        if (!$user instanceof User) {
            // Non connecté → accès refusé (on ne peut pas vérifier l'abonnement)
            return false;
        }

        // SubscriptionChecker::isSubscribed() retourne true si ROLE_ADMIN OU abonnement actif.
        // La logique "admin → true" est dans le service, pas répétée ici.
        return $this->subscriptionChecker->isSubscribed($user);
    }
}
