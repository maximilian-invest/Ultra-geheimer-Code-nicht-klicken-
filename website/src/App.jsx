import { useState, useEffect, useCallback, useMemo } from "react";
import { ASSETS } from "./config.js";
import { useProperties } from "./hooks/useProperties.js";
import { useCmsContent } from "./hooks/useCmsContent.js";
import { useTheme } from "./hooks/useTheme.js";
import { GlobalStyles } from "./components/ui.jsx";
import { Nav } from "./components/Nav.jsx";
import { Footer } from "./components/Footer.jsx";
import { HomePage } from "./pages/HomePage.jsx";
import { ImmobilienPage } from "./pages/ImmobilienPage.jsx";
import { DetailPage } from "./pages/DetailPage.jsx";
import { VerkaufenPage } from "./pages/VerkaufenPage.jsx";
import { BewertenPage } from "./pages/BewertenPage.jsx";
import { PortalPage } from "./pages/PortalPage.jsx";
import { ÜberPage } from "./pages/UeberPage.jsx";
import { KontaktPage } from "./pages/KontaktPage.jsx";
import { ImpressumPage } from "./pages/ImpressumPage.jsx";
import { DatenschutzPage } from "./pages/DatenschutzPage.jsx";

// ═══════════════════════════════════════════════════════════════════
// MAIN APP
// ═══════════════════════════════════════════════════════════════════
// ─── URL ROUTING HELPERS ──────────────────────────────────────────
const viewToPath = { home: "/", immobilien: "/immobilien", detail: "/objekt", verkaufen: "/verkaufen", bewerten: "/bewerten", portal: "/portal", "über": "/ueber-uns", kontakt: "/kontakt", impressum: "/impressum", datenschutz: "/datenschutz" };
const pathToView = Object.fromEntries(Object.entries(viewToPath).map(([k, v]) => [v, k]));
const viewTitles = { home: "SR-Homes Immobilien", immobilien: "Immobilien", detail: "Objekt", verkaufen: "Verkaufen", bewerten: "Bewertung", portal: "Kundenportal", "über": "Über uns", kontakt: "Kontakt", impressum: "Impressum", datenschutz: "Datenschutz" };

export default function App() {
  const initialPage = pathToView[window.location.pathname] || "home";
  const [page, setPage] = useState(initialPage);
  const [scrolled, setScrolled] = useState(false);
  const [selected, setSelected] = useState(null);
  const t = useTheme(false);
  const { properties } = useProperties();
  const cms = useCmsContent();

  // Override ASSETS with CMS branding
  const logos = useMemo(() => ({
    color: cms.branding?.logo_color || ASSETS.logoColor,
    white: cms.branding?.logo_white || ASSETS.logoWhite,
  }), [cms.branding]);

  const go = useCallback((p) => {
    if (window._srPopState) return setPage(p);
    setPage(p);
    const path = viewToPath[p] || "/";
    const title = viewTitles[p] || "SR-Homes";
    window.history.pushState({ view: p }, title, path);
    document.title = title + " | SR-Homes";
    window.scrollTo({ top: 0, behavior: "instant" });
  }, []);

  // Handle browser back/forward
  useEffect(() => {
    const onPop = () => {
      const view = pathToView[window.location.pathname] || "home";
      window._srPopState = true;
      setPage(view);
      window.scrollTo({ top: 0, behavior: "instant" });
      setTimeout(() => { window._srPopState = false; }, 100);
    };
    window.addEventListener("popstate", onPop);
    // Set initial history state
    const initPath = viewToPath[initialPage] || "/";
    window.history.replaceState({ view: initialPage }, document.title, initPath);
    return () => window.removeEventListener("popstate", onPop);
  }, [initialPage]);

  useEffect(() => {
    const fn = () => setScrolled(window.scrollY > 50);
    window.addEventListener("scroll", fn, { passive: true });
    return () => window.removeEventListener("scroll", fn);
  }, []);

  // Update page title from CMS SEO
  useEffect(() => {
    if (cms.seo?.meta_title && page === "home") document.title = cms.seo.meta_title;
    const metaDesc = document.querySelector('meta[name="description"]');
    if (metaDesc && cms.seo?.meta_description) metaDesc.setAttribute("content", cms.seo.meta_description);
  }, [cms.seo, page]);

  const pages = {
    home: <HomePage setPage={go} setSelected={setSelected} t={t} properties={properties} cms={cms} />,
    immobilien: <ImmobilienPage setPage={go} setSelected={setSelected} t={t} properties={properties} />,
    detail: <DetailPage property={selected} setPage={go} setSelected={setSelected} t={t} properties={properties} />,
    verkaufen: <VerkaufenPage setPage={go} t={t} properties={properties} />,
    bewerten: <BewertenPage setPage={go} t={t} />,
    portal: <PortalPage setPage={go} t={t} cms={cms} />,
    über: <ÜberPage setPage={go} t={t} />,
    kontakt: <KontaktPage t={t} cms={cms} />,
    impressum: <ImpressumPage t={t} cms={cms} />,
    datenschutz: <DatenschutzPage t={t} cms={cms} />,
  };

  return (
    <div className="min-h-screen" style={{ background: t.bg, color: t.text }}>
      <GlobalStyles />
      <div className="grain" />
      <Nav page={page} setPage={go} scrolled={scrolled || page !== "home"} t={t} logos={logos} />
      <main>{pages[page] || pages.home}</main>
      <Footer setPage={go} t={t} cms={cms} logos={logos} />
    </div>
  );
}
