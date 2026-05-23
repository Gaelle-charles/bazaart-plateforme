// src/app.jsx — root shell, router, nav, tweaks

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "theme": "light",
  "palette": ["#C6F24E", "#FF6B2C"],
  "landingVariant": "manifesto",
  "oppCardStyle": "editorial",
  "artistProfileStyle": "editorial",
  "displayFont": "Archivo Black",
  "showGrain": true
}/*EDITMODE-END*/;

const NAV = [
  { key:'home', label:'Accueil', screen:'home' },
  { key:'opportunities', label:'Opportunités', screen:'opportunities' },
  { key:'forum', label:'Forum', screen:'forum' },
  { key:'blog', label:'Blog', screen:'blog' },
  { key:'pricing', label:'Tarifs', screen:'pricing' },
  { key:'dashboards', label:'Dashboards', children:[
    { key:'dashboard-artist', label:'Artiste', screen:'dashboard-artist' },
    { key:'dashboard-structure', label:'Structure', screen:'dashboard-structure' },
    { key:'dashboard-admin', label:'Admin', screen:'dashboard-admin' },
  ] },
];

const PALETTES = [
  ['#C6F24E', '#FF6B2C'], // acid + tangerine (default)
  ['#FFD23F', '#E5484D'], // sun + red
  ['#B794F6', '#7DD3FC'], // violet + sky
  ['#C6F24E', '#000000'], // green + black (monochrome accent)
  ['#FF6B2C', '#0D0D0D'], // tangerine + ink
];

const FONT_OPTIONS = [
  { label:'Archivo Black', stack:'"Archivo Black", Impact, sans-serif' },
  { label:'Anton', stack:'"Anton", Impact, sans-serif' },
  { label:'Bebas Neue', stack:'"Bebas Neue", Impact, sans-serif' },
  { label:'Inter (sage)', stack:'"Inter", system-ui, sans-serif' },
];

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [route, setRoute] = React.useState({ screen:'home', param:null });
  const [openDropdown, setOpenDropdown] = React.useState(null);

  // Apply theme & palette to <body>
  React.useEffect(()=>{
    document.body.setAttribute('data-theme', t.theme);
    document.documentElement.style.setProperty('--accent', t.palette[0]);
    document.documentElement.style.setProperty('--accent-2', t.palette[1]);
    const font = FONT_OPTIONS.find(f => f.label === t.displayFont);
    if (font) document.documentElement.style.setProperty('--display', font.stack);
    document.body.style.setProperty('--__grain', t.showGrain ? .05 : 0);
    // toggle grain pseudo by class
    if (t.showGrain) document.body.classList.remove('no-grain');
    else document.body.classList.add('no-grain');
  }, [t.theme, t.palette, t.displayFont, t.showGrain]);

  const go = (screen, param=null) => {
    setRoute({ screen, param });
    setOpenDropdown(null);
    window.scrollTo({ top:0, behavior:'instant' });
  };

  // Map screen key → component
  const renderScreen = () => {
    switch(route.screen) {
      case 'home': return <ScreenLanding go={go} variant={t.landingVariant}/>;
      case 'opportunities': return <ScreenOpportunities go={go} cardStyle={t.oppCardStyle}/>;
      case 'opportunity': return <ScreenOpportunity id={route.param} go={go}/>;
      case 'forum': return <ScreenForum go={go}/>;
      case 'thread': return <ScreenThread id={route.param} go={go}/>;
      case 'live': return <ScreenLive id={route.param} go={go}/>;
      case 'dashboard-artist': return <ScreenArtistDashboard go={go} profileStyle={t.artistProfileStyle}/>;
      case 'dashboard-structure': return <ScreenStructureDashboard go={go}/>;
      case 'dashboard-admin': return <ScreenAdminDashboard go={go}/>;
      case 'blog': return <ScreenBlog go={go}/>;
      case 'pricing': return <ScreenPricing go={go}/>;
      default: return <ScreenLanding go={go} variant={t.landingVariant}/>;
    }
  };

  // determine active nav key
  const activeNav = (() => {
    const s = route.screen;
    if (['opportunities','opportunity'].includes(s)) return 'opportunities';
    if (['forum','thread','live'].includes(s)) return 'forum';
    if (['dashboard-artist','dashboard-structure','dashboard-admin'].includes(s)) return 'dashboards';
    return s;
  })();

  // Hide nav on live screen for cinematic full-bleed
  const hideNav = route.screen === 'live';

  return (
    <div>
      {!hideNav && (
        <header className="nav">
          <div className="nav-inner">
            <button className="nav-logo" onClick={()=>go('home')} style={{ border:0, cursor:'default' }}>
              <Logo height={20}/>
            </button>
            <nav className="nav-tabs">
              {NAV.map(item => {
                const active = activeNav === item.key;
                if (item.children) {
                  return (
                    <div key={item.key} style={{ position:'relative', display:'flex' }}
                         onMouseEnter={()=>setOpenDropdown(item.key)}
                         onMouseLeave={()=>setOpenDropdown(null)}>
                      <button className="nav-tab" data-active={active} style={{ display:'flex', alignItems:'center', gap:6 }}>
                        <span className="dot"/> {item.label} <Icon name="arrow" size={11} style={{ transform:'rotate(90deg)' }}/>
                      </button>
                      {openDropdown === item.key && (
                        <div style={{
                          position:'absolute', top:'100%', left:0, minWidth:240,
                          background:'var(--bg)', border:'1px solid var(--ink)', borderTop:0, zIndex:200,
                        }}>
                          {item.children.map(c => (
                            <button key={c.key} onClick={()=>go(c.screen)} style={{
                              display:'block', width:'100%', textAlign:'left',
                              padding:'12px 18px', background: route.screen===c.screen?'var(--ink)':'transparent',
                              color: route.screen===c.screen?'var(--accent)':'var(--ink)',
                              border:0, borderBottom:'1px solid var(--line-soft)',
                              font:'700 12px var(--body)', letterSpacing:'.04em', textTransform:'uppercase', cursor:'default',
                            }}>
                              {c.label}
                              <span className="mono" style={{ marginLeft:6, fontSize:10, opacity:.6 }}>
                                {c.key==='dashboard-artist' && '· toi'}
                                {c.key==='dashboard-structure' && '· struct.'}
                                {c.key==='dashboard-admin' && '· staff'}
                              </span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  );
                }
                return (
                  <button key={item.key} className="nav-tab" data-active={active} onClick={()=>go(item.screen)}>
                    <span className="dot"/> {item.label}
                  </button>
                );
              })}
            </nav>
            <div className="nav-side">
              <div className="nav-search">
                <Icon name="search" size={13}/>
                <input placeholder="Chercher…"/>
                <span className="mono" style={{ fontSize:10, padding:'2px 5px', border:'1px solid var(--line-soft)', borderRadius:2 }}>⌘K</span>
              </div>
              <button className="nav-cta" onClick={()=>go('pricing')}>Adhérer</button>
              <div className="nav-avatar">MO</div>
            </div>
          </div>
        </header>
      )}

      {renderScreen()}

      {/* TWEAKS PANEL */}
      <TweaksPanel title="Tweaks BazaArt">
        <TweakSection label="Esthétique"/>
        <TweakColor label="Palette d’accents" value={t.palette}
          options={PALETTES}
          onChange={v => setTweak('palette', v)}/>
        <TweakRadio label="Thème" value={t.theme}
          options={['light','dark']}
          onChange={v => setTweak('theme', v)}/>
        <TweakSelect label="Typo display" value={t.displayFont}
          options={FONT_OPTIONS.map(f => f.label)}
          onChange={v => setTweak('displayFont', v)}/>
        <TweakToggle label="Grain papier" value={t.showGrain}
          onChange={v => setTweak('showGrain', v)}/>

        <TweakSection label="Variantes"/>
        <TweakSelect label="Style de la home" value={t.landingVariant}
          options={['manifesto', 'editorial', 'cards']}
          onChange={v => setTweak('landingVariant', v)}/>
        <TweakSelect label="Cartes opportunité" value={t.oppCardStyle}
          options={['editorial', 'poster', 'ticket']}
          onChange={v => setTweak('oppCardStyle', v)}/>
        <TweakSelect label="Profil artiste public" value={t.artistProfileStyle}
          options={['editorial', 'gallery', 'cv']}
          onChange={v => setTweak('artistProfileStyle', v)}/>

        <TweakSection label="Navigation"/>
        <TweakButton label="→ Accueil" onClick={()=>go('home')}/>
        <TweakButton label="→ Opportunités" onClick={()=>go('opportunities')}/>
        <TweakButton label="→ Détail opportunité" onClick={()=>go('opportunity', 'o1')}/>
        <TweakButton label="→ Forum" onClick={()=>go('forum')}/>
        <TweakButton label="→ Thread" onClick={()=>go('thread', 'p1')}/>
        <TweakButton label="→ Live" onClick={()=>go('live', 'p4')}/>
        <TweakButton label="→ Dashboard artiste" onClick={()=>go('dashboard-artist')}/>
        <TweakButton label="→ Dashboard structure" onClick={()=>go('dashboard-structure')}/>
        <TweakButton label="→ Dashboard admin" onClick={()=>go('dashboard-admin')}/>
        <TweakButton label="→ Blog & article" onClick={()=>go('blog')}/>
        <TweakButton label="→ Tarifs" onClick={()=>go('pricing')}/>
      </TweaksPanel>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
