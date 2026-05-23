/* ═══════════════════════════════════════════════════════════
   vitrine.js — BazaArt page vitrine
   - Scroll reveal (IntersectionObserver)
   - Navbar ombre au scroll
   - Menu hamburger mobile
═══════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {

  /* ── 1. Scroll reveal ────────────────────────────────── */
  // On observe chaque élément avec la classe "reveal"
  // Quand il entre dans le viewport, on ajoute "visible" → transition CSS
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('visible');
        observer.unobserve(e.target); // On arrête d'observer une fois visible
      }
    });
  }, { threshold: 0.12 }); // Déclenché quand 12% de l'élément est visible

  // On applique la classe "reveal" à tous les blocs animables
  document.querySelectorAll(
    '.section, .stats-bar, .hub-card, .projet-card, .membre-card, .disc-card, .partner-tag'
  ).forEach(el => {
    el.classList.add('reveal');
    observer.observe(el);
  });

  /* ── 2. Navbar ombre au scroll ───────────────────────── */
  // Quand on descend de plus de 30px, on ajoute la classe "scrolled"
  // La classe "scrolled" est définie dans vitrine.css (box-shadow)
  window.addEventListener('scroll', () => {
    document.querySelector('.nav')
      ?.classList.toggle('scrolled', window.scrollY > 30);
  });

  /* ── 3. Menu hamburger mobile ────────────────────────── */
  // Sur mobile, le menu est caché. Le bouton hamburger l'ouvre/ferme
  const hamburger = document.querySelector('.nav-hamburger');
  const navLinks  = document.querySelector('.nav-links');

  if (hamburger && navLinks) {
    hamburger.addEventListener('click', () => {
      // Bascule la classe "open" sur le menu
      navLinks.classList.toggle('open');

      // Animation du bouton hamburger → croix
      hamburger.classList.toggle('active');
    });

    // Ferme le menu quand on clique sur un lien
    navLinks.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        navLinks.classList.remove('open');
        hamburger.classList.remove('active');
      });
    });

    // Bouton "Faire un don" dans le menu mobile → ouvre le même overlay que le desktop
    const donMobile = document.getElementById('openHaOverlayMobile');
    const haModal   = document.getElementById('haWidgetModal');
    if (donMobile && haModal) {
      donMobile.addEventListener('click', () => {
        navLinks.classList.remove('open');
        hamburger.classList.remove('active');
        haModal.style.display = 'flex';
      });
    }
  }

  /* ── 4. Formulaire de contact (AJAX) ─────────────────── */
  // On intercepte la soumission du formulaire pour l'envoyer
  // en fetch (sans rechargement de page) et afficher le résultat
  const form      = document.getElementById('contact-form');
  const feedback  = document.getElementById('contact-feedback');
  const submitBtn = document.getElementById('contact-submit');

  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); // Empêche le rechargement de la page

      // Désactive le bouton et affiche un état de chargement
      submitBtn.disabled    = true;
      submitBtn.textContent = 'Envoi en cours...';
      feedback.textContent  = '';
      feedback.className    = 'contact-feedback';

      try {
        // Envoie les données du formulaire via fetch (POST)
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
        });

        const data = await response.json();

        if (data.success) {
          // Succès : affiche un message vert et vide le formulaire
          feedback.textContent = '✓ Message envoyé ! Nous vous répondrons très bientôt.';
          feedback.className   = 'contact-feedback success';
          form.reset();
        } else {
          // Erreur métier (validation...)
          feedback.textContent = data.error || 'Une erreur est survenue.';
          feedback.className   = 'contact-feedback error';
        }

      } catch (err) {
        // Erreur réseau
        feedback.textContent = 'Erreur de connexion. Veuillez réessayer.';
        feedback.className   = 'contact-feedback error';
      }

      // Réactive le bouton dans tous les cas
      submitBtn.disabled    = false;
      submitBtn.textContent = 'Envoyer un message →';
    });
  }

});

/* ── 5. Carrousel miniatures DIMÉ ───────────────────── */
// Change l'image principale quand on clique sur une miniature
function switchDimeImg(thumb, src) {
  var mainImg = document.getElementById('dime-main-img');
  if (!mainImg) return;

  mainImg.src = src;

  // Met à jour la miniature active
  document.querySelectorAll('.event-thumb').forEach(function(t) {
    t.classList.remove('active');
  });
  thumb.classList.add('active');
}

// Change l'image principale quand on clique sur une card RDV
function switchDimeFromRdv(rdv) {
  var src = rdv.getAttribute('data-img');
  if (!src) return;

  var mainImg = document.getElementById('dime-main-img');
  if (!mainImg) return;

  mainImg.src = src;

  // Synchronise la miniature active correspondante
  document.querySelectorAll('.event-thumb').forEach(function(t) {
    t.classList.remove('active');
    if (t.querySelector('img') && t.querySelector('img').src.indexOf(src.split('/').pop()) !== -1) {
      t.classList.add('active');
    }
  });
}
