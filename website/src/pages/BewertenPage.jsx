import { Home, BarChart3, FileText, ArrowUpRight } from "lucide-react";
import { ASSETS } from "../config.js";
import { Eyebrow, Btn } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// BEWERTEN PAGE
// ═══════════════════════════════════════════════════════════════════
export const BewertenPage = ({ setPage, t }) => (
  <div className="pt-20">
    <section className="relative" style={{ height: "70vh", minHeight: 500 }}>
      <img src={ASSETS.valueImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.9) 0%, rgba(10,10,8,0.3) 100%)" }} />
      <div className="relative z-10 h-full flex items-center">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
          <div className="max-w-2xl">
            <Eyebrow t={{ accent: "#E8743A" }}>Bewertung</Eyebrow>
            <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
              Was ist Ihre Immobilie wirklich wert?
            </h1>
            <p className="text-lg text-white/50 mt-6 max-w-lg leading-relaxed">Keine Mondpreise. Sondern eine ehrliche, datenbasierte Bewertung auf Grundlage aktueller Marktdaten und unserer lokalen Expertise.</p>
            <div className="mt-10"><Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Kostenlose Bewertung</Btn></div>
          </div>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {[
            { n: "01", title: "Vor-Ort Besichtigung", desc: "Wir kommen zu Ihnen, begutachten die Immobilie und erfassen alle wertrelevanten Faktoren: Zustand, Lage, Ausstattung, Potential.", icon: Home },
            { n: "02", title: "Datengestützte Analyse", desc: "Vergleichspreise, EZB-Zinsen, regionale Trends — unsere Marktintelligenz-Plattform liefert die Basis für eine präzise Einschätzung.", icon: BarChart3 },
            { n: "03", title: "Bewertungsbericht", desc: "Sie erhalten einen transparenten Bewertungsbericht mit nachvollziehbarer Herleitung. Die perfekte Basis für Ihre Entscheidung.", icon: FileText },
          ].map((s, i) => (
            <div key={i} className="hover-lift p-10 rounded-2xl relative" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <span className="text-7xl font-black absolute top-4 right-6" style={{ color: t.accentLight }}>{s.n}</span>
              <div className="relative">
                <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-6" style={{ background: t.accentLight }}><s.icon size={24} style={{ color: t.accent }} /></div>
                <h3 className="text-xl font-bold tracking-tight mb-3" style={{ color: t.text }}>{s.title}</h3>
                <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{s.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  </div>
);
