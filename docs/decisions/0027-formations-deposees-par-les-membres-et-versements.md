# ADR-0027 — Formations/ateliers déposés par les membres + versements (sans Stripe Connect)

- **Date** : 2026-06-23
- **Statut** : arbitré
- **Décidé par** : Gaëlle

## Contexte

Jusqu'ici, la création de formations était **100 % admin** (cf. ADR-0012 révisé, qui
avait désactivé la « proposition de formation » par les membres). Gaëlle souhaite
désormais que **tout membre connecté** (artiste, formateur, artisan) puisse **déposer
une formation ou un atelier (payant)**, avec validation par l'admin, et reversement
d'une part au créateur.

Contrainte forte réaffirmée : **« Pas de Stripe Connect : seulement Stripe simple
admin-only »** (cf. `CLAUDE.md`). On **ne** met **pas** en place de marketplace Stripe
(payouts automatiques). Les versements aux créateurs se font **manuellement** par
virement bancaire, à partir des coordonnées collectées.

Cet ADR **révise l'ADR-0012** (réouverture du dépôt aux membres, avec validation) et
**ne modifie pas** la règle « pas de Connect ».

## Options envisagées

1. **Stripe Connect (marketplace, payouts automatiques)** — écartée : contredit la
   décision `CLAUDE.md` ; chantier technique + juridique lourd (KYC délégué à Stripe,
   agrément plateforme, fiscalité marketplace).
2. **Dépôt membre + validation admin + encaissement Bazaart + virement manuel
   (retenue)** — Stripe simple (l'argent va à Bazaart), puis Bazaart reverse au
   créateur par virement après l'atelier, à partir du RIB collecté. Commission 10 %.

## Décision

**Option 2 retenue.** Règles :

- **Qui dépose** : tout **membre connecté**.
- **Validation** : l'**admin** valide la formation/atelier **et** les justificatifs
  avant publication (réactivation du flux de proposition — A2).
- **Contenu** : formations **et** ateliers = **liens externes** (en ligne et
  présentiel), modèle proche des « lives » : titre, description, prix, date/lieu,
  **lien externe** (pas de lecteur vidéo intégré à construire).
- **Justificatifs créateur** (obligatoires pour être payé) : **pièce d'identité +
  RIB (IBAN/BIC) + SIRET**. Collectés et **stockés par nous, de façon sécurisée**.
- **Paiement** : via **Stripe simple** (réutilise l'existant `CoursePayment`),
  encaissé par **Bazaart**.
- **Commission** : **10 % Bazaart / 90 % créateur**.
- **Versement** : **virement manuel** déclenché/enregistré par l'**admin** **après
  l'atelier** (date, montant, référence) ; suivi « payé / à payer ».

## Conséquences

### RGPD & sécurité (point critique)
Comme Stripe ne gère pas le KYC ici, **nous** stockons des **données sensibles**
(pièce d'identité, RIB, SIRET). Donc :
- Fichiers stockés **hors du dossier web public** (jamais d'URL publique).
- Accès **strictement admin**, via un contrôleur qui **streame** le fichier après
  contrôle d'autorisation (jamais en lien direct).
- **Durée de conservation limitée** (suppression après paiement / délai légal).
- Mention dans la **politique de confidentialité** ; droit à l'effacement.
- Validation des uploads (types autorisés, taille), validation **IBAN**.

### Modèle de données
- Nouvelle entité **profil de versement créateur** (IBAN/BIC, SIRET, chemin pièce
  d'identité, statut de vérification) — migration.
- **Lien créateur** sur la formation (aujourd'hui seulement `instructorName` en
  texte) — migration.
- Nouvelle entité **versement créateur** (payout) pour le suivi des virements.

### Découpage en lots
- **Lot 1** — Profil créateur & justificatifs (RGPD) : collecte sécurisée + validation admin.
- **Lot 2** — Dépôt de formation/atelier (A2) lié au créateur + validation→publication.
- **Lot 3** — Paiement (Stripe simple existant) + calcul commission 90/10.
- **Lot 4** — Espace financier admin : paiements reçus, montants dus, enregistrement
  des virements après l'atelier.

### Liens
- **Révise** [[0012]] (réouverture du dépôt aux membres avec validation).
- **Respecte** la règle « pas de Stripe Connect » du `CLAUDE.md` (inchangée).
- S'appuie sur l'existant `Course`, `CoursePayment`, `CourseProposal`.
