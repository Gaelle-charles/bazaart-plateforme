---
name: project-legal-reminder
description: Rappel obligatoire avant lancement — remplir les placeholders des pages légales RGPD
metadata:
  type: project
---

À faire AVANT le lancement du 2026-06-15 : remplir les placeholders dans les pages légales.

**Why:** Les pages `/confidentialite`, `/cgu` et `/mentions-legales` contiennent des marqueurs `[À COMPLÉTER — ...]` qui doivent être remplacés par les vraies informations de l'association avant la mise en ligne. Obligation légale (RGPD art. 13-14, LCEN art. 6-III).

**How to apply:** Rappeler à Gaëlle systématiquement si elle parle de mise en production, de déploiement ou de lancement. Bloquer le go-live si ces champs ne sont pas remplis.

**Fichiers concernés :**
- `app/templates/legal/privacy.html.twig` — raison sociale, SIRET, adresse, DPO/responsable traitement
- `app/templates/legal/cgu.html.twig` — mêmes infos
- `app/templates/legal/mentions.html.twig` — mêmes infos + directeur de publication

**Informations à demander à Gaëlle :**
1. Raison sociale officielle de l'association Bazaart
2. Numéro SIRET
3. Adresse postale du siège social
4. Nom du responsable du traitement / DPO
5. Directeur de publication (pour les mentions légales LCEN)
