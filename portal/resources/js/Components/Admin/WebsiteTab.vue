<script setup>
import { ref, inject, onMounted, computed } from "vue";
import {
  Save, Upload, Eye, EyeOff, Globe, Image, Video,
  BarChart3, Phone, Shield, Lock, Star, Sparkles,
  RefreshCw, ExternalLink, Home, Loader2, Check,
  Plus, Trash2, Users
} from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");

// ── State ─────────────────────────────────────────────
const loading = ref(true);
const saving = ref(false);
const uploading = ref("");  // z.B. "hero:video_url" während Upload
const activeSection = ref("hero");

// CMS Content (grouped by section)
const cms = ref({});

// ── Sections Config (ohne Objekte — das läuft über Portale in Objektdaten) ──
const sections = [
  { key: "hero", label: "Hero", icon: Video },
  { key: "stats", label: "Statistiken", icon: BarChart3 },
  { key: "services", label: "Leistungen", icon: Shield },
  { key: "about", label: "Über uns & Team", icon: Image },
  { key: "portal", label: "Portal", icon: Lock },
  { key: "contact", label: "Kontakt", icon: Phone },
  { key: "testimonial", label: "Referenzen", icon: Star },
  { key: "seo", label: "SEO", icon: Globe },
  { key: "branding", label: "Branding", icon: Sparkles },
  { key: "legal", label: "Rechtliches", icon: Shield },
];

// ── Load ──────────────────────────────────────────────
onMounted(async () => {
  await loadContent();
  loading.value = false;
});

async function loadContent() {
  try {
    const r = await fetch(API.value + "&action=website_content_list");
    const d = await r.json();
    if (d.success) {
      const grouped = {};
      // API returns items[] with content_key / content_value
      (d.items || []).forEach(e => {
        if (!grouped[e.section]) grouped[e.section] = {};
        let val = e.content_value;
        if (e.content_type === 'json' && typeof val === 'string') {
          try { val = JSON.parse(val); } catch {}
        } else if (typeof val === 'object' && val !== null) {
          // already parsed by backend
        }
        grouped[e.section][e.content_key] = val;
      });
      cms.value = grouped;
    }
  } catch (e) { toast("Fehler beim Laden: " + e.message); }
}

// ── Save entire section ───────────────────────────────
async function saveSection(section) {
  const data = cms.value[section];
  if (!data) return;
  saving.value = true;
  let ok = 0;
  for (const [key, value] of Object.entries(data)) {
    try {
      const isObj = typeof value === "object" && value !== null;
      const body = {
        section,
        content_key: key,
        content_type: isObj ? "json" : "text",
        content_value: isObj ? JSON.stringify(value) : (value ?? ""),
      };
      const r = await fetch(API.value + "&action=website_content_save", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const d = await r.json();
      if (d.success) ok++;
    } catch {}
  }
  toast(ok + " Einträge gespeichert!");
  saving.value = false;
  clearCache();
}

// ── Upload file ───────────────────────────────────────
async function uploadFile(event, section, key) {
  const file = event.target.files[0];
  if (!file) return;
  event.target.value = "";

  uploading.value = `${section}:${key}`;
  const fd = new FormData();
  fd.append("file", file);
  fd.append("section", section);
  fd.append("content_key", key);

  try {
    const r = await fetch(API.value + "&action=website_content_upload", {
      method: "POST",
      body: fd,
    });
    const d = await r.json();
    if (d.success && d.url) {
      if (!cms.value[section]) cms.value[section] = {};
      cms.value[section][key] = d.url;
      toast("Upload erfolgreich!");
      clearCache();
    } else {
      toast("Upload-Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) { toast("Fehler: " + e.message); }
  finally { uploading.value = ""; }
}

// ── Clear cache ───────────────────────────────────────
async function clearCache() {
  try {
    await fetch(API.value + "&action=website_clear_cache", { method: "POST" });
  } catch {}
}

// ── Helper: get/set CMS value ─────────────────────────
function g(section, key, fallback = "") {
  return cms.value?.[section]?.[key] ?? fallback;
}
function s(section, key, val) {
  if (!cms.value[section]) cms.value[section] = {};
  cms.value[section][key] = val;
}

// ── Helper: nested JSON value ─────────────────────────
function gn(section, key, subkey, fallback = "") {
  const obj = cms.value?.[section]?.[key];
  if (obj && typeof obj === "object") return obj[subkey] ?? fallback;
  return fallback;
}
function sn(section, key, subkey, val) {
  if (!cms.value[section]) cms.value[section] = {};
  if (!cms.value[section][key] || typeof cms.value[section][key] !== "object") {
    cms.value[section][key] = {};
  }
  cms.value[section][key][subkey] = val;
}

// ── Upload image for nested items (team, testimonials) ──
async function uploadTeamImage(event, key) {
  const file = event.target.files[0];
  if (!file) return;
  event.target.value = "";
  uploading.value = `team:${key}:image`;
  const fd = new FormData();
  fd.append("file", file);
  fd.append("section", "team");
  fd.append("content_key", `${key}_image`);
  try {
    const r = await fetch(API.value + "&action=website_content_upload", { method: "POST", body: fd });
    const d = await r.json();
    if (d.success && d.url) {
      sn("team", key, "image", d.url);
      toast("Foto hochgeladen!");
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
  finally { uploading.value = ""; }
}

async function uploadTestimonialImage(event, key) {
  const file = event.target.files[0];
  if (!file) return;
  event.target.value = "";
  uploading.value = `testimonials:${key}:image`;
  const fd = new FormData();
  fd.append("file", file);
  fd.append("section", "testimonials");
  fd.append("content_key", `${key}_image`);
  try {
    const r = await fetch(API.value + "&action=website_content_upload", { method: "POST", body: fd });
    const d = await r.json();
    if (d.success && d.url) {
      sn("testimonials", key, "image", d.url);
      toast("Kundenfoto hochgeladen!");
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
  finally { uploading.value = ""; }
}

const iconOptions = ['TrendingUp','FileText','Globe','Shield','Users','Lock','BarChart3','Zap','Eye','Target','Star','Sparkles','Home'];

// ── Dynamic list helpers ─────────────────────────────
function getItems(section, prefix) {
  const data = cms.value[section] || {};
  return Object.keys(data).filter(k => k.startsWith(prefix + "_")).sort();
}

function addItem(section, prefix, template = {}) {
  if (!cms.value[section]) cms.value[section] = {};
  const existing = getItems(section, prefix);
  const nextNum = existing.length > 0
    ? Math.max(...existing.map(k => parseInt(k.split("_").pop()) || 0)) + 1
    : 1;
  cms.value[section][`${prefix}_${nextNum}`] = { ...template };
}

function removeItem(section, key) {
  if (!cms.value[section]) return;
  delete cms.value[section][key];
}

// ── Delete from DB ───────────────────────────────────
async function deleteEntry(section, key) {
  removeItem(section, key);
  try {
    await fetch(API.value + "&action=website_content_save", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ section, content_key: key, content_value: "", content_type: "text", _delete: true }),
    });
  } catch {}
}
</script>

<template>
  <div>


    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-24">
      <Loader2 class="w-5 h-5 animate-spin text-[var(--muted-foreground)]" />
      <span class="ml-3 text-sm text-[var(--muted-foreground)]">Lade CMS-Daten...</span>
    </div>

    <div v-else>

      <!-- ── Layout: Content + Right Sidebar Nav ── -->
      <div class="flex gap-6 max-w-[1200px] mx-auto">

        <!-- ── Content area ── -->
        <div class="flex-1 min-w-0 max-w-3xl">

        <!-- ═══ HERO ═══ -->
        <div v-if="activeSection === 'hero'" class="space-y-5">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Video class="w-4.5 h-4.5 text-[var(--accent)]" /> Hero-Bereich
            </h2>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Headline</label>
                <input type="text" :value="g('hero','headline')" @input="s('hero','headline',$event.target.value)"
                  placeholder="z.B. Ihr nächstes Zuhause wartet."
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)] outline-none transition-all" />
                <p class="text-[11px] text-[var(--muted-foreground)] mt-1 opacity-60">HTML erlaubt — z.B. &lt;br/&gt; für Zeilenumbruch</p>
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Akzent-Wort</label>
                <input type="text" :value="g('hero','headline_accent')" @input="s('hero','headline_accent',$event.target.value)"
                  placeholder="Wort das farbig hervorgehoben wird"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)] outline-none transition-all" />
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Subheadline</label>
                <textarea :value="g('hero','subheadline')" @input="s('hero','subheadline',$event.target.value)" rows="2"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)] outline-none transition-all" />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Video-URL</label>
                  <input type="text" :value="g('hero','video_url')" @input="s('hero','video_url',$event.target.value)"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)] outline-none transition-all" />
                  <label :class="['mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', uploading === 'hero:video_url' ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 cursor-pointer hover:bg-zinc-200 active:scale-[0.98]']">
                    <Loader2 v-if="uploading === 'hero:video_url'" class="w-3.5 h-3.5 animate-spin" />
                    <Upload v-else class="w-3.5 h-3.5" />
                    {{ uploading === 'hero:video_url' ? 'Wird hochgeladen…' : 'Video hochladen' }}
                    <input type="file" accept="video/*" @change="uploadFile($event, 'hero', 'video_url')" class="hidden" :disabled="uploading === 'hero:video_url'" />
                  </label>
                </div>
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Hintergrundbild</label>
                  <input type="text" :value="g('hero','background_image')" @input="s('hero','background_image',$event.target.value)"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)] outline-none transition-all" />
                  <label :class="['mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', uploading === 'hero:background_image' ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 cursor-pointer hover:bg-zinc-200 active:scale-[0.98]']">
                    <Loader2 v-if="uploading === 'hero:background_image'" class="w-3.5 h-3.5 animate-spin" />
                    <Upload v-else class="w-3.5 h-3.5" />
                    {{ uploading === 'hero:background_image' ? 'Wird hochgeladen…' : 'Bild hochladen' }}
                    <input type="file" accept="image/*" @change="uploadFile($event, 'hero', 'background_image')" class="hidden" :disabled="uploading === 'hero:background_image'" />
                  </label>
                </div>
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('hero')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> {{ saving ? "Speichere..." : "Speichern" }}
              </button>
            </div>
          </div>

          <!-- Video Preview -->
          <div v-if="g('hero','video_url')" class="p-4 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <div class="text-xs font-semibold text-[var(--muted-foreground)] mb-2.5">Vorschau</div>
            <div class="relative rounded-xl overflow-hidden" style="aspect-ratio:16/7">
              <video :src="g('hero','video_url')" autoplay muted loop playsinline class="w-full h-full object-cover" />
              <div class="absolute inset-0" style="background:linear-gradient(180deg,rgba(0,0,0,0.2) 0%,rgba(0,0,0,0.65) 100%)"></div>
              <div class="absolute bottom-4 left-5 text-white">
                <div class="text-xl font-bold tracking-tight" v-html="(g('hero','headline') || '').replace(g('hero','headline_accent',''), '<span style=color:#E8743A>' + g('hero','headline_accent','') + '</span>')"></div>
                <div class="text-sm opacity-50 mt-1">{{ g('hero','subheadline') }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══ STATS ═══ -->
        <div v-if="activeSection === 'stats'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-1.5 flex items-center gap-2">
              <BarChart3 class="w-4.5 h-4.5 text-[var(--accent)]" /> Statistiken
            </h2>
            <p class="text-xs text-[var(--muted-foreground)] mb-5">Die vier Kennzahlen auf der Startseite.</p>

            <div class="grid grid-cols-2 gap-3">
              <div v-for="i in 4" :key="i" class="p-4 rounded-xl bg-[var(--background)] border border-[var(--border)]">
                <div class="text-xs font-bold text-[var(--accent)] mb-3">Stat {{ i }}</div>
                <div class="space-y-2">
                  <input type="text" :value="gn('stats','stat_'+i,'value')" @input="sn('stats','stat_'+i,'value',$event.target.value)" placeholder="Wert (z.B. 250)"
                    class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                  <div class="grid grid-cols-2 gap-2">
                    <input type="text" :value="gn('stats','stat_'+i,'suffix')" @input="sn('stats','stat_'+i,'suffix',$event.target.value)" placeholder="Suffix"
                      class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                    <input type="text" :value="gn('stats','stat_'+i,'label')" @input="sn('stats','stat_'+i,'label',$event.target.value)" placeholder="Label"
                      class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                  </div>
                </div>
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('stats')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ SERVICES ═══ -->
        <div v-if="activeSection === 'services'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <div class="flex items-center justify-between mb-5">
              <div>
                <h2 class="text-base font-bold text-[var(--foreground)] flex items-center gap-2">
                  <Shield class="w-4.5 h-4.5 text-[var(--accent)]" /> Leistungen
                </h2>
                <p class="text-xs text-[var(--muted-foreground)] mt-1">Service-Karten auf der Startseite. Beliebig viele möglich.</p>
              </div>
              <button @click="addItem('services', 'service', { icon: 'Zap', title: '', desc: '' })"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-zinc-100 text-zinc-700 hover:bg-zinc-200 active:scale-[0.98] transition-all">
                <Plus class="w-3.5 h-3.5" /> Hinzufügen
              </button>
            </div>

            <div class="space-y-3">
              <div v-for="key in getItems('services', 'service')" :key="key" class="p-4 rounded-xl bg-[var(--background)] border border-[var(--border)] group">
                <div class="flex items-center justify-between mb-3">
                  <div class="flex items-center gap-2">
                    <span class="w-5 h-5 rounded-md bg-zinc-100 text-zinc-600 flex items-center justify-center text-[10px] font-bold">{{ key.split('_').pop() }}</span>
                    <span class="text-sm font-semibold text-[var(--foreground)]">{{ gn('services', key, 'title') || 'Neue Leistung' }}</span>
                  </div>
                  <button @click="deleteEntry('services', key)"
                    class="opacity-0 group-hover:opacity-100 p-1.5 rounded-lg text-zinc-400 hover:text-red-500 hover:bg-red-50 active:scale-[0.95] transition-all">
                    <Trash2 class="w-3.5 h-3.5" />
                  </button>
                </div>
                <div class="grid grid-cols-4 gap-2">
                  <select :value="gn('services', key, 'icon', 'Zap')" @change="sn('services', key, 'icon', $event.target.value)"
                    class="px-2.5 py-2 rounded-lg text-xs bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none">
                    <option v-for="ic in iconOptions" :key="ic" :value="ic">{{ ic }}</option>
                  </select>
                  <input type="text" :value="gn('services', key, 'title')" @input="sn('services', key, 'title', $event.target.value)" placeholder="Titel"
                    class="px-2.5 py-2 rounded-lg text-xs bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                  <input type="text" :value="gn('services', key, 'desc')" @input="sn('services', key, 'desc', $event.target.value)" placeholder="Beschreibung"
                    class="col-span-2 px-2.5 py-2 rounded-lg text-xs bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                </div>
              </div>
              <div v-if="getItems('services', 'service').length === 0" class="py-8 text-center text-sm text-[var(--muted-foreground)]">
                Noch keine Leistungen. Klicke auf "Hinzufügen".
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('services')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> {{ saving ? 'Speichere...' : 'Speichern' }}
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ ABOUT ═══ -->
        <div v-if="activeSection === 'about'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Image class="w-4.5 h-4.5 text-[var(--accent)]" /> Über uns — Parallax
            </h2>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Headline</label>
                <input type="text" :value="g('about','parallax_headline')" @input="s('about','parallax_headline',$event.target.value)"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Text</label>
                <textarea :value="g('about','parallax_text')" @input="s('about','parallax_text',$event.target.value)" rows="3"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Parallax-Bild</label>
                <input type="text" :value="g('about','parallax_image')" @input="s('about','parallax_image',$event.target.value)"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                <label :class="['mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', uploading === 'about:parallax_image' ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 cursor-pointer hover:bg-zinc-200 active:scale-[0.98]']">
                  <Loader2 v-if="uploading === 'about:parallax_image'" class="w-3.5 h-3.5 animate-spin" />
                  <Upload v-else class="w-3.5 h-3.5" />
                  {{ uploading === 'about:parallax_image' ? 'Wird hochgeladen…' : 'Bild hochladen' }}
                  <input type="file" accept="image/*" @change="uploadFile($event, 'about', 'parallax_image')" class="hidden" :disabled="uploading === 'about:parallax_image'" />
                </label>
                <div v-if="g('about','parallax_image')" class="mt-3 rounded-xl overflow-hidden border border-[var(--border)]" style="max-height:160px">
                  <img :src="g('about','parallax_image')" class="w-full h-full object-cover" />
                </div>
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('about')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>

          <!-- ── Team / Makler (same page: Über uns) ── -->
          <div class="mt-5 p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <div class="flex items-center justify-between mb-5">
              <div>
                <h2 class="text-base font-bold text-[var(--foreground)] flex items-center gap-2">
                  <Users class="w-4.5 h-4.5 text-[var(--accent)]" /> Team / Makler
                </h2>
                <p class="text-xs text-[var(--muted-foreground)] mt-1">Makler-Profile mit Foto, Rolle und Beschreibung — erscheinen auf der „Über uns"-Seite.</p>
              </div>
              <button @click="addItem('team', 'team', { name: '', role: '', bio: '', image: '' })"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-zinc-100 text-zinc-700 hover:bg-zinc-200 active:scale-[0.98] transition-all">
                <Plus class="w-3.5 h-3.5" /> Makler hinzufügen
              </button>
            </div>

            <div class="space-y-4">
              <div v-for="key in getItems('team', 'team')" :key="key" class="p-4 rounded-xl bg-[var(--background)] border border-[var(--border)] group">
                <div class="flex items-center justify-between mb-3">
                  <div class="flex items-center gap-3">
                    <img v-if="gn('team', key, 'image')" :src="gn('team', key, 'image')" class="w-10 h-10 rounded-full object-cover border border-[var(--border)]" />
                    <div v-else class="w-10 h-10 rounded-full bg-zinc-100 flex items-center justify-center">
                      <Users class="w-4 h-4 text-zinc-400" />
                    </div>
                    <span class="text-sm font-semibold text-[var(--foreground)]">{{ gn('team', key, 'name') || 'Neuer Makler' }}</span>
                  </div>
                  <button @click="deleteEntry('team', key)"
                    class="opacity-0 group-hover:opacity-100 p-1.5 rounded-lg text-zinc-400 hover:text-red-500 hover:bg-red-50 active:scale-[0.95] transition-all">
                    <Trash2 class="w-3.5 h-3.5" />
                  </button>
                </div>
                <div class="space-y-2.5">
                  <div class="grid grid-cols-2 gap-2.5">
                    <div>
                      <label class="text-[11px] font-semibold text-[var(--muted-foreground)] mb-1 block">Name</label>
                      <input type="text" :value="gn('team', key, 'name')" @input="sn('team', key, 'name', $event.target.value)" placeholder="Vor- und Nachname"
                        class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                    </div>
                    <div>
                      <label class="text-[11px] font-semibold text-[var(--muted-foreground)] mb-1 block">Rolle / Position</label>
                      <input type="text" :value="gn('team', key, 'role')" @input="sn('team', key, 'role', $event.target.value)" placeholder="z.B. Geschäftsführer"
                        class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                    </div>
                  </div>
                  <div>
                    <label class="text-[11px] font-semibold text-[var(--muted-foreground)] mb-1 block">Beschreibung</label>
                    <textarea :value="gn('team', key, 'bio')" @input="sn('team', key, 'bio', $event.target.value)" rows="2" placeholder="Kurze Beschreibung des Maklers..."
                      class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] outline-none" />
                  </div>
                  <div>
                    <label class="text-[11px] font-semibold text-[var(--muted-foreground)] mb-1 block">Foto</label>
                    <div class="flex items-center gap-2">
                      <input type="text" :value="gn('team', key, 'image')" @input="sn('team', key, 'image', $event.target.value)" placeholder="Bild-URL"
                        class="flex-1 px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                      <label :class="['inline-flex items-center gap-1 px-2.5 py-2 rounded-lg text-xs font-medium transition-all cursor-pointer', uploading === ('team:' + key + ':image') ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 active:scale-[0.98]']">
                        <Loader2 v-if="uploading === ('team:' + key + ':image')" class="w-3 h-3 animate-spin" />
                        <Upload v-else class="w-3 h-3" /> Foto
                        <input type="file" accept="image/*" @change="uploadTeamImage($event, key)" class="hidden" />
                      </label>
                    </div>
                  </div>
                </div>
              </div>
              <div v-if="getItems('team', 'team').length === 0" class="py-8 text-center text-sm text-[var(--muted-foreground)]">
                Noch keine Makler. Klicke auf "Makler hinzufügen".
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('team')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> {{ saving ? 'Speichere...' : 'Speichern' }}
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ PORTAL ═══ -->
        <div v-if="activeSection === 'portal'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Lock class="w-4.5 h-4.5 text-[var(--accent)]" /> Kundenportal-Bereich
            </h2>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Headline</label>
                <input type="text" :value="g('portal','headline')" @input="s('portal','headline',$event.target.value)"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Subheadline</label>
                <textarea :value="g('portal','subheadline')" @input="s('portal','subheadline',$event.target.value)" rows="3"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] outline-none transition-all" />
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('portal')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ CONTACT ═══ -->
        <div v-if="activeSection === 'contact'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Phone class="w-4.5 h-4.5 text-[var(--accent)]" /> Kontaktdaten
            </h2>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Adresse</label>
                <textarea :value="g('contact','address')" @input="s('contact','address',$event.target.value)" rows="2"
                  placeholder="Zeilenumbruch = neue Zeile"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Telefon</label>
                  <input type="text" :value="g('contact','phone')" @input="s('contact','phone',$event.target.value)"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                </div>
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Email</label>
                  <input type="text" :value="g('contact','email')" @input="s('contact','email',$event.target.value)"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                </div>
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Bürozeiten</label>
                <input type="text" :value="g('contact','hours')" @input="s('contact','hours',$event.target.value)"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('contact')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ TESTIMONIALS ═══ -->
        <div v-if="activeSection === 'testimonial'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <div class="flex items-center justify-between mb-5">
              <div>
                <h2 class="text-base font-bold text-[var(--foreground)] flex items-center gap-2">
                  <Star class="w-4.5 h-4.5 text-[var(--accent)]" /> Referenzen / Testimonials
                </h2>
                <p class="text-xs text-[var(--muted-foreground)] mt-1">Kundenstimmen mit optionalem Foto.</p>
              </div>
              <button @click="addItem('testimonials', 'testimonial', { quote: '', author: '', image: '' })"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-zinc-100 text-zinc-700 hover:bg-zinc-200 active:scale-[0.98] transition-all">
                <Plus class="w-3.5 h-3.5" /> Hinzufügen
              </button>
            </div>

            <div class="space-y-4">
              <div v-for="key in getItems('testimonials', 'testimonial')" :key="key" class="p-4 rounded-xl bg-[var(--background)] border border-[var(--border)] group">
                <div class="flex items-center justify-between mb-3">
                  <span class="text-sm font-semibold text-[var(--foreground)]">{{ gn('testimonials', key, 'author') || 'Neues Testimonial' }}</span>
                  <button @click="deleteEntry('testimonials', key)"
                    class="opacity-0 group-hover:opacity-100 p-1.5 rounded-lg text-zinc-400 hover:text-red-500 hover:bg-red-50 active:scale-[0.95] transition-all">
                    <Trash2 class="w-3.5 h-3.5" />
                  </button>
                </div>
                <div class="space-y-2.5">
                  <textarea :value="gn('testimonials', key, 'quote')" @input="sn('testimonials', key, 'quote', $event.target.value)" rows="3" placeholder="Zitat des Kunden..."
                    class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] outline-none transition-all" />
                  <div class="grid grid-cols-2 gap-2.5">
                    <input type="text" :value="gn('testimonials', key, 'author')" @input="sn('testimonials', key, 'author', $event.target.value)" placeholder="Name, Ort"
                      class="px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                    <div class="flex items-center gap-2">
                      <input type="text" :value="gn('testimonials', key, 'image')" @input="sn('testimonials', key, 'image', $event.target.value)" placeholder="Bild-URL (optional)"
                        class="flex-1 px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                      <label :class="['inline-flex items-center gap-1 px-2.5 py-2 rounded-lg text-xs font-medium transition-all cursor-pointer', uploading === ('testimonials:' + key + ':image') ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 active:scale-[0.98]']">
                        <Loader2 v-if="uploading === ('testimonials:' + key + ':image')" class="w-3 h-3 animate-spin" />
                        <Upload v-else class="w-3 h-3" />
                        <input type="file" accept="image/*" @change="uploadTestimonialImage($event, key)" class="hidden" />
                      </label>
                    </div>
                  </div>
                  <div v-if="gn('testimonials', key, 'image')" class="flex items-center gap-3 mt-1">
                    <img :src="gn('testimonials', key, 'image')" class="w-10 h-10 rounded-full object-cover border border-[var(--border)]" />
                    <span class="text-xs text-[var(--muted-foreground)]">Kundenfoto geladen</span>
                  </div>
                </div>
              </div>
              <div v-if="getItems('testimonials', 'testimonial').length === 0" class="py-8 text-center text-sm text-[var(--muted-foreground)]">
                Noch keine Testimonials. Klicke auf "Hinzufügen".
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('testimonials')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> {{ saving ? 'Speichere...' : 'Speichern' }}
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ SEO ═══ -->
        <div v-if="activeSection === 'seo'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Globe class="w-4.5 h-4.5 text-[var(--accent)]" /> SEO
            </h2>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Meta Title</label>
                <input type="text" :value="g('seo','meta_title')" @input="s('seo','meta_title',$event.target.value)"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                <div class="flex items-center gap-2 mt-1.5">
                  <div class="h-1 flex-1 rounded-full bg-[var(--border)] overflow-hidden">
                    <div class="h-full rounded-full transition-all" :style="{ width: Math.min(100, ((g('seo','meta_title') || '').length / 60) * 100) + '%', background: (g('seo','meta_title') || '').length > 60 ? '#ef4444' : 'var(--accent)' }" />
                  </div>
                  <span class="text-[11px] text-[var(--muted-foreground)]">{{ (g('seo','meta_title') || '').length }}/60</span>
                </div>
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Meta Description</label>
                <textarea :value="g('seo','meta_description')" @input="s('seo','meta_description',$event.target.value)" rows="3"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-none focus:border-[var(--accent)] outline-none transition-all" />
                <div class="flex items-center gap-2 mt-1.5">
                  <div class="h-1 flex-1 rounded-full bg-[var(--border)] overflow-hidden">
                    <div class="h-full rounded-full transition-all" :style="{ width: Math.min(100, ((g('seo','meta_description') || '').length / 160) * 100) + '%', background: (g('seo','meta_description') || '').length > 160 ? '#ef4444' : 'var(--accent)' }" />
                  </div>
                  <span class="text-[11px] text-[var(--muted-foreground)]">{{ (g('seo','meta_description') || '').length }}/160</span>
                </div>
              </div>
            </div>

            <!-- SERP Preview -->
            <div class="mt-5 p-4 rounded-xl bg-[var(--background)] border border-[var(--border)]">
              <div class="text-[11px] font-semibold text-[var(--muted-foreground)] mb-2.5">Google-Vorschau</div>
              <div class="text-[#1a0dab] text-sm font-medium truncate">{{ g('seo','meta_title') || 'SR-Homes — Immobilien Salzburg' }}</div>
              <div class="text-[#006621] text-xs mt-0.5">https://sr-homes.at</div>
              <div class="text-[#545454] text-xs mt-0.5 line-clamp-2">{{ g('seo','meta_description') || 'Ihr Immobilienmakler in Salzburg' }}</div>
            </div>

            <div class="mt-6 flex justify-end">
              <button @click="saveSection('seo')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ BRANDING ═══ -->
        <div v-if="activeSection === 'branding'">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Sparkles class="w-4.5 h-4.5 text-[var(--accent)]" /> Branding
            </h2>
            <div class="grid grid-cols-2 gap-5">
              <div class="p-4 rounded-xl bg-[var(--background)] border border-[var(--border)]">
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Logo (Farbe)</label>
                <input type="text" :value="g('branding','logo_color')" @input="s('branding','logo_color',$event.target.value)"
                  class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                <div class="mt-2.5 p-3 rounded-lg bg-white flex items-center justify-center" style="min-height:56px">
                  <img v-if="g('branding','logo_color')" :src="g('branding','logo_color')" class="h-7 max-w-full" />
                  <span v-else class="text-xs text-zinc-300">Kein Logo</span>
                </div>
                <label :class="['mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', uploading === 'branding:logo_color' ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 cursor-pointer hover:bg-zinc-200 active:scale-[0.98]']">
                  <Loader2 v-if="uploading === 'branding:logo_color'" class="w-3.5 h-3.5 animate-spin" />
                  <Upload v-else class="w-3.5 h-3.5" />
                  {{ uploading === 'branding:logo_color' ? 'Wird hochgeladen…' : 'Hochladen' }}
                  <input type="file" accept="image/*,.svg" @change="uploadFile($event, 'branding', 'logo_color')" class="hidden" :disabled="uploading === 'branding:logo_color'" />
                </label>
              </div>
              <div class="p-4 rounded-xl bg-[var(--background)] border border-[var(--border)]">
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Logo (Weiß)</label>
                <input type="text" :value="g('branding','logo_white')" @input="s('branding','logo_white',$event.target.value)"
                  class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--card)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none" />
                <div class="mt-2.5 p-3 rounded-lg bg-zinc-900 flex items-center justify-center" style="min-height:56px">
                  <img v-if="g('branding','logo_white')" :src="g('branding','logo_white')" class="h-7 max-w-full" />
                  <span v-else class="text-xs text-zinc-600">Kein Logo</span>
                </div>
                <label :class="['mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', uploading === 'branding:logo_white' ? 'bg-zinc-200 text-zinc-500 pointer-events-none' : 'bg-zinc-100 text-zinc-700 cursor-pointer hover:bg-zinc-200 active:scale-[0.98]']">
                  <Loader2 v-if="uploading === 'branding:logo_white'" class="w-3.5 h-3.5 animate-spin" />
                  <Upload v-else class="w-3.5 h-3.5" />
                  {{ uploading === 'branding:logo_white' ? 'Wird hochgeladen…' : 'Hochladen' }}
                  <input type="file" accept="image/*,.svg" @change="uploadFile($event, 'branding', 'logo_white')" class="hidden" :disabled="uploading === 'branding:logo_white'" />
                </label>
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('branding')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>
        </div>

        <!-- ═══ LEGAL (Impressum & Datenschutz) ═══ -->
        <div v-if="activeSection === 'legal'" class="space-y-5">
          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Shield class="w-4.5 h-4.5 text-[var(--accent)]" /> Impressum
            </h2>
            <p class="text-xs text-[var(--muted-foreground)] mb-3">Wird auf der Website unter /impressum angezeigt. HTML erlaubt.</p>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Firmenname</label>
                <input type="text" :value="g('legal','company_name')" @input="s('legal','company_name',$event.target.value)"
                  placeholder="SR-Homes Immobilien GmbH"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">FN (Firmenbuchnummer)</label>
                  <input type="text" :value="g('legal','fn_number')" @input="s('legal','fn_number',$event.target.value)"
                    placeholder="FN 4556571 i"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                </div>
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">UID-Nr.</label>
                  <input type="text" :value="g('legal','uid_number')" @input="s('legal','uid_number',$event.target.value)"
                    placeholder="ATU 71268923"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Geschäftsführer</label>
                  <input type="text" :value="g('legal','ceo_name')" @input="s('legal','ceo_name',$event.target.value)"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                </div>
                <div>
                  <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Firmenbuchgericht</label>
                  <input type="text" :value="g('legal','court')" @input="s('legal','court',$event.target.value)"
                    placeholder="Landesgericht Salzburg"
                    class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
                </div>
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Gewerbe / Berechtigung</label>
                <input type="text" :value="g('legal','trade_license')" @input="s('legal','trade_license',$event.target.value)"
                  placeholder="Konzessionierter Immobilientreuhänder"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Aufsichtsbehörde</label>
                <input type="text" :value="g('legal','authority')" @input="s('legal','authority',$event.target.value)"
                  placeholder="Magistrat der Stadt Salzburg"
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" />
              </div>
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Zusätzlicher Impressum-Text (HTML)</label>
                <textarea :value="g('legal','impressum_extra')" @input="s('legal','impressum_extra',$event.target.value)" rows="6"
                  placeholder="Weitere rechtliche Hinweise, Haftungsausschluss, etc."
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-y focus:border-[var(--accent)] outline-none transition-all font-mono" />
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('legal')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>

          <div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
            <h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">
              <Lock class="w-4.5 h-4.5 text-[var(--accent)]" /> Datenschutzerklärung
            </h2>
            <p class="text-xs text-[var(--muted-foreground)] mb-3">Wird auf der Website unter /datenschutz angezeigt. HTML erlaubt.</p>
            <div class="space-y-4">
              <div>
                <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">Datenschutzerklärung (HTML)</label>
                <textarea :value="g('legal','datenschutz_html')" @input="s('legal','datenschutz_html',$event.target.value)" rows="20"
                  placeholder="Vollständige Datenschutzerklärung hier einfügen..."
                  class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-y focus:border-[var(--accent)] outline-none transition-all font-mono" />
              </div>
            </div>
            <div class="mt-6 flex justify-end">
              <button @click="saveSection('legal')" :disabled="saving"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] disabled:opacity-40 transition-all">
                <Save class="w-4 h-4" /> Speichern
              </button>
            </div>
          </div>
        </div>

        </div>

        <!-- ── Right Sidebar Navigation ── -->
        <div class="w-44 shrink-0 sticky top-4 self-start hidden lg:block">
          <nav class="flex flex-col gap-0.5">
            <button
              v-for="sec in sections" :key="sec.key"
              @click="activeSection = sec.key"
              class="flex items-center gap-2 px-3 py-2 rounded-xl text-[13px] font-medium whitespace-nowrap transition-all text-left"
              :class="activeSection === sec.key
                ? 'bg-[var(--foreground)] text-[var(--background)]'
                : 'text-[var(--muted-foreground)] hover:bg-zinc-100 hover:text-[var(--foreground)]'"
            >
              <component :is="sec.icon" class="w-3.5 h-3.5 shrink-0" />
              {{ sec.label }}
            </button>
          </nav>
          <a href="https://sr-homes.at" target="_blank"
            class="flex items-center gap-2 px-3 py-2 mt-3 rounded-xl text-[13px] font-medium text-[var(--accent)] hover:bg-zinc-100 transition-all border border-[var(--border)]">
            <ExternalLink class="w-3.5 h-3.5 shrink-0" /> Website öffnen
          </a>
        </div>

      </div>
    </div>
  </div>
</template>
