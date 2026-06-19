---
name: feedback_adr0016_lot2_patterns
description: Anti-patterns et points de vigilance détectés lors de la relecture ADR-0016 Lot 2 (masquage deadline + dédup contenu)
metadata:
  type: feedback
---

## Patterns récurrents ADR-0016 Lot 2

### 1. Cohérence liste/compteur hideExpired — bien fait ici
findPublished() et countPublished() partagent buildPublishedQueryBuilder() avec le même $hideExpired.
Pattern correct à reproduire : toute pagination doit passer le même jeu de filtres aux deux méthodes.

**Why:** Un filtre asymétrique (liste filtrée, COUNT non filtré) casse la pagination — totalPages calculé sur plus de résultats que la liste n'en montre.

**How to apply:** À chaque nouveau filtre booléen sur une liste paginée, vérifier qu'il est transmis à la fois à la méthode de liste ET à la méthode de comptage.

---

### 2. normalizeTitle dupliquée en 3 endroits — dette identifiée
ScrapedResourcePersister::normalizeTitle(), ScrapedResourceRepository::normalizeTitle(), ResourceRepository::normalizeTitle() : code identique × 3.
Les trois implémentations sont actuellement synchrones. Risk : divergence future silencieuse.

**Why:** Pas d'extraction en service car 5 lignes de pure transformation de chaîne (choix délibéré documenté). Acceptable en V1, à surveiller.

**How to apply:** Si l'une des trois est modifiée, signaler impérativement les deux autres. Suggérer extraction en `src/Service/TitleNormalizerService.php` lors d'une prochaine session de refactoring.

---

### 3. parseDtoDeadline ne gère pas le format français long
Formats reconnus : ISO (YYYY-MM-DD) et français court (JJ/MM/AAAA).
Format français long ("30 septembre 2026") → retourne null → clé "titleNorm|" → dédup "double null".

**Why:** Le format long est géré par DeadlineParserService après flush, pas avant. La clé de dédup avant flush est donc approximative pour ce format.
Conséquence : deux opportunités avec deadline "30 septembre 2026" et même titre → $deadlineDateDto null des deux côtés → faux positif de dédup (le 2e est skippé + loggué).

**How to apply:** Vérifier si le format long est courant dans les flux traités. Si oui, extraire la logique de DeadlineParserService et l'appeler dans parseDtoDeadline.

---

### 4. contentDedup non affiché dans les commandes ScrapeOpportunitiesCommand et ReadFeedsCommand
PersistResult::$contentDedup est calculé et renvoyé, mais ScrapeOpportunitiesCommand et ReadFeedsCommand ne l'affichent pas dans leur message de sortie (contrairement à ImportGrantCsvCommand qui ne l'affiche pas non plus).

**Why:** Manque de visibilité en production sur le nombre de doublons contenu ignorés.

**How to apply:** Ajouter " | X doublons contenu" dans le sprintf des commandes concernées.

---

### 5. PersistResult::total() n'inclut pas contentDedup
total() = inserted + reactivated + updated + skipped. Les doublons contenu ne sont pas comptabilisés.
Conséquence : total() != count($opportunities) si des doublons contenu ont été ignorés.

**Why:** Incohérence potentiellement source de confusion dans les logs ("X traitées" vs N réellement reçues).

**How to apply:** Documenter explicitement que total() exclut contentDedup, ou l'y inclure.

---

### 6. Timezone PHP non configurée explicitement dans le Dockerfile
Le Dockerfile (docker/php/Dockerfile) ne configure pas date.timezone dans php.ini.
new DateTimeImmutable('today') utilise la timezone système du container (probablement UTC sur Alpine).

**Why:** Sur un serveur en UTC, "today" = minuit UTC. Si les utilisateurs sont à Paris (Europe/Paris, UTC+2), une ressource dont la deadline est 18 juin 2026 disparaît à minuit UTC (2h du matin Paris) alors qu'elle devrait rester visible jusqu'à minuit Paris.

**How to apply:** Ajouter dans le Dockerfile : `RUN echo "date.timezone=Europe/Paris" >> /usr/local/etc/php/conf.d/custom.ini`
OU passer la timezone explicitement dans le code : `new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'))`.
