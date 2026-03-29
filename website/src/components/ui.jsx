import { useState, useEffect, useRef } from "react";
import { MapPin, Heart, Bed, Bath, Maximize, Car } from "lucide-react";
import { themes } from "../config.js";

export const fmt = (n) => new Intl.NumberFormat("de-AT", { maximumFractionDigits: 0 }).format(n);

// ─── GLOBAL STYLES ────────────────────────────────────────────────
export const GlobalStyles = () => (
  <style>{`
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@200;300;400;500;600;700;800;900&display=swap');
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600&display=swap');
    * { font-family: 'Outfit', system-ui, sans-serif; box-sizing: border-box; }
    html { scroll-behavior: auto; }
    .font-display { font-family: 'Playfair Display', Georgia, serif; }
    body { background: #FAF8F5; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(40px); } to { opacity:1; transform:translateY(0); } }
    @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
    @keyframes slideRight { from { transform:translateX(-100%); } to { transform:translateX(0); } }
    @keyframes scaleUp { from { transform:scale(0.9); opacity:0; } to { transform:scale(1); opacity:1; } }
    @keyframes float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-10px); } }
    @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.5; } }
    @keyframes marquee { 0% { transform:translateX(0); } 100% { transform:translateX(-50%); } }
    @keyframes countUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    @keyframes videoZoom { 0% { transform:scale(1); } 100% { transform:scale(1.1); } }
    .hero-video { animation: none; }
    @media (min-width:769px) { .hero-video { animation: videoZoom 30s ease-in-out infinite alternate; } }
    @keyframes heroTextReveal { from { clip-path:inset(100% 0 0 0); opacity:0; } to { clip-path:inset(0 0 0 0); opacity:1; } }

    .anim-fade-up { animation: fadeUp 1s cubic-bezier(0.22, 1, 0.36, 1) forwards; opacity:0; }
    .anim-fade-in { animation: fadeIn 0.8s ease forwards; opacity:0; }
    .anim-hero-text { animation: heroTextReveal 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
    .anim-d1 { animation-delay:0.1s; }
    .anim-d2 { animation-delay:0.25s; }
    .anim-d3 { animation-delay:0.4s; }
    .anim-d4 { animation-delay:0.55s; }
    .anim-d5 { animation-delay:0.7s; }

    .hover-lift { transition: transform 0.6s cubic-bezier(0.22,1,0.36,1), box-shadow 0.6s cubic-bezier(0.22,1,0.36,1); }
    .hover-lift:hover { transform:translateY(-6px); box-shadow:0 30px 60px -20px rgba(0,0,0,0.12); }

    .hover-scale { transition: transform 1.2s cubic-bezier(0.22,1,0.36,1); }
    .hover-scale:hover { transform:scale(1.05); }

    .hover-glow:hover { box-shadow: 0 0 40px rgba(212,98,43,0.12); }

    .line-reveal { position:relative; display:inline-block; }
    .line-reveal::after {
      content:''; position:absolute; bottom:-2px; left:0; width:0; height:2px;
      background:#D4622B;
      transition: width 0.4s cubic-bezier(0.22,1,0.36,1);
    }
    .line-reveal:hover::after { width:100%; }

    .marquee-track { display:flex; animation: marquee 30s linear infinite; }

    .grain { position:fixed; inset:0; z-index:9998; pointer-events:none; opacity:0.025; mix-blend-mode:multiply;
      background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    }

    .video-overlay { position:absolute; inset:0; background: linear-gradient(180deg, rgba(10,10,8,0.3) 0%, rgba(10,10,8,0.1) 30%, rgba(10,10,8,0.6) 70%, rgba(10,10,8,0.95) 100%); }

    ::-webkit-scrollbar { width:5px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:#C5C0B8; border-radius:10px; }

    @media (max-width:768px) { .hero-h1 { font-size:2rem !important; } }
    @media (min-width:769px) and (max-width:1440px) {
      .hero-h1 { font-size:clamp(2.8rem, 5.5vw, 5rem) !important; }
      .section-heading { font-size:clamp(1.8rem, 3.2vw, 2.8rem) !important; }
      .stat-number { font-size:3.5rem !important; }
      .laptop-py { padding-top:5rem !important; padding-bottom:5rem !important; }
      .page-hero-h1 { font-size:clamp(2.2rem, 4.5vw, 3.8rem) !important; }
    }

    .card-img { aspect-ratio:4/3; overflow:hidden; }
    .card-img img { transition: transform 1.4s cubic-bezier(0.22,1,0.36,1); }
    .card-img:hover img { transform:scale(1.08); }

    input, textarea, select { font-family: 'Outfit', system-ui, sans-serif; }
    input:focus, textarea:focus, select:focus {
      outline:none; border-color:#D4622B !important;
      box-shadow:0 0 0 3px #D4622B15;
    }
  `}</style>
);

// ─── EYEBROW ──────────────────────────────────────────────────────
export const Eyebrow = ({ children, t }) => (
  <div className="flex items-center gap-3 mb-5">
    <div style={{ width: 32, height: 2, background: t.accent, borderRadius: 2 }} />
    <span className="uppercase tracking-[0.2em] text-xs font-semibold" style={{ color: t.accent, letterSpacing: "0.2em" }}>
      {children}
    </span>
  </div>
);

// ─── BUTTON ───────────────────────────────────────────────────────
export const Btn = ({ children, primary, large, icon: Icon, onClick, className = "", style: extraStyle = {} }) => {
  const [hov, setHov] = useState(false);
  return (
    <button
      onClick={onClick}
      onMouseEnter={() => setHov(true)}
      onMouseLeave={() => setHov(false)}
      className={`inline-flex items-center gap-3 font-semibold transition-all duration-500 active:scale-[0.97] ${large ? "text-base px-10 py-5" : "text-sm px-8 py-4"} ${primary ? "text-white" : ""} rounded-full group ${className}`}
      style={{
        background: primary ? (hov ? themes.light.accentHover : themes.light.accent) : "transparent",
        border: primary ? "none" : `1.5px solid ${hov ? themes.light.accent : "rgba(255,255,255,0.2)"}`,
        color: primary ? "#fff" : (hov ? themes.light.accent : "currentColor"),
        letterSpacing: "0.05em",
        ...extraStyle,
      }}
    >
      <span>{children}</span>
      {Icon && <Icon size={large ? 18 : 16} className="transition-transform duration-500 group-hover:translate-x-1" />}
    </button>
  );
};

// ─── STAT COUNTER (animated) ──────────────────────────────────────
export const StatCounter = ({ value, suffix, label, t, delay = 0 }) => {
  const [visible, setVisible] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const obs = new IntersectionObserver(([e]) => { if (e.isIntersecting) setVisible(true); }, { threshold: 0.3 });
    if (ref.current) obs.observe(ref.current);
    return () => obs.disconnect();
  }, []);

  return (
    <div ref={ref} className="text-center" style={{ animation: visible ? `countUp 0.8s ${delay}s cubic-bezier(0.22,1,0.36,1) forwards` : "none", opacity: visible ? undefined : 0 }}>
      <div className="flex items-baseline justify-center gap-1">
        <span className="stat-number text-5xl md:text-7xl font-bold tracking-tighter" style={{ color: t.text }}>{value}</span>
        <span className="text-lg md:text-xl font-semibold" style={{ color: t.accent }}>{suffix}</span>
      </div>
      <span className="text-sm font-medium mt-2 block uppercase tracking-widest" style={{ color: t.textMuted, letterSpacing: "0.15em" }}>{label}</span>
    </div>
  );
};

// ─── PROPERTY CARD ────────────────────────────────────────────────
export const PropertyCard = ({ property: p, onClick, t, featured }) => (
  <div
    onClick={onClick}
    className={`hover-lift hover-glow cursor-pointer rounded-2xl overflow-hidden ${featured ? "" : ""}`}
    style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}
  >
    <div className="card-img relative">
      <img src={p.images[0]} alt={p.title} className="w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to top, rgba(0,0,0,0.5) 0%, transparent 50%)" }} />
      <div className="absolute top-4 left-4 flex gap-2">
        <span className="px-3 py-1.5 rounded-full text-xs font-semibold tracking-wider uppercase text-white" style={{ background: "rgba(0,0,0,0.5)", backdropFilter: "blur(12px)" }}>{p.type}</span>
        {p.units && <span className="px-3 py-1.5 rounded-full text-xs font-semibold text-white" style={{ background: t.accent }}>{p.unitsFree} Einheiten frei</span>}
      </div>
      <button className="absolute top-4 right-4 w-10 h-10 rounded-full flex items-center justify-center transition-all hover:scale-110" style={{ background: "rgba(255,255,255,0.15)", backdropFilter: "blur(8px)" }}>
        <Heart size={16} color="#fff" />
      </button>
      <div className="absolute bottom-4 left-4 right-4">
        <div className="text-white text-2xl font-bold tracking-tight">
          {p.priceFrom && "ab "}{p.price >= 1e6 ? `EUR ${(p.price / 1e6).toFixed(2).replace(".", ",")} Mio.` : `EUR ${fmt(p.price)}`}
        </div>
      </div>
    </div>
    <div className="p-6">
      <div className="flex items-center gap-2 mb-2">
        <MapPin size={13} style={{ color: t.textMuted }} />
        <span className="text-xs font-medium uppercase tracking-wider" style={{ color: t.textMuted }}>{p.city} | {p.region}</span>
      </div>
      <h3 className="text-lg font-bold tracking-tight mb-1" style={{ color: t.text }}>{p.title}</h3>
      <p className="text-sm mb-4" style={{ color: t.textSecondary }}>{p.subtitle}</p>
      <div className="flex items-center gap-5 pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
        {p.area > 0 && <span className="flex items-center gap-1.5 text-xs font-medium" style={{ color: t.textMuted }}><Maximize size={13} /> {p.area} m²</span>}
        {p.rooms > 0 && <span className="flex items-center gap-1.5 text-xs font-medium" style={{ color: t.textMuted }}><Bed size={13} /> {p.rooms} Zimmer</span>}
        {p.bathrooms > 0 && <span className="flex items-center gap-1.5 text-xs font-medium" style={{ color: t.textMuted }}><Bath size={13} /> {p.bathrooms}</span>}
        {p.parking > 0 && <span className="flex items-center gap-1.5 text-xs font-medium" style={{ color: t.textMuted }}><Car size={13} /> {p.parking}</span>}
      </div>
    </div>
  </div>
);

// ─── FEATURED PROPERTY (large hero card) ──────────────────────────
export const FeaturedCard = ({ property: p, onClick, t, index }) => (
  <div
    onClick={onClick}
    className="cursor-pointer group relative overflow-hidden rounded-3xl"
    style={{ height: index === 0 ? 560 : 380, background: t.bgCard }}
  >
    <img src={p.images[0]} alt={p.title} className="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-105" />
    <div className="absolute inset-0" style={{ background: "linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.1) 50%, transparent 100%)" }} />
    <div className="absolute top-5 left-5 flex gap-2">
      <span className="px-4 py-2 rounded-full text-xs font-bold tracking-widest uppercase text-white" style={{ background: "rgba(0,0,0,0.4)", backdropFilter: "blur(12px)" }}>{p.type}</span>
    </div>
    <div className="absolute bottom-0 left-0 right-0 p-6 md:p-8">
      <div className="flex items-center gap-2 mb-2">
        <MapPin size={13} color="rgba(255,255,255,0.6)" />
        <span className="text-xs font-medium text-white/60 uppercase tracking-wider">{p.address}, {p.city}</span>
      </div>
      <h3 className={`font-bold tracking-tight text-white mb-2 ${index === 0 ? "text-3xl md:text-4xl" : "text-xl md:text-2xl"}`}>{p.title}</h3>
      <div className="flex items-center gap-6">
        <span className="text-xl font-bold text-white">{p.priceFrom && "ab "}{p.price >= 1e6 ? `EUR ${(p.price / 1e6).toFixed(2).replace(".", ",")} Mio.` : `EUR ${fmt(p.price)}`}</span>
        <div className="flex gap-4">
          {p.area > 0 && <span className="text-xs text-white/60">{p.area} m²</span>}
          {p.rooms > 0 && <span className="text-xs text-white/60">{p.rooms} Zi.</span>}
        </div>
      </div>
    </div>
  </div>
);
