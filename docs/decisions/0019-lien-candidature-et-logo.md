# ADR-0019 — Lien de candidature distinct + logo des ressources (avec repli)

- **Date** : 2026-06-19
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Suite de l'enrichissement Ressourcerie (ADR-0016 / ADR-0018). Gaëlle veut :
- distinguer le **lien où l'offre a été trouvée** (source/détail) du **lien pour candidater**
  (souvent un bouton « Candidater / Postuler / Apply » sur la page) ;
- afficher un **logo** sur les ressources pour les enrichir visuellement, avec une chaîne de repli.

## Décision

- **Nouveau champ `applicationUrl`** (Resource + ScrapedResource) : l'URL de candidature, distincte
  de l'URL source. Extraite par le LLM parmi les liens réels de la page (mots-clés
  candidater/postuler/apply/submit/déposer), avec garde anti-hallucination (doit appartenir aux
  liens de la page).
- **Nouveau champ `logoUrl`** : le logo du site, avec **chaîne de repli** :
  1. si `applicationUrl` existe → logo du **site de candidature** ;
  2. sinon → logo du **site de l'offre** ;
  3. sinon → vide → le template affiche un **« B » Bazaart** par défaut.
  Méthode de récupération du logo : `apple-touch-icon` puis `<link rel="icon">` puis `og:image`
  (le plus « logo » d'abord), URL absolue stockée. Qualité variable selon les sites (favicon
  basse résolution, og:image parfois bannière) — on ajustera au rendu.
- **Affichage** : logo sur les **cartes du catalogue** et la **page détail** des ressources.

## Conséquences

- **Modèle** : champs `applicationUrl` (string 500) et `logoUrl` (string 500) nullable sur
  Resource et ScrapedResource + migration ; DTO `ScrapedOpportunity` étendu.
- **Extraction** : LlmExtractorService + OpportunityEnrichmentService — extraire applicationUrl
  (parmi les liens de la page) ; récupérer le logoUrl selon la chaîne de repli (réutiliser
  LinkExtractorService / DomCrawler pour lire les balises icon/og:image ; gardes SSRF + tailles).
- **Propagation** ScrapedResource → Resource (validation admin).
- **Affichage** : cartes + détail ; placeholder « B » Bazaart si pas de logo.
- **Séquencement** : à construire APRÈS le lot ADR-0018 (mêmes fichiers) pour éviter deux agents
  en parallèle sur les mêmes sources.
- **À surveiller** : qualité des logos ; SSRF/tailles sur la récupération d'images ; ne pas
  télécharger l'image (juste stocker l'URL) sauf décision contraire.
