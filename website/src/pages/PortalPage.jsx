import {
  Lock, ArrowRight, Eye, Activity, Star, LineChart, FileText, MessageSquare
} from "lucide-react";
import { ASSETS, PROPERTIES, DEFAULT_CMS } from "../config.js";
import { Eyebrow, Btn } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// PORTAL PAGE (Customer Benefits Focus)
// ═══════════════════════════════════════════════════════════════════
export const PortalPage = ({ setPage, t, cms = DEFAULT_CMS }) => (
  <div className="pt-20">
    <section className="relative py-28 md:py-40" style={{ background: "#0A0A08" }}>
      <div className="absolute inset-0 opacity-30" style={{ background: `radial-gradient(ellipse 80% 50% at 60% 40%, ${"#D4622B"}20, transparent)` }} />
      <div className="relative z-10 max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="max-w-3xl">
          <Eyebrow t={{ accent: "#E8743A" }}>Kundenportal</Eyebrow>
          <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
            Nie wieder im Dunkeln tappen.
          </h1>
          <p className="text-xl text-white/45 mt-6 max-w-xl leading-relaxed">
            Unser digitales Kundenportal gibt Ihnen als Eigentümer volle Kontrolle und Transparenz über die Vermarktung Ihrer Immobilie. 24 Stunden am Tag, 7 Tage die Woche.
          </p>
          <div className="flex flex-wrap gap-4 mt-10">
            <Btn primary large icon={Lock} onClick={() => window.open("https://kundenportal.sr-homes.at/portal", "_blank")}>Zum Portal</Btn>
            <Btn large icon={ArrowRight} onClick={() => setPage("kontakt")} style={{ color: "#fff", borderColor: "rgba(255,255,255,0.2)" }}>Demo anfragen</Btn>
          </div>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="text-center mb-20">
          <h2 className="font-display" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, letterSpacing: "-0.03em" }}>Was Sie als Kunde davon haben</h2>
          <p className="text-lg mt-4 max-w-2xl mx-auto" style={{ color: t.textSecondary }}>Kein anderer Makler in der Region bietet Ihnen diese Art von Einblick.</p>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {[
            { icon: Eye, title: "Sehen Sie jede Anfrage in Echtzeit", desc: "Sobald ein Interessent Ihre Immobilie kontaktiert, sehen Sie es im Portal. Wer hat angefragt, wann, über welche Plattform — und wie wir darauf reagiert haben. Kein Warten auf den nächsten Statusbericht.", metric: "Ø 2h", metricLabel: "Reaktionszeit" },
            { icon: Activity, title: "Verfolgen Sie den Vermarktungsfortschritt", desc: "Vom ersten Inserat bis zum Kaufanbot: Sehen Sie den kompletten Trichter — Anfragen, geplante Besichtigungen, durchgeführte Besichtigungen, Angebote. Alles in übersichtlichen Grafiken.", metric: "192", metricLabel: "Anfragen getrackt" },
            { icon: Star, title: "Lesen Sie echtes Interessenten-Feedback", desc: "Nach jeder Besichtigung erfassen wir das Feedback des Interessenten: Was hat gefallen? Was waren Bedenken? Was könnte den Preis beeinflussen? Sie wissen immer, wie Ihre Immobilie wahrgenommen wird.", metric: "100%", metricLabel: "Dokumentiert" },
            { icon: LineChart, title: "Verstehen Sie den Markt mit harten Daten", desc: "Unser Marktbericht zeigt Ihnen die aktuelle Preisentwicklung, EZB-Leitzins-Verlauf und Vergleichsobjekte in Ihrer Region. Keine Meinungen — nur verifizierte Marktdaten als Entscheidungsgrundlage.", metric: "Live", metricLabel: "Marktdaten" },
            { icon: FileText, title: "Alle Dokumente an einem Ort", desc: "Exposé, Grundriss, Energieausweis, Kaufanbote, Bewertungsbericht — alles sicher gespeichert und jederzeit zum Download bereit. Kein Suchen in alten Emails.", metric: "Sicher", metricLabel: "Archiviert" },
            { icon: MessageSquare, title: "Kommunizieren Sie direkt mit Ihrem Makler", desc: "Stellen Sie Fragen, teilen Sie Informationen, besprechen Sie nächste Schritte — alles über das sichere Portal-Messaging. Keine wichtigen Infos gehen in der Email-Flut verloren.", metric: "Direkt", metricLabel: "Ohne Umwege" },
          ].map((f, i) => (
            <div key={i} className="hover-lift hover-glow p-8 rounded-2xl flex flex-col md:flex-row gap-6" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <div className="shrink-0">
                <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-3" style={{ background: t.accentLight }}><f.icon size={24} style={{ color: t.accent }} /></div>
                <div className="text-2xl font-black" style={{ color: t.accent }}>{f.metric}</div>
                <div className="text-xs" style={{ color: t.textMuted }}>{f.metricLabel}</div>
              </div>
              <div>
                <h3 className="text-lg font-bold tracking-tight mb-2" style={{ color: t.text }}>{f.title}</h3>
                <p className="text-sm leading-relaxed" style={{ color: t.textSecondary }}>{f.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>

    {/* Portal Preview Mockup */}
    <section className="py-24" style={{ background: "#0A0A08" }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="text-center mb-14">
          <h2 className="font-display text-3xl md:text-4xl font-bold text-white tracking-tight">So sieht Ihr Portal aus</h2>
        </div>
        <div className="max-w-4xl mx-auto rounded-2xl overflow-hidden" style={{ border: "1px solid rgba(255,255,255,0.08)" }}>
          <div className="flex items-center gap-2 px-5 py-3" style={{ background: "rgba(255,255,255,0.03)", borderBottom: "1px solid rgba(255,255,255,0.05)" }}>
            <div className="flex gap-2"><div className="w-3 h-3 rounded-full" style={{ background: "#FF5F57" }} /><div className="w-3 h-3 rounded-full" style={{ background: "#FEBC2E" }} /><div className="w-3 h-3 rounded-full" style={{ background: "#28C840" }} /></div>
            <div className="flex-1 mx-6 px-4 py-1.5 rounded-lg text-xs text-white/25" style={{ background: "rgba(255,255,255,0.04)" }}>kundenportal.sr-homes.at/portal</div>
          </div>
          <div className="p-6 md:p-8" style={{ background: "rgba(255,255,255,0.02)" }}>
            <div className="flex items-center gap-4 mb-6">
              <img src={ASSETS.logoWhite} alt="" style={{ height: 24, opacity: 0.5 }} />
              <div><div className="text-sm font-semibold text-white">Willkommen zurück, Herr Steinberger</div><div className="text-xs text-white/30">3 Objekte in Vermarktung</div></div>
            </div>
            <div className="grid grid-cols-4 gap-3 mb-6">
              {[{ n: "12", l: "Anfragen", c: "+3 neu" }, { n: "4", l: "Besichtigungen", c: "2 geplant" }, { n: "2", l: "Kaufanbote", c: "1 neu" }, { n: "EUR 52M", l: "Marktvolumen", c: "Region" }].map((s, i) => (
                <div key={i} className="p-4 rounded-xl" style={{ background: "rgba(255,255,255,0.03)", border: "1px solid rgba(255,255,255,0.05)" }}>
                  <div className="text-2xl font-bold text-white">{s.n}</div>
                  <div className="text-xs text-white/30">{s.l}</div>
                  <div className="text-xs font-semibold mt-1" style={{ color: "#E8743A" }}>{s.c}</div>
                </div>
              ))}
            </div>
            <div className="space-y-2">
              {PROPERTIES.slice(0, 3).map((p, i) => (
                <div key={i} className="flex items-center justify-between p-4 rounded-xl" style={{ background: "rgba(255,255,255,0.02)", border: "1px solid rgba(255,255,255,0.04)" }}>
                  <div className="flex items-center gap-3">
                    <div className="w-2 h-2 rounded-full" style={{ background: ["#28C840", "#E8743A", "#FEBC2E"][i] }} />
                    <span className="text-sm text-white/50">{p.address}, {p.city}</span>
                  </div>
                  <span className="text-xs font-semibold" style={{ color: ["#28C840", "#E8743A", "#FEBC2E"][i] }}>{["Inserat live", "Besichtigungen", "Angebote"][i]}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
);
