<script setup>
import { onMounted, watch, ref, onBeforeUnmount } from "vue";

const props = defineProps({
  lat: { type: Number, required: true },
  lng: { type: Number, required: true },
  radiusM: { type: Number, default: 350 },
  height: { type: String, default: "240px" },
});

const container = ref(null);
let map = null;
let circle = null;
let leafletLoaded = false;

async function ensureLeaflet() {
  if (window.L) { leafletLoaded = true; return; }
  // CSS
  if (!document.getElementById("leaflet-css")) {
    const link = document.createElement("link");
    link.id = "leaflet-css";
    link.rel = "stylesheet";
    link.href = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";
    link.integrity = "sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=";
    link.crossOrigin = "";
    document.head.appendChild(link);
  }
  // Styles: grayscale + kraeftiger Kontrast = schwarze Straßen auf hell
  if (!document.getElementById("sr-map-preview-style")) {
    const style = document.createElement("style");
    style.id = "sr-map-preview-style";
    style.textContent = `
      .sr-map-preview .leaflet-tile-pane{
        filter:grayscale(1) contrast(1.1);
      }
      .sr-map-preview .leaflet-container{background:#FAF8F5}
      .sr-map-preview .leaflet-control-attribution{
        background:rgba(255,255,255,0.9);font-size:10px;
      }
    `;
    document.head.appendChild(style);
  }
  // JS
  if (!document.getElementById("leaflet-js")) {
    await new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.id = "leaflet-js";
      s.src = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";
      s.integrity = "sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=";
      s.crossOrigin = "";
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }
  leafletLoaded = !!window.L;
}

function renderMap() {
  if (!leafletLoaded || !container.value) return;
  const L = window.L;
  if (!map) {
    map = L.map(container.value, {
      scrollWheelZoom: false,
      zoomControl: true,
      attributionControl: true,
    }).setView([props.lat, props.lng], 13);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
      maxZoom: 19,
      subdomains: 'abcd',
      attribution: '&copy; OSM · &copy; CARTO',
    }).addTo(map);
  } else {
    map.setView([props.lat, props.lng], 14);
  }
  if (circle) circle.remove();
  circle = L.circle([props.lat, props.lng], {
    radius: props.radiusM,
    color: '#D4743B',
    weight: 2.5,
    fillColor: '#D4743B',
    fillOpacity: 0.2,
  }).addTo(map);
}

onMounted(async () => {
  await ensureLeaflet();
  renderMap();
});

watch(() => [props.lat, props.lng], async () => {
  if (!leafletLoaded) await ensureLeaflet();
  renderMap();
});

onBeforeUnmount(() => {
  if (map) { map.remove(); map = null; }
});
</script>

<template>
  <div class="rounded-lg overflow-hidden border border-zinc-200 sr-map-preview"
       :style="{ height }">
    <div ref="container" class="w-full h-full"></div>
  </div>
</template>
