# ADR-0018 — Extraction enrichie : comment candidater, financement, description complète et structurée

- **Date** : 2026-06-19
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Suite de l'enrichissement de la Ressourcerie (ADR-0016). Gaëlle veut que le scraping fasse
remonter davantage d'informations exploitables sur chaque opportunité, et les afficher en bonne
place sur la page détail :
- **Comment candidater** : les étapes à suivre, si elles figurent.
- **Le financement** : le montant et sa nature.
- **Une description complète et structurée**, obligatoirement produite par le LLM.

## Décision

Ajout à l'extraction LLM (et à l'affichage) de :
- `howToApply` (texte) : les étapes/modalités de candidature.
- `fundingAmount` (texte) : le montant (ex. « 5 000 € », « jusqu'à 10 000 € », « non précisé »).
- `fundingType` (texte) : la nature du soutien financier (bourse en argent, prix, prise en
  charge de frais, etc.).
- **Description** : le prompt LLM doit **obligatoirement** produire une description **complète
  et structurée** (sections : présentation, objectifs, éligibilité, comment candidater, montant).

**Affichage** : montant + comment candidater mis **en tête** de la page détail de la ressource
(avant le reste), description structurée affichée clairement.

**Reporté (hors de ce lot, décision Gaëlle)** : le scraping des documents téléchargeables
(PDF / DOC / DOCX) sur la page de l'opportunité. À traiter dans un lot ultérieur (nécessitera
une dépendance de parsing PDF type `smalot/pdfparser`, à arbitrer à ce moment-là). Le champ
`documents` de `ScrapedResource` existe déjà pour stocker les liens le moment venu.

## Conséquences

- **Modèle** : champs `howToApply`, `fundingAmount`, `fundingType` sur `Resource` et
  `ScrapedResource` (nullable) + migration ; DTO `ScrapedOpportunity` étendu.
- **Extraction** : `LlmExtractorService` ET `OpportunityEnrichmentService` (import CSV) — prompts
  enrichis pour sortir ces champs ET une description complète/structurée. Troncature défensive
  aux longueurs de colonnes.
- **Propagation** ScrapedResource → Resource (validation admin).
- **Affichage** : `resource/show.html.twig` — montant + comment candidater en tête.
- **Séquencement (Option A)** : construire + déployer ces champs AVANT de lancer le scraping de
  prod, pour que les opportunités sortent complètes en une seule passe (évite un re-scrape et le
  double coût Mistral).
- **À surveiller** : qualité/longueur de la description (le LLM doit rester factuel, pas
  d'hallucination) ; coût LLM (description plus longue = plus de tokens).
