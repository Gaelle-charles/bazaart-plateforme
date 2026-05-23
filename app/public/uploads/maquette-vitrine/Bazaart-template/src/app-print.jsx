// src/app-print.jsx — renders all screens stacked vertically for PDF export

const noop = () => {};

function PrintApp() {
  // Apply theme palette
  React.useEffect(() => {
    document.documentElement.style.setProperty('--accent', '#C6F24E');
    document.documentElement.style.setProperty('--accent-2', '#FF6B2C');
  }, []);

  const pages = [
    { label: '01 — Accueil (Manifeste)',          el: <ScreenLanding go={noop} variant="manifesto"/> },
    { label: '02 — Accueil (Éditorial)',          el: <ScreenLanding go={noop} variant="editorial"/> },
    { label: '03 — Accueil (Cards)',              el: <ScreenLanding go={noop} variant="cards"/> },
    { label: '04 — Opportunités · Liste',         el: <ScreenOpportunities go={noop} cardStyle="editorial"/> },
    { label: '05 — Opportunités · Détail',        el: <ScreenOpportunity go={noop} id="o1"/> },
    { label: '06 — Forum hybride',                el: <ScreenForum go={noop}/> },
    { label: '07 — Forum · Fil de discussion',    el: <ScreenThread go={noop} id="p1"/> },
    { label: '08 — Forum · Live',                 el: <ScreenLive go={noop} id="p4"/> },
    { label: '09 — Dashboard artiste',            el: <ScreenArtistDashboard go={noop} profileStyle="editorial"/> },
    { label: '10 — Dashboard structure',          el: <ScreenStructureDashboard go={noop}/> },
    { label: '11 — Dashboard admin',              el: <ScreenAdminDashboard go={noop}/> },
    { label: '12 — Blog',                         el: <ScreenBlog go={noop}/> },
    { label: '13 — Tarifs',                       el: <ScreenPricing go={noop}/> },
  ];

  return (
    <div className="print-stack">
      {pages.map((p, i) => (
        <div key={i} className="print-page" data-page-label={p.label}>
          <div className="print-page-caption">
            <span>BazaArt · prototype clickable</span>
            <span>{p.label}</span>
            <span>{i + 1} / {pages.length}</span>
          </div>
          <div className="print-page-frame">
            {p.el}
          </div>
        </div>
      ))}
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<PrintApp/>);
