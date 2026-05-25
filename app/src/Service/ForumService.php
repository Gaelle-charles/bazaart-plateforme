<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ForumCategory;
use App\Entity\ForumReply;
use App\Entity\ForumThread;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\ForumThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * ForumService — logique métier du forum communautaire Bazaart.
 *
 * Ce service centralise toutes les opérations sur les threads et réponses :
 * création, suppression, modération (pin/lock), compteurs de vues, signalements.
 *
 * Principe de séparation des responsabilités :
 *   - Le controller gère HTTP (request, response, CSRF, redirects, flash messages)
 *   - Le voter gère les autorisations
 *   - CE service gère la logique métier (validation, persistance, cohérence des données)
 *
 * Le service ne connaît pas le Request Symfony — il travaille avec des entités et scalaires.
 */
class ForumService
{
    /**
     * Segments d'URL réservés par le routeur du forum.
     *
     * Si un titre génère exactement l'un de ces slugs, il entrerait en collision
     * avec une route Symfony existante (ex : /forum/{cat}/nouveau → app_forum_new_thread).
     * On leur ajoute automatiquement un suffixe aléatoire court pour éviter le conflit.
     *
     * Tenir cette liste à jour dès qu'une nouvelle route est ajoutée avec un segment fixe.
     */
    private const RESERVED_SLUGS = ['nouveau', 'new'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ForumThreadRepository $threadRepository,
        // Injecté pour notifier l'auteur du thread lors d'une nouvelle réponse
        private readonly NotificationService $notificationService,
        // Injecté pour envoyer des emails de signalement à l'admin
        private readonly MailerInterface $mailer,
    ) {}

    // ─── Threads ──────────────────────────────────────────────────────────────

    /**
     * Crée un nouveau thread dans une catégorie.
     *
     * Validation des données avant persistance :
     *   - title : obligatoire, entre 5 et 255 caractères
     *   - content : obligatoire, au moins 10 caractères
     *   - category : doit être active
     *
     * Génération du slug :
     *   Le SluggerInterface de Symfony transforme le titre en URL-friendly string.
     *   Exemple : "Recherche d'un graphiste" → "Recherche-d-un-graphiste" → (lower) "recherche-d-un-graphiste"
     *   Si le slug est déjà pris (collision), on ajoute l'ID du thread après création.
     *
     * @param array{title?: string, content?: string} $data Données du formulaire POST
     * @return ForumThread|string L'entité créée en cas de succès, ou un message d'erreur
     */
    public function createThread(User $author, ForumCategory $category, array $data): ForumThread|string
    {
        // ── Validation ────────────────────────────────────────────────────────

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return 'Le titre est obligatoire.';
        }
        if (mb_strlen($title) < 5) {
            return 'Le titre doit contenir au moins 5 caractères.';
        }
        if (mb_strlen($title) > 255) {
            return 'Le titre ne doit pas dépasser 255 caractères.';
        }

        $content = trim($data['content'] ?? '');
        if ($content === '') {
            return 'Le contenu est obligatoire.';
        }
        if (mb_strlen($content) < 10) {
            return 'Le contenu doit contenir au moins 10 caractères.';
        }

        // La catégorie doit être active pour accueillir de nouveaux threads
        if (!$category->isActive()) {
            return 'Cette catégorie est désactivée et n\'accepte plus de nouveaux sujets.';
        }

        // ── Création de l'entité ───────────────────────────────────────────────

        $thread = new ForumThread();
        $thread->setAuthor($author);
        $thread->setCategory($category);
        $thread->setTitle($title);
        $thread->setContent($content);

        // ── Génération et déduplication du slug ───────────────────────────────

        // symfony/string n'est pas une dépendance directe dans ce projet,
        // donc on utilise une translittération maison (iconv + regex) pour rester autonome.
        $slug = $this->slugify($title);

        // ── Correction 1 : protection contre les slugs réservés ──────────────
        // Certains segments d'URL sont utilisés par le routeur Symfony comme routes fixes.
        // Exemple : un titre "Nouveau" génère le slug "nouveau", qui intercepte la route
        // /forum/{cat}/nouveau (app_forum_new_thread) et rend le thread inaccessible.
        //
        // On vérifie EN PREMIER (avant la déduplication) car le slug doit être valide
        // comme base avant de chercher des doublons en BDD.
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            // On ajoute 6 caractères hexadécimaux aléatoires : "nouveau" → "nouveau-a1b2c3"
            // bin2hex(random_bytes(3)) génère 6 caractères hex cryptographiquement sûrs
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        // ⚠️ IMPORTANT : le slug est UNIQUE en BDD sur toute la table (pas par catégorie).
        // Si deux threads de catégories différentes ont le même titre (même slug candidat),
        // le flush() lèverait une UniqueConstraintViolationException sans ce contrôle.
        //
        // On vérifie AVANT le persist si le slug existe déjà (globalement, pas par catégorie).
        // Si oui, on ajoute un suffixe aléatoire court pour garantir l'unicité.
        // Le format reste lisible : "recherche-graphiste-a1b2" plutôt qu'un UUID complet.
        //
        // Note : il subsiste un risque de race condition théorique entre le findOneBy et
        // le flush si deux threads sont créés simultanément avec le même titre — acceptable
        // pour la V1 où le volume est faible. En V2 : retry loop ou exception catchée.
        $existingGlobal = $this->threadRepository->findOneBy(['slug' => $slug]);
        if ($existingGlobal !== null) {
            // Collision globale détectée — on ajoute 6 caractères aléatoires
            $slug = $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $thread->setSlug($slug);

        // ── Persistance ───────────────────────────────────────────────────────

        // persist() dit à Doctrine "je veux sauvegarder cette entité"
        $this->em->persist($thread);
        // flush() exécute les requêtes SQL réelles (INSERT)
        // À ce stade, le slug est déjà unique (vérifié ci-dessus) — pas de double flush nécessaire.
        $this->em->flush();

        return $thread;
    }

    /**
     * Ajoute une réponse à un thread.
     *
     * Validation :
     *   - content : obligatoire, minimum 5 caractères
     *
     * Note : la vérification du verrouillage du thread (isLocked) est gérée
     * dans le controller, pas ici. Ce service fait confiance au controller
     * pour ne l'appeler que si l'action est autorisée (principe de séparation).
     *
     * Après ajout, les compteurs du thread sont mis à jour :
     *   - repliesCount++ (incrémenté sur l'entité)
     *   - lastReplyAt = now (pour le tri par activité)
     *
     * @param array{content?: string, parentReplyId?: int} $data Données du formulaire
     * @return ForumReply|string La réponse créée, ou un message d'erreur
     */
    public function addReply(User $author, ForumThread $thread, array $data): ForumReply|string
    {
        // ── Validation ────────────────────────────────────────────────────────

        $content = trim($data['content'] ?? '');
        if ($content === '') {
            return 'Le contenu de la réponse est obligatoire.';
        }
        if (mb_strlen($content) < 5) {
            return 'La réponse doit contenir au moins 5 caractères.';
        }

        // ── Création ──────────────────────────────────────────────────────────

        $reply = new ForumReply();
        $reply->setAuthor($author);
        $reply->setThread($thread);
        $reply->setContent($content);

        // Gestion de la réponse parente (feature optionnelle V1 — non exposée en UI)
        // Si un parentReplyId est passé, on résout la relation auto-référentielle.
        // En V1, l'UI ne génère pas de parentReplyId — ce code prépare la V2.
        if (!empty($data['parentReplyId'])) {
            $parentReply = $this->em->find(ForumReply::class, (int) $data['parentReplyId']);
            // On vérifie que le parent appartient bien au même thread (sécurité)
            if ($parentReply instanceof ForumReply && $parentReply->getThread() === $thread) {
                $reply->setParentReply($parentReply);
            }
        }

        // ── Mise à jour des compteurs du thread ───────────────────────────────
        // On met à jour le compteur dénormalisé sur le thread pour éviter des COUNT(*)
        // coûteux à chaque affichage de la liste des threads.
        $thread->incrementReplies();

        // ── Persistance ───────────────────────────────────────────────────────

        $this->em->persist($reply);
        // flush() sauvegarde à la fois la nouvelle réponse ET les changements sur le thread
        $this->em->flush();

        // ── Notification à l'auteur du thread ────────────────────────────────

        // Après le flush (réponse bien sauvegardée), on notifie l'auteur du thread.
        // Règle : si l'auteur de la réponse EST l'auteur du thread (il répond à lui-même),
        // NotificationService::create() bloque l'auto-notification grâce au $sender.
        // ForumThread::getAuthor() retourne toujours un User (non nullable) → pas besoin de null-check.
        $threadAuthor = $thread->getAuthor();

        // Crée une notification de type "new_reply" pour l'auteur du thread.
        // data['threadTitle'] → affiché dans le texte de la notif ("Réponse sur : Mon sujet")
        // data['replyAuthorEmail'] → permet d'afficher qui a répondu
        $this->notificationService->create(
            recipient: $threadAuthor,
            type: NotificationType::NewReply,
            relatedEntityType: 'forum_thread',
            relatedEntityId: $thread->getId(),
            data: [
                'threadTitle' => $thread->getTitle(),
                // Partie locale uniquement — RGPD : l'email complet est une donnée personnelle.
                // "replyAuthorEmail" contient désormais "marie" (pas "marie@example.com").
                'replyAuthorEmail' => explode('@', $author->getEmail())[0],
            ],
            sender: $author,
        );

        return $reply;
    }

    // ─── Modération ───────────────────────────────────────────────────────────

    /**
     * Bascule le statut verrouillé d'un thread (toggle on/off).
     *
     * Seuls admin et modérateurs peuvent appeler cette méthode
     * (la vérification est faite dans le controller via ForumVoter::FORUM_LOCK).
     *
     * @return bool Le nouvel état : true = verrouillé, false = déverrouillé
     */
    public function toggleLock(ForumThread $thread): bool
    {
        // Inverse l'état actuel
        $newState = !$thread->isLocked();
        $thread->setIsLocked($newState);
        $this->em->flush();
        return $newState;
    }

    /**
     * Bascule le statut épinglé d'un thread (toggle on/off).
     *
     * Seuls les admins peuvent appeler cette méthode
     * (la vérification est faite dans le controller via ForumVoter::FORUM_PIN).
     *
     * @return bool Le nouvel état : true = épinglé, false = non épinglé
     */
    public function togglePin(ForumThread $thread): bool
    {
        $newState = !$thread->isPinned();
        $thread->setIsPinned($newState);
        $this->em->flush();
        return $newState;
    }

    // ─── Suppressions ─────────────────────────────────────────────────────────

    /**
     * Supprime un thread et toutes ses réponses.
     *
     * Grâce à orphanRemoval: true sur ForumThread::$replies et à la cascade
     * configurée au niveau SQL (onDelete: 'CASCADE' dans ForumReply), la
     * suppression du thread entraîne automatiquement la suppression de ses réponses.
     * Les deux mécanismes sont redondants mais cohérents (sécurité en double).
     *
     * Note : le compteur de la catégorie n'est pas dénormalisé en V1,
     * donc aucune mise à jour nécessaire ici.
     */
    public function deleteThread(ForumThread $thread): void
    {
        $this->em->remove($thread);
        $this->em->flush();
    }

    /**
     * Supprime une réponse et décrémente le compteur de réponses du thread.
     *
     * L'ordre est important :
     *   1. On récupère le thread AVANT de supprimer la réponse
     *   2. On décrémente le compteur
     *   3. On supprime la réponse
     *   4. On flush (sauvegarde les deux changements en une seule transaction)
     */
    public function deleteReply(ForumReply $reply): void
    {
        // Récupérer le thread parent avant suppression (pour mettre à jour le compteur)
        $thread = $reply->getThread();

        // Décrémente le compteur dénormalisé (max(0, count-1))
        $thread->decrementReplies();

        // Marque la réponse pour suppression
        $this->em->remove($reply);

        // Flush sauvegarde les deux modifications en une seule transaction SQL
        $this->em->flush();
    }

    // ─── Compteurs ────────────────────────────────────────────────────────────

    // ─── Utilitaire interne ───────────────────────────────────────────────────

    /**
     * Génère un slug URL-friendly depuis un titre en français.
     *
     * Pourquoi une méthode maison ?
     *   symfony/string (qui fournit SluggerInterface) n'est pas dans les dépendances
     *   directes de ce projet. Plutôt que d'ajouter une dépendance, on utilise iconv()
     *   (disponible via ext-iconv, listée dans composer.json) pour la translittération.
     *
     * Processus :
     *   1. iconv translittère les caractères accentués : "é" → "e", "ç" → "c", etc.
     *   2. Passage en minuscules
     *   3. Remplacement des caractères non alphanumériques par des tirets
     *   4. Nettoyage des tirets multiples et des tirets en début/fin
     *
     * Exemple : "Recherche d'un Graphiste — Diaspora !" → "recherche-d-un-graphiste-diaspora"
     */
    private function slugify(string $text): string
    {
        // Translittère les caractères Unicode vers leur équivalent ASCII
        // TRANSLIT → tente de trouver le caractère le plus proche
        // IGNORE   → ignore les caractères sans équivalent ASCII
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        // Passe en minuscules
        $text = strtolower($text);

        // Remplace tout ce qui n'est pas une lettre ou un chiffre par un tiret
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        // Supprime les tirets en début et en fin de chaîne
        return trim($text, '-');
    }

    /**
     * Incrémente le compteur de vues d'un thread de façon ATOMIQUE.
     *
     * Appelé à chaque affichage de la page du thread (ForumController::thread()).
     * En V1, le compteur est brut (pas de déduplication par session/IP/user).
     * Cela donne une indication d'intérêt relatif mais pas une mesure exacte.
     *
     * Correction 4 — Race condition :
     *   L'ancienne implémentation faisait lecture → +1 → écriture sur l'entité.
     *   Si deux requêtes HTTP arrivent simultanément, elles lisent toutes les deux
     *   viewsCount = 42, et écrivent toutes les deux 43 → une vue est perdue.
     *
     *   Solution : un UPDATE SQL direct avec une expression "t.viewsCount + 1".
     *   La base de données garantit l'atomicité de cet incrément, peu importe
     *   la concurrence. C'est le pattern standard pour les compteurs haute-fréquence.
     *
     * Note : on n'appelle pas flush() ici — la requête DQL UPDATE s'exécute
     * directement en base sans passer par l'Unit of Work de Doctrine.
     */
    public function incrementViews(ForumThread $thread): void
    {
        // UPDATE forum_thread SET views_count = views_count + 1 WHERE id = :id
        // L'incrément est atomique côté PostgreSQL — pas de race condition possible.
        $this->threadRepository->createQueryBuilder('t')
            ->update()
            ->set('t.viewsCount', 't.viewsCount + 1')
            ->where('t.id = :id')
            ->setParameter('id', $thread->getId())
            ->getQuery()
            ->execute();
        // Pas de flush() nécessaire : DQL UPDATE contourne l'Unit of Work de Doctrine
        // et exécute la requête immédiatement en base.
    }

    /**
     * Envoie un email de signalement à l'administrateur du forum.
     *
     * Correction 3 — fonctionnalité CDC manquante.
     *
     * Règles métier :
     *   - Un utilisateur NE PEUT PAS signaler son propre thread (vérification dans le controller).
     *   - Le signalement n'est pas persisté en BDD en V1 (pas de nouvelle entité, pas de migration).
     *   - L'email est envoyé via Symfony Mailer (DSN Mailpit en dev, Brevo en prod).
     *
     * En dev : l'email est capturé par Mailpit → http://localhost:8025
     * En prod : Mailpit est remplacé par Brevo ou Resend via MAILER_DSN dans .env
     *
     * @param ForumThread $thread   Le thread signalé
     * @param User        $reporter L'utilisateur qui signale
     */
    public function reportThread(ForumThread $thread, User $reporter): void
    {
        // ── Adresse de destination ────────────────────────────────────────────
        // TODO : déplacer 'admin@bazaart.fr' en paramètre de service (services.yaml)
        // pour pouvoir la modifier sans toucher au code.
        // Exemple dans services.yaml :
        //   parameters:
        //     app.admin_email: '%env(ADMIN_EMAIL)%'
        // Puis dans le constructeur : private readonly string $adminEmail
        // (injecté via $adminEmail: '%app.admin_email%' dans services.yaml)
        $adminEmail = 'admin@bazaart.fr';

        // ── Construction de l'email ───────────────────────────────────────────
        // On utilise symfony/mailer avec Email (email texte simple — pas de template Twig).
        // Pour un email HTML complet, on utiliserait TemplatedEmail (cf. ResourceAlertService).
        $email = (new Email())
            ->from('noreply@bazaart.fr')
            ->to($adminEmail)
            ->subject('[Forum Bazaart] Signalement — ' . $thread->getTitle())
            ->text(implode("\n\n", [
                'Un thread du forum a été signalé.',
                // Titre du thread signalé
                'Sujet    : ' . $thread->getTitle(),
                // Partie locale de l'email de l'auteur (avant le @) — RGPD : pas l'email complet
                'Auteur   : ' . explode('@', $thread->getAuthor()->getEmail())[0],
                // Partie locale du signaleur
                'Signalé par : ' . explode('@', $reporter->getEmail())[0],
                // ID du thread — permet à l'admin de le retrouver facilement
                'ID thread  : #' . $thread->getId(),
                '',
                'Connectez-vous au back-office pour modérer ce sujet.',
            ]));

        // ── Envoi via Symfony Mailer ──────────────────────────────────────────
        // MailerInterface::send() lève une TransportExceptionInterface si l'envoi échoue.
        // En V1 on laisse l'exception remonter — elle sera gérée dans le controller
        // (flash 'error' + redirection) si nécessaire.
        $this->mailer->send($email);
    }
}
