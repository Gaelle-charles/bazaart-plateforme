---
name: feedback_dql_case_orderby
description: CASE ... WHEN en DQL dans orderBy() — syntaxe non standard qui peut échouer selon la version Doctrine/driver
metadata:
  type: feedback
---

ResourceAlertRepository::findAllActive() utilise un CASE SQL brut dans orderBy() via DQL :

```php
->orderBy(
    "CASE a.frequency WHEN 'immediate' THEN 1 WHEN 'daily' THEN 2 ELSE 3 END",
    'ASC'
)
```

Cette syntaxe est une expression SQL native passée à orderBy() DQL, ce qui peut ne pas être supporté ou produire une erreur selon la version de Doctrine ORM. La solution sûre est d'utiliser `->addOrderBy()` avec une `NativeQuery` ou de passer par `->getQuery()->setHint()`, ou plus simplement d'éviter l'ORDER BY sur l'enum et de trier côté PHP après récupération (usort).

**Why:** Signalé lors de la relecture du job d'alertes email (mai 2026). Le tri par fréquence est cosmétique (log lisible), pas fonctionnel — le batch fonctionne dans n'importe quel ordre.

**How to apply:** Signaler comme avertissement à chaque relecture de repository utilisant CASE dans orderBy() DQL. Proposer tri PHP comme alternative robuste.
