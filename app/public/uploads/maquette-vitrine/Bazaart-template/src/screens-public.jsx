// src/screens-public.jsx — Landing, Blog, Pricing, Hub detail

// ─── LANDING / HOME ─────────────────────────────────────────────────────────
const ScreenLanding = ({ go, variant = 'manifesto' }) => {
  return (
    <div className="screen" data-screen-label="01 Home">
      {variant === 'manifesto' && <LandingManifesto go={go}/>}
      {variant === 'editorial' && <LandingEditorial go={go}/>}
      {variant === 'cards' && <LandingCards go={go}/>}
    </div>
  );
};

// ─── Hero v1 : MANIFESTO ─────────────────────────────────────────────────────
const LandingManifesto = ({ go }) => (
  <div>
    {/* HERO */}
    <section style={{ borderBottom:'1px solid var(--ink)', position:'relative', overflow:'hidden' }}>
      <div className="container" style={{ paddingTop:48, paddingBottom:24 }}>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:24 }}>
          <Eyebrow>Association · SaaS · Fr · 2026</Eyebrow>
          <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'var(--ink-mute)' }}>
            42° 21′ N — Quelque part en France
          </div>
        </div>

        <h1 className="h-display" style={{ fontSize:'clamp(96px, 14vw, 220px)', margin:0, letterSpacing:'-.03em' }}>
          LE BAZAR<br/>
          DE L’<span style={{ color:'var(--accent-2)' }}>ART</span> — <span style={{ background:'var(--accent)', padding:'0 .12em' }}>SANS</span><br/>
          LES INTERMÉDIAIRES.
        </h1>

        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:32, marginTop:48, alignItems:'end' }}>
          <p className="serif" style={{ fontSize:24, lineHeight:1.25, margin:0, gridColumn:'span 2', maxWidth:760 }}>
            BazaArt rassemble en un seul endroit ce qui était dispersé sur 200 sites, 30 newsletters et trois groupes Facebook fatigués&nbsp;: les opportunités, les pairs, les ressources, les lives.
            Une <em>association</em> à trois hubs, et une <em>plateforme</em> faite par et pour les artistes.
          </p>
          <div style={{ display:'flex', flexDirection:'column', gap:10, alignItems:'flex-start' }}>
            <button className="btn btn-primary" onClick={()=>go('opportunities')}>
              Explorer les opportunités <Icon name="arrow" size={14}/>
            </button>
            <button className="btn btn-ghost" onClick={()=>go('pricing')}>
              Voir les tarifs
            </button>
          </div>
        </div>
      </div>

      {/* Marquee */}
      <div style={{ borderTop:'1px solid var(--ink)', borderBottom:'1px solid var(--ink)', background:'var(--ink)', color:'var(--accent)', padding:'14px 0', marginTop:48 }}>
        <Marquee speed={45}>
          {['+ 240 opportunités · indexées chaque semaine', '· · ·', 'forum + lives — communauté de 1 800 artistes', '· · ·', 'mentorat 1-to-1 avec des producteurs séniors', '· · ·', 'la coopérative qui te répond en 24h', '· · ·'].map((t,i)=>(
            <span key={i} className="h-display" style={{ fontSize:36, padding:'0 32px', whiteSpace:'nowrap' }}>{t}</span>
          ))}
        </Marquee>
      </div>
    </section>

    {/* HUBS */}
    <section className="container" style={{ paddingBottom:48 }}>
      <SectionHeader index="01" title="Les trois hubs de l’asso" subtitle="événement · ingénierie · transmission"/>
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', borderLeft:'1px solid var(--ink)', borderRight:'1px solid var(--ink)', borderBottom:'1px solid var(--ink)' }}>
        {[
          { no:'01', name:'EVENT', tag:'Programmation', desc:"On produit. Soirées de restitution, vernissages alternatifs, plateaux mixtes — on met les artistes BazaArt sur scène, en vrai, devant des gens.", stats:['18 événements / an','3 villes','9 000 spectateurs cumulés'], color:'var(--accent)' },
          { no:'02', name:"INGÉNIERIE\nCULTURELLE", tag:'Accompagnement', desc:"On structure. Conseil aux artistes et aux structures sur le montage de projets, les financements croisés, et la conduite d’une production de A à Z.", stats:['120 projets accompagnés','78% taux de réussite','Réseau de 40 financeurs'], color:'var(--bg-alt)' },
          { no:'03', name:'FORMATION', tag:'Transmission', desc:"On forme. Cycles courts (fiscalité, droit, communication, IA) animés par des pairs et des experts. Gratuit pour les adhérents, abordable pour les autres.", stats:['12 cycles / an','280 artistes formés','Note moyenne 4.7 / 5'], color:'var(--accent-2)' },
        ].map((h, i) => (
          <div key={h.name} style={{
            padding:'32px 28px', borderRight: i<2 ? '1px solid var(--ink)' : '0',
            display:'flex', flexDirection:'column', gap:20, minHeight:520,
            background: i===1 ? 'var(--bg)' : 'var(--bg)',
          }}>
            <div style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start' }}>
              <span className="mono" style={{ fontSize:12, fontWeight:700, letterSpacing:'.1em' }}>HUB {h.no}</span>
              <span className="chip" style={{ background: i===0 ? 'var(--accent)' : i===2 ? 'var(--accent-2)' : 'var(--bg-alt)' }}>{h.tag}</span>
            </div>
            <h3 className="h-display" style={{ fontSize:64, margin:0, whiteSpace:'pre-line', lineHeight:.88 }}>{h.name}</h3>
            <p style={{ fontSize:15, lineHeight:1.5, color:'var(--ink-mute)', margin:0 }}>{h.desc}</p>
            <div style={{ marginTop:'auto', borderTop:'1px solid var(--line-soft)', paddingTop:16, display:'flex', flexDirection:'column', gap:8 }}>
              {h.stats.map(s => (
                <div key={s} style={{ display:'flex', justifyContent:'space-between', fontFamily:'var(--mono)', fontSize:11, letterSpacing:'.04em' }}>
                  <span style={{ color:'var(--ink-mute)' }}>›</span>
                  <span>{s}</span>
                </div>
              ))}
            </div>
            <button className="btn btn-sm" style={{ alignSelf:'flex-start' }}>En savoir + <Icon name="arrow" size={12}/></button>
          </div>
        ))}
      </div>
    </section>

    {/* SAAS PITCH */}
    <section style={{ background:'var(--ink)', color:'var(--bg)', borderTop:'1px solid var(--ink)' }}>
      <div className="container" style={{ paddingTop:64, paddingBottom:48 }}>
        <div style={{ display:'grid', gridTemplateColumns:'1.3fr .9fr', gap:48, alignItems:'flex-start' }}>
          <div>
            <Eyebrow>La plateforme</Eyebrow>
            <h2 className="h-display" style={{ fontSize:108, lineHeight:.85, margin:'12px 0 24px' }}>
              Tout ce dont<br/>tu as besoin,<br/><span style={{ color:'var(--accent)' }}>sans le bruit.</span>
            </h2>
            <p style={{ fontSize:18, lineHeight:1.5, maxWidth:560, color:'rgba(242,239,230,.75)' }}>
              Un seul abonnement. Pas de pub. Pas de revente de tes données. Tu te connectes, tu vois ce qui te concerne, tu en parles avec des pairs, tu candidates.
            </p>
          </div>
          <div style={{ display:'flex', flexDirection:'column', gap:0, border:'1px solid var(--bg)' }}>
            {[
              { ic:'sparkle', t:'Opportunités', d:'240+ aides, résidences et appels, indexés et filtrés.', go:'opportunities' },
              { ic:'msg', t:'Forum + Lives', d:'Pose une question, lance un live, monte un collectif.', go:'forum' },
              { ic:'user', t:'Dashboard artiste', d:'Profil public, candidatures suivies, agenda.', go:'dashboard-artist' },
              { ic:'book', t:'Blog & ressources', d:'Articles longs, cartographies, modèles de dossier.', go:'blog' },
            ].map((f, i, a) => (
              <button key={f.t} onClick={()=>go(f.go)} style={{
                display:'flex', gap:18, alignItems:'center',
                padding:'20px 22px',
                borderBottom: i<a.length-1 ? '1px solid var(--line-soft)' : '0',
                background:'transparent', color:'var(--bg)', textAlign:'left', cursor:'default',
                fontFamily:'inherit', fontSize:15,
              }}
              onMouseEnter={e=>e.currentTarget.style.background='rgba(198,242,78,.08)'}
              onMouseLeave={e=>e.currentTarget.style.background='transparent'}
              >
                <div style={{ width:48, height:48, background:'var(--accent)', color:'var(--ink)', display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0 }}>
                  <Icon name={f.ic} size={22}/>
                </div>
                <div style={{ flex:1 }}>
                  <div className="h-display" style={{ fontSize:22, marginBottom:4 }}>{f.t}</div>
                  <div style={{ fontSize:13, color:'rgba(242,239,230,.65)' }}>{f.d}</div>
                </div>
                <Icon name="arrow" size={18}/>
              </button>
            ))}
          </div>
        </div>
      </div>
    </section>

    {/* OPPORTUNITIES PREVIEW */}
    <section className="container">
      <SectionHeader
        index="02"
        title="Ça se passe maintenant"
        subtitle="extrait du flux"
        action={<button className="btn btn-sm" onClick={()=>go('opportunities')}>Tout voir <Icon name="arrow" size={12}/></button>}
      />
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:0, borderLeft:'1px solid var(--ink)', borderRight:'1px solid var(--ink)', borderBottom:'1px solid var(--ink)' }}>
        {OPPORTUNITIES.slice(0,3).map((o, i) => (
          <button key={o.id} onClick={()=>go('opportunity', o.id)} style={{
            textAlign:'left', cursor:'default', border:'0', borderRight: i<2 ? '1px solid var(--ink)' : '0',
            background:'transparent', padding:'28px 24px', display:'flex', flexDirection:'column', gap:16, minHeight:340, fontFamily:'inherit', color:'inherit',
          }}>
            <div style={{ display:'flex', justifyContent:'space-between' }}>
              <span className="chip chip-accent">{o.type}</span>
              <Countdown days={o.days} hours={o.hours} mins={o.mins}/>
            </div>
            <h3 className="h-display" style={{ fontSize:28, margin:0, letterSpacing:'-.01em' }}>{o.title}</h3>
            <div style={{ display:'flex', gap:14, fontSize:12, color:'var(--ink-mute)', alignItems:'center' }}>
              <span style={{ display:'inline-flex', gap:6, alignItems:'center' }}><Icon name="pin" size={13}/> {o.city}</span>
              <span style={{ display:'inline-flex', gap:6, alignItems:'center' }}><Icon name="euro" size={13}/> {o.amount}</span>
            </div>
            <p style={{ fontSize:13.5, lineHeight:1.5, color:'var(--ink-mute)', margin:0 }}>{o.summary}</p>
            <div style={{ marginTop:'auto', display:'flex', justifyContent:'space-between', alignItems:'center' }}>
              <div style={{ display:'flex', gap:6 }}>{o.tags.slice(0,2).map(t => <span key={t} className="chip" style={{ fontSize:10, padding:'4px 7px' }}>{t}</span>)}</div>
              <Icon name="arrow" size={16}/>
            </div>
          </button>
        ))}
      </div>
    </section>

    {/* COMMUNITY */}
    <section className="container" style={{ paddingBottom:48 }}>
      <SectionHeader index="03" title="La communauté en direct" subtitle="forum & lives"
        action={<button className="btn btn-sm" onClick={()=>go('forum')}>Aller au forum <Icon name="arrow" size={12}/></button>}
      />
      <div style={{ display:'grid', gridTemplateColumns:'1.4fr 1fr', gap:0, border:'1px solid var(--ink)', borderTop:0 }}>
        {/* live preview */}
        <div style={{ background:'var(--ink)', color:'var(--bg)', padding:'24px', borderRight:'1px solid var(--ink)', display:'flex', flexDirection:'column', gap:16 }}>
          <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center' }}>
            <div style={{ display:'flex', alignItems:'center', gap:10 }}>
              <LiveDot/>
              <span className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'var(--accent-2)' }}>EN DIRECT · 312 SPECTATEURS</span>
            </div>
            <button className="btn btn-sm" style={{ background:'var(--accent)', color:'var(--ink)', borderColor:'var(--accent)' }} onClick={()=>go('live', 'p4')}>
              Rejoindre <Icon name="play" size={11}/>
            </button>
          </div>
          <div style={{ aspectRatio:'16/8', background:'#1A1A1A', position:'relative', overflow:'hidden', border:'1px solid var(--line-soft)' }}>
            {/* abstract waveform-ish placeholder */}
            <svg viewBox="0 0 600 200" preserveAspectRatio="none" style={{ width:'100%', height:'100%' }}>
              {Array.from({length:60}).map((_,i)=>{
                const h = 30 + Math.abs(Math.sin(i*.6)*80) + Math.abs(Math.cos(i*.2)*30);
                return <rect key={i} x={i*10+2} y={100-h/2} width="6" height={h} fill="var(--accent)" opacity={.4 + (i%5)*.12}/>;
              })}
            </svg>
            <div style={{ position:'absolute', left:16, bottom:16, display:'flex', alignItems:'center', gap:10 }}>
              <Avatar initials="ST" size={36} bg="var(--accent-2)" fg="var(--ink)"/>
              <div>
                <div style={{ fontWeight:700, fontSize:14 }}>Sékou Traoré</div>
                <div className="mono" style={{ fontSize:10, opacity:.6, letterSpacing:'.08em' }}>SET MODULAIRE — AMBIENT / DRONE</div>
              </div>
            </div>
          </div>
        </div>
        {/* hot threads */}
        <div style={{ display:'flex', flexDirection:'column' }}>
          {POSTS.filter(p=>p.kind!=='live').slice(0,4).map((p, i, a) => (
            <button key={p.id} onClick={()=>go('thread', p.id)} style={{
              background:'transparent', textAlign:'left', cursor:'default',
              border:0, borderBottom: i<a.length-1 ? '1px solid var(--line-soft)' : '0',
              padding:'14px 18px', display:'flex', gap:12, alignItems:'flex-start', fontFamily:'inherit', color:'inherit',
            }}>
              <div className="h-display" style={{ fontSize:20, color:'var(--ink-mute)', width:44 }}>{String(p.votes).padStart(3,'0')}</div>
              <div style={{ flex:1, minWidth:0 }}>
                <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:4 }}>
                  <span className="mono" style={{ fontSize:10, letterSpacing:'.08em', color:'var(--ink-mute)' }}>{p.tag.toUpperCase()}</span>
                  {p.hot && <span className="chip chip-warn" style={{ fontSize:9, padding:'2px 5px' }}><Icon name="flame" size={9}/> HOT</span>}
                </div>
                <div style={{ fontWeight:600, fontSize:14, lineHeight:1.3 }}>{p.title}</div>
                <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:6 }}>{p.author} · {p.replies} réponses · {p.time}</div>
              </div>
            </button>
          ))}
        </div>
      </div>
    </section>

    {/* PRICING CTA */}
    <section style={{ background:'var(--accent)', borderTop:'1px solid var(--ink)' }}>
      <div className="container" style={{ paddingTop:64, paddingBottom:64, display:'grid', gridTemplateColumns:'1fr 1fr', gap:48, alignItems:'center' }}>
        <div>
          <Eyebrow dot={false}>3 façons de nous rejoindre</Eyebrow>
          <h2 className="h-display" style={{ fontSize:96, lineHeight:.9, margin:'12px 0 0' }}>
            6,60 € / mois<br/>en formule annuelle.
          </h2>
          <p className="serif" style={{ fontSize:22, fontStyle:'italic', marginTop:16 }}>
            Le prix d’un café et d’un croissant — pour 365 jours de plateforme.
          </p>
        </div>
        <div style={{ display:'flex', flexDirection:'column', gap:14 }}>
          {[
            { p:'9,90 €', s:'/ mois — engagement souple', t:'Mensuel'},
            { p:'79 €', s:'/ an — 33% d’économie', t:'Annuel', hot:true},
            { p:'39 €', s:'/ an — adhérents BazaArt', t:'Adhérent'},
          ].map(o => (
            <div key={o.t} style={{ display:'flex', alignItems:'center', justifyContent:'space-between', background:'var(--bg)', border:'1px solid var(--ink)', padding:'18px 22px' }}>
              <div>
                <div className="mono" style={{ fontSize:11, letterSpacing:'.1em' }}>{o.t.toUpperCase()}</div>
                <div className="h-display" style={{ fontSize:40, lineHeight:1 }}>{o.p} <span style={{ fontFamily:'var(--body)', fontSize:14, fontWeight:500, color:'var(--ink-mute)' }}>{o.s}</span></div>
              </div>
              {o.hot && <span className="chip chip-dark">★ recommandé</span>}
            </div>
          ))}
          <button className="btn btn-dark" onClick={()=>go('pricing')} style={{ alignSelf:'flex-end' }}>
            Comparer en détail <Icon name="arrow" size={14}/>
          </button>
        </div>
      </div>
    </section>

    <Footer go={go}/>
  </div>
);

// ─── Hero v2 : EDITORIAL ────────────────────────────────────────────────────
const LandingEditorial = ({ go }) => (
  <div>
    <section style={{ borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ paddingTop:48, paddingBottom:48 }}>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 2fr', gap:48 }}>
          <aside style={{ borderRight:'1px solid var(--line-soft)', paddingRight:32 }}>
            <Eyebrow>N°01 — Mai 2026</Eyebrow>
            <p className="serif" style={{ fontSize:18, lineHeight:1.4, marginTop:24 }}>
              BazaArt est <em>à la fois</em> une association, une plateforme et — disons-le franchement — un parti pris.
              Celui que l’art ne se fait plus sans ses artistes au centre.
            </p>
            <div className="mono" style={{ fontSize:11, letterSpacing:'.08em', color:'var(--ink-mute)', marginTop:32, lineHeight:1.8 }}>
              DANS CE NUMÉRO<br/>
              ___________________________<br/>
              <span style={{ color:'var(--ink)' }}>p.04 → Les 3 hubs</span><br/>
              <span style={{ color:'var(--ink)' }}>p.12 → Opportunités du moment</span><br/>
              <span style={{ color:'var(--ink)' }}>p.20 → Forum & lives</span><br/>
              <span style={{ color:'var(--ink)' }}>p.34 → Comment ça marche</span><br/>
            </div>
          </aside>
          <div>
            <h1 className="h-display" style={{ fontSize:'clamp(80px,12vw,180px)', lineHeight:.85, margin:0 }}>
              UNE COOPÉ-<br/>RATIVE,<br/>UN OUTIL,<br/>UNE <span style={{ background:'var(--accent)', padding:'0 .1em' }}>SCÈNE</span>.
            </h1>
            <div style={{ display:'flex', gap:14, marginTop:32 }}>
              <button className="btn btn-primary" onClick={()=>go('opportunities')}>Voir les opportunités <Icon name="arrow" size={14}/></button>
              <button className="btn" onClick={()=>go('forum')}>Entrer dans le forum</button>
            </div>
          </div>
        </div>
      </div>
    </section>
    <section className="container" style={{ paddingTop:48 }}>
      <p className="serif" style={{ fontSize:32, lineHeight:1.3, maxWidth:980, fontStyle:'italic' }}>
        « On en avait marre des plateformes qui prennent 20%, qui revendent les profils, et qui n’ont jamais discuté avec un artiste. Alors on l’a faite. »
      </p>
      <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'var(--ink-mute)', marginTop:12 }}>— L’équipe fondatrice, février 2026</div>
    </section>
    <LandingManifestoBody go={go}/>
    <Footer go={go}/>
  </div>
);

// shared body used by editorial variant — minimal duplication
const LandingManifestoBody = ({ go }) => (
  <>
    <section className="container" style={{ paddingBottom:48 }}>
      <SectionHeader index="01" title="Trois hubs" subtitle="ce que l’asso fait, concrètement"/>
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:24, paddingTop:24 }}>
        {[
          { no:'01', name:'EVENT', desc:"On produit des soirées de restitution, vernissages alternatifs, plateaux mixtes." },
          { no:'02', name:'INGÉNIERIE', desc:"Conseil aux artistes et aux structures : montage, financement, production." },
          { no:'03', name:'FORMATION', desc:"Cycles courts (fiscalité, droit, com, IA) animés par pairs et experts." },
        ].map(h => (
          <div key={h.name} style={{ border:'1px solid var(--ink)', padding:28, display:'flex', flexDirection:'column', gap:14 }}>
            <span className="mono" style={{ fontSize:11, letterSpacing:'.08em', color:'var(--ink-mute)' }}>HUB {h.no}</span>
            <div className="h-display" style={{ fontSize:48 }}>{h.name}</div>
            <p style={{ margin:0, color:'var(--ink-mute)' }}>{h.desc}</p>
          </div>
        ))}
      </div>
    </section>
  </>
);

// ─── Hero v3 : CARDS / POSTER COLLAGE ─────────────────────────────────────────
const LandingCards = ({ go }) => (
  <div>
    <section style={{ background:'var(--ink)', color:'var(--bg)', borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ paddingTop:32, paddingBottom:32, position:'relative' }}>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:16 }}>
          <Eyebrow dot={false}>★ NOUVELLE PLATEFORME · MAI 2026</Eyebrow>
          <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', opacity:.6 }}>FR · v1.0</div>
        </div>
        <h1 className="h-display" style={{ fontSize:'clamp(110px, 16vw, 260px)', margin:'0 0 0 -.05em', letterSpacing:'-.04em', lineHeight:.82, color:'var(--accent)' }}>
          BAZAART
        </h1>
        <div className="h-display" style={{ fontSize:'clamp(40px, 5vw, 72px)', margin:0, color:'var(--bg)' }}>
          ON A RANGÉ <span style={{ color:'var(--accent-2)' }}>LE BAZAR</span>.
        </div>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:0, marginTop:48, border:'1px solid var(--bg)' }}>
          {[
            { v:'240+', l:'Opportunités indexées'},
            { v:'1 800', l:'Artistes membres'},
            { v:'18', l:'Évents / an'},
            { v:'78%', l:'Taux de réussite dossier'},
          ].map((s,i,a)=>(
            <div key={s.l} style={{ padding:'24px 18px', borderRight: i<a.length-1 ? '1px solid var(--line-soft)' : '0' }}>
              <div className="h-display" style={{ fontSize:64, color:'var(--accent)', lineHeight:1 }}>{s.v}</div>
              <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', marginTop:6, opacity:.7 }}>{s.l.toUpperCase()}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
    <LandingManifestoBody go={go}/>
    <Footer go={go}/>
  </div>
);

// ─── PRICING ────────────────────────────────────────────────────────────────
const ScreenPricing = ({ go }) => {
  const plans = [
    { name:'Mensuel', price:'9,90', unit:'€/mois', sub:'Pas d’engagement, résiliable en un clic.',
      perks:['Accès à toutes les opportunités','Forum + lives','Dashboard artiste public','Newsletter hebdo'],
      cta:'Commencer', color:'var(--bg)' },
    { name:'Annuel', price:'79', unit:'€/an', sub:'Soit 6,60 € / mois — 33% d’économie.',
      perks:['Tout le mensuel','Accès anticipé aux opportunités (24h)','1 séance de mentorat / an','Badge « membre soutien »'],
      cta:'Le meilleur deal', color:'var(--accent)', recommended:true },
    { name:'Adhérent BazaArt', price:'39', unit:'€/an', sub:'Tarif réservé aux adhérents de l’association.',
      perks:['Tout l’annuel','Tous les cycles de formation gratuits','Vote à l’AG','Priorité programmation EVENT'],
      cta:'Vérifier mon adhésion', color:'var(--ink)', dark:true },
  ];
  return (
    <div className="screen" data-screen-label="08 Tarifs">
      <section style={{ borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ paddingTop:48, paddingBottom:24 }}>
          <Eyebrow>Tarifs · pas de palier caché</Eyebrow>
          <h1 className="h-display" style={{ fontSize:'clamp(80px, 11vw, 160px)', margin:'12px 0 0', lineHeight:.88 }}>
            UN PRIX,<br/>POINT.
          </h1>
        </div>
      </section>
      <section className="container" style={{ paddingTop:48, paddingBottom:64 }}>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:0, border:'1px solid var(--ink)' }}>
          {plans.map((p, i, a) => (
            <div key={p.name} style={{
              padding:'32px 28px', borderRight: i<a.length-1 ? '1px solid var(--ink)' : '0',
              background:p.color, color:p.dark?'var(--bg)':'var(--ink)',
              display:'flex', flexDirection:'column', gap:18, position:'relative', minHeight:560,
            }}>
              {p.recommended && <div className="chip chip-dark" style={{ position:'absolute', top:-12, right:24 }}>★ Recommandé</div>}
              <div className="mono" style={{ fontSize:11, letterSpacing:'.12em' }}>{p.name.toUpperCase()}</div>
              <div>
                <div className="h-display" style={{ fontSize:96, lineHeight:1 }}>{p.price}</div>
                <div className="mono" style={{ fontSize:13, letterSpacing:'.05em', marginTop:4 }}>{p.unit}</div>
              </div>
              <p style={{ margin:0, fontSize:13.5, opacity:.85 }}>{p.sub}</p>
              <div style={{ height:1, background:p.dark?'rgba(242,239,230,.2)':'rgba(13,13,13,.15)' }}/>
              <ul style={{ listStyle:'none', padding:0, margin:0, display:'flex', flexDirection:'column', gap:10 }}>
                {p.perks.map(pe => (
                  <li key={pe} style={{ display:'flex', gap:10, fontSize:13.5 }}>
                    <Icon name="check" size={16}/>{pe}
                  </li>
                ))}
              </ul>
              <button className={`btn ${p.dark?'':'btn-dark'}`} style={{
                marginTop:'auto', alignSelf:'flex-start',
                ...(p.dark?{background:'var(--accent)',color:'var(--ink)',borderColor:'var(--accent)'}:{}),
              }}>{p.cta} <Icon name="arrow" size={14}/></button>
            </div>
          ))}
        </div>

        {/* FAQ */}
        <div style={{ marginTop:64 }}>
          <SectionHeader index="?" title="Foire aux questions"/>
          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:0, border:'1px solid var(--ink)', borderTop:0 }}>
            {[
              ['Je peux essayer avant de payer ?','7 jours d’essai gratuit, sans CB. Tu as accès au forum et à 5 opportunités.'],
              ['Comment vérifier mon adhésion BazaArt ?','Connecte-toi avec ton email d’adhérent — on récupère ton statut automatiquement.'],
              ['Et si je suis une structure ?','Les structures publient des opportunités gratuitement après validation par l’équipe.'],
              ['Vous prenez une commission sur les aides ?','Jamais. Le montant des bourses te revient à 100%, sans frais BazaArt.'],
            ].map(([q, a], i) => (
              <div key={q} style={{
                padding:'22px 24px',
                borderRight: i%2===0 ? '1px solid var(--ink)' : '0',
                borderBottom: i<2 ? '1px solid var(--ink)' : '0',
              }}>
                <div className="mono" style={{ fontSize:11, letterSpacing:'.08em', color:'var(--ink-mute)' }}>Q.{String(i+1).padStart(2,'0')}</div>
                <h4 className="h-display" style={{ fontSize:22, margin:'8px 0 10px' }}>{q}</h4>
                <p style={{ margin:0, color:'var(--ink-mute)', lineHeight:1.5 }}>{a}</p>
              </div>
            ))}
          </div>
        </div>
      </section>
      <Footer go={go}/>
    </div>
  );
};

// ─── BLOG ────────────────────────────────────────────────────────────────────
const ScreenBlog = ({ go }) => {
  const [open, setOpen] = React.useState(null);
  if (open) return <ArticleView article={open} onBack={()=>setOpen(null)} go={go}/>;
  return (
    <div className="screen" data-screen-label="07 Blog">
      <section style={{ borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ paddingTop:48, paddingBottom:32 }}>
          <Eyebrow>Le journal de BazaArt</Eyebrow>
          <h1 className="h-display" style={{ fontSize:'clamp(80px, 11vw, 160px)', margin:'12px 0 0', lineHeight:.88 }}>
            ON ÉCRIT.<br/>VOUS LISEZ.
          </h1>
        </div>
      </section>

      {/* featured */}
      <section className="container" style={{ paddingTop:32 }}>
        <div onClick={()=>setOpen(ARTICLES[0])} style={{ display:'grid', gridTemplateColumns:'1.1fr .9fr', gap:32, border:'1px solid var(--ink)', cursor:'default' }}>
          <div style={{ background:'var(--ink)', color:'var(--bg)', padding:'40px 36px', display:'flex', flexDirection:'column', justifyContent:'space-between', minHeight:480 }}>
            <div>
              <span className="chip" style={{ background:'var(--accent)' }}>★ À LA UNE</span>
              <h2 className="h-display" style={{ fontSize:64, margin:'24px 0 0', lineHeight:.92 }}>{ARTICLES[0].title}</h2>
              <p className="serif" style={{ fontSize:22, fontStyle:'italic', marginTop:20, color:'rgba(242,239,230,.8)' }}>{ARTICLES[0].excerpt}</p>
            </div>
            <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', opacity:.7 }}>
              {ARTICLES[0].date.toUpperCase()} · {ARTICLES[0].read.toUpperCase()} · {ARTICLES[0].tag.toUpperCase()}
            </div>
          </div>
          <Placeholder label="cover article — manifeste" ratio="" style={{ minHeight:480 }}/>
        </div>
      </section>

      {/* grid */}
      <section className="container" style={{ paddingTop:48, paddingBottom:48 }}>
        <SectionHeader title="Tous les articles" subtitle="33 publications · trié par récence"
          action={
            <div style={{ display:'flex', gap:8 }}>
              {['Tous','Manifeste','Ressource','Enquête','Pratique'].map((c,i)=>(
                <span key={c} className="chip" style={{ background: i===0?'var(--ink)':'var(--bg)', color: i===0?'var(--accent)':'var(--ink)' }}>{c}</span>
              ))}
            </div>
          }
        />
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:0, borderLeft:'1px solid var(--ink)', borderRight:'1px solid var(--ink)', borderBottom:'1px solid var(--ink)' }}>
          {ARTICLES.slice(1).concat(ARTICLES.slice(1)).map((a, i) => (
            <button key={i} onClick={()=>setOpen(a)} style={{
              padding:'28px 22px', borderRight: (i%3)<2 ? '1px solid var(--ink)' : '0',
              borderTop: i>=3 ? '1px solid var(--ink)' : '0',
              background:'transparent', textAlign:'left', cursor:'default', fontFamily:'inherit', color:'inherit',
              display:'flex', flexDirection:'column', gap:14,
            }}>
              <Placeholder label={a.cover} ratio="3/2"/>
              <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>{a.tag.toUpperCase()} · {a.read.toUpperCase()}</div>
              <h3 className="h-display" style={{ fontSize:24, margin:0, lineHeight:1 }}>{a.title}</h3>
              <p style={{ margin:0, fontSize:13, color:'var(--ink-mute)' }}>{a.excerpt}</p>
              <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:'auto' }}>{a.date}</div>
            </button>
          ))}
        </div>
      </section>
      <Footer go={go}/>
    </div>
  );
};

const ArticleView = ({ article, onBack, go }) => (
  <div className="screen" data-screen-label="07b Article">
    <div className="container" style={{ paddingTop:32, paddingBottom:64, maxWidth:880 }}>
      <button className="btn btn-sm btn-ghost" onClick={onBack}>← Retour au blog</button>
      <div style={{ marginTop:32 }}>
        <Eyebrow>{article.tag} · {article.read} · {article.date}</Eyebrow>
        <h1 className="h-display" style={{ fontSize:80, lineHeight:.92, margin:'16px 0 24px' }}>{article.title}</h1>
        <p className="serif" style={{ fontSize:24, fontStyle:'italic', color:'var(--ink-mute)' }}>{article.excerpt}</p>
      </div>
      <Placeholder label="image éditoriale" ratio="16/9" style={{ marginTop:32 }}/>
      <div style={{ marginTop:32, fontSize:17, lineHeight:1.65, color:'var(--ink)' }}>
        <p style={{ fontSize:22, lineHeight:1.5 }}><span style={{ float:'left', fontSize:88, lineHeight:.85, fontFamily:'var(--display)', marginRight:8, marginTop:-4 }}>I</span>l y a trois ans, on a démarré dans un appartement à Belleville. Six personnes, une feuille A3, des post-its de toutes les couleurs. La question : qu’est-ce qui manque vraiment aux artistes aujourd’hui, et qu’est-ce qu’on peut faire à notre échelle.</p>
        <p>La réponse a tenu en trois mots : <em>les infos, les pairs, les opportunités.</em> Pas l’IA. Pas une marketplace de plus. Pas une énième newsletter. Juste un endroit où tout converge, sans intermédiaire qui prélève au passage.</p>
        <h3 className="h-display" style={{ fontSize:36, marginTop:32 }}>Le constat de départ</h3>
        <p>800 artistes interrogés en 2024. 71% disent perdre plus de 4 heures par semaine à chercher des opportunités sur des sites institutionnels mal indexés. 63% n’ont jamais reçu de mentorat. 88% aimeraient « discuter avec d’autres artistes qui font la même chose ».</p>
        <p>BazaArt c’est ça : un outil qui répond à ces trois besoins, opéré par une coopérative — donc sans actionnaire à servir.</p>
        <h3 className="h-display" style={{ fontSize:36, marginTop:32 }}>Ce qu’on ne fera pas</h3>
        <p>On ne vendra jamais vos données. On ne prendra jamais de commission sur les aides obtenues. On n’ajoutera pas de pub. Et si un jour on dévie, vous nous le direz — on est une asso, le contre-pouvoir c’est vous.</p>
      </div>
    </div>
    <Footer go={go}/>
  </div>
);

// ─── FOOTER ──────────────────────────────────────────────────────────────────
const Footer = ({ go }) => (
  <footer style={{ background:'var(--ink)', color:'var(--bg)', borderTop:'1px solid var(--ink)' }}>
    <div className="container" style={{ paddingTop:48, paddingBottom:24 }}>
      <div className="h-display" style={{ fontSize:'clamp(80px, 14vw, 220px)', lineHeight:.85, color:'var(--accent)', letterSpacing:'-.03em' }}>
        BAZAART.
      </div>
      <div style={{ display:'grid', gridTemplateColumns:'2fr 1fr 1fr 1fr 1fr', gap:32, marginTop:32, paddingTop:32, borderTop:'1px solid var(--line-soft)' }}>
        <div>
          <Eyebrow dot={false}>L’asso</Eyebrow>
          <p style={{ marginTop:12, fontSize:13.5, color:'rgba(242,239,230,.65)', maxWidth:380 }}>
            Association loi 1901 basée à Paris, opérant la plateforme BazaArt et trois hubs : événementiel, ingénierie culturelle et formation.
          </p>
          <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)', marginTop:12, opacity:.7 }}>
            12 RUE DES ARTS · 75020 PARIS<br/>SIRET 893 217 449 00012
          </div>
        </div>
        {[
          { t:'Plateforme', l:[['Opportunités','opportunities'],['Forum','forum'],['Dashboard','dashboard-artist'],['Blog','blog']]},
          { t:'L’asso', l:[['Hub Event','hub-event'],['Hub Ingénierie','hub-ing'],['Hub Formation','hub-form'],['Adhérer','pricing']]},
          { t:'Structures', l:[['Publier','dashboard-structure'],['Documentation','blog'],['Espace admin','dashboard-admin']]},
          { t:'Légal', l:[['CGU','blog'],['Confidentialité','blog'],['Contact','blog']]},
        ].map(c => (
          <div key={c.t}>
            <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'rgba(242,239,230,.4)' }}>{c.t.toUpperCase()}</div>
            <ul style={{ listStyle:'none', padding:0, margin:'12px 0 0', display:'flex', flexDirection:'column', gap:8 }}>
              {c.l.map(([n, g]) => (
                <li key={n}><button onClick={()=>go(g)} style={{ background:'transparent', border:0, color:'var(--bg)', padding:0, font:'500 13px var(--body)', cursor:'default' }}>{n}</button></li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div style={{ marginTop:32, paddingTop:16, borderTop:'1px solid var(--line-soft)', display:'flex', justifyContent:'space-between', fontFamily:'var(--mono)', fontSize:11, letterSpacing:'.08em', color:'rgba(242,239,230,.5)' }}>
        <span>© 2026 BAZAART — TOUT EST À NOUS</span>
        <span>v1.0 · MIS À JOUR LE 21 MAI 2026</span>
      </div>
    </div>
  </footer>
);

Object.assign(window, { ScreenLanding, ScreenPricing, ScreenBlog, Footer });
