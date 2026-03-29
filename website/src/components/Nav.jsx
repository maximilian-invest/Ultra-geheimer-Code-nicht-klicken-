import { useState } from "react";
import { Phone, ArrowUpRight, Menu, X } from "lucide-react";
import { ASSETS } from "../config.js";
import { Btn } from "./ui.jsx";

// ═══════════════════════════════════════════════════════════════════
// NAVIGATION
// ═══════════════════════════════════════════════════════════════════
export const Nav = ({ page, setPage, scrolled, t, logos }) => {
  const [open, setOpen] = useState(false);
  const links = [
    { k: "home", l: "Start" }, { k: "immobilien", l: "Immobilien" },
    { k: "verkaufen", l: "Verkaufen" }, { k: "bewerten", l: "Bewerten" },
    { k: "portal", l: "Kundenportal" }, { k: "über", l: "Über uns" }, { k: "kontakt", l: "Kontakt" },
  ];

  return (
    <>
      <nav className={`fixed top-0 left-0 right-0 z-50 transition-all duration-700 ${open ? "pointer-events-none opacity-0" : ""}`} style={{
        background: scrolled ? t.navBg : "transparent",
        backdropFilter: scrolled ? "blur(24px) saturate(1.3)" : "none",
        borderBottom: scrolled ? `1px solid ${t.navBorder}` : "1px solid transparent",
      }}>
        <div className="max-w-[1440px] mx-auto px-4 sm:px-6 md:px-12 lg:px-16">
          <div className="flex items-center justify-between h-16 md:h-20">
            <button onClick={() => setPage("home")} className="shrink-0 transition-all duration-300 hover:opacity-70">
              <img src={scrolled ? (logos?.color || ASSETS.logoColor) : (logos?.white || ASSETS.logoWhite)} alt="SR-Homes" className="h-7 md:h-9" />
            </button>

            <div className="hidden xl:flex items-center gap-1">
              {links.map(({ k, l }) => (
                <button key={k} onClick={() => setPage(k)}
                  className="line-reveal px-4 py-2 text-sm font-medium tracking-wide transition-colors duration-300"
                  style={{ color: page === k ? t.accent : (scrolled ? t.textSecondary : "rgba(255,255,255,0.7)") }}
                >{l}</button>
              ))}
            </div>

            <div className="flex items-center gap-3 md:gap-4">
              <a href="tel:+436642600930" className="hidden lg:flex items-center gap-2 text-sm font-medium" style={{ color: scrolled ? t.textSecondary : "rgba(255,255,255,0.7)" }}>
                <Phone size={14} /> +43 664 2600 930
              </a>
              <Btn primary icon={ArrowUpRight} onClick={() => setPage("kontakt")} className="hidden md:inline-flex" style={{ padding: "10px 24px", fontSize: 13 }}>
                Beratung
              </Btn>
              <button onClick={() => setOpen(!open)} className="xl:hidden p-2" style={{ color: scrolled ? t.text : "#fff" }}>
                <Menu size={22} />
              </button>
            </div>
          </div>
        </div>
      </nav>

      {/* Mobile Menu */}
      {open && (
        <div className="fixed inset-0 z-[60] flex flex-col justify-center px-8 anim-fade-in" style={{ background: t.bg }}>
          <button onClick={() => setOpen(false)} className="absolute top-5 right-5 p-2" style={{ color: t.text }}><X size={28} /></button>
          {links.map(({ k, l }, i) => (
            <button key={k} onClick={() => { setPage(k); setOpen(false); }}
              className="anim-fade-up py-4 text-left font-bold tracking-tight transition-colors"
              style={{ fontSize: "clamp(28px, 6vw, 42px)", color: page === k ? t.accent : t.text, animationDelay: `${i * 0.06}s`, borderBottom: `1px solid ${t.border}` }}
            >{l}</button>
          ))}
        </div>
      )}
    </>
  );
};
