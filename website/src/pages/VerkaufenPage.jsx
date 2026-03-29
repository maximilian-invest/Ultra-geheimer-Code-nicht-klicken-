import { ArrowUpRight } from "lucide-react";
import { ASSETS, PROPERTIES } from "../config.js";
import { Eyebrow, Btn } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// VERKAUFEN PAGE
// ═══════════════════════════════════════════════════════════════════
export const VerkaufenPage = ({ setPage, t, properties = PROPERTIES }) => (
  <div className="pt-20">
    <section className="relative" style={{ height: "70vh", minHeight: 500 }}>
      <img src={ASSETS.sellImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.9) 0%, rgba(10,10,8,0.3) 100%)" }} />
      <div className="relative z-10 h-full flex items-center">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
          <div className="max-w-2xl">
            <Eyebrow t={{ accent: "#E8743A" }}>Verkaufen</Eyebrow>
            <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
              Ihre Immobilie verdient den besten Preis.
            </h1>
            <p className="text-lg text-white/50 mt-6 max-w-lg leading-relaxed">Mit unserem 14-Punkte Rundum-Service, datengestützter Marktanalyse und persönlichem Engagement erzielen wir für Sie das optimale Ergebnis.</p>
            <div className="mt-10"><Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Kostenlose Erstberatung</Btn></div>
          </div>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="text-center mb-16">
          <h2 className="font-display" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, letterSpacing: "-0.03em" }}>14 Leistungen. Ein Paket.</h2>
          <p className="text-base mt-4 max-w-xl mx-auto" style={{ color: t.textSecondary }}>Alles was Sie für einen erfolgreichen, stressfreien Verkauf brauchen.</p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[
            "Professionelle Bewertung Ihrer Immobilie",
            "Beschaffung aller Objektunterlagen",
            "Professionelle Fotoaufnahmen und Drohnenbilder",
            "Verkauf zum bestmöglichen Marktpreis",
            "Inserate auf allen relevanten Plattformen",
            "Organisation des Energieausweises",
            "Aufbereitung aller Grundrisse",
            "Hochwertiges Exposé in Print und Digital",
            "Verkaufsschilder und Banner auf Wunsch",
            "Social Media Marketing (Facebook, Instagram)",
            "Prüfung der Finanzierung des Interessenten",
            "Führung von Kaufpreisverhandlungen",
            "Kaufvertragsbesprechung und notarielle Begleitung",
            "Begleitung der Übergabe mit Protokoll",
          ].map((s, i) => (
            <div key={i} className="flex items-center gap-5 p-6 rounded-xl hover-lift" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <div className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-sm font-black" style={{ background: t.accentLight, color: t.accent }}>{String(i + 1).padStart(2, "0")}</div>
              <span className="text-sm font-semibold" style={{ color: t.text }}>{s}</span>
            </div>
          ))}
        </div>
        <div className="text-center mt-16"><Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Jetzt Verkauf starten</Btn></div>
      </div>
    </section>
  </div>
);
