<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * UserChecker — vérifie l'état du compte avant l'authentification.
 *
 * Symfony appelle ce checker automatiquement pour TOUS les firewalls
 * qui le déclarent (formulaire de login ET Google OAuth via custom_authenticators).
 *
 * Problème résolu :
 *   Sans ce checker, un compte anonymisé (RGPD droit à l'oubli) peut être
 *   contourné via Google OAuth. Si l'utilisateur tente de se reconnecter
 *   avec Google après anonymisation, GoogleAuthenticator ne trouve plus son
 *   email (remplacé par "anonymise_X@bazaart-deleted.fr") et crée un NOUVEAU
 *   compte — ce qui bypass complètement l'anonymisation.
 *
 * Solution :
 *   checkPreAuth() est appelé par Symfony AVANT la vérification du mot de passe
 *   ou du token OAuth. Si le compte est marqué anonymisé (anonymizedAt != null),
 *   on lève une exception qui bloque l'authentification immédiatement.
 *
 * Enregistrement dans security.yaml :
 *   firewalls:
 *       main:
 *           user_checker: App\Security\UserChecker
 *
 * Convention Symfony : ce service est injecté via l'interface UserCheckerInterface.
 * L'autowiring Symfony reconnaît automatiquement l'implémentation si elle est
 * déclarée dans user_checker (pas besoin de configuration de service supplémentaire).
 */
class UserChecker implements UserCheckerInterface
{
    /**
     * Vérifié AVANT l'authentification (mot de passe, token OAuth...).
     *
     * Cette méthode est appelée par Symfony Security juste après que le provider
     * a chargé l'utilisateur depuis la base de données, mais AVANT que le
     * credentials (mot de passe ou token) soit vérifié.
     *
     * Si une exception est levée ici, l'authentification est avortée et
     * le message est affiché sur la page de login via le système de flash
     * de Symfony Security.
     *
     * @throws CustomUserMessageAccountStatusException si le compte est anonymisé
     */
    public function checkPreAuth(UserInterface $user): void
    {
        // On ne traite que les entités User de l'application.
        // UserInterface est plus générique : on pourrait recevoir un autre type
        // dans un contexte multi-firewall avec plusieurs providers.
        if (!$user instanceof User) {
            return;
        }

        // Bloquer les comptes anonymisés (demande RGPD d'effacement traitée).
        // isAnonymized() retourne true si anonymizedAt !== null.
        //
        // Message intentionnellement vague : ne pas révéler que le compte
        // "existait" et a été supprimé (protection vie privée, RGPD art. 5.1.c).
        // CustomUserMessageAccountStatusException est rendu par Symfony Security
        // dans le template de login — pas besoin de le gérer manuellement.
        if ($user->isAnonymized()) {
            throw new CustomUserMessageAccountStatusException(
                'Ce compte n\'est plus accessible. Contactez support@bazaart.fr pour toute question.'
            );
        }
    }

    /**
     * Vérifié APRÈS l'authentification réussie.
     *
     * Appelé une fois que les credentials ont été validés avec succès.
     * Permet de bloquer des comptes dans des états spéciaux POST-authentification
     * (ex : compte suspendu, email non vérifié avec délai de grâce expiré...).
     *
     * Non utilisé en V1 — réservé pour des vérifications futures.
     * Ex V2 : bloquer un compte suspendu par un admin après une modération.
     */
    public function checkPostAuth(UserInterface $user): void
    {
        // Rien à vérifier en V1 après authentification réussie.
    }
}
