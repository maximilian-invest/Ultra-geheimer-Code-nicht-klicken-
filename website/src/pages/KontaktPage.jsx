import { MapPin, Phone, Mail, Clock, ArrowUpRight } from "lucide-react";
import { ASSETS, DEFAULT_CMS } from "../config.js";
import { Eyebrow, Btn } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// KONTAKT PAGE
// ═══════════════════════════════════════════════════════════════════
export const KontaktPage = ({ t, cms = DEFAULT_CMS }) => (
  <div className="pt-20">
    <section className="relative" style={{ height: "50vh", minHeight: 400 }}>
      <img src={ASSETS.contactImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to top, rgba(10,10,8,0.95) 0%, rgba(10,10,8,0.4) 50%, rgba(10,10,8,0.2) 100%)" }} />
      <div className="relative z-10 h-full flex items-end pb-16">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
          <Eyebrow t={{ accent: "#E8743A" }}>Kontakt</Eyebrow>
          <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
            Sprechen wir darüber.
          </h1>
        </div>
      </div>
    </section>

    <section className="py-20 md:py-28" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="grid grid-cols-1 lg:grid-cols-5 gap-16">
          <div className="lg:col-span-3">
            <div className="p-8 md:p-12 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <h2 className="text-2xl font-bold mb-8" style={{ color: t.text }}>Nachricht senden</h2>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
                {[["Vorname","text"],["Nachname","text"]].map(([l,ty]) => (
                  <div key={l}><label className="text-xs font-bold uppercase tracking-widest mb-2 block" style={{ color: t.textMuted }}>{l}</label><input type={ty} className="w-full px-5 py-4 rounded-xl text-sm border font-medium" style={{ borderColor: t.border, background: t.bgAlt, color: t.text }} /></div>
                ))}
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
                {[["Email","email"],["Telefon","tel"]].map(([l,ty]) => (
                  <div key={l}><label className="text-xs font-bold uppercase tracking-widest mb-2 block" style={{ color: t.textMuted }}>{l}</label><input type={ty} className="w-full px-5 py-4 rounded-xl text-sm border font-medium" style={{ borderColor: t.border, background: t.bgAlt, color: t.text }} /></div>
                ))}
              </div>
              <div className="mb-5">
                <label className="text-xs font-bold uppercase tracking-widest mb-2 block" style={{ color: t.textMuted }}>Betreff</label>
                <select className="w-full px-5 py-4 rounded-xl text-sm border font-medium" style={{ borderColor: t.border, background: t.bgAlt, color: t.text }}>
                  {["Allgemeine Anfrage", "Immobilie verkaufen", "Immobilie vermieten", "Bewertung anfragen", "Interesse an einem Objekt", "Kundenportal Demo"].map(o => <option key={o}>{o}</option>)}
                </select>
              </div>
              <div className="mb-8">
                <label className="text-xs font-bold uppercase tracking-widest mb-2 block" style={{ color: t.textMuted }}>Nachricht</label>
                <textarea rows={6} className="w-full px-5 py-4 rounded-xl text-sm border resize-none font-medium" style={{ borderColor: t.border, background: t.bgAlt, color: t.text }} />
              </div>
              <Btn primary large icon={ArrowUpRight}>Nachricht senden</Btn>
            </div>
          </div>

          <div className="lg:col-span-2 space-y-6">
            <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <h3 className="text-lg font-bold mb-6" style={{ color: t.text }}>Kontaktdaten</h3>
              <div className="space-y-6">
                {[
                  { icon: MapPin, label: "Adresse", value: (cms.contact || DEFAULT_CMS.contact).address },
                  { icon: Phone, label: "Telefon", value: (cms.contact || DEFAULT_CMS.contact).phone, href: `tel:${((cms.contact || DEFAULT_CMS.contact).phone || "").replace(/\s/g, "")}` },
                  { icon: Mail, label: "Email", value: (cms.contact || DEFAULT_CMS.contact).email, href: `mailto:${(cms.contact || DEFAULT_CMS.contact).email}` },
                  { icon: Clock, label: "Bürozeiten", value: (cms.contact || DEFAULT_CMS.contact).hours },
                ].map((c, i) => (
                  <div key={i} className="flex items-start gap-4">
                    <div className="w-11 h-11 rounded-xl flex items-center justify-center shrink-0" style={{ background: t.accentLight }}><c.icon size={18} style={{ color: t.accent }} /></div>
                    <div>
                      <div className="text-sm font-bold" style={{ color: t.text }}>{c.label}</div>
                      {c.href ? <a href={c.href} className="text-sm whitespace-pre-line" style={{ color: t.textSecondary }}>{c.value}</a> : <div className="text-sm whitespace-pre-line" style={{ color: t.textSecondary }}>{c.value}</div>}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <h3 className="text-lg font-bold mb-4" style={{ color: t.text }}>Rechtliches</h3>
              <div className="space-y-2 text-sm" style={{ color: t.textMuted }}>
                <p>SR-Homes GmbH</p><p>FN 4556571 i</p><p>ATU 71268923</p>
                <p>Konzessionierter Immobilientreuhänder</p><p>Mitglied der WKO Salzburg</p>
              </div>
            </div>

            <div className="p-8 rounded-2xl" style={{ background: t.accent }}>
              <h3 className="text-lg font-bold text-white mb-2">Lieber direkt sprechen?</h3>
              <p className="text-sm text-white/60 mb-5">Rufen Sie uns an — wir nehmen uns Zeit für Sie.</p>
              <a href="tel:+436642600930" className="inline-flex items-center gap-2 text-white font-bold text-lg"><Phone size={18} /> +43 664 2600 930</a>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
);
