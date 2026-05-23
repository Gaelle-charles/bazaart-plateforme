// src/ui.jsx — shared primitives & icons

// ─── Icon set (minimal hand-drawn stroke icons) ──────────────────────────────
const Icon = ({ name, size = 18, stroke = 1.7, ...rest }) => {
  const paths = {
    search: <><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></>,
    bell:   <><path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 18a2 2 0 0 0 4 0"/></>,
    plus:   <><path d="M12 5v14M5 12h14"/></>,
    arrow:  <><path d="M5 12h14M13 5l7 7-7 7"/></>,
    arrowUp:<><path d="M12 19V5M5 12l7-7 7 7"/></>,
    map:    <><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2z"/><path d="M9 4v14M15 6v14"/></>,
    list:   <><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></>,
    grid:   <><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></>,
    heart:  <><path d="M12 21s-7-4.5-9.5-9C.7 8 3 4 6.5 4 9 4 10.5 5.5 12 7.5 13.5 5.5 15 4 17.5 4 21 4 23.3 8 21.5 12 19 16.5 12 21 12 21z"/></>,
    bookmark:<><path d="M6 4h12v17l-6-4-6 4z"/></>,
    clock:  <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>,
    pin:    <><path d="M12 22s-7-7-7-12a7 7 0 1 1 14 0c0 5-7 12-7 12z"/><circle cx="12" cy="10" r="2.5"/></>,
    euro:   <><path d="M19 5a8 8 0 1 0 0 14"/><path d="M4 10h10M4 14h10"/></>,
    live:   <><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/></>,
    play:   <><path d="M8 5v14l11-7z"/></>,
    msg:    <><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.5 8.5 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9 8.5 8.5 0 0 1 8.5 8.5z"/></>,
    user:   <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></>,
    eye:    <><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></>,
    eyeOff: <><path d="M3 3l18 18M10 5.5a10 10 0 0 1 12 6.5 11 11 0 0 1-3.4 4.2M6.2 6.2A11 11 0 0 0 2 12s4 7 10 7c2 0 3.9-.7 5.5-1.7"/></>,
    filter: <><path d="M3 5h18M6 12h12M10 19h4"/></>,
    check:  <><path d="m5 12 5 5L20 7"/></>,
    x:      <><path d="M6 6l12 12M18 6L6 18"/></>,
    dot3:   <><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></>,
    upload: <><path d="M12 16V4M5 11l7-7 7 7M4 20h16"/></>,
    edit:   <><path d="M4 20h4l11-11-4-4L4 16v4z"/><path d="M14 6l4 4"/></>,
    trash:  <><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></>,
    money:  <><rect x="3" y="6" width="18" height="12"/><circle cx="12" cy="12" r="3"/><path d="M6 9h.01M18 15h.01"/></>,
    chart:  <><path d="M3 21h18M6 17V9M11 17V5M16 17v-7M21 17v-3"/></>,
    shield: <><path d="M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6l8-3z"/></>,
    sparkle:<><path d="M12 3v6M12 15v6M3 12h6M15 12h6M6 6l3.5 3.5M14.5 14.5 18 18M6 18l3.5-3.5M14.5 9.5 18 6"/></>,
    cal:    <><rect x="3" y="5" width="18" height="16"/><path d="M3 9h18M8 3v4M16 3v4"/></>,
    home:   <><path d="M3 11 12 4l9 7v9h-6v-6h-6v6H3z"/></>,
    book:   <><path d="M4 4h7a3 3 0 0 1 3 3v13a3 3 0 0 0-3-3H4z"/><path d="M20 4h-7a3 3 0 0 0-3 3v13a3 3 0 0 1 3-3h7z"/></>,
    flame:  <><path d="M12 22c4 0 7-2.5 7-6.5 0-2-1-3.5-2-4.5-1 3-3 3-3 0 0-3.5 2-5.5-2-8 .5 4-4 4-4 9 0 5 0 10 4 10z"/></>,
    mic:    <><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></>,
    pal:    <><path d="M12 3a9 9 0 1 0 0 18c1 0 2-1 2-2 0-1-1-1.5-1-2.5s1-1.5 2-1.5h2a4 4 0 0 0 4-4c0-4-4-8-9-8z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="12" cy="7" r="1"/><circle cx="16.5" cy="10.5" r="1"/></>,
    music:  <><path d="M9 18V5l10-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="16" cy="16" r="3"/></>,
    cam:    <><path d="M3 7h4l2-3h6l2 3h4v12H3z"/><circle cx="12" cy="13" r="4"/></>,
    pen:    <><path d="M14 4l6 6L8 22H2v-6z"/></>,
    settings:<><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3h0a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5h0a1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8v0a1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></>,
  };
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none"
         stroke="currentColor" strokeWidth={stroke} strokeLinecap="round" strokeLinejoin="round"
         {...rest}>{paths[name]}</svg>
  );
};

// ─── Logo ────────────────────────────────────────────────────────────────────
const Logo = ({ height = 22 }) => (
  <img src="assets/logo-green.png" alt="BAZAART" style={{ height, width: 'auto', display: 'block' }} />
);
const LogoText = ({ size = 80, color = 'var(--ink)' }) => (
  <span className="h-display" style={{ fontSize: size, color, lineHeight: .85, letterSpacing: '-.02em', display:'inline-block' }}>BAZAART</span>
);

// ─── Eyebrow ────────────────────────────────────────────────────────────────
const Eyebrow = ({ children, dot = true }) => (
  <span className="eyebrow">{dot && <span style={{width:8,height:8,background:'var(--accent)',display:'inline-block'}}/>}<span>{children}</span></span>
);

// ─── Avatar (placeholder) ───────────────────────────────────────────────────
const Avatar = ({ initials, size = 40, bg = 'var(--ink)', fg = 'var(--accent)' }) => (
  <div style={{
    width:size,height:size,borderRadius:'50%',background:bg,color:fg,
    display:'flex',alignItems:'center',justifyContent:'center',
    fontFamily:'var(--mono)',fontWeight:700,fontSize:size*.32,letterSpacing:'.04em',
    border:'1px solid var(--ink)',flexShrink:0,
  }}>{initials}</div>
);

// ─── Placeholder image ──────────────────────────────────────────────────────
const Placeholder = ({ label = 'photo', ratio = '4/3', style = {} }) => (
  <div className="placeholder-img" style={{ aspectRatio: ratio, ...style }}>
    <div style={{ padding: 12 }}>{label}</div>
  </div>
);

// ─── Stat ───────────────────────────────────────────────────────────────────
const Stat = ({ value, label, color = 'var(--ink)' }) => (
  <div>
    <div className="h-display" style={{ fontSize: 64, color, lineHeight: .9 }}>{value}</div>
    <div className="mono" style={{ fontSize: 11, letterSpacing: '.1em', textTransform: 'uppercase', marginTop: 6, color: 'var(--ink-mute)' }}>{label}</div>
  </div>
);

// ─── Marquee ────────────────────────────────────────────────────────────────
const Marquee = ({ children, speed = 35, direction = 'left' }) => {
  return (
    <div style={{ overflow: 'hidden', display: 'flex' }}>
      <div style={{
        display: 'inline-flex', whiteSpace: 'nowrap', gap: 0,
        animation: `marquee-${direction} ${speed}s linear infinite`,
      }}>
        {children}{children}
      </div>
    </div>
  );
};

// Inject marquee keyframes
(function injectKeyframes(){
  if (document.getElementById('__kf')) return;
  const s = document.createElement('style');
  s.id = '__kf';
  s.textContent = `
    @keyframes marquee-left { from { transform: translateX(0) } to { transform: translateX(-50%) } }
    @keyframes marquee-right { from { transform: translateX(-50%) } to { transform: translateX(0) } }
    @keyframes pulse-live { 0%,100% { opacity:1 } 50% { opacity:.35 } }
    @keyframes rotate-slow { from { transform: rotate(0) } to { transform: rotate(360deg) } }
    @keyframes fadeUp { from { opacity:0; transform: translateY(10px) } to { opacity:1; transform: none } }
    .fade-up { animation: fadeUp .4s ease forwards; }
  `;
  document.head.appendChild(s);
})();

// ─── Live pulse dot ─────────────────────────────────────────────────────────
const LiveDot = ({ size = 8 }) => (
  <span style={{ display:'inline-block', width:size, height:size, borderRadius:'50%', background:'var(--accent-2)', animation:'pulse-live 1.4s ease-in-out infinite' }}/>
);

// ─── Countdown chip ─────────────────────────────────────────────────────────
const Countdown = ({ days, hours, mins }) => (
  <div style={{ display:'inline-flex', alignItems:'center', gap:0, border:'1px solid var(--ink)', background:'var(--bg)' }}>
    {[['J', days], ['H', hours], ['M', mins]].map(([k,v], i, a)=>(
      <div key={k} style={{ padding:'5px 9px', borderRight: i<a.length-1?'1px solid var(--ink)':'0', fontFamily:'var(--mono)', fontWeight:700, fontSize:11, letterSpacing:'.04em' }}>
        <span style={{ color:'var(--ink-mute)' }}>{k}</span>{' '}<span>{String(v).padStart(2,'0')}</span>
      </div>
    ))}
  </div>
);

// ─── Section header ─────────────────────────────────────────────────────────
const SectionHeader = ({ index, title, subtitle, action }) => (
  <div style={{ display:'flex', alignItems:'flex-end', justifyContent:'space-between', gap:24, padding:'40px 0 20px', borderBottom:'1px solid var(--ink)' }}>
    <div style={{ display:'flex', alignItems:'flex-end', gap:20 }}>
      {index && <div className="mono" style={{ fontSize:12, letterSpacing:'.1em', color:'var(--ink-mute)' }}>§{index}</div>}
      <h2 className="h-display" style={{ fontSize:56, margin:0 }}>{title}</h2>
      {subtitle && <div className="serif" style={{ fontSize:18, fontStyle:'italic', color:'var(--ink-mute)', paddingBottom:8 }}>{subtitle}</div>}
    </div>
    {action}
  </div>
);

// ─── Expose globals ─────────────────────────────────────────────────────────
Object.assign(window, {
  Icon, Logo, LogoText, Eyebrow, Avatar, Placeholder,
  Stat, Marquee, LiveDot, Countdown, SectionHeader,
});
