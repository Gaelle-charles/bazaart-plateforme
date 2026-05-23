// src/screens-forum.jsx — Forum (hybride : posts + lives + threads)

const ScreenForum = ({ go, layout = 'hybrid' }) => {
  const [tab, setTab] = React.useState('all');
  return (
    <div className="screen" data-screen-label="03 Forum">
      {/* Header */}
      <section style={{ borderBottom:'1px solid var(--ink)' }}>
        <div className="container" style={{ paddingTop:32, paddingBottom:20 }}>
          <div style={{ display:'flex', alignItems:'flex-end', justifyContent:'space-between', gap:24 }}>
            <div>
              <Eyebrow>Forum + lives · 1 824 membres</Eyebrow>
              <h1 className="h-display" style={{ fontSize:'clamp(64px, 9vw, 132px)', margin:'8px 0 0', lineHeight:.88 }}>
                ON SE PARLE.
              </h1>
            </div>
            <div style={{ display:'flex', gap:10 }}>
              <button className="btn btn-ghost btn-sm"><Icon name="mic" size={14}/> Programmer un live</button>
              <button className="btn btn-primary"><Icon name="plus" size={14}/> Nouveau post</button>
            </div>
          </div>
        </div>
      </section>

      {/* Tab bar */}
      <div style={{ borderBottom:'1px solid var(--ink)', background:'var(--bg-alt)' }}>
        <div className="container" style={{ display:'flex', gap:0, alignItems:'stretch', padding:0 }}>
          {[
            ['all','Tout',null], ['hot','Hot','flame'], ['questions','Questions','msg'],
            ['lives','Lives en cours','live'], ['scheduled','Lives programmés','cal'],
            ['threads','Discussions','book']
          ].map(([k,l,ic],i,a)=>(
            <button key={k} onClick={()=>setTab(k)} style={{
              padding:'14px 18px', display:'inline-flex', alignItems:'center', gap:8,
              background: tab===k?'var(--ink)':'transparent', color: tab===k?'var(--accent)':'var(--ink)',
              border:0, borderRight: i<a.length-1?'1px solid var(--line-soft)':'0',
              font:'700 12px var(--body)', letterSpacing:'.04em', textTransform:'uppercase', cursor:'default',
            }}>
              {ic && <Icon name={ic} size={13}/>} {l}
              {k==='lives' && <span style={{ marginLeft:6, padding:'2px 6px', background:'var(--accent-2)', color:'var(--ink)', fontSize:9, fontWeight:700 }}>3</span>}
            </button>
          ))}
          <div style={{ marginLeft:'auto', display:'flex', alignItems:'center', gap:10, padding:'0 18px' }}>
            <span className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>TRIER :</span>
            <span className="mono" style={{ fontSize:11, fontWeight:700 }}>Récent ▾</span>
          </div>
        </div>
      </div>

      <section className="container" style={{ paddingTop:24, paddingBottom:64 }}>
        <div style={{ display:'grid', gridTemplateColumns:'.85fr 2.2fr .95fr', gap:24 }}>
          {/* LEFT sidebar — categories */}
          <ForumLeftRail go={go}/>

          {/* MIDDLE — feed */}
          <ForumFeed go={go}/>

          {/* RIGHT sidebar — lives + leaderboard */}
          <ForumRightRail go={go}/>
        </div>
      </section>

      <Footer go={go}/>
    </div>
  );
};

// ─── LEFT RAIL ──────────────────────────────────────────────────────────────
const ForumLeftRail = ({ go }) => (
  <aside style={{ position:'sticky', top:80, alignSelf:'flex-start' }}>
    <div style={{ border:'1px solid var(--ink)' }}>
      <div style={{ padding:'14px 16px', borderBottom:'1px solid var(--ink)', background:'var(--ink)', color:'var(--accent)' }}>
        <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', fontWeight:700 }}>CATÉGORIES</div>
      </div>
      {[
        ['Statut & droits', 124, 'shield'],
        ['Production', 88, 'sparkle'],
        ['Résidences', 67, 'home'],
        ['Local · Paris', 211, 'pin'],
        ['Local · Lyon', 92, 'pin'],
        ['Local · Marseille', 78, 'pin'],
        ['Musique', 156, 'music'],
        ['Arts visuels', 203, 'pal'],
        ['Cinéma & vidéo', 91, 'cam'],
        ['Écriture', 64, 'pen'],
      ].map(([n, c, ic], i, a) => (
        <button key={n} style={{
          display:'flex', alignItems:'center', justifyContent:'space-between',
          width:'100%', padding:'10px 16px',
          background:'transparent', border:0, borderBottom: i<a.length-1?'1px solid var(--line-soft)':'0',
          cursor:'default', fontFamily:'inherit', color:'inherit', textAlign:'left',
          fontSize:13,
        }}
          onMouseEnter={e=>e.currentTarget.style.background='var(--bg-alt)'}
          onMouseLeave={e=>e.currentTarget.style.background='transparent'}
        >
          <span style={{ display:'flex', alignItems:'center', gap:8 }}>
            <Icon name={ic} size={13}/> {n}
          </span>
          <span className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>{c}</span>
        </button>
      ))}
    </div>

    {/* Top contributors */}
    <div style={{ marginTop:16, border:'1px solid var(--ink)' }}>
      <div style={{ padding:'14px 16px', borderBottom:'1px solid var(--ink)' }}>
        <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', fontWeight:700 }}>TOP CONTRIBUTEURS · MAI</div>
      </div>
      {ARTISTS.slice(0, 4).map((a, i) => (
        <div key={a.id} style={{ display:'flex', alignItems:'center', gap:10, padding:'10px 16px', borderBottom: i<3?'1px solid var(--line-soft)':'0' }}>
          <div className="h-display" style={{ fontSize:22, color:'var(--ink-mute)', width:24 }}>{i+1}</div>
          <Avatar initials={a.initials} size={28} bg={a.color} fg="var(--ink)"/>
          <div style={{ flex:1, minWidth:0 }}>
            <div style={{ fontSize:12.5, fontWeight:600 }}>{a.name}</div>
            <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>{(a.followers + i*150)} pts</div>
          </div>
        </div>
      ))}
    </div>
  </aside>
);

// ─── FEED ────────────────────────────────────────────────────────────────────
const ForumFeed = ({ go }) => (
  <div>
    {/* Composer */}
    <div style={{ background:'var(--bg-alt)', border:'1px solid var(--ink)', padding:'14px 16px', marginBottom:16, display:'flex', alignItems:'center', gap:14 }}>
      <Avatar initials="MO" size={36} bg="var(--accent)" fg="var(--ink)"/>
      <div style={{ flex:1, color:'var(--ink-mute)' }}>Une question, un appel, un partage…</div>
      <div style={{ display:'flex', gap:6 }}>
        <button className="btn btn-sm btn-ghost"><Icon name="msg" size={12}/> Post</button>
        <button className="btn btn-sm btn-ghost"><Icon name="mic" size={12}/> Live</button>
        <button className="btn btn-sm btn-ghost"><Icon name="cam" size={12}/> Vidéo</button>
      </div>
    </div>

    {/* Live banner — currently live */}
    <LivesStrip go={go}/>

    {/* Posts */}
    <div style={{ marginTop:16, display:'flex', flexDirection:'column', gap:0 }}>
      {POSTS.filter(p=>p.live!=='on').map((p, i) => (
        <PostCard key={p.id} p={p} i={i} go={go}/>
      ))}
    </div>
  </div>
);

// ─── Lives strip ────────────────────────────────────────────────────────────
const LivesStrip = ({ go }) => {
  const lives = POSTS.filter(p => p.live === 'on');
  const soon = POSTS.filter(p => p.live === 'soon');
  return (
    <div style={{ border:'1px solid var(--ink)' }}>
      <div style={{ padding:'10px 14px', background:'var(--ink)', color:'var(--bg)', display:'flex', justifyContent:'space-between', alignItems:'center' }}>
        <div style={{ display:'flex', alignItems:'center', gap:10 }}>
          <LiveDot/>
          <span className="mono" style={{ fontSize:11, letterSpacing:'.1em', fontWeight:700, color:'var(--accent-2)' }}>EN DIRECT MAINTENANT</span>
        </div>
        <button onClick={()=>go('live', 'p4')} style={{ background:'transparent', color:'var(--accent)', border:0, fontFamily:'var(--mono)', fontSize:11, cursor:'default', textTransform:'uppercase' }}>
          Tous les lives →
        </button>
      </div>
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:0 }}>
        {lives.map((p, i) => {
          const a = ARTISTS.find(a => a.initials === p.author);
          return (
            <button key={p.id} onClick={()=>go('live', p.id)} style={{
              padding:0, background:'transparent', border:0, borderRight: i===0?'1px solid var(--ink)':'0',
              cursor:'default', textAlign:'left', fontFamily:'inherit', color:'inherit',
            }}>
              <div style={{ aspectRatio:'16/9', background:'#0E0E0E', position:'relative', borderBottom:'1px solid var(--ink)' }}>
                <svg viewBox="0 0 400 220" preserveAspectRatio="none" style={{ width:'100%', height:'100%' }}>
                  {Array.from({length:50}).map((_,k)=>{
                    const h = 40 + Math.abs(Math.sin(k*.7+i)*70) + Math.abs(Math.cos(k*.3)*30);
                    return <rect key={k} x={k*8+2} y={110-h/2} width="5" height={h} fill="var(--accent)" opacity={.4 + (k%5)*.1}/>;
                  })}
                </svg>
                <span style={{ position:'absolute', top:10, left:10, padding:'4px 8px', background:'var(--accent-2)', color:'var(--ink)', fontFamily:'var(--mono)', fontWeight:700, fontSize:10, letterSpacing:'.1em' }}>● EN DIRECT</span>
                <span style={{ position:'absolute', top:10, right:10, padding:'4px 8px', background:'rgba(0,0,0,.7)', color:'var(--bg)', fontFamily:'var(--mono)', fontSize:10, letterSpacing:'.05em' }}>👁 {p.viewers}</span>
              </div>
              <div style={{ padding:'12px 14px', display:'flex', gap:10 }}>
                <Avatar initials={a?.initials || p.author} size={32} bg={a?.color || 'var(--accent)'} fg="var(--ink)"/>
                <div style={{ flex:1, minWidth:0 }}>
                  <div style={{ fontWeight:700, fontSize:13.5 }}>{p.title}</div>
                  <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:4 }}>{a?.name || p.author} · {p.tag.toUpperCase()}</div>
                </div>
              </div>
            </button>
          );
        })}
      </div>
      <div style={{ padding:'10px 14px', background:'var(--bg-alt)', borderTop:'1px solid var(--ink)', display:'flex', alignItems:'center', gap:14 }}>
        <span className="mono" style={{ fontSize:10, letterSpacing:'.1em', color:'var(--ink-mute)' }}>À VENIR :</span>
        {soon.map(p => {
          const a = ARTISTS.find(a => a.initials === p.author);
          return (
            <div key={p.id} style={{ display:'flex', alignItems:'center', gap:8 }}>
              <Avatar initials={p.author} size={22} bg={a?.color || 'var(--accent)'} fg="var(--ink)"/>
              <span style={{ fontSize:12 }}><strong>{p.title.replace('Live : ','')}</strong> <span style={{ color:'var(--ink-mute)' }}>· {p.time}</span></span>
            </div>
          );
        })}
      </div>
    </div>
  );
};

// ─── Post card ──────────────────────────────────────────────────────────────
const PostCard = ({ p, i, go }) => {
  const a = ARTISTS.find(a => a.initials === p.author);
  return (
    <button onClick={()=>go('thread', p.id)} style={{
      display:'grid', gridTemplateColumns:'56px 1fr', gap:0,
      background: 'var(--bg)', border:'1px solid var(--ink)', borderTop: i>0?'0':'1px solid var(--ink)',
      cursor:'default', fontFamily:'inherit', color:'inherit', textAlign:'left', padding:0,
    }}>
      {/* Vote rail */}
      <div style={{ background:'var(--bg-alt)', borderRight:'1px solid var(--line-soft)', display:'flex', flexDirection:'column', alignItems:'center', padding:'14px 0', gap:6 }}>
        <Icon name="arrowUp" size={18}/>
        <div className="h-display" style={{ fontSize:18 }}>{p.votes}</div>
        <Icon name="arrowUp" size={18} style={{ transform:'rotate(180deg)', opacity:.3 }}/>
      </div>
      <div style={{ padding:'14px 18px' }}>
        <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:8 }}>
          <Avatar initials={p.author} size={24} bg={a?.color || 'var(--accent)'} fg="var(--ink)"/>
          <span style={{ fontSize:13, fontWeight:600 }}>{a?.name || p.author}</span>
          <span className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>· {p.time}</span>
          <span className="chip" style={{ fontSize:9, padding:'2px 6px', marginLeft:6 }}>{p.tag}</span>
          {p.hot && <span className="chip chip-warn" style={{ fontSize:9, padding:'2px 6px' }}><Icon name="flame" size={9}/> HOT</span>}
          {p.kind === 'question' && <span className="chip" style={{ fontSize:9, padding:'2px 6px', background:'var(--accent)' }}>? Question</span>}
        </div>
        <div className="h-display" style={{ fontSize:22, lineHeight:1.1, marginBottom:6 }}>{p.title}</div>
        <p style={{ margin:0, fontSize:13.5, color:'var(--ink-mute)' }}>{p.body}</p>
        <div style={{ marginTop:12, display:'flex', gap:18, alignItems:'center', fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>
          <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="msg" size={12}/> {p.replies} réponses</span>
          <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="bookmark" size={12}/> sauver</span>
          <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="eye" size={12}/> {p.votes * 8} vues</span>
        </div>
      </div>
    </button>
  );
};

// ─── RIGHT RAIL ──────────────────────────────────────────────────────────────
const ForumRightRail = ({ go }) => (
  <aside style={{ position:'sticky', top:80, alignSelf:'flex-start' }}>
    <div style={{ background:'var(--accent)', border:'1px solid var(--ink)', padding:16 }}>
      <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', fontWeight:700 }}>RÈGLE DE BASE</div>
      <p className="serif" style={{ fontSize:18, margin:'10px 0 0', lineHeight:1.3 }}>
        Pas d’AMA déguisée en pub. Pas de spam d’appels à projet payants. On reste entre nous.
      </p>
    </div>

    {/* Trending */}
    <div style={{ marginTop:16, border:'1px solid var(--ink)' }}>
      <div style={{ padding:'14px 16px', borderBottom:'1px solid var(--ink)', display:'flex', alignItems:'center', gap:8 }}>
        <Icon name="flame" size={14}/> <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', fontWeight:700 }}>HOT DU JOUR</div>
      </div>
      {POSTS.filter(p=>p.hot).map((p, i, a) => (
        <button key={p.id} onClick={()=>go('thread', p.id)} style={{
          width:'100%', textAlign:'left', cursor:'default', fontFamily:'inherit', color:'inherit',
          background:'transparent', border:0, borderBottom: i<a.length-1?'1px solid var(--line-soft)':'0',
          padding:'12px 16px',
        }}>
          <div style={{ fontSize:13, fontWeight:600, lineHeight:1.3 }}>{p.title}</div>
          <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:6 }}>{p.replies} réponses · {p.votes} votes</div>
        </button>
      ))}
    </div>

    {/* Scheduled lives */}
    <div style={{ marginTop:16, border:'1px solid var(--ink)' }}>
      <div style={{ padding:'14px 16px', borderBottom:'1px solid var(--ink)', display:'flex', alignItems:'center', gap:8 }}>
        <Icon name="cal" size={14}/> <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', fontWeight:700 }}>LIVES À VENIR</div>
      </div>
      {[
        { d:'21 MAI · 18h', t:'Atelier dossier DRAC', a:'IK' },
        { d:'22 MAI · 14h', t:'Q/R intermittence 2026', a:'AB' },
        { d:'24 MAI · 20h', t:'Live set ambient — guest', a:'ST' },
      ].map((l, i, a)=>(
        <div key={i} style={{ padding:'12px 16px', borderBottom: i<a.length-1?'1px solid var(--line-soft)':'0', display:'flex', gap:10 }}>
          <div style={{ width:46, textAlign:'center', borderRight:'1px solid var(--line-soft)', paddingRight:8 }}>
            <div className="h-display" style={{ fontSize:18, lineHeight:1 }}>{l.d.split(' · ')[0].replace(' MAI','')}</div>
            <div className="mono" style={{ fontSize:8, letterSpacing:'.08em', color:'var(--ink-mute)', marginTop:2 }}>MAI</div>
          </div>
          <div style={{ flex:1 }}>
            <div style={{ fontWeight:600, fontSize:13 }}>{l.t}</div>
            <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:3 }}>{l.d.split(' · ')[1]}</div>
          </div>
          <button className="btn btn-sm" style={{ padding:'4px 8px', fontSize:10 }}>RDV</button>
        </div>
      ))}
    </div>
  </aside>
);

// ─── THREAD VIEW ────────────────────────────────────────────────────────────
const ScreenThread = ({ id, go }) => {
  const p = POSTS.find(x => x.id === id) || POSTS[0];
  const a = ARTISTS.find(x => x.initials === p.author);
  return (
    <div className="screen" data-screen-label="03b Forum — fil">
      <section style={{ borderBottom:'1px solid var(--ink)', background:'var(--bg-alt)' }}>
        <div className="container" style={{ paddingTop:24, paddingBottom:24 }}>
          <button className="btn btn-sm btn-ghost" onClick={()=>go('forum')}>← Forum</button>
          <div style={{ marginTop:16 }}>
            <div style={{ display:'flex', gap:8, marginBottom:14, alignItems:'center' }}>
              <span className="chip" style={{ background:'var(--accent)' }}>? {p.tag}</span>
              {p.hot && <span className="chip chip-warn"><Icon name="flame" size={11}/> HOT</span>}
              <span className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>· {p.time} · {p.votes * 8} vues</span>
            </div>
            <h1 className="h-display" style={{ fontSize:'clamp(36px, 5vw, 64px)', margin:0, lineHeight:1.05 }}>{p.title}</h1>
          </div>
        </div>
      </section>

      <section className="container" style={{ paddingTop:32, paddingBottom:64 }}>
        <div style={{ display:'grid', gridTemplateColumns:'2fr .9fr', gap:32 }}>
          <div>
            {/* OP */}
            <div style={{ display:'grid', gridTemplateColumns:'48px 1fr', gap:0, border:'1px solid var(--ink)' }}>
              <div style={{ background:'var(--bg-alt)', borderRight:'1px solid var(--line-soft)', display:'flex', flexDirection:'column', alignItems:'center', padding:'14px 0', gap:6 }}>
                <Icon name="arrowUp" size={16}/>
                <div className="h-display" style={{ fontSize:16 }}>{p.votes}</div>
                <Icon name="arrowUp" size={16} style={{ transform:'rotate(180deg)', opacity:.3 }}/>
              </div>
              <div style={{ padding:'18px 22px' }}>
                <div style={{ display:'flex', alignItems:'center', gap:10, marginBottom:14 }}>
                  <Avatar initials={p.author} size={36} bg={a?.color || 'var(--accent)'} fg="var(--ink)"/>
                  <div>
                    <div style={{ fontSize:14, fontWeight:700 }}>{a?.name || p.author}</div>
                    <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>{a?.practice || 'Artiste BazaArt'} · {a?.city || 'France'}</div>
                  </div>
                </div>
                <p style={{ fontSize:16, lineHeight:1.55, margin:0 }}>{p.body}</p>
                <p style={{ fontSize:16, lineHeight:1.55, marginTop:14 }}>
                  Pour préciser : j’ai un statut d’auteur depuis 2019, je touche aussi des cachets ponctuels en intermittent.
                  La caisse me dit qu’il faut tout déclarer côté URSSAF artistes-auteurs maintenant — mais mon expert-comptable n’est pas d’accord.
                </p>
                <div style={{ marginTop:18, display:'flex', gap:14, fontFamily:'var(--mono)', fontSize:11, color:'var(--ink-mute)' }}>
                  <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="bookmark" size={12}/> SAUVER</span>
                  <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}>↗ PARTAGER</span>
                  <span style={{ display:'inline-flex', alignItems:'center', gap:5 }}><Icon name="flame" size={12}/> SIGNALER</span>
                </div>
              </div>
            </div>

            {/* Reply composer */}
            <div style={{ marginTop:16, border:'1px solid var(--ink)' }}>
              <div style={{ padding:'14px 18px' }}>
                <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', color:'var(--ink-mute)', marginBottom:10 }}>RÉPONDRE EN TANT QUE MO (MAÏA OLIVA)</div>
                <div style={{ background:'var(--bg-alt)', minHeight:90, padding:14, color:'var(--ink-mute)', fontSize:14, border:'1px solid var(--line-soft)' }}>Tape ta réponse… markdown supporté.</div>
                <div style={{ display:'flex', justifyContent:'space-between', marginTop:12, alignItems:'center' }}>
                  <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>SOIS GENTIL, SOIS UTILE.</div>
                  <button className="btn btn-primary btn-sm">Publier <Icon name="arrow" size={12}/></button>
                </div>
              </div>
            </div>

            {/* Replies */}
            <div style={{ marginTop:24 }}>
              <div className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'var(--ink-mute)', marginBottom:12 }}>{p.replies} RÉPONSES · TRIÉES PAR PERTINENCE</div>
              {[
                { a:'MV', body:"C'est devenu plus simple en 2025 : la fusion AGESSA/MDA → URSSAF artistes-auteurs, et tu cotises au régime général via ce guichet unique. Les cachets intermittents restent à part (Audiens). Ton EC a raison sur la séparation des flux.", votes:142, time:'il y a 1h', best:true },
                { a:'ST', body:"Mon expérience : passe par le simulateur de l’URSSAF AA — il a sauvé ma vie. Et garde tous tes contrats classés par type.", votes:38, time:'il y a 45 min' },
                { a:'YD', body:"Je crois qu'on confond souvent « régime » et « caisse de retraite ». Le régime c’est URSSAF AA. Pour la retraite, c’est l’IRCEC.", votes:21, time:'il y a 30 min' },
              ].map((r, i) => {
                const aR = ARTISTS.find(x => x.initials === r.a);
                return (
                  <div key={i} style={{ display:'grid', gridTemplateColumns:'48px 1fr', gap:0, border:'1px solid var(--line-soft)', borderTop: i===0?'1px solid var(--line-soft)':0, marginBottom:0 }}>
                    <div style={{ background: r.best?'var(--accent)':'var(--bg-alt)', borderRight:'1px solid var(--line-soft)', display:'flex', flexDirection:'column', alignItems:'center', padding:'14px 0', gap:4 }}>
                      <Icon name="arrowUp" size={14}/>
                      <div className="h-display" style={{ fontSize:13 }}>{r.votes}</div>
                      {r.best && <Icon name="check" size={14}/>}
                    </div>
                    <div style={{ padding:'14px 18px' }}>
                      <div style={{ display:'flex', alignItems:'center', gap:10, marginBottom:8 }}>
                        <Avatar initials={r.a} size={26} bg={aR?.color || 'var(--accent)'} fg="var(--ink)"/>
                        <span style={{ fontWeight:700, fontSize:13 }}>{aR?.name}</span>
                        <span className="mono" style={{ fontSize:10, color:'var(--ink-mute)' }}>· {r.time}</span>
                        {r.best && <span className="chip" style={{ background:'var(--accent)', fontSize:9, padding:'2px 6px' }}>✓ Meilleure réponse</span>}
                      </div>
                      <p style={{ margin:0, fontSize:14, lineHeight:1.55 }}>{r.body}</p>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <aside>
            <div style={{ border:'1px solid var(--ink)', padding:18 }}>
              <Eyebrow>Le posteur</Eyebrow>
              <div style={{ display:'flex', alignItems:'center', gap:12, marginTop:14 }}>
                <Avatar initials={p.author} size={48} bg={a?.color || 'var(--accent)'} fg="var(--ink)"/>
                <div>
                  <div style={{ fontWeight:700 }}>{a?.name}</div>
                  <div className="mono" style={{ fontSize:11, color:'var(--ink-mute)' }}>{a?.city.toUpperCase()} · {a?.followers} ABONNÉS</div>
                </div>
              </div>
              <p style={{ marginTop:12, fontSize:13, color:'var(--ink-mute)' }}>{a?.practice}. Sur BazaArt depuis 14 mois.</p>
              <button className="btn btn-sm" style={{ marginTop:10 }} onClick={()=>go('dashboard-artist')}>Voir le profil <Icon name="arrow" size={11}/></button>
            </div>

            <div style={{ marginTop:16, border:'1px solid var(--ink)', padding:18, background:'var(--bg-alt)' }}>
              <Eyebrow>Sujets liés</Eyebrow>
              {POSTS.slice(0,3).map((s,i,a)=>(
                <div key={s.id} style={{ padding:'10px 0', borderBottom: i<a.length-1?'1px solid var(--line-soft)':'0' }}>
                  <div style={{ fontSize:13, fontWeight:600 }}>{s.title}</div>
                  <div className="mono" style={{ fontSize:10, color:'var(--ink-mute)', marginTop:4 }}>{s.replies} réponses</div>
                </div>
              ))}
            </div>
          </aside>
        </div>
      </section>
      <Footer go={go}/>
    </div>
  );
};

// ─── LIVE VIEW ──────────────────────────────────────────────────────────────
const ScreenLive = ({ id, go }) => {
  const p = POSTS.find(x => x.id === id) || POSTS.find(x => x.live === 'on');
  const a = ARTISTS.find(x => x.initials === p.author);
  return (
    <div className="screen" data-screen-label="03c Forum — live" style={{ background:'#0A0A0A', color:'var(--bg)' }}>
      <section style={{ borderBottom:'1px solid rgba(242,239,230,.15)' }}>
        <div className="container" style={{ paddingTop:14, paddingBottom:14, display:'flex', justifyContent:'space-between' }}>
          <button onClick={()=>go('forum')} style={{ background:'transparent', color:'var(--bg)', border:'1px solid rgba(242,239,230,.3)', padding:'6px 10px', font:'700 11px var(--body)', letterSpacing:'.06em', textTransform:'uppercase', cursor:'default' }}>← Forum</button>
          <div style={{ display:'flex', gap:12, alignItems:'center' }}>
            <LiveDot/>
            <span className="mono" style={{ fontSize:11, letterSpacing:'.1em', color:'var(--accent-2)' }}>EN DIRECT · 312 SPECTATEURS · 00:47:21</span>
          </div>
        </div>
      </section>

      <section className="container" style={{ paddingTop:16, paddingBottom:32 }}>
        <div style={{ display:'grid', gridTemplateColumns:'2.4fr 1fr', gap:16 }}>
          {/* Player + meta */}
          <div>
            <div style={{ aspectRatio:'16/9', background:'#111', position:'relative', overflow:'hidden', border:'1px solid rgba(242,239,230,.15)' }}>
              <svg viewBox="0 0 1200 675" preserveAspectRatio="none" style={{ width:'100%', height:'100%' }}>
                <defs><linearGradient id="lg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#C6F24E" stopOpacity=".15"/><stop offset="1" stopColor="#000" stopOpacity="0"/></linearGradient></defs>
                <rect width="1200" height="675" fill="url(#lg)"/>
                {Array.from({length:80}).map((_,k)=>{
                  const h = 80 + Math.abs(Math.sin(k*.5)*200) + Math.abs(Math.cos(k*.13)*100);
                  return <rect key={k} x={k*15+10} y={340-h/2} width="9" height={h} fill="#C6F24E" opacity={.4 + (k%5)*.12}/>;
                })}
                <text x="600" y="120" textAnchor="middle" fill="#C6F24E" fontFamily="var(--display)" fontSize="56">SET MODULAIRE</text>
                <text x="600" y="160" textAnchor="middle" fill="#fff" opacity=".5" fontFamily="var(--mono)" fontSize="14">ambient / drone · session ouverte</text>
              </svg>
              {/* Player controls */}
              <div style={{ position:'absolute', left:0, right:0, bottom:0, padding:'14px 18px', background:'linear-gradient(to top, rgba(0,0,0,.8), transparent)', display:'flex', alignItems:'center', gap:14 }}>
                <button style={{ width:42, height:42, borderRadius:'50%', background:'var(--accent)', border:0, display:'flex', alignItems:'center', justifyContent:'center' }}><Icon name="play" size={16}/></button>
                <div style={{ flex:1, height:4, background:'rgba(255,255,255,.2)', position:'relative' }}>
                  <div style={{ position:'absolute', left:0, top:0, bottom:0, width:'62%', background:'var(--accent-2)' }}/>
                </div>
                <span className="mono" style={{ fontSize:11, opacity:.7 }}>EN DIRECT</span>
                <Icon name="settings" size={16}/>
              </div>
            </div>

            <div style={{ marginTop:16, display:'flex', justifyContent:'space-between', alignItems:'flex-start', gap:24 }}>
              <div>
                <h1 className="h-display" style={{ fontSize:36, margin:0, color:'var(--accent)' }}>{p.title}</h1>
                <div style={{ marginTop:10, display:'flex', alignItems:'center', gap:12 }}>
                  <Avatar initials={p.author} size={42} bg={a?.color || 'var(--accent)'} fg="var(--ink)"/>
                  <div>
                    <div style={{ fontWeight:700 }}>{a?.name}</div>
                    <div className="mono" style={{ fontSize:11, opacity:.6 }}>{a?.practice.toUpperCase()} · {a?.city.toUpperCase()}</div>
                  </div>
                  <button className="btn btn-sm" style={{ background:'var(--accent)', color:'var(--ink)', borderColor:'var(--accent)', marginLeft:10 }}>+ Suivre</button>
                </div>
              </div>
              <div style={{ display:'flex', gap:8 }}>
                <button className="btn btn-sm" style={{ background:'transparent', color:'var(--bg)', borderColor:'rgba(242,239,230,.3)' }}><Icon name="heart" size={13}/> 184</button>
                <button className="btn btn-sm" style={{ background:'transparent', color:'var(--bg)', borderColor:'rgba(242,239,230,.3)' }}>↗ Partager</button>
                <button className="btn btn-sm" style={{ background:'transparent', color:'var(--bg)', borderColor:'rgba(242,239,230,.3)' }}>💰 Pourboire</button>
              </div>
            </div>

            {/* Tabs */}
            <div style={{ marginTop:24, borderBottom:'1px solid rgba(242,239,230,.15)', display:'flex', gap:24 }}>
              {['Description','Setlist','À propos','Replays (12)'].map((t, i) => (
                <div key={t} style={{ padding:'10px 0', borderBottom: i===0?'2px solid var(--accent)':'2px solid transparent', color: i===0?'var(--bg)':'rgba(242,239,230,.5)', cursor:'default', fontWeight:600, fontSize:13 }}>{t}</div>
              ))}
            </div>
            <p style={{ marginTop:16, fontSize:14, lineHeight:1.6, color:'rgba(242,239,230,.8)' }}>
              Session studio ouverte. Je compose en temps réel sur mon modulaire — patchs lents, sons de souffle, drones harmoniques.
              N’hésitez pas à poser des questions techniques dans le chat, je réponds entre deux modulations.
            </p>
          </div>

          {/* Chat */}
          <aside style={{ display:'flex', flexDirection:'column', border:'1px solid rgba(242,239,230,.15)', maxHeight:'calc(100vh - 200px)' }}>
            <div style={{ padding:'12px 14px', borderBottom:'1px solid rgba(242,239,230,.15)', display:'flex', justifyContent:'space-between', alignItems:'center' }}>
              <div className="mono" style={{ fontSize:10, letterSpacing:'.12em', fontWeight:700, color:'var(--accent)' }}>CHAT EN DIRECT</div>
              <div className="mono" style={{ fontSize:10, opacity:.5 }}>312 EN LIGNE</div>
            </div>
            <div style={{ flex:1, overflowY:'auto', padding:'10px 14px', display:'flex', flexDirection:'column', gap:6, minHeight:380 }}>
              {[
                { a:'YD', c:'#FF6B2C', t:'magnifique drone' },
                { a:'MV', c:'#B794F6', t:'c’est quoi le module orange à droite ?' },
                { a:'IK', c:'#C6F24E', t:'cette texture 😍' },
                { a:'ST', c:'#FFD23F', t:'@MV un Make Noise Maths, basique mais énorme', host:true },
                { a:'JP', c:'#7DD3FC', t:'tu fais ça en post ou tout est live ?' },
                { a:'ST', c:'#FFD23F', t:'@JP tout live, zéro overdub', host:true },
                { a:'LB', c:'#C6F24E', t:'je peux capter la setlist en MP ?' },
                { a:'MV', c:'#B794F6', t:'merci pour la réponse ! je note' },
                { a:'YD', c:'#FF6B2C', t:'+1 sur la setlist' },
                { a:'NM', c:'#FFD23F', t:'super sound design' },
                { a:'ST', c:'#FFD23F', t:'je posterai le patch après le live promis', host:true },
                { a:'IK', c:'#C6F24E', t:'❤️❤️❤️' },
                { a:'AB', c:'#7DD3FC', t:'enfin un live qui prend son temps' },
              ].map((m, i) => (
                <div key={i} style={{ display:'flex', gap:8, alignItems:'flex-start', fontSize:12.5 }}>
                  <span style={{ color:m.c, fontWeight:700, flexShrink:0 }}>{m.a}{m.host && ' ★'}</span>
                  <span style={{ color:'rgba(242,239,230,.85)' }}>{m.t}</span>
                </div>
              ))}
            </div>
            <div style={{ padding:10, borderTop:'1px solid rgba(242,239,230,.15)' }}>
              <div style={{ display:'flex', gap:8 }}>
                <input className="input" placeholder="Envoie un message…" style={{ background:'rgba(255,255,255,.06)', color:'var(--bg)', border:'1px solid rgba(242,239,230,.2)' }}/>
                <button className="btn btn-sm" style={{ background:'var(--accent)', color:'var(--ink)', borderColor:'var(--accent)' }}>Envoyer</button>
              </div>
            </div>
          </aside>
        </div>
      </section>
    </div>
  );
};

Object.assign(window, { ScreenForum, ScreenThread, ScreenLive });
