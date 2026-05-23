// src/screens-opps.jsx — Opportunités (list / magazine / map) + détail

const ScreenOpportunities = ({ go, cardStyle = 'editorial' }) => {
  const [view, setView] = React.useState('list'); // list | grid | map
  const [filters, setFilters] = React.useState({ disc:[], region:'Toutes', type:'Tous', sort:'deadline' });

  return (
    <div className="screen" data-screen-label="02 Opportunités">
      {/* Header */}
      <section style={{ borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ paddingTop:32, paddingBottom:24 }}>
          <div style={{ display:'flex', alignItems:'flex-end', justifyContent:'space-between', gap:24 }}>
            <div>
              <Eyebrow>Opportunités · 247 actives</Eyebrow>
              <h1 className="h-display" style={{ fontSize:'clamp(64px, 9vw, 132px)', margin:'8px 0 0', lineHeight:.88 }}>
                CE QUI EST<br/>OUVERT.
              </h1>
            </div>
            <div style={{ display:'flex', alignItems:'center', gap:14 }}>
              <button className="btn btn-ghost btn-sm"><Icon name="bell" size={14}/> Créer une alerte</button>
              <ViewSwitcher view={view} setView={setView}/>
            </div>
          </div>
        </div>
      </section>

      {/* Filter bar */}
      <FilterBar filters={filters} setFilters={setFilters}/>

      {/* Content */}
      <section className="container" style={{ paddingTop:24, paddingBottom:64 }}>
        {view === 'list' && <OppList go={go}/>}
        {view === 'grid' && <OppGrid go={go} style={cardStyle}/>}
        {view === 'map'  && <OppMap go={go}/>}
      </section>

      <Footer go={go}/>
    </div>
  );
};

// ─── View switcher ──────────────────────────────────────────────────────────
const ViewSwitcher = ({ view, setView }) => (
  <div style={{ display:'inline-flex', border:'1px solid var(--ink)' }}>
    {[['list','Liste','list'],['grid','Magazine','grid'],['map','Carte','map']].map(([k, l, ic], i) => (
      <button key={k} onClick={()=>setView(k)} style={{
        padding:'9px 14px',
        background: view===k ? 'var(--ink)' : 'transparent',
        color: view===k ? 'var(--accent)' : 'var(--ink)',
        borderRight: i<2 ? '1px solid var(--ink)' : '0',
        border:0, borderRight: i<2 ? '1px solid var(--ink)' : '0',
        font:'700 11px var(--body)', letterSpacing:'.06em', textTransform:'uppercase', cursor:'default',
        display:'inline-flex', alignItems:'center', gap:7,
      }}>
        <Icon name={ic} size={13}/> {l}
      </button>
    ))}
  </div>
);

// ─── Filter bar ─────────────────────────────────────────────────────────────
const FilterBar = ({ filters, setFilters }) => {
  return (
    <div style={{ background:'var(--bg-alt)', borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ display:'flex', gap:0, alignItems:'stretch', padding:0 }}>
        {/* Search */}
        <div style={{ display:'flex', alignItems:'center', gap:8, padding:'14px 18px', flex:1, borderRight:'1px solid var(--ink)' }}>
          <Icon name="search" size={16}/>
          <input className="input" placeholder="Mots-clés, organisateur, lieu…" style={{ border:0, background:'transparent', padding:0, fontSize:14 }}/>
        </div>
        <FilterDrop label="Discipline" value={filters.disc.length ? `${filters.disc.length} sélectionnées` : 'Toutes'}/>
        <FilterDrop label="Région" value="Toutes"/>
        <FilterDrop label="Type" value="Tous"/>
        <FilterDrop label="Montant" value="< 30 000 €"/>
        <FilterDrop label="Deadline" value="2026"/>
        <div style={{ display:'flex', alignItems:'center', gap:10, padding:'0 18px' }}>
          <button className="btn btn-sm btn-ghost"><Icon name="x" size={12}/> Effacer</button>
        </div>
      </div>
      {/* Active chip strip */}
      <div className="container" style={{ display:'flex', gap:8, padding:'10px 32px', borderTop:'1px solid var(--line-soft)', alignItems:'center', flexWrap:'wrap' }}>
        <span className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>FILTRES ACTIFS:</span>
        {['Arts visuels','Île-de-France','Résidence','Avec logement'].map(t => (
          <span key={t} className="chip" style={{ background:'var(--bg)' }}>{t} <Icon name="x" size={10}/></span>
        ))}
        <span style={{ marginLeft:'auto', fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>
          → 24 résultats · trié par <strong style={{ color:'var(--ink)' }}>deadline proche</strong>
        </span>
      </div>
    </div>
  );
};

const FilterDrop = ({ label, value }) => (
  <button style={{
    display:'flex', flexDirection:'column', alignItems:'flex-start', gap:2,
    padding:'10px 18px', background:'transparent', border:0, borderRight:'1px solid var(--ink)',
    cursor:'default', minWidth:140, textAlign:'left',
  }}>
    <span className="mono" style={{ fontSize:9, letterSpacing:'.12em', color:'var(--ink-mute)', textTransform:'uppercase' }}>{label}</span>
    <span style={{ fontSize:13, fontWeight:600, display:'flex', alignItems:'center', gap:6 }}>
      {value} <Icon name="arrow" size={11}/>
    </span>
  </button>
);

// ─── LIST view (job-board style) ────────────────────────────────────────────
const OppList = ({ go }) => (
  <div style={{ border:'1px solid var(--ink)' }}>
    {/* Header row */}
    <div style={{
      display:'grid', gridTemplateColumns:'90px 1fr 200px 160px 140px 110px',
      gap:0, background:'var(--ink)', color:'var(--accent)',
      fontFamily:'var(--mono)', fontSize:10, letterSpacing:'.1em', fontWeight:700,
    }}>
      {['#','TITRE','ORGANISATEUR','LIEU','MONTANT','DEADLINE'].map((h, i, a) => (
        <div key={h} style={{ padding:'14px 16px', borderRight: i<a.length-1?'1px solid rgba(198,242,78,.2)':'0' }}>{h}</div>
      ))}
    </div>
    {OPPORTUNITIES.map((o, i) => (
      <button key={o.id} onClick={()=>go('opportunity', o.id)} style={{
        display:'grid', gridTemplateColumns:'90px 1fr 200px 160px 140px 110px', gap:0, width:'100%',
        background: i%2===0 ? 'var(--bg)' : 'var(--bg-alt)',
        border:0, borderTop: i>0?'1px solid var(--line-soft)':'0', cursor:'default',
        fontFamily:'inherit', color:'inherit', textAlign:'left',
        transition:'background .12s',
      }}
        onMouseEnter={e=>e.currentTarget.style.background='var(--accent)'}
        onMouseLeave={e=>e.currentTarget.style.background= i%2===0 ? 'var(--bg)' : 'var(--bg-alt)'}
      >
        <div style={{ padding:'18px 16px', display:'flex', alignItems:'center', gap:8 }}>
          <span className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>{String(i+1).padStart(3,'0')}</span>
          {o.hot && <Icon name="flame" size={14}/>}
        </div>
        <div style={{ padding:'14px 16px' }}>
          <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:4 }}>
            <span className="chip" style={{ fontSize:10, padding:'2px 6px', background:'var(--bg-alt)' }}>{o.type}</span>
            {o.tags.slice(0,1).map(t => <span key={t} className="mono" style={{ fontSize:10, color:'var(--ink-mute)', letterSpacing:'.05em' }}>· {t}</span>)}
          </div>
          <div style={{ fontWeight:700, fontSize:15 }}>{o.title}</div>
          <div style={{ fontSize:12, color:'var(--ink-mute)', marginTop:4 }}>{o.summary.slice(0, 80)}…</div>
        </div>
        <div style={{ padding:'18px 16px', fontSize:13 }}>{o.org}</div>
        <div style={{ padding:'18px 16px', fontSize:13, display:'flex', alignItems:'center', gap:6 }}>
          <Icon name="pin" size={13}/> {o.city}
        </div>
        <div style={{ padding:'18px 16px', fontFamily:'var(--mono)', fontSize:13, fontWeight:700 }}>{o.amount}</div>
        <div style={{ padding:'14px 12px' }}>
          <Countdown days={o.days} hours={o.hours} mins={o.mins}/>
        </div>
      </button>
    ))}
  </div>
);

// ─── GRID view (editorial magazine) ─────────────────────────────────────────
const OppGrid = ({ go, style = 'editorial' }) => (
  <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:0, border:'1px solid var(--ink)' }}>
    {OPPORTUNITIES.map((o, i) => (
      <OppCard key={o.id} o={o} i={i} go={go} style={style}/>
    ))}
  </div>
);

const OppCard = ({ o, i, go, style }) => {
  const accentColors = ['var(--accent)','var(--accent-2)','var(--bg-alt)'];
  const accent = accentColors[i % 3];
  const border = (i % 3) < 2 ? '1px solid var(--ink)' : '0';
  const borderBottom = i < OPPORTUNITIES.length - 3 ? '1px solid var(--ink)' : '0';

  if (style === 'editorial') return (
    <button onClick={()=>go('opportunity', o.id)} style={{
      padding:'28px 22px', borderRight:border, borderBottom,
      background:'transparent', textAlign:'left', cursor:'default', fontFamily:'inherit', color:'inherit',
      display:'flex', flexDirection:'column', gap:14, minHeight:380,
    }}>
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start' }}>
        <div className="h-display" style={{ fontSize:80, lineHeight:.85, color: accent === 'var(--bg-alt)' ? 'var(--ink-mute)' : accent, opacity: accent === 'var(--bg-alt)' ? .25 : 1 }}>
          {String(i+1).padStart(2,'0')}
        </div>
        {o.hot && <span className="chip chip-warn"><Icon name="flame" size={10}/> hot</span>}
      </div>
      <span className="chip" style={{ background:accent, alignSelf:'flex-start' }}>{o.type}</span>
      <h3 className="h-display" style={{ fontSize:24, margin:0, lineHeight:1.05 }}>{o.title}</h3>
      <div style={{ fontSize:12, color:'var(--ink-mute)', display:'flex', gap:12 }}>
        <span style={{ display:'inline-flex', gap:5, alignItems:'center' }}><Icon name="pin" size={12}/> {o.city}</span>
        <span style={{ display:'inline-flex', gap:5, alignItems:'center' }}><Icon name="euro" size={12}/> {o.amount}</span>
      </div>
      <p style={{ fontSize:13, color:'var(--ink-mute)', margin:0, lineHeight:1.45 }}>{o.summary}</p>
      <div style={{ marginTop:'auto', display:'flex', justifyContent:'space-between', alignItems:'center', paddingTop:12, borderTop:'1px solid var(--line-soft)' }}>
        <Countdown days={o.days} hours={o.hours} mins={o.mins}/>
        <span style={{ display:'inline-flex', alignItems:'center', gap:6, fontFamily:'var(--mono)', fontSize:11, fontWeight:700 }}>
          Voir <Icon name="arrow" size={14}/>
        </span>
      </div>
    </button>
  );

  if (style === 'poster') return (
    <button onClick={()=>go('opportunity', o.id)} style={{
      padding:'24px', borderRight:border, borderBottom,
      background: accent === 'var(--bg-alt)' ? 'var(--ink)' : accent,
      color: accent === 'var(--bg-alt)' ? 'var(--bg)' : 'var(--ink)',
      textAlign:'left', cursor:'default', fontFamily:'inherit',
      display:'flex', flexDirection:'column', gap:14, minHeight:380,
    }}>
      <div className="mono" style={{ fontSize:11, letterSpacing:'.1em' }}>№ {String(i+1).padStart(3,'0')} — {o.type.toUpperCase()}</div>
      <h3 className="h-display" style={{ fontSize:36, margin:0, lineHeight:.9, textTransform:'uppercase' }}>{o.title}</h3>
      <div style={{ marginTop:'auto', display:'flex', flexDirection:'column', gap:10 }}>
        <div className="mono" style={{ fontSize:12, fontWeight:700 }}>{o.amount} · {o.city}</div>
        <Countdown days={o.days} hours={o.hours} mins={o.mins}/>
      </div>
    </button>
  );

  // ticket
  return (
    <button onClick={()=>go('opportunity', o.id)} style={{
      borderRight:border, borderBottom, background:'transparent', cursor:'default',
      textAlign:'left', fontFamily:'inherit', color:'inherit', display:'flex', flexDirection:'column',
    }}>
      <Placeholder label={o.discipline[0]} ratio="2/1"/>
      <div style={{ padding:20, display:'flex', flexDirection:'column', gap:10, flex:1 }}>
        <div style={{ display:'flex', justifyContent:'space-between' }}>
          <span className="chip" style={{ background:accent }}>{o.type}</span>
          <Countdown days={o.days} hours={o.hours} mins={o.mins}/>
        </div>
        <h3 className="h-display" style={{ fontSize:22, margin:0 }}>{o.title}</h3>
        <div style={{ fontSize:12, color:'var(--ink-mute)' }}>{o.org} · {o.city}</div>
      </div>
    </button>
  );
};

// ─── MAP view ───────────────────────────────────────────────────────────────
const OppMap = ({ go }) => {
  // Synthetic France map. Positions hand-tuned within an 800x900 viewBox-ish.
  const pins = [
    { id:'o1', x:.55, y:.28 }, { id:'o2', x:.51, y:.16 }, { id:'o3', x:.62, y:.72 },
    { id:'o4', x:.55, y:.28 }, { id:'o5', x:.46, y:.66 }, { id:'o6', x:.51, y:.50 },
    { id:'o7', x:.30, y:.40 }, { id:'o8', x:.51, y:.50 },
  ];
  const [hovered, setHovered] = React.useState(null);
  return (
    <div style={{ display:'grid', gridTemplateColumns:'1.4fr 1fr', gap:0, border:'1px solid var(--ink)', minHeight:640 }}>
      {/* MAP */}
      <div style={{ position:'relative', background:'var(--bg-alt)', borderRight:'1px solid var(--ink)', padding:32, overflow:'hidden' }}>
        {/* grid overlay */}
        <svg width="100%" height="100%" viewBox="0 0 100 100" preserveAspectRatio="none" style={{ position:'absolute', inset:0, opacity:.2 }}>
          {Array.from({length:11}).map((_,i)=>(<g key={i}><line x1={i*10} y1="0" x2={i*10} y2="100" stroke="var(--ink)" strokeWidth=".15"/><line x1="0" y1={i*10} x2="100" y2={i*10} stroke="var(--ink)" strokeWidth=".15"/></g>))}
        </svg>
        {/* Fake France silhouette */}
        <svg viewBox="0 0 100 110" style={{ position:'relative', width:'100%', height:'100%', maxHeight:600 }}>
          <path d="M50 8 L62 10 L70 16 L75 28 L78 40 L72 50 L74 62 L72 75 L65 88 L55 95 L45 96 L35 92 L28 82 L25 70 L20 60 L25 48 L24 38 L28 28 L36 18 L44 12 Z"
                fill="var(--bg)" stroke="var(--ink)" strokeWidth=".4"/>
          {/* Corsica */}
          <path d="M82 78 L86 76 L88 84 L84 90 Z" fill="var(--bg)" stroke="var(--ink)" strokeWidth=".4"/>
          {/* Pins */}
          {pins.map((p, i) => {
            const o = OPPORTUNITIES.find(x=>x.id===p.id);
            const isHover = hovered === i;
            return (
              <g key={i} transform={`translate(${p.x*100}, ${p.y*100})`}
                 onMouseEnter={()=>setHovered(i)} onMouseLeave={()=>setHovered(null)}
                 onClick={()=>go('opportunity', o.id)} style={{ cursor:'default' }}>
                <circle r={isHover?3.5:2.5} fill="var(--accent)" stroke="var(--ink)" strokeWidth=".5"/>
                <circle r="5" fill="var(--accent)" opacity=".25"/>
                {isHover && (
                  <g transform="translate(4, -2)">
                    <rect x="0" y="-5" width="38" height="10" fill="var(--ink)"/>
                    <text x="2" y="2" fill="var(--accent)" fontSize="3.5" fontFamily="var(--mono)" fontWeight="700">{o.city}</text>
                  </g>
                )}
              </g>
            );
          })}
        </svg>
        <div style={{ position:'absolute', left:24, top:24 }}>
          <Eyebrow>247 opportunités · France</Eyebrow>
        </div>
        <div style={{ position:'absolute', right:24, bottom:24, background:'var(--bg)', border:'1px solid var(--ink)', padding:'10px 14px' }}>
          <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>LÉGENDE</div>
          <div style={{ display:'flex', gap:12, marginTop:6, alignItems:'center' }}>
            <span style={{ display:'inline-flex', alignItems:'center', gap:6 }}>
              <span style={{ width:10, height:10, borderRadius:'50%', background:'var(--accent)', border:'1px solid var(--ink)' }}/>
              <span className="mono" style={{ fontSize:11 }}>Ouvert</span>
            </span>
            <span style={{ display:'inline-flex', alignItems:'center', gap:6 }}>
              <span style={{ width:10, height:10, borderRadius:'50%', background:'var(--accent-2)', border:'1px solid var(--ink)' }}/>
              <span className="mono" style={{ fontSize:11 }}>Urgent</span>
            </span>
          </div>
        </div>
      </div>
      {/* SIDE LIST */}
      <div style={{ overflowY:'auto', maxHeight:640 }}>
        {OPPORTUNITIES.map((o, i) => (
          <button key={o.id} onClick={()=>go('opportunity', o.id)} style={{
            display:'block', width:'100%', textAlign:'left', cursor:'default', fontFamily:'inherit', color:'inherit',
            background: hovered === i ? 'var(--accent)' : 'transparent', border:0,
            borderBottom:'1px solid var(--line-soft)', padding:'14px 18px',
          }}
            onMouseEnter={()=>setHovered(i)} onMouseLeave={()=>setHovered(null)}
          >
            <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:4 }}>
              <span className="chip" style={{ fontSize:10, padding:'2px 6px' }}>{o.type}</span>
              <span className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>{o.city}</span>
            </div>
            <div style={{ fontWeight:600, fontSize:14 }}>{o.title}</div>
            <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:4 }}>{o.amount} · J-{o.days}</div>
          </button>
        ))}
      </div>
    </div>
  );
};

// ─── OPPORTUNITY DETAIL ─────────────────────────────────────────────────────
const ScreenOpportunity = ({ id, go }) => {
  const o = OPPORTUNITIES.find(x => x.id === id) || OPPORTUNITIES[0];
  return (
    <div className="screen" data-screen-label="02b Opportunité — détail">
      {/* Hero band */}
      <section style={{ background:'var(--ink)', color:'var(--bg)', borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ paddingTop:24, paddingBottom:32 }}>
          <button className="btn btn-sm" style={{ background:'transparent', color:'var(--bg)', borderColor:'rgba(242,239,230,.3)' }} onClick={()=>go('opportunities')}>
            ← Toutes les opportunités
          </button>
          <div style={{ display:'grid', gridTemplateColumns:'1.6fr 1fr', gap:48, marginTop:24, alignItems:'flex-end' }}>
            <div>
              <div style={{ display:'flex', gap:8, marginBottom:18 }}>
                <span className="chip chip-accent">{o.type}</span>
                <span className="chip" style={{ background:'transparent', color:'var(--bg)', borderColor:'rgba(242,239,230,.3)' }}>{o.discipline.join(' · ')}</span>
                {o.hot && <span className="chip chip-warn"><Icon name="flame" size={11}/> Très demandé</span>}
              </div>
              <h1 className="h-display" style={{ fontSize:'clamp(48px, 7vw, 96px)', margin:0, lineHeight:.92 }}>{o.title}</h1>
              <div style={{ marginTop:24, display:'flex', gap:24, alignItems:'center', flexWrap:'wrap' }}>
                <div style={{ display:'flex', alignItems:'center', gap:10 }}>
                  <Avatar initials={o.org.split(' ').slice(0,2).map(w=>w[0]).join('')} bg="var(--accent)" fg="var(--ink)"/>
                  <div>
                    <div style={{ fontSize:13.5, fontWeight:600 }}>{o.org}</div>
                    <div className="mono" style={{ fontSize:11, opacity:.6 }}>STRUCTURE VÉRIFIÉE</div>
                  </div>
                </div>
                <div className="div-x" style={{ width:1, height:32, background:'rgba(242,239,230,.3)' }}/>
                <div style={{ display:'flex', gap:5, alignItems:'center', fontSize:14 }}><Icon name="pin" size={15}/> {o.city}, {o.region}</div>
                <div style={{ display:'flex', gap:5, alignItems:'center', fontSize:14 }}><Icon name="user" size={15}/> {o.level}</div>
              </div>
            </div>
            <div style={{ display:'flex', flexDirection:'column', gap:12, alignItems:'flex-end' }}>
              <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', opacity:.7 }}>DEADLINE</div>
              <div style={{ display:'flex', gap:6 }}>
                {[['Jours', o.days], ['Heures', o.hours], ['Minutes', o.mins]].map(([k,v])=>(
                  <div key={k} style={{ background:'var(--accent)', color:'var(--ink)', padding:'12px 14px', textAlign:'center', minWidth:64 }}>
                    <div className="h-display" style={{ fontSize:36, lineHeight:1 }}>{String(v).padStart(2,'0')}</div>
                    <div className="mono" style={{ fontSize:9, letterSpacing:'.1em', marginTop:4 }}>{k.toUpperCase()}</div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Body */}
      <section className="container" style={{ paddingTop:48, paddingBottom:64 }}>
        <div style={{ display:'grid', gridTemplateColumns:'2fr 1fr', gap:48 }}>
          {/* LEFT */}
          <div>
            <div style={{ display:'flex', gap:8, marginBottom:24 }}>
              <button className="btn btn-primary">Candidater <Icon name="arrow" size={14}/></button>
              <button className="btn"><Icon name="bookmark" size={14}/> Sauvegarder</button>
              <button className="btn btn-ghost"><Icon name="msg" size={14}/> Discuter dans le forum</button>
            </div>

            <Eyebrow>En bref</Eyebrow>
            <p className="serif" style={{ fontSize:22, lineHeight:1.4, marginTop:12 }}>{o.summary}</p>

            <h3 className="h-display" style={{ fontSize:28, marginTop:40 }}>Ce que tu touches</h3>
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:0, border:'1px solid var(--ink)', marginTop:16 }}>
              {[
                ['Bourse de production', o.amount],
                ['Atelier individuel', '90 m² · accès 24/7'],
                ['Restitution publique', 'Vernissage + presse'],
                ['Per diem', '18 € / jour'],
              ].map((r,i) => (
                <div key={i} style={{ padding:'18px 20px', borderRight: i%2===0?'1px solid var(--ink)':'0', borderTop: i>=2?'1px solid var(--ink)':'0' }}>
                  <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>{r[0].toUpperCase()}</div>
                  <div className="h-display" style={{ fontSize:22, marginTop:6 }}>{r[1]}</div>
                </div>
              ))}
            </div>

            <h3 className="h-display" style={{ fontSize:28, marginTop:40 }}>Calendrier</h3>
            <div style={{ marginTop:16, borderLeft:'2px solid var(--ink)', paddingLeft:24, display:'flex', flexDirection:'column', gap:18 }}>
              {[
                { d:'Dépôt des dossiers', date:'12 juin 2026', state:'open' },
                { d:'Jury & sélection', date:'25 juin → 5 juillet', state:'next' },
                { d:'Annonce des lauréats', date:'15 juillet', state:'next' },
                { d:'Début de la résidence', date:'1 septembre', state:'later' },
                { d:'Restitution publique', date:'8 décembre', state:'later' },
              ].map((s, i)=>(
                <div key={i} style={{ display:'flex', gap:18, alignItems:'flex-start', position:'relative' }}>
                  <div style={{ position:'absolute', left:-31, top:6, width:14, height:14, background: s.state==='open' ? 'var(--accent)' : 'var(--bg)', border:'2px solid var(--ink)', borderRadius:'50%' }}/>
                  <div>
                    <div style={{ fontWeight:600, fontSize:15 }}>{s.d}</div>
                    <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:2 }}>{s.date.toUpperCase()}</div>
                  </div>
                </div>
              ))}
            </div>

            <h3 className="h-display" style={{ fontSize:28, marginTop:40 }}>Critères</h3>
            <ul style={{ paddingLeft:0, listStyle:'none', display:'flex', flexDirection:'column', gap:10, marginTop:16 }}>
              {['Avoir + 18 ans et résider en France','Pratique professionnelle attestée','Dossier de 8 pages max + portfolio','Engagement à la restitution publique'].map(c => (
                <li key={c} style={{ display:'flex', gap:12, padding:'12px 16px', border:'1px solid var(--line-soft)' }}>
                  <Icon name="check" size={18}/><span>{c}</span>
                </li>
              ))}
            </ul>
          </div>

          {/* RIGHT */}
          <aside>
            <div style={{ border:'1px solid var(--ink)', padding:'20px', display:'flex', flexDirection:'column', gap:14 }}>
              <Eyebrow>Tu n’es pas seul</Eyebrow>
              <p style={{ margin:0, fontSize:14, color:'var(--ink-mute)' }}>
                <strong style={{ color:'var(--ink)' }}>74 artistes</strong> ont déjà sauvegardé cette opportunité. <strong style={{ color:'var(--ink)' }}>12 candidatures</strong> en cours d’écriture.
              </p>
              <div style={{ display:'flex', alignItems:'center' }}>
                {ARTISTS.slice(0,4).map((a, i) => (
                  <div key={a.id} style={{ marginLeft: i?-8:0 }}>
                    <Avatar initials={a.initials} size={32} bg={a.color} fg="var(--ink)"/>
                  </div>
                ))}
                <span className="mono" style={{ fontSize:11, marginLeft:10, color:'var(--ink-mute)' }}>+ 70 autres</span>
              </div>
            </div>

            <div style={{ marginTop:16, border:'1px solid var(--ink)', padding:'20px' }}>
              <Eyebrow>Discussion en cours</Eyebrow>
              <div style={{ marginTop:12 }}>
                <div style={{ fontWeight:600, fontSize:14 }}>« Retours sur la résidence à la Manufacture (Ivry) »</div>
                <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:6 }}>YD · 47 réponses · 301 votes</div>
                <button className="btn btn-sm btn-ghost" style={{ marginTop:12 }} onClick={()=>go('thread', 'p6')}>Lire le fil <Icon name="arrow" size={11}/></button>
              </div>
            </div>

            <div style={{ marginTop:16, padding:'20px', background:'var(--accent)' }}>
              <div className="mono" style={{ fontSize:11, letterSpacing:'.1em' }}>BAZAART × MANUFACTURE</div>
              <div className="h-display" style={{ fontSize:24, marginTop:8 }}>Atelier dossier — 12 places</div>
              <p style={{ margin:'10px 0 0', fontSize:13 }}>On t’aide à monter ce dossier. 2 séances de 3h, à Paris ou en visio.</p>
              <button className="btn btn-dark btn-sm" style={{ marginTop:12 }}>S’inscrire <Icon name="arrow" size={11}/></button>
            </div>

            <div style={{ marginTop:16, padding:'20px', border:'1px solid var(--ink)' }}>
              <Eyebrow>Similaires</Eyebrow>
              <div style={{ display:'flex', flexDirection:'column', gap:0, marginTop:12 }}>
                {OPPORTUNITIES.slice(1,4).map((s,i,a) => (
                  <button key={s.id} onClick={()=>go('opportunity', s.id)} style={{ background:'transparent', border:0, borderBottom: i<a.length-1?'1px solid var(--line-soft)':'0', padding:'12px 0', textAlign:'left', cursor:'default', fontFamily:'inherit', color:'inherit' }}>
                    <div style={{ fontWeight:600, fontSize:13 }}>{s.title}</div>
                    <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:4 }}>{s.org} · J-{s.days}</div>
                  </button>
                ))}
              </div>
            </div>
          </aside>
        </div>
      </section>

      <Footer go={go}/>
    </div>
  );
};

Object.assign(window, { ScreenOpportunities, ScreenOpportunity });
