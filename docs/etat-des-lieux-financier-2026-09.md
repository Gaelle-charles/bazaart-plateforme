# État des lieux financier — Plateforme Bazaart (app.bazaart.fr)

> Date : 29 septembre 2026 · Rédigé à partir de l'analyse du code source (dépôt `bazaart-plateforme`)
> Tous les montants sont **HT**. Conversion utilisée : **1 $ = 0,87 €**.
> Les chiffres marqués ⚠️ reposent sur une hypothèse d'usage : chaque hypothèse est écrite
> à côté du chiffre, pour que le calcul puisse être refait avec vos chiffres réels.

---

## 0. Synthèse (les chiffres à retenir)

| Rubrique | Montant |
|---|---|
| **Valeur de la plateforme actuelle** (coût pour la refaire chez un prestataire) | **104 650 € HT** (fourchette 80 500 – 120 750 €) |
| Coût réellement dépensé pour la développer (outil IA de code) | ≈ 610 – 1 220 € HT + temps interne de Gaëlle |
| **Coût mensuel actuel** (hébergement + services) | **≈ 31 – 90 €/mois** |
| Coût mensuel actuel avec l'outil IA de développement | **≈ 120 – 265 €/mois** |
| **Coût de développement de la V2** (prestataire, avec 15 % de marge pour imprévus) | **109 850 € HT** (fourchette 84 500 – 126 750 €) |
| Coût mensuel V2 — lancement (300 utilisateurs actifs IA) | **≈ 560 €/mois** |
| Coût mensuel V2 — croissance (1 500 utilisateurs actifs IA) | **≈ 2 190 €/mois** |
| Coût mensuel V2 — échelle (5 000 utilisateurs actifs IA) | **≈ 6 750 €/mois** |
| Coût IA par utilisateur actif et par mois (V2) | **≈ 1,20 €** (soit 13 % d'un abonnement à 9 €) |

---

## 1. Ce qui existe aujourd'hui (inventaire du code)

Mesures faites sur le dépôt au 29/09/2026 :

| Indicateur | Valeur |
|---|---|
| Code PHP (Symfony 7.4) | 74 178 lignes, dont **≈ 35 000 lignes de code** hors commentaires et lignes vides |
| Gabarits d'interface (Twig) | 54 224 lignes (≈ 46 000 utiles), 123 gabarits |
| Entités (tables métier) | 47 |
| Contrôleurs / Services / Commandes | 51 / 103 / 23 |
| Migrations de base de données | 50 |
| Tests automatisés | 31 fichiers (9 263 lignes) |
| Décisions d'architecture documentées (ADR) | 37 |

Stack : PHP 8.3, Symfony 7.4, PostgreSQL 16, Redis 7, Docker, Nginx, Stimulus/Turbo,
Tailwind. Services externes : Stripe, Bunny Stream, Google (OAuth, Drive, Sheets), Brevo,
API Mistral (principale) + API Claude (secours) pour la veille.

**Point important pour le dossier :** le cahier des charges V3 (mai 2026) estimait la V1 à
23 j/h (14 950 €). Le périmètre réellement livré est **bien plus large** : il comprend déjà
des briques prévues en V2 (paiement Stripe et abonnements, formations payantes et
reversements aux créateurs, matching artiste-opportunité, veille automatisée enrichie par IA,
espace de gestion de projets). Le chiffrage du CDC n'est donc plus représentatif.

---

## 2. Valeur de la plateforme actuelle

### 2.1 Méthode

La plateforme a été développée en interne avec un outil IA de code : il n'y a pas de facture
de prestataire. On la valorise donc par son **coût de remplacement** : combien coûterait-elle
si on la faisait développer à l'identique par un prestataire ?

- Unité : le jour/homme (j/h) d'un développeur Symfony confirmé.
- Taux journalier (TJM) : **650 € HT**, le même que dans le cahier des charges V3.
  Fourchette : 500 € (freelance) à 750 € (agence).

### 2.2 Chiffrage par module

| # | Module (ce qui est en production) | j/h | Montant (650 €/j) |
|---|---|---:|---:|
| 1 | Socle technique : Docker, déploiement, sécurité (CSRF, rate limiting, en-têtes, SSRF), RGPD (export, suppression), pages légales | 10 | 6 500 € |
| 2 | Comptes et accès : inscription, confirmation email, mot de passe oublié, connexion Google, rôles, onboarding, profils artiste et structure, annuaire | 12 | 7 800 € |
| 3 | Ressourcerie : catalogue, filtres (discipline, localisation, niveau), favoris, alertes email, soumission et validation par les structures, dashboard structure | 15 | 9 750 € |
| 4 | Veille automatisée + IA : 10 scrapers dédiés, flux RSS, extraction par IA, enrichissement, découverte de sources, dédoublonnage (pg_trgm), dates limites, logos, import CSV / Google Sheets, admin des sources | 25 | 16 250 € |
| 5 | Matching artiste ↔ opportunité (swipe, formulaire, freemium 3 consultations / semaine) | 8 | 5 200 € |
| 6 | Communauté : forum (images, réactions, charte), messagerie privée, notifications, fil de publications, likes, commentaires, modération | 18 | 11 700 € |
| 7 | Lives : planification, inscriptions, rappels | 4 | 2 600 € |
| 8 | Formation : catalogue, modules et leçons, vidéo Bunny, progression, propositions des membres, formations-événements payantes, inscriptions et annulations, reversements aux créateurs | 20 | 13 000 € |
| 9 | Paiement : Stripe, abonnements, essai gratuit, paywall, webhooks, paiement des formations | 8 | 5 200 € |
| 10 | Blog / articles et pages vitrine | 5 | 3 250 € |
| 11 | Back-office admin : tableau de bord, réglages, validations, modération | 10 | 6 500 € |
| 12 | Espace projets interne : Kanban, calendrier, vues, notes signées, Google Drive, import, récapitulatif | 14 | 9 100 € |
| 13 | Conception, pilotage, cahier des charges, 37 ADR, tests, recette, documentation | 12 | 7 800 € |
| | **Total** | **161 j/h** | **104 650 € HT** |

Fourchette selon le TJM : **80 500 € HT** (500 €/j) à **120 750 € HT** (750 €/j).

**Contrôle croisé :** ≈ 81 000 lignes utiles (PHP + Twig). Avec une productivité courante de
400 à 500 lignes finies par jour, cela représente 160 à 200 j/h. Les 161 j/h retenus sont
donc une estimation **prudente**.

### 2.3 Ce que le développement a réellement coûté en argent

| Poste | Hypothèse | Montant |
|---|---|---|
| Outil IA de code (abonnement Claude Max) | 7 mois (mars à septembre 2026), à 100 $ ou 200 $/mois | ≈ 610 – 1 220 € HT |
| Hébergement pendant le développement | ≈ 20 – 35 €/mois × 7 mois | ≈ 140 – 245 € |
| Temps de Gaëlle | à valoriser : **nombre de jours passés × coût journalier chargé** | à compléter |

⚠️ Remplacez les deux premières lignes par les montants de vos factures réelles.

> Comptabilité : une association ou une société peut, sous conditions, inscrire ces frais de
> développement à l'actif comme **immobilisation incorporelle** (logiciel créé en interne,
> PCG art. 212-3). À valider avec votre expert-comptable.

---

## 3. Coût mensuel actuel (V1 en production)

| Poste | Détail | Coût mensuel |
|---|---|---:|
| Serveur DigitalOcean | Droplet Basic 4 Go / 2 vCPU (24 $). **Partagé avec la vitrine bazaart.fr** | 21 € |
| Sauvegardes du serveur | Option hebdomadaire DigitalOcean (+20 %) | 4 € |
| Stockage des sauvegardes de la base | DigitalOcean Spaces 250 Go (5 $) | 4 € |
| Nom de domaine | ≈ 15 €/an | 1 € |
| Certificat SSL | Let's Encrypt | 0 € |
| Emails transactionnels | Brevo : gratuit jusqu'à 300/jour, puis offre payante | 0 – 25 € |
| Vidéo des formations | Bunny Stream, facturé au volume | 5 – 30 € |
| IA de la veille ⚠️ | Mistral Small, avec Claude Haiku en secours (≈ 12 sources analysées par IA 3×/semaine, ≈ 300 nouvelles opportunités enrichies par mois) | 1 – 10 € |
| API Google (connexion, Drive, Sheets) | Quotas gratuits | 0 € |
| Supervision (Sentry, UptimeRobot) | Offres gratuites | 0 € |
| **Sous-total hébergement + services** | | **≈ 31 – 90 €/mois** |
| Outil IA de développement | Claude Max, 100 $ ou 200 $/mois, si le développement continue | 87 – 174 € |
| **Total avec l'outil de développement** | | **≈ 120 – 265 €/mois** |
| Stripe (variable) | ≈ 1,5 % + 0,25 € par paiement par carte européenne | selon le chiffre d'affaires |

**Recommandé mais absent aujourd'hui :** un serveur de préproduction (staging), 2 Go, 12 $,
soit **+10 €/mois**.

**Coût complet (non décaissé si fait en interne) :** la maintenance corrective et les mises à
jour de sécurité représentent 2 à 4 j/h par mois, soit **1 300 – 2 600 €/mois** en
équivalent prestataire.

---

## 4. Version 2 : périmètre et coût de développement

### 4.1 Périmètre V2

1. **Veille augmentée** : une IA qui découvre de nouvelles sources d'opportunités par
   recherche web, et qui extrait **toutes** les informations utiles de chaque opportunité :
   type (aide, bourse, résidence, prix…), montant, critères d'éligibilité, calendrier,
   **pièces à fournir**, contacts. Elle lit aussi les règlements en PDF et surveille les
   changements.
2. **Gestion de projets et de candidatures** pour les artistes et les structures, sur la
   plateforme : projets, tâches, échéances, pièces, suivi de chaque candidature, travail en
   équipe pour les structures.
3. **Accompagnement intelligent** : liste automatique des documents et éléments à fournir
   pour chaque candidature, contrôle de complétude du dossier, aide à la rédaction (note
   d'intention, biographie, budget prévisionnel, lettre de motivation), relecture.
4. **Assistant conversationnel (chatbot RAG)** pour les utilisateurs. Il répond à partir de
   la base des opportunités, des guides Bazaart et des documents de l'utilisateur, en citant
   ses sources.
5. **Analyse des projets** : l'IA de la plateforme détecte les éléments de chaque projet
   (discipline, besoins, budget, calendrier) pour proposer les opportunités compatibles et
   signaler ce qui manque.

### 4.2 Chiffrage du développement V2

Le chiffrage tient compte de ce qui existe déjà et sera réutilisé : pipeline de veille,
espace projets interne, matching, paiement.

| # | Lot | j/h | Montant (650 €/j) |
|---|---|---:|---:|
| 1 | Socle IA commun : plusieurs fournisseurs IA, choix du modèle selon la tâche, quotas par utilisateur, suivi des coûts, cache, journalisation, file d'attente et traitements en arrière-plan | 10 | 6 500 € |
| 2 | Veille : agent de découverte de nouvelles sources (recherche web, score de pertinence, validation admin) | 12 | 7 800 € |
| 3 | Veille : extraction complète (≈ 30 champs, lecture des PDF, pièces à fournir, éligibilité, indice de confiance, re-vérification et détection des changements) | 15 | 9 750 € |
| 4 | Base de connaissances RAG : pgvector, découpage et indexation (opportunités, guides, documents utilisateurs), recherche hybride | 10 | 6 500 € |
| 5 | Chatbot : interface avec réponse en direct, historique, citations, limites freemium, retours utilisateurs | 10 | 6 500 € |
| 6 | Gestion de projets et de candidatures pour les membres : adaptation de l'espace projets interne (espaces séparés par membre ou structure, équipes, suivi des candidatures lié aux opportunités, tâches, échéances, rappels, calendrier) | 22 | 14 300 € |
| 7 | Accompagnement intelligent : liste automatique des pièces, contrôle de complétude, aide à la rédaction et relecture, export PDF / Word | 20 | 13 000 € |
| 8 | Analyse des projets : détection des éléments du projet, opportunités compatibles, score d'éligibilité, alertes | 8 | 5 200 € |
| 9 | Stockage sécurisé des documents : Spaces, chiffrement, antivirus, quotas | 5 | 3 250 € |
| 10 | Conformité : RGPD et AI Act (transparence, consentement, registre, export et suppression des données IA) | 5 | 3 250 € |
| 11 | Design UX/UI des nouveaux parcours | 10 | 6 500 € |
| 12 | Tests, recette, évaluation de la qualité de l'IA (extraction, chatbot), sécurité (injection de prompt), documentation | 12 | 7 800 € |
| 13 | Pilotage de projet | 8 | 5 200 € |
| | **Sous-total** | **147 j/h** | **95 550 €** |
| | Marge pour imprévus (15 %) | 22 j/h | 14 300 € |
| | **Total V2** | **169 j/h** | **109 850 € HT** |

Fourchette selon le TJM (marge incluse) : **84 500 € HT** (500 €/j) à **126 750 € HT** (750 €/j).

**Durée estimée :** 7 à 9 mois pour un développeur à temps plein chez un prestataire,
4 à 6 mois en interne avec un outil IA de code.

**Coût décaissé si la V2 est développée en interne (6 mois) :**

| Poste | Montant |
|---|---|
| Outil IA de code | 6 × 87 – 174 € = 520 – 1 045 € |
| Consommation d'API IA pendant le développement et les tests | 6 × 50 – 150 € = 300 – 900 € |
| Serveur de préproduction | 6 × 10 € = 60 € |
| **Total décaissé** | **≈ 880 – 2 000 € HT** + temps interne |

---

## 5. Coût mensuel de la V2 en production

Le coût mensuel de la V2 dépend surtout de l'usage de l'IA. Il se compose de trois parties :
**(A)** l'IA utilisée par la plateforme elle-même (coût presque fixe), **(B)** l'IA utilisée
par les membres (coût proportionnel au nombre d'utilisateurs actifs), **(C)** l'hébergement
et les services.

### 5.1 Tarifs des modèles IA utilisés

| Modèle | Usage prévu | Entrée ($ / million de tokens) | Sortie ($ / million de tokens) |
|---|---|---:|---:|
| Mistral Small ⚠️ | Veille en masse (lecture des pages) | ≈ 0,10 | ≈ 0,30 |
| Claude Haiku 4.5 | Veille, vérifications, chatbot en mode économique | 1,00 | 5,00 |
| Claude Sonnet 5.5 | Extraction complète, chatbot, aide à la rédaction, découverte de sources | 2,00 | 10,00 |
| Claude Opus 5.5 | Analyses complexes (usage ponctuel) | 4,00 | 20,00 |
| Embeddings (Mistral Embed ou Voyage) ⚠️ | Indexation RAG | ≈ 0,02 – 0,12 | — |

Réductions prises en compte : traitement différé (Batch) **−50 %** pour la veille ; lecture en
cache facturée **10 %** du prix normal pour le chatbot. Recherche web : **≈ 10 $ les 1 000
recherches**.

### 5.2 (A) L'IA de la plateforme : veille, découverte, analyse (coût presque fixe)

| Traitement | Hypothèse de volume ⚠️ | Calcul | Coût mensuel |
|---|---|---|---:|
| Veille quotidienne | 150 sources, 4 500 pages/mois, 15 000 tokens lus + 1 000 écrits par page | Mistral Small : 8 $ · Haiku en Batch : 45 $ | 7 – 39 € |
| Extraction complète des nouvelles opportunités | 600/mois, 40 000 tokens lus (page + PDF) + 4 000 écrits | Sonnet 5.5 en Batch : 24 $ + 12 $ | 31 € |
| Découverte de nouvelles sources | 30 recherches par mois, 40 requêtes web chacune, 400 000 tokens lus + 20 000 écrits | 12 $ (web) + 24 $ + 6 $ | 37 € |
| Re-vérification (dates, changements) | 1 500 opportunités, 8 000 tokens lus + 1 000 écrits | Haiku en Batch : 6 $ + 4 $ | 9 € |
| Indexation RAG (embeddings) | 5 millions de tokens | ≈ 0,5 $ | 1 € |
| **Total IA plateforme** | | | **≈ 85 – 120 €/mois** |

### 5.3 (B) L'IA des membres : coût par utilisateur actif et par mois

| Usage | Hypothèse par utilisateur actif ⚠️ | Coût par mois |
|---|---|---:|
| Chatbot RAG | 30 messages/mois ; 10 000 tokens lus (dont la moitié en cache) + 700 écrits ; Sonnet 5.5 | 0,47 € (0,23 € avec Haiku) |
| Accompagnement des dossiers | 2 dossiers/mois ; pour chacun, 1 analyse des pièces + 8 aides à la rédaction (≈ 126 000 tokens lus + 14 000 écrits) ; Sonnet 5.5 | 0,68 € |
| Analyse des projets et matching | 4 analyses/mois ; Haiku 4.5 | 0,04 € |
| **Total par utilisateur actif** | | **≈ 1,20 €** (0,95 € en mode économique ; ≈ 3,50 € pour un utilisateur intensif) |

Sans limite d'usage, un petit nombre d'utilisateurs intensifs peut faire grimper la facture.
Il faut donc **plafonner l'IA par formule** (gratuit / abonné / structure). La plateforme a
déjà un mécanisme de ce type avec le freemium du matching.

### 5.4 (C) Hébergement DigitalOcean et services, selon la taille

| Poste | Lancement (≤ 1 000 inscrits) | Croissance (≤ 5 000) | Échelle (≥ 10 000) |
|---|---|---|---|
| Serveur(s) application | 1 × 8 Go / 4 vCPU : 42 € | 1 × 8 Go (app) + 1 × 4 Go (tâches IA) : 63 € | 2 × 8 Go + répartiteur de charge + 1 × 8 Go (tâches IA) : 136 € |
| Base PostgreSQL + pgvector | sur le serveur : 0 € | base gérée 4 Go : 52 € | base gérée 8 Go + secours : 209 € |
| Stockage (documents, sauvegardes) | Spaces : 4 € | 9 € | 13 € |
| Sauvegardes des serveurs | 8 € | 13 € | 25 € |
| Serveur de préproduction | 10 € | 10 € | 10 € |
| Emails (Brevo) | 0 – 9 € | 25 € | 65 € |
| Vidéo (Bunny Stream) | 30 € | 80 € | 150 € |
| Supervision (Sentry) | 0 € | 23 € | 23 € |
| Nom de domaine | 1 € | 1 € | 1 € |
| **Sous-total (C)** | **≈ 100 €** | **≈ 276 €** | **≈ 632 €** |

### 5.5 Coût mensuel total V2, par scénario

| | Lancement | Croissance | Échelle |
|---|---:|---:|---:|
| Utilisateurs actifs IA par mois | 300 | 1 500 | 5 000 |
| (A) IA plateforme | 100 € | 110 € | 120 € |
| (B) IA des membres (1,20 € × utilisateurs) | 360 € | 1 800 € | 6 000 € |
| (C) Hébergement et services | 100 € | 276 € | 632 € |
| **Total mensuel** | **≈ 560 €** | **≈ 2 190 €** | **≈ 6 750 €** |
| **Total annuel** | **≈ 6 700 €** | **≈ 26 200 €** | **≈ 81 000 €** |
| Abonnés à 9 €/mois nécessaires pour couvrir ces coûts | ≈ 63 | ≈ 243 | ≈ 750 |

À ajouter selon les cas : l'outil IA de développement (87 – 174 €/mois), les frais Stripe
(≈ 1,5 % + 0,25 € par paiement) et la maintenance (2 – 4 j/h par mois en équivalent
prestataire).

**Leviers d'économie (−30 à −50 % sur l'IA) :** utiliser Haiku pour les questions simples du
chatbot, mettre en cache les consignes et le contexte, traiter la veille en Batch, utiliser
Mistral pour la lecture en masse, plafonner l'usage par formule.

---

## 6. Hypothèses et limites

- **Tarifs :** les prix Claude sont à jour au 25/09/2026. Les prix DigitalOcean, Mistral,
  Brevo, Bunny et Sentry sont ceux des grilles publiques connues, sans vérification en ligne
  possible le jour de la rédaction. **Vérifiez-les avant de signer le dossier.**
- **Taux de change :** 1 $ = 0,87 €. Une variation de 5 % du dollar fait varier d'environ
  5 % tous les postes facturés en dollars (DigitalOcean, API IA).
- **Volumes :** les volumes d'usage (⚠️) sont des estimations raisonnables pour une
  plateforme de ce type. Il faudra les remplacer par les mesures réelles dès que le suivi des
  coûts IA (lot V2 n°1) sera en place.
- **« Utilisateur actif IA » :** un membre qui utilise au moins une fonction IA dans le mois.
  Ce n'est pas le nombre total d'inscrits.
- **Hébergement des données :** pour le RGPD, choisir une région DigitalOcean européenne
  (Francfort ou Amsterdam), privilégier Mistral (hébergé dans l'UE) pour les données
  sensibles, et signer les accords de traitement des données (DPA) avec chaque fournisseur
  d'IA.
- **Valeur ≠ prix de vente :** la valeur donnée ici est un **coût de remplacement**. Un prix
  de cession ou une valorisation d'entreprise dépendrait aussi des utilisateurs, des revenus
  et des partenariats.
