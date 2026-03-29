import { useState } from "react";
import {
  MapPin, Phone, Mail, Clock, ChevronLeft,
  Bed, Bath, Maximize, Car, Heart, Share2,
  ArrowUpRight, CheckCircle
} from "lucide-react";
import { PROPERTIES } from "../config.js";
import { Btn, PropertyCard, fmt } from "../components/ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// DETAIL PAGE
// ═══════════════════════════════════════════════════════════════════
export const DetailPage = ({ property, setPage, setSelected, t, properties = PROPERTIES }) => {
  const [img, setImg] = useState(0);
  const p = property || properties[0];

  return (
    <div className="pt-20">
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 pt-6">
        <button onClick={() => setPage("immobilien")} className="flex items-center gap-2 text-sm font-semibold" style={{ color: t.textMuted }}>
          <ChevronLeft size={16} /> Zurück
        </button>
      </div>

      {/* Hero Gallery */}
      <section className="py-8">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <div className="lg:col-span-2 rounded-2xl overflow-hidden cursor-pointer relative group" style={{ aspectRatio: "16/10" }}>
              <img src={p.images[img]} alt={p.title} className="w-full h-full object-cover transition-transform duration-[1.5s] group-hover:scale-105" />
              <div className="absolute bottom-4 right-4 px-3 py-1.5 rounded-full text-xs font-semibold text-white" style={{ background: "rgba(0,0,0,0.5)", backdropFilter: "blur(8px)" }}>
                {img + 1} / {p.images.length}
              </div>
            </div>
            <div className="grid grid-cols-2 lg:grid-cols-1 gap-3">
              {p.images.slice(1, 3).map((im, i) => (
                <div key={i} className="rounded-2xl overflow-hidden cursor-pointer" style={{ aspectRatio: "16/10" }} onClick={() => setImg(i + 1)}>
                  <img src={im} alt="" className="w-full h-full object-cover hover-scale" />
                </div>
              ))}
              {p.images.length <= 2 && <div className="rounded-2xl flex items-center justify-center" style={{ background: t.bgAlt, aspectRatio: "16/10" }}><span className="text-sm" style={{ color: t.textMuted }}>Weitere Bilder</span></div>}
            </div>
          </div>
        </div>
      </section>

      <section className="py-8 md:py-16">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-12 lg:gap-20">
            <div className="lg:col-span-2">
              <div className="flex flex-wrap gap-3 mb-4">
                <span className="px-4 py-2 rounded-full text-xs font-bold uppercase tracking-wider" style={{ background: t.accentLight, color: t.accent }}>{p.type}</span>
                {p.ref && <span className="px-4 py-2 rounded-full text-xs font-bold" style={{ background: t.bgAlt, color: t.textMuted }}>Ref: {p.ref}</span>}
                {p.energyClass && <span className="px-4 py-2 rounded-full text-xs font-bold" style={{ background: t.bgAlt, color: t.textMuted }}>Energie: {p.energyClass}</span>}
              </div>

              <h1 className="font-display mb-2" style={{ fontSize: "clamp(2rem, 4vw, 3rem)", fontWeight: 700, color: t.text, lineHeight: 1.1, letterSpacing: "-0.03em" }}>{p.title}</h1>
              <p className="text-lg mb-2" style={{ color: t.textSecondary }}>{p.subtitle}</p>
              <div className="flex items-center gap-2 mb-10"><MapPin size={15} style={{ color: t.textMuted }} /><span className="text-sm" style={{ color: t.textMuted }}>{p.address}, {p.zip} {p.city}</span></div>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-12 p-8 rounded-2xl" style={{ background: t.bgAlt }}>
                {[
                  p.area > 0 && { icon: Maximize, l: "Wohnfläche", v: `${p.area} m²` },
                  p.rooms > 0 && { icon: Bed, l: "Zimmer", v: p.rooms },
                  p.bathrooms > 0 && { icon: Bath, l: "Bäder", v: p.bathrooms },
                  p.parking > 0 && { icon: Car, l: "Stellplätze", v: p.parking },
                ].filter(Boolean).map((f, i) => (
                  <div key={i} className="text-center"><f.icon size={20} className="mx-auto mb-2" style={{ color: t.accent }} /><div className="text-2xl font-bold" style={{ color: t.text }}>{f.v}</div><div className="text-xs" style={{ color: t.textMuted }}>{f.l}</div></div>
                ))}
              </div>

              <h2 className="text-xl font-bold mb-4" style={{ color: t.text }}>Beschreibung</h2>
              <p className="text-base leading-relaxed mb-10" style={{ color: t.textSecondary, maxWidth: "70ch" }}>{p.description}</p>

              <h2 className="text-xl font-bold mb-4" style={{ color: t.text }}>Ausstattung</h2>
              <div className="flex flex-wrap gap-2 mb-10">
                {p.features.map((f, i) => (
                  <span key={i} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium" style={{ background: t.bgAlt, color: t.text }}>
                    <CheckCircle size={14} style={{ color: t.accent }} /> {f}
                  </span>
                ))}
              </div>

              {p.units && <>
                <h2 className="text-xl font-bold mb-4" style={{ color: t.text }}>Verfügbare Einheiten</h2>
                <div className="p-6 rounded-2xl mb-10" style={{ background: t.bgAlt }}>
                  <div className="flex items-center gap-6">
                    <div><span className="text-3xl font-black" style={{ color: t.accent }}>{p.unitsFree}</span><span className="text-sm ml-2" style={{ color: t.textMuted }}>von {p.units} frei</span></div>
                    <div className="flex-1 h-3 rounded-full overflow-hidden" style={{ background: t.border }}>
                      <div className="h-full rounded-full" style={{ width: `${((p.units - p.unitsFree) / p.units) * 100}%`, background: t.accent }} />
                    </div>
                  </div>
                </div>
              </>}

              <h2 className="text-xl font-bold mb-4" style={{ color: t.text }}>Details</h2>
              <div className="space-y-0 mb-10">
                {[
                  p.year && ["Baujahr", p.year],
                  p.yearRenovated && ["Renoviert", p.yearRenovated],
                  p.energyClass && ["Energieausweis", p.energyClass],
                  ["Region", p.region],
                  ["Plattformen", p.platforms?.join(", ")],
                ].filter(Boolean).map(([k, v], i) => (
                  <div key={i} className="flex justify-between py-4 text-sm" style={{ borderBottom: `1px solid ${t.borderLight}` }}>
                    <span style={{ color: t.textMuted }}>{k}</span>
                    <span className="font-semibold" style={{ color: t.text }}>{v}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="lg:col-span-1">
              <div className="sticky top-28 space-y-5">
                <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.border}`, boxShadow: "0 12px 40px -15px rgba(0,0,0,0.08)" }}>
                  <div className="text-xs font-bold uppercase tracking-widest mb-1" style={{ color: t.textMuted }}>Kaufpreis</div>
                  <div className="font-bold tracking-tight mb-6" style={{ fontSize: 32, color: t.text }}>{p.priceFrom && "ab "}{p.price >= 1e6 ? `EUR ${(p.price / 1e6).toFixed(2).replace(".", ",")} Mio.` : `EUR ${fmt(p.price)}`}</div>
                  <Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")} className="w-full justify-center">Anfrage senden</Btn>
                  <div className="flex gap-3 mt-4">
                    <button className="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl text-xs font-bold" style={{ border: `1px solid ${t.border}`, color: t.textMuted }}><Heart size={14} /> Merken</button>
                    <button className="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl text-xs font-bold" style={{ border: `1px solid ${t.border}`, color: t.textMuted }}><Share2 size={14} /> Teilen</button>
                  </div>
                </div>

                <div className="p-8 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.border}` }}>
                  <div className="flex items-center gap-4 mb-5">
                    <div className="w-16 h-16 rounded-2xl flex items-center justify-center text-white text-xl font-bold" style={{ background: t.accent }}>MH</div>
                    <div>
                      <div className="text-base font-bold" style={{ color: t.text }}>Maximilian Hölzl</div>
                      <div className="text-xs" style={{ color: t.textMuted }}>Konzessionierter Immobilientreuhänder</div>
                    </div>
                  </div>
                  <div className="space-y-3">
                    <a href="tel:+436642600930" className="flex items-center gap-3 text-sm" style={{ color: t.textSecondary }}><Phone size={15} style={{ color: t.accent }} />+43 664 2600 930</a>
                    <a href="mailto:hoelzl@sr-homes.at" className="flex items-center gap-3 text-sm" style={{ color: t.textSecondary }}><Mail size={15} style={{ color: t.accent }} />hoelzl@sr-homes.at</a>
                    <div className="flex items-center gap-3 text-sm" style={{ color: t.textSecondary }}><Clock size={15} style={{ color: t.accent }} />Mo-Fr 8:00 - 18:00</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="py-20" style={{ background: t.bgAlt }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <h2 className="text-2xl font-bold tracking-tight mb-8" style={{ color: t.text }}>Weitere Objekte</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {properties.filter(x => x.id !== p.id).slice(0, 3).map(pr => (
              <PropertyCard key={pr.id} property={pr} onClick={() => { setSelected(pr); setImg(0); window.scrollTo({ top: 0, behavior: "smooth" }); }} t={t} />
            ))}
          </div>
        </div>
      </section>
    </div>
  );
};
