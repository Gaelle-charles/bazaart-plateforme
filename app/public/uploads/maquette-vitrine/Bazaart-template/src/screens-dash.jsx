// src/screens-dash.jsx — Dashboards artiste & structure

// ─── DASHBOARD ARTISTE ──────────────────────────────────────────────────────
const ScreenArtistDashboard = ({ go, profileStyle = 'editorial' }) => {
  const [mode, setMode] = React.useState('private'); // public | private
  return (
    <div className="screen" data-screen-label="04 Dashboard artiste">
      {/* Mode toggle bar */}
      <section style={{ background:'var(--ink)', color:'var(--bg)' }}>
        <div className="container" style={{ paddingTop:14, paddingBottom:14, display:'flex', justifyContent:'space-between', alignItems:'center' }}>
          <div style={{ display:'flex', gap:14, alignItems:'center' }}>
            <span className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'rgba(242,239,230,.5)' }}>VOUS ÊTES EN :</span>
            <div style={{ display:'inline-flex', border:'1px solid var(--bg)' }}>
              {[['private','Mode privé','eyeOff'],['public','Mode public','eye']].map(([k,l,ic],i)=>(
                <button key={k} onClick={()=>setMode(k)} style={{
                  padding:'8px 14px', display:'inline-flex', alignItems:'center', gap:8,
                  background: mode===k?'var(--accent)':'transparent', color: mode===k?'var(--ink)':'var(--bg)',
                  border:0, borderRight: i===0?'1px solid var(--bg)':'0',
                  font:'700 11px var(--body)', letterSpacing:'.06em', textTransform:'uppercase', cursor:'default',
                }}>
                  <Icon name={ic} size={13}/> {l}
                </button>
              ))}
            </div>
            <span className="mono" style={{ fontSize:11, color:'rgba(242,239,230,.5)' }}>
              {mode==='private' ? '· Cockpit perso, candidatures, agenda' : '· Ce que voient les autres'}
            </span>
          </div>
          <div style={{ display:'flex', gap:10 }}>
            <button className="btn btn-sm" style={{ background:'transparent', color:'var(--bg)', borderColor:'rgba(242,239,230,.3)' }}><Icon name="edit" size={12}/> Éditer</button>
            <button className="btn btn-sm" style={{ background:'var(--accent)', color:'var(--ink)', borderColor:'var(--accent)' }}>↗ Partager</button>
          </div>
        </div>
      </section>

      {mode === 'private' ? <ArtistPrivate go={go}/> : <ArtistPublic go={go} style={profileStyle}/>}

      <Footer go={go}/>
    </div>
  );
};

// ─── PRIVATE — cockpit ──────────────────────────────────────────────────────
const ArtistPrivate = ({ go }) => (
  <div>
    {/* Greeting */}
    <section style={{ borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ paddingTop:32, paddingBottom:24 }}>
        <Eyebrow>Mardi 21 mai · 14h22</Eyebrow>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-end', gap:32 }}>
          <h1 className="h-display" style={{ fontSize:'clamp(56px, 8vw, 120px)', margin:'8px 0 0', lineHeight:.88 }}>
            BONJOUR<br/>MAÏA.
          </h1>
          <p className="serif" style={{ fontSize:22, fontStyle:'italic', margin:0, color:'var(--ink-mute)', maxWidth:420, paddingBottom:12 }}>
            3 deadlines cette semaine, 2 réponses qui t’attendent au forum, 1 message d’une structure.
          </p>
        </div>
      </div>
    </section>

    {/* Stats strip */}
    <section style={{ background:'var(--accent)', borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:0, padding:0 }}>
        {[
          { v:'7', l:'Candidatures actives'},
          { v:'2', l:'Aides obtenues · 2026'},
          { v:'8 300 €', l:'Cumul de l’année'},
          { v:'412', l:'Followers · profil public'},
        ].map((s, i, a)=>(
          <div key={s.l} style={{
            padding:'24px 28px', borderRight: i<a.length-1?'1px solid var(--ink)':'0',
            display:'flex', flexDirection:'column', gap:6,
          }}>
            <div className="h-display" style={{ fontSize:64, lineHeight:.9 }}>{s.v}</div>
            <div className="mono" style={{ fontSize:11, letterSpacing:'.08em' }}>{s.l.toUpperCase()}</div>
          </div>
        ))}
      </div>
    </section>

    {/* Main grid */}
    <section className="container" style={{ paddingTop:32, paddingBottom:48 }}>
      <div style={{ display:'grid', gridTemplateColumns:'2fr 1fr', gap:32 }}>
        {/* LEFT */}
        <div>
          {/* Candidatures */}
          <SectionHeader index="A" title="Mes candidatures" action={<button className="btn btn-sm btn-ghost">Toutes →</button>}/>
          <div style={{ border:'1px solid var(--ink)', marginTop:16 }}>
            {[
              { o:'Résidence Manufacture', org:'Manufacture des Œillets', state:'En cours · brouillon', pct:65, deadline:'J-21', color:'var(--accent)' },
              { o:'Bourse SACEM création', org:'SACEM', state:'Soumise · en jury', pct:100, deadline:'Réponse mi-juin', color:'var(--accent-2)' },
              { o:'Aide DRAC IDF', org:'DRAC Île-de-France', state:'Acceptée ✓ 5 200 €', pct:100, deadline:'Convention signée', color:'var(--accent)' },
              { o:'Festival Lumineuses', org:'Festival Lumineuses', state:'Refusée — feedback dispo', pct:100, deadline:'Postuler à nouveau ?', color:'var(--bg-alt)' },
            ].map((r, i, a) => (
              <div key={i} style={{
                display:'grid', gridTemplateColumns:'1fr 200px 140px 120px',
                gap:0, padding:0,
                borderTop: i>0?'1px solid var(--line-soft)':0,
              }}>
                <div style={{ padding:'16px 18px' }}>
                  <div style={{ fontWeight:700, fontSize:14 }}>{r.o}</div>
                  <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)', marginTop:4 }}>{r.org}</div>
                </div>
                <div style={{ padding:'16px 18px', display:'flex', alignItems:'center', gap:8, borderLeft:'1px solid var(--line-soft)' }}>
                  <div style={{ flex:1, height:6, background:'var(--bg-alt)' }}>
                    <div style={{ width:`${r.pct}%`, height:'100%', background:r.color }}/>
                  </div>
                  <span className="mono" style={{ fontSize:11, fontWeight:700 }}>{r.pct}%</span>
                </div>
                <div style={{ padding:'16px 18px', borderLeft:'1px solid var(--line-soft)', fontSize:12 }}>{r.state}</div>
                <div style={{ padding:'16px 18px', borderLeft:'1px solid var(--line-soft)', fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>{r.deadline}</div>
              </div>
            ))}
          </div>

          {/* Agenda */}
          <SectionHeader index="B" title="Cette semaine" subtitle="agenda"/>
          <div style={{ marginTop:16, display:'grid', gridTemplateColumns:'repeat(7, 1fr)', gap:0, border:'1px solid var(--ink)' }}>
            {['Lun 19','Mar 20','Mer 21','Jeu 22','Ven 23','Sam 24','Dim 25'].map((d, i) => {
              const today = i === 2;
              const events = [
                [], [{ t:'Atelier dossier DRAC', tag:'EVENT' }],
                [{ t:'Live · session studio', tag:'LIVE', color:'var(--accent-2)' }, { t:'Rendu dossier Manufacture', tag:'⚠ DEADLINE', color:'var(--accent)' }],
                [],
                [{ t:'Rencontre mentor', tag:'BAZAART' }],
                [{ t:'Vernissage — La Pop', tag:'EVENT' }],
                [],
              ][i];
              return (
                <div key={d} style={{
                  padding:'12px 12px', minHeight:160, borderRight: i<6?'1px solid var(--line-soft)':'0',
                  background: today ? 'var(--bg-alt)' : 'transparent',
                }}>
                  <div className="mono" style={{ fontSize:10, letterSpacing:'.08em', color: today?'var(--ink)':'var(--ink-mute)', fontWeight: today?700:400 }}>{d.toUpperCase()}{today && ' ·'}</div>
                  <div style={{ display:'flex', flexDirection:'column', gap:6, marginTop:10 }}>
                    {events.map((e, j) => (
                      <div key={j} style={{ padding:'6px 8px', background: e.color || 'var(--ink)', color: e.color ? 'var(--ink)' : 'var(--accent)', fontSize:10.5, fontWeight:600, lineHeight:1.3 }}>
                        <div style={{ fontFamily:'var(--mono)', fontSize:9, opacity:.7 }}>{e.tag}</div>
                        <div>{e.t}</div>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>

          {/* Opportunités recommandées */}
          <SectionHeader index="C" title="Pour toi"
            subtitle="basé sur ton profil"
            action={<button className="btn btn-sm btn-ghost" onClick={()=>go('opportunities')}>Tout voir →</button>}
          />
          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:0, borderLeft:'1px solid var(--ink)', borderRight:'1px solid var(--ink)', borderBottom:'1px solid var(--ink)', marginTop:16 }}>
            {OPPORTUNITIES.slice(0, 2).map((o, i) => (
              <button key={o.id} onClick={()=>go('opportunity', o.id)} style={{
                padding:'20px', borderRight: i===0?'1px solid var(--ink)':'0',
                background:'transparent', textAlign:'left', cursor:'default', fontFamily:'inherit', color:'inherit',
                display:'flex', flexDirection:'column', gap:10,
              }}>
                <div style={{ display:'flex', justifyContent:'space-between' }}>
                  <span className="chip chip-accent">★ MATCH 94%</span>
                  <Countdown days={o.days} hours={o.hours} mins={o.mins}/>
                </div>
                <div className="h-display" style={{ fontSize:22, lineHeight:1 }}>{o.title}</div>
                <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>{o.org} · {o.amount}</div>
              </button>
            ))}
          </div>
        </div>

        {/* RIGHT */}
        <aside style={{ display:'flex', flexDirection:'column', gap:16 }}>
          {/* Profile completeness */}
          <div style={{ border:'1px solid var(--ink)', padding:18 }}>
            <Eyebrow>Profil public</Eyebrow>
            <div style={{ marginTop:12 }}>
              <div style={{ display:'flex', justifyContent:'space-between', marginBottom:6 }}>
                <span style={{ fontSize:12, fontWeight:600 }}>Complétude</span>
                <span className="mono" style={{ fontSize:11, fontWeight:700 }}>78%</span>
              </div>
              <div style={{ height:8, background:'var(--bg-alt)' }}>
                <div style={{ width:'78%', height:'100%', background:'var(--accent)' }}/>
              </div>
              <ul style={{ marginTop:14, padding:0, listStyle:'none', display:'flex', flexDirection:'column', gap:6 }}>
                {[['Bio courte','done'],['Portfolio (8 œuvres)','done'],['Vidéo de présentation','todo'],['Statement long','done'],['Lien Bandcamp / SoundCloud','todo']].map(([l, s]) => (
                  <li key={l} style={{ display:'flex', alignItems:'center', gap:8, fontSize:12.5 }}>
                    {s === 'done' ? <Icon name="check" size={14}/> : <Icon name="plus" size={14} style={{ color:'var(--accent-2)' }}/>}
                    <span style={{ opacity: s==='todo'?1:.7, fontWeight: s==='todo'?600:400 }}>{l}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>

          {/* Messages */}
          <div style={{ border:'1px solid var(--ink)' }}>
            <div style={{ padding:'14px 16px', borderBottom:'1px solid var(--ink)', display:'flex', justifyContent:'space-between' }}>
              <Eyebrow>Messages · 3 non lus</Eyebrow>
              <span className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>→</span>
            </div>
            {[
              { f:'Manufacture', t:'Bonjour Maïa, on a bien reçu votre…', u:true, c:'var(--accent)' },
              { f:'Inès K.', t:'Yo, je participe au live ce soir, tu ?', u:true, c:'#C6F24E' },
              { f:'Festival Lumineuses', t:'Vos retours sur l’édition 2025…', u:true, c:'var(--accent-2)' },
              { f:'Mentor — Antoine', t:'Bon courage pour le dossier !', u:false, c:'#B794F6' },
            ].map((m, i, a)=>(
              <div key={i} style={{ padding:'10px 16px', borderBottom: i<a.length-1?'1px solid var(--line-soft)':'0', display:'flex', gap:10, alignItems:'center', background: m.u?'var(--bg)':'var(--bg-alt)' }}>
                <Avatar initials={m.f.split(' ').map(w=>w[0]).join('').slice(0,2)} size={28} bg={m.c} fg="var(--ink)"/>
                <div style={{ flex:1, minWidth:0 }}>
                  <div style={{ fontSize:12.5, fontWeight: m.u?700:500 }}>{m.f}</div>
                  <div style={{ fontSize:11, color:'var(--ink-mute)', whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis' }}>{m.t}</div>
                </div>
                {m.u && <div style={{ width:6, height:6, borderRadius:'50%', background:'var(--accent-2)' }}/>}
              </div>
            ))}
          </div>

          {/* Earnings */}
          <div style={{ border:'1px solid var(--ink)', background:'var(--ink)', color:'var(--bg)', padding:18 }}>
            <Eyebrow dot={false}>Revenus BazaArt · 2026</Eyebrow>
            <div className="h-display" style={{ fontSize:56, color:'var(--accent)', marginTop:8 }}>8 300 €</div>
            <div className="mono" style={{ fontSize:11, letterSpacing:'.08em', opacity:.6, marginTop:6 }}>2 AIDES · 1 CACHET · 0% DE COMMISSION</div>
            {/* mini chart */}
            <svg viewBox="0 0 220 60" style={{ width:'100%', marginTop:14, display:'block' }}>
              <polyline points="0,50 30,40 60,42 90,28 120,30 150,18 180,16 220,8" fill="none" stroke="var(--accent)" strokeWidth="2"/>
              <polyline points="0,50 30,40 60,42 90,28 120,30 150,18 180,16 220,8 220,60 0,60" fill="var(--accent)" opacity=".2"/>
            </svg>
          </div>
        </aside>
      </div>
    </section>
  </div>
);

// ─── PUBLIC — profile / portfolio ───────────────────────────────────────────
const ArtistPublic = ({ go, style = 'editorial' }) => (
  <div>
    {style === 'editorial' && <ArtistPublicEditorial go={go}/>}
    {style === 'gallery' && <ArtistPublicGallery go={go}/>}
    {style === 'cv' && <ArtistPublicCV go={go}/>}
  </div>
);

const ArtistPublicEditorial = ({ go }) => (
  <div>
    {/* Massive name */}
    <section style={{ borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ paddingTop:32, paddingBottom:32 }}>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:32 }}>
          <div>
            <Eyebrow>Profil · public</Eyebrow>
            <h1 className="h-display" style={{ fontSize:'clamp(72px, 11vw, 152px)', margin:'8px 0 0', lineHeight:.85, letterSpacing:'-.02em' }}>
              MAÏA<br/>OLIVA
            </h1>
            <p className="serif" style={{ fontSize:22, fontStyle:'italic', marginTop:20, maxWidth:520 }}>
              Vidéaste & performeuse — entre archive familiale, mémoire collective et image en mouvement.
            </p>
            <div style={{ display:'flex', gap:10, marginTop:20, alignItems:'center' }}>
              <span className="chip"><Icon name="pin" size={11}/> Marseille</span>
              <span className="chip"><Icon name="user" size={11}/> 412 followers</span>
              <span className="chip chip-accent">★ Vérifiée</span>
              <button className="btn btn-sm btn-primary">+ Suivre</button>
              <button className="btn btn-sm"><Icon name="msg" size={11}/> Contacter</button>
            </div>
          </div>
          <Placeholder label="portrait artiste · 3:4" ratio="3/4"/>
        </div>
      </div>
    </section>

    {/* Practices strip */}
    <section style={{ background:'var(--ink)', color:'var(--bg)', borderBottom:'1px solid var(--ink)' }}>
      <div className="container" style={{ padding:'18px 32px', display:'flex', alignItems:'center', gap:14, fontFamily:'var(--mono)', fontSize:12, letterSpacing:'.08em' }}>
        <span style={{ color:'var(--accent)' }}>PRATIQUES</span>
        <span>·</span>
        {['VIDÉO','INSTALLATION','PERFORMANCE','ARCHIVE','ÉCRITURE'].map((d, i) => (
          <span key={d} style={{ color: i===0?'var(--accent)':'rgba(242,239,230,.7)' }}>{d}</span>
        ))}
        <span style={{ marginLeft:'auto', color:'rgba(242,239,230,.4)' }}>SUR BAZAART DEPUIS FÉVRIER 2025</span>
      </div>
    </section>

    {/* Portfolio */}
    <section className="container" style={{ paddingTop:32, paddingBottom:32 }}>
      <SectionHeader index="01" title="Œuvres" subtitle="14 pièces · 2018 — 2026"/>
      <div style={{ marginTop:16, display:'grid', gridTemplateColumns:'2fr 1fr 1fr', gap:0, border:'1px solid var(--ink)', borderBottom:0 }}>
        <Placeholder label="MARÉES · vidéo 24min · 2026" ratio="16/9" style={{ borderRight:'1px solid var(--ink)' }}/>
        <Placeholder label="LE NOM DE MA GRAND-MÈRE · installation" ratio="" style={{ borderRight:'1px solid var(--ink)' }}/>
        <Placeholder label="VENT DE TERRE · performance"/>
      </div>
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 2fr', gap:0, border:'1px solid var(--ink)' }}>
        <Placeholder label="MIRAGE · vidéo"  style={{ borderRight:'1px solid var(--ink)' }}/>
        <Placeholder label="LES POSSÉDÉES · série photo" style={{ borderRight:'1px solid var(--ink)' }}/>
        <Placeholder label="QUE RESTE-T-IL · installation sonore"/>
      </div>
    </section>

    {/* Bio + CV */}
    <section className="container" style={{ paddingTop:32, paddingBottom:48 }}>
      <div style={{ display:'grid', gridTemplateColumns:'1.4fr 1fr', gap:48 }}>
        <div>
          <SectionHeader index="02" title="Statement"/>
          <p className="serif" style={{ fontSize:21, lineHeight:1.5, marginTop:20 }}>
            Mon travail interroge ce qui survit. Pas le grand récit, mais la trace : un nom recopié, une cassette VHS oubliée,
            une chanson chantonnée par une voisine. Je filme ces survivances, je les rejoue, je les passe à des inconnus
            pour qu’ils en fassent autre chose.
          </p>
          <p style={{ fontSize:15, lineHeight:1.6, marginTop:18, color:'var(--ink-mute)' }}>
            Né·e en 1992, formée à l’ENSP Arles puis au Fresnoy. Expose depuis 2018 (Le BAL, Triennale Milano, Festival Côté Court).
            Vit et travaille à Marseille où je co-fonde l’atelier collectif <em>Les Mistraux</em>.
          </p>
        </div>
        <div>
          <SectionHeader index="03" title="CV public"/>
          <div style={{ marginTop:20, fontFamily:'var(--mono)', fontSize:12 }}>
            {[
              ['2026','Résidence La Manufacture (en cours)'],
              ['2025','Le BAL — exposition collective'],
              ['2024','Aide à la création DRAC PACA'],
              ['2023','Festival Côté Court — Pantin'],
              ['2022','Le Fresnoy — diplôme'],
              ['2020','ENSP Arles — diplôme'],
              ['',''],
              ['CONTACTS', ''],
              ['IG','@maia.oliva'],
              ['SITE','maia-oliva.fr'],
              ['MAIL','contact@maia-oliva.fr'],
            ].map(([y, t], i)=>(
              <div key={i} style={{ display:'grid', gridTemplateColumns:'80px 1fr', gap:12, padding:'8px 0', borderBottom:'1px dashed var(--line-soft)', alignItems:'baseline' }}>
                <span style={{ color:'var(--ink-mute)', letterSpacing:'.05em' }}>{y}</span>
                <span style={{ fontFamily:'var(--body)', fontWeight: y && t ? 500 : 700 }}>{t}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  </div>
);

const ArtistPublicGallery = ({ go }) => (
  <section className="container" style={{ paddingTop:40, paddingBottom:48 }}>
    <h1 className="h-display" style={{ fontSize:96, margin:0 }}>MAÏA OLIVA</h1>
    <div style={{ display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:12, marginTop:32 }}>
      {Array.from({length:12}).map((_,i)=><Placeholder key={i} label={`Œuvre ${i+1}`} ratio={['3/4','1','16/9','4/3'][i%4]}/>)}
    </div>
  </section>
);

const ArtistPublicCV = ({ go }) => (
  <section className="container" style={{ paddingTop:40, paddingBottom:48, maxWidth:760, fontFamily:'var(--mono)', fontSize:13 }}>
    <h1 className="h-display" style={{ fontSize:64, margin:0, fontFamily:'var(--display)' }}>MAÏA OLIVA · CV</h1>
    <div style={{ marginTop:24 }}>VIDÉO · INSTALLATION · PERFORMANCE</div>
    <hr/>
    <div>2026 — Résidence La Manufacture, Ivry</div>
    <div>2025 — Le BAL · Paris (groupe)</div>
    <div>2024 — Aide DRAC PACA</div>
  </section>
);

// ─── DASHBOARD STRUCTURE ─────────────────────────────────────────────────────
const ScreenStructureDashboard = ({ go }) => {
  const [view, setView] = React.useState('overview'); // overview | publish | published
  return (
    <div className="screen" data-screen-label="05 Dashboard structure">
      <section style={{ background:'var(--bg-alt)', borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ paddingTop:32, paddingBottom:24 }}>
          <div style={{ display:'flex', alignItems:'flex-end', justifyContent:'space-between' }}>
            <div>
              <Eyebrow>Structure · vérifiée</Eyebrow>
              <h1 className="h-display" style={{ fontSize:'clamp(56px, 8vw, 108px)', margin:'8px 0 0', lineHeight:.88 }}>
                MANUFACTURE<br/>DES ŒILLETS
              </h1>
              <div className="mono" style={{ fontSize:12, letterSpacing:'.08em', color:'var(--ink-mute)', marginTop:12 }}>
                IVRY-SUR-SEINE · LIEU DE FABRIQUE · MEMBRE DEPUIS 2024
              </div>
            </div>
            <button className="btn btn-primary" onClick={()=>setView('publish')}>
              <Icon name="plus" size={14}/> Publier une opportunité
            </button>
          </div>
          {/* Tabs */}
          <div style={{ display:'flex', gap:0, marginTop:24, borderBottom:'1px solid var(--ink)' }}>
            {[['overview','Vue d’ensemble'],['published','Publications (8)'],['publish','Créer'],['candidates','Candidatures (47)'],['team','Équipe']].map(([k,l],i)=>(
              <button key={k} onClick={()=>setView(k)} style={{
                padding:'12px 18px',
                background: view===k?'var(--ink)':'transparent', color: view===k?'var(--accent)':'var(--ink)',
                border:0, font:'700 12px var(--body)', letterSpacing:'.04em', textTransform:'uppercase', cursor:'default',
                borderRight: i<4?'1px solid var(--line-soft)':'0',
              }}>{l}</button>
            ))}
          </div>
        </div>
      </section>

      {view === 'overview' && <StructureOverview go={go} setView={setView}/>}
      {view === 'publish' && <StructurePublish go={go}/>}
      {view === 'published' && <StructurePublished go={go}/>}
      {view === 'candidates' && <StructureCandidates go={go}/>}
      {view === 'team' && <div className="container" style={{ paddingTop:32, paddingBottom:64 }}><Placeholder label="Équipe — 6 membres" ratio="3/1"/></div>}

      <Footer go={go}/>
    </div>
  );
};

const StructureOverview = ({ go, setView }) => (
  <section className="container" style={{ paddingTop:32, paddingBottom:48 }}>
    {/* KPIs */}
    <div style={{ display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:0, border:'1px solid var(--ink)' }}>
      {[
        ['8','Opportunités publiées'], ['47','Candidatures reçues'],
        ['12 400','Vues totales'], ['3','En attente de validation'],
      ].map((s, i, a)=>(
        <div key={s[1]} style={{ padding:'24px 22px', borderRight: i<a.length-1?'1px solid var(--ink)':'0' }}>
          <div className="h-display" style={{ fontSize:56, lineHeight:.9 }}>{s[0]}</div>
          <div className="mono" style={{ fontSize:11, letterSpacing:'.08em', color:'var(--ink-mute)', marginTop:8 }}>{s[1].toUpperCase()}</div>
        </div>
      ))}
    </div>

    <div style={{ display:'grid', gridTemplateColumns:'1.5fr 1fr', gap:32, marginTop:32 }}>
      <div>
        <SectionHeader title="Publications récentes" action={<button className="btn btn-sm btn-ghost" onClick={()=>setView('published')}>Toutes →</button>}/>
        <div style={{ marginTop:16, border:'1px solid var(--ink)' }}>
          {[
            { t:'Résidence Manufacture — atelier 3 mois', s:'EN LIGNE', cand:18, color:'var(--accent)' },
            { t:'Studio son disponible — 6 mois', s:'EN LIGNE', cand:11, color:'var(--accent)' },
            { t:'Performance · plateau ouvert', s:'EN ATTENTE', cand:0, color:'var(--accent-2)' },
            { t:'Bourse production vidéo 2025', s:'CLÔTURÉE', cand:42, color:'var(--bg-alt)' },
          ].map((r, i, a) => (
            <div key={i} style={{ display:'grid', gridTemplateColumns:'1fr 130px 100px 80px', borderTop: i>0?'1px solid var(--line-soft)':0 }}>
              <div style={{ padding:'14px 18px', fontWeight:600, fontSize:14 }}>{r.t}</div>
              <div style={{ padding:'14px 18px' }}><span className="chip" style={{ background:r.color, fontSize:10, padding:'3px 8px' }}>{r.s}</span></div>
              <div style={{ padding:'14px 18px', fontFamily:'var(--mono)', fontSize:12, color:'var(--ink-mute)' }}>{r.cand} cand.</div>
              <div style={{ padding:'14px 18px', textAlign:'right' }}><Icon name="dot3" size={16}/></div>
            </div>
          ))}
        </div>
      </div>
      <aside>
        <div style={{ border:'1px solid var(--ink)', padding:18, background:'var(--bg)' }}>
          <Eyebrow>Validation en cours</Eyebrow>
          <p style={{ margin:'10px 0 0', fontSize:13, color:'var(--ink-mute)' }}>
            « Performance · plateau ouvert » est en attente de validation par un admin BazaArt.
            Délai moyen : <strong style={{ color:'var(--ink)' }}>4h ouvrées</strong>.
          </p>
          <div style={{ marginTop:14, height:6, background:'var(--bg-alt)' }}>
            <div style={{ width:'40%', height:'100%', background:'var(--accent-2)' }}/>
          </div>
          <button className="btn btn-sm" style={{ marginTop:14 }}>Voir le brouillon</button>
        </div>
        <div style={{ marginTop:16, border:'1px solid var(--ink)', padding:18 }}>
          <Eyebrow>Mémo BazaArt</Eyebrow>
          <p style={{ margin:'10px 0 0', fontSize:13, color:'var(--ink-mute)' }}>
            Les opportunités gratuites pour les artistes restent <strong style={{ color:'var(--ink)' }}>gratuites pour vous</strong>.
            Vous ne payez que si vous diffusez une opportunité payante (concours avec frais d’inscription).
          </p>
        </div>
      </aside>
    </div>
  </section>
);

const StructurePublish = ({ go }) => (
  <section className="container" style={{ paddingTop:32, paddingBottom:64 }}>
    <div style={{ display:'grid', gridTemplateColumns:'2fr 1fr', gap:32 }}>
      <div style={{ border:'1px solid var(--ink)', padding:'32px' }}>
        <Eyebrow>Étape 1 / 3 — informations</Eyebrow>
        <h2 className="h-display" style={{ fontSize:48, margin:'12px 0 24px' }}>Nouvelle opportunité</h2>
        <div style={{ display:'flex', flexDirection:'column', gap:18 }}>
          <div><label className="label">Titre</label><input className="input" defaultValue="Résidence Manufacture — atelier 3 mois"/></div>
          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:14 }}>
            <div><label className="label">Type</label><select className="input"><option>Résidence</option><option>Appel à projet</option><option>Aide financière</option><option>Mentorat</option><option>Formation</option></select></div>
            <div><label className="label">Niveau</label><select className="input"><option>Tous niveaux</option><option>Émergent</option><option>Confirmé</option></select></div>
            <div><label className="label">Disciplines</label><input className="input" defaultValue="Arts visuels · Numérique"/></div>
          </div>
          <div style={{ display:'grid', gridTemplateColumns:'2fr 1fr 1fr', gap:14 }}>
            <div><label className="label">Lieu</label><input className="input" defaultValue="Ivry-sur-Seine — Île-de-France"/></div>
            <div><label className="label">Montant / dotation</label><input className="input" defaultValue="4 500 €"/></div>
            <div><label className="label">Deadline</label><input className="input" defaultValue="12 juin 2026"/></div>
          </div>
          <div>
            <label className="label">Description</label>
            <div className="input" style={{ minHeight:140, color:'var(--ink-mute)' }}>
              Atelier individuel de 90m², bourse de production et restitution publique en septembre. Le candidat doit présenter…
            </div>
          </div>
          <div>
            <label className="label">Pièces jointes</label>
            <div style={{ border:'1px dashed var(--ink)', padding:'24px', textAlign:'center', color:'var(--ink-mute)' }}>
              <Icon name="upload" size={20}/>
              <div style={{ marginTop:8, fontSize:13 }}>Glisse ton dossier d’appel ici (PDF, max 8 Mo)</div>
            </div>
          </div>
        </div>
        <div style={{ marginTop:24, display:'flex', justifyContent:'space-between' }}>
          <button className="btn btn-ghost">Sauvegarder le brouillon</button>
          <div style={{ display:'flex', gap:10 }}>
            <button className="btn">Étape suivante</button>
            <button className="btn btn-primary">Envoyer pour validation <Icon name="arrow" size={13}/></button>
          </div>
        </div>
      </div>
      <aside>
        <div style={{ background:'var(--ink)', color:'var(--bg)', padding:20 }}>
          <Eyebrow dot={false}>Aperçu</Eyebrow>
          <div style={{ background:'var(--bg)', color:'var(--ink)', padding:18, marginTop:14, border:'1px solid var(--bg)' }}>
            <div style={{ display:'flex', justifyContent:'space-between' }}>
              <span className="chip chip-accent">Résidence</span>
              <Countdown days={21} hours={6} mins={14}/>
            </div>
            <h3 className="h-display" style={{ fontSize:20, margin:'12px 0 6px' }}>Résidence Manufacture — atelier 3 mois</h3>
            <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>4 500 € · IVRY-SUR-SEINE</div>
          </div>
        </div>
        <div style={{ marginTop:16, border:'1px solid var(--ink)', padding:18 }}>
          <Eyebrow>Validation BazaArt</Eyebrow>
          <ul style={{ marginTop:12, padding:0, listStyle:'none', display:'flex', flexDirection:'column', gap:10, fontSize:13 }}>
            <li style={{ display:'flex', gap:10 }}><Icon name="check" size={16}/> Bonne foi (pas un appel pyramidal)</li>
            <li style={{ display:'flex', gap:10 }}><Icon name="check" size={16}/> Rémunération transparente</li>
            <li style={{ display:'flex', gap:10 }}><Icon name="check" size={16}/> Pas plus de 30€ de frais d’inscription</li>
            <li style={{ display:'flex', gap:10 }}><Icon name="check" size={16}/> Respect des droits d’auteur</li>
          </ul>
        </div>
      </aside>
    </div>
  </section>
);

const StructurePublished = ({ go }) => (
  <section className="container" style={{ paddingTop:32, paddingBottom:64 }}>
    <Placeholder label="LISTE COMPLÈTE DES 8 PUBLICATIONS · idem que dashboard admin" ratio="3/1"/>
  </section>
);
const StructureCandidates = ({ go }) => (
  <section className="container" style={{ paddingTop:32, paddingBottom:64 }}>
    <SectionHeader title="47 candidatures reçues"/>
    <div style={{ marginTop:16, display:'grid', gridTemplateColumns:'repeat(4, 1fr)', gap:14 }}>
      {ARTISTS.concat(ARTISTS.slice(0,3)).map((a, i) => (
        <div key={i} style={{ border:'1px solid var(--ink)', padding:16 }}>
          <div style={{ display:'flex', gap:10, alignItems:'center' }}>
            <Avatar initials={a.initials} bg={a.color} fg="var(--ink)"/>
            <div>
              <div style={{ fontWeight:700, fontSize:14 }}>{a.name}</div>
              <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>{a.city.toUpperCase()}</div>
            </div>
          </div>
          <div className="mono" style={{ fontSize:11, marginTop:14, color:'var(--ink-mute)' }}>RÉSIDENCE MANUFACTURE</div>
          <div style={{ marginTop:6, fontSize:13 }}>Score auto : <strong>{82 - i*4}%</strong></div>
          <div style={{ marginTop:12, display:'flex', gap:6 }}>
            <button className="btn btn-sm" style={{ fontSize:10, padding:'5px 8px' }}>Lire</button>
            <button className="btn btn-sm btn-primary" style={{ fontSize:10, padding:'5px 8px' }}>Présélectionner</button>
          </div>
        </div>
      ))}
    </div>
  </section>
);

Object.assign(window, { ScreenArtistDashboard, ScreenStructureDashboard });
