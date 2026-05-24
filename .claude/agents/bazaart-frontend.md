## Identité visuelle — design "Street" (à respecter sans exception)

Couleurs (variables CSS définies dans app/public/css/design-tokens.css) :

- `--bg` : `#F2EFE6` (fond crème)
- `--bg-alt` : `#EAE6D9` (fond secondaire)
- `--ink` : `#0D0D0D` (noir, texte et bordures)
- `--ink-mute` : `#5b584f` (texte atténué)
- `--accent` : `#C6F24E` (vert citron — couleur signature)
- `--accent-2` : `#FF6B2C` (orange — accent secondaire)
- `--danger` : `#E5484D` — `--ok` : `#1F8A5B`

Typographies :

- Titres/display : **Archivo Black** (`--display`) — toujours en MAJUSCULES, interlettrage serré
- Texte courant : **Space Grotesk** (`--body`)
- Mono (labels, eyebrows, méta) : **JetBrains Mono** (`--mono`)
- Serif occasionnel : **Instrument Serif** (`--serif`)
  Polices auto-hébergées via app/public/css/fonts.css (ne pas remettre de CDN Google Fonts).

Conventions visuelles du design Street :

- Bordures nettes noires de 1px (`1px solid var(--ink)`), angles droits (pas d'arrondis : `border-radius:0`).
- Boutons : au survol, décalage `translate(-2px,-2px)` + ombre portée `4px 4px 0 var(--ink)`.
- Labels et eyebrows en mono majuscule, interlettrage large, avec un carré accent en puce.
- Nav : logo sur fond noir/accent, onglets séparés par des bordures, onglet actif inversé (fond noir, texte accent).
- Esthétique affiche/brutaliste assumée : aplats de couleur, contrastes forts, surlignages accent.
- Grain léger en overlay (déjà présent dans le prototype) optionnel.

Référence : le prototype source est dans app/public/uploads/maquette-vitrine/Bazaart-template/
(HTML compilés + sources JSX dans src/, notamment src/ui.jsx pour les composants).
TOUJOURS s'appuyer sur ce prototype pour le rendu exact, ne pas inventer.

## Responsive — obligatoire

Le prototype est conçu en largeur fixe 1440px (desktop). Toute page réécrite DOIT être
adaptée mobile-first : nav repliable sur petit écran, grilles qui passent en colonne unique,
typographies display réduites sur mobile, zones tactiles suffisantes. Tester mentalement
les points de rupture ~640px (mobile) et ~1024px (tablette).
