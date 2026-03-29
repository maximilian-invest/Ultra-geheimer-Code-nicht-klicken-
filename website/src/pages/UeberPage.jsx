import { Award, Zap, Shield, Eye } from "lucide-react";
import { ASSETS } from "../config.js";
import { Eyebrow } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// UEBER UNS PAGE
// ═══════════════════════════════════════════════════════════════════
export const ÜberPage = ({ setPage, t }) => (
  <div className="pt-20">
    <section className="relative" style={{ height: "70vh", minHeight: 500 }}>
      <img src={ASSETS.teamImage} alt="SR-Homes Team" className="absolute inset-0 w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to top, rgba(10,10,8,0.95) 0%, rgba(10,10,8,0.3) 50%, rgba(10,10,8,0.1) 100%)" }} />
      <div className="relative z-10 h-full flex items-end pb-16">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
          <Eyebrow t={{ accent: "#E8743A" }}>Über uns</Eyebrow>
          <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
            Zuverlässig. Modern.<br/>Transparent.
          </h1>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-16">
          <div>
            <h2 className="font-display text-3xl md:text-4xl font-bold tracking-tight mb-8" style={{ color: t.text, letterSpacing: "-0.02em" }}>
              Authentisch, freundlich und zielorientiert
            </h2>
            <div className="space-y-6 text-base leading-relaxed" style={{ color: t.textSecondary }}>
              <p>Wir verbinden fundiertes Immobilienwissen mit modernen Informationskomponenten und ausgezeichneter Marktkenntnis. Es ist unser Anspruch, Ihnen eine moderne Informationsplattform gepaart mit durchdachtem Rundum-Service anzubieten.</p>
              <p>Unsere Philosophie: Sie verantwortungsbewusst, zuverlässig und mit höchster Sorgfalt beraten. Zusammen lassen sich die besten Ergebnisse erzielen.</p>
              <p>Unser Ziel ist es, dass Sie sich entspannt zurücklehnen können. Wir haben es uns zur Aufgabe gemacht, Ihnen alle wesentlichen Schritte abzunehmen, um Sie sicher über die Ziellinie zu geleiten.</p>
            </div>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
            {[
              { icon: Award, title: "Erfahrung & Kompetenz", desc: "Fachlich ausgebildetes Team mit ständiger Weiterbildung und regionaler Marktkenntnis." },
              { icon: Zap, title: "Moderne Technologie", desc: "KI-gestützte Analysen, digitales Kundenportal und datengetriebene Vermarktungsstrategien." },
              { icon: Shield, title: "Sicherheit & Vertrauen", desc: "Umfangreiche Recherche, lückenlose Dokumentation und rechtssichere Abwicklung." },
              { icon: Eye, title: "Volle Transparenz", desc: "24/7 Einblick in den Vermarktungsstand durch unser einzigartiges Kundenportal." },
            ].map((v, i) => (
              <div key={i} className="hover-lift p-7 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                <div className="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style={{ background: t.accentLight }}><v.icon size={20} style={{ color: t.accent }} /></div>
                <h3 className="text-base font-bold mb-2" style={{ color: t.text }}>{v.title}</h3>
                <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{v.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bgAlt }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="text-center mb-16">
          <Eyebrow t={t}>Unser Team</Eyebrow>
          <h2 className="font-display" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, letterSpacing: "-0.03em" }}>Die Menschen hinter SR-Homes</h2>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-3xl mx-auto">
          {[
            { name: "Maximilian Hölzl", role: "Geschäftsführer\nKonzessionierter Immobilientreuhänder", phone: "+43 664 2600 930", email: "hoelzl@sr-homes.at", initials: "MH" },
            { name: "Bernhard Hölzl", role: "Immobilienberater", phone: "+43 676 8526 77 200", email: "b.hoelzl@sr-homes.at", initials: "BH" },
          ].map((m, i) => (
            <div key={i} className="hover-lift p-10 rounded-2xl text-center" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <div className="w-24 h-24 rounded-3xl mx-auto flex items-center justify-center text-3xl font-bold text-white mb-6" style={{ background: t.accent }}>{m.initials}</div>
              <h3 className="text-xl font-bold" style={{ color: t.text }}>{m.name}</h3>
              <p className="text-sm mt-1 mb-6 whitespace-pre-line" style={{ color: t.textMuted }}>{m.role}</p>
              <div className="flex flex-col items-center gap-2">
                <a href={`tel:${m.phone.replace(/\s/g, "")}`} className="text-sm font-semibold" style={{ color: t.accent }}>{m.phone}</a>
                <a href={`mailto:${m.email}`} className="text-sm" style={{ color: t.textSecondary }}>{m.email}</a>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  </div>
);
