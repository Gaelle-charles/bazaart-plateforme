---
name: feedback-permissions-antipatterns
description: Anti-patterns récurrents sur les restrictions de permissions contrôleur/Twig dans Bazaart
metadata:
  type: feedback
---

Patterns à contrôler lors de toute relecture d'une restriction d'accès (IsGranted, is_granted) :

1. **Service contourne le contrôleur** — Quand un contrôleur est restreint à ROLE_ADMIN, vérifier si des services appelés depuis ce contrôleur contiennent eux-mêmes une vérification d'autorisation plus permissive. Ex : ArticleController::delete() est restreint ROLE_ADMIN, mais ArticleService::deleteArticle() accepte aussi "$isAuthor" (auteur non-admin). Si une autre route sans #[IsGranted] appelait ce service, un non-admin pourrait supprimer. Toujours vérifier que la permission est portée par le contrôleur ET non contredite par le service.

2. **Cohérence condition brouillon dans show()** — Quand on restreint la publication aux admins, la route show() peut exposer une logique résiduelle "l'auteur peut voir son brouillon". Si un non-admin a créé un article avant la restriction, il peut encore accéder à /articles/{slug} en lecture brouillon. C'est souvent voulu (prévisualisation) mais à noter explicitement dans la review.

3. **in_array() sur getRoles() vs hiérarchie Symfony** — ArticleService::deleteArticle() utilise `in_array('ROLE_ADMIN', $user->getRoles())` pour vérifier admin, sans passer par la hiérarchie de rôles Symfony (security.yaml). Cela signifie que ROLE_SUPER_ADMIN ou tout rôle héritant de ROLE_ADMIN via la hiérarchie ne serait pas reconnu. Préférer l'injection de AuthorizationCheckerInterface et appeler $authChecker->isGranted('ROLE_ADMIN').

4. **Liens dans les commentaires de template vs routes réelles** — Les commentaires d'en-tête des templates (.html.twig) listent parfois des routes (ex: "app_resource_my") qui ne sont plus accessibles depuis cette vue. Après une restriction, mettre à jour ces commentaires pour éviter la confusion.

5. **Commentaire docblock orphelin après retrait du #[IsGranted] de classe** — Quand on retire #[IsGranted] au niveau classe pour rendre des routes publiques, des commentaires dans les méthodes mentionnent encore "complète la restriction de classe" alors qu'il n'y a plus de restriction de classe. Le commentaire devient faux et trompeur pour le futur développeur. Toujours mettre à jour les docblocks en même temps.

6. **Route show avec priority:-1 + slug regex vs routes fixes** — Les routes fixes sans paramètre (ex: `/articles/new`, `/articles/my`) ont priority=0 par défaut et sont donc résolues AVANT `/articles/{slug}` (priority=-1). La sécurité d'une méthode admin ne dépend donc pas du fait que slug="new" matche le regex `[a-z0-9-]+`. Mais vérifier toujours que priority:-1 est bien présent sur la route à paramètre, sans quoi l'ordre de déclaration dans le fichier devient le seul arbitre.

7. **Comparaison d'identité d'entité Doctrine via ===** — Dans show(), `$draft->getAuthor() !== $currentUser` compare l'auteur (chargé en lazy si findOneBy sans jointure) avec l'objet User du token Symfony (getUser()). Si Doctrine recharge l'entité User depuis un cache vidé, ces deux instances peuvent différer, rendant la comparaison === fausse même pour le bon utilisateur. Préférer `$draft->getAuthor()->getId() !== $currentUser->getId()` pour une comparaison robuste.

**Why:** Points 1-4 identifiés lors de la relecture du lot restrictions permissions articles/ressources (2026-06-13). Points 5-7 identifiés lors de la relecture blog public / SEO (2026-06-15).
**How to apply:** Vérifier ces 7 points sur toute PR qui ajoute ou modifie des #[IsGranted] sur des contrôleurs existants, et en particulier sur toute PR qui retire un #[IsGranted] de classe pour rendre des routes publiques.
