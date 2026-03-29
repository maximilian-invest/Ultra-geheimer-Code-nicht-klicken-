import { MapPin, Phone, Mail, Clock } from "lucide-react";
import { ASSETS, DEFAULT_CMS } from "../config.js";

// ═══════════════════════════════════════════════════════════════════
// FOOTER
// ═══════════════════════════════════════════════════════════════════
export const Footer = ({ setPage, t, cms = DEFAULT_CMS, logos }) => {
  const contact = cms.contact || DEFAULT_CMS.contact;
  return (
  <footer style={{ background: "#0A0A08" }}>
    {/* Marquee */}
    <div className="overflow-hidden py-10" style={{ borderBottom: "1px solid rgba(255,255,255,0.05)" }}>
      <div className="marquee-track whitespace-nowrap">
        {Array(4).fill(null).map((_, i) => (
          <span key={i} className="text-6xl md:text-8xl font-black tracking-tighter mx-8" style={{ color: "rgba(255,255,255,0.03)", WebkitTextStroke: "1px rgba(255,255,255,0.06)" }}>
            SR-HOMES IMMOBILIEN
          </span>
        ))}
      </div>
    </div>

    <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 py-20 md:py-28">
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-12">
        <div className="lg:col-span-4">
          <img src={logos?.white || ASSETS.logoWhite} alt="SR-Homes" className="mb-6" style={{ height: 32 }} />
          <p className="text-sm leading-relaxed text-white/40 max-w-sm mb-8">
            {cms.seo?.meta_description || "Ihr vertrauensvoller Partner für hochwertige Immobilien in Salzburg und Oberösterreich. Professionell, transparent, technologisch führend."}
          </p>
          <div className="flex gap-3">
            {["LI", "IG", "FB"].map((s) => (
              <div key={s} className="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold text-white/30 transition-all hover:text-white cursor-pointer" style={{ border: "1px solid rgba(255,255,255,0.08)" }}>{s}</div>
            ))}
          </div>
        </div>

        <div className="lg:col-span-2">
          <h4 className="text-xs font-bold uppercase tracking-[0.2em] text-white/25 mb-6">Immobilien</h4>
          {["Kaufen", "Mieten", "Neubauprojekte", "Grundstücke"].map((l) => (
            <button key={l} onClick={() => setPage("immobilien")} className="block text-sm text-white/40 hover:text-white transition-colors mb-3">{l}</button>
          ))}
        </div>

        <div className="lg:col-span-2">
          <h4 className="text-xs font-bold uppercase tracking-[0.2em] text-white/25 mb-6">Services</h4>
          {[["verkaufen","Verkaufen"],["bewerten","Bewerten"],["portal","Kundenportal"]].map(([k,l]) => (
            <button key={k} onClick={() => setPage(k)} className="block text-sm text-white/40 hover:text-white transition-colors mb-3">{l}</button>
          ))}
        </div>

        <div className="lg:col-span-4">
          <h4 className="text-xs font-bold uppercase tracking-[0.2em] text-white/25 mb-6">Kontakt</h4>
          <div className="space-y-4 text-sm text-white/40">
            <div className="flex items-start gap-3"><MapPin size={15} style={{ color: "#E8743A" }} className="mt-0.5 shrink-0" /><span dangerouslySetInnerHTML={{ __html: (contact.address || "").replace(/\n/g, "<br/>") }} /></div>
            <div className="flex items-center gap-3"><Phone size={15} style={{ color: "#E8743A" }} className="shrink-0" /><span>{contact.phone}</span></div>
            <div className="flex items-center gap-3"><Mail size={15} style={{ color: "#E8743A" }} className="shrink-0" /><span>{contact.email}</span></div>
            <div className="flex items-center gap-3"><Clock size={15} style={{ color: "#E8743A" }} className="shrink-0" /><span>{contact.hours}</span></div>
          </div>
        </div>
      </div>

      <div className="mt-20 pt-8 flex flex-col md:flex-row justify-between gap-4" style={{ borderTop: "1px solid rgba(255,255,255,0.05)" }}>
        <span className="text-xs text-white/20">
          {(cms.legal && cms.legal.company_name) || "SR-Homes GmbH"} | {(cms.legal && cms.legal.fn_number) || "FN 4556571 i"} | {(cms.legal && cms.legal.uid_number) || "ATU 71268923"} | {(cms.legal && cms.legal.trade_license) || "Konzessionierter Immobilientreuhänder"}
        </span>
        <span className="text-xs text-white/20 flex gap-3">
          <button onClick={() => setPage("impressum")} className="cursor-pointer text-white/40 hover:text-white/70 transition-colors underline">Impressum</button>
          <button onClick={() => setPage("datenschutz")} className="cursor-pointer text-white/40 hover:text-white/70 transition-colors underline">Datenschutz</button>
          <span>© 2026 SR-Homes</span>
        </span>
      </div>
    </div>
  </footer>
  );
};
