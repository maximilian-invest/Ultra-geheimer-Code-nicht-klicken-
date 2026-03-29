import { DEFAULT_CMS } from "../config.js";
import { Eyebrow } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// DATENSCHUTZ PAGE
// ═══════════════════════════════════════════════════════════════════
export const DatenschutzPage = ({ t, cms = DEFAULT_CMS }) => {
  const legal = cms.legal || {};
  return (
    <div className="pt-20">
      <section className="py-20 md:py-28" style={{ background: t.bg }}>
        <div className="max-w-3xl mx-auto px-6 md:px-12">
          <Eyebrow t={t}>Rechtliches</Eyebrow>
          <h1 className="font-display text-4xl md:text-5xl font-bold mb-12" style={{ color: t.text, letterSpacing: "-0.03em" }}>Datenschutzerklärung</h1>

          {legal.datenschutz_html ? (
            <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <div className="prose prose-sm max-w-none" style={{ color: t.textSecondary }} dangerouslySetInnerHTML={{ __html: legal.datenschutz_html }} />
            </div>
          ) : (
            <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <p style={{ color: t.textMuted }}>Die Datenschutzerklärung wird derzeit aktualisiert. Bei Fragen wenden Sie sich bitte an:</p>
              <p className="mt-4 font-semibold" style={{ color: t.text }}>{(cms.contact || DEFAULT_CMS.contact).email}</p>
            </div>
          )}
        </div>
      </section>
    </div>
  );
};
