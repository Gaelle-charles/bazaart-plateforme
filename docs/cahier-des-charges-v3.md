📘 CAHIER DES CHARGES — PLATEFORME BAZAART (V3 — final)
Document de validation à destination du comité de décision
Maître d'ouvrage : Bazaart (Reines Des Temps Modernes)
Référente projet : Gaëlle Charles-Belamour, Co-fondatrice — Pôle Lab
Comité de décision : Wendie Zahibo, Laëtitia Charles-Belamour, Laure
Version : 3.0 — Mai 2026 (révisée après cadrage Studio IA × Goumies_créatives)
Statut : Document soumis à validation urgente — délai 15 mai 2026

0. Synthèse exécutive
Bazaart lance officiellement sa plateforme bazaart.fr le 15 juin 2026 lors d'une soirée organisée à l'occasion de la clôture de l'incubation Mansa. Cette soirée marque l'entrée en phase opérationnelle de la plateforme et la sortie d'incubation de l'association.
Le déploiement se fait en trois temps :

V1 livrable au 15 juin : Ressourcerie + Communauté + Formation, 3 modules cœur,
V2 sur juillet–septembre 2026 : Studio IA (en collaboration avec Goumies_créatives) + Billetterie + Lives natifs + évolutions Formation,
V3 à partir d'octobre 2026 : Module Archivage après cadrage Wendie × Felix.

Délai critique : 38 jours calendaires entre la validation de ce document et la livraison de la V1.
Charge V1 estimée : 23 jours/homme, soit une marge de sécurité d'environ 2 à 3 jours sur les 25 jours ouvrés disponibles.

1. Contexte et finalité
Bazaart est une association culturelle pluridisciplinaire basée à Baie-Mahault (Guadeloupe), dédiée à la structuration des Industries Culturelles et Créatives (ICC) de la Caraïbe française et au soutien des artistes de la diaspora afro-atlantique. L'association s'organise autour de trois pôles complémentaires — Events, Studio et Lab — chacun portant une mission distincte.
La plateforme bazaart.fr constitue la quatrième brique stratégique : un dispositif digital qui prolonge, fédère et amplifie l'action des trois pôles physiques.
Pourquoi maintenant. Aucune plateforme structurante n'existe aujourd'hui pour les artistes de la diaspora afro-atlantique sur l'axe Caraïbe française – Hexagone – Afrique. Bazaart est en position légitime pour combler ce vide grâce à son ancrage territorial, à son réseau de partenaires institutionnels (Mansa, MACTe, AFD, DAC Guadeloupe, Institut Français, CNM, Caisse des dépôts) et à l'expertise de ses co-fondatrices.
Pourquoi le 15 juin. La date coïncide avec la fin de l'incubation Mansa. Ce calendrier consolide la sortie d'incubation par un livrable concret, maximise la visibilité institutionnelle, et s'aligne avec les candidatures en cours (Prix IFCIC, BPI) qui valorisent une plateforme déjà en production.

2. Vision et positionnement
La plateforme bazaart.fr ambitionne de devenir le premier hub digital structurant dédié aux artistes de la diaspora afro-atlantique. Sa singularité repose sur quatre piliers :
Hybride physique–digital. La plateforme prolonge en continu les actions des trois pôles (ressourcerie alimentée par la veille du Lab, formations issues du Studio, événements et communauté nourris par les Events).
Innovation par les agents IA. L'agent IA de veille (déjà en production) automatise le sourcing d'opportunités. À partir de la V2, chaque membre disposera d'un agent IA personnel — assistant de carrière développé en collaboration avec Goumies_créatives. Ce dispositif est, à notre connaissance, inédit dans le secteur culturel francophone.
Souveraineté et éthique des données. Hébergement européen, conformité RGPD native, contrôle total sur les données des artistes — point de différenciation fort face aux plateformes US.
Communauté et entre-pairs. La plateforme intègre forum, messagerie, et planification de lives entre artistes pour créer un véritable lieu de vie professionnel.

3. Publics cibles
Cible primaire — Artistes individuels. Plasticien·nes, musicien·nes, auteur·es, photographes, performers, designers de la diaspora afro-atlantique. Profils émergents à confirmés cherchant structuration, opportunités, formation et visibilité.
Cible secondaire — Structures culturelles. Associations, collectifs, festivals, galeries, lieux de diffusion, institutions de formation, centres de résidence. Elles peuvent créer un compte structure pour publier leurs propres opportunités.
Cible tertiaire — Communauté élargie. Passionnés, mécènes, étudiants, professionnels du secteur culturel.
Cible institutionnelle — Décideurs et bailleurs. Mansa, AFD, DAC, IFCIC, Conseils Régional et Départemental, CNM, Institut Français.

4. Périmètre fonctionnel V1 — Livrable 15 juin 2026
Module 1 — Ressourcerie
Finalité. Centraliser et rendre accessibles les opportunités utiles aux artistes : aides financières, appels à projet, appels à résidence, tutorat, mentorat, bourses, prix, formations externes.
Trois logiques d'alimentation.

Administrateurs Bazaart : CRUD complet sur toutes les opportunités, validation, modération.
Comptes Structures : création de compte dédié, CRUD sur leurs propres opportunités sans validation préalable (auto-publication).
Artistes membres : peuvent soumettre une opportunité repérée. Soumission soumise à validation admin avant publication.

Fonctionnalités V1.

Catalogue filtrable (discipline, type d'opportunité, géographie, public éligible, deadline).
Fiche opportunité détaillée (description, conditions, lien externe, contact, dates clés).
Système de favoris pour les membres.
Alertes par email sur les nouvelles opportunités matchant le profil.
Tableau de bord structure dédié.
Workflow de validation pour les soumissions artistes.
Agent IA de scraping multi-sources existant (7 scrapers actifs : ADAGP, CNAP, CNM, Culture.gouv, Musiques Actuelles, Pro Helvetia, SAIF) — maintenu et intégré.

État actuel. Module à environ 75 %. Reste à développer : compte structure dédié, dashboard structure, soumission artiste avec validation, alertes email personnalisées.
Charge restante : 5 jours/homme.

Module 2 — Communauté
Finalité. Créer un espace de vie entre artistes : entraide, échanges, événements en ligne, networking. Moteur d'engagement de la plateforme.
Fonctionnalités V1.

Hub social existant : posts courts, commentaires, likes, articles longs, annuaire des artistes.
Forum thématique : catégories créées par les admins, threads par les membres, réponses, modération admin.
Messagerie privée 1-à-1 entre membres.
Planification de lives : un membre crée un événement live (titre, description, date, heure, lien externe Twitch / Jitsi / Meet), les autres s'inscrivent et reçoivent un rappel, un replay peut être uploadé manuellement après le live.
Notifications in-app et email pour mentions, réponses, messages privés, nouveaux lives.

Périmètre exclu V1 (renvoyé en V2). Live streaming natif intégré, enregistrement automatique des lives, chat live propriétaire.
État actuel. Module à environ 50 %. Posts/articles/annuaire faits. Reste forum, messagerie, notifications, planification de lives.
Charge restante : 8 jours/homme.

Module 3 — Formation
Finalité. Donner aux membres l'accès aux formations Bazaart × Studio sous format vidéo en ligne.
Logique éditoriale V1. Seuls les administrateurs créent et publient les formations.
Fonctionnalités V1.

Catalogue de formations admin-only.
Organisation en parcours (modules → leçons).
Lecture vidéo intégrée (Bunny Stream recommandé, ou Vimeo Pro / YouTube unlisted en iframe en solution dégradée).
Suivi de progression individuel.
Espace ressources téléchargeables associées (PDF, templates).
Système d'inscription (gratuit, ou inclus dans abonnement à partir de la V2).

Périmètre exclu V1 (renvoyé en V2). Quiz d'évaluation, attestations PDF générées automatiquement, live formation intégré, paiement à l'unité.
État actuel. Non démarré.
Charge : 6 jours/homme.

Module 4 — Dashboard d'administration (transverse)
Finalité. Donner aux équipes Bazaart un outil de pilotage centralisé.
Fonctionnalités V1.

Gestion des utilisateurs (recherche, modification, suspension, attribution des rôles dont rôle "structure").
Validation des opportunités soumises (artistes) et scrapées (agent IA).
Modération communauté (signalements, suppressions).
Création et gestion des formations.
Gestion du blog et de la vitrine.
Statistiques d'usage de base.

État actuel. Bases en place. À enrichir au fur et à mesure que les nouveaux modules avancent.
Charge intégrée dans les modules concernés.

5. Périmètre fonctionnel V2 — Post-lancement (juillet–septembre 2026)
Module 5 — Studio IA (collaboration Bazaart × Goumies_créatives)
Finalité. Mettre à disposition de chaque membre un agent IA personnel pour l'accompagner dans le développement de son activité.
Modalités de collaboration. Le module sera développé conjointement avec Goumies_créatives. Les rôles, modèles économiques associés et planning précis seront définis dans une convention dédiée à signer avant le démarrage du développement.
Fonctionnalités prévues.

Interface conversationnelle dédiée par utilisateur, mémoire persistante.
Architecture RAG donnant accès à la base ressources, aux formations et au profil utilisateur.
Cas d'usage : aide à la rédaction de candidatures, recherche d'opportunités matchant le profil, suggestions de formations, structuration administrative.
Limites d'usage configurables selon abonnement.

Charge estimée : 8 à 12 jours/homme côté Bazaart (à préciser selon répartition Goumies_créatives).

Module 6 — Billetterie événements (style HelloAsso, admin-only)
Finalité. Module de billetterie pour les événements physiques organisés par Bazaart, géré uniquement par les administrateurs.
Fonctionnalités prévues.

Création d'événements admin-only.
Plusieurs catégories de billets (gratuit, plein tarif, tarif réduit, membre Bazaart).
Paiement en ligne via Stripe avec option de don supplémentaire.
Génération automatique de billets PDF avec QR code.
Application admin de scan QR le jour J.
Gestion des remboursements et exports comptables.

Charge estimée : 8 à 12 jours/homme.

Module 7 — Lives natifs intégrés
Finalité. Faire évoluer la planification de lives V1 vers une expérience native sur la plateforme.
Fonctionnalités prévues. Lecteur live intégré (LiveKit / Mux / OvenMediaEngine selon arbitrage budgétaire), chat live intégré avec modération, enregistrement automatique, replay disponible immédiatement après la fin, multi-intervenants.
Charge estimée : 10 à 15 jours/homme.

Module 8 — Évolutions Formation
Quiz d'évaluation, attestations PDF générées, live formation intégré, paiement à la formation à l'unité.
Charge estimée : 5 à 8 jours/homme.

Module 9 — Recommandations IA personnalisées
Suggestions automatiques d'opportunités, formations, contenus dans le tableau de bord. Notifications proactives par email basées sur l'IA.
Charge estimée : 5 à 7 jours/homme.

6. Périmètre fonctionnel V3 — Archivage (à partir d'octobre 2026)
Module 10 — Archivage / Catalogue
Finalité. Constituer un catalogue numérique pérenne des œuvres et productions des artistes membres. Mémoire collective et outil de valorisation institutionnelle.
Statut. En attente de cadrage Wendie Zahibo × Felix. Le périmètre, le modèle de données et les standards d'interopérabilité (Dublin Core ou équivalent muséal) sont à définir avant tout développement.
Décision attendue du comité. Fixer une date butoir pour ce cadrage afin de planifier le démarrage du module en V3.

7. Modèle économique
Abonnements membres (récurrent).

Gratuit : ressourcerie en lecture, profil, communauté en lecture limitée.
Mensuel (≈ 9 €/mois) : accès aux formations, alertes personnalisées, communauté en écriture, agent IA personnel à partir de la V2.
Annuel (≈ 90 €/an) : avantage tarifaire vs. mensuel.
Tarif préférentiel membre Bazaart : accès complet inclus dans la cotisation associative.

Compte structure. Recommandation Lab : gratuit en V1 pour acquérir le réseau, basculer vers payant en V2 ou V3.
Billetterie événements (V2). Revenus directs via Stripe pour les événements Bazaart.
Formations à l'unité (V2). Achat ponctuel hors abonnement (≈ 30 à 80 € selon format).
Subventions et dispositifs institutionnels. AFD, DAC, Conseil Régional, Caisse des dépôts, Institut Français.
Mécénat et dons (Pôle Laure). Donateurs particuliers, mécènes culturels.
Prestations Pôle Lab. Cultural engineering pour partenaires (Mansa, institutions).
Collaboration Studio IA × Goumies_créatives (V2). Modèle économique à définir conjointement (partage de revenus, licence, ou cofinancement) dans la convention de collaboration.

8. Estimation budgétaire
8.1 Coûts de développement V1
Développement porté en interne par Gaëlle (Pôle Lab) avec assistance Claude Code. Coût cash sortant pour le développement : 0 €.
Pour information, l'équivalent prestation externe (650 €/jour HT) :
ModuleChargeÉquivalent externeRessourcerie (finalisation + comptes structures)5 j3 250 €Communauté (forum + messagerie + lives planifiés)8 j5 200 €Formation (catalogue + vidéo + parcours)6 j3 900 €Polish, QA, déploiement, contenu de démarrage4 j2 600 €Total V123 j14 950 €
8.2 Infrastructure et hébergement
Statut actuel. Droplet DigitalOcean (≈ 14,90 €/mois), basique 2 GB RAM / 1-2 vCPU. Convient à l'usage actuel mais sera tendu au lancement avec PostgreSQL + Redis + n8n + workers de scraping + agent IA + afflux d'inscriptions.
Recommandation pour le lancement. Upgrade à 4 GB RAM / 2 vCPU avant le 15 juin (≈ 24 $/mois soit ≈ 22 €/mois). Redimensionnement en quelques clics chez DigitalOcean, sans migration ni interruption durable.
Optimisation post-lancement (à instruire après le 15 juin). DigitalOcean est ≈ 30 % plus cher que la concurrence européenne à puissance équivalente. Pistes :

Hetzner Cloud (Allemagne) : un CX22 4 GB / 2 vCPU = 3,79 €/mois. Économie potentielle ≈ 200 €/an.
Scaleway (France) : équivalent ≈ 7-10 €/mois, argument souveraineté France utile pour Mansa, AFD, IFCIC.
OVH VPS (France) : ≈ 10 €/mois.

Pas de changement avant le lancement. À arbitrer en septembre-octobre 2026.
8.3 Coûts récurrents (à partir du lancement)
PosteCoût mensuelCommentaireHébergement DigitalOcean prod (4 GB)~22 €Upgrade recommandé avant le 15 juinHébergement DigitalOcean staging (2 GB)~14 €À ajouter si pas déjà en placeSauvegardes externalisées (Spaces ou Wasabi)5–15 €Bonnes pratiquesDomaine + SSL2 €Let's Encrypt gratuitStockage vidéo Bunny Stream30–150 €Variable selon volume formationsEmail transactionnel (Brevo, Resend)0–30 €Tier gratuit jusqu'à ~3 000 emails/moisMonitoring (Sentry + UptimeRobot)0–25 €Tier gratuit possibleTotal mensuel V1 (sans Studio IA)70–260 €Total annuel V1840 – 3 100 €
8.4 Coûts variables V2 (à anticiper)

API Claude / Anthropic (Studio IA) : 50–500 €/mois selon usage et nombre de membres actifs.
Stripe (V2) : 1,4 % + 0,25 € par transaction européenne, pas de coût fixe.
Live streaming natif (V2, selon scénario) : de 0 € (Twitch embed) à 100–500 €/mois (LiveKit/Mux managed).

8.5 Coûts V2 (juillet–septembre 2026)
Module V2Charge BazaartÉquivalent externeStudio IA (collab Goumies_créatives)8–12 j5 200 – 7 800 €Billetterie complète10 j6 500 €Lives natifs intégrés12 j7 800 €Évolutions Formation6 j3 900 €Recommandations IA6 j3 900 €Total V2 côté Bazaart42–46 j27 300 – 29 900 €
8.6 Récapitulatif global Année 1
PériodeCash sortant (dev interne)Équivalent externeV1 (mai–juin 2026)0 €14 950 €Coûts récurrents 6 premiers mois420 – 1 560 €—V2 (juillet–septembre 2026)0 €27 300 – 29 900 €Total Année 1420 – 1 560 € cash42 250 – 44 850 € équivalent

9. Planning de la V1 — 38 jours calendaires
PériodeÉtapes clés8–11 maiValidation du présent cahier des charges par le comité. Validation des décisions clés (cf. section 10).12–18 mai (Sem. 1)Module Ressourcerie : compte structure + dashboard structure + soumission artiste avec validation.19–25 mai (Sem. 2)Module Communauté : forum + messagerie privée + notifications.26 mai – 1er juin (Sem. 3)Module Communauté (planification de lives) + démarrage Module Formation.2–8 juin (Sem. 4)Module Formation (suite et fin).9–11 juinPolish, QA, contenu de démarrage, upgrade infrastructure, tests utilisateurs.12–13 juinRecette finale, déploiement production, vérifications.14 juinMarge de sécurité (correctifs de dernière minute, contenu final).15 juinSoirée de lancement officielle.
Marge de sécurité disponible : ≈ 2 à 3 jours. Le report du Studio IA en V2 permet de récupérer du temps pour la qualité plutôt que pour ajouter du périmètre.

10. Points d'arbitrage urgents soumis au comité
À valider impérativement avant le 15 mai pour tenir le planning.
Décision 1 — Validation du périmètre V1 des 3 modules (Ressourcerie, Communauté, Formation) tels que définis section 4.
Décision 2 — Validation du report en V2 de Studio IA (collab Goumies_créatives), billetterie, lives natifs intégrés, quiz/attestations formation, recommandations IA.
Décision 3 — Solution de planification de lives V1. Recommandation Lab : annonce + lien externe (Twitch, Jitsi Meet, Google Meet selon préférence de l'artiste) + upload manuel du replay.
Décision 4 — Solution vidéo formations V1. Recommandation Lab : Bunny Stream (≈ 30–80 €/mois selon volume). Alternative dégradée : Vimeo Pro ou YouTube unlisted en iframe.
Décision 5 — Politique tarifaire V1. Validation des fourchettes (gratuit / 9 €/mois / 90 €/an / membre Bazaart). Le compte structure est gratuit en V1.
Décision 6 — Upgrade du droplet DigitalOcean à 4 GB RAM avant le 15 juin (impact budget : +7 €/mois).
Décision 7 — Cadre de la collaboration Studio IA × Goumies_créatives. Convention à signer avant le démarrage de la V2 : périmètre, partage de revenus, propriété intellectuelle, calendrier.
Décision 8 — Calendrier de cadrage du module Archivage avec Wendie × Felix. Date butoir à fixer.
Décision 9 — Plan de communication du lancement. Stratégie d'acquisition entre maintenant et le 15 juin pour avoir des inscrits le jour J. Pilotage Pôle Events × Lab.
Décision 10 — Contenu de démarrage V1. Qui produit quoi avant le 15 juin :

Au moins 1 formation publiée (Pôle Studio).
Au moins 30 ressources/opportunités actives.
Au moins 5 catégories de forum avec un thread d'amorce chacun.
Au moins 3 structures partenaires inscrites avec leurs opportunités.


11. Risques et mitigation
Risque 1 — Glissement de planning. À 5 semaines de la deadline, tout retard se paie en périmètre. Mitigation : validation comité dans les 7 jours, gel des évolutions de scope une fois le développement lancé, MVP minimum identifié pour chaque module si compression nécessaire.
Risque 2 — Indisponibilité technique le jour J. Mitigation : déploiement en production le 13 juin avec 48 h de tests réels, plan de bascule en cas de panne (page de maintenance avec landing email-capture).
Risque 3 — Plateforme vide le jour J. Mitigation : contenu de démarrage validé section 10 décision 10, à produire en parallèle du dev.
Risque 4 — Surcharge serveur le soir du lancement. Mitigation : upgrade infrastructure (décision 6), monitoring temps réel le 15 juin, capacité de redimensionnement en quelques clics.
Risque 5 — Modération communautaire. Mitigation : identifier 2-3 modérateurs avant le 15 juin.
Risque 6 — RGPD et CGU. Mitigation : CGU et politique de confidentialité finalisées avant le lancement, registre des traitements démarré, base juridique du traitement IA documentée pour la V2.
Risque 7 — Disponibilité partenaire Goumies_créatives sur la V2. Mitigation : convention signée avec calendrier ferme avant fin juin, périmètre Studio IA modulable selon disponibilité.

12. Gouvernance et RACI
DomaineResponsableApprobateurConsultéInforméVision et roadmapGaëlleComitéMansaPartenairesDéveloppement technique V1Gaëlle—Claude CodeComitéModule RessourcerieGaëlle (dev)ComitéStructures partenairesMembresModule CommunautéGaëlle (dev)ComitéModérateursMembresModule FormationGaëlle (dev)Wendie (métier)Moossé, RomyComitéModule Studio IA (V2)Gaëlle + Goumies_créativesComité—MembresModule Billetterie (V2)Gaëlle (dev)Laëtitia (métier)Laure (financier)ComitéModule Archivage (V3)Gaëlle (dev)Wendie (métier)FelixComitéCommunication & marketing lancementLaëtitia + GaëlleComitéMansaPartenairesMécénat et financementsLaureComitéBailleurs—Validation tarifaireComitéComité—MembresModération communautaireModérateurs désignésGaëlleComité—

13. Critères de succès et indicateurs
Soirée de lancement (15 juin 2026).

Plateforme fonctionnelle, sans incident technique majeur.
50 inscriptions le soir même.
Démonstration en direct des 3 modules réussie.
Couverture par au moins 2 médias locaux et/ou spécialisés.

À 3 mois (mi-septembre 2026).

200 membres inscrits, 50 actifs/mois.
50 ressources/opportunités actives.
3 formations publiées, 30 inscriptions formation.
10 lives planifiés et tenus.
5 structures inscrites avec leurs propres opportunités.
Studio IA déployé en bêta avec Goumies_créatives.

À 12 mois (juin 2027).

500 membres, 150 actifs/mois.
200 ressources actives.
15 formations en ligne.
30 000 à 50 000 € de revenus directs cumulés.
Plateforme citée par 3 institutions partenaires.


14. Demandes de validation au comité
Le comité de décision est sollicité pour se prononcer dans un délai maximum de 7 jours sur les éléments suivants :

Validation de la vision et du positionnement (sections 1 et 2).
Validation du périmètre V1 — 3 modules (section 4).
Validation du report en V2 du Studio IA (collab Goumies_créatives), de la billetterie, des lives natifs, des évolutions formation et des recommandations IA (section 5).
Validation des décisions techniques 1 à 10 (section 10).
Validation du modèle économique et des fourchettes tarifaires (section 7).
Validation des budgets prévisionnels (section 8).
Engagement sur le calendrier de cadrage du module Archivage (V3).
Engagement sur la production du contenu de démarrage (décision 10).
Engagement sur le cadre de la collaboration Studio IA × Goumies_créatives (décision 7).
Validation du plan de communication du lancement.

Toute décision tardive après le 15 mai entraîne mécaniquement une réduction du périmètre V1.

15. Annexes

A1 — Cahier des charges technique (document séparé)
A2 — Charte graphique Bazaart (existante)
A3 — Plan de communication du lancement (à produire par Pôle Events × Lab)
A4 — Conditions Générales d'Utilisation et Politique de Confidentialité (à finaliser avant lancement)
A5 — Convention de collaboration Bazaart × Goumies_créatives (à signer avant juillet 2026)


Fin du cahier des charges décisionnel V3.
Document soumis à validation urgente — délai maximal 15 mai 2026.
Contact projet : bonjourbazaart@gmail.com



🛠 CAHIER DES CHARGES TECHNIQUE — bazaart.fr V1
Document de référence pour le développement (Pôle Lab × Claude Code)
Référent technique : Gaëlle Charles-Belamour
Outil de développement : VSCode + Claude Code
Repository : privé (à confirmer GitHub / GitLab)
Branche cible déploiement : main (production), dev (intégration)
Version : 1.0 — Mai 2026
Deadline livraison : 15 juin 2026

1. Architecture globale
1.1 Vue d'ensemble
L'application bazaart.fr est une monolithe Symfony servant à la fois la plateforme membre et le site vitrine, avec une couche d'automatisation externe (n8n) pour les tâches asynchrones longues (scraping, traitements IA, notifications batch).
┌─────────────────────────────────────────────────────┐
│                  Utilisateur (web)                  │
└──────────────────────┬──────────────────────────────┘
                       │ HTTPS
┌──────────────────────▼──────────────────────────────┐
│                Nginx (reverse proxy)                │
│          Let's Encrypt SSL, gzip, cache             │
└──────────────────────┬──────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────┐
│             Symfony 7.x (PHP-FPM 8.3)               │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────┐  │
│  │ Vitrine      │  │ Plateforme   │  │ Admin     │  │
│  │ /            │  │ /app/*       │  │ /admin/*  │  │
│  └──────────────┘  └──────────────┘  └───────────┘  │
└────┬──────────────────┬──────────────────┬──────────┘
     │                  │                  │
┌────▼─────┐   ┌────────▼──────┐   ┌───────▼────────┐
│PostgreSQL│   │   Redis       │   │  n8n           │
│  16      │   │  (cache,      │   │  (workflows,   │
│          │   │   sessions,   │   │   scrapers,    │
│          │   │   queues)     │   │   IA tasks)    │
└──────────┘   └───────────────┘   └────────────────┘
                                          │
                                ┌─────────▼─────────┐
                                │ Services externes │
                                │ - Anthropic API   │
                                │ - Bunny Stream    │
                                │ - Brevo/Resend    │
                                │ - Stripe (V2)     │
                                └───────────────────┘
1.2 Pattern architectural

MVC Symfony classique : Controllers fins, Services pour la logique métier, Doctrine pour la persistance, Twig pour les vues.
Async via Messenger + Redis pour les tâches courtes (envoi d'emails, notifications, post-traitements).
Async via n8n pour les workflows longs ou multi-étapes (scraping multi-sources, alertes batch quotidiennes, futur RAG IA).
API JSON interne pour les vues dynamiques (tableaux de bord, recherches Ressourcerie, chat futur Studio IA) — pas de SPA, du Stimulus + Turbo pour l'interactivité.


2. Stack technique
CoucheTechnologieVersionJustificationLangagePHP8.3+Performance, types, attributesFrameworkSymfony7.xMature, robuste, documentationBase de donnéesPostgreSQL16JSONB, full-text search, robustesseCache / Sessions / QueueRedis7.xPerformance, simplicitéORMDoctrine3.xStandard SymfonyTemplatingTwig3.xStandard SymfonyJSStimulus + Turbo3.x / 8.xHotwire, sans SPACSSTailwind CSS3.xCohérence design systemAuthSymfony Security + JWT (V2 API)—StandardEmailSymfony Mailer + Brevo/Resend—Transactionnel fiableVidéoBunny Stream—Coût, performance, simplicitéAuto / Workflowsn8n self-hostedlatestExistant, flexibleIAAnthropic Claude API (V2)claude-opus-4-7Existant pour scrapersConteneurisationDocker + Docker ComposelatestExistant prodReverse proxyNginx1.24+StandardOS serveurUbuntu24.04 LTSStable

3. Infrastructure et hébergement
3.1 Environnement de production

Hébergeur : DigitalOcean.
Type : Droplet (VPS) Ubuntu 24.04 LTS.
Dimensionnement actuel : ≈ 14,90 €/mois (Basic 2 GB RAM / 2 vCPU / 60 GB SSD ou équivalent).
Dimensionnement cible avant le 15 juin : 4 GB RAM / 2 vCPU / 80 GB SSD (≈ 22 €/mois). Action à planifier semaine du 9 juin.
IP serveur : 206.189.3.112.

3.2 Environnement de staging

Recommandation : un second droplet 2 GB à ≈ 14 €/mois pour staging, déployé automatiquement depuis la branche dev.
À mettre en place dans la semaine du 12 mai si pas déjà existant.

3.3 Conteneurs Docker (production)
Stack via docker-compose.prod.yml (existant) :

app : PHP-FPM + Symfony.
nginx : reverse proxy.
postgres : base de données.
redis : cache + sessions + queue Messenger.
n8n : workflows.

3.4 Sauvegardes

Base PostgreSQL : pg_dump quotidien automatisé (cron), conservé 7 jours en local + 30 jours sur stockage objet externe (DigitalOcean Spaces ou Wasabi, ≈ 5–10 €/mois).
Volumes Docker : snapshots DigitalOcean hebdomadaires (option payante ≈ 20 % du coût droplet).
Action : vérifier que le script de backup tourne effectivement et que la restauration est testée avant le 15 juin (test de restauration sur staging).

3.5 Domaines et SSL

Domaine : bazaart.fr.
SSL : Let's Encrypt via Certbot, renouvellement automatique.
Sous-domaines à prévoir :

www.bazaart.fr → redirection vers bazaart.fr,
staging.bazaart.fr → environnement de staging,
n8n.bazaart.fr → interface n8n (protégée par Basic Auth + IP allowlist),
cdn.bazaart.fr → optionnel, pour les assets statiques.



3.6 Monitoring et observabilité

Sentry (tier gratuit) : tracking des erreurs PHP et JS.
UptimeRobot (tier gratuit) : ping toutes les 5 minutes, alertes email si downtime.
Logs Symfony : centralisés dans /var/log/symfony/, rotation logrotate quotidienne, conservation 30 jours.
Dashboard interne : page admin /admin/system-health montrant état BDD, Redis, espace disque, dernière sauvegarde, dernier scraping IA.


4. Sécurité et RGPD
4.1 Authentification et autorisation

Hashage des mots de passe via bcrypt (algo par défaut Symfony Security).
Politique de mot de passe : min. 10 caractères, au moins 1 majuscule + 1 chiffre.
OAuth Google existant via KnpUOAuth2ClientBundle (à conserver).
Rate limiting sur /login et /register via RateLimiterFactory Symfony : max 5 tentatives / 15 min / IP.
CSRF tokens sur tous les formulaires.
Session cookies : httponly, secure, samesite=lax, durée de vie 7 jours.
Rôles : ROLE_USER, ROLE_ARTIST, ROLE_STRUCTURE (nouveau V1), ROLE_MODERATOR (nouveau V1), ROLE_ADMIN.

4.2 Conformité RGPD
À finaliser avant le 15 juin :

Politique de confidentialité publique sur /confidentialite.
CGU publiques sur /cgu.
Mentions légales publiques sur /mentions-legales.
Bannière de consentement cookies (conformité RGPD + e-Privacy). Outil léger : Tarteaucitron ou solution Symfony intégrée.
Espace utilisateur RGPD dans le profil :

Téléchargement de l'export complet de ses données (JSON).
Demande de suppression de compte (workflow d'anonymisation : email mis à NULL ou hashé, contenus orphelins préservés mais détachés).


Registre des traitements : document interne (responsable traitement = Bazaart, sous-traitants = DigitalOcean, Anthropic V2, Brevo, Bunny Stream).
Durée de conservation : utilisateurs inactifs > 3 ans = mail de relance puis anonymisation.
Consentement explicite au traitement IA (V2) lors de l'utilisation du Studio IA.

4.3 Sécurisation HTTP

Headers : Strict-Transport-Security, X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, Content-Security-Policy adaptée aux iframes vidéos (Bunny Stream, YouTube, Twitch).
HTTPS obligatoire : redirection 301 de http:// vers https://.

4.4 Sécurisation des uploads

Validation MIME stricte côté serveur (pas de confiance dans l'extension du fichier).
Taille max :

Image profil : 5 MB.
PDF ressource téléchargeable : 20 MB.
Replay live (upload manuel) : 500 MB → traité par n8n vers Bunny Stream.


Stockage : volumes Docker dédiés, hors webroot, servis via contrôleur Symfony avec contrôle d'accès.


5. Modèle de données par module
5.1 Schéma global (V1)
User ──┐
       ├── ArtistProfile ─── Discipline (M2M)
       ├── OrganizationProfile (renommé "Structure" en UI)
       ├── Resource (créées par user)
       ├── Resource (favoris, M2M)
       ├── Post / PostLike / Comment
       ├── Article
       ├── ForumThread / ForumReply  ← nouveau V1
       ├── Conversation / Message    ← nouveau V1
       ├── Notification              ← nouveau V1
       ├── Live (planifié)           ← nouveau V1
       ├── LiveAttendee              ← nouveau V1
       ├── CourseEnrollment          ← nouveau V1
       └── LessonProgress            ← nouveau V1

Resource ─── Discipline (M2M)
Course ──── Module ──── Lesson ──── LessonResource (PDF, etc.)
ScrapedResource (existant)
5.2 Module Ressourcerie
Entités existantes à adapter :
Resource (existante) — ajouter :

submitter_role : enum ADMIN | STRUCTURE | ARTIST (qui a créé la ressource ?).
status : enum DRAFT | PENDING_VALIDATION | PUBLISHED | REJECTED | ARCHIVED (existe partiellement).
published_at, validated_at, validated_by_id (FK User).
auto_published : bool (true si créée par admin ou structure, false si soumise par artiste).

Logique de publication :

submitter_role = ADMIN ou STRUCTURE → status = PUBLISHED directement, auto_published = true.
submitter_role = ARTIST → status = PENDING_VALIDATION, validation manuelle requise.

Nouvelle entité : ResourceFavorite (M2M User × Resource avec timestamp).
Nouvelle entité : ResourceAlert (préférences d'alertes par utilisateur : disciplines, types, fréquence).
Routes principales :
GET    /resources                          (liste filtrée)
GET    /resources/{id}                     (détail)
GET    /resources/submit                   (formulaire artist/structure)
POST   /resources                          (création)
GET    /resources/my                       (mes ressources créées)
POST   /resources/{id}/favorite            (toggle favori)
GET    /resources/favorites                (mes favoris)

GET    /admin/resources/pending            (à valider)
POST   /admin/resources/{id}/publish       (validation admin)
POST   /admin/resources/{id}/reject

GET    /structure/dashboard                (dashboard structure)
GET    /structure/resources                (mes opportunités structure)
Job CRON quotidien (n8n) : alertes email aux utilisateurs avec préférences correspondantes aux nouvelles ressources publiées dans les dernières 24 h.

5.3 Module Communauté — Forum
Nouvelles entités :
phpForumCategory {
    id, name, slug, description,
    icon, color, order_position,
    is_active, created_at
}

ForumThread {
    id, category_id, author_id,
    title, slug,
    content (text long), 
    is_pinned, is_locked,
    views_count, replies_count, last_reply_at,
    created_at, updated_at
}

ForumReply {
    id, thread_id, author_id, parent_reply_id (FK self, optionnel),
    content,
    is_solution (bool, marque "réponse acceptée"),
    created_at, updated_at
}
Routes :
GET    /forum                              (liste catégories)
GET    /forum/{categorySlug}               (threads d'une catégorie)
GET    /forum/{categorySlug}/{threadSlug}  (détail thread + réponses)
GET    /forum/{categorySlug}/new           (nouveau thread)
POST   /forum/{categorySlug}/new
POST   /forum/thread/{id}/reply
POST   /forum/thread/{id}/lock             (admin/modérateur)
POST   /forum/thread/{id}/pin              (admin)
POST   /forum/thread/{id}/report           (signalement modération)

5.4 Module Communauté — Messagerie privée
Nouvelles entités :
phpConversation {
    id, created_at, last_message_at
    participants (M2M User via ConversationParticipant)
}

ConversationParticipant {
    conversation_id, user_id,
    last_read_at, is_archived, joined_at
}

Message {
    id, conversation_id, author_id,
    content,
    is_read, sent_at
}
Logique : uniquement messagerie 1-à-1 en V1 (groupes en V2). Une conversation est créée à la première interaction.
Routes :
GET    /messages                           (liste conversations)
GET    /messages/{conversationId}          (fil de conversation)
POST   /messages/{conversationId}/send
POST   /messages/start/{userId}            (initier conv avec un membre)
POST   /messages/{conversationId}/archive
Sécurité : vérification systématique que userId dans la session fait partie des participants de la conversation avant tout GET ou POST.

5.5 Module Communauté — Notifications
Nouvelle entité :
phpNotification {
    id, recipient_id (FK User),
    type (enum: NEW_MESSAGE, NEW_REPLY, MENTION, NEW_LIVE, RESOURCE_VALIDATED, RESOURCE_MATCH),
    payload (JSON: contient l'ID de la ressource/thread/message lié),
    is_read,
    created_at, read_at
}
Génération :

Side-effects directs (création de notif au moment de l'événement) pour types in-app rapides.
Email transactionnel via Symfony Mailer + Brevo/Resend pour les notifications importantes.
Préférences utilisateur dans le profil : in-app seulement / email / les deux / désactivé.

API interne pour le badge "non lues" :
GET  /api/notifications/unread-count       (JSON, polling toutes les 60s côté client via Stimulus)
GET  /notifications                         (page complète)
POST /notifications/{id}/read
POST /notifications/read-all

5.6 Module Communauté — Lives planifiés
Nouvelle entité :
phpLive {
    id, host_id (FK User),
    title, description, cover_image,
    starts_at, duration_minutes,
    external_platform (enum: TWITCH, JITSI, GOOGLE_MEET, ZOOM, OTHER),
    external_url,
    status (enum: SCHEDULED, LIVE, ENDED, CANCELLED),
    replay_video_url (URL Bunny Stream après upload),
    replay_uploaded_at,
    attendees_count,
    created_at, updated_at
}

LiveAttendee {
    live_id, user_id, registered_at,
    notified_30min_before
}
Workflow V1 :

Un membre crée un live → annonce automatique dans le forum + notif à son réseau.
Les membres s'inscrivent → reçoivent un email de rappel 1h avant.
À l'heure du live, l'organisateur partage le lien externe (qui était caché aux non-inscrits).
Après le live, l'organisateur upload la vidéo replay via formulaire → traitée par n8n vers Bunny Stream → URL stockée dans replay_video_url.

Routes :
GET    /lives                              (calendrier des lives)
GET    /lives/{id}                         (détail)
GET    /lives/new                          (créer)
POST   /lives
POST   /lives/{id}/register                (s'inscrire)
POST   /lives/{id}/upload-replay           (host uniquement, redirige vers n8n upload)
Job CRON (n8n) : toutes les 15 minutes, vérifier les lives à venir et envoyer les rappels (1h avant).

5.7 Module Formation
Nouvelles entités :
phpCourse {
    id, slug, title, subtitle, description,
    cover_image, trailer_video_url,
    instructor_name, instructor_bio, instructor_avatar,
    duration_minutes_total,
    level (enum: BEGINNER, INTERMEDIATE, ADVANCED),
    is_published, published_at,
    created_at, updated_at
}

CourseModule {
    id, course_id, title, description,
    order_position
}

Lesson {
    id, module_id,
    title, description,
    video_bunny_id (référence Bunny Stream),
    duration_seconds,
    order_position,
    is_free_preview (bool, si true accessible sans inscription)
}

LessonResource {
    id, lesson_id,
    title, file_path, file_size, mime_type
}

CourseEnrollment {
    id, user_id, course_id,
    enrolled_at, completed_at,
    progress_percent
}

LessonProgress {
    id, enrollment_id, lesson_id,
    started_at, completed_at,
    last_position_seconds  // reprise lecture
}
Routes :
GET    /formations                         (catalogue)
GET    /formations/{slug}                  (détail formation, page de vente)
POST   /formations/{slug}/enroll           (inscription, V1 = gratuit ou abonnement)
GET    /formations/{slug}/learn            (espace apprenant, liste leçons)
GET    /formations/{slug}/learn/{lessonSlug}  (lecteur leçon)
POST   /formations/{slug}/learn/{lessonId}/progress  (POST AJAX progression)

GET    /admin/formations                   (gestion admin)
GET    /admin/formations/new
POST   /admin/formations/{id}/modules/new
POST   /admin/formations/{id}/lessons/new  (avec upload vers Bunny Stream)
POST   /admin/formations/{id}/publish
Intégration Bunny Stream :

Upload vidéo via API Bunny depuis le formulaire admin (passe par n8n pour les uploads > 100 MB).
Stockage de l'ID Bunny dans Lesson.video_bunny_id.
Lecture via player iframe Bunny avec token signé (URL temporaire) pour empêcher le hotlinking.
DRM optionnel non activé en V1.


5.8 Module Compte Structure (étend OrganizationProfile)
L'entité OrganizationProfile existe déjà. Ajouter :

is_structure_partner (bool) : true si compte structure activé.
structure_activated_at : timestamp.
structure_activation_validated_by_id : FK User (admin qui a validé).

Workflow d'activation :

Une organisation s'inscrit (formulaire d'inscription dédié /structure/register).
Profil créé avec is_structure_partner = false par défaut.
Admin reçoit notification, valide depuis /admin/structures/pending.
Validation admin → is_structure_partner = true, ajout du rôle ROLE_STRUCTURE.
La structure peut alors créer des opportunités auto-publiées.


6. APIs et intégrations externes
6.1 Bunny Stream (vidéo)

API Key dans variable d'environnement BUNNY_STREAM_API_KEY.
Library zone ID dans BUNNY_STREAM_LIBRARY_ID.
Upload : appel API depuis n8n (workflow dédié) avec progression renvoyée à Symfony via webhook.
Lecture : iframe avec token signé généré côté Symfony (TTL 4h).

6.2 Email transactionnel (Brevo ou Resend)

API Key dans MAILER_DSN.
Templates HTML dans templates/emails/ (Twig + MJML compilé).
Envoi via Symfony Messenger en async pour ne pas bloquer la requête.

6.3 Google OAuth (existant)

Maintenu tel quel.

6.4 Anthropic Claude API (V2)

Préparer la configuration dès la V1 (ajouter ANTHROPIC_API_KEY au .env.dist) sans l'activer. Implémentation effective en V2.

6.5 Stripe (V2)

Pas activé en V1. Ajouter les variables d'environnement vides en prévision.


7. DevOps : déploiement, CI/CD, tests
7.1 Workflow Git

Branche main → production (déploiement manuel via SSH + git pull + docker compose up).
Branche dev → staging (idéalement déploiement auto sur push).
Branches feature : feature/<module>-<description>, mergées dans dev via PR (revue par soi-même via Claude Code).

7.2 Déploiement (procédure type)
bash# Sur le serveur production
cd /opt/bazaart
git pull origin main
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console cache:clear --env=prod
docker compose exec app php bin/console assets:install
À automatiser via un script bin/deploy.sh ou un workflow GitHub Actions (à mettre en place avant le 15 juin si possible, sinon V2).
7.3 Variables d'environnement (.env.local)
Liste minimale V1 :
APP_ENV=prod
APP_SECRET=<random>
DATABASE_URL=postgresql://bazaart:<pwd>@postgres:5432/bazaart
REDIS_URL=redis://redis:6379
MAILER_DSN=<brevo ou resend dsn>
BUNNY_STREAM_API_KEY=<key>
BUNNY_STREAM_LIBRARY_ID=<id>
GOOGLE_OAUTH_CLIENT_ID=<existant>
GOOGLE_OAUTH_CLIENT_SECRET=<existant>
N8N_WEBHOOK_BASE_URL=https://n8n.bazaart.fr
SENTRY_DSN=<dsn>
7.4 Plan de tests V1
Tests automatisés (priorité minimale viable) :

PHPUnit : tests unitaires sur les services critiques (validation ressource, génération notifications, calcul de progression formation). Couverture cible V1 : 30 % (réaliste vu le délai).
Panther / Symfony Functional tests : tests end-to-end sur les parcours critiques (inscription, login, soumettre une ressource, créer un thread forum, envoyer un message privé, s'inscrire à une formation). Cible : 5 à 8 scénarios.

Tests manuels structurés (semaine du 9 juin) :

Checklist par persona (artiste / structure / admin) avec parcours complets.
Test de charge léger (Apache Bench ou k6) sur les endpoints les plus sollicités : /, /resources, /forum.
Test de la procédure de restauration de sauvegarde sur staging.

7.5 Plan de bascule (rollback)

Avant tout déploiement prod, sauvegarde BDD systématique.
Tag Git de la version précédente avant déploiement (git tag prod-2026-06-13).
En cas d'incident grave : rollback en 5 minutes via git checkout <tag> + restauration BDD.


8. Roadmap technique semaine par semaine
Semaine 0 — du 8 au 11 mai (cadrage)

Validation du cahier des charges décisionnel par le comité.
Mise à jour du README.md du repo avec ce plan.
Vérification de l'environnement staging (à créer si absent).
Test de la procédure de sauvegarde / restauration sur staging.

Semaine 1 — du 12 au 18 mai (Ressourcerie)

Création du rôle ROLE_STRUCTURE + workflow d'activation admin.
Page /structure/register + dashboard /structure/dashboard.
Adaptation entité Resource (champs submitter_role, auto_published).
Workflow de soumission artiste avec validation admin.
Système d'alertes email (préférences utilisateur + job n8n quotidien).
Tests fonctionnels parcours structure + artiste + admin.

Semaine 2 — du 19 au 25 mai (Communauté — partie 1)

Entités ForumCategory, ForumThread, ForumReply. Migrations.
Pages forum (liste, catégorie, thread, création thread, réponse).
Modération admin (lock, pin, suppression).
Entités Conversation, Message, ConversationParticipant.
Pages messagerie (liste, conversation, envoi).
Tests fonctionnels.

Semaine 3 — du 26 mai au 1er juin (Communauté — partie 2 + démarrage Formation)

Entité Notification + génération automatique sur événements.
Endpoint API badge non-lues + Stimulus controller polling.
Préférences notifications dans profil utilisateur.
Entité Live + pages (calendrier, détail, création, inscription).
Job n8n de rappels lives.
Démarrage Module Formation : entités Course, CourseModule, Lesson, migrations.

Semaine 4 — du 2 au 8 juin (Formation)

Pages catalogue formations + détail.
Espace apprenant (liste leçons, lecteur vidéo Bunny).
Suivi de progression (LessonProgress).
Admin : création formation, modules, leçons avec upload Bunny.
Tests fonctionnels parcours formation.

Semaine 5 — du 9 au 13 juin (Polish, QA, contenu, infra)

Mardi 9 : upgrade droplet à 4 GB.
Mercredi 10 : finalisation CGU, politique de confidentialité, mentions légales.
Jeudi 11 : tests utilisateurs avec 3-5 personnes externes (artistes amis).
Vendredi 12 : correction bugs prioritaires, recette finale.
Samedi 13 : déploiement production, vérifications.
Dimanche 14 : marge de sécurité.

J-1 et J — 14 et 15 juin

14 juin : briefing équipe, dernier check.
15 juin : monitoring temps réel, soirée de lancement.

Post-lancement (16 juin et au-delà)

Bilan technique du lancement (incidents, charge serveur, retours utilisateurs).
Démarrage de la V2 selon priorisation comité.


9. Standards de code

PSR-12 pour le PHP.
PHPStan niveau 6 minimum sur les nouveaux modules (vendor/bin/phpstan analyse src --level=6).
Symfony Coding Standards via PHP-CS-Fixer (config existante à vérifier).
Twig : préférer la composition de templates ({% extends %}, {% include %}) à la duplication.
Naming conventions :

Entités au singulier en PascalCase (ForumThread, pas forum_threads).
Routes en kebab-case (/forum/{category-slug}).
Controllers en PascalCase se terminant par Controller.
Services en PascalCase se terminant par leur rôle (Service, Repository, Factory).




10. Annexes

A1 — Schéma de base de données complet : à générer via php bin/console doctrine:schema:update --dump-sql une fois toutes les migrations V1 appliquées.
A2 — Diagramme de séquence d'inscription Structure : à produire si besoin.
A3 — Liste des variables d'environnement complètes : disponible dans .env.dist.
A4 — Procédure de déploiement détaillée : à formaliser dans docs/DEPLOY.md.


Fin du cahier des charges technique V1.
Document vivant — à actualiser au fur et à mesure des choix techniques de mise en œuvre.
Maintenu par : Gaëlle Charles-Belamour, Pôle Lab.


✅ Les deux cahiers sont prêts.
Quelques notes pour la suite :

Le décisionnel est prêt à envoyer au comité dès que tu valides — délai 7 jours pour leurs retours.
Le technique est volontairement opérationnel : à chaque semaine du planning correspond un livrable précis. Tu peux le coller dans Claude Code comme contexte de référence.
Quand le sandbox sera revenu, je te génère les deux en .docx propres (avec table des matières, headers, page de garde Bazaart). Tu n'auras qu'à les imprimer / partager.
Le module Communauté est le plus lourd des 3 (8 jours/homme = 35 % du budget V1). Je recommande de le démarrer d'abord par la messagerie + notifications (briques réutilisables partout), puis le forum, puis les lives planifiés. Si tu prends du retard, le module lives planifiés est celui qu'on peut compresser le plus (V0 = juste un champ "lien externe" + email de rappel).
Point ouvert que tu pourrais arbitrer cette semaine : Bunny Stream vs Vimeo Pro pour les vidéos formations. Bunny est plus pro mais demande 1 jour d'intégration de plus que Vimeo iframe. Si tu veux gagner du temps, Vimeo en V1 → migration Bunny en V2.

