import { Phone, Mail } from "lucide-react";
import { DEFAULT_CMS } from "../config.js";
import { Eyebrow } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// IMPRESSUM PAGE
// ═══════════════════════════════════════════════════════════════════
export const ImpressumPage = ({ t, cms = DEFAULT_CMS }) => {
  const legal = cms.legal || {};
  return (
    <div className="pt-20">
      <section className="py-20 md:py-28" style={{ background: t.bg }}>
        <div className="max-w-3xl mx-auto px-6 md:px-12">
          <Eyebrow t={t}>Rechtliches</Eyebrow>
          <h1 className="font-display text-4xl md:text-5xl font-bold mb-12" style={{ color: t.text, letterSpacing: "-0.03em" }}>Impressum</h1>

          <div className="space-y-8">
            <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <h2 className="text-xl font-bold mb-6" style={{ color: t.text }}>Angaben gemäß § 5 ECG</h2>
              <div className="space-y-4 text-sm leading-relaxed" style={{ color: t.textSecondary }}>
                <p className="text-lg font-bold" style={{ color: t.text }}>{legal.company_name || "SR-Homes Immobilien GmbH"}</p>
                <p>{(cms.contact || DEFAULT_CMS.contact).address}</p>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                  <div><span className="text-xs font-bold uppercase tracking-widest block mb-1" style={{ color: t.textMuted }}>Firmenbuchnummer</span>{legal.fn_number || "FN 4556571 i"}</div>
                  <div><span className="text-xs font-bold uppercase tracking-widest block mb-1" style={{ color: t.textMuted }}>UID-Nr.</span>{legal.uid_number || "ATU 71268923"}</div>
                </div>
                {legal.ceo_name && (
                  <div className="pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                    <span className="text-xs font-bold uppercase tracking-widest block mb-1" style={{ color: t.textMuted }}>Geschäftsführer</span>{legal.ceo_name}
                  </div>
                )}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                  <div><span className="text-xs font-bold uppercase tracking-widest block mb-1" style={{ color: t.textMuted }}>Firmenbuchgericht</span>{legal.court || "Landesgericht Salzburg"}</div>
                  <div><span className="text-xs font-bold uppercase tracking-widest block mb-1" style={{ color: t.textMuted }}>Gewerbe</span>{legal.trade_license || "Konzessionierter Immobilientreuhänder"}</div>
                </div>
                {legal.authority && (
                  <div className="pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                    <span className="text-xs font-bold uppercase tracking-widest block mb-1" style={{ color: t.textMuted }}>Aufsichtsbehörde</span>{legal.authority}
                  </div>
                )}
              </div>
            </div>

            <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <h2 className="text-xl font-bold mb-4" style={{ color: t.text }}>Kontakt</h2>
              <div className="space-y-3 text-sm" style={{ color: t.textSecondary }}>
                <div className="flex items-center gap-3"><Phone size={15} style={{ color: t.accent }} /><a href={`tel:${((cms.contact || DEFAULT_CMS.contact).phone || "").replace(/\s/g, "")}`}>{(cms.contact || DEFAULT_CMS.contact).phone}</a></div>
                <div className="flex items-center gap-3"><Mail size={15} style={{ color: t.accent }} /><a href={`mailto:${(cms.contact || DEFAULT_CMS.contact).email}`}>{(cms.contact || DEFAULT_CMS.contact).email}</a></div>
              </div>
            </div>

            {legal.impressum_extra && (
              <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                <div className="prose prose-sm max-w-none" style={{ color: t.textSecondary }} dangerouslySetInnerHTML={{ __html: legal.impressum_extra }} />
              </div>
            )}
          </div>
        </div>
      </section>
    </div>
  );
};
