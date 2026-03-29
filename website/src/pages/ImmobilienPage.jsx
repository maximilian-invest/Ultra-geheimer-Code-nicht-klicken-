import { useState } from "react";
import { Grid3X3, List } from "lucide-react";
import { PROPERTIES } from "../config.js";
import { Eyebrow, PropertyCard, fmt } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// IMMOBILIEN PAGE
// ═══════════════════════════════════════════════════════════════════
export const ImmobilienPage = ({ setPage, setSelected, t, properties = PROPERTIES }) => {
  const [fType, setFType] = useState("alle");
  const [fCat, setFCat] = useState("alle");
  const [view, setView] = useState("grid");

  const data = properties.filter(p => {
    if (fType !== "alle" && !p.type.toLowerCase().includes(fType)) return false;
    if (fCat !== "alle" && p.category !== fCat) return false;
    return true;
  });

  return (
    <div className="pt-20">
      <section className="py-20 md:py-28" style={{ background: t.bgAlt, borderBottom: `1px solid ${t.borderLight}` }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <Eyebrow t={t}>Immobilienangebot</Eyebrow>
          <h1 className="font-display section-heading" style={{ fontSize: "clamp(2.5rem, 5vw, 4.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
            Finden Sie Ihr neues Zuhause
          </h1>
          <p className="text-lg mt-4 max-w-xl" style={{ color: t.textSecondary }}>
            {properties.length} ausgewählte Immobilien in Salzburg und Oberösterreich.
          </p>
        </div>
      </section>

      <section className="py-5 sticky top-20 z-30" style={{ background: t.navBg, backdropFilter: "blur(16px)", borderBottom: `1px solid ${t.borderLight}` }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 flex flex-wrap items-center justify-between gap-4">
          <div className="flex flex-wrap gap-2">
            {[["alle","Alle"],["kauf","Kaufen"]].map(([k,l]) => (
              <button key={k} onClick={() => setFCat(k)}
                className="px-5 py-2.5 rounded-full text-xs font-bold tracking-wider uppercase transition-all"
                style={{ background: fCat === k ? t.accent : "transparent", color: fCat === k ? "#fff" : t.textMuted, border: `1px solid ${fCat === k ? t.accent : t.border}` }}
              >{l}</button>
            ))}
            <div className="w-px mx-1 self-stretch" style={{ background: t.border }} />
            {[["alle","Alle Typen"],["haus","Haus"],["wohnung","Wohnung"],["neubau","Neubau"],["grundst","Grundst."]].map(([k,l]) => (
              <button key={k} onClick={() => setFType(k)}
                className="px-4 py-2.5 rounded-full text-xs font-bold tracking-wider uppercase transition-all"
                style={{ background: fType === k ? t.bgDark : "transparent", color: fType === k ? "#fff" : t.textMuted, border: `1px solid ${fType === k ? t.bgDark : t.border}` }}
              >{l}</button>
            ))}
          </div>
          <div className="flex items-center gap-3">
            <span className="text-sm font-semibold" style={{ color: t.textMuted }}>{data.length} Ergebnisse</span>
            <div className="flex gap-1 p-1 rounded-lg" style={{ background: t.bgAlt }}>
              {[["grid", Grid3X3], ["list", List]].map(([v, Icon]) => (
                <button key={v} onClick={() => setView(v)} className="p-2 rounded-md" style={{ background: view === v ? t.bgCard : "transparent" }}>
                  <Icon size={14} style={{ color: view === v ? t.text : t.textLight }} />
                </button>
              ))}
            </div>
          </div>
        </div>
      </section>

      <section className="py-12 md:py-20" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          {view === "grid" ? (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-7">
              {data.map(p => <PropertyCard key={p.id} property={p} onClick={() => { setSelected(p); setPage("detail"); }} t={t} />)}
            </div>
          ) : (
            <div className="space-y-5">
              {data.map(p => (
                <div key={p.id} onClick={() => { setSelected(p); setPage("detail"); }}
                  className="hover-lift cursor-pointer flex flex-col md:flex-row gap-6 p-5 rounded-2xl"
                  style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                  <div className="w-full md:w-72 h-52 md:h-48 rounded-xl overflow-hidden shrink-0">
                    <img src={p.images[0]} alt={p.title} className="w-full h-full object-cover hover-scale" />
                  </div>
                  <div className="flex-1 flex flex-col justify-between py-1">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <span className="px-3 py-1 rounded-full text-xs font-bold" style={{ background: t.accentLight, color: t.accent }}>{p.type}</span>
                        <span className="text-xs font-medium" style={{ color: t.textMuted }}>{p.city} | {p.region}</span>
                      </div>
                      <h3 className="text-xl font-bold tracking-tight mb-1" style={{ color: t.text }}>{p.title}</h3>
                      <p className="text-sm" style={{ color: t.textSecondary }}>{p.subtitle}</p>
                    </div>
                    <div className="flex items-center justify-between mt-4 pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                      <div className="flex gap-5">
                        {p.area > 0 && <span className="text-xs" style={{ color: t.textMuted }}>{p.area} m²</span>}
                        {p.rooms > 0 && <span className="text-xs" style={{ color: t.textMuted }}>{p.rooms} Zi.</span>}
                        {p.bathrooms > 0 && <span className="text-xs" style={{ color: t.textMuted }}>{p.bathrooms} Bad</span>}
                      </div>
                      <span className="text-2xl font-bold" style={{ color: t.text }}>{p.priceFrom && "ab "}{fmt(p.price)} EUR</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
          {data.length === 0 && (
            <div className="text-center py-20">
              <div className="text-6xl mb-4">🏠</div>
              <div className="text-lg font-semibold" style={{ color: t.text }}>Keine Objekte gefunden</div>
              <div className="text-sm" style={{ color: t.textMuted }}>Versuchen Sie andere Filterkriterien</div>
            </div>
          )}
        </div>
      </section>
    </div>
  );
};
