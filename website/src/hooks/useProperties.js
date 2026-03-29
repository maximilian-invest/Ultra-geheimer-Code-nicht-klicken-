import { useState, useEffect } from "react";
import { API_BASE, PROPERTIES, PLACEHOLDER_IMGS } from "../config.js";

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
export const useProperties = () => {
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
