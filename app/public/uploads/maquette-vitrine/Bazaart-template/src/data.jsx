// src/data.jsx — mock dataset

const DISCIPLINES = ['Arts visuels','Musique','Théâtre','Danse','Cinéma','Littérature','Photographie','Numérique','Performance','Cirque','Mode'];

const OPPORTUNITIES = [
  {
    id:'o1', type:'Résidence', title:"Résidence Manufacture — atelier 3 mois",
    org:'Manufacture des Œillets', city:'Ivry-sur-Seine', region:'Île-de-France',
    discipline:['Arts visuels','Numérique'], amount:'4 500 €', deadlineISO:'2026-06-12',
    days:21, hours:6, mins:14, level:'Émergent / Confirmé',
    summary:"Atelier individuel de 90m², bourse de production et restitution publique en septembre.",
    tags:['Production','Bourse','Restitution'], hot:true,
  },
  {
    id:'o2', type:'Aide financière', title:'Aide à la création — DRAC Hauts-de-France',
    org:'DRAC Hauts-de-France', city:'Lille', region:'Hauts-de-France',
    discipline:['Théâtre','Danse'], amount:'Jusqu’à 25 000 €', deadlineISO:'2026-07-03',
    days:42, hours:9, mins:2, level:'Confirmé',
    summary:"Soutien à la création pour compagnies professionnelles. Dossier en ligne.",
    tags:['Subvention','Public','National'], hot:false,
  },
  {
    id:'o3', type:'Appel à projet', title:'Festival LUMINEUSES — programmation 2027',
    org:'Festival Lumineuses', city:'Marseille', region:'PACA',
    discipline:['Performance','Numérique','Arts visuels'], amount:'2 000 — 8 000 €', deadlineISO:'2026-05-30',
    days:8, hours:3, mins:41, level:'Tous niveaux',
    summary:"Création in-situ ou projection vidéo pour la 7e édition. Thème : « Survivances ».",
    tags:['Festival','In-situ'], hot:true,
  },
  {
    id:'o4', type:'Mentorat', title:"Mentorat 1-to-1 — production scénique",
    org:'BazaArt × Quai Branly', city:'Paris', region:'Île-de-France',
    discipline:['Théâtre','Danse','Performance'], amount:'Gratuit', deadlineISO:'2026-06-25',
    days:34, hours:11, mins:55, level:'Émergent',
    summary:"8 séances avec un producteur senior. 12 places. Sélection sur dossier court.",
    tags:['Gratuit','Émergents','BazaArt'], hot:false,
  },
  {
    id:'o5', type:'Appel à résidence', title:'Résidence Pyrénées — atelier nature',
    org:'La Forêt de Margeride', city:'Saint-Chély-d’Apcher', region:'Occitanie',
    discipline:['Arts visuels','Littérature','Photographie'], amount:'1 800 € + logement', deadlineISO:'2026-08-15',
    days:85, hours:0, mins:0, level:'Tous niveaux',
    summary:"Résidence de 6 semaines en immersion. Pratique solitaire ou en duo.",
    tags:['Logement','Nature','Long format'], hot:false,
  },
  {
    id:'o6', type:'Aide financière', title:"Bourse SACEM — création musicale",
    org:'SACEM', city:'France', region:'National',
    discipline:['Musique'], amount:'3 000 — 15 000 €', deadlineISO:'2026-06-30',
    days:39, hours:14, mins:22, level:'Confirmé',
    summary:"Soutien à la composition originale. Plusieurs sessions dans l’année.",
    tags:['Bourse','Musique'], hot:false,
  },
  {
    id:'o7', type:'Appel à projet', title:'Commande publique — fresque urbaine 200m²',
    org:'Ville de Nantes', city:'Nantes', region:'Pays de la Loire',
    discipline:['Arts visuels'], amount:'18 000 €', deadlineISO:'2026-09-10',
    days:111, hours:2, mins:8, level:'Confirmé',
    summary:"Commande pour un mur du quartier Bottière-Chénaie. Concertation habitants.",
    tags:['Commande','Espace public','XL'], hot:false,
  },
  {
    id:'o8', type:'Formation', title:'Formation — fiscalité de l’artiste-auteur',
    org:'BazaArt — Hub Formation', city:'Visio + Paris', region:'National',
    discipline:['Tous'], amount:'Gratuit adhérents', deadlineISO:'2026-06-05',
    days:14, hours:0, mins:0, level:'Tous niveaux',
    summary:"3 modules de 2h. AGESSA, MDA, BNC, TVA. Animé par un expert-comptable.",
    tags:['Formation','BazaArt','Adhérents'], hot:false,
  },
];

const ARTISTS = [
  { id:'a1', name:'Inès Khoury', practice:'Vidéo & installation', city:'Marseille', initials:'IK', followers:412, color:'#C6F24E' },
  { id:'a2', name:'Yacine Demba', practice:'Théâtre documentaire', city:'Saint-Denis', initials:'YD', followers:1840, color:'#FF6B2C' },
  { id:'a3', name:'Mira Vasseur', practice:'Photographie argentique', city:'Lyon', initials:'MV', followers:266, color:'#B794F6' },
  { id:'a4', name:'Sékou Traoré', practice:'Musique électronique', city:'Bordeaux', initials:'ST', followers:902, color:'#FFD23F' },
  { id:'a5', name:'Joana Pires', practice:'Performance & textile', city:'Nantes', initials:'JP', followers:188, color:'#7DD3FC' },
];

const POSTS = [
  { id:'p1', kind:'question', author:'YD', title:"AGESSA vs MDA en 2026 : on cotise où finalement ?", body:"J'ai eu trois réponses différentes en deux semaines. Quelqu'un a un retour clair ?", tag:'Statut & droits', replies:38, votes:212, time:'il y a 2 h', hot:true },
  { id:'p2', kind:'live', author:'IK', title:'Live : monter un dossier DRAC — j\'ouvre le mien en direct', body:'Je relis et corrige mon dossier en direct. Posez vos questions.', tag:'Production', replies:0, votes:54, time:'commence dans 1 h 20', live:'soon', viewers:0 },
  { id:'p3', kind:'thread', author:'MV', title:"Argentique : labo collectif à Lyon, qui dans le coup ?", body:"On cherche 4 personnes pour mutualiser un labo N&B à la Croix-Rousse.", tag:'Local · Lyon', replies:14, votes:88, time:'il y a 5 h', hot:false },
  { id:'p4', kind:'live', author:'ST', title:'Live : set modulaire — composition lente', body:'Studio session ouvert. Ambient/drone. Venez écouter.', tag:'Musique', replies:0, votes:128, time:'EN DIRECT', live:'on', viewers:312 },
  { id:'p5', kind:'question', author:'JP', title:'Mutuelle santé pour intermittents — vos retours ?', body:'Audiens vs Smacl vs autre — votre expérience après 2 ans ?', tag:'Statut & droits', replies:22, votes:74, time:'hier', hot:false },
  { id:'p6', kind:'thread', author:'YD', title:"Retours sur la résidence à la Manufacture (Ivry)", body:"J'y étais en 2024. AMA — atelier, équipe, budget réel.", tag:'Résidences', replies:47, votes:301, time:'il y a 1 j', hot:true },
];

const ARTICLES = [
  { id:'b1', title:"Pourquoi on a créé BazaArt", excerpt:"Un manifeste, et trois années de bricolage avec 800 artistes.", tag:'Manifeste', read:'7 min', date:'12 mai 2026', cover:'Cover article' },
  { id:'b2', title:"Cartographie des aides régionales 2026", excerpt:"Tour de France des dispositifs : ce qui change, ce qui disparaît.", tag:'Ressource', read:'14 min', date:'04 mai 2026', cover:'Carte de France' },
  { id:'b3', title:"Faire vivre un atelier collectif sans s'épuiser", excerpt:"Outils, gouvernance, économie — un retour d'expérience.", tag:'Pratique', read:'9 min', date:'22 avril 2026', cover:'Atelier' },
  { id:'b4', title:"L'IA dans l'atelier : 6 artistes témoignent", excerpt:"Outil, sujet, ou ennemi ? Aucune conclusion, beaucoup de nuances.", tag:'Enquête', read:'18 min', date:'10 avril 2026', cover:'IA & art' },
];

const STRUCTURES = [
  { id:'s1', name:'Manufacture des Œillets', city:'Ivry-sur-Seine', kind:'Lieu de fabrique', published:8 },
  { id:'s2', name:'DRAC Hauts-de-France', city:'Lille', kind:'Institution publique', published:23 },
  { id:'s3', name:'Festival Lumineuses', city:'Marseille', kind:'Festival', published:5 },
  { id:'s4', name:'Ville de Nantes — DAC', city:'Nantes', kind:'Collectivité', published:12 },
];

Object.assign(window, {
  DISCIPLINES, OPPORTUNITIES, ARTISTS, POSTS, ARTICLES, STRUCTURES,
});
