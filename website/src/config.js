import {
  TrendingUp, FileText, Globe, Shield, Users, Lock, BarChart3, Zap, Eye, Target,
  Activity, PieChart, LineChart, MessageSquare, Star, Award, Sparkles, Layers, Home, Building2
} from "lucide-react";

// ─── API CONFIGURATION ──────────────────────────────────────────
export const API_BASE = "https://kundenportal.sr-homes.at/api/website";

// ─── REAL SR-HOMES ASSETS ─────────────────────────────────────────
export const ASSETS = {
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
export const themes = {
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
export const PROPERTIES = [
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
export const PLACEHOLDER_IMGS = [
  "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1583608205776-bfd35f0d9f83?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200&h=800&fit=crop",
  "https://images.unsplash.com/photo-1602343168117-bb8bbe693920?w=1200&h=800&fit=crop",
];

// ─── ICON MAP (for CMS service icons) ───────────────────────────
export const ICON_MAP = { TrendingUp, FileText, Globe, Shield, Users, Lock, BarChart3, Zap, Eye, Target, Activity, PieChart, LineChart, MessageSquare, Star, Award, Sparkles, Layers, Home, Building2 };

// ─── useCmsContent HOOK ─────────────────────────────────────────
export const DEFAULT_CMS = {
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
