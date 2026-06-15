---
name: project-seo-todo
description: Chantier SEO technique complémentaire (sitemap, robots.txt, JSON-LD) planifié pour le 19 ou 20 juin 2026
metadata:
  type: project
---

# SEO technique complémentaire du blog — planifié le 2026-06-19 ou 2026-06-20

**Fait :** chantier SEO du blog en 3 volets, déployé le 2026-06-15 :
- A. Blog rendu PUBLIC (lecture sans connexion, indexable) ; publication restée admin-only ; brouillons invisibles.
- B. Balises meta : `meta description`, Open Graph, `canonical` (dans `base.html.twig` + `article/show.html.twig`).
- C. Réécriture SEO des 4 articles (titres optimisés, structure H2, maillage interne, ~2300 à 3400 car.).

**À faire le 19 ou 20 juin (décidé par Gaëlle le 2026-06-15) :**
1. **`sitemap.xml`** : lister les URLs publiques (page d'accueil, `/articles`, chaque fiche article publiée). Idéalement un contrôleur dynamique (route `/sitemap.xml`) qui interroge `ArticleRepository::findPublished()`.
2. **`robots.txt`** : autoriser le crawl des pages publiques, pointer vers le sitemap (`Sitemap: https://app.bazaart.fr/sitemap.xml`). Attention : ne PAS exposer `/admin`, `/articles/new`, etc.
3. **Données structurées JSON-LD** (`schema.org/Article`) dans `templates/article/show.html.twig` (headline, image, datePublished, author, publisher) pour les « rich results » Google.

**Why:** maximiser l'indexation et la visibilité du blog public.
**How to apply:** rappeler/proposer ce chantier à Gaëlle autour du 19-20 juin. Voir [[project-v1-status]] (deadline V1 = 22 juin).
