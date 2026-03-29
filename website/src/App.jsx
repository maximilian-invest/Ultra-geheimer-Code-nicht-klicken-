import { useState, useEffect, useRef, useCallback, useMemo } from "react";
import {
  Search, MapPin, Home, Building2, TreePine, Phone, Mail, Clock,
  ChevronRight, ChevronDown, ArrowUpRight, ArrowRight, Menu, X,
  Bed, Bath, Maximize, Car, Heart, Share2, Calendar, Star,
  Shield, Eye, TrendingUp, Users, FileText, BarChart3,
  CheckCircle, Award, Zap, Globe, Lock, MessageSquare,
  ChevronLeft, Filter, Grid3X3, List, Play, Pause,
  ArrowDown, Target, Briefcase, PieChart, LineChart, Activity,
  Layers, Sparkles, Volume2, MousePointer
} from "lucide-react";

/* ═══════════════════════════════════════════════════════════════════
   SR-HOMES.AT — PREMIUM REAL ESTATE WEBSITE v2
   Inspired by SERHANT.com — Cinematic, Emotional, Data-Driven
   ═══════════════════════════════════════════════════════════════════ */

// ─── API CONFIGURATION ──────────────────────────────────────────
const API_BASE = "https://kundenportal.sr-homes.at/api/website";

// ─── REAL SR-HOMES ASSETS ─────────────────────────────────────────
const ASSETS = {
  logoColor: "https://api.immoji.org/image/c602e391-bb1f-445e-b783-70d7fd1de866.svg",
  logoWhite: "https://api.immoji.org/image/e747b1e6-58ee-4dc8-a2ab-28ab72798310.svg",
  heroImage: "https://api.immoji.org/image/original/47f9ac44-1ce8-4a23-b210-eeee392d9c2b.jpg",
  heroDesktop: "https://api.immoji.org/image/desktop-47f9ac44-1ce8-4a23-b210-eeee392d9c2b.jpg",
  homeParallax: "https://api.immoji.org/image/desktop-7214fedb-3350-449d-ae94-09510717b479.webp",
  teamImage: "https://api.immoji.org/image/original/507f07b3-579d-4b20-a74b-90146e3abb02",
  philosophyImage: "https://api.immoji.org/image/original/fa95032a-b161-4084-b545-a5d3d39347fc",
  sellImage: "https://api.immoji.org/image/original/2986c7bc-27ba-40f2-a829-791b2d7ec8e5",
  rentImage: "https://api.immoji.org/image/original/a23dce9b-08ae-41df-9cc0-0dbe7b529a85",
  valueImage: "https://api.immoji.org/image/original/03c539c9-0829-4aef-9270-3c5f368a2e13",
  contactImage: "https://api.immoji.org/image/original/cff9990a-00ab-402a-b8f9-0836c62e8ebb",
  // Example video (cinematic real estate drone shot — Salzburg area)
  heroVideo: "https://cdn.coverr.co/videos/coverr-aerial-shot-of-city-surrounded-by-mountains-1868/1080p.mp4",
  // High-quality property images (Unsplash)
  prop1: "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200&h=800&fit=crop",
  prop2: "https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&h=800&fit=crop",
  prop3: "https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200&h=800&fit=crop",
  prop4: "https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200&h=800&fit=crop",
  prop5: "https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=1200&h=800&fit=crop",
  prop6: "https://images.unsplash.com/photo-1583608205776-bfd35f0d9f83?w=1200&h=800&fit=crop",
  prop7: "https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200&h=800&fit=crop",
  prop8: "https://images.unsplash.com/photo-1602343168117-bb8bbe693920?w=1200&h=800&fit=crop",
  propInt1: "https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1200&h=800&fit=crop",
  propInt2: "https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200&h=800&fit=crop",
  propInt3: "https://images.unsplash.com/photo-1600573472592-401b489a3cdc?w=1200&h=800&fit=crop",
  propInt4: "https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200&h=800&fit=crop",
  propView1: "https://images.unsplash.com/photo-1605146769289-440113cc3d00?w=1200&h=800&fit=crop",
  propView2: "https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?w=1200&h=800&fit=crop",
  propGarden: "https://images.unsplash.com/photo-1598228723793-52759bba239c?w=1200&h=800&fit=crop",
  propNew1: "https://images.unsplash.com/photo-1613977257363-707ba9348227?w=1200&h=800&fit=crop",
  propNew2: "https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200&h=800&fit=crop",
  propLand: "https://images.unsplash.com/photo-1500382017468-9049fed747ef?w=1200&h=800&fit=crop",
  salzburg: "https://images.unsplash.com/photo-1609951651556-5334e2706168?w=1600&h=900&fit=crop",
  salzburg2: "https://images.unsplash.com/photo-1614439169968-28a7ab73b733?w=1600&h=900&fit=crop",
};

// ─── THEME SYSTEM ─────────────────────────────────────────────────
const themes = {
  light: {
    bg: "#FAF8F5", bgAlt: "#F0ECE6", bgCard: "#FFFFFF",
    bgDark: "#0A0A08", bgDarkAlt: "#141410",
    accent: "#D4622B", accentHover: "#C0551F", accentLight: "#D4622B12",
    text: "#0A0A08", textSecondary: "#5A564E", textMuted: "#9A958C", textLight: "#C5C0B8",
    border: "#E5E0D8", borderLight: "#F0ECE6",
    navBg: "rgba(250, 248, 245, 0.7)", navBorder: "rgba(229, 224, 216, 0.5)",
  },
  dark: {
    bg: "#0A0A08", bgAlt: "#141410", bgCard: "#1A1A16",
    bgDark: "#050504", bgDarkAlt: "#0A0A08",
    accent: "#E8743A", accentHover: "#F08A55", accentLight: "#E8743A15",
    text: "#F0ECE6", textSecondary: "#B5AFA7", textMuted: "#7A756D", textLight: "#4A463E",
    border: "#2A2A24", borderLight: "#1E1E1A",
    navBg: "rgba(10, 10, 8, 0.8)", navBorder: "rgba(42, 42, 36, 0.5)",
  },
};

// ─── PROPERTIES (echte SR-Homes Objekte) ──────────────────────────
const PROPERTIES = [
  {
    id: 1, ref: "Kau-Hau-Ste-01", title: "Exklusive Villa an der Klessheimer Allee",
    subtitle: "Repräsentatives Wohnen in erstklassiger Salzburger Lage",
    address: "Klessheimer Allee 74", city: "Salzburg", zip: "5020", region: "Salzburg Stadt",
    type: "Haus", category: "kauf", status: "inserat",
    price: 1290000, area: 285, rooms: 7, bathrooms: 3, parking: 2,
    year: 1965, yearRenovated: 2019, energyClass: "B",
    description: "Diese repräsentative Villa liegt an einer der renommiertesten Adressen Salzburgs. Auf 285m2 Wohnfläche bietet sie großzügige Räumlichkeiten, einen weitläufigen Garten mit altem Baumbestand und eine Doppelgarage. Die vollständige Renovierung 2019 vereint historischen Charme mit zeitgemäßem Komfort. Hochwertige Materialien, Parkettböden und bodentiefe Fenster prägen das Interieur. Die Lage bietet schnellen Zugang zur Salzburger Altstadt bei gleichzeitiger Ruhe und Privatsphäre.",
    features: ["Garten", "Doppelgarage", "Keller", "Terrasse", "Kamin", "Parkett", "Alarmanlage"],
    images: ["https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1598228723793-52759bba239c?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "immowelt", "SR-Homes"],
    highlights: "Top-Lage, vollständig renoviert, repräsentativ"
  },
  {
    id: 2, ref: "Kau-Wo-Ham-01", title: "Stilvolle Altbauwohnung nahe Altstadt",
    subtitle: "Hohe Decken und Stuck im Herzen Salzburgs",
    address: "Enzingergasse 14/1", city: "Salzburg", zip: "5020", region: "Salzburg Stadt",
    type: "Eigentumswohnung", category: "kauf", status: "inserat",
    price: 389000, area: 92, rooms: 3, bathrooms: 1, parking: 1,
    year: 1928, yearRenovated: 2021, energyClass: "C",
    description: "Charmante Altbauwohnung mit über 3 Meter hohen Decken, originalem Stuck und Fischgrätparkett. Die sanierte Wohnung verbindet historisches Flair mit modernem Wohnkomfort. Zentrale Lage mit allen Annehmlichkeiten in Gehdistanz.",
    features: ["Balkon", "Keller", "Parkett", "Stuck", "Altbau-Charme"],
    images: ["https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600573472592-401b489a3cdc?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "SR-Homes"],
    highlights: "Altbau-Charme, Toplage, hochwertig saniert"
  },
  {
    id: 3, ref: "Kau-Woh-Mo-01", title: "Moderne Gartenwohnung im Grünen",
    subtitle: "Neuwertig mit eigenem Gartenanteil",
    address: "Eichenweg 4/6", city: "Straßwalchen", zip: "5204", region: "Flachgau",
    type: "Eigentumswohnung", category: "kauf", status: "inserat",
    price: 325000, area: 78, rooms: 3, bathrooms: 1, parking: 1,
    year: 2020, energyClass: "A",
    description: "Neuwertige Gartenwohnung mit eigenem Gartenanteil in ruhiger Wohnlage. Offener Wohn-Essbereich, Fußbodenheizung, hochwertige Einbauküche und Tiefgaragenstellplatz. Perfekt für Paare und junge Familien.",
    features: ["Garten", "Fußbodenheizung", "Tiefgarage", "Terrasse", "Einbauküche"],
    images: ["https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1598228723793-52759bba239c?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "immowelt", "SR-Homes"],
    highlights: "Neuwertig, Garten, energieeffizient"
  },
  {
    id: 4, ref: "The37", title: "The 37 — Urbanes Neubauprojekt",
    subtitle: "12 exklusive Wohneinheiten im Zentrum von Ried",
    address: "St. Anna 4", city: "Ried im Innkreis", zip: "4910", region: "Innviertel",
    type: "Neubauprojekt", category: "kauf", status: "angebote",
    price: 245000, priceFrom: true, area: 65, rooms: 2, bathrooms: 1, parking: 1,
    year: 2026, energyClass: "A+",
    description: "The 37 definiert urbanes Wohnen in Ried im Innkreis neu. 12 durchdachte Einheiten von 45 bis 110m2, nachhaltige Bauweise mit Wärmepumpe und Photovoltaik. Intelligente Grundrisse, großzügige Freiflächen und eine Tiefgarage. 4 Einheiten noch verfügbar.",
    features: ["Neubau", "Lift", "Fußbodenheizung", "Tiefgarage", "Loggia", "Photovoltaik", "Wärmepumpe"],
    images: ["https://images.unsplash.com/photo-1613977257363-707ba9348227?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1602343168117-bb8bbe693920?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "immowelt", "ImmobilienScout24", "SR-Homes"],
    units: 12, unitsFree: 4,
    highlights: "4 Einheiten verfügbar, nachhaltig, zentral"
  },
  {
    id: 5, ref: "Kau-Per-Vit-01", title: "Traumhafter Neubau am Grabensee",
    subtitle: "Exklusives Wohnen in absoluter Seenähe",
    address: "Perwang am Grabensee", city: "Perwang", zip: "5163", region: "Flachgau",
    type: "Neubau", category: "kauf", status: "inserat",
    price: 495000, area: 120, rooms: 4, bathrooms: 2, parking: 2,
    year: 2025, energyClass: "A+",
    description: "Exklusiver Neubau in absoluter Seenähe mit Blick auf den Grabensee. Offene Raumgestaltung, großzügige Terrasse, Garten zum See hin und hochwertigste Materialien. Luft-Wärme-Pumpe und dreifach verglaste Fenster.",
    features: ["Seeblick", "Garten", "Terrasse", "Carport", "Wärmepumpe", "Smart Home"],
    images: ["https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1605146769289-440113cc3d00?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "SR-Homes"],
    highlights: "Seenähe, Neubau, Energieeffizient"
  },
  {
    id: 6, title: "Sonniges Einfamilienhaus mit Bergpanorama",
    subtitle: "Idyllisch gelegen am Fusse des Schafbergs",
    address: "Russbachweg 2", city: "Mondsee", zip: "5310", region: "Mondseeland",
    type: "Einfamilienhaus", category: "kauf", status: "inserat",
    price: 685000, area: 165, rooms: 5, bathrooms: 2, parking: 2,
    year: 2005, yearRenovated: 2022, energyClass: "B",
    description: "Gepflegtes Einfamilienhaus in traumhafter Lage am Mondsee. Unverbaubarer Bergblick, großer Garten mit altem Baumbestand, Doppelgarage und Sauna. Vollständig renoviert 2022 mit neuer Küche und Bädern.",
    features: ["Bergblick", "Garten", "Doppelgarage", "Keller", "Sauna", "Pool-Vorbereitung"],
    images: ["https://images.unsplash.com/photo-1583608205776-bfd35f0d9f83?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "SR-Homes"],
    highlights: "Bergblick, renoviert, Sauna"
  },
  {
    id: 7, title: "Großzügiges Familiendomizil am Stadtrand",
    subtitle: "800m2 Grund, ruhig und doch stadtnah",
    address: "Weiherweg 2", city: "Salzburg/Grödig", zip: "5082", region: "Flachgau",
    type: "Einfamilienhaus", category: "kauf", status: "inserat",
    price: 549000, area: 142, rooms: 5, bathrooms: 2, parking: 2,
    year: 1990, yearRenovated: 2023, energyClass: "B",
    description: "Großzügiges Familienhaus auf knapp 800m2 Grund am südlichen Stadtrand von Salzburg. 2023 komplett modernisiert: neue Fenster, Fassadendämmung, Badsanierung. Ruhige Sackgassenlage, perfekt für Familien.",
    features: ["Garten", "Garage", "Keller", "Werkstatt", "Balkon", "Vollwärmeschutz"],
    images: ["https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "immowelt", "SR-Homes"],
    highlights: "800m2 Grund, modernisiert, familienfreundlich"
  },
  {
    id: 8, ref: "Kau-Gru-Han-01", title: "Neubauprojekt Holzleithen",
    subtitle: "Modernes Wohnen im idyllischen Almtal",
    address: "Holzleithen 8", city: "Ohlsdorf", zip: "4694", region: "Innviertel",
    type: "Neubauprojekt", category: "kauf", status: "inserat",
    price: 310000, priceFrom: true, area: 85, rooms: 3, bathrooms: 1, parking: 1,
    year: 2026, energyClass: "A+",
    description: "Attraktives Neubauprojekt im idyllischen Ohlsdorf. 8 Wohneinheiten mit durchdachten Grundrissen, eigenen Gärten oder Terrassen und Carports. Nachhaltige Bauweise, kurze Wege ins Zentrum.",
    features: ["Neubau", "Garten", "Terrasse", "Carport", "Wärmepumpe", "Photovoltaik"],
    images: ["https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1602343168117-bb8bbe693920?w=1200&h=800&fit=crop"],
    platforms: ["willhaben", "SR-Homes"],
    units: 8, unitsFree: 6,
    highlights: "6 Einheiten frei, nachhaltig, idyllisch"
  },
  {
    id: 9, ref: "Kau-Woh-Gai", title: "Elegante Wohnung in Mondsee",
    subtitle: "Seenähe und erstklassige Infrastruktur",
    address: "August Strindberg Str. 1", city: "Mondsee", zip: "5310", region: "Mondseeland",
    type: "Wohnung", category: "kauf", status: "auftrag",
    price: 420000, area: 95, rooms: 4, bathrooms: 2, parking: 1,
    year: 2018, energyClass: "A",
    description: "Elegante 4-Zimmer-Wohnung in beliebter Wohnlage von Mondsee. Hochwertige Ausstattung, zwei Bäder, südseitige Terrasse mit Blick ins Grüne. Wenige Gehminuten zum Mondsee.",
    features: ["Terrasse", "Tiefgarage", "Fußbodenheizung", "2 Bäder", "Abstellraum"],
    images: ["https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&h=800&fit=crop", "https://images.unsplash.com/photo-1600573472592-401b489a3cdc?w=1200&h=800&fit=crop"],
    platforms: ["SR-Homes"],
    highlights: "Seenähe, hochwertig, 2 Bäder"
  },
  {
    id: 10, ref: "Kau-Gst-Est", title: "Baugrundst. in Grödig — Glanstraße",
    subtitle: "Erschlossenes Bauland in Toplage",
    address: "Glanstraße", city: "Grödig", zip: "5082", region: "Flachgau",
    type: "Grundstück", category: "kauf", status: "auftrag",
    price: 380000, area: 650, rooms: 0, bathrooms: 0, parking: 0,
    year: null, energyClass: null,
    description: "Vollerschlossenes Baugrundst. in attraktiver Lage von Grödig. Ebene Topografie, südliche Ausrichtung, alle Anschlüsse vorhanden. Ideal für Ein- oder Zweifamilienhaus.",
    features: ["Vollerschlossen", "Südausrichtung", "Ebene Lage", "Ruhig"],
    images: ["https://images.unsplash.com/photo-1500382017468-9049fed747ef?w=1200&h=800&fit=crop"],
    platforms: ["SR-Homes"],
    highlights: "Erschlossen, südlich, Toplage"
  },
];

// ─── PLACEHOLDER IMAGES (used when API has no images) ───────────
const PLACEHOLDER_IMGS = [
  "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1583608205776-bfd35f0d9f83?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1602343168117-bb8bbe693920?w=1200&h=800&fit=crop",
];

// ─── MAP API → COMPONENT FORMAT ─────────────────────────────────
const mapApiProperty = (ap, index) => {
  const price = ap.price ? parseFloat(ap.price) : 0;
  const area = ap.area_living || ap.area_land || ap.size_m2 || 0;
  const rooms = ap.rooms || 0;

  // Generate a readable title from available data
  let title = ap.project_name || "";
  if (!title) {
    const typeLabel = ap.type || "Immobilie";
    title = `${typeLabel} in ${ap.city || "Salzburg"}`;
    if (ap.address) title = `${typeLabel} — ${ap.address}`;
  }

  // Parse highlights: might be JSON string or plain text
  let highlights = ap.highlights || "";
  if (typeof highlights === "string" && highlights.startsWith("[")) {
    try { highlights = JSON.parse(highlights).join(", "); } catch (e) { /* keep as string */ }
  }

  // Build images array: API images or placeholders
  const imgs = [];
  if (ap.main_image_url) imgs.push(ap.main_image_url);
  if (ap.gallery_urls && ap.gallery_urls.length > 0) {
    ap.gallery_urls.forEach(u => { if (u && !imgs.includes(u)) imgs.push(u); });
  }
  if (imgs.length === 0) {
    // Use 2-3 placeholder images, cycling through the array
    const base = index % PLACEHOLDER_IMGS.length;
    imgs.push(PLACEHOLDER_IMGS[base]);
    imgs.push(PLACEHOLDER_IMGS[(base + 1) % PLACEHOLDER_IMGS.length]);
    imgs.push(PLACEHOLDER_IMGS[(base + 2) % PLACEHOLDER_IMGS.length]);
  }

  return {
    id: ap.id,
    ref: ap.ref_id || null,
    title,
    subtitle: ap.description ? ap.description.substring(0, 80).replace(/\s+\S*$/, "") + "…" : (ap.project_name || `${ap.address}, ${ap.city}`),
    address: ap.address || "",
    city: ap.city || "",
    zip: ap.zip || "",
    region: ap.city || "",
    type: ap.type || "Immobilie",
    category: ap.property_category || "kauf",
    status: ap.status || "inserat",
    price: price,
    priceFrom: ap.type?.toLowerCase().includes("neubau") && ap.units_total > 1,
    area: area,
    rooms: rooms,
    bathrooms: 0,
    parking: 0,
    year: ap.year_built || null,
    yearRenovated: ap.year_renovated || null,
    energyClass: ap.energy_certificate || null,
    description: ap.description || "Weitere Details auf Anfrage.",
    features: ap.features || [],
    images: imgs,
    platforms: ["SR-Homes"],
    highlights: highlights,
    units: ap.units_total || null,
    unitsFree: ap.units_free || null,
  };
};

// ─── useProperties HOOK ─────────────────────────────────────────
const useProperties = () => {
  const [properties, setProperties] = useState(PROPERTIES);
  const [loading, setLoading] = useState(true);
  const [fromApi, setFromApi] = useState(false);

  useEffect(() => {
    let cancelled = false;
    fetch(`${API_BASE}/properties`)
      .then(r => r.json())
      .then(data => {
        if (cancelled) return;
        if (data.success && data.properties && data.properties.length > 0) {
          const mapped = data.properties.map((p, i) => mapApiProperty(p, i));
          setProperties(mapped);
          setFromApi(true);
        }
        // If API returns empty or fails, keep hardcoded PROPERTIES
      })
      .catch(() => { /* keep hardcoded fallback */ })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  return { properties, loading, fromApi };
};

// ─── ICON MAP (for CMS service icons) ───────────────────────────
const ICON_MAP = { TrendingUp, FileText, Globe, Shield, Users, Lock, BarChart3, Zap, Eye, Target, Activity, PieChart, LineChart, MessageSquare, Star, Award, Sparkles, Layers, Home, Building2 };

// ─── useCmsContent HOOK ─────────────────────────────────────────
const DEFAULT_CMS = {
  hero: { headline: "Ihr nächstes<br/>Zuhause wartet.", headline_accent: "Zuhause", subheadline: "Wir verbinden Immobilienexpertise mit modernster Technologie für ein Erlebnis, das den Unterschied macht.", video_url: ASSETS.heroVideo, background_image: ASSETS.heroDesktop },
  stats: { stat_1: { value: "250", suffix: "+", label: "Vermittelt" }, stat_2: { value: "98", suffix: "%", label: "Zufriedenheit" }, stat_3: { value: "52", suffix: "Mio", label: "Volumen 2025" }, stat_4: { value: "15", suffix: "+", label: "Jahre Erfahrung" } },
  about: { parallax_headline: "Unser Herz schlägt für Immobilien.", parallax_text: "Ob Wohnimmobilien, Gewerbeobjekte oder Kapitalanlagen — wir sind Ihr fachkundiger Partner. Für Verkäufer, Vermieter, Käufer und Mieter gleichermaßen.", parallax_image: ASSETS.homeParallax },
  services: {
    service_1: { icon: "TrendingUp", title: "Präzise Bewertung", desc: "Datenbasierte Marktpreisermittlung mit regionaler Expertise." },
    service_2: { icon: "FileText", title: "Premium Exposé", desc: "Professionelle Fotografie, virtuelle Rundgänge, hochwertige Digitalexposés." },
    service_3: { icon: "Globe", title: "Multi-Plattform", desc: "Maximale Reichweite auf willhaben, immowelt, ImmobilienScout24 und mehr." },
    service_4: { icon: "Shield", title: "Rechtssicherheit", desc: "Komplette Abwicklung: Vertragsgestaltung, notarielle Begleitung." },
    service_5: { icon: "Users", title: "Persönlicher Makler", desc: "Ihr dedizierter Ansprechpartner kennt den lokalen Markt." },
    service_6: { icon: "Lock", title: "Digitales Portal", desc: "24/7 transparenter Einblick in den Vermarktungsstand." },
    service_7: { icon: "BarChart3", title: "Marktintelligenz", desc: "EZB-Leitzins, regionale Trends und Asset-Klassen-Analysen." },
    service_8: { icon: "Zap", title: "KI-gestützte Analyse", desc: "Unser Sherlock-System analysiert Marktdaten und optimiert Ihre Strategie." },
  },
  portal: { headline: "Volle Transparenz. Jederzeit. Überall.", subheadline: "Als SR-Homes Kunde sehen Sie in Echtzeit, was mit Ihrer Immobilie passiert. Kein Rätselraten — nur Fakten, Daten und klare nächste Schritte." },
  contact: { address: "Innsbrucker Bundesstraße 73/Top 5\nA-5020 Salzburg, Österreich", phone: "+43 664 2600 930", email: "office@sr-homes.at", hours: "Mo — Fr 8:00 bis 18:00" },
  testimonial: { quote: "Die Zusammenarbeit mit SR-Homes war herausragend. Vom ersten Gespräch bis zur Übergabe fühlen wir uns perfekt betreut. Das Kundenportal ist ein Game-Changer.", author: "Familie Steinberger, Salzburg" },
  branding: { logo_color: ASSETS.logoColor, logo_white: ASSETS.logoWhite },
  seo: { meta_title: "SR-Homes Immobilien GmbH | Salzburg & Oberösterreich", meta_description: "Ihr vertrauensvoller Partner für hochwertige Immobilien in Salzburg und Oberösterreich." },
  team: {},
};

const useCmsContent = () => {
  const [cms, setCms] = useState(DEFAULT_CMS);

  useEffect(() => {
    let cancelled = false;
    fetch(`${API_BASE}/content`)
      .then(r => r.json())
      .then(data => {
        if (cancelled || !data.success || !data.content) return;
        const c = data.content;
        // Deep merge: for each section, merge API values over defaults
        const merged = { ...DEFAULT_CMS };
        for (const section of Object.keys(c)) {
          if (c[section] && typeof c[section] === "object") {
            merged[section] = { ...DEFAULT_CMS[section], ...c[section] };
          } else if (c[section]) {
            merged[section] = c[section];
          }
        }
        setCms(merged);
      })
      .catch(() => { /* keep defaults */ });
    return () => { cancelled = true; };
  }, []);

  return cms;
};

const fmt = (n) => new Intl.NumberFormat("de-AT", { maximumFractionDigits: 0 }).format(n);

// ─── GLOBAL STYLES ────────────────────────────────────────────────
const GlobalStyles = () => (
  <style>{`
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@200;300;400;500;600;700;800;900&display=swap');
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600&display=swap');
    * { font-family: 'Outfit', system-ui, sans-serif; box-sizing: border-box; }
    html { scroll-behavior: smooth; }
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

// ─── HELPER: useTheme ─────────────────────────────────────────────
const useTheme = (dark) => dark ? themes.dark : themes.light;

// ─── EYEBROW ──────────────────────────────────────────────────────
const Eyebrow = ({ children, t }) => (
  <div className="flex items-center gap-3 mb-5">
    <div style={{ width: 32, height: 2, background: t.accent, borderRadius: 2 }} />
    <span className="uppercase tracking-[0.2em] text-xs font-semibold" style={{ color: t.accent, letterSpacing: "0.2em" }}>
      {children}
    </span>
  </div>
);

// ─── BUTTON ───────────────────────────────────────────────────────
const Btn = ({ children, primary, large, icon: Icon, onClick, className = "", style: extraStyle = {} }) => {
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
const StatCounter = ({ value, suffix, label, t, delay = 0 }) => {
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
const PropertyCard = ({ property: p, onClick, t, featured }) => (
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
const FeaturedCard = ({ property: p, onClick, t, index }) => (
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

// ═══════════════════════════════════════════════════════════════════
// NAVIGATION
// ═══════════════════════════════════════════════════════════════════
const Nav = ({ page, setPage, scrolled, t, logos }) => {
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

// ═══════════════════════════════════════════════════════════════════
// FOOTER
// ═══════════════════════════════════════════════════════════════════
const Footer = ({ setPage, t, cms = DEFAULT_CMS, logos }) => {
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
          <button onClick={() => setPage("impressum")} className="hover:text-white/50 transition-colors underline">Impressum</button>
          <button onClick={() => setPage("datenschutz")} className="hover:text-white/50 transition-colors underline">Datenschutz</button>
          <span>© 2026 SR-Homes</span>
        </span>
      </div>
    </div>
  </footer>
  );
};

// ═══════════════════════════════════════════════════════════════════
// HOME PAGE
// ═══════════════════════════════════════════════════════════════════
const HomePage = ({ setPage, setSelected, t, properties = PROPERTIES, cms = DEFAULT_CMS }) => {
  const [searchCat, setSearchCat] = useState("kauf");
  const [videoLoaded, setVideoLoaded] = useState(false);
  const hero = cms.hero || DEFAULT_CMS.hero;
  const stats = cms.stats || DEFAULT_CMS.stats;
  const services = cms.services || DEFAULT_CMS.services;
  const about = cms.about || DEFAULT_CMS.about;
  const portal = cms.portal || DEFAULT_CMS.portal;
  const testimonial = cms.testimonial || DEFAULT_CMS.testimonial;

  return (
    <div>
      {/* ── CINEMATIC HERO with Video ──────────── */}
      <section className="relative min-h-[100dvh]">
        <div className="absolute inset-0 overflow-hidden">
          <video autoPlay muted loop playsInline onLoadedData={() => setVideoLoaded(true)}
            className="w-full h-full object-cover hero-video"
            poster={hero.background_image || ASSETS.heroDesktop}>
            <source src={hero.video_url || ASSETS.heroVideo} type="video/mp4" />
          </video>
          <div className="video-overlay" />
        </div>

        <div className="relative z-10 h-full min-h-[100dvh] flex flex-col justify-end pt-20 md:pt-24" style={{ paddingBottom: "clamp(24px, 4vh, 80px)" }}>
          <div className="max-w-[1440px] mx-auto px-4 sm:px-6 md:px-12 lg:px-16 w-full">
            <div className="max-w-4xl">
              <div className="anim-fade-up hidden md:block">
                <Eyebrow t={{ ...t, accent: "#E8743A" }}>Salzburg &amp; Oberösterreich</Eyebrow>
              </div>

              <h1 className="hero-h1 font-display anim-hero-text anim-d1" style={{ fontSize: "clamp(2.5rem, 5vw, 4.5rem)", fontWeight: 700, lineHeight: 0.95, color: "#fff", letterSpacing: "-0.03em" }}
                dangerouslySetInnerHTML={{ __html: (hero.headline || "Ihr nächstes<br/>Zuhause wartet.").replace(hero.headline_accent || "Zuhause", `<span style="color:#E8743A">${hero.headline_accent || "Zuhause"}</span>`) }}
              />

              <p className="anim-fade-up anim-d2 mt-4 md:mt-8 text-base md:text-xl leading-relaxed max-w-xl" style={{ color: "rgba(255,255,255,0.55)" }}>
                {hero.subheadline || "Wir verbinden Immobilienexpertise mit modernster Technologie für ein Erlebnis, das den Unterschied macht."}
              </p>

              {/* Search Box */}
              <div className="anim-fade-up anim-d3 mt-6 md:mt-10 max-w-3xl">
                <div className="rounded-2xl overflow-hidden" style={{ background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.08)", backdropFilter: "blur(20px)" }}>
                  <div className="p-5 md:p-6">
                    <div className="flex gap-1 mb-5 p-1 rounded-full w-fit" style={{ background: "rgba(255,255,255,0.06)" }}>
                      {["kauf", "miete"].map((c) => (
                        <button key={c} onClick={() => setSearchCat(c)}
                          className="px-6 py-2.5 text-sm font-semibold rounded-full transition-all duration-300 tracking-wide"
                          style={{ background: searchCat === c ? "#E8743A" : "transparent", color: searchCat === c ? "#fff" : "rgba(255,255,255,0.5)" }}
                        >{c === "kauf" ? "KAUFEN" : "MIETEN"}</button>
                      ))}
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                      {[
                        { label: "Objekttyp", opts: ["Alle Typen", "Haus", "Wohnung", "Grundstück", "Neubauprojekt", "Gewerbe"] },
                        { label: "Region", opts: ["Alle Regionen", "Salzburg Stadt", "Flachgau", "Innviertel", "Mondseeland"] },
                        { label: "Preis bis", opts: ["Kein Limit", "EUR 200.000", "EUR 350.000", "EUR 500.000", "EUR 750.000", "EUR 1 Mio.+"] },
                      ].map((f, i) => (
                        <div key={i}>
                          <label className="text-xs font-semibold mb-1.5 block uppercase tracking-widest" style={{ color: "rgba(255,255,255,0.3)" }}>{f.label}</label>
                          <select className="w-full px-4 py-3 rounded-xl text-sm font-medium border-0"
                            style={{ background: "rgba(255,255,255,0.08)", color: "#fff", appearance: "none" }}>
                            {f.opts.map((o) => <option key={o} style={{ color: "#000" }}>{o}</option>)}
                          </select>
                        </div>
                      ))}
                      <div className="flex items-end">
                        <button onClick={() => setPage("immobilien")}
                          className="w-full flex items-center justify-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white transition-all hover:scale-[1.02] active:scale-[0.98]"
                          style={{ background: "#E8743A", letterSpacing: "0.1em" }}>
                          <Search size={16} /> SUCHEN
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </section>

      {/* ── STATS SECTION (huge numbers) ───────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg, borderBottom: `1px solid ${t.borderLight}` }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-12 md:gap-8">
            {Object.values(stats).filter(s => s && s.value).map((s, i) => (
              <StatCounter key={i} value={s.value} suffix={s.suffix} label={s.label} t={t} delay={i * 0.15} />
            ))}
          </div>
        </div>
      </section>

      {/* ── FEATURED PROPERTIES (SERHANT-Style) ── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="flex flex-col md:flex-row md:items-end justify-between gap-8 mb-14">
            <div>
              <Eyebrow t={t}>Exklusive Objekte</Eyebrow>
              <h2 className="font-display section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
                Unsere Top-Immobilien
              </h2>
            </div>
            <Btn icon={ArrowRight} onClick={() => setPage("immobilien")} style={{ color: t.text, borderColor: t.border }}>
              Alle {properties.length} Objekte
            </Btn>
          </div>

          {properties.length > 0 && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <FeaturedCard property={properties[0]} onClick={() => { setSelected(properties[0]); setPage("detail"); }} t={t} index={0} />
            {properties.length > 2 && (
            <div className="grid grid-rows-2 gap-5">
              <FeaturedCard property={properties[Math.min(3, properties.length - 1)]} onClick={() => { setSelected(properties[Math.min(3, properties.length - 1)]); setPage("detail"); }} t={t} index={1} />
              <FeaturedCard property={properties[Math.min(5, properties.length - 1)]} onClick={() => { setSelected(properties[Math.min(5, properties.length - 1)]); setPage("detail"); }} t={t} index={2} />
            </div>
            )}
          </div>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-6">
            {properties.slice(1, 4).map((p) => (
              <PropertyCard key={p.id} property={p} onClick={() => { setSelected(p); setPage("detail"); }} t={t} />
            ))}
          </div>
        </div>
      </section>

      {/* ── FULL-WIDTH IMAGE BREAK ─────────────── */}
      <section className="relative" style={{ height: 500 }}>
        <img src={about.parallax_image || ASSETS.homeParallax} alt="" className="absolute inset-0 w-full h-full object-cover" />
        <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.85) 0%, rgba(10,10,8,0.3) 60%, transparent 100%)" }} />
        <div className="relative z-10 h-full flex items-center">
          <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
            <div className="max-w-xl">
              <h2 className="font-display text-3xl md:text-4xl lg:text-5xl xl:text-6xl font-bold text-white tracking-tight leading-none mb-6">
                {about.parallax_headline}
              </h2>
              <p className="text-base text-white/50 leading-relaxed mb-8">
                {about.parallax_text}
              </p>
              <Btn primary large icon={ArrowUpRight} onClick={() => setPage("über")}>Über uns</Btn>
            </div>
          </div>
        </div>
      </section>

      {/* ── SERVICES GRID ──────────────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-16">
            <div className="lg:col-span-5 lg:sticky lg:top-32 self-start">
              <Eyebrow t={t}>Unsere Leistungen</Eyebrow>
              <h2 className="font-display mb-6 section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
                Rundum-Service auf höchstem Niveau
              </h2>
              <p className="text-base leading-relaxed mb-10" style={{ color: t.textSecondary }}>
                Von der präzisen Marktbewertung über professionelle Vermarktung bis zum erfolgreichen Abschluss — wir übernehmen alles.
              </p>
              <div className="flex flex-col sm:flex-row gap-4">
                <Btn primary icon={ArrowUpRight} onClick={() => setPage("verkaufen")}>Verkaufen</Btn>
                <Btn icon={ArrowRight} onClick={() => setPage("bewerten")} style={{ color: t.text, borderColor: t.border }}>Bewerten</Btn>
              </div>
            </div>
            <div className="lg:col-span-7 grid grid-cols-1 sm:grid-cols-2 gap-5">
              {Object.values(services).filter(s => s && s.title).map((s, i) => {
                const IconComp = ICON_MAP[s.icon] || Zap;
                return (
                  <div key={i} className="hover-lift hover-glow p-7 rounded-2xl" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                    <div className="w-12 h-12 rounded-xl flex items-center justify-center mb-5" style={{ background: t.accentLight }}>
                      <IconComp size={22} style={{ color: t.accent }} />
                    </div>
                    <h3 className="text-base font-bold tracking-tight mb-2" style={{ color: t.text }}>{s.title}</h3>
                    <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{s.desc}</p>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </section>

      {/* ── KUNDENPORTAL SHOWCASE (Customer POV) ── */}
      <section className="py-24 md:py-36 overflow-hidden" style={{ background: "#0A0A08" }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="text-center mb-20">
            <Eyebrow t={{ ...t, accent: "#E8743A" }}>Ihr Kundenportal</Eyebrow>
            <h2 className="font-display section-heading mx-auto" style={{ fontSize: "clamp(2rem, 5vw, 4rem)", fontWeight: 700, color: "#fff", lineHeight: 1, letterSpacing: "-0.03em", maxWidth: 700 }}>
              {portal.headline}
            </h2>
            <p className="text-lg text-white/40 mt-6 max-w-2xl mx-auto leading-relaxed">
              {portal.subheadline}
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            {[
              { icon: Activity, title: "Live-Aktivitäten", desc: "Jede Anfrage, jedes Exposé, jede Besichtigung — Sie sehen alles in Echtzeit. Wissen Sie immer genau, wo Sie stehen.", big: "815+", sub: "Aktivitäten getrackt" },
              { icon: Eye, title: "Besichtigungs-Feedback", desc: "Lesen Sie, was Interessenten nach der Besichtigung sagen. Direktes, ungefiltertes Feedback — die Basis für kluge Entscheidungen.", big: "100%", sub: "Transparenz" },
              { icon: Target, title: "Vermarktungs-Trichter", desc: "Verfolgen Sie den Weg vom Lead zum Kaufanbot. Wie viele Anfragen, Besichtigungen und Angebote gibt es? Alles visualisiert.", big: "192", sub: "Anfragen gesamt" },
              { icon: PieChart, title: "Plattform-Analyse", desc: "Sehen Sie genau, welche Plattform die meisten qualifizierten Anfragen liefert. willhaben, immowelt, ImmobilienScout24 — alles aufgeschlüsselt.", big: "5", sub: "Plattformen verbunden" },
              { icon: LineChart, title: "Marktbericht", desc: "Datengestützte Marktanalyse für Ihre Region: Preisentwicklung, EZB-Leitzins, Vergleichsobjekte. Fundierte Entscheidungsgrundlage.", big: "EUR 52M", sub: "analysiertes Volumen" },
              { icon: MessageSquare, title: "Direkter Draht", desc: "Senden Sie Nachrichten, teilen Sie Dokumente, stellen Sie Fragen — alles sicher und archiviert in Ihrem persönlichen Portal.", big: "24/7", sub: "Verfügbar" },
            ].map((f, i) => (
              <div key={i} className="p-7 rounded-2xl transition-all duration-500 hover:translate-y-[-4px]"
                style={{ background: "rgba(255,255,255,0.03)", border: "1px solid rgba(255,255,255,0.06)" }}>
                <div className="flex items-center gap-3 mb-5">
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ background: "rgba(232,116,58,0.12)" }}>
                    <f.icon size={18} style={{ color: "#E8743A" }} />
                  </div>
                  <h3 className="text-base font-bold text-white">{f.title}</h3>
                </div>
                <div className="mb-4">
                  <span className="text-3xl font-black tracking-tighter text-white">{f.big}</span>
                  <span className="text-xs text-white/30 ml-2 uppercase tracking-wider">{f.sub}</span>
                </div>
                <p className="text-sm leading-relaxed text-white/40">{f.desc}</p>
              </div>
            ))}
          </div>

          <div className="text-center mt-14">
            <Btn primary large icon={ArrowUpRight} onClick={() => setPage("portal")}>Portal entdecken</Btn>
          </div>
        </div>
      </section>

      {/* ── DATA-DRIVEN EXPERTISE SECTION ──────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
            <div>
              <Eyebrow t={t}>Datengetriebene Expertise</Eyebrow>
              <h2 className="font-display mb-6 section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
                Entscheidungen basierend auf Daten, nicht Bauchgefühl
              </h2>
              <p className="text-base leading-relaxed mb-8" style={{ color: t.textSecondary }}>
                Unser Analyse-System verarbeitet täglich hunderte Datenpunkte: EZB-Leitzinsentwicklungen, regionale Immobilienpreise, Plattform-Performance und Markttrends. Diese Intelligenz fliesst in jede Beratung und jede Vermarktungsstrategie ein.
              </p>

              <div className="space-y-5">
                {[
                  { label: "Regionaler Marktbericht", desc: "Salzburg, Flachgau, Innviertel — aktuelle Preistrends und Prognosen" },
                  { label: "EZB-Leitzins Monitoring", desc: "Wie Zinsentwicklungen den Immobilienmarkt beeinflussen" },
                  { label: "Vergleichsobjekt-Analyse", desc: "Reale Verkaufspreise ähnlicher Immobilien in Ihrer Umgebung" },
                  { label: "Plattform-Performance", desc: "Welche Kanäle die qualifiziertesten Anfragen liefern" },
                  { label: "Verkaufstrichter-Analyse", desc: "Conversion-Raten von Anfrage bis Kaufanbot" },
                ].map((item, i) => (
                  <div key={i} className="flex items-start gap-4 p-4 rounded-xl" style={{ background: t.bgAlt }}>
                    <CheckCircle size={18} style={{ color: t.accent }} className="mt-0.5 shrink-0" />
                    <div>
                      <div className="text-sm font-bold" style={{ color: t.text }}>{item.label}</div>
                      <div className="text-xs mt-0.5" style={{ color: t.textMuted }}>{item.desc}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Data Visualization Mockup */}
            <div className="p-1.5 rounded-3xl" style={{ background: t.bgAlt, border: `1px solid ${t.borderLight}` }}>
              <div className="rounded-2xl overflow-hidden p-6" style={{ background: t.bgCard }}>
                <div className="flex items-center justify-between mb-6">
                  <div>
                    <div className="text-xs font-bold uppercase tracking-widest" style={{ color: t.textMuted }}>Marktanalyse</div>
                    <div className="text-lg font-bold" style={{ color: t.text }}>Salzburg Region</div>
                  </div>
                  <span className="px-3 py-1 rounded-full text-xs font-semibold" style={{ background: t.accentLight, color: t.accent }}>Live</span>
                </div>

                <div className="grid grid-cols-3 gap-3 mb-6">
                  {[
                    { l: "Durchschn. m²-Preis", v: "EUR 5.280", c: "+3.2%" },
                    { l: "EZB Leitzins", v: "2,65%", c: "-0.25%" },
                    { l: "Ø Vermarktungszeit", v: "42 Tage", c: "-8 T." },
                  ].map((d, i) => (
                    <div key={i} className="p-4 rounded-xl" style={{ background: t.bgAlt }}>
                      <div className="text-xs mb-1" style={{ color: t.textMuted }}>{d.l}</div>
                      <div className="text-lg font-bold" style={{ color: t.text }}>{d.v}</div>
                      <div className="text-xs font-semibold" style={{ color: d.c.startsWith("+") || d.c.startsWith("-") ? t.accent : t.textMuted }}>{d.c}</div>
                    </div>
                  ))}
                </div>

                {/* Fake Chart */}
                <div className="mb-4">
                  <div className="text-xs font-bold mb-3 uppercase tracking-wider" style={{ color: t.textMuted }}>Anfragen-Trend (8 Wochen)</div>
                  <div className="flex items-end gap-2" style={{ height: 120 }}>
                    {[45, 62, 55, 78, 85, 72, 90, 95].map((h, i) => (
                      <div key={i} className="flex-1 rounded-t-md transition-all duration-500" style={{ height: `${h}%`, background: i === 7 ? t.accent : t.accentLight }} />
                    ))}
                  </div>
                  <div className="flex justify-between mt-2 text-xs" style={{ color: t.textLight }}>
                    <span>KW 5</span><span>KW 6</span><span>KW 7</span><span>KW 8</span><span>KW 9</span><span>KW 10</span><span>KW 11</span><span>KW 12</span>
                  </div>
                </div>

                <div className="pt-4" style={{ borderTop: `1px solid ${t.borderLight}` }}>
                  <div className="text-xs font-bold mb-3 uppercase tracking-wider" style={{ color: t.textMuted }}>Plattform-Performance</div>
                  {[
                    { name: "willhaben.at", pct: 42 },
                    { name: "SR-Homes Website", pct: 28 },
                    { name: "immowelt.at", pct: 18 },
                    { name: "ImmobilienScout24", pct: 12 },
                  ].map((p, i) => (
                    <div key={i} className="mb-3">
                      <div className="flex justify-between text-xs mb-1">
                        <span style={{ color: t.textSecondary }}>{p.name}</span>
                        <span className="font-bold" style={{ color: t.text }}>{p.pct}%</span>
                      </div>
                      <div className="h-2 rounded-full overflow-hidden" style={{ background: t.bgAlt }}>
                        <div className="h-full rounded-full transition-all duration-1000" style={{ width: `${p.pct}%`, background: i === 0 ? t.accent : `${t.accent}${60 - i * 15}` }} />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── PROCESS / HOW WE WORK ─────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bgAlt }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="text-center mb-20">
            <Eyebrow t={t}>Unser Prozess</Eyebrow>
            <h2 className="font-display section-heading" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, lineHeight: 1, letterSpacing: "-0.03em" }}>
              In vier Schritten zum Erfolg
            </h2>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            {[
              { n: "01", title: "Erstberatung", desc: "Persönliches Kennenlernen, Vor-Ort-Besichtigung und gemeinsame Preisfindung auf Basis unserer Marktdaten.", icon: Users },
              { n: "02", title: "Aufbereitung", desc: "Professionelle Fotografie, virtuelle Rundgänge, Premium-Exposé, Beschaffung aller Unterlagen.", icon: FileText },
              { n: "03", title: "Vermarktung", desc: "Multi-Plattform-Strategie, qualifizierte Interessenten, Besichtigungsorganisation. Alles live im Portal.", icon: Globe },
              { n: "04", title: "Abschluss", desc: "Kaufvertragsverhandlung, notarielle Begleitung, Übergabe mit Protokoll. Ihr Makler bis zum letzten Schritt.", icon: CheckCircle },
            ].map((s, i) => (
              <div key={i} className="hover-lift p-8 rounded-2xl relative" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
                <span className="text-6xl font-black tracking-tighter absolute top-4 right-6" style={{ color: t.accentLight }}>{s.n}</span>
                <div className="relative">
                  <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-6" style={{ background: t.accentLight }}>
                    <s.icon size={24} style={{ color: t.accent }} />
                  </div>
                  <h3 className="text-xl font-bold tracking-tight mb-3" style={{ color: t.text }}>{s.title}</h3>
                  <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{s.desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── TEAM TEASER ────────────────────────── */}
      <section className="relative" style={{ height: 600 }}>
        <img src={ASSETS.teamImage} alt="SR-Homes Team" className="absolute inset-0 w-full h-full object-cover" />
        <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.9) 0%, rgba(10,10,8,0.4) 60%, rgba(10,10,8,0.2) 100%)" }} />
        <div className="relative z-10 h-full flex items-center">
          <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
            <div className="max-w-lg">
              <Eyebrow t={{ ...t, accent: "#E8743A" }}>Unser Team</Eyebrow>
              <h2 className="font-display text-3xl md:text-4xl lg:text-5xl xl:text-6xl font-bold text-white tracking-tight leading-none mb-6">
                Menschen, die den Unterschied machen.
              </h2>
              <p className="text-base text-white/50 leading-relaxed mb-8">
                Maximilian Hölzl und sein Team verbinden jahrelange Erfahrung mit Leidenschaft für Immobilien. Persönlich, kompetent, engagiert.
              </p>
              <Btn primary large icon={ArrowUpRight} onClick={() => setPage("über")}>Team kennenlernen</Btn>
            </div>
          </div>
        </div>
      </section>

      {/* ── TESTIMONIAL / TRUST ────────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="max-w-4xl mx-auto text-center">
            <div className="flex justify-center gap-1 mb-8">
              {[1,2,3,4,5].map((s) => <Star key={s} size={24} fill={t.accent} style={{ color: t.accent }} />)}
            </div>
            <blockquote className="font-display text-2xl md:text-4xl font-medium leading-snug mb-8" style={{ color: t.text, fontStyle: "italic" }}>
              "Die Zusammenarbeit mit SR-Homes war herausragend. Vom ersten Gespräch bis zur Übergabe fühlen wir uns perfekt betreut. Das Kundenportal ist ein Game-Changer — wir konnten jeden Schritt live mitverfolgen."
            </blockquote>
            <div className="text-sm font-semibold" style={{ color: t.text }}>Familie Steinberger</div>
            <div className="text-xs" style={{ color: t.textMuted }}>Verkauf Einfamilienhaus, Salzburg</div>
          </div>
        </div>
      </section>

      {/* ── CTA SECTION ────────────────────────── */}
      <section className="py-24 md:py-36 laptop-py" style={{ background: t.bgAlt }}>
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
          <div className="relative rounded-3xl overflow-hidden" style={{ minHeight: 500 }}>
            <img src={ASSETS.contactImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
            <div className="absolute inset-0" style={{ background: "linear-gradient(135deg, rgba(10,10,8,0.92) 0%, rgba(10,10,8,0.6) 100%)" }} />
            <div className="relative z-10 flex flex-col items-center justify-center text-center px-8 py-24">
              <h2 className="font-display text-3xl md:text-4xl lg:text-5xl xl:text-7xl font-bold text-white tracking-tight leading-none mb-6">
                Bereit für den<br/><span style={{ color: "#E8743A" }}>nächsten Schritt?</span>
              </h2>
              <p className="text-lg text-white/45 max-w-xl mb-10 leading-relaxed">
                Vereinbaren Sie Ihr kostenloses Erstgespräch. Wir nehmen uns Zeit für Ihre Fragen und entwickeln gemeinsam die beste Strategie.
              </p>
              <div className="flex flex-wrap gap-4 justify-center">
                <Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Erstgespräch vereinbaren</Btn>
                <Btn large icon={Phone} onClick={() => window.open("tel:+436642600930")} style={{ color: "#fff", borderColor: "rgba(255,255,255,0.2)" }}>+43 664 2600 930</Btn>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
};

// ═══════════════════════════════════════════════════════════════════
// IMMOBILIEN PAGE
// ═══════════════════════════════════════════════════════════════════
const ImmobilienPage = ({ setPage, setSelected, t, properties = PROPERTIES }) => {
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

// ═══════════════════════════════════════════════════════════════════
// DETAIL PAGE
// ═══════════════════════════════════════════════════════════════════
const DetailPage = ({ property, setPage, setSelected, t, properties = PROPERTIES }) => {
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

// ═══════════════════════════════════════════════════════════════════
// VERKAUFEN PAGE
// ═══════════════════════════════════════════════════════════════════
const VerkaufenPage = ({ setPage, t, properties = PROPERTIES }) => (
  <div className="pt-20">
    <section className="relative" style={{ height: "70vh", minHeight: 500 }}>
      <img src={ASSETS.sellImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.9) 0%, rgba(10,10,8,0.3) 100%)" }} />
      <div className="relative z-10 h-full flex items-center">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
          <div className="max-w-2xl">
            <Eyebrow t={{ accent: "#E8743A" }}>Verkaufen</Eyebrow>
            <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
              Ihre Immobilie verdient den besten Preis.
            </h1>
            <p className="text-lg text-white/50 mt-6 max-w-lg leading-relaxed">Mit unserem 14-Punkte Rundum-Service, datengestützter Marktanalyse und persönlichem Engagement erzielen wir für Sie das optimale Ergebnis.</p>
            <div className="mt-10"><Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Kostenlose Erstberatung</Btn></div>
          </div>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="text-center mb-16">
          <h2 className="font-display" style={{ fontSize: "clamp(2rem, 4vw, 3.5rem)", fontWeight: 700, color: t.text, letterSpacing: "-0.03em" }}>14 Leistungen. Ein Paket.</h2>
          <p className="text-base mt-4 max-w-xl mx-auto" style={{ color: t.textSecondary }}>Alles was Sie für einen erfolgreichen, stressfreien Verkauf brauchen.</p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[
            "Professionelle Bewertung Ihrer Immobilie",
            "Beschaffung aller Objektunterlagen",
            "Professionelle Fotoaufnahmen und Drohnenbilder",
            "Verkauf zum bestmöglichen Marktpreis",
            "Inserate auf allen relevanten Plattformen",
            "Organisation des Energieausweises",
            "Aufbereitung aller Grundrisse",
            "Hochwertiges Exposé in Print und Digital",
            "Verkaufsschilder und Banner auf Wunsch",
            "Social Media Marketing (Facebook, Instagram)",
            "Prüfung der Finanzierung des Interessenten",
            "Führung von Kaufpreisverhandlungen",
            "Kaufvertragsbesprechung und notarielle Begleitung",
            "Begleitung der Übergabe mit Protokoll",
          ].map((s, i) => (
            <div key={i} className="flex items-center gap-5 p-6 rounded-xl hover-lift" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <div className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-sm font-black" style={{ background: t.accentLight, color: t.accent }}>{String(i + 1).padStart(2, "0")}</div>
              <span className="text-sm font-semibold" style={{ color: t.text }}>{s}</span>
            </div>
          ))}
        </div>
        <div className="text-center mt-16"><Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Jetzt Verkauf starten</Btn></div>
      </div>
    </section>
  </div>
);

// ═══════════════════════════════════════════════════════════════════
// BEWERTEN PAGE
// ═══════════════════════════════════════════════════════════════════
const BewertenPage = ({ setPage, t }) => (
  <div className="pt-20">
    <section className="relative" style={{ height: "70vh", minHeight: 500 }}>
      <img src={ASSETS.valueImage} alt="" className="absolute inset-0 w-full h-full object-cover" />
      <div className="absolute inset-0" style={{ background: "linear-gradient(to right, rgba(10,10,8,0.9) 0%, rgba(10,10,8,0.3) 100%)" }} />
      <div className="relative z-10 h-full flex items-center">
        <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16 w-full">
          <div className="max-w-2xl">
            <Eyebrow t={{ accent: "#E8743A" }}>Bewertung</Eyebrow>
            <h1 className="font-display text-white page-hero-h1" style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)", fontWeight: 700, lineHeight: 0.95, letterSpacing: "-0.03em" }}>
              Was ist Ihre Immobilie wirklich wert?
            </h1>
            <p className="text-lg text-white/50 mt-6 max-w-lg leading-relaxed">Keine Mondpreise. Sondern eine ehrliche, datenbasierte Bewertung auf Grundlage aktueller Marktdaten und unserer lokalen Expertise.</p>
            <div className="mt-10"><Btn primary large icon={ArrowUpRight} onClick={() => setPage("kontakt")}>Kostenlose Bewertung</Btn></div>
          </div>
        </div>
      </div>
    </section>

    <section className="py-24 md:py-36 laptop-py" style={{ background: t.bg }}>
      <div className="max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {[
            { n: "01", title: "Vor-Ort Besichtigung", desc: "Wir kommen zu Ihnen, begutachten die Immobilie und erfassen alle wertrelevanten Faktoren: Zustand, Lage, Ausstattung, Potential.", icon: Home },
            { n: "02", title: "Datengestützte Analyse", desc: "Vergleichspreise, EZB-Zinsen, regionale Trends — unsere Marktintelligenz-Plattform liefert die Basis für eine präzise Einschätzung.", icon: BarChart3 },
            { n: "03", title: "Bewertungsbericht", desc: "Sie erhalten einen transparenten Bewertungsbericht mit nachvollziehbarer Herleitung. Die perfekte Basis für Ihre Entscheidung.", icon: FileText },
          ].map((s, i) => (
            <div key={i} className="hover-lift p-10 rounded-2xl relative" style={{ background: t.bgCard, border: `1px solid ${t.borderLight}` }}>
              <span className="text-7xl font-black absolute top-4 right-6" style={{ color: t.accentLight }}>{s.n}</span>
              <div className="relative">
                <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-6" style={{ background: t.accentLight }}><s.icon size={24} style={{ color: t.accent }} /></div>
                <h3 className="text-xl font-bold tracking-tight mb-3" style={{ color: t.text }}>{s.title}</h3>
                <p className="text-sm leading-relaxed" style={{ color: t.textMuted }}>{s.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  </div>
);

// ═══════════════════════════════════════════════════════════════════
// PORTAL PAGE (Customer Benefits Focus)
// ═══════════════════════════════════════════════════════════════════
const PortalPage = ({ setPage, t, cms = DEFAULT_CMS }) => (
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
              {properties.slice(0, 3).map((p, i) => (
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

// ═══════════════════════════════════════════════════════════════════
// UEBER UNS PAGE
// ═══════════════════════════════════════════════════════════════════
const ÜberPage = ({ setPage, t }) => (
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

// ═══════════════════════════════════════════════════════════════════
// KONTAKT PAGE
// ═══════════════════════════════════════════════════════════════════
const KontaktPage = ({ t, cms = DEFAULT_CMS }) => (
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

// ═══════════════════════════════════════════════════════════════════
// IMPRESSUM PAGE
// ═══════════════════════════════════════════════════════════════════
const ImpressumPage = ({ t, cms = DEFAULT_CMS }) => {
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

// ═══════════════════════════════════════════════════════════════════
// DATENSCHUTZ PAGE
// ═══════════════════════════════════════════════════════════════════
const DatenschutzPage = ({ t, cms = DEFAULT_CMS }) => {
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

// ═══════════════════════════════════════════════════════════════════
// MAIN APP
// ═══════════════════════════════════════════════════════════════════
// ─── URL ROUTING HELPERS ──────────────────────────────────────────
const viewToPath = { home: "/", immobilien: "/immobilien", detail: "/objekt", verkaufen: "/verkaufen", bewerten: "/bewerten", portal: "/portal", "über": "/ueber-uns", kontakt: "/kontakt", impressum: "/impressum", datenschutz: "/datenschutz" };
const pathToView = Object.fromEntries(Object.entries(viewToPath).map(([k, v]) => [v, k]));
const viewTitles = { home: "SR-Homes Immobilien", immobilien: "Immobilien", detail: "Objekt", verkaufen: "Verkaufen", bewerten: "Bewertung", portal: "Kundenportal", "über": "Über uns", kontakt: "Kontakt", impressum: "Impressum", datenschutz: "Datenschutz" };

export default function App() {
  const initialPage = pathToView[window.location.pathname] || "home";
  const [page, setPage] = useState(initialPage);
  const [scrolled, setScrolled] = useState(false);
  const [selected, setSelected] = useState(null);
  const t = useTheme(false);
  const { properties } = useProperties();
  const cms = useCmsContent();

  // Override ASSETS with CMS branding
  const logos = useMemo(() => ({
    color: cms.branding?.logo_color || ASSETS.logoColor,
    white: cms.branding?.logo_white || ASSETS.logoWhite,
  }), [cms.branding]);

  const go = useCallback((p) => {
    if (window._srPopState) return setPage(p);
    setPage(p);
    const path = viewToPath[p] || "/";
    const title = viewTitles[p] || "SR-Homes";
    window.history.pushState({ view: p }, title, path);
    document.title = title + " | SR-Homes";
    window.scrollTo({ top: 0, behavior: "smooth" });
  }, []);

  // Handle browser back/forward
  useEffect(() => {
    const onPop = () => {
      const view = pathToView[window.location.pathname] || "home";
      window._srPopState = true;
      setPage(view);
      window.scrollTo({ top: 0, behavior: "smooth" });
      setTimeout(() => { window._srPopState = false; }, 100);
    };
    window.addEventListener("popstate", onPop);
    // Set initial history state
    const initPath = viewToPath[initialPage] || "/";
    window.history.replaceState({ view: initialPage }, document.title, initPath);
    return () => window.removeEventListener("popstate", onPop);
  }, [initialPage]);

  useEffect(() => {
    const fn = () => setScrolled(window.scrollY > 50);
    window.addEventListener("scroll", fn, { passive: true });
    return () => window.removeEventListener("scroll", fn);
  }, []);

  // Update page title from CMS SEO
  useEffect(() => {
    if (cms.seo?.meta_title && page === "home") document.title = cms.seo.meta_title;
    const metaDesc = document.querySelector('meta[name="description"]');
    if (metaDesc && cms.seo?.meta_description) metaDesc.setAttribute("content", cms.seo.meta_description);
  }, [cms.seo, page]);

  const pages = {
    home: <HomePage setPage={go} setSelected={setSelected} t={t} properties={properties} cms={cms} />,
    immobilien: <ImmobilienPage setPage={go} setSelected={setSelected} t={t} properties={properties} />,
    detail: <DetailPage property={selected} setPage={go} setSelected={setSelected} t={t} properties={properties} />,
    verkaufen: <VerkaufenPage setPage={go} t={t} properties={properties} />,
    bewerten: <BewertenPage setPage={go} t={t} />,
    portal: <PortalPage setPage={go} t={t} cms={cms} />,
    über: <ÜberPage setPage={go} t={t} />,
    kontakt: <KontaktPage t={t} cms={cms} />,
    impressum: <ImpressumPage t={t} cms={cms} />,
    datenschutz: <DatenschutzPage t={t} cms={cms} />,
  };

  return (
    <div className="min-h-screen" style={{ background: t.bg, color: t.text }}>
      <GlobalStyles />
      <div className="grain" />
      <Nav page={page} setPage={go} scrolled={scrolled || page !== "home"} t={t} logos={logos} />
      <main>{pages[page] || pages.home}</main>
      <Footer setPage={go} t={t} cms={cms} logos={logos} />
    </div>
  );
}
