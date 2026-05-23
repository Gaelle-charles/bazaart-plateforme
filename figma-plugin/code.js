/**
 * BazaArt Maquettes — Plugin Figma v2
 *
 * Architecture robuste :
 * 1. Toutes les polices chargées EN AVANCE (évite les erreurs async)
 * 2. Chaque frame est CRÉÉE et POSITIONNÉE avant d'être remplie
 * 3. Le contenu est ajouté avec try/catch pour chaque frame
 * 4. Les couleurs avec alpha sont gérées correctement
 */

// ============================================================
// COULEURS (uniquement RGB — pas d'alpha dans les fills Figma)
// ============================================================
// ============================================================
// NOUVELLE PALETTE — inspirée de bazaart-maquette_2
// Design clair, chaleureux, éditorial
// ============================================================
const C = {
  // --- Fonds ---
  bgPrimary:     { r: 1,     g: 1,     b: 1     },  // blanc pur #FFFFFF
  bgSecondary:   { r: 0.969, g: 0.957, b: 0.937 },  // crème chaude #F7F4EF
  bgTertiary:    { r: 0.949, g: 0.941, b: 0.922 },  // gris clair #F2F0EB
  bgCard:        { r: 1,     g: 1,     b: 1     },  // cards blanches
  bgInput:       { r: 0.980, g: 0.976, b: 0.969 },  // inputs très légèrement crème

  // --- Textes ---
  textPrimary:   { r: 0.102, g: 0.102, b: 0.102 },  // quasi-noir #1A1A1A
  textSecondary: { r: 0.420, g: 0.408, b: 0.376 },  // gris moyen #6B6860
  textMuted:     { r: 0.663, g: 0.651, b: 0.627 },  // gris clair

  // --- Accents ---
  // Note : "gold" garde le même nom pour ne pas tout casser,
  // mais sa valeur est maintenant le rouge terracotta du design
  gold:          { r: 0.784, g: 0.314, b: 0.227 },  // terracotta #C8503A
  brown:         { r: 0.910, g: 0.659, b: 0.486 },  // pêche #E8A87C (avatars)
  green:         { r: 0.110, g: 0.227, b: 0.184 },  // vert foncé #1C3A2F
  amber:         { r: 0.910, g: 0.659, b: 0.486 },  // pêche #E8A87C
  red:           { r: 0.753, g: 0.224, b: 0.169 },  // rouge erreur #C0392B
  white:         { r: 1,     g: 1,     b: 1     },

  // --- Fonds de badges (très pâles) ---
  goldFaint:     { r: 0.996, g: 0.941, b: 0.925 },  // rose très pâle #FEF0EC
  greenFaint:    { r: 0.831, g: 0.902, b: 0.863 },  // vert pâle #D4E6DC
  redFaint:      { r: 0.996, g: 0.918, b: 0.914 },  // rouge très pâle
  amberFaint:    { r: 0.996, g: 0.961, b: 0.937 },  // pêche très pâle

  // --- Structure ---
  border:        { r: 0.886, g: 0.867, b: 0.839 },  // bordure chaude #E2DDD6

  // --- Sidebar (vert foncé, comme dans le design de référence) ---
  sidebarBg:     { r: 0.110, g: 0.227, b: 0.184 },  // vert foncé #1C3A2F
  sidebarActive: { r: 0.996, g: 0.941, b: 0.925 },  // fond item actif (rose pâle)
  sidebarText:   { r: 0.886, g: 0.918, b: 0.906 },  // texte sidebar (vert-blanc)
  sidebarMuted:  { r: 0.443, g: 0.561, b: 0.518 },  // texte muted sidebar
};

const PAGES = [
  // ---- Landing (page publique, avant connexion) ----
  { id: 'home',             name: '00 — Accueil (landing)',         section: 'Landing' },
  // ---- Auth ----
  { id: 'login',            name: '01 — Login',                     section: 'Auth' },
  { id: 'register',         name: '02 — Inscription',               section: 'Auth' },
  // ---- App (connecte) ----
  { id: 'dashboard',        name: '03 — Dashboard',                 section: 'App' },
  { id: 'artist-show',      name: '04 — Profil Artiste (voir)',     section: 'App' },
  { id: 'artist-edit',      name: '05 — Profil Artiste (modifier)', section: 'App' },
  { id: 'org-show',         name: '06 — Profil Organisation (voir)',section: 'App' },
  { id: 'org-edit',         name: '07 — Profil Organisation (edit)',section: 'App' },
  // ---- Ressources ----
  { id: 'resources-list',   name: '08 — Ressources (liste)',        section: 'Resources' },
  { id: 'resource-detail',  name: '09 — Ressource (detail)',        section: 'Resources' },
  { id: 'resource-submit',  name: '10 — Soumettre une ressource',   section: 'Resources' },
  { id: 'resource-my',      name: '11 — Mes soumissions',           section: 'Resources' },
  // ---- Communaute ----
  { id: 'community',        name: '12 — Communaute (feed)',         section: 'Community' },
  { id: 'articles-list',    name: '13 — Articles (liste)',          section: 'Articles' },
  { id: 'article-show',     name: '14 — Article (lire)',            section: 'Articles' },
  { id: 'article-new',      name: '15 — Ecrire un article',         section: 'Articles' },
  { id: 'article-my',       name: '16 — Mes articles',              section: 'Articles' },
  // ---- Admin ----
  { id: 'admin-dashboard',  name: '17 — Admin Dashboard',           section: 'Admin' },
  { id: 'admin-pending',    name: '18 — Admin Moderation',          section: 'Admin' },
  { id: 'admin-resources',  name: '19 — Admin Ressources',          section: 'Admin' },
  { id: 'admin-users',      name: '20 — Admin Utilisateurs',        section: 'Admin' },
];

// ============================================================
// HELPERS DE BASE
// ============================================================

/**
 * Applique un fill sur un nœud Figma de manière sécurisée
 * Figma n'accepte que {r,g,b} pour color — pas d'alpha ici
 */
function setFill(node, color, opacity = 1) {
  node.fills = [{ type: 'SOLID', color: { r: color.r, g: color.g, b: color.b }, opacity }];
}

/**
 * Crée un rectangle simple
 */
function addRect(parent, x, y, w, h, color, opts = {}) {
  const r = figma.createRectangle();
  r.x = x; r.y = y;
  r.resize(Math.max(w, 1), Math.max(h, 1));
  setFill(r, color, opts.opacity || 1);
  if (opts.radius) r.cornerRadius = opts.radius;
  if (opts.name) r.name = opts.name;
  if (opts.stroke) {
    r.strokes = [{ type: 'SOLID', color: opts.stroke }];
    r.strokeWeight = opts.strokeWeight || 1;
  }
  parent.appendChild(r);
  return r;
}

/**
 * Crée un frame (conteneur)
 */
function addFrame(parent, name, x, y, w, h, color = C.bgPrimary) {
  const f = figma.createFrame();
  f.name = name;
  f.x = x; f.y = y;
  f.resize(Math.max(w, 1), Math.max(h, 1));
  setFill(f, color);
  f.clipsContent = false;
  if (parent) parent.appendChild(f);
  return f;
}

/**
 * Crée un texte — ATTENTION : les polices doivent être pré-chargées
 * @param {number} weight - 400, 500, 600, 700
 */
function addText(parent, content, x, y, size, color, weight = 400, maxW = 0) {
  const styleMap = { 400: 'Regular', 500: 'Medium', 600: 'Semi Bold', 700: 'Bold' };
  const style = styleMap[weight] || 'Regular';

  const node = figma.createText();
  node.fontName = { family: 'Inter', style };
  node.fontSize = size;
  node.characters = content;
  setFill(node, color);
  node.x = x;
  node.y = y;

  if (maxW > 0) {
    node.textAutoResize = 'HEIGHT';
    node.resize(maxW, 20);
  }

  parent.appendChild(node);
  return node;
}

/**
 * Crée un texte en Playfair Display (serif) — pour les titres principaux
 * Même signature que addText mais utilise la police d'affichage du design
 */
function addTitle(parent, content, x, y, size, color = C.textPrimary, bold = true) {
  const style = bold ? 'Bold' : 'Regular';
  const node = figma.createText();
  node.fontName = { family: 'Playfair Display', style };
  node.fontSize = size;
  node.characters = content;
  setFill(node, color);
  node.x = x;
  node.y = y;
  parent.appendChild(node);
  return node;
}

/**
 * Crée un bouton primaire
 */
function addBtnPrimary(parent, label, x, y, w = 160) {
  const btn = addFrame(parent, 'Btn', x, y, w, 40, C.gold);
  btn.cornerRadius = 6;
  const t = figma.createText();
  t.fontName = { family: 'Inter', style: 'Semi Bold' };
  t.fontSize = 13;
  t.characters = label;
  setFill(t, C.white);  // texte blanc sur fond terracotta
  t.x = 16;
  t.y = 12;
  btn.appendChild(t);
  return btn;
}

/**
 * Crée un bouton secondaire (contour)
 */
function addBtnSecondary(parent, label, x, y, w = 160) {
  const btn = addFrame(parent, 'Btn Outline', x, y, w, 40, C.bgCard);
  btn.cornerRadius = 6;
  btn.strokes = [{ type: 'SOLID', color: C.textPrimary }];  // bordure noire/foncée
  btn.strokeWeight = 1.5;
  const t = figma.createText();
  t.fontName = { family: 'Inter', style: 'Medium' };
  t.fontSize = 13;
  t.characters = label;
  setFill(t, C.textPrimary);
  t.x = 16;
  t.y = 12;
  btn.appendChild(t);
  return btn;
}

/**
 * Crée un champ de saisie
 */
function addInput(parent, label, placeholder, x, y, w = 340) {
  if (label) {
    addText(parent, label.toUpperCase(), x, y, 10, C.textMuted, 600);
    y += 18;
  }
  const field = addFrame(parent, 'Input', x, y, w, 40, C.bgInput);
  field.cornerRadius = 6;
  field.strokes = [{ type: 'SOLID', color: C.border }];
  field.strokeWeight = 1;
  if (placeholder) addText(field, placeholder, 12, 12, 13, C.textMuted, 400);
  return field;
}

/**
 * Crée un badge (tag coloré)
 */
function addBadge(parent, label, x, y, bgColor, textColor) {
  const b = addFrame(parent, 'Badge', x, y, label.length * 7 + 16, 22, bgColor);
  b.cornerRadius = 999;
  const t = figma.createText();
  t.fontName = { family: 'Inter', style: 'Semi Bold' };
  t.fontSize = 10;
  t.characters = label;
  setFill(t, textColor);
  t.x = 8;
  t.y = 6;
  b.appendChild(t);
  return b;
}

/**
 * Crée une card avec contour
 */
function addCard(parent, x, y, w, h, name = 'Card') {
  const c = addFrame(parent, name, x, y, w, h, C.bgCard);
  c.cornerRadius = 12;
  c.strokes = [{ type: 'SOLID', color: C.border }];
  c.strokeWeight = 1;
  // Ombre subtile (style du design de référence)
  c.effects = [{
    type: 'DROP_SHADOW',
    color: { r: 0, g: 0, b: 0, a: 0.06 },
    offset: { x: 0, y: 2 },
    radius: 16,
    spread: 0,
    visible: true,
    blendMode: 'NORMAL',
  }];
  return c;
}

// ============================================================
// COMPOSANTS RÉUTILISABLES
// ============================================================

/**
 * Barre latérale de navigation (sidebar 240px)
 */
function drawSidebar(parent, activeId = '') {
  const sb = addFrame(parent, 'Sidebar', 0, 0, 240, 900, C.sidebarBg);

  // Logo
  addText(sb, 'BazaArt', 24, 22, 18, C.white, 700);
  addText(sb, 'artistes francophones', 24, 46, 9, C.sidebarMuted, 400);
  addRect(sb, 0, 68, 240, 1, { r: 0.176, g: 0.298, b: 0.251 });

  // Navigation principale
  addText(sb, 'PRINCIPAL', 24, 84, 9, C.sidebarMuted, 600);

  const mainNav = [
    { label: '⊞  Tableau de bord', id: 'dashboard' },
    { label: '◈  Ressources', id: 'resources-list' },
    { label: '◉  Communauté', id: 'community' },
    { label: '◎  Articles', id: 'articles-list' },
  ];

  let yNav = 102;
  for (const item of mainNav) {
    const isActive = item.id === activeId || activeId.startsWith(item.id.split('-')[0]);
    if (isActive) {
      addRect(sb, 0, yNav - 4, 3, 32, C.gold);
      addRect(sb, 0, yNav - 4, 240, 32, C.sidebarActive);
    }
    addText(sb, item.label, 20, yNav + 6, 13, isActive ? C.gold : C.sidebarText, isActive ? 600 : 400);
    yNav += 34;
  }

  // Mon espace
  addText(sb, 'MON ESPACE', 24, yNav + 8, 9, C.sidebarMuted, 600);
  yNav += 26;

  const myNav = [
    { label: '◐  Mon profil artiste', id: 'artist' },
    { label: '▣  Mon organisation', id: 'org' },
    { label: '◳  Mes soumissions', id: 'resource-my' },
    { label: '◷  Mes articles', id: 'article-my' },
  ];
  for (const item of myNav) {
    const isActive = activeId.startsWith(item.id);
    if (isActive) {
      addRect(sb, 0, yNav - 4, 3, 32, C.gold);
      addRect(sb, 0, yNav - 4, 240, 32, C.sidebarActive);
    }
    addText(sb, item.label, 20, yNav + 6, 13, isActive ? C.gold : C.sidebarText, isActive ? 600 : 400);
    yNav += 34;
  }

  // Avatar utilisateur (bas)
  addRect(sb, 0, 854, 240, 1, { r: 0.176, g: 0.298, b: 0.251 });
  const av = addFrame(sb, 'Avatar', 16, 862, 32, 32, C.brown);
  av.cornerRadius = 999;
  addText(av, 'MC', 8, 9, 11, C.white, 700);
  addText(sb, 'Marie Callot', 58, 864, 12, C.white, 600);
  addText(sb, 'Artiste', 58, 880, 10, C.sidebarMuted, 400);

  return sb;
}

/**
 * Sidebar administration (variante dorée)
 */
function drawAdminSidebar(parent, activeId = '') {
  const sb = addFrame(parent, 'Sidebar Admin', 0, 0, 240, 900, C.sidebarBg);
  addText(sb, 'BazaArt', 24, 22, 18, C.white, 700);
  addText(sb, '⚙ Administration', 24, 46, 9, C.amber, 400);
  addRect(sb, 0, 68, 240, 1, { r: 0.176, g: 0.298, b: 0.251 });

  addText(sb, 'ADMINISTRATION', 24, 84, 9, C.sidebarMuted, 600);

  const adminNav = [
    { label: '⊞  Dashboard Admin', id: 'admin-dashboard' },
    { label: '(...) Modération', id: 'admin-pending', badge: '7' },
    { label: '◈  Toutes les ressources', id: 'admin-resources' },
    { label: '[ ] Utilisateurs', id: 'admin-users' },
  ];

  let yNav = 102;
  for (const item of adminNav) {
    const isActive = item.id === activeId;
    if (isActive) {
      addRect(sb, 0, yNav - 4, 3, 32, C.amber);
      addRect(sb, 0, yNav - 4, 240, 32, C.amberFaint);
    }
    addText(sb, item.label, 20, yNav + 6, 13, isActive ? C.gold : C.sidebarText, isActive ? 600 : 400);
    if (item.badge) {
      addBadge(sb, item.badge, 198, yNav + 4, C.red, C.white);
    }
    yNav += 34;
  }

  addRect(sb, 0, 854, 240, 1, { r: 0.176, g: 0.298, b: 0.251 });
  const av = addFrame(sb, 'Avatar', 16, 862, 32, 32, C.brown);
  av.cornerRadius = 999;
  addText(av, 'MC', 8, 9, 11, C.white, 700);
  addText(sb, 'Marie Callot', 58, 864, 12, C.white, 600);
  addText(sb, 'Admin', 58, 880, 10, C.gold, 400);

  return sb;
}

/**
 * Barre de titre en haut
 */
function drawTopbar(parent, title, w = 1200) {
  const tb = addFrame(parent, 'Topbar', 0, 0, w, 56, C.bgPrimary);
  addRect(tb, 0, 55, w, 1, C.border);
  addTitle(tb, title, 28, 14, 16, C.textPrimary, true);
  // Icône notification
  addText(tb, '', w - 56, 16, 16, C.textMuted, 400);
  return tb;
}

/**
 * Conteneur principal après la sidebar
 */
function drawMain(parent, title, activeId) {
  const MAIN_W = 1440 - 240;
  const main = addFrame(parent, 'Main', 240, 0, MAIN_W, 900, C.bgPrimary);
  drawTopbar(main, title, MAIN_W);
  return main;
}

// ============================================================
// GÉNÉRATEURS DE PAGES — TOUTES LES 20 PAGES
// ============================================================

// ============================================================
// PAGE D'ACCUEIL — LANDING PAGE PUBLIQUE
// ============================================================
/**
 * Dessine la page d'accueil visible par les visiteurs non connectés.
 * Elle présente la valeur de la plateforme et incite à s'inscrire.
 *
 * Layout (1440 × 900) :
 *   - Navbar    :   0 →  68px  (fond blanc)
 *   - Hero      :  68 → 496px  (fond crème, colonne gauche + cartes déco droite)
 *   - Features  : 496 → 790px  (fond blanc, 3 cartes de fonctionnalités)
 *   - Footer    : 790 → 900px  (fond vert foncé)
 */
function fillHome(f) {
  setFill(f, C.bgSecondary); // fond crème par défaut

  // ===========================================================
  // 1. NAVBAR (y=0, h=68, fond blanc)
  // ===========================================================
  addRect(f, 0, 0, 1440, 68, C.bgPrimary);
  // Séparateur bas de navbar
  addRect(f, 0, 67, 1440, 1, C.border);

  // Logo (Playfair Display pour le côté éditorial)
  addTitle(f, 'BazaArt', 60, 20, 22, C.green, true);

  // Liens de navigation principaux (centrés)
  const navItems = ['Ressources', 'Evenements', 'Communaute', 'Articles'];
  navItems.forEach((item, i) => {
    addText(f, item, 460 + i * 130, 24, 13, C.textSecondary, 500);
  });

  // Boutons connexion / inscription dans la navbar
  addBtnSecondary(f, 'Se connecter', 1132, 14, 112);
  addBtnPrimary(f,   'Rejoindre',    1260, 14, 120);

  // ===========================================================
  // 2. HERO (y=68, h=428, fond crème)
  // ===========================================================
  // La crème est déjà le fond du frame complet.
  // On ajoute juste un rectangle crème explicite pour la zone hero.
  addRect(f, 0, 68, 1440, 428, C.bgSecondary);

  // --- Colonne gauche (x=60, largeur ~560) ---

  // Badge pill de positionnement
  const pill = addFrame(f, 'Badge hero', 60, 104, 300, 28, C.goldFaint);
  pill.cornerRadius = 999;
  pill.strokes = [{ type: 'SOLID', color: C.brown }];
  pill.strokeWeight = 1;
  addText(pill, 'Plateforme des artistes francophones', 14, 7, 10, C.gold, 600);

  // Titre principal (Playfair Display, 46px)
  addTitle(f, 'Trouvez vos financements,', 60, 146, 46, C.green, true);
  addTitle(f, 'residences et opportunites', 60, 202, 46, C.green, true);

  // Sous-titre descriptif
  addText(f, 'BazaArt centralise les ressources, appels a projets,', 60, 270, 14, C.textSecondary, 400, 520);
  addText(f, 'evenements et reseau pour les artistes francophones.', 60, 292, 14, C.textSecondary, 400, 520);

  // Boutons CTA principaux
  addBtnPrimary(f,   'Commencer gratuitement', 60,  330, 220);
  addBtnSecondary(f, 'Voir les ressources',    298, 330, 176);

  // Barre de statistiques
  // "247 ressources | 1 842 artistes | 93 articles"
  addText(f, '247',    60,  394, 22, C.textPrimary, 700);
  addText(f, 'ressources', 60, 424, 11, C.textMuted, 400);
  addRect(f, 156, 394, 1, 42, C.border); // séparateur vertical
  addText(f, '1 842',  172, 394, 22, C.textPrimary, 700);
  addText(f, 'artistes', 172, 424, 11, C.textMuted, 400);
  addRect(f, 268, 394, 1, 42, C.border); // séparateur vertical
  addText(f, '93',     284, 394, 22, C.textPrimary, 700);
  addText(f, 'articles', 284, 424, 11, C.textMuted, 400);

  // --- Colonne droite : cartes déco qui illustrent les ressources ---
  // Carte 1 (haut gauche)
  const c1 = addCard(f, 740, 90, 320, 148, 'Carte Bourse');
  addBadge(c1, 'Bourse', 16, 16, C.goldFaint, C.gold);
  addText(c1, 'Bourse de creation — DRAC', 16, 50, 13, C.textPrimary, 600, 284);
  addText(c1, 'Jusqu au 15 avril 2026', 16, 74, 11, C.textMuted, 400);
  addText(c1, 'Ile-de-France', 16, 94, 11, C.textMuted, 400);
  addRect(c1, 16, 116, 284, 1, C.border);
  addText(c1, 'Arts visuels · Pluridisciplinaire', 16, 126, 10, C.textMuted, 400);

  // Carte 2 (haut droite)
  const c2 = addCard(f, 1080, 136, 320, 148, 'Carte Residence');
  addBadge(c2, 'Residence', 16, 16, C.greenFaint, C.green);
  addText(c2, 'Residence Villa Medicis — Rome', 16, 50, 13, C.textPrimary, 600, 284);
  addText(c2, 'Jusqu au 30 mars 2026', 16, 74, 11, C.textMuted, 400);
  addText(c2, 'International', 16, 94, 11, C.textMuted, 400);
  addRect(c2, 16, 116, 284, 1, C.border);
  addText(c2, 'Litterature · Arts visuels', 16, 126, 10, C.textMuted, 400);

  // Carte 3 (milieu, chevauchement)
  const c3 = addCard(f, 864, 258, 364, 130, 'Carte Appel projets');
  addBadge(c3, 'Appel a projets', 16, 16, C.amberFaint, C.amber);
  addText(c3, 'Appel a projets — Ville de Lyon', 16, 50, 13, C.textPrimary, 600, 328);
  addText(c3, 'Cloture le 28 fevrier 2026 · Lyon', 16, 72, 11, C.textMuted, 400);
  addText(c3, 'Theatre · Danse · Musique', 16, 98, 10, C.textMuted, 400);

  // Petite carte "notification" bas droite
  const c4 = addCard(f, 1096, 306, 288, 74, 'Notif nouveau membre');
  addText(c4, 'Nouveau membre', 16, 12, 10, C.textMuted, 400);
  addText(c4, 'Marie C. a rejoint la communaute', 16, 30, 12, C.textPrimary, 600, 252);
  addText(c4, 'Il y a 2 minutes', 16, 52, 10, C.textMuted, 400);

  // ===========================================================
  // 3. SECTION FONCTIONNALITÉS (y=496, h=294, fond blanc)
  // ===========================================================
  addRect(f, 0, 496, 1440, 294, C.bgPrimary);
  addRect(f, 0, 496, 1440, 1, C.border); // ligne de séparation

  // Titre de section (centré)
  addTitle(f, 'Tout ce dont vous avez besoin', 468, 520, 26, C.textPrimary, true);

  // 3 cartes de fonctionnalités
  // Largeur totale : 3 × 400 + 2 × 40 = 1280 → marge gauche = (1440 - 1280) / 2 = 80
  const features = [
    {
      label: 'RESSOURCES',
      title: 'Financements & residences',
      desc: 'Bourses, appels a projets, residences artistiques — tout centralise en un seul endroit.',
      bg: C.goldFaint, color: C.gold,
    },
    {
      label: 'COMMUNAUTE',
      title: 'Reseau & communaute',
      desc: 'Rencontrez des artistes, partagez vos projets, echangez et lisez des articles de fond.',
      bg: C.greenFaint, color: C.green,
    },
    {
      label: 'EVENEMENTS',
      title: 'Agenda culturel',
      desc: 'Concerts, expositions, festivals, ateliers — ne ratez plus aucune occasion de vous exprimer.',
      bg: C.bgTertiary, color: C.textSecondary,
    },
  ];

  features.forEach((feat, i) => {
    const cx = 80 + i * (400 + 40);
    const fc = addCard(f, cx, 562, 400, 172, 'Feature ' + feat.label);

    // Pastille colorée (icône stylisée)
    addRect(fc, 20, 18, 36, 36, feat.bg, { radius: 8 });

    // Libellé de catégorie en majuscules + titre + description
    addText(fc, feat.label, 20, 66, 9, feat.color, 700);
    addText(fc, feat.title, 20, 84, 15, C.textPrimary, 600, 356);
    addText(fc, feat.desc, 20, 110, 12, C.textSecondary, 400, 356);
  });

  // ===========================================================
  // 4. FOOTER (y=790, h=110, vert foncé)
  // ===========================================================
  addRect(f, 0, 790, 1440, 110, C.sidebarBg);

  // Logo + tagline
  addTitle(f, 'BazaArt', 60, 808, 18, C.white, true);
  addText(f, 'La plateforme des artistes francophones', 60, 836, 11, C.sidebarMuted, 400);
  addText(f, '2026 — BazaArt. Tous droits reserves.', 60, 860, 10, C.sidebarMuted, 400);

  // Liens du footer à droite
  const footLinks = ['A propos', 'Conditions', 'Contact', 'API'];
  footLinks.forEach((lnk, i) => {
    addText(f, lnk, 1080 + i * 92, 832, 11, C.sidebarMuted, 400);
  });
}

function fillLogin(f) {
  setFill(f, C.bgSecondary);  // fond crème
  // Fond décoratif : panneau vert foncé à gauche (comme le hero du design de référence)
  addRect(f, 0, 0, 560, 900, C.sidebarBg);
  addRect(f, 560, 0, 880, 900, C.bgSecondary);

  // Texte décoratif sur le panneau vert
  addTitle(f, 'BazaArt', 60, 380, 42, C.white, true);
  addText(f, 'La plateforme des artistes', 60, 434, 14, C.sidebarMuted, 400);
  addText(f, 'francophones', 60, 456, 14, C.sidebarMuted, 400);
  // Badge vert sur le panneau
  const hbadge = addFrame(f, 'Badge', 60, 320, 180, 28, C.gold);
  hbadge.cornerRadius = 50;
  addText(hbadge, 'Nouveau sur BazaArt ?', 12, 7, 10, C.white, 600);

  // Titre de la card côté droit
  addTitle(f, 'Connexion', 700, 280, 28, C.textPrimary, true);
  addText(f, 'Ravis de vous revoir', 700, 318, 13, C.textSecondary, 400);

  // Card connexion (centrée dans la partie droite)
  const c = addCard(f, 640, 240, 440, 400, 'Login Card');
  c.cornerRadius = 16;

  addInput(c, 'Adresse e-mail', 'vous@exemple.com', 40, 102);
  addInput(c, 'Mot de passe', '••••••••', 40, 172);

  addText(c, 'Mot de passe oublié ?', 258, 228, 12, C.gold, 400);
  addBtnPrimary(c, 'Se connecter', 40, 254, 340);

  addRect(c, 40, 310, 155, 1, C.border);
  addText(c, 'ou', 196, 303, 12, C.textMuted, 400);
  addRect(c, 225, 310, 155, 1, C.border);

  addText(c, "Pas encore de compte ?", 80, 330, 13, C.textMuted, 400);
  addText(c, "S'inscrire →", 260, 330, 13, C.gold, 600);

  addText(c, 'Connexion Google', 40, 374, 13, C.textMuted, 400);
}

function fillRegister(f) {
  setFill(f, C.bgSecondary);  // fond crème
  addRect(f, 0, 0, 560, 900, C.sidebarBg);  // panneau vert à gauche
  addRect(f, 560, 0, 880, 900, C.bgSecondary);  // zone formulaire crème

  addTitle(f, 'BazaArt', 60, 380, 42, C.white, true);
  addText(f, 'Rejoignez la communaute', 60, 434, 14, C.sidebarMuted, 400);
  addText(f, 'des artistes francophones', 60, 456, 14, C.sidebarMuted, 400);

  addTitle(f, 'Creer un compte', 640, 80, 24, C.textPrimary, true);
  addText(f, 'Rejoignez la communaute des artistes francophones', 580, 116, 13, C.textSecondary, 400);

  const c = addCard(f, 620, 148, 460, 490, 'Register Card');
  c.cornerRadius = 16;

  addText(c, 'Je suis…', 40, 28, 13, C.textMuted, 400);
  const btnArtist = addFrame(c, 'Type Artiste', 40, 50, 172, 44, C.goldFaint);
  btnArtist.cornerRadius = 8;
  btnArtist.strokes = [{ type: 'SOLID', color: C.gold }]; btnArtist.strokeWeight = 1;
  addText(btnArtist, 'Artiste', 28, 12, 13, C.gold, 600);
  const btnOrg = addFrame(c, 'Type Org', 228, 50, 192, 44, C.bgTertiary);
  btnOrg.cornerRadius = 8;
  btnOrg.strokes = [{ type: 'SOLID', color: C.border }]; btnOrg.strokeWeight = 1;
  addText(btnOrg, 'Organisation', 28, 12, 13, C.textSecondary, 400);

  addInput(c, 'Nom affiché', 'ex : Marie Callot', 40, 118);
  addInput(c, 'Adresse e-mail', 'vous@exemple.com', 40, 188);
  addInput(c, 'Mot de passe', '••••••••', 40, 258);
  addInput(c, 'Confirmer le mot de passe', '••••••••', 40, 328);

  addBtnPrimary(c, 'Créer mon compte', 40, 398, 380);
  addText(c, 'Déjà un compte ? Se connecter →', 110, 452, 13, C.textMuted, 400);
}

function fillDashboard(f) {
  setFill(f, C.bgPrimary);
  drawSidebar(f, 'dashboard');
  const main = drawMain(f, 'Tableau de bord', 'dashboard');
  const MWIDTH = 1440 - 240;

  // Body
  const body = addFrame(main, 'Body', 28, 74, MWIDTH - 56, 800, C.bgPrimary);

  addTitle(body, 'Bonjour, Marie ✦', 0, 8, 26, C.textPrimary, true);
  addText(body, 'Voici les dernières opportunités et actualités de la communauté.', 0, 44, 13, C.textMuted, 400);

  // Stats
  const stats = [
    { label: 'Ressources publiées', val: '247', sub: '↑ +12 cette semaine' },
    { label: 'Membres actifs', val: '1 842', sub: '↑ +38 ce mois' },
    { label: 'Articles publiés', val: '93', sub: '' },
    { label: 'Mes soumissions', val: '4', sub: '' },
  ];
  const sW = Math.floor((MWIDTH - 56 - 3 * 16) / 4);
  for (let i = 0; i < stats.length; i++) {
    const sc = addCard(body, i * (sW + 16), 80, sW, 96, 'Stat');
    addText(sc, stats[i].label, 16, 14, 10, C.textMuted, 600);
    addText(sc, stats[i].val, 16, 32, 28, C.textPrimary, 700);
    if (stats[i].sub) addText(sc, stats[i].sub, 16, 68, 10, C.gold, 400);
  }

  // Deux colonnes
  const colW = Math.floor((MWIDTH - 56 - 20) / 2);
  const colY = 196;

  // Col gauche — ressources récentes
  const rc = addCard(body, 0, colY, colW, 300, 'Ressources récentes');
  addText(rc, 'Ressources récentes', 20, 18, 14, C.textPrimary, 600);
  addText(rc, 'Dernières opportunités validées', 20, 38, 11, C.textMuted, 400);
  addRect(rc, 20, 56, colW - 40, 1, C.border);

  const items = [
    { type: 'Residence', name: 'Villa Médicis 2025', date: '15 avr.' },
    { type: 'Financement', name: 'Bourse Fondation Cartier', date: '30 mars' },
    { type: 'Appel à projets', name: 'Festival Off Avignon', date: '1 mai' },
  ];
  for (let i = 0; i < items.length; i++) {
    const ry = 66 + i * 68;
    addText(rc, items[i].type.toUpperCase(), 20, ry + 2, 9, C.gold, 600);
    addText(rc, items[i].name, 20, ry + 18, 13, C.textPrimary, 600);
    addText(rc, '[t] ' + items[i].date, 20, ry + 36, 11, C.textMuted, 400);
    addText(rc, '→', colW - 36, ry + 20, 13, C.textMuted, 400);
    if (i < 2) addRect(rc, 20, ry + 58, colW - 40, 1, C.border);
  }

  // Col droite — communauté
  const cc = addCard(body, colW + 20, colY, colW, 300, 'Communauté');
  addText(cc, 'Communauté', 20, 18, 14, C.textPrimary, 600);
  addText(cc, 'Publications récentes', 20, 38, 11, C.textMuted, 400);
  addRect(cc, 20, 56, colW - 40, 1, C.border);

  const posts = [
    { initials: 'JT', name: 'Jean Tamba', time: '2h', msg: 'Quelqu\'un a de l\'expérience avec les bourses DRAC ?' },
    { initials: 'AL', name: 'Amara Ly', time: '1j', msg: 'Super conférence sur la médiation culturelle ce weekend !' },
    { initials: 'PD', name: 'Paula D.', time: '3j', msg: 'J\'ai publié un article sur les droits des artistes indépendants.' },
  ];
  for (let i = 0; i < posts.length; i++) {
    const py = 66 + i * 72;
    const av = addFrame(cc, 'Av', 20, py, 28, 28, C.brown);
    av.cornerRadius = 999;
    addText(av, posts[i].initials, 4, 8, 9, C.white, 700);
    addText(cc, posts[i].name, 56, py + 2, 12, C.textPrimary, 600);
    addText(cc, '· il y a ' + posts[i].time, 56 + posts[i].name.length * 7, py + 2, 11, C.textMuted, 400);
    addText(cc, posts[i].msg, 56, py + 20, 12, C.textSecondary, 400, colW - 80);
    if (i < 2) addRect(cc, 20, py + 60, colW - 40, 1, C.border);
  }
}

function fillArtistShow(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'artist-show');
  const main = drawMain(f, 'Profil Artiste', 'artist');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  // En-tête profil
  const header = addCard(body, 0, 8, MW - 56, 160, 'Profile Header');
  const avBig = addFrame(header, 'Avatar', 28, 28, 80, 80, C.brown);
  avBig.cornerRadius = 999;
  addText(avBig, 'MC', 24, 24, 22, C.white, 700);
  addText(header, 'Marie Callot', 128, 36, 22, C.textPrimary, 700);
  addText(header, '✦ Plasticienne · Paris, France', 128, 64, 13, C.gold, 400);
  addBadge(header, 'Arts visuels', 128, 92, C.goldFaint, C.gold);
  addBadge(header, 'Photographie', 230, 92, C.goldFaint, C.gold);
  addBtnPrimary(header, 'Modifier mon profil', MW - 56 - 40 - 180, 60, 180);

  // Colonnes
  const colW = Math.floor((MW - 56 - 20) / 2);

  // Bio
  const bioCard = addCard(body, 0, 188, colW, 220, 'Bio');
  addText(bioCard, 'Biographie', 20, 18, 14, C.textPrimary, 600);
  addRect(bioCard, 20, 42, colW - 40, 1, C.border);
  addText(bioCard, 'Plasticienne engagée, je travaille autour des\nquestions de mémoire et d\'identité culturelle.\nFormée à l\'École des Beaux-Arts de Paris, j\'ai\nexposé dans une vingtaine de lieux en France\net à l\'étranger.', 20, 56, 13, C.textSecondary, 400, colW - 40);

  // Portfolio
  const portCard = addCard(body, colW + 20, 188, colW, 220, 'Portfolio');
  addText(portCard, 'Liens', 20, 18, 14, C.textPrimary, 600);
  addRect(portCard, 20, 42, colW - 40, 1, C.border);

  const links = [
    { icon: 'www', label: 'Site web', val: 'www.mariecallot.fr' },
    { icon: '[P]', label: 'Portfolio', val: 'mariecallot.portfoliobox.net' },
    { icon: '[I]', label: 'Instagram', val: '@marie_callot_art' },
  ];
  for (let i = 0; i < links.length; i++) {
    addText(portCard, links[i].icon + '  ' + links[i].label, 20, 56 + i * 44, 12, C.textMuted, 400);
    addText(portCard, links[i].val, 20, 72 + i * 44, 13, C.gold, 400);
    if (i < 2) addRect(portCard, 20, 96 + i * 44, colW - 40, 1, C.border);
  }

  // Ressources suivies
  const resCard = addCard(body, 0, 428, MW - 56, 120, 'Ressources favoris');
  addText(resCard, 'Opportunités sauvegardées (3)', 20, 18, 14, C.textPrimary, 600);
  addRect(resCard, 20, 42, MW - 56 - 40, 1, C.border);
  addText(resCard, 'Résidence Villa Médicis 2025', 20, 56, 13, C.textPrimary, 600);
  addText(resCard, 'Bourse Fondation Cartier', 340, 56, 13, C.textPrimary, 600);
  addText(resCard, 'Festival Off Avignon 2025', 660, 56, 13, C.textPrimary, 600);
  addText(resCard, '↳ 15 avr.', 20, 74, 11, C.textMuted, 400);
  addText(resCard, '↳ 30 mars', 340, 74, 11, C.textMuted, 400);
  addText(resCard, '↳ 1 mai', 660, 74, 11, C.textMuted, 400);
}

function fillArtistEdit(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'artist-edit');
  const main = drawMain(f, 'Modifier mon profil artiste', 'artist');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  const formCard = addCard(body, 0, 8, MW - 56, 680, 'Form Card');
  addText(formCard, 'Informations de profil', 28, 24, 18, C.textPrimary, 700);
  addText(formCard, 'Ces informations sont visibles par tous les membres', 28, 52, 13, C.textMuted, 400);
  addRect(formCard, 28, 76, MW - 56 - 56, 1, C.border);

  // Photo de profil
  addText(formCard, 'PHOTO DE PROFIL', 28, 96, 10, C.textMuted, 600);
  const avEdit = addFrame(formCard, 'Av', 28, 114, 72, 72, C.brown);
  avEdit.cornerRadius = 999;
  addText(avEdit, 'MC', 20, 22, 18, C.white, 700);
  addBtnSecondary(formCard, 'Changer la photo', 116, 132, 180);

  const colW = Math.floor((MW - 56 - 56 - 20) / 2);

  addInput(formCard, 'Nom affiché', 'Marie Callot', 28, 206, colW);
  addInput(formCard, 'Localisation', 'Paris, France', colW + 56, 206, colW);
  addInput(formCard, 'Site web', 'https://www.mariecallot.fr', 28, 290, colW);
  addInput(formCard, 'Portfolio', 'https://...', colW + 56, 290, colW);

  // Biographie (textarea)
  addText(formCard, 'BIOGRAPHIE', 28, 374, 10, C.textMuted, 600);
  const ta = addFrame(formCard, 'Textarea', 28, 392, MW - 56 - 56, 100, C.bgInput);
  ta.cornerRadius = 6;
  ta.strokes = [{ type: 'SOLID', color: C.border }]; ta.strokeWeight = 1;
  addText(ta, 'Plasticienne engagée, je travaille autour des questions de mémoire...', 12, 12, 13, C.textSecondary, 400);

  // Disciplines
  addText(formCard, 'DISCIPLINES', 28, 508, 10, C.textMuted, 600);
  addBadge(formCard, 'Arts visuels ✕', 28, 526, C.goldFaint, C.gold);
  addBadge(formCard, 'Photographie ✕', 148, 526, C.goldFaint, C.gold);
  addText(formCard, '+ Ajouter une discipline', 280, 530, 12, C.gold, 400);

  // Actions
  addBtnPrimary(formCard, 'Enregistrer les modifications', 28, 610, 260);
  addBtnSecondary(formCard, 'Annuler', 304, 610, 100);
}

function fillOrgShow(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'org-show');
  const main = drawMain(f, 'Profil Organisation', 'org');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  // Header avec badge vérifié
  const header = addCard(body, 0, 8, MW - 56, 148, 'Org Header');
  const logoEl = addFrame(header, 'Logo', 24, 24, 80, 80, C.bgTertiary);
  logoEl.cornerRadius = 12;
  addText(logoEl, 'Org', 8, 8, 14, C.gold, 700);
  addText(header, 'Fondation des Arts Vivants', 124, 28, 20, C.textPrimary, 700);
  addBadge(header, '✓ Organisation vérifiée', 124, 56, C.greenFaint, C.green);
  addText(header, 'loc. Paris · contact@fondation-arts.fr', 124, 84, 13, C.textMuted, 400);
  addText(header, 'N° SIRET : 123 456 789 00010', 124, 104, 12, C.textMuted, 400);

  const colW = Math.floor((MW - 56 - 20) / 2);

  const descCard = addCard(body, 0, 176, colW, 240, 'Description');
  addText(descCard, 'Présentation', 20, 18, 14, C.textPrimary, 600);
  addRect(descCard, 20, 42, colW - 40, 1, C.border);
  addText(descCard, 'La Fondation des Arts Vivants soutient la\ncréation artistique contemporaine en France\net à l\'international. Résidences, financements\net mise en réseau pour artistes émergents.', 20, 56, 13, C.textSecondary, 400, colW - 40);

  const contactCard = addCard(body, colW + 20, 176, colW, 240, 'Contact');
  addText(contactCard, 'Contact', 20, 18, 14, C.textPrimary, 600);
  addRect(contactCard, 20, 42, colW - 40, 1, C.border);
  addText(contactCard, 'www fondation-arts.fr', 20, 56, 13, C.textSecondary, 400);
  addText(contactCard, '✉  contact@fondation-arts.fr', 20, 84, 13, C.textSecondary, 400);
  addText(contactCard, 'loc. 12 rue de la Paix, 75001 Paris', 20, 112, 13, C.textSecondary, 400);

  const resCard = addCard(body, 0, 436, MW - 56, 180, 'Ressources publiées');
  addText(resCard, 'Ressources publiées par cette organisation (8)', 20, 18, 14, C.textPrimary, 600);
  addRect(resCard, 20, 42, MW - 56 - 40, 1, C.border);
  const orgRes = [
    { t: 'Résidence', n: 'Résidence d\'été 2025' },
    { t: 'Financement', n: 'Bourse Émergences' },
    { t: 'Appel', n: 'Prix Jeune Création' },
  ];
  for (let i = 0; i < orgRes.length; i++) {
    addBadge(resCard, orgRes[i].t, 20 + i * 280, 56, C.goldFaint, C.gold);
    addText(resCard, orgRes[i].n, 20 + i * 280, 82, 13, C.textPrimary, 600);
    addText(resCard, 'Voir →', 20 + i * 280, 104, 12, C.gold, 400);
  }
}

function fillOrgEdit(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'org-edit');
  const main = drawMain(f, 'Modifier le profil organisation', 'org');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);
  const formCard = addCard(body, 0, 8, MW - 56, 660, 'Form');
  addText(formCard, 'Informations de l\'organisation', 28, 24, 18, C.textPrimary, 700);
  addRect(formCard, 28, 60, MW - 56 - 56, 1, C.border);

  const colW = Math.floor((MW - 56 - 56 - 20) / 2);
  addInput(formCard, 'Nom de l\'organisation', 'Fondation des Arts Vivants', 28, 80, colW);
  addInput(formCard, 'Numéro SIRET', '123 456 789 00010', colW + 56, 80, colW);
  addInput(formCard, 'Email de contact', 'contact@fondation-arts.fr', 28, 164, colW);
  addInput(formCard, 'Site web', 'https://fondation-arts.fr', colW + 56, 164, colW);
  addInput(formCard, 'Localisation', 'Paris, France', 28, 248, colW);

  addText(formCard, 'DESCRIPTION', 28, 336, 10, C.textMuted, 600);
  const ta = addFrame(formCard, 'Textarea', 28, 354, MW - 56 - 56, 100, C.bgInput);
  ta.cornerRadius = 6;
  ta.strokes = [{ type: 'SOLID', color: C.border }]; ta.strokeWeight = 1;
  addText(ta, 'La Fondation des Arts Vivants soutient...', 12, 12, 13, C.textSecondary, 400);

  // Vérification SIRET
  const verif = addFrame(formCard, 'Verif Banner', 28, 480, MW - 56 - 56, 56, C.greenFaint);
  verif.cornerRadius = 8;
  verif.strokes = [{ type: 'SOLID', color: C.green }]; verif.strokeWeight = 1;
  addText(verif, '✓ Votre organisation est vérifiée (SIRET validé)', 20, 18, 13, C.green, 600);

  addBtnPrimary(formCard, 'Enregistrer les modifications', 28, 568, 260);
  addBtnSecondary(formCard, 'Annuler', 304, 568, 100);
}

function fillResourcesList(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'resources-list');
  const main = drawMain(f, 'Ressources', 'resources');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  addTitle(body, 'Opportunités artistiques', 0, 8, 26, C.textPrimary, true);
  addText(body, '247 ressources disponibles pour les artistes francophones', 0, 44, 13, C.textMuted, 400);

  // Barre de recherche + filtres
  const search = addFrame(body, 'Search', 0, 78, 400, 40, C.bgCard);
  search.cornerRadius = 6;
  search.strokes = [{ type: 'SOLID', color: C.border }]; search.strokeWeight = 1;
  addText(search, 'Rechercher une opportunite...', 12, 12, 13, C.textMuted, 400);

  const filters = ['Tous types ▾', 'Toutes disciplines ▾', 'Tous pays ▾'];
  for (let i = 0; i < filters.length; i++) {
    const fd = addFrame(body, 'Filter', 416 + i * 152, 78, 140, 40, C.bgCard);
    fd.cornerRadius = 6;
    fd.strokes = [{ type: 'SOLID', color: C.border }]; fd.strokeWeight = 1;
    addText(fd, filters[i], 12, 12, 12, C.textSecondary, 400);
  }

  // Grille de cards (3 colonnes)
  const resItems = [
    { type: 'Residence', title: 'Villa Médicis 2025', desc: 'Programme annuel à Rome — logement, atelier et bourse mensuelle.', tags: ['Arts visuels', 'Littérature'], deadline: '15 avr. 2025' },
    { type: 'Financement', title: 'Bourse Fondation Cartier', desc: 'Soutien pour projets artistiques innovants en design et photo.', tags: ['Design', 'Photo'], deadline: '30 mars 2025' },
    { type: 'Appel a projets', title: 'Festival Off Avignon 2025', desc: 'Candidature ouverte — plus grand festival théâtral mondial.', tags: ['Théâtre', 'Danse'], deadline: '1 mai 2025' },
    { type: 'Formation', title: 'Masterclass INHA', desc: 'Formation intensive sur les pratiques de médiation culturelle.', tags: ['Médiation'], deadline: 'Gratuit' },
    { type: 'Bourse DRAC', title: 'Aide individuelle DRAC PACA', desc: 'Bourse régionale pour artistes professionnels. Jusqu\'à 8 000€.', tags: ['Arts visuels'], deadline: '15 juin 2025' },
    { type: 'Residence', title: 'Résidence La Friche', desc: 'Résidence 3 mois à la Friche La Belle de Mai, Marseille.', tags: ['Pluridisciplinaire'], deadline: '20 avr. 2025' },
  ];

  const cW = Math.floor((MW - 56 - 2 * 16) / 3);
  for (let i = 0; i < resItems.length; i++) {
    const col = i % 3;
    const row = Math.floor(i / 3);
    const rc = addCard(body, col * (cW + 16), 138 + row * 196, cW, 180, 'Resource Card');
    addText(rc, resItems[i].type, 16, 16, 10, C.gold, 600);
    addText(rc, resItems[i].title, 16, 34, 14, C.textPrimary, 600, cW - 32);
    addText(rc, resItems[i].desc, 16, 60, 12, C.textMuted, 400, cW - 32);
    addBadge(rc, resItems[i].tags[0], 16, 114, C.goldFaint, C.gold);
    addText(rc, '[t] ' + resItems[i].deadline, 16, 152, 11, C.amber, 500);
    addText(rc, '→', cW - 30, 152, 14, C.textMuted, 400);
  }
}

function fillResourceDetail(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'resources-list');
  const main = drawMain(f, 'Détail de la ressource', 'resources');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  // Breadcrumb
  addText(body, '← Ressources  /  Résidences  /  Villa Médicis 2025', 0, 4, 12, C.textMuted, 400);

  addTitle(body, 'Résidence Villa Médicis 2025', 0, 36, 26, C.textPrimary, true);
  addText(body, 'Académie de France à Rome — Programme annuel', 0, 72, 14, C.textMuted, 400);

  const colW = Math.floor((MW - 56 - 24) / 3);
  const mainW = colW * 2 + 24;

  // Contenu principal
  const descCard = addCard(body, 0, 104, mainW, 380, 'Description');
  addText(descCard, 'Présentation', 24, 20, 15, C.textPrimary, 600);
  addRect(descCard, 24, 46, mainW - 48, 1, C.border);
  addText(descCard, 'La Villa Médicis, siège de l\'Académie de France à Rome,\naccueille chaque année des artistes et créateurs français\nou ressortissants d\'un pays membre de l\'Union européenne.\n\nLes pensionnaires bénéficient d\'un logement, d\'un atelier\net d\'une bourse mensuelle pendant 12 à 18 mois.', 24, 60, 13, C.textSecondary, 400, mainW - 48);

  addText(descCard, 'CONDITIONS D\'ÉLIGIBILITÉ', 24, 200, 10, C.textMuted, 600);
  const conditions = ['Artiste de nationalité française ou UE', 'Moins de 40 ans (dérogations possibles)', 'Dossier artistique complet'];
  for (let i = 0; i < conditions.length; i++) {
    addText(descCard, '✓  ' + conditions[i], 24, 220 + i * 22, 13, C.textSecondary, 400);
  }

  addBtnPrimary(descCard, 'Candidater maintenant →', 24, 316, 220);
  addBtnSecondary(descCard, 'Sauvegarder', 260, 316, 160);

  // Sidebar droite
  const sideCard = addCard(body, mainW + 24, 104, colW, 380, 'Info Side');
  addText(sideCard, 'Informations clés', 20, 18, 14, C.textPrimary, 600);
  addRect(sideCard, 20, 42, colW - 40, 1, C.border);

  const infos = [
    ['Date limite', '15 avr. 2025'],
    ['Lieu', 'Rome, Italie'],
    ['⏱ Durée', '12 à 18 mois'],
    ['Bourse', 'Logement + atelier'],
    ['Type', 'Résidence'],
  ];
  for (let i = 0; i < infos.length; i++) {
    addText(sideCard, infos[i][0], 20, 56 + i * 52, 11, C.textMuted, 600);
    addText(sideCard, infos[i][1], 20, 74 + i * 52, 13, C.textPrimary, 600);
    if (i < 4) addRect(sideCard, 20, 98 + i * 52, colW - 40, 1, C.border);
  }
}

function fillResourceSubmit(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'resource-my');
  const main = drawMain(f, 'Soumettre une ressource', 'resource');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  addTitle(body, 'Soumettre une nouvelle ressource', 0, 8, 22, C.textPrimary, true);
  addText(body, 'Partagez une opportunité avec la communauté — elle sera vérifiée avant publication.', 0, 40, 13, C.textMuted, 400);

  const formCard = addCard(body, 0, 76, MW - 56, 588, 'Submit Form');
  const colW = Math.floor((MW - 56 - 56 - 20) / 2);

  addInput(formCard, 'Titre de la ressource', 'ex : Résidence Villa Médicis 2025', 28, 24, MW - 56 - 56);

  // Type + Disciplines
  addText(formCard, 'TYPE DE RESSOURCE', 28, 90, 10, C.textMuted, 600);
  const types = ['Résidence', 'Financement', 'Appel à projets', 'Formation', 'Bourse', 'Autre'];
  for (let i = 0; i < types.length; i++) {
    const selected = i === 0;
    const tb = addFrame(formCard, 'Type', 28 + i * 158, 108, 148, 36, selected ? C.goldFaint : C.bgTertiary);
    tb.cornerRadius = 6;
    tb.strokes = [{ type: 'SOLID', color: selected ? C.gold : C.border }]; tb.strokeWeight = 1;
    addText(tb, types[i], 16, 10, 12, selected ? C.gold : C.textSecondary, selected ? 600 : 400);
  }

  addInput(formCard, 'Organisation', 'Nom de l\'organisation', 28, 168, colW);
  addInput(formCard, 'Date limite', 'JJ/MM/AAAA', colW + 56, 168, colW);

  addText(formCard, 'DESCRIPTION', 28, 254, 10, C.textMuted, 600);
  const ta = addFrame(formCard, 'Textarea', 28, 272, MW - 56 - 56, 120, C.bgInput);
  ta.cornerRadius = 6;
  ta.strokes = [{ type: 'SOLID', color: C.border }]; ta.strokeWeight = 1;
  addText(ta, 'Décrivez cette ressource : objectifs, conditions, montant, durée…', 12, 12, 13, C.textMuted, 400);

  addInput(formCard, 'URL externe (lien officiel)', 'https://...', 28, 420, colW * 2 + 20);

  const notice = addFrame(formCard, 'Notice', 28, 492, MW - 56 - 56, 44, C.amberFaint);
  notice.cornerRadius = 8;
  addText(notice, '⚠  La ressource sera vérifiée par un administrateur avant d\'être publiée.', 16, 14, 12, C.amber, 400);

  addBtnPrimary(formCard, 'Soumettre la ressource', 28, 552, 220);
  addBtnSecondary(formCard, 'Annuler', 264, 552, 120);
}

function fillResourceMy(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'resource-my');
  const main = drawMain(f, 'Mes soumissions', 'resource');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  addTitle(body, 'Mes soumissions', 0, 8, 22, C.textPrimary, true);
  addText(body, '4 ressources soumises · 2 validées · 1 en attente · 1 rejetée', 0, 40, 13, C.textMuted, 400);
  addBtnPrimary(body, '+ Nouvelle soumission', MW - 56 - 180, 8, 180);

  // Stats mini
  const statItems = [
    { label: 'Validées', val: '2', color: C.green },
    { label: 'En attente', val: '1', color: C.amber },
    { label: 'Rejetées', val: '1', color: C.red },
  ];
  for (let i = 0; i < statItems.length; i++) {
    const sc = addCard(body, i * 180, 76, 160, 72, 'Mini stat');
    addText(sc, statItems[i].val, 20, 14, 28, statItems[i].color, 700);
    addText(sc, statItems[i].label, 20, 48, 11, C.textMuted, 400);
  }

  // Liste
  const submissions = [
    { title: 'Résidence Villa Médicis 2025', type: 'Résidence', status: '✓ Validée', statusColor: C.green, statusBg: C.greenFaint, date: '12 janv. 2025' },
    { title: 'Bourse Fondation du Patrimoine', type: 'Financement', status: '✓ Validée', statusColor: C.green, statusBg: C.greenFaint, date: '3 févr. 2025' },
    { title: 'Prix de la Jeune Création', type: 'Appel à projets', status: '⏳ En attente', statusColor: C.amber, statusBg: C.amberFaint, date: '20 févr. 2025' },
    { title: 'Masterclass Peinture — Atelier 17', type: 'Formation', status: '✗ Rejetée', statusColor: C.red, statusBg: C.redFaint, date: '5 mars 2025' },
  ];

  for (let i = 0; i < submissions.length; i++) {
    const row = addCard(body, 0, 172 + i * 72, MW - 56, 60, 'Row');
    row.cornerRadius = 8;
    addText(row, submissions[i].type.toUpperCase(), 20, 12, 9, C.gold, 600);
    addText(row, submissions[i].title, 20, 28, 14, C.textPrimary, 600);
    addBadge(row, submissions[i].status, MW - 56 - 160, 18, submissions[i].statusBg, submissions[i].statusColor);
    addText(row, 'Soumis le ' + submissions[i].date, MW - 56 - 320, 22, 11, C.textMuted, 400);
  }
}

function fillCommunity(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'community');
  const main = drawMain(f, 'Communauté', 'community');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);
  const feedW = Math.floor((MW - 56) * 0.65);
  const sideW = MW - 56 - feedW - 24;

  // Composeur de post
  const composer = addCard(body, 0, 8, feedW, 100, 'Composer');
  const av = addFrame(composer, 'Av', 16, 16, 36, 36, C.brown);
  av.cornerRadius = 999;
  addText(av, 'MC', 10, 10, 12, C.white, 700);
  const inp = addFrame(composer, 'Input', 64, 16, feedW - 80, 36, C.bgTertiary);
  inp.cornerRadius = 18;
  addText(inp, 'Partagez quelque chose avec la communauté…', 16, 10, 13, C.textMuted, 400);
  addBtnPrimary(composer, 'Publier', feedW - 96, 62, 80);

  // Posts
  const postsData = [
    { initials: 'JT', name: 'Jean Tamba', time: 'il y a 2h', msg: 'Quelqu\'un a de l\'expérience avec les bourses DRAC Île-de-France ? Je prépare un dossier pour la prochaine session et je cherche des retours d\'expérience !', likes: '12', comments: '4' },
    { initials: 'AL', name: 'Amara Ly', time: 'hier', msg: 'Super conférence sur la médiation culturelle ce weekend à la Gaîté Lyrique ! Des rencontres inspirantes, des projets ambitieux. Merci à tous les intervenants ✦', likes: '34', comments: '8' },
    { initials: 'PD', name: 'Paula Diaz', time: 'il y a 3j', msg: 'J\'ai publié un article sur les droits des artistes indépendants face aux plateformes numériques. Vos retours sont les bienvenus !', likes: '28', comments: '15' },
  ];

  for (let i = 0; i < postsData.length; i++) {
    const pc = addCard(body, 0, 128 + i * 188, feedW, 172, 'Post Card');
    const pav = addFrame(pc, 'Av', 16, 16, 36, 36, C.brown);
    pav.cornerRadius = 999;
    addText(pav, postsData[i].initials, 9, 10, 12, C.white, 700);
    addText(pc, postsData[i].name, 62, 18, 13, C.textPrimary, 600);
    addText(pc, postsData[i].time, 62 + postsData[i].name.length * 8, 18, 12, C.textMuted, 400);
    addText(pc, postsData[i].msg, 16, 62, 13, C.textSecondary, 400, feedW - 32);
    addRect(pc, 16, 136, feedW - 32, 1, C.border);
    addText(pc, postsData[i].likes + ' j\'aime', 16, 148, 12, C.textMuted, 400);
    addText(pc, postsData[i].comments + ' comm.', 72, 148, 12, C.textMuted, 400);
    addText(pc, 'Partager', feedW - 80, 148, 12, C.textMuted, 400);
  }

  // Sidebar droite
  const trendCard = addCard(body, feedW + 24, 8, sideW, 240, 'Trending');
  addText(trendCard, 'Sujets populaires', 16, 16, 13, C.textPrimary, 600);
  addRect(trendCard, 16, 40, sideW - 32, 1, C.border);
  const trends = ['#BoursesDRAC', '#RésidenceArtiste', '#AppelAProjets', '#MédiationCulturelle', '#ArtisteIndépendant'];
  for (let i = 0; i < trends.length; i++) {
    addText(trendCard, trends[i], 16, 52 + i * 34, 13, C.gold, 400);
    addText(trendCard, String(Math.floor(Math.random() * 40) + 5) + ' posts', sideW - 70, 52 + i * 34, 11, C.textMuted, 400);
  }
}

function fillArticlesList(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'articles-list');
  const main = drawMain(f, 'Articles', 'articles');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);
  addTitle(body, 'Articles de la communauté', 0, 8, 24, C.textPrimary, true);
  addText(body, '93 articles publiés par les membres', 0, 42, 13, C.textMuted, 400);
  addBtnPrimary(body, '+ Ecrire un article', MW - 56 - 180, 8, 180);

  const articleItems = [
    { cat: 'Droits', title: 'Les droits des artistes face aux plateformes numériques', author: 'Paula Diaz', date: '7 mars 2025', read: '8 min', likes: '42' },
    { cat: 'Financement', title: 'Comment préparer un dossier de bourse DRAC ?', author: 'Jean Tamba', date: '28 févr. 2025', read: '12 min', likes: '67' },
    { cat: 'Résidence', title: 'Retour d\'expérience : 6 mois à la Villa Médicis', author: 'Sophie Martin', date: '15 févr. 2025', read: '15 min', likes: '89' },
    { cat: 'Réseau', title: 'Construire son réseau professionnel en tant qu\'artiste', author: 'Amara Ly', date: '2 févr. 2025', read: '7 min', likes: '53' },
  ];

  const aW = Math.floor((MW - 56 - 16) / 2);
  for (let i = 0; i < articleItems.length; i++) {
    const col = i % 2;
    const row = Math.floor(i / 2);
    const ac = addCard(body, col * (aW + 16), 78 + row * 164, aW, 148, 'Article Card');
    addBadge(ac, articleItems[i].cat, 16, 16, C.goldFaint, C.gold);
    addText(ac, articleItems[i].title, 16, 46, 14, C.textPrimary, 600, aW - 32);
    addRect(ac, 16, 96, aW - 32, 1, C.border);
    addText(ac, articleItems[i].author, 16, 108, 12, C.textSecondary, 500);
    addText(ac, articleItems[i].date + '  ·  ' + articleItems[i].read + ' de lecture', 16, 124, 11, C.textMuted, 400);
    addText(ac, articleItems[i].likes + ' j\'aime', aW - 72, 122, 11, C.textMuted, 400);
  }
}

function fillArticleShow(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'articles-list');
  const main = drawMain(f, 'Lire un article', 'articles');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 60, 74, MW - 120, 800, C.bgPrimary);

  addText(body, '← Articles', 0, 4, 12, C.textMuted, 400);
  addBadge(body, 'Droits & Réglementation', 0, 30, C.goldFaint, C.gold);
  addTitle(body, 'Les droits des artistes face aux\nplateformes numériques', 0, 64, 28, C.textPrimary, true);
  addText(body, 'Paula Diaz  ·  7 mars 2025  ·  8 min de lecture  ·  42 ♥', 0, 132, 13, C.textMuted, 400);
  addRect(body, 0, 160, MW - 120, 1, C.border);

  addText(body, 'Introduction', 0, 180, 16, C.textPrimary, 600);
  addText(body, 'Les artistes indépendants font face à des défis croissants dans\nl\'environnement numérique actuel. Entre les droits de diffusion,\nla rémunération des contenus créatifs et les contrats abusifs\ndes grandes plateformes, il est essentiel de bien se protéger.', 0, 204, 13, C.textSecondary, 400, MW - 120);

  addText(body, 'Les droits fondamentaux', 0, 302, 16, C.textPrimary, 600);
  addText(body, 'En France, le Code de la propriété intellectuelle garantit à\ntout artiste des droits moraux inaliénables sur ses œuvres.\nCes droits incluent le droit à la paternité, le droit au respect\nde l\'intégrité de l\'œuvre et le droit de divulgation.', 0, 326, 13, C.textSecondary, 400, MW - 120);

  addText(body, 'Bonnes pratiques', 0, 434, 16, C.textPrimary, 600);
  const tips = ['Toujours signer un contrat avant toute cession de droits', 'Consulter un avocat spécialisé en droit d\'auteur', 'Enregistrer vos créations auprès de l\'ADAGP ou SACEM'];
  for (let i = 0; i < tips.length; i++) {
    addText(body, '→  ' + tips[i], 0, 460 + i * 28, 13, C.textSecondary, 400);
  }

  addRect(body, 0, 560, MW - 120, 1, C.border);
  addText(body, 'Vous avez aimé cet article ?', 0, 576, 14, C.textPrimary, 600);
  addText(body, '42 personnes ont aime', 0, 600, 13, C.textMuted, 400);
  addBtnPrimary(body, 'J\'aime', 0, 630, 120);
  addBtnSecondary(body, 'Partager', 136, 630, 120);
}

function fillArticleNew(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'articles-list');
  const main = drawMain(f, 'Écrire un article', 'articles');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);
  addTitle(body, 'Écrire un nouvel article', 0, 8, 22, C.textPrimary, true);
  addText(body, 'Partagez vos connaissances et expériences avec la communauté.', 0, 40, 13, C.textMuted, 400);

  const editorCard = addCard(body, 0, 76, MW - 56, 600, 'Editor Card');

  // Titre
  addText(editorCard, 'TITRE DE L\'ARTICLE', 24, 20, 10, C.textMuted, 600);
  const titleField = addFrame(editorCard, 'Title Input', 24, 38, MW - 56 - 48, 48, C.bgTertiary);
  titleField.cornerRadius = 6;
  titleField.strokes = [{ type: 'SOLID', color: C.border }]; titleField.strokeWeight = 1;
  addText(titleField, 'Donnez un titre accrocheur à votre article…', 16, 14, 16, C.textMuted, 400);

  // Catégorie
  const colW = Math.floor((MW - 56 - 48 - 16) / 2);
  addText(editorCard, 'CATÉGORIE', 24, 104, 10, C.textMuted, 600);
  const catSel = addFrame(editorCard, 'Cat Select', 24, 122, colW, 40, C.bgTertiary);
  catSel.cornerRadius = 6;
  catSel.strokes = [{ type: 'SOLID', color: C.border }]; catSel.strokeWeight = 1;
  addText(catSel, 'Choisir une catégorie ▾', 12, 12, 13, C.textMuted, 400);

  // Toolbar éditeur
  addRect(editorCard, 24, 182, MW - 56 - 48, 1, C.border);
  const tools = ['B', 'I', 'U', 'H1', 'H2', '•—•', 'lien', 'IMG'];
  for (let i = 0; i < tools.length; i++) {
    addText(editorCard, tools[i], 24 + i * 48, 192, 12, C.textMuted, i < 3 ? 700 : 400);
  }
  addRect(editorCard, 24, 210, MW - 56 - 48, 1, C.border);

  // Zone d'édition
  addText(editorCard, 'Rédigez votre article ici. Vous pouvez utiliser la barre d\'outils pour\nformater votre texte, ajouter des liens et des images.', 24, 224, 13, C.textMuted, 400);

  addBtnPrimary(editorCard, 'Publier l\'article', 24, 544, 180);
  addBtnSecondary(editorCard, 'Enregistrer le brouillon', 220, 544, 200);
  addBtnSecondary(editorCard, 'Annuler', 436, 544, 100);
}

function fillArticleMy(f) {
  setFill(f, C.bgSecondary);
  drawSidebar(f, 'article-my');
  const main = drawMain(f, 'Mes articles', 'articles');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);
  addTitle(body, 'Mes articles', 0, 8, 22, C.textPrimary, true);
  addText(body, '3 articles publiés · 1 brouillon', 0, 40, 13, C.textMuted, 400);
  addBtnPrimary(body, '+ Nouvel article', MW - 56 - 160, 8, 160);

  const myArticles = [
    { title: 'Les droits des artistes face aux plateformes numériques', status: '● Publié', statusColor: C.green, statusBg: C.greenFaint, date: '7 mars 2025', views: '234', likes: '42' },
    { title: 'Retour sur ma résidence à la Fondation de France', status: '● Publié', statusColor: C.green, statusBg: C.greenFaint, date: '20 févr. 2025', views: '187', likes: '29' },
    { title: 'Comment j\'ai décroché ma première subvention', status: '● Publié', statusColor: C.green, statusBg: C.greenFaint, date: '5 janv. 2025', views: '412', likes: '78' },
    { title: 'Mon projet pour la DRAC — brouillon en cours', status: '◌ Brouillon', statusColor: C.amber, statusBg: C.amberFaint, date: 'Modifié hier', views: '—', likes: '—' },
  ];

  for (let i = 0; i < myArticles.length; i++) {
    const row = addCard(body, 0, 76 + i * 76, MW - 56, 64, 'Article Row');
    row.cornerRadius = 8;
    addText(row, myArticles[i].title, 20, 14, 14, C.textPrimary, 600, MW - 56 - 400);
    addBadge(row, myArticles[i].status, MW - 56 - 420, 18, myArticles[i].statusBg, myArticles[i].statusColor);
    addText(row, myArticles[i].date, MW - 56 - 280, 22, 12, C.textMuted, 400);
    addText(row, myArticles[i].views + ' vues', MW - 56 - 170, 22, 12, C.textMuted, 400);
    addText(row, myArticles[i].likes + ' j\'aime', MW - 56 - 80, 22, 12, C.textMuted, 400);
    addText(row, '···', MW - 56 - 30, 18, 16, C.textMuted, 700);
  }
}

function fillAdminDashboard(f) {
  setFill(f, C.bgSecondary);
  drawAdminSidebar(f, 'admin-dashboard');
  const main = drawMain(f, 'Dashboard Administrateur', 'admin');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);
  addTitle(body, 'Vue d\'ensemble', 0, 8, 24, C.textPrimary, true);
  addText(body, 'Plateforme BazaArt · Données en temps réel', 0, 42, 13, C.textMuted, 400);

  // Stats admin (6 cards)
  const adminStats = [
    { label: 'Utilisateurs total', val: '1 842', delta: '↑ +38', color: C.textPrimary },
    { label: 'Ressources publiées', val: '247', delta: '↑ +12', color: C.textPrimary },
    { label: 'En attente modération', val: '7', delta: '⚠ Urgent', color: C.red },
    { label: 'Organisations vérif.', val: '124', delta: '', color: C.textPrimary },
    { label: 'Articles publiés', val: '93', delta: '', color: C.textPrimary },
    { label: 'Membres artistes', val: '1 437', delta: '', color: C.textPrimary },
  ];

  const sW = Math.floor((MW - 56 - 5 * 12) / 6);
  for (let i = 0; i < adminStats.length; i++) {
    const sc = addCard(body, i * (sW + 12), 76, sW, 96, 'Admin Stat');
    if (i === 2) {
      sc.strokes = [{ type: 'SOLID', color: C.red }]; sc.strokeWeight = 1;
    }
    addText(sc, adminStats[i].label, 14, 12, 10, C.textMuted, 600, sW - 28);
    addText(sc, adminStats[i].val, 14, 30, 26, adminStats[i].color, 700);
    if (adminStats[i].delta) addText(sc, adminStats[i].delta, 14, 66, 10, i === 2 ? C.red : C.gold, 400);
  }

  // Activité récente + Ressources en attente
  const colW = Math.floor((MW - 56 - 20) / 2);
  const colY = 192;

  const pendingCard = addCard(body, 0, colY, colW, 320, 'Pending');
  addText(pendingCard, '⏳ Ressources en attente (7)', 16, 16, 13, C.textPrimary, 600);
  addRect(pendingCard, 16, 40, colW - 32, 1, C.border);
  const pendingItems = [
    { name: 'Prix de la Jeune Création', sub: 'Soumis par Jean Tamba' },
    { name: 'Résidence Franche-Comté', sub: 'Soumis par Sophie M.' },
    { name: 'Bourse DRAC Grand Est', sub: 'Soumis par Marc L.' },
  ];
  for (let i = 0; i < pendingItems.length; i++) {
    const pr = addFrame(pendingCard, 'PRow', 16, 52 + i * 72, colW - 32, 56, C.bgTertiary);
    pr.cornerRadius = 6;
    addText(pr, pendingItems[i].name, 12, 10, 13, C.textPrimary, 600);
    addText(pr, pendingItems[i].sub, 12, 28, 11, C.textMuted, 400);
    addBtnPrimary(pr, 'Valider', colW - 32 - 180, 10, 80);
    addBtnSecondary(pr, 'Rejeter', colW - 32 - 92, 10, 76);
  }

  const usersCard = addCard(body, colW + 20, colY, colW, 320, 'New Users');
  addText(usersCard, 'Nouveaux utilisateurs (38 ce mois)', 16, 16, 13, C.textPrimary, 600);
  addRect(usersCard, 16, 40, colW - 32, 1, C.border);
  const newUsers = [
    { name: 'Marie Dubois', type: 'Artiste', date: 'Aujourd\'hui' },
    { name: 'Fondation Lumière', type: 'Organisation', date: 'Hier' },
    { name: 'Carlos Rivera', type: 'Artiste', date: 'Il y a 2j' },
    { name: 'Studio K2', type: 'Organisation', date: 'Il y a 3j' },
  ];
  for (let i = 0; i < newUsers.length; i++) {
    const ur = addFrame(usersCard, 'URow', 16, 52 + i * 62, colW - 32, 48, C.bgTertiary);
    ur.cornerRadius = 6;
    const av = addFrame(ur, 'Av', 10, 10, 28, 28, C.brown);
    av.cornerRadius = 999;
    addText(av, newUsers[i].name.slice(0, 2).toUpperCase(), 4, 8, 9, C.white, 700);
    addText(ur, newUsers[i].name, 46, 10, 13, C.textPrimary, 600);
    addBadge(ur, newUsers[i].type, 46, 28, C.goldFaint, C.gold);
    addText(ur, newUsers[i].date, colW - 32 - 100, 18, 11, C.textMuted, 400);
  }
}

function fillAdminPending(f) {
  setFill(f, C.bgSecondary);
  drawAdminSidebar(f, 'admin-pending');
  const main = drawMain(f, 'Modération des ressources', 'admin');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  // Bandeau d'alerte
  const alert = addFrame(body, 'Alert', 0, 8, MW - 56, 48, C.redFaint);
  alert.cornerRadius = 8;
  alert.strokes = [{ type: 'SOLID', color: C.red }]; alert.strokeWeight = 1;
  addText(alert, '⚠  7 ressources en attente de validation — Traitement recommandé dans les 48h', 20, 16, 13, C.red, 400);

  // Filtres
  const tabY = 76;
  const tabs = ['Toutes (7)', 'Ressources (5)', 'Articles (2)'];
  for (let i = 0; i < tabs.length; i++) {
    const isActive = i === 0;
    const tb = addFrame(body, 'Tab', i * 148, tabY, 136, 36, isActive ? C.goldFaint : C.bgCard);
    tb.cornerRadius = 6;
    tb.strokes = [{ type: 'SOLID', color: isActive ? C.gold : C.border }]; tb.strokeWeight = 1;
    addText(tb, tabs[i], 16, 10, 13, isActive ? C.gold : C.textSecondary, isActive ? 600 : 400);
  }

  // Liste des soumissions en attente
  const pending = [
    { title: 'Prix de la Jeune Création', type: 'Appel à projets', author: 'Jean Tamba', date: '8 mars' },
    { title: 'Résidence Franche-Comté 2025', type: 'Résidence', author: 'Sophie Martin', date: '7 mars' },
    { title: 'Bourse DRAC Grand Est', type: 'Financement', author: 'Marc Leroy', date: '6 mars' },
    { title: 'Formation Médiation — INHA', type: 'Formation', author: 'Paula Diaz', date: '5 mars' },
    { title: 'Festival Musiques du Monde', type: 'Appel à projets', author: 'Amara Ly', date: '4 mars' },
  ];

  for (let i = 0; i < pending.length; i++) {
    const row = addCard(body, 0, 132 + i * 80, MW - 56, 68, 'Pending Row');
    row.cornerRadius = 8;
    addBadge(row, pending[i].type, 20, 16, C.goldFaint, C.gold);
    addText(row, pending[i].title, 20, 40, 14, C.textPrimary, 600);
    addText(row, 'Soumis par ' + pending[i].author + '  ·  ' + pending[i].date, 20, 56, 11, C.textMuted, 400);
    addBtnPrimary(row, '✓ Valider', MW - 56 - 264, 18, 120);
    addBtnSecondary(row, '✗ Rejeter', MW - 56 - 136, 18, 112);
    addText(row, 'Voir', MW - 56 - 30, 22, 12, C.textMuted, 400);
  }
}

function fillAdminResources(f) {
  setFill(f, C.bgSecondary);
  drawAdminSidebar(f, 'admin-resources');
  const main = drawMain(f, 'Toutes les ressources', 'admin');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  // Stats mini + actions
  const miniStats = [
    { label: 'Total', val: '247', color: C.textPrimary },
    { label: 'Validées', val: '236', color: C.green },
    { label: 'En attente', val: '7', color: C.amber },
    { label: 'Rejetées', val: '4', color: C.red },
  ];
  for (let i = 0; i < miniStats.length; i++) {
    const sc = addCard(body, i * 200, 8, 180, 64, 'Mini');
    addText(sc, miniStats[i].val, 16, 10, 24, miniStats[i].color, 700);
    addText(sc, miniStats[i].label, 16, 42, 10, C.textMuted, 600);
  }
  addBtnPrimary(body, '+ Ajouter (Admin)', MW - 56 - 180, 18, 178);

  // Tableau
  const tableY = 92;
  const headers = ['Titre', 'Type', 'Soumis par', 'Date', 'Statut', 'Actions'];
  const hWidths = [300, 120, 160, 100, 120, 200];
  let hX = 0;
  const thead = addFrame(body, 'THead', 0, tableY, MW - 56, 36, C.bgSecondary);
  thead.cornerRadius = 8;
  for (let i = 0; i < headers.length; i++) {
    addText(thead, headers[i].toUpperCase(), hX + 16, 12, 10, C.textMuted, 600);
    hX += hWidths[i];
  }

  const tableRows = [
    { title: 'Résidence Villa Médicis', type: 'Résidence', author: 'Admin', date: '12 janv.', status: '✓ Validée', statusColor: C.green, statusBg: C.greenFaint },
    { title: 'Bourse Fondation Cartier', type: 'Financement', author: 'Jean Tamba', date: '3 févr.', status: '✓ Validée', statusColor: C.green, statusBg: C.greenFaint },
    { title: 'Prix Jeune Création', type: 'Appel à projets', author: 'Paula Diaz', date: '8 mars', status: '⏳ En attente', statusColor: C.amber, statusBg: C.amberFaint },
    { title: 'Formation Illus. Num.', type: 'Formation', author: 'Marc L.', date: '5 mars', status: '✗ Rejetée', statusColor: C.red, statusBg: C.redFaint },
    { title: 'Aide DRAC PACA', type: 'Financement', author: 'Admin', date: '1 mars', status: '✓ Validée', statusColor: C.green, statusBg: C.greenFaint },
  ];

  for (let i = 0; i < tableRows.length; i++) {
    const row = addFrame(body, 'Row', 0, tableY + 36 + i * 52, MW - 56, 44, i % 2 === 0 ? C.bgCard : C.bgPrimary);
    const vals = [tableRows[i].title, tableRows[i].type, tableRows[i].author, tableRows[i].date, '', ''];
    let cX = 0;
    for (let j = 0; j < vals.length; j++) {
      if (j === 4) {
        addBadge(row, tableRows[i].status, cX + 16, 12, tableRows[i].statusBg, tableRows[i].statusColor);
      } else if (j === 5) {
        addText(row, '✏ Éditer', cX + 16, 14, 12, C.gold, 400);
        addText(row, 'Suppr.', cX + 88, 14, 12, C.red, 400);
      } else {
        addText(row, vals[j], cX + 16, 14, 13, C.textPrimary, j === 0 ? 600 : 400, hWidths[j] - 20);
      }
      cX += hWidths[j];
    }
  }
}

function fillAdminUsers(f) {
  setFill(f, C.bgSecondary);
  drawAdminSidebar(f, 'admin-users');
  const main = drawMain(f, 'Gestion des utilisateurs', 'admin');
  const MW = 1440 - 240;

  const body = addFrame(main, 'Body', 28, 74, MW - 56, 800, C.bgPrimary);

  // Barre de recherche
  const search = addFrame(body, 'Search', 0, 8, 360, 40, C.bgCard);
  search.cornerRadius = 6;
  search.strokes = [{ type: 'SOLID', color: C.border }]; search.strokeWeight = 1;
  addText(search, 'Rechercher un utilisateur...', 12, 12, 13, C.textMuted, 400);

  const roleFilters = ['Tous les rôles ▾', 'Artistes ▾', 'Organisations ▾', 'Admins ▾'];
  for (let i = 0; i < roleFilters.length; i++) {
    const rf = addFrame(body, 'RF', 376 + i * 152, 8, 140, 40, C.bgCard);
    rf.cornerRadius = 6;
    rf.strokes = [{ type: 'SOLID', color: C.border }]; rf.strokeWeight = 1;
    addText(rf, roleFilters[i], 10, 12, 12, C.textSecondary, 400);
  }

  // Stats
  const uStats = [
    { label: 'Total membres', val: '1 842' },
    { label: 'Artistes', val: '1 437' },
    { label: 'Organisations', val: '381' },
    { label: 'Admins', val: '24' },
  ];
  for (let i = 0; i < uStats.length; i++) {
    const sc = addCard(body, i * 200, 66, 180, 64, 'UStat');
    addText(sc, uStats[i].val, 16, 10, 22, C.textPrimary, 700);
    addText(sc, uStats[i].label, 16, 40, 10, C.textMuted, 600);
  }

  // Tableau utilisateurs
  const headers = ['Utilisateur', 'Rôle', 'Email', 'Inscrit le', 'Statut', 'Actions'];
  const hW = [220, 120, 220, 100, 120, 180];
  const thead = addFrame(body, 'THead', 0, 152, MW - 56, 36, C.bgSecondary);
  thead.cornerRadius = 8;
  let hX = 0;
  for (let i = 0; i < headers.length; i++) {
    addText(thead, headers[i].toUpperCase(), hX + 12, 12, 10, C.textMuted, 600);
    hX += hW[i];
  }

  const users = [
    { name: 'Marie Callot', role: 'Artiste', email: 'm.callot@mail.fr', date: '12 janv. 2024', status: '● Actif', sc: C.green, sb: C.greenFaint },
    { name: 'Jean Tamba', role: 'Artiste', email: 'jean.tamba@mail.fr', date: '5 mars 2024', status: '● Actif', sc: C.green, sb: C.greenFaint },
    { name: 'Fondation Arts Vivants', role: 'Organisation', email: 'contact@fav.fr', date: '20 févr. 2024', status: '● Actif', sc: C.green, sb: C.greenFaint },
    { name: 'Paula Diaz', role: 'Artiste', email: 'p.diaz@mail.fr', date: '8 avr. 2024', status: '|| Suspendu', sc: C.amber, sb: C.amberFaint },
    { name: 'Admin Principal', role: 'Admin', email: 'admin@bazaart.fr', date: '1 janv. 2024', status: '● Actif', sc: C.amber, sb: C.amberFaint },
  ];

  for (let i = 0; i < users.length; i++) {
    const row = addFrame(body, 'URow', 0, 188 + i * 52, MW - 56, 44, i % 2 === 0 ? C.bgCard : C.bgPrimary);
    // Avatar + nom
    const av = addFrame(row, 'Av', 12, 8, 26, 26, C.brown);
    av.cornerRadius = 999;
    addText(av, users[i].name.slice(0, 2).toUpperCase(), 3, 7, 9, C.white, 700);
    addText(row, users[i].name, 46, 14, 13, C.textPrimary, 600);
    // Reste des colonnes
    let cX = 220;
    addBadge(row, users[i].role, cX + 12, 12, C.goldFaint, C.gold);
    cX += hW[1];
    addText(row, users[i].email, cX + 12, 14, 12, C.textSecondary, 400);
    cX += hW[2];
    addText(row, users[i].date, cX + 12, 14, 12, C.textSecondary, 400);
    cX += hW[3];
    addBadge(row, users[i].status, cX + 12, 12, users[i].sb, users[i].sc);
    cX += hW[4];
    addText(row, '✏ Éditer', cX + 12, 14, 12, C.gold, 400);
    addText(row, '⊘ Suspendre', cX + 80, 14, 12, C.red, 400);
  }
}

// ============================================================
// CORRESPONDANCE ID → FONCTION
// ============================================================
const FILL_FUNCTIONS = {
  'home':             fillHome,          // landing page publique
  'login':            fillLogin,
  'register':         fillRegister,
  'dashboard':        fillDashboard,
  'artist-show':      fillArtistShow,
  'artist-edit':      fillArtistEdit,
  'org-show':         fillOrgShow,
  'org-edit':         fillOrgEdit,
  'resources-list':   fillResourcesList,
  'resource-detail':  fillResourceDetail,
  'resource-submit':  fillResourceSubmit,
  'resource-my':      fillResourceMy,
  'community':        fillCommunity,
  'articles-list':    fillArticlesList,
  'article-show':     fillArticleShow,
  'article-new':      fillArticleNew,
  'article-my':       fillArticleMy,
  'admin-dashboard':  fillAdminDashboard,
  'admin-pending':    fillAdminPending,
  'admin-resources':  fillAdminResources,
  'admin-users':      fillAdminUsers,
};

// ============================================================
// LOGIQUE PRINCIPALE DU PLUGIN
// ============================================================
figma.showUI(__html__, { width: 320, height: 300 });

figma.ui.onmessage = async (msg) => {
  if (msg.type !== 'generate') return;

  figma.ui.postMessage({ type: 'progress', message: 'Chargement des polices…' });

  // ⬇ ÉTAPE 1 : pré-charger TOUTES les polices avant de créer quoi que ce soit
  // Cela évite toutes les erreurs async de chargement de police en cours de route
  try {
    await Promise.all([
      figma.loadFontAsync({ family: 'Inter', style: 'Regular' }),
      figma.loadFontAsync({ family: 'Inter', style: 'Medium' }),
      figma.loadFontAsync({ family: 'Inter', style: 'Semi Bold' }),
      figma.loadFontAsync({ family: 'Inter', style: 'Bold' }),
      // Playfair Display : police serif du design de référence (titres)
      figma.loadFontAsync({ family: 'Playfair Display', style: 'Regular' }),
      figma.loadFontAsync({ family: 'Playfair Display', style: 'Bold' }),
    ]);
  } catch (e) {
    figma.ui.postMessage({ type: 'error', message: 'Erreur de chargement de police : ' + e.message });
    return;
  }

  figma.ui.postMessage({ type: 'progress', message: 'Préparation de la page Figma…' });

  // ⬇ ÉTAPE 2 : utiliser la page courante (compatible avec tous les plans Figma)
  // On n'essaie plus de créer une nouvelle page pour éviter l'erreur
  // "The Starter plan only comes with 3 pages."
  // Les frames seront générées directement sur la page que l'utilisateur a ouverte.
  const newPage = figma.currentPage;

  // ⬇ ÉTAPE 3 : filtrer les pages selon le scope sélectionné
  // Index 0 = home (landing), 1 = login, 2 = register, 3-7 = app, 8-11 = resources,
  // 12-16 = community/articles, 17-20 = admin
  const scope = msg.scope || 'all';
  let pagesToGen = PAGES;
  if (scope === 'landing')    pagesToGen = PAGES.slice(0, 1);   // 1 frame : accueil
  else if (scope === 'auth')  pagesToGen = PAGES.slice(1, 3);   // 2 frames : login + register
  else if (scope === 'app')   pagesToGen = PAGES.slice(1, 8);   // 7 frames : auth + app
  else if (scope === 'resources')   pagesToGen = PAGES.slice(8, 12);   // 4 frames
  else if (scope === 'community')  pagesToGen = PAGES.slice(12, 17);  // 5 frames
  else if (scope === 'admin') pagesToGen = PAGES.slice(17);     // 4 frames

  // ⬇ ÉTAPE 4 : configuration de la grille
  // Chaque frame = 1440 × 900px (Desktop HD)
  // Grille = 4 colonnes avec 80px de gap
  const W = 1440;
  const H = 900;
  const GAP = 80;
  const COLS = 4;

  const allFrames = [];

  // ⬇ ÉTAPE 5 : CRÉER et POSITIONNER chaque frame AVANT de la remplir
  // Cela garantit que toutes les frames existent même si le remplissage échoue
  for (let i = 0; i < pagesToGen.length; i++) {
    try {
      const col = i % COLS;
      const row = Math.floor(i / COLS);
      const x = col * (W + GAP);
      const y = row * (H + GAP);

      // Créer le frame à la bonne position dès le départ
      const f = figma.createFrame();
      // Noms des frames en ASCII pur pour éviter tout problème d'encodage
      f.name = (i + 1).toString().padStart(2, '0') + ' - ' + pagesToGen[i].id;
      f.x = x;
      f.y = y;
      f.resize(W, H);
      setFill(f, C.bgPrimary);
      newPage.appendChild(f);

      allFrames.push({ frame: f, page: pagesToGen[i] });
    } catch (e) {
      console.error('Erreur creation frame', i, e);
    }
  }

  if (allFrames.length === 0) {
    figma.ui.postMessage({ type: 'error', message: 'Aucun frame n\'a pu être créé. Vérifiez les logs.' });
    return;
  }

  // ⬇ ÉTAPE 6 : remplir le contenu de chaque frame avec gestion d'erreur
  let success = 0;
  let errors = 0;

  for (const { frame: f, page } of allFrames) {
    figma.ui.postMessage({ type: 'progress', message: `Génération : ${page.name}…` });
    try {
      const fillFn = FILL_FUNCTIONS[page.id];
      if (fillFn) {
        fillFn(f);
        success++;
      } else {
        // Page sans générateur spécifique — placeholder
        addText(f, page.name.replace(/^\d+ — /, ''), 80, 80, 28, C.textPrimary, 700);
        addText(f, 'Contenu à développer', 80, 120, 14, C.textMuted, 400);
        success++;
      }
    } catch (e) {
      // En cas d'erreur : afficher le nom de la page + message d'erreur sur le frame
      // IMPORTANT : utiliser uniquement des caractères ASCII dans le catch pour éviter
      // que le message d'erreur lui-même provoque une nouvelle erreur
      try {
        const errTitle = figma.createText();
        errTitle.fontName = { family: 'Inter', style: 'Semi Bold' };
        errTitle.fontSize = 16;
        errTitle.characters = 'ERREUR : ' + page.id;
        setFill(errTitle, C.red);
        errTitle.x = 40;
        errTitle.y = 40;
        f.appendChild(errTitle);

        // Nettoyer le message d'erreur pour ne garder que l'ASCII imprimable
        const rawMsg = String(e.message || e);
        const safeMsg = rawMsg.replace(/[^\x20-\x7E]/g, '?');
        const errMsg = figma.createText();
        errMsg.fontName = { family: 'Inter', style: 'Regular' };
        errMsg.fontSize = 12;
        errMsg.characters = safeMsg.slice(0, 200);
        setFill(errMsg, C.textMuted);
        errMsg.x = 40;
        errMsg.y = 70;
        errMsg.textAutoResize = 'HEIGHT';
        errMsg.resize(W - 80, 20);
        f.appendChild(errMsg);
      } catch (_) {}
      errors++;
      console.error('Erreur page', page.id, String(e));
    }
  }

  // ⬇ ÉTAPE 7 : centrer la vue sur toutes les frames générées
  figma.viewport.scrollAndZoomIntoView(newPage.children);

  figma.ui.postMessage({
    type: 'done',
    // Note : le message utilise des caractères simples pour rester lisible dans l'UI
    message: (success) + ' frames generes' + (errors > 0 ? ' · ' + errors + ' erreur(s) - voir console' : '') + ' !'
  });
};
