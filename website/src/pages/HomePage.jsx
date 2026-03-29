import { useState } from "react";
import {
  Search, ArrowUpRight, ArrowRight, Phone, Star,
  CheckCircle, Users, FileText, Globe, Zap,
  Activity, Eye, Target, PieChart, LineChart, MessageSquare
} from "lucide-react";
import { ASSETS, PROPERTIES, ICON_MAP, DEFAULT_CMS } from "../config.js";
import { Eyebrow, Btn, StatCounter, FeaturedCard, PropertyCard } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// HOME PAGE
// ═══════════════════════════════════════════════════════════════════
export const HomePage = ({ setPage, setSelected, t, properties = PROPERTIES, cms = DEFAULT_CMS }) => {
  const [searchCat, setSearchCat] = useState("kauf");
  const [videoLoaded, setVideoLoaded] = useState(false);
  const hero = cms.hero || DEFAULT_CMS.hero;
  const stats = cms.stats || DEFAULT_CMS.stats;
  const services = cms.services || DEFAULT_CMS.services;
  const about = cms.about || DEFAULT_CMS.about;
  const portal = cms.portal || DEFAULT_CMS.portal;
  const testimonial = cms.testimonial || DEFAULT_CMS.testimonial;

  return (
    <div>
      {/* ── CINEMATIC HERO with Video ──────────── */}
      <section className="relative min-h-[100dvh]">
        <div className="absolute inset-0 overflow-hidden">
          <video autoPlay muted loop playsInline onLoadedData={() => setVideoLoaded(true)}
            className="w-full h-full object-cover hero-video"
            poster={hero.background_image || ASSETS.heroDesktop}>
            <source src={hero.video_url || ASSETS.heroVideo} type="video/mp4" />
          </video>
          <div className="video-overlay" />
        </div>

        <div className="relative z-10 h-full min-h-[100dvh] flex flex-col justify-end pt-20 md:pt-24" style={{ paddingBottom: "clamp(24px, 4vh, 80px)" }}>
          <div className="max-w-[1440px] mx-auto px-4 sm:px-6 md:px-12 lg:px-16 w-full">
            <div className="max-w-4xl">
              <div className="anim-fade-up hidden md:block">
                <Eyebrow t={{ ...t, accent: "#E8743A" }}>Salzburg &amp; Oberösterreich</Eyebrow>
              </div>

              <h1 className="hero-h1 font-display anim-hero-text anim-d1" style={{ fontSize: "clamp(2.5rem, 5vw, 4.5rem)", fontWeight: 700, lineHeight: 0.95, color: "#fff", letterSpacing: "-0.03em" }}
                dangerouslySetInnerHTML={{ __html: (hero.headline || "Ihr nächstes<br/>Zuhause wartet.").replace(hero.headline_accent || "Zuhause", `<span style="color:#E8743A">${hero.headline_accent || "Zuhause"}</span>`) }}
              />

              <p className="anim-fade-up anim-d2 mt-4 md:mt-8 text-base md:text-xl leading-relaxed max-w-xl" style={{ color: "rgba(255,255,255,0.55)" }}>
                {hero.subheadline || "Wir verbinden Immobilienexpertise mit modernster Technologie für ein Erlebnis, das den Unterschied macht."}
              </p>

              {/* Search Box */}
              <div className="anim-fade-up anim-d3 mt-6 md:mt-10 max-w-3xl">
                <div className="rounded-2xl overflow-hidden" style={{ background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.08)", backdropFilter: "blur(20px)" }}>
                  <div className="p-5 md:p-6">
                    <div className="flex gap-1 mb-5 p-1 rounded-full w-fit" style={{ background: "rgba(255,255,255,0.06)" }}>
                      {["kauf", "miete"].map((c) => (
                        <button key={c} onClick={() => setSearchCat(c)}
                          className="px-6 py-2.5 text-sm font-semibold rounded-full transition-all duration-300 tracking-wide"
                          style={{ background: searchCat === c ? "#E8743A" : "transparent", color: searchCat === c ? "#fff" : "rgba(255,255,255,0.5)" }}
                        >{c === "kauf" ? "KAUFEN" : "MIETEN"}</button>
                      ))}
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                      {[
                        { label: "Objekttyp", opts: ["Alle Typen", "Haus", "Wohnung", "Grundstück", "Neubauprojekt", "Gewerbe"] },
                        { label: "Region", opts: ["Alle Regionen", "Salzburg Stadt", "Flachgau", "Innviertel", "Mondseeland"] },
                        { label: "Preis bis", opts: ["Kein Limit", "EUR 200.000", "EUR 350.000", "EUR 500.000", "EUR 750.000", "EUR 1 Mio.+"] },
                      ].map((f, i) => (
                        <div key={i}>
                          <label className="text-xs font-semibold mb-1.5 block uppercase tracking-widest" style={{ color: "rgba(255,255,255,0.3)" }}>{f.label}</label>
                          <select className="w-full px-4 py-3 rounded-xl text-sm font-medium border-0"
                            style={{ background: "rgba(255,255,255,0.08)", color: "#fff", appearance: "none" }}>
                            {f.opts.map((o) => <option key={o} style={{ color: "#000" }}>{o}</option>)}
                          </select>
                        </div>
                      ))}
                      <div className="flex items-end">
                        <button onClick={() => setPage("immobilien")}
                          className="w-full flex items-center justify-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white transition-all hover:scale-[1.02] active:scale-[0.98]"
                          style={{ background: "#E8743A", letterSpacing: "0.1em" }}>
                          <Search size={16} /> SUCHEN
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </section>

      {/* ── STATS SECTION (huge numbers) ───────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg, borderBottom: `1px solid ${t.borderLight}` }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-12 md:gap-8">
            {Object.values(stats).filter(s => s && s.value).map((s, i) => (
              <StatCounter key={i} value={s.value} suffix={s.suffix} label={s.label} t={t} delay={i * 0.15} />
            ))}
          </div>
        </div>
      </section>

      {/* ── FEATURED PROPERTIES (SERHANT-Style) ── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="flex flex-col md:flex-row md:items-end justify-between gap-8 mb-14">
            <div>
              <Eyebrow t={t}>Exklusive Objekte</Eyebrow>
              <h2 className="font-display section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
                Unsere Top-Immobilien
              </h2>
            </div>
            <Btn icon={ArrowRight} onClick={() => setPage("immobilien")} style={{ color: t.text, borderColor: t.border }}>
              Alle {properties.length} Objekte
            </Btn>
          </div>

          {properties.length > 0 && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <FeaturedCard property={properties[0]} onClick={() => { setSelected(properties[0]); setPage("detail"); }} t={t} index={0} />
            {properties.length > 2 && (
            <div className="grid grid-rows-2 gap-5">
              <FeaturedCard property={properties[Math.min(3, properties.length - 1)]} onClick={() => { setSelected(properties[Math.min(3, properties.length - 1)]); setPage("detail"); }} t={t} index={1} />
              <FeaturedCard property={properties[Math.min(5, properties.length - 1)]} onClick={() => { setSelected(properties[Math.min(5, properties.length - 1)]); setPage("detail"); }} t={t} index={2} />
            </div>
            )}
          </div>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-6">
            {properties.slice(1, 4).map((p) => (
              <PropertyCard key={p.id} property={p} onClick={() => { setSelected(p); setPage("detail"); }} t={t} />
            ))}
          </div>
        </div>
      </section>

      {/* ── FULL-WIDTH IMAGE BREAK ─────────────── */}
      <section className="relative" style={{ height: 500 }}>
        <img src={about.parallax_image || ASSETS.homeParallax} alt="" className="absolute inset-0 w-full h-full object-cover" />
        <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.85) 0%, rgba(10,10,8,0.3) 60%, transparent 100%)" }} />
        <div className="relative z-10 h-full flex items-center">
          <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
            <div className="max-w-xl">
              <h2 className="font-display text-3xl md:text-4xl lg:text-5xl xl:text-6xl font-bold text-white tracking-tight leading-none mb-6">
                {about.parallax_headline}
              </h2>
              <p className="text-base text-white/50 leading-relaxed mb-8">
                {about.parallax_text}
              </p>
              <Btn primary large icon={ArrowUpRight} onClick={() => setPage("über")}>Über uns</Btn>
            </div>
          </div>
        </div>
      </section>

      {/* ── SERVICES GRID ──────────────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-16">
            <div className="lg:col-span-5 lg:sticky lg:top-32 self-start">
              <Eyebrow t={t}>Unsere Leistungen</Eyebrow>
              <h2 className="font-display mb-6 section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
                Rundum-Service auf höchstem Niveau
              </h2>
              <p className="text-base leading-relaxed mb-10" style={{ color: t.textSecondary }}>
                Von der präzisen Marktbewertung über professionelle Vermarktung bis zum erfolgreichen Abschluss — wir übernehmen alles.
              </p>
              <div className="flex flex-col sm:flex-row gap-4">
                <Btn primary icon={ArrowUpRight} onClick={() => setPage("verkaufen")}>Verkaufen</Btn>
                <Btn icon={ArrowRight} onClick={() => setPage("bewerten")} style={{ color: t.text, borderColor: t.border }}>Bewerten</Btn>
              </div>
            </div>
            <div className="lg:col-span-7 grid grid-cols-1 sm:grid-cols-2 gap-5">
              {Object.values(services).filter(s => s && s.title).map((s, i) => {
                const IconComp = ICON_MAP[s.icon] || Zap;
                return (
                  <div key={i} className="hover-lift hover-glow p-7 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                    <div className="w-12 h-12 rounded-xl flex items-center justify-center mb-5" style={{ background: t.accentLight }}>
                      <IconComp size={22} style={{ color: t.accent }} />
                    </div>
                    <h3 className="text-base font-bold tracking-tight mb-2" style={{ color: t.text }}>{s.title}</h3>
                    <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{s.desc}</p>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </section>

      {/* ── KUNDENPORTAL SHOWCASE (Customer POV) ── */}
      <section className="py-24 md:py-36 overflow-hidden" style={{ background: "#0A0A08" }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="mb-20">
            <Eyebrow t={{ ...t, accent: "#E8743A" }}>Ihr Kundenportal</Eyebrow>
            <h2 className="font-display section-heading" style={{ fontSize: "clamp(2rem, 5vw, 4rem)", fontWeight: 700, color: "#fff", lineHeight: 1, letterSpacing: "-0.03em", maxWidth: 700 }}>
              {portal.headline}
            </h2>
            <p className="text-lg text-white/40 mt-6 max-w-2xl leading-relaxed">
              {portal.subheadline}
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            {[
              { icon: Activity, title: "Live-Aktivitäten", desc: "Jede Anfrage, jedes Exposé, jede Besichtigung — Sie sehen alles in Echtzeit. Wissen Sie immer genau, wo Sie stehen.", big: "815+", sub: "Aktivitäten getrackt" },
              { icon: Eye, title: "Besichtigungs-Feedback", desc: "Lesen Sie, was Interessenten nach der Besichtigung sagen. Direktes, ungefiltertes Feedback — die Basis für kluge Entscheidungen.", big: "100%", sub: "Transparenz" },
              { icon: Target, title: "Vermarktungs-Trichter", desc: "Verfolgen Sie den Weg vom Lead zum Kaufanbot. Wie viele Anfragen, Besichtigungen und Angebote gibt es? Alles visualisiert.", big: "192", sub: "Anfragen gesamt" },
              { icon: PieChart, title: "Plattform-Analyse", desc: "Sehen Sie genau, welche Plattform die meisten qualifizierten Anfragen liefert. willhaben, immowelt, ImmobilienScout24 — alles aufgeschlüsselt.", big: "5", sub: "Plattformen verbunden" },
              { icon: LineChart, title: "Marktbericht", desc: "Datengestützte Marktanalyse für Ihre Region: Preisentwicklung, EZB-Leitzins, Vergleichsobjekte. Fundierte Entscheidungsgrundlage.", big: "EUR 52M", sub: "analysiertes Volumen" },
              { icon: MessageSquare, title: "Direkter Draht", desc: "Senden Sie Nachrichten, teilen Sie Dokumente, stellen Sie Fragen — alles sicher und archiviert in Ihrem persönlichen Portal.", big: "24/7", sub: "Verfügbar" },
            ].map((f, i) => (
              <div key={i} className="p-7 rounded-2xl transition-all duration-500 hover:translate-y-[-4px]"
                style={{ background: "rgba(255,255,255,0.03)", border: "1px solid rgba(255,255,255,0.06)" }}>
                <div className="flex items-center gap-3 mb-5">
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ background: "rgba(232,116,58,0.12)" }}>
                    <f.icon size={18} style={{ color: "#E8743A" }} />
                  </div>
                  <h3 className="text-base font-bold text-white">{f.title}</h3>
                </div>
                <div className="mb-4">
                  <span className="text-3xl font-black tracking-tighter text-white">{f.big}</span>
                  <span className="text-xs text-white/30 ml-2 uppercase tracking-wider">{f.sub}</span>
                </div>
                <p className="text-sm leading-relaxed text-white/40">{f.desc}</p>
              </div>
            ))}
          </div>

          <div className="mt-14">
            <Btn primary large icon={ArrowUpRight} onClick={() => setPage("portal")}>Portal entdecken</Btn>
          </div>
        </div>
      </section>

      {/* ── DATA-DRIVEN EXPERTISE SECTION ──────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
            <div>
              <Eyebrow t={t}>Datengetriebene Expertise</Eyebrow>
              <h2 className="font-display mb-6 section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
                Entscheidungen basierend auf Daten, nicht Bauchgefühl
              </h2>
              <p className="text-base leading-relaxed mb-8" style={{ color: t.textSecondary }}>
                Unser Analyse-System verarbeitet täglich hunderte Datenpunkte: EZB-Leitzinsentwicklungen, regionale Immobilienpreise, Plattform-Performance und Markttrends. Diese Intelligenz fliesst in jede Beratung und jede Vermarktungsstrategie ein.
              </p>

              <div className="space-y-5">
                {[
                  { label: "Regionaler Marktbericht", desc: "Salzburg, Flachgau, Innviertel — aktuelle Preistrends und Prognosen" },
                  { label: "EZB-Leitzins Monitoring", desc: "Wie Zinsentwicklungen den Immobilienmarkt beeinflussen" },
                  { label: "Vergleichsobjekt-Analyse", desc: "Reale Verkaufspreise ähnlicher Immobilien in Ihrer Umgebung" },
                  { label: "Plattform-Performance", desc: "Welche Kanäle die qualifiziertesten Anfragen liefern" },
                  { label: "Verkaufstrichter-Analyse", desc: "Conversion-Raten von Anfrage bis Kaufanbot" },
                ].map((item, i) => (
                  <div key={i} className="flex items-start gap-4 p-4 rounded-xl" style={{ background: t.bgAlt }}>
                    <CheckCircle size={18} style={{ color: t.accent }} className="mt-0.5 shrink-0" />
                    <div>
                      <div className="text-sm font-bold" style={{ color: t.text }}>{item.label}</div>
                      <div className="text-xs mt-0.5" style={{ color: t.textMuted }}>{item.desc}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Data Visualization Mockup */}
            <div className="p-1.5 rounded-3xl" style={{ background: t.bgAlt, border: `1px solid ${t.borderLight}` }}>
              <div className="rounded-2xl overflow-hidden p-6" style={{ background: t.bgCard }}>
                <div className="flex items-center justify-between mb-6">
                  <div>
                    <div className="text-xs font-bold uppercase tracking-widest" style={{ color: t.textMuted }}>Marktanalyse</div>
                    <div className="text-lg font-bold" style={{ color: t.text }}>Salzburg Region</div>
                  </div>
                  <span className="px-3 py-1 rounded-full text-xs font-semibold" style={{ background: t.accentLight, color: t.accent }}>Live</span>
                </div>

                <div className="grid grid-cols-3 gap-3 mb-6">
                  {[
                    { l: "Durchschn. m²-Preis", v: "EUR 5.280", c: "+3.2%" },
                    { l: "EZB Leitzins", v: "2,65%", c: "-0.25%" },
                    { l: "Ø Vermarktungszeit", v: "42 Tage", c: "-8 T." },
                  ].map((d, i) => (
                    <div key={i} className="p-4 rounded-xl" style={{ background: t.bgAlt }}>
                      <div className="text-xs mb-1" style={{ color: t.textMuted }}>{d.l}</div>
                      <div className="text-lg font-bold" style={{ color: t.text }}>{d.v}</div>
                      <div className="text-xs font-semibold" style={{ color: d.c.startsWith("+") || d.c.startsWith("-") ? t.accent : t.textMuted }}>{d.c}</div>
                    </div>
                  ))}
                </div>

                {/* Fake Chart */}
                <div className="mb-4">
                  <div className="text-xs font-bold mb-3 uppercase tracking-wider" style={{ color: t.textMuted }}>Anfragen-Trend (8 Wochen)</div>
                  <div className="flex items-end gap-2" style={{ height: 120 }}>
                    {[45, 62, 55, 78, 85, 72, 90, 95].map((h, i) => (
                      <div key={i} className="flex-1 rounded-t-md transition-all duration-500" style={{ height: `${h}%`, background: i === 7 ? t.accent : t.accentLight }} />
                    ))}
                  </div>
                  <div className="flex justify-between mt-2 text-xs" style={{ color: t.textLight }}>
                    <span>KW 5</span><span>KW 6</span><span>KW 7</span><span>KW 8</span><span>KW 9</span><span>KW 10</span><span>KW 11</span><span>KW 12</span>
                  </div>
                </div>

                <div className="pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                  <div className="text-xs font-bold mb-3 uppercase tracking-wider" style={{ color: t.textMuted }}>Plattform-Performance</div>
                  {[
                    { name: "willhaben.at", pct: 42 },
                    { name: "SR-Homes Website", pct: 28 },
                    { name: "immowelt.at", pct: 18 },
                    { name: "ImmobilienScout24", pct: 12 },
                  ].map((p, i) => (
                    <div key={i} className="mb-3">
                      <div className="flex justify-between text-xs mb-1">
                        <span style={{ color: t.textSecondary }}>{p.name}</span>
                        <span className="font-bold" style={{ color: t.text }}>{p.pct}%</span>
                      </div>
                      <div className="h-2 rounded-full overflow-hidden" style={{ background: t.bgAlt }}>
                        <div className="h-full rounded-full transition-all duration-1000" style={{ width: `${p.pct}%`, background: i === 0 ? t.accent : `${t.accent}${60 - i * 15}` }} />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── PROCESS / HOW WE WORK ─────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bgAlt }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="text-center mb-20">
            <Eyebrow t={t}>Unser Prozess</Eyebrow>
            <h2 className="font-display section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
              In vier Schritten zum Erfolg
            </h2>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            {[
              { n: "01", title: "Erstberatung", desc: "Persönliches Kennenlernen, Vor-Ort-Besichtigung und gemeinsame Preisfindung auf Basis unserer Marktdaten.", icon: Users },
              { n: "02", title: "Aufbereitung", desc: "Professionelle Fotografie, virtuelle Rundgänge, Premium-Exposé, Beschaffung aller Unterlagen.", icon: FileText },
              { n: "03", title: "Vermarktung", desc: "Multi-Plattform-Strategie, qualifizierte Interessenten, Besichtigungsorganisation. Alles live im Portal.", icon: Globe },
              { n: "04", title: "Abschluss", desc: "Kaufvertragsverhandlung, notarielle Begleitung, Übergabe mit Protokoll. Ihr Makler bis zum letzten Schritt.", icon: CheckCircle },
            ].map((s, i) => (
              <div key={i} className="hover-lift p-8 rounded-2xl relative" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                <span className="text-6xl font-black tracking-tighter absolute top-4 right-6" style={{ color: t.accentLight }}>{s.n}</span>
                <div className="relative">
                  <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-6" style={{ background: t.accentLight }}>
                    <s.icon size={24} style={{ color: t.accent }} />
                  </div>
                  <h3 className="text-xl font-bold tracking-tight mb-3" style={{ color: t.text }}>{s.title}</h3>
                  <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{s.desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── TEAM TEASER ────────────────────────── */}
      <section className="relative" style={{ height: 600 }}>
        <img src={ASSETS.teamImage} alt="SR-Homes Team" className="absolute inset-0 w-full h-full object-cover" />
        <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.9) 0%, rgba(10,10,8,0.4) 60%, rgba(10,10,8,0.2) 100%)" }} />
        <div className="relative z-10 h-full flex items-center">
          <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
            <div className="max-w-lg">
              <Eyebrow t={{ ...t, accent: "#E8743A" }}>Unser Team</Eyebrow>
              <h2 className="font-display text-3xl md:text-4xl lg:text-5xl xl:text-6xl font-bold text-white tracking-tight leading-none mb-6">
                Menschen, die den Unterschied machen.
              </h2>
              <p className="text-base text-white/50 leading-relaxed mb-8">
                Maximilian Hölzl und sein Team verbinden jahrelange Erfahrung mit Leidenschaft für Immobilien. Persönlich, kompetent, engagiert.
              </p>
              <Btn primary large icon={ArrowUpRight} onClick={() => setPage("über")}>Team kennenlernen</Btn>
            </div>
          </div>
        </div>
      </section>

      {/* ── TESTIMONIAL / TRUST ────────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="max-w-4xl mx-auto text-center">
            <div className="flex justify-center gap-1 mb-8">
              {[1,2,3,4,5].map((s) => <Star key={s} size={24} fill={t.accent} style={{ color: t.accent }} />)}
            </div>
            <blockquote className="font-display text-2xl md:text-4xl font-medium leading-snug mb-8" style={{ color: t.text, fontStyle: "italic" }}>
              "Die Zusammenarbeit mit SR-Homes war herausragend. Vom ersten Gespräch bis zur Übergabe fühlen wir uns perfekt betreut. Das Kundenportal ist ein Game-Changer — wir konnten jeden Schritt live mitverfolgen."
            </blockquote>
            <div className="text-sm font-semibold" style={{ color: t.text }}>Familie Steinberger</div>
            <div className="text-xs" style={{ color: t.textMuted }}>Verkauf Einfamilienhaus, Salzburg</div>
          </div>
        </div>
      </section>

      {/* ── CTA SECTION ────────────────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bgAlt }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="relative rounded-3xl overflow-hidden" style={{ minHeight: 500 }}>
            <img src={ASSETS.contactImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
            <div className="absolute inset-0" style={{ background: "linear-gradient(135deg, rgba(10,10,8,0.92) 0%, rgba(10,10,8,0.6) 100%)" }} />
            <div className="relative z-10 flex flex-col items-center justify-center text-center px-8 py-24">
              <h2 className="font-display text-3xl md:text-4xl lg:text-5xl xl:text-7xl font-bold text-white tracking-tight leading-none mb-6">
                Bereit für den<br/><span style={{ color: "#E8743A" }}>nächsten Schritt?</span>
              </h2>
              <p className="text-lg text-white/45 max-w-xl mb-10 leading-relaxed">
                Vereinbaren Sie Ihr kostenloses Erstgespräch. Wir nehmen uns Zeit für Ihre Fragen und entwickeln gemeinsam die beste Strategie.
              </p>
              <div className="flex flex-wrap gap-4 justify-center">
                <Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Erstgespräch vereinbaren</Btn>
                <Btn large icon={Phone} onClick={() => window.open("tel:+436642600930")} style={{ color: "#fff", borderColor: "rgba(255,255,255,0.2)" }}>+43 664 2600 930</Btn>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
};
