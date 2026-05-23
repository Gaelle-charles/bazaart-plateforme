// src/screens-admin.jsx — Admin dashboard

const ScreenAdminDashboard = ({ go }) => {
  const [tab, setTab] = React.useState('overview');
  return (
    <div className="screen" data-screen-label="06 Dashboard admin" style={{ background:'var(--bg-alt)' }}>
      {/* Top bar */}
      <section style={{ background:'var(--ink)', color:'var(--bg)', borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ padding:'18px 32px', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
          <div style={{ display:'flex', alignItems:'center', gap:14 }}>
            <Icon name="shield" size={20} style={{ color:'var(--accent)' }}/>
            <div>
              <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', color:'var(--accent)' }}>ESPACE ADMIN · ACCÈS RESTREINT</div>
              <div className="h-display" style={{ fontSize:24, marginTop:2 }}>COCKPIT BAZAART</div>
            </div>
          </div>
          <div style={{ display:'flex', alignItems:'center', gap:14, fontFamily:'var(--mono)', fontSize:11 }}>
            <span style={{ opacity:.6 }}>CONNECTÉ COMME</span>
            <span style={{ color:'var(--accent)', fontWeight:700 }}>SARAH B. (SUPER-ADMIN)</span>
            <Avatar initials="SB" size={28} bg="var(--accent)" fg="var(--ink)"/>
          </div>
        </div>
      </section>

      {/* Tabs */}
      <div style={{ borderBottom:'1px solid var(--ink)', background:'var(--bg)' }}>
        <div className="container" style={{ display:'flex', padding:0 }}>
          {[
            ['overview','Vue d’ensemble','chart'],
            ['validation','Validation · 14','check'],
            ['users','Utilisateurs · 1 824','user'],
            ['structures','Structures · 87','home'],
            ['articles','Articles','book'],
            ['payments','Paiements','money'],
            ['mods','Modération · 3','shield'],
          ].map(([k, l, ic], i, a) => (
            <button key={k} onClick={()=>setTab(k)} style={{
              padding:'14px 18px', display:'inline-flex', alignItems:'center', gap:8,
              background: tab===k?'var(--bg-alt)':'transparent', color:'var(--ink)',
              borderTop: tab===k?'2px solid var(--ink)':'2px solid transparent',
              border:0, borderRight: i<a.length-1?'1px solid var(--line-soft)':'0',
              borderTopColor: tab===k?'var(--accent-2)':'transparent', borderTopWidth:2, borderTopStyle:'solid',
              font:'700 12px var(--body)', letterSpacing:'.04em', textTransform:'uppercase', cursor:'default',
              borderBottom: tab===k?'2px solid var(--bg-alt)':'2px solid transparent',
              marginBottom: tab===k?-1:0,
            }}>
              <Icon name={ic} size={13}/> {l}
            </button>
          ))}
        </div>
      </div>

      <section className="container" style={{ paddingTop:24, paddingBottom:48 }}>
        {tab==='overview' && <AdminOverview go={go} setTab={setTab}/>}
        {tab==='validation' && <AdminValidation/>}
        {tab==='users' && <AdminUsers/>}
        {tab==='structures' && <AdminStructures/>}
        {tab==='articles' && <AdminArticles/>}
        {tab==='payments' && <AdminPayments/>}
        {tab==='mods' && <AdminMods/>}
      </section>
    </div>
  );
};

// ─── OVERVIEW ────────────────────────────────────────────────────────────────
const AdminOverview = ({ go, setTab }) => (
  <div>
    {/* KPI grid */}
    <div style={{ display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:0, border:'1px solid var(--ink)', background:'var(--bg)' }}>
      {[
        { v:'1 824', d:'+ 48 cette semaine', l:'Artistes inscrits', g:'var(--accent)' },
        { v:'87', d:'+ 3 cette semaine', l:'Structures', g:'var(--accent-2)' },
        { v:'247', d:'14 en attente', l:'Opportunités actives', g:'var(--accent)' },
        { v:'9 432 €', d:'MRR · + 12% / mois', l:'Revenus mensuels', g:'var(--accent)' },
      ].map((s, i, a) => (
        <div key={s.l} style={{ padding:'24px 22px', borderRight: i<a.length-1?'1px solid var(--ink)':'0', display:'flex', flexDirection:'column', gap:6 }}>
          <div className="mono" style={{ fontSize:11, letterSpacing:'.08em', color:'var(--ink-mute)' }}>{s.l.toUpperCase()}</div>
          <div className="h-display" style={{ fontSize:56, lineHeight:.9 }}>{s.v}</div>
          <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>
            <span style={{ color:s.g, fontWeight:700 }}>● </span>{s.d}
          </div>
        </div>
      ))}
    </div>

    {/* Chart + Queue */}
    <div style={{ display:'grid', gridTemplateColumns:'1.6fr 1fr', gap:24, marginTop:24 }}>
      {/* Chart card */}
      <div style={{ border:'1px solid var(--ink)', background:'var(--bg)', padding:24 }}>
        <div style={{ display:'flex', justifyContent:'space-between' }}>
          <div>
            <Eyebrow>Revenus & inscriptions · 12 mois</Eyebrow>
            <div className="h-display" style={{ fontSize:36, marginTop:8 }}>108 240 €</div>
            <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>CUMULÉ DEPUIS JANVIER 2025</div>
          </div>
          <div style={{ display:'flex', gap:6 }}>
            <span className="chip">12 mois</span>
            <span className="chip" style={{ background:'var(--ink)', color:'var(--accent)' }}>3 mois</span>
            <span className="chip">30 jours</span>
          </div>
        </div>
        {/* SVG chart */}
        <svg viewBox="0 0 600 220" style={{ width:'100%', height:220, marginTop:20 }}>
          {/* y grid */}
          {[0,1,2,3,4].map(i => (<line key={i} x1="40" x2="600" y1={20+i*44} y2={20+i*44} stroke="var(--line-soft)" strokeWidth=".7"/>))}
          {/* y labels */}
          {['12k','9k','6k','3k','0'].map((l,i)=>(<text key={l} x="0" y={24+i*44} fontFamily="var(--mono)" fontSize="9" fill="var(--ink-mute)">{l}</text>))}
          {/* bars */}
          {[
            ['Juin','5','30'],['Juil','7','45'],['Août','6','38'],['Sept','8','52'],
            ['Oct','9','61'],['Nov','11','72'],['Déc','12','80'],['Jan','13','88'],
            ['Fév','14','100'],['Mar','15','115'],['Avr','16','130'],['Mai','17','140'],
          ].map(([m, e, h], i, arr) => {
            const x = 50 + i * 46;
            const ht = parseFloat(h) * 1.4;
            return (
              <g key={m}>
                <rect x={x} y={200-ht} width="32" height={ht} fill={i===arr.length-1?'var(--accent-2)':'var(--ink)'}/>
                <text x={x+16} y={214} textAnchor="middle" fontFamily="var(--mono)" fontSize="9" fill="var(--ink-mute)">{m}</text>
              </g>
            );
          })}
          {/* line: signups */}
          <polyline
            points="66,160 112,148 158,150 204,132 250,118 296,108 342,100 388,90 434,80 480,70 526,62 572,52"
            fill="none" stroke="var(--accent)" strokeWidth="2.5"
          />
          {[160,148,150,132,118,108,100,90,80,70,62,52].map((y, i)=>(
            <circle key={i} cx={66+i*46} cy={y} r="3.5" fill="var(--bg)" stroke="var(--accent)" strokeWidth="2"/>
          ))}
        </svg>
        <div style={{ display:'flex', gap:18, marginTop:14, fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>
          <span><span style={{ display:'inline-block', width:10, height:10, background:'var(--ink)', marginRight:6 }}/>Revenus (€)</span>
          <span><span style={{ display:'inline-block', width:10, height:10, background:'var(--accent)', marginRight:6, borderRadius:'50%' }}/>Nouveaux inscrits</span>
        </div>
      </div>

      {/* Validation queue */}
      <div style={{ border:'1px solid var(--ink)', background:'var(--bg)' }}>
        <div style={{ padding:'14px 18px', background:'var(--accent-2)', color:'var(--ink)', display:'flex', justifyContent:'space-between' }}>
          <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', fontWeight:700 }}>⚠ FILE DE VALIDATION</div>
          <span className="chip chip-dark" style={{ fontSize:10 }}>14 EN ATTENTE</span>
        </div>
        {[
          { t:'Performance · plateau ouvert', s:'Manufacture des Œillets', age:'1h', kind:'opp' },
          { t:'Studio son disponible — 6 mois', s:'La Pop', age:'3h', kind:'opp' },
          { t:'Cie KARSI — nouveau compte', s:'Structure', age:'5h', kind:'struct' },
          { t:'Bourse anonyme — 8 000 €', s:'Fondation X', age:'1j', kind:'opp', warn:true },
          { t:'Article : « Sortir du burn-out d’atelier »', s:'Rédaction', age:'2j', kind:'art' },
        ].map((r, i, a) => (
          <div key={i} style={{ padding:'14px 18px', borderTop:'1px solid var(--line-soft)', display:'flex', alignItems:'center', gap:12 }}>
            <span style={{
              width:8, height:8, borderRadius:'50%',
              background: r.warn ? 'var(--accent-2)' : 'var(--accent)',
            }}/>
            <div style={{ flex:1 }}>
              <div style={{ fontSize:13, fontWeight:600 }}>{r.t} {r.warn && <span className="mono" style={{ fontSize:10, color:'var(--accent-2)' }}>· À VÉRIFIER</span>}</div>
              <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:3 }}>{r.s.toUpperCase()} · IL Y A {r.age}</div>
            </div>
            <div style={{ display:'flex', gap:6 }}>
              <button style={{ width:28, height:28, background:'var(--accent)', border:'1px solid var(--ink)', display:'flex', alignItems:'center', justifyContent:'center', cursor:'default' }}><Icon name="check" size={14}/></button>
              <button style={{ width:28, height:28, background:'var(--bg)', border:'1px solid var(--ink)', display:'flex', alignItems:'center', justifyContent:'center', cursor:'default' }}><Icon name="x" size={14}/></button>
            </div>
          </div>
        ))}
        <button onClick={()=>setTab('validation')} style={{
          width:'100%', padding:'14px', background:'var(--ink)', color:'var(--accent)', border:0,
          fontFamily:'var(--mono)', fontSize:11, letterSpacing:'.1em', fontWeight:700, cursor:'default',
        }}>
          OUVRIR LA FILE COMPLÈTE →
        </button>
      </div>
    </div>

    {/* Activity feed + Health */}
    <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:24, marginTop:24 }}>
      <div style={{ border:'1px solid var(--ink)', background:'var(--bg)' }}>
        <div style={{ padding:'14px 18px', borderBottom:'1px solid var(--ink)' }}>
          <Eyebrow>Activité récente</Eyebrow>
        </div>
        {[
          { ic:'check', t:'Sarah B. a validé « Studio son disponible »', time:'14:18' },
          { ic:'user', t:'Inès Khoury a complété son profil (98%)', time:'13:52' },
          { ic:'money', t:'Nouveau paiement annuel — 79 € (M. Vasseur)', time:'13:41' },
          { ic:'shield', t:'Signalement modéré — post #2841 archivé', time:'12:08' },
          { ic:'plus', t:'Festival Lumineuses a publié 2 nouvelles opportunités', time:'11:24' },
          { ic:'book', t:'Article « Cartographie aides 2026 » publié', time:'09:30' },
        ].map((a, i, arr) => (
          <div key={i} style={{ padding:'10px 18px', borderTop: i>0?'1px solid var(--line-soft)':0, display:'flex', alignItems:'center', gap:14 }}>
            <div style={{ width:28, height:28, background:'var(--bg-alt)', border:'1px solid var(--line-soft)', display:'flex', alignItems:'center', justifyContent:'center' }}>
              <Icon name={a.ic} size={13}/>
            </div>
            <div style={{ flex:1, fontSize:13 }}>{a.t}</div>
            <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>{a.time}</div>
          </div>
        ))}
      </div>

      <div style={{ border:'1px solid var(--ink)', background:'var(--bg)' }}>
        <div style={{ padding:'14px 18px', borderBottom:'1px solid var(--ink)' }}>
          <Eyebrow>Santé de la plateforme</Eyebrow>
        </div>
        <div style={{ padding:18, display:'flex', flexDirection:'column', gap:12 }}>
          {[
            { l:'Disponibilité 30j', v:'99.97%', g:'var(--accent)' },
            { l:'Temps de réponse moyen (P95)', v:'182 ms', g:'var(--accent)' },
            { l:'Délai moyen de validation opportunité', v:'4h 12min', g:'var(--accent)' },
            { l:'Taux de complétude profil moyen', v:'71%', g:'var(--accent-2)' },
            { l:'Signalements en cours', v:'3 actifs · 0 critique', g:'var(--accent-2)' },
            { l:'Taux de churn mensuel', v:'2.1%', g:'var(--accent)' },
          ].map(r => (
            <div key={r.l} style={{ display:'flex', justifyContent:'space-between', alignItems:'center', borderBottom:'1px dashed var(--line-soft)', paddingBottom:10 }}>
              <span style={{ fontSize:13, color:'var(--ink-mute)' }}>{r.l}</span>
              <span style={{ display:'inline-flex', alignItems:'center', gap:8 }}>
                <span style={{ width:8, height:8, borderRadius:'50%', background:r.g }}/>
                <span className="mono" style={{ fontSize:13, fontWeight:700 }}>{r.v}</span>
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  </div>
);

// ─── VALIDATION QUEUE ───────────────────────────────────────────────────────
const AdminValidation = () => (
  <div>
    <SectionHeader title="File de validation" subtitle="14 en attente · délai cible : 24h"
      action={<div style={{ display:'flex', gap:8 }}>
        {['Tout','Opportunités','Structures','Articles'].map((c,i)=>(<span key={c} className="chip" style={{ background: i===0?'var(--ink)':'var(--bg)', color: i===0?'var(--accent)':'var(--ink)' }}>{c}</span>))}
      </div>}
    />
    <div style={{ marginTop:16, display:'grid', gridTemplateColumns:'1fr 1fr', gap:16 }}>
      {OPPORTUNITIES.slice(0, 4).map((o, i) => (
        <div key={o.id} style={{ border:'1px solid var(--ink)', background:'var(--bg)' }}>
          <div style={{ padding:'12px 16px', borderBottom:'1px solid var(--line-soft)', display:'flex', justifyContent:'space-between' }}>
            <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>#{2840 - i} · DÉPOSÉ IL Y A {i+1}H · {o.org.toUpperCase()}</div>
            <span className="chip chip-warn" style={{ fontSize:10 }}>EN ATTENTE</span>
          </div>
          <div style={{ padding:'16px 18px' }}>
            <div style={{ display:'flex', gap:8, marginBottom:10 }}>
              <span className="chip" style={{ fontSize:10 }}>{o.type}</span>
              <span className="chip" style={{ fontSize:10 }}>{o.city}</span>
              <span className="chip" style={{ fontSize:10 }}>{o.amount}</span>
            </div>
            <h3 className="h-display" style={{ fontSize:20, margin:0 }}>{o.title}</h3>
            <p style={{ margin:'10px 0 0', fontSize:13, color:'var(--ink-mute)' }}>{o.summary}</p>
            {/* Checklist */}
            <div style={{ marginTop:14, padding:'10px 12px', background:'var(--bg-alt)', borderLeft:'3px solid var(--accent)' }}>
              <div className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>CHECK AUTO</div>
              <div style={{ display:'flex', gap:14, marginTop:8, fontSize:12, flexWrap:'wrap' }}>
                <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="check" size={12}/> Structure vérifiée</span>
                <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="check" size={12}/> Rémunération transparente</span>
                <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="check" size={12}/> Pas de frais cachés</span>
                {i===1 && <span style={{ display:'inline-flex', alignItems:'center', gap:5, color:'var(--accent-2)' }}>⚠ Délai &lt; 14j (à vérifier)</span>}
              </div>
            </div>
          </div>
          <div style={{ padding:'12px 16px', borderTop:'1px solid var(--line-soft)', display:'flex', justifyContent:'space-between', alignItems:'center' }}>
            <button className="btn btn-sm btn-ghost">Voir le détail →</button>
            <div style={{ display:'flex', gap:8 }}>
              <button className="btn btn-sm">Demander une correction</button>
              <button className="btn btn-sm" style={{ background:'var(--danger)', color:'var(--bg)', borderColor:'var(--danger)' }}>Rejeter</button>
              <button className="btn btn-sm btn-primary">✓ Valider</button>
            </div>
          </div>
        </div>
      ))}
    </div>
  </div>
);

// ─── USERS ──────────────────────────────────────────────────────────────────
const AdminUsers = () => (
  <div>
    <SectionHeader title="Utilisateurs" subtitle="1 824 artistes inscrits"
      action={<div style={{ display:'flex', gap:8 }}>
        <button className="btn btn-sm btn-ghost"><Icon name="filter" size={12}/> Filtrer</button>
        <button className="btn btn-sm">Exporter CSV</button>
        <button className="btn btn-sm btn-primary">+ Ajouter</button>
      </div>}
    />
    <div style={{ marginTop:16, border:'1px solid var(--ink)', background:'var(--bg)' }}>
      <div style={{ display:'grid', gridTemplateColumns:'40px 1fr 200px 140px 110px 110px 90px 60px', background:'var(--ink)', color:'var(--accent)', fontFamily:'var(--mono)', fontSize:10, letterSpacing:'.1em', fontWeight:700 }}>
        {['','UTILISATEUR','PRATIQUE','VILLE','PLAN','INSCRIT','STATUT',''].map((h, i, a)=>(
          <div key={i} style={{ padding:'12px 14px', borderRight: i<a.length-1?'1px solid rgba(198,242,78,.15)':'0' }}>{h}</div>
        ))}
      </div>
      {ARTISTS.concat(ARTISTS).map((a, i) => (
        <div key={i} style={{
          display:'grid', gridTemplateColumns:'40px 1fr 200px 140px 110px 110px 90px 60px',
          borderTop: i>0?'1px solid var(--line-soft)':0,
          background: i%2===0?'var(--bg)':'var(--bg-alt)',
        }}>
          <div style={{ padding:'12px 14px', display:'flex', alignItems:'center' }}><input type="checkbox" defaultChecked={i===0}/></div>
          <div style={{ padding:'10px 14px', display:'flex', gap:10, alignItems:'center' }}>
            <Avatar initials={a.initials} size={28} bg={a.color} fg="var(--ink)"/>
            <div>
              <div style={{ fontWeight:600, fontSize:13 }}>{a.name}</div>
              <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>@{a.name.toLowerCase().replace(' ', '.')}</div>
            </div>
          </div>
          <div style={{ padding:'12px 14px', fontSize:12.5 }}>{a.practice}</div>
          <div style={{ padding:'12px 14px', fontSize:12.5 }}>{a.city}</div>
          <div style={{ padding:'12px 14px' }}>
            <span className="chip" style={{ fontSize:9, padding:'2px 6px', background: i%3===0?'var(--accent)':i%3===1?'var(--bg-alt)':'var(--ink)', color: i%3===2?'var(--bg)':'var(--ink)' }}>
              {i%3===0?'ANNUEL':i%3===1?'MENSUEL':'ADHÉRENT'}
            </span>
          </div>
          <div style={{ padding:'12px 14px', fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>{['2024-09','2025-02','2025-11','2026-01','2026-03'][i%5]}</div>
          <div style={{ padding:'12px 14px' }}>
            <span style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:11 }}>
              <span style={{ width:6, height:6, borderRadius:'50%', background: i%5===4?'var(--accent-2)':'var(--accent)' }}/>
              {i%5===4?'Suspendu':'Actif'}
            </span>
          </div>
          <div style={{ padding:'12px 8px' }}><Icon name="dot3" size={16}/></div>
        </div>
      ))}
    </div>
  </div>
);

// ─── STRUCTURES ─────────────────────────────────────────────────────────────
const AdminStructures = () => (
  <div>
    <SectionHeader title="Structures" subtitle="87 structures vérifiées"/>
    <div style={{ marginTop:16, display:'grid', gridTemplateColumns:'1fr 1fr', gap:14 }}>
      {STRUCTURES.concat(STRUCTURES).map((s, i) => (
        <div key={i} style={{ border:'1px solid var(--ink)', background:'var(--bg)', padding:'16px 18px', display:'flex', alignItems:'center', gap:14 }}>
          <Avatar initials={s.name.split(' ').slice(0,2).map(w=>w[0]).join('')} size={42} bg="var(--ink)" fg="var(--accent)"/>
          <div style={{ flex:1, minWidth:0 }}>
            <div style={{ fontWeight:700 }}>{s.name}</div>
            <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:3 }}>{s.kind.toUpperCase()} · {s.city.toUpperCase()}</div>
          </div>
          <div style={{ textAlign:'right' }}>
            <div className="h-display" style={{ fontSize:24 }}>{s.published}</div>
            <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>OPPORTUNITÉS</div>
          </div>
          <Icon name="dot3" size={16}/>
        </div>
      ))}
    </div>
  </div>
);

// ─── ARTICLES ───────────────────────────────────────────────────────────────
const AdminArticles = () => (
  <div>
    <SectionHeader title="Articles & ressources"
      action={<button className="btn btn-sm btn-primary">+ Nouveau brouillon</button>}
    />
    <div style={{ marginTop:16, border:'1px solid var(--ink)', background:'var(--bg)' }}>
      {[...ARTICLES, ...ARTICLES.slice(0,2)].map((a, i) => (
        <div key={i} style={{
          display:'grid', gridTemplateColumns:'60px 1fr 120px 120px 120px 80px',
          alignItems:'center',
          borderTop: i>0?'1px solid var(--line-soft)':0,
        }}>
          <div style={{ padding:'10px 14px' }}><Placeholder label="" ratio="1" style={{ minHeight:36 }}/></div>
          <div style={{ padding:'14px 6px' }}>
            <div style={{ fontWeight:700, fontSize:13.5 }}>{a.title}</div>
            <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:3 }}>{a.excerpt}</div>
          </div>
          <div style={{ padding:'14px 12px' }}><span className="chip" style={{ fontSize:10 }}>{a.tag}</span></div>
          <div style={{ padding:'14px 12px' }}>
            <span className="chip" style={{ fontSize:10, background: i<3?'var(--accent)':i<5?'var(--accent-2)':'var(--bg-alt)' }}>
              {i<3?'PUBLIÉ':i<5?'BROUILLON':'PROGRAMMÉ'}
            </span>
          </div>
          <div style={{ padding:'14px 12px', fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>{a.date}</div>
          <div style={{ padding:'14px 12px', display:'flex', gap:6 }}>
            <Icon name="edit" size={14}/><Icon name="trash" size={14}/>
          </div>
        </div>
      ))}
    </div>
  </div>
);

// ─── PAYMENTS ───────────────────────────────────────────────────────────────
const AdminPayments = () => (
  <div>
    <div style={{ display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:0, border:'1px solid var(--ink)', background:'var(--bg)' }}>
      {[
        { v:'9 432 €', l:'MRR · mai 2026', d:'+12% / avril' },
        { v:'108 240 €', l:'Cumul 2026', d:'objectif 2026 · 230 k' },
        { v:'1 219', l:'Abonnés payants', d:'67% du total' },
        { v:'2.1%', l:'Churn mensuel', d:'stable' },
      ].map((s, i, a) => (
        <div key={s.l} style={{ padding:'22px', borderRight: i<a.length-1?'1px solid var(--ink)':'0' }}>
          <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', letterSpacing:'.08em' }}>{s.l.toUpperCase()}</div>
          <div className="h-display" style={{ fontSize:48, marginTop:6, lineHeight:1 }}>{s.v}</div>
          <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:6 }}>{s.d}</div>
        </div>
      ))}
    </div>

    <div style={{ marginTop:24, display:'grid', gridTemplateColumns:'1fr 1fr', gap:16 }}>
      <div style={{ border:'1px solid var(--ink)', background:'var(--bg)' }}>
        <div style={{ padding:'14px 18px', borderBottom:'1px solid var(--ink)' }}>
          <Eyebrow>Répartition des plans</Eyebrow>
        </div>
        <div style={{ padding:24 }}>
          {[
            { l:'Annuel · 79 €', v:'742', pct:60.8, c:'var(--accent)' },
            { l:'Mensuel · 9,90 €', v:'318', pct:26.1, c:'var(--ink)' },
            { l:'Adhérent · 39 €', v:'159', pct:13.1, c:'var(--accent-2)' },
          ].map(p => (
            <div key={p.l} style={{ marginBottom:14 }}>
              <div style={{ display:'flex', justifyContent:'space-between', marginBottom:5, fontSize:13 }}>
                <span style={{ fontWeight:600 }}>{p.l}</span>
                <span className="mono">{p.v} · {p.pct}%</span>
              </div>
              <div style={{ height:10, background:'var(--bg-alt)', border:'1px solid var(--line-soft)' }}>
                <div style={{ width:`${p.pct}%`, height:'100%', background:p.c }}/>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div style={{ border:'1px solid var(--ink)', background:'var(--bg)' }}>
        <div style={{ padding:'14px 18px', borderBottom:'1px solid var(--ink)' }}>
          <Eyebrow>Derniers paiements</Eyebrow>
        </div>
        {[
          { n:'Mira Vasseur', p:'Annuel', m:'79,00 €', t:'14:18', s:'ok' },
          { n:'Yacine Demba', p:'Mensuel', m:'9,90 €', t:'13:02', s:'ok' },
          { n:'Sékou Traoré', p:'Annuel', m:'79,00 €', t:'12:41', s:'ok' },
          { n:'Inès Khoury', p:'Adhérent', m:'39,00 €', t:'11:08', s:'ok' },
          { n:'Joana Pires', p:'Mensuel', m:'9,90 €', t:'09:44', s:'fail' },
        ].map((r, i, a) => (
          <div key={i} style={{ display:'flex', alignItems:'center', gap:14, padding:'12px 18px', borderTop: i>0?'1px solid var(--line-soft)':0 }}>
            <Avatar initials={r.n.split(' ').map(w=>w[0]).join('')} size={28} bg="var(--ink)" fg="var(--accent)"/>
            <div style={{ flex:1 }}>
              <div style={{ fontSize:13, fontWeight:600 }}>{r.n}</div>
              <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>{r.p.toUpperCase()} · {r.t}</div>
            </div>
            <div className="mono" style={{ fontWeight:700, fontSize:13 }}>{r.m}</div>
            {r.s==='ok'
              ? <Icon name="check" size={16} style={{ color:'var(--ok)' }}/>
              : <span className="chip chip-warn" style={{ fontSize:9 }}>ÉCHEC</span>}
          </div>
        ))}
      </div>
    </div>
  </div>
);

// ─── MODS ───────────────────────────────────────────────────────────────────
const AdminMods = () => (
  <div>
    <SectionHeader title="Modération" subtitle="3 signalements actifs"/>
    <div style={{ marginTop:16, display:'flex', flexDirection:'column', gap:14 }}>
      {[
        { who:'YD', post:'« AGESSA vs MDA en 2026 »', why:'Spam / lien externe douteux', who2:'signalé par 4 personnes' },
        { who:'JP', post:'Commentaire dans thread « Live ambient »', why:'Harcèlement présumé', who2:'signalé par 2 personnes' },
        { who:'AB', post:'Profil public', why:'Photo non conforme', who2:'signalé par 1 personne' },
      ].map((m, i) => (
        <div key={i} style={{ border:'1px solid var(--ink)', background:'var(--bg)', padding:'16px 20px', display:'flex', alignItems:'center', gap:20 }}>
          <Avatar initials={m.who} size={42} bg="var(--accent-2)" fg="var(--ink)"/>
          <div style={{ flex:1 }}>
            <div style={{ fontSize:14, fontWeight:600 }}>{m.post}</div>
            <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:3 }}>RAISON: {m.why.toUpperCase()} — {m.who2.toUpperCase()}</div>
          </div>
          <div style={{ display:'flex', gap:8 }}>
            <button className="btn btn-sm btn-ghost">Lire</button>
            <button className="btn btn-sm">Avertir</button>
            <button className="btn btn-sm" style={{ background:'var(--danger)', color:'var(--bg)', borderColor:'var(--danger)' }}>Suspendre</button>
            <button className="btn btn-sm btn-primary">Archiver</button>
          </div>
        </div>
      ))}
    </div>
  </div>
);

Object.assign(window, { ScreenAdminDashboard });
