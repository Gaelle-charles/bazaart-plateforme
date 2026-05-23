<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Resource;
use App\Entity\ResourceAlert;
use App\Enum\AlertFrequency;
use App\Repository\ResourceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * ResourceAlertService — Logique métier du système d'alertes email Ressourcerie.
 *
 * Ce service est le cœur du job d'alertes. Il encapsule trois responsabilités :
 *   1. Calculer la fenêtre temporelle selon la fréquence d'alerte (getWindowStart)
 *   2. Trouver les ressources qui correspondent aux préférences d'un utilisateur (findMatchingResources)
 *   3. Envoyer l'email de notification avec la liste des ressources trouvées (sendAlertEmail)
 *
 * Il est appelé exclusivement par SendResourceAlertsCommand (app:send-resource-alerts),
 * qui tourne en cron quotidien à 8h.
 *
 * Convention d'erreur : les exceptions d'envoi email sont catchées ici et loguées.
 * Le job continue même si un email individuel échoue — on ne veut pas bloquer
 * toute la batch à cause d'une adresse invalide ou d'un timeout SMTP.
 */
class ResourceAlertService
{
    // Adresse expéditrice de la plateforme (doit correspondre au domaine configuré dans Brevo/Resend en prod)
    private const FROM_EMAIL = 'noreply@bazaart.fr';
    private const FROM_NAME  = 'Bazaart';

    public function __construct(
        private readonly ResourceRepository $resourceRepository,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Détermine le point de départ de la fenêtre temporelle selon la fréquence.
     *
     * Règle V1 (pragmatique) :
     *   - Immediate → 24 heures (le cron est quotidien, pas temps-réel en V1)
     *   - Daily     → 24 heures
     *   - Weekly    → 7 jours
     *
     * On retourne un DateTimeImmutable "maintenant minus la fenêtre".
     * La query cherchera les ressources publiées APRÈS cette date.
     *
     * Exemple : si on est le lundi 23 mai à 8h00
     *   - Immediate/Daily → depuis le dimanche 22 mai à 8h00
     *   - Weekly          → depuis le lundi 16 mai à 8h00
     */
    public function getWindowStart(AlertFrequency $frequency): \DateTimeImmutable
    {
        // DateTimeImmutable::modify() retourne une nouvelle instance (immutable = sûr)
        $now = new \DateTimeImmutable();

        return match ($frequency) {
            // Immediate et Daily : même fenêtre de 24h en V1 (le cron tourne une fois par jour)
            AlertFrequency::Immediate, AlertFrequency::Daily => $now->modify('-24 hours'),
            // Weekly : fenêtre de 7 jours (envoyé le lundi, cf. la command)
            AlertFrequency::Weekly => $now->modify('-7 days'),
        };
    }

    /**
     * Trouve les ressources publiées récemment qui correspondent aux préférences
     * de filtrage de l'alerte.
     *
     * La fenêtre temporelle est calculée depuis la fréquence de l'alerte.
     * Les filtres sont extraits de l'alerte elle-même :
     *   - filterDisciplines vide → toutes les disciplines
     *   - filterResourceTypes vide → tous les types
     *
     * @return Resource[] Tableau vide si aucune ressource ne correspond
     */
    public function findMatchingResources(ResourceAlert $alert): array
    {
        // Calcule la date depuis laquelle chercher (ex: il y a 24h pour daily)
        $windowStart = $this->getWindowStart($alert->getFrequency());

        // Extrait les IDs des disciplines filtrées ([] si pas de filtre)
        // array_map sur la Collection Doctrine retourne un tableau PHP
        $disciplineIds = $alert->getFilterDisciplines()
            ->map(fn ($d) => $d->getId())
            ->filter(fn ($id) => $id !== null)  // sécurité : getId() peut retourner null avant persist
            ->toArray();

        // Extrait les IDs des types de ressources filtrés ([] si pas de filtre)
        $typeIds = $alert->getFilterResourceTypes()
            ->map(fn ($t) => $t->getId())
            ->filter(fn ($id) => $id !== null)
            ->toArray();

        // Délègue la requête SQL au repository (logique de requête ≠ logique de service)
        return $this->resourceRepository->findPublishedSince(
            since:         $windowStart,
            disciplineIds: array_values($disciplineIds),  // reindex pour éviter les trous d'index
            typeIds:       array_values($typeIds),
        );
    }

    /**
     * Envoie l'email d'alerte à l'utilisateur propriétaire de l'alerte.
     *
     * Utilise TemplatedEmail (Symfony Bridge Twig) qui rend le template
     * Twig côté serveur avant l'envoi — on peut utiliser les variables
     * Twig normalement dans le template email.
     *
     * Si $resources est vide, la méthode ne fait rien (pas d'email vide envoyé).
     *
     * Gestion d'erreur : les TransportException sont catchées et loguées.
     * On ne relève pas l'exception pour ne pas bloquer le traitement des autres
     * utilisateurs dans la boucle batch.
     *
     * @param Resource[] $resources La liste des ressources à inclure dans l'email
     * @return bool true si l'email a été envoyé avec succès, false sinon
     */
    public function sendAlertEmail(ResourceAlert $alert, array $resources): bool
    {
        // Garde-fou : ne pas envoyer un email vide
        if (empty($resources)) {
            return false;
        }

        $user  = $alert->getUser();
        $count = count($resources);

        // Objet de l'email : "[Bazaart] 3 nouvelle(s) opportunité(s) pour vous"
        $subject = sprintf('[Bazaart] %d nouvelle(s) opportunité(s) pour vous', $count);

        try {
            $email = (new TemplatedEmail())
                // from() accepte une string "email" ou "Nom <email>"
                ->from(sprintf('%s <%s>', self::FROM_NAME, self::FROM_EMAIL))
                ->to($user->getEmail())
                ->subject($subject)
                // Template HTML principal (rendu par Twig via BodyRenderer)
                ->htmlTemplate('emails/resource_alert.html.twig')
                // Template texte brut (fallback pour clients qui bloquent HTML)
                ->textTemplate('emails/resource_alert.txt.twig')
                // Variables passées aux deux templates
                ->context([
                    'alert'     => $alert,
                    'resources' => $resources,
                    'user'      => $user,
                    'count'     => $count,
                ]);

            $this->mailer->send($email);

            $this->logger->info(
                sprintf('Alerte email envoyée à %s (%d ressource(s))', $user->getEmail(), $count),
                [
                    'user_id'       => $user->getId(),
                    'resource_ids'  => array_map(fn (Resource $r) => $r->getId(), $resources),
                    'frequency'     => $alert->getFrequency()->value,
                ]
            );

            return true;

        } catch (TransportExceptionInterface $e) {
            // On logue l'erreur SMTP/transport mais on ne bloque pas le reste du batch
            $this->logger->error(
                sprintf(
                    'Échec envoi alerte email à %s : %s',
                    $user->getEmail(),
                    $e->getMessage()
                ),
                [
                    'user_id'   => $user->getId(),
                    'exception' => $e,
                ]
            );

            return false;
        }
    }
}
