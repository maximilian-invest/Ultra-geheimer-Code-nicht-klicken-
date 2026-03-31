<script setup>
import { ref, computed, inject, watch } from "vue";
import {
  X, Pencil, Users, Key, Building2, ClipboardList, BookOpen,
  FileText, MessageCircle, Sparkles, ChevronRight,
  MapPin, ArrowLeft, Pause, Play, Trash2,
  ShoppingCart, ParkingSquare, FolderOpen, Plus, Check, UserPlus, Unlink, Link2
, Upload, Clock} from "lucide-vue-next";
const props = defineProps({
  property: { type: Object, required: true },
  visible: { type: Boolean, default: false }
});

const emit = defineEmits([
  "close", "openEditor", "openActivities", "openKnowledge",
  "openFiles", "openMessages", "openSettings", "toggleOnHold",
  "deleteProperty", "ownerChanged", "assignParent", "propertyCreated"
]);

const API = inject("API");

// Child property creation
const childCreateLoading = ref(false);
const childCreateModal = ref(false);
const childCategories = ref([]);
const childCategoriesLoading = ref(false);
const childSelected = ref(new Set());
const childManualTitle = ref('');
const childMode = ref('categories'); // 'categories' or 'manual'

async function openChildCreateModal() {
  childCreateModal.value = true;
  childMode.value = 'categories';
  childManualTitle.value = '';
  childSelected.value = new Set();
  childCategories.value = [];
  const p = props.property;
  if (!p) return;
  childCategoriesLoading.value = true;
  try {
    const res = await fetch(API.value + '&action=get_unit_categories&property_id=' + p.id);
    const d = await res.json();
    if (d.success && d.categories?.length) {
      childCategories.value = d.categories.map(c => ({
        ...c,
        rooms: parseFloat(c.rooms),
        selected: false,
        title: Math.floor(parseFloat(c.rooms)) + '-Zimmer Wohnungen',
      }));
    }
  } catch(e) { console.error(e); }
  childCategoriesLoading.value = false;
}

function toggleCategory(rooms) {
  const s = new Set(childSelected.value);
  if (s.has(rooms)) s.delete(rooms); else s.add(rooms);
  childSelected.value = s;
}

function formatPrice(val) {
  return new Intl.NumberFormat('de-AT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(val);
}

async function createChildrenFromCategories() {
  const p = props.property;
  if (!p) return;
  childCreateLoading.value = true;
  try {
    const cats = childCategories.value
      .filter(c => childSelected.value.has(c.rooms))
      .map(c => ({ rooms: c.rooms, title: c.title, min_price: c.min_price, min_area: c.min_area, max_area: c.max_area }));
    const res = await fetch(API.value + '&action=create_children_from_categories', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ parent_id: p.id, categories: cats })
    });
    const d = await res.json();
    if (d.success) {
      toast(d.message);
      childCreateModal.value = false;
      emit('propertyCreated');
      window.location.reload();
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'));
    }
  } catch(e) { toast('Fehler: ' + e.message); }
  childCreateLoading.value = false;
}

async function createChildManual() {
  const p = props.property;
  if (!p || !childManualTitle.value.trim()) return;
  childCreateLoading.value = true;
  try {
    const res = await fetch(API.value + '&action=create_child_property', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ parent_id: p.id, title: childManualTitle.value.trim() })
    });
    const d = await res.json();
    if (d.success) {
      toast('Unterobjekt erstellt');
      childCreateModal.value = false;
      emit('propertyCreated');
      window.location.reload();
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'));
    }
  } catch(e) { toast('Fehler: ' + e.message); }
  childCreateLoading.value = false;
}
const toast = inject("toast");
const kbCounts = inject("kbCounts");

// ─── Portal + Eigentuemer State ─────────────────────────
const portalPopupOpen = ref(false);
const historyOpen = ref(false);
const historyItems = ref([]);
const historyAdding = ref(false);
const historyNew = ref({ year: "", title: "", description: "" });
const historyEditIdx = ref(-1);
const historySaving = ref(false);

function openHistory() {
  const p = props.property;
  if (!p) return;
  let d = p.property_history;
  if (typeof d === "string") { try { d = JSON.parse(d); } catch(e) { d = []; } }
  historyItems.value = Array.isArray(d) ? JSON.parse(JSON.stringify(d)) : [];
  historyAdding.value = false;
  historyEditIdx.value = -1;
  historyNew.value = { year: "", title: "", description: "" };
  historyOpen.value = true;
}

function historyAddEntry() {
  if (!historyNew.value.year || !historyNew.value.title) return;
  historyItems.value.push({ ...historyNew.value });
  historyItems.value.sort((a, b) => String(a.year).localeCompare(String(b.year)));
  historyNew.value = { year: "", title: "", description: "" };
  historyAdding.value = false;
  saveHistory();
}

function historyDeleteEntry(idx) {
  historyItems.value.splice(idx, 1);
  saveHistory();
}

async function saveHistory() {
  historySaving.value = true;
  try {
    const r = await fetch(API.value + "&action=update_property", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id, property_history: JSON.stringify(historyItems.value) }),
    });
    const d = await r.json();
    if (d.success) {
      props.property.property_history = JSON.stringify(historyItems.value);
      toast("Historie gespeichert");
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch(e) { toast("Fehler: " + e.message); }
  historySaving.value = false;
}
const portalUser = ref(null);
const portalLoading = ref(false);
const showPortalForm = ref(false);
const portalForm = ref({ password: "" });
const portalCreating = ref(false);
const portalError = ref("");
const portalSuccess = ref("");

const ownerData = ref({ customer_id: null, owner_name: "", owner_email: "", owner_phone: "" });
const customersList = ref([]);
const customersLoaded = ref(false);
const selectedCustomerId = ref("");
const showCreateOwnerForm = ref(false);
const newOwnerForm = ref({ name: "", email: "", phone: "" });
const newOwnerSaving = ref(false);

// ─── Expose KI ──────────────────────────────────────────
const exposePopupOpen = ref(false);
const exposeLoading = ref(false);
const exposeMode = ref(null); // 'kb' or 'fields'
const exposeResult = ref(null);
const exposeFileSelect = ref(false);
const exposeFiles = ref([]);
const exposeSelectedFiles = ref([]);

const exposeUploading = ref(false);

async function loadExposeFiles() {
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    exposeFiles.value = d.files || [];
    // Pre-select files with "expos" in name
    exposeSelectedFiles.value = exposeFiles.value
      .filter(f => /expos/i.test(f.filename) || /expos/i.test(f.label || ''))
      .map(f => f.id);
  } catch (e) { exposeFiles.value = []; }
}

async function uploadExposeFiles(event) {
  const files = event.target.files;
  if (!files || !files.length) return;
  exposeUploading.value = true;
  const propId = props.property.id;
  for (const file of files) {
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('property_id', propId);
      fd.append('label', file.name.replace(/\.[^.]+$/, ''));
      const r = await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success && d.file) {
        exposeFiles.value.push(d.file);
        exposeSelectedFiles.value.push(d.file.id);
      }
    } catch(e) { console.error('Upload failed:', e); }
  }
  exposeUploading.value = false;
  event.target.value = '';
  toast(files.length + ' Datei(en) hochgeladen');
}

async function toggleWebsiteDownload(f) {
  try {
    const r = await fetch(API.value + '&action=toggle_website_download', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: f.id }),
    });
    const d = await r.json();
    if (d.success) {
      f.is_website_download = d.is_website_download;
      toast(d.is_website_download ? 'Download auf Website aktiviert' : 'Download von Website entfernt');
    }
  } catch(e) { console.error('Toggle failed:', e); }
}

async function runExpose(mode) {
  exposeLoading.value = true;
  exposeMode.value = mode;
  exposeResult.value = null;
  try {
    const body = { property_id: props.property.id };
    if (exposeSelectedFiles.value.length > 0) {
      body.file_ids = exposeSelectedFiles.value;
    }
    const r = await fetch(API.value + "&action=parse_expose&property_id=" + props.property.id, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const txt = await r.text();
    if (txt.startsWith("<!") || txt.startsWith("<html")) {
      toast("Session abgelaufen, bitte Seite neu laden");
      exposeLoading.value = false;
      return;
    }
    const d = JSON.parse(txt);
    console.log("[Expose] API response:", d);
    if (d.error) { toast(d.error); }
    else {
      exposeResult.value = d.extracted || d;
      console.log("[Expose] exposeResult set:", JSON.stringify(exposeResult.value).substring(0, 200));
      console.log("[Expose] has fields:", !!exposeResult.value?.fields, "field count:", exposeResult.value?.fields ? Object.keys(exposeResult.value.fields).length : 0);
      // Backend auto-saves fields now, just show result
      const savedMsg = d.fields_saved ? d.fields_saved + " Felder gespeichert" : "";
      const unitsMsg = (d.units_created || d.units_updated) ? (d.units_created + " Einheiten importiert, " + d.units_updated + " aktualisiert") : "";
      const msg = [savedMsg, unitsMsg].filter(Boolean).join(", ");
      toast(msg || "Expose analysiert!");
      // Still run apply for KB mode
      if (mode === 'kb') {
        await applyExposeToKB();
      } else if (mode === 'fields') {
        // Fields already saved by backend, just reload
        emit("close");
        setTimeout(() => emit("openEditor", props.property.id), 300);
      }
    }
  } catch (e) { toast("Fehler: " + e.message); }
  exposeLoading.value = false;
}

async function applyExposeToKB() {
  if (!exposeResult.value) return;
  // Save extracted data as knowledge base entries
  const result = exposeResult.value;
  const entries = [];
  if (result.fields) {
    for (const [k, v] of Object.entries(result.fields)) {
      if (v !== null && v !== undefined && v !== "") {
        entries.push({ title: k, content: String(v), category: "dokument_extrakt" });
      }
    }
  }
  if (entries.length > 0) {
    try {
      for (const entry of entries) {
        await fetch(API.value + "&action=feed_knowledge", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ property_id: props.property.id, ...entry }),
        });
      }
      toast(entries.length + " Eintraege in Wissens-DB gespeichert");
    } catch (e) { toast("Fehler: " + e.message); }
  }
  exposeResult.value = null;
}

async function applyExposeToFields() {
  console.log("[Expose] applyExposeToFields called, exposeResult:", !!exposeResult.value);
  if (!exposeResult.value) { console.log("[Expose] ABORT: no exposeResult"); return; }
  const result = exposeResult.value;
  console.log("[Expose] result.fields:", !!result.fields, result.fields ? Object.keys(result.fields).length + " fields" : "none");
  // Use save_property_settings (accepts ALL fields via FIELD_LABELS)
  if (result.fields) {
    try {
      const payload = { property_id: props.property.id, ...result.fields };
      console.log("[Expose] Saving to save_property_settings, payload keys:", Object.keys(payload).join(","));
      const r = await fetch(API.value + "&action=save_property_settings", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const d = await r.json();
      console.log("[Expose] save response:", d);
      if (d.success) {
        toast("Objektdaten aktualisiert (" + Object.keys(result.fields).length + " Felder gespeichert)");
        // Reload property to show updated data
        emit("close");
        setTimeout(() => emit("openEditor", props.property.id), 300);
      } else { toast("Fehler beim Speichern: " + (d.error || "Unbekannt")); }
    } catch (e) { console.error("[Expose] save error:", e); toast("Fehler: " + e.message); }
  } else { console.log("[Expose] SKIP: no fields in result"); }
  // Import units if found
  if (result.units && result.units.length) {
    try {
      const r = await fetch(API.value + "&action=bulk_import_units", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ property_id: props.property.id, units: result.units }),
      });
      const d = await r.json();
      if (d.success) toast(d.created + " Einheiten importiert, " + d.updated + " aktualisiert");
    } catch (e) {}
  }
  exposeResult.value = null;
}

// ─── Einheiten Quick Stats ──────────────────────────────
const unitStats = ref(null);

// Project Groups
const projectGroups = ref([]);
const projectGroupPopup = ref(false);
const newGroupName = ref('');
const newGroupDesc = ref('');
const showNewGroupForm = ref(false);

// ─── Watch visibility ───────────────────────────────────
watch(() => props.visible, async (v) => {
  // Lock/unlock body scroll
  // Lock ALL scroll on parent containers when popup is visible
  if (v) {
    document.body.style.overflow = 'hidden';
    // Find and lock all scrollable parents
    document.querySelectorAll('[class*="overflow-y-auto"], [class*="overflow-y: auto"]').forEach(el => {
      el.dataset.prevOverflow = el.style.overflow || '';
      el.style.overflow = 'hidden';
    });
  } else {
    document.body.style.overflow = '';
    // Restore all parents
    document.querySelectorAll('[data-prev-overflow]').forEach(el => {
      el.style.overflow = el.dataset.prevOverflow || '';
      delete el.dataset.prevOverflow;
    });
  }
  if (v && props.property) {
    // Reset state
    portalPopupOpen.value = false;
    showPortalForm.value = false;
    portalError.value = "";
    portalSuccess.value = "";
    ownerData.value = {
      customer_id: props.property.customer_id || null,
      owner_name: props.property.owner_name || "",
      owner_email: props.property.owner_email || "",
      owner_phone: props.property.owner_phone || "",
    };
    loadPortalAccess();
    loadCustomersList();
    if (props.property.property_category === "newbuild") loadUnitStats();
    loadProjectGroups();
  }
});

async function loadCustomersList() {
  if (customersLoaded.value) return;
  try {
    const r = await fetch(API.value + "&action=list_customers");
    const d = await r.json();
    customersList.value = d.customers || [];
    customersLoaded.value = true;
  } catch (e) {}
}

async function loadPortalAccess() {
  if (!ownerData.value.owner_email) { portalUser.value = null; return; }
  portalLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=check_portal_access&property_id=" + props.property.id + "&email=" + encodeURIComponent(ownerData.value.owner_email));
    const d = await r.json();
    portalUser.value = d.portal_user || null;
  } catch (e) {}
  portalLoading.value = false;
}

async function selectExistingOwner() {
  const id = Number(selectedCustomerId.value);
  if (!id) return;
  const c = customersList.value.find(x => x.id === id);
  if (!c) return;
  try {
    const r = await fetch(API.value + "&action=save_property_settings", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        customer_id: c.id,
        owner_name: c.name,
        owner_email: c.email || "",
        owner_phone: c.phone || "",
      })
    });
    const d = await r.json();
    if (d.success) {
      ownerData.value = { customer_id: c.id, owner_name: c.name, owner_email: c.email || "", owner_phone: c.phone || "" };
      emit("ownerChanged", { propertyId: props.property.id, ...ownerData.value });
      toast("Eigentuemer zugewiesen: " + c.name);
      loadPortalAccess();
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
  selectedCustomerId.value = "";
}

async function createNewOwner() {
  const f = newOwnerForm.value;
  if (!f.name) { toast("Bitte Name eingeben"); return; }
  newOwnerSaving.value = true;
  try {
    const r = await fetch(API.value + "&action=create_customer", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: f.name, email: f.email, phone: f.phone }),
    });
    const d = await r.json();
    if (d.success && d.customer) {
      customersLoaded.value = false;
      await loadCustomersList();
      // Auto-assign
      await fetch(API.value + "&action=save_property_settings", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          property_id: props.property.id,
          customer_id: d.customer.id,
          owner_name: d.customer.name,
          owner_email: d.customer.email || "",
          owner_phone: d.customer.phone || "",
        })
      });
      ownerData.value = {
        customer_id: d.customer.id,
        owner_name: d.customer.name,
        owner_email: d.customer.email || "",
        owner_phone: d.customer.phone || "",
      };
      emit("ownerChanged", { propertyId: props.property.id, ...ownerData.value });
      toast("Eigentuemer angelegt & zugewiesen");
      showCreateOwnerForm.value = false;
      newOwnerForm.value = { name: "", email: "", phone: "" };
      loadPortalAccess();
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
  newOwnerSaving.value = false;
}

async function unlinkCustomer() {
  if (!confirm("Eigentuemer-Verknuepfung wirklich loesen?")) return;
  try {
    const r = await fetch(API.value + "&action=save_property_settings", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id, customer_id: 0, owner_name: "", owner_email: "", owner_phone: "" })
    });
    const d = await r.json();
    if (d.success) {
      ownerData.value = { customer_id: null, owner_name: "", owner_email: "", owner_phone: "" };
      portalUser.value = null;
      emit("ownerChanged", { propertyId: props.property.id, ...ownerData.value });
      toast("Eigentuemer-Verknuepfung geloest");
    }
  } catch (e) { toast("Fehler: " + e.message); }
}

async function createPortalAccess() {
  if (!ownerData.value.owner_name || !ownerData.value.owner_email || !portalForm.value.password) {
    portalError.value = "Eigentuemer-Daten und Passwort erforderlich";
    return;
  }
  portalCreating.value = true;
  portalError.value = "";
  try {
    const r = await fetch(API.value + "&action=create_portal_access", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        name: ownerData.value.owner_name,
        email: ownerData.value.owner_email,
        password: portalForm.value.password,
      }),
    });
    const d = await r.json();
    if (d.success) {
      portalUser.value = d.user;
      showPortalForm.value = false;
      portalSuccess.value = "Zugang erstellt!";
      toast("Portalzugang erstellt");
    } else { portalError.value = d.error || "Fehler beim Erstellen"; }
  } catch (e) { portalError.value = e.message; }
  portalCreating.value = false;
}

async function loadUnitStats() {
  try {
    const r = await fetch(API.value + "&action=get_property_settings&property_id=" + props.property.id);
    const d = await r.json();
    if (d.units) {
      const units = d.units;
      const realUnits = units.filter(u => !u.is_parking);
      const totalArea = realUnits.reduce((s, u) => s + (parseFloat(u.area_m2) || 0), 0);
      const verkauftArea = realUnits.filter(u => u.status === "verkauft").reduce((s, u) => s + (parseFloat(u.area_m2) || 0), 0);
      const freiArea = realUnits.filter(u => u.status === "frei").reduce((s, u) => s + (parseFloat(u.area_m2) || 0), 0);
      const reserviertArea = realUnits.filter(u => u.status === "reserviert").reduce((s, u) => s + (parseFloat(u.area_m2) || 0), 0);
      unitStats.value = {
        total: realUnits.length,
        frei: realUnits.filter(u => u.status === "frei").length,
        reserviert: realUnits.filter(u => u.status === "reserviert").length,
        verkauft: realUnits.filter(u => u.status === "verkauft").length,
        totalArea,
        verkauftArea,
        freiArea,
        reserviertArea,
        useArea: totalArea > 0,
      };
    }
  } catch (e) {}
}

// ─── Tiles ──────────────────────────────────────────────
const tiles = computed(() => {
  const p = props.property;
  if (!p) return [];

  const items = [
    {
      id: "objektdaten", label: "Objektdaten", sub: "Alle Felder bearbeiten",
      icon: "Pencil", color: "#18181b", accent: "rgba(24,24,27,0.06)",
      action: () => emit("openEditor", p.id),
      secondaryLabel: exposeLoading.value && exposeMode.value === 'fields' ? "KI analysiert..." : "Dateien auslesen",
      secondaryLoading: exposeLoading.value && exposeMode.value === 'fields',
      secondaryAction: async () => { if (!exposeLoading.value) { await loadExposeFiles(); exposeFileSelect.value = true; exposeMode.value = 'fields'; } },
    },
    {
      id: "portalzugang", label: "Eigentuemer & Portal",
      sub: ownerData.value.owner_name
        ? (portalUser.value ? ownerData.value.owner_name + " — Zugang aktiv" : ownerData.value.owner_name + " — kein Zugang")
        : "Eigentuemer zuweisen",
      icon: "Key", color: "#D4622B", accent: "rgba(212,98,43,0.06)",
      badge: portalUser.value ? "Aktiv" : (ownerData.value.customer_id ? null : "!"),
      badgeColor: portalUser.value ? "#10b981" : "#f59e0b",
      action: () => { portalPopupOpen.value = true; },
    },
    {
      id: "aktivitaeten", label: "Aktivitaeten", sub: "Protokoll & Eintraege",
      icon: "ClipboardList", color: "#0891b2", accent: "rgba(8,145,178,0.06)",
      action: () => emit("openActivities", p.id, p.address),
    },
    {
      id: "wissensdatenbank", label: "Wissens-DB",
      sub: (kbCounts.value?.[p.id] || 0) + " Eintraege",
      icon: "BookOpen", color: "#059669", accent: "rgba(5,150,105,0.06)",
      action: () => emit("openKnowledge", p.id, p.address),
      secondaryLabel: exposeLoading.value && exposeMode.value === 'kb' ? "KI analysiert..." : "Dateien auslesen",
      secondaryLoading: exposeLoading.value && exposeMode.value === 'kb',
      secondaryAction: async () => { if (!exposeLoading.value) { await loadExposeFiles(); exposeFileSelect.value = true; exposeMode.value = 'kb'; } },
    },
    {
      id: "dateien", label: "Dateien",
      sub: (p.files_count || 0) + " Dokumente",
      icon: "FileText", color: "#dc2626", accent: "rgba(220,38,38,0.06)",
      badge: p.files_count > 0 ? String(p.files_count) : null,
      badgeColor: "#10b981",
      action: () => emit("openFiles", p.id, p.address),
    },
    {
      id: "nachrichten", label: "Nachrichten", sub: "Portal-Kommunikation",
      icon: "MessageCircle", color: "#2563eb", accent: "rgba(37,99,235,0.06)",
      action: () => emit("openMessages", p.id, p.address),
    },

    {
      id: "kaufanbote", label: "Kaufanbote", sub: "Angebote verwalten",
      icon: "ShoppingCart", color: "#be185d", accent: "rgba(190,24,93,0.06)",
      action: () => emit("openSettings", p.id),
    },
    {
      id: "parent_child", label: "Hierarchie",
      sub: p.parent_id ? "Ist Unterobjekt" : ((p.children && p.children.length) ? p.children.length + " Unterobjekt" + (p.children.length > 1 ? "e" : "") : "Zuordnung verwalten"),
      icon: "Link2", color: "#6366f1", accent: "rgba(99,102,241,0.06)",
      badge: p.parent_id ? "Kind" : ((p.children && p.children.length) ? String(p.children.length) : null),
      badgeColor: "#6366f1",
      action: () => { emit("assignParent", p); emit("close"); },
    },
  ];

  // Historie tile: show for all properties except newbuild
  if (p.property_category !== 'newbuild') {
    const histData = p.property_history;
    const histArr = typeof histData === 'string' ? (() => { try { return JSON.parse(histData); } catch(e) { return []; } })() : (histData || []);
    const histCount = Array.isArray(histArr) ? histArr.length : 0;
    items.splice(2, 0, {
      id: "historie", label: "Historie",
      sub: histCount ? histCount + " Eintraege" : "Keine Eintraege",
      icon: "Clock", color: "#D4743B", accent: "rgba(212,116,59,0.06)",
      badge: histCount ? String(histCount) : null, badgeColor: "#D4743B",
      action: () => { openHistory(); },
    });
  }

  // Newbuild-only tiles
  if (p.property_category === "newbuild") {
    // Insert after Eigentuemer & Portal (index 2)
    items.splice(2, 0,
      {
        id: "einheiten", label: "Einheiten",
        sub: unitStats.value ? `${unitStats.value.frei} frei, ${unitStats.value.reserviert} res., ${unitStats.value.verkauft} verk.` : "Laden...",
        icon: "Building2", color: "#ea580c", accent: "rgba(234,88,12,0.06)",
        action: () => emit("openSettings", p.id),
      },
      {
        id: "stellplaetze", label: "Stellplaetze", sub: "Parkplaetze & Garagen",
        icon: "ParkingSquare", color: "#4f46e5", accent: "rgba(79,70,229,0.06)",
        action: () => emit("openSettings", p.id),
      }
    );
  }

  // Child objects: only show limited tiles
  if (p.parent_id) {
    const childAllowed = ['objektdaten', 'parent_child'];
    return items.filter(t => childAllowed.includes(t.id));
  }

  // Add child create tile before hierarchy
  const pcIdx = items.findIndex(t => t.id === 'parent_child');
  const insertAt = pcIdx >= 0 ? pcIdx : items.length;
  items.splice(insertAt, 0, {
    id: "create_child", label: "Unterobjekt anlegen",
    sub: (p.children?.length || 0) + " vorhanden",
    icon: "Plus", color: "#6366f1", accent: "rgba(99,102,241,0.08)",
    action: () => { openChildCreateModal(); },
  });

  return items;
});

const iconMap = {
  Pencil, Users, Key, Building2, ClipboardList, BookOpen,
  FileText, MessageCircle, Sparkles, ShoppingCart, ParkingSquare, FolderOpen, Link2, Plus, Clock
};


async function loadProjectGroups() {
  try {
    const res = await fetch(`${API.value}&action=list_project_groups`);
    const data = await res.json();
    if (data.success) projectGroups.value = data.groups || [];
  } catch(e) { console.error('Failed to load project groups', e); }
}

async function assignProjectGroup(groupId) {
  const p = props.property;
  if (!p) return;
  try {
    await fetch(`${API.value}&action=update_property`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ property_id: p.id, project_group_id: groupId || null })
    });
    p.project_group_id = groupId;
    toast('Projektgruppe ' + (groupId ? 'zugewiesen' : 'entfernt'));
  } catch(e) { console.error(e); }
}

async function createAndAssignGroup() {
  if (!newGroupName.value.trim()) return;
  const p = props.property;
  try {
    const fd = new FormData();
    fd.append('name', newGroupName.value.trim());
    if (newGroupDesc.value.trim()) fd.append('description', newGroupDesc.value.trim());
    if (p?.customer_id) fd.append('customer_id', p.customer_id);
    const res = await fetch(`${API.value}&action=create_project_group`, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.group) {
      projectGroups.value.push(data.group);
      await assignProjectGroup(data.group.id);
      showNewGroupForm.value = false;
      newGroupName.value = '';
      newGroupDesc.value = '';
      toast('Projektgruppe erstellt & zugewiesen');
    }
  } catch(e) { console.error(e); }
}
</script>

<template>
  <Teleport to="body">
  <!-- ═══ MAIN OVERLAY ═══ -->
  <Transition
    enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
    enter-from-class="opacity-0" enter-to-class="opacity-100"
    leave-active-class="transition duration-200 ease-in"
    leave-from-class="opacity-100" leave-to-class="opacity-0"
  >
    <div v-if="visible && property" class="fixed inset-0 z-[300] flex items-center justify-center" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)" @wheel.stop @touchmove.prevent>
      <Transition
        enter-active-class="transition duration-400 ease-[cubic-bezier(0.22,1,0.36,1)]"
        enter-from-class="opacity-0 translate-y-6 scale-[0.97]" enter-to-class="opacity-100 translate-y-0 scale-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 translate-y-4"
      >
        <div v-if="visible" class="w-full max-w-3xl mx-4" style="max-height:92vh;display:flex;flex-direction:column" @click.stop>

          <!-- ─── Header Card ─── -->
          <div class="bg-white rounded-t-3xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);border-bottom:none;flex-shrink:0">
            <div class="relative px-4 sm:px-8 pt-6 sm:pt-8 pb-5 sm:pb-6">
              <div class="absolute inset-0 opacity-[0.03]" style="background:linear-gradient(135deg,#D4622B 0%,transparent 60%)"></div>

              <div class="relative flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
                <div class="flex items-start gap-3 sm:gap-4 min-w-0">
                  <button @click="$emit('close')"
                    class="w-10 h-10 rounded-xl flex items-center justify-center bg-zinc-100 hover:bg-zinc-200 transition-all duration-200 active:scale-[0.97] flex-shrink-0 mt-0.5">
                    <ArrowLeft class="w-5 h-5 text-zinc-600" />
                  </button>
                  <div class="min-w-0">
                    <h2 class="text-base sm:text-xl font-bold text-zinc-900 tracking-tight truncate">
                      {{ property.project_name || property.address }}
                    </h2>
                    <div class="flex items-center gap-2 sm:gap-3 mt-1 sm:mt-1.5 flex-wrap">
                      <span class="inline-flex items-center gap-1.5 text-sm text-zinc-500">
                        <MapPin class="w-3.5 h-3.5" />
                        {{ property.city }}{{ property.zip ? ", " + property.zip : "" }}
                      </span>
                      <span class="text-xs font-medium px-2 py-0.5 rounded-lg bg-zinc-100 text-zinc-600">{{ property.ref_id }}</span>
                      <span class="text-xs font-medium px-2 py-0.5 rounded-lg"
                        :style="property.realty_status === 'verkauft' ? 'background:rgba(239,68,68,0.08);color:#dc2626' : property.realty_status === 'aktiv' || property.realty_status === 'auftrag' ? 'background:rgba(16,185,129,0.08);color:#059669' : 'background:rgba(245,158,11,0.08);color:#d97706'">
                        {{ property.realty_status }}
                      </span>
                      <span v-if="property.on_hold" class="text-xs font-bold px-2 py-0.5 rounded-lg" style="background:rgba(107,114,128,0.08);color:#6b7280">Pausiert</span>
                    </div>
                    <div class="flex items-center gap-3 sm:gap-4 mt-1.5 sm:mt-2 flex-wrap">
                      <span v-if="property.purchase_price" class="text-base sm:text-lg font-bold text-zinc-900 tabular-nums">
                        {{ Number(property.purchase_price).toLocaleString("de-DE") }} EUR
                      </span>
                      <span v-if="property.total_area" class="text-sm text-zinc-500">{{ property.total_area }} m2</span>
                      <span v-if="property.rooms_amount" class="text-sm text-zinc-500">{{ property.rooms_amount }} Zi.</span>
                    </div>
                  </div>
                </div>

                <div class="flex items-center gap-1.5 sm:gap-2 flex-shrink-0 self-start sm:self-auto">
                  <button v-if="!property.on_hold" @click.stop="$emit('toggleOnHold', property)"
                    class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center border border-zinc-200 hover:bg-amber-50 hover:border-amber-200 transition-all duration-200 active:scale-[0.97]" title="Pausieren">
                    <Pause class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-amber-600" />
                  </button>
                  <button v-else @click.stop="$emit('toggleOnHold', property)"
                    class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center border border-zinc-200 hover:bg-emerald-50 hover:border-emerald-200 transition-all duration-200 active:scale-[0.97]" title="Aktivieren">
                    <Play class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-600" />
                  </button>
                  <button @click.stop="$emit('deleteProperty', property)"
                    class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center border border-zinc-200 hover:bg-red-50 hover:border-red-200 transition-all duration-200 active:scale-[0.97]" title="Loeschen">
                    <Trash2 class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-red-500" />
                  </button>
                  <button @click.stop="$emit('close')"
                    class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center border border-zinc-200 hover:bg-zinc-100 transition-all duration-200 active:scale-[0.97]">
                    <X class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-zinc-500" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- ─── Tiles Grid ─── -->
          <div class="bg-zinc-50 px-4 sm:px-6 py-3 sm:py-4" style="border-left:1px solid rgba(228,228,231,0.8);border-right:1px solid rgba(228,228,231,0.8);border-top:1px solid rgba(228,228,231,0.4);flex:1;min-height:0;overflow-y:auto">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
              <div v-for="tile in tiles" :key="tile.id"
                @click="tile.action ? tile.action() : null"
                class="group relative bg-white rounded-xl p-3 cursor-pointer transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:shadow-lg hover:shadow-zinc-200/50 hover:-translate-y-0.5 active:scale-[0.97] active:shadow-sm"
                style="border:1px solid rgba(228,228,231,0.6)">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center mb-1.5 transition-transform duration-300 group-hover:scale-105"
                  :style="'background:' + tile.accent">
                  <component :is="iconMap[tile.icon]" class="w-5 h-5" :style="'color:' + tile.color" />
                </div>
                <div class="text-sm font-semibold text-zinc-900 mb-0.5">{{ tile.label }}</div>
                <div class="text-xs text-zinc-500 leading-relaxed">{{ tile.sub }}</div>
                <button v-if="tile.secondaryAction" @click.stop="tile.secondaryAction()"
                  :disabled="tile.secondaryLoading"
                  class="mt-2 inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97] hover:shadow-sm"
                  :style="tile.secondaryLoading ? 'background:rgba(139,92,246,0.15);color:#7c3aed;border:1px solid rgba(139,92,246,0.25);opacity:0.8' : 'background:rgba(139,92,246,0.08);color:#7c3aed;border:1px solid rgba(139,92,246,0.15)'">
                  <span v-if="tile.secondaryLoading" class="w-3 h-3 border-[1.5px] border-violet-300 border-t-violet-600 rounded-full animate-spin"></span>
                  <Sparkles v-else class="w-3 h-3" />
                  {{ tile.secondaryLabel }}
                </button>
                <span v-if="tile.badge" class="absolute top-4 right-4 text-[10px] font-bold px-2 py-0.5 rounded-full text-white"
                  :style="'background:' + tile.badgeColor">{{ tile.badge }}</span>
                <ChevronRight class="absolute bottom-5 right-5 w-4 h-4 text-zinc-300 group-hover:text-zinc-400 group-hover:translate-x-0.5 transition-all duration-300" />
              </div>
            </div>

            <!-- Newbuild Stats -->
            <div v-if="property.property_category === 'newbuild' && unitStats && !property.parent_id" class="mt-4">
              <div class="bg-white rounded-2xl p-5" style="border:1px solid rgba(228,228,231,0.6)">
                <div class="flex items-center justify-between mb-3">
                  <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Verkaufsfortschritt</span>
                  <span class="text-xs font-bold" style="color:#D4622B">
                    {{ unitStats.useArea ? Math.round((unitStats.verkauftArea / unitStats.totalArea) * 100) : (unitStats.total > 0 ? Math.round((unitStats.verkauft / unitStats.total) * 100) : 0) }}% verkauft
                  </span>
                </div>
                <div class="w-full h-2.5 rounded-full overflow-hidden bg-zinc-100">
                  <div class="h-full rounded-full transition-all duration-700 ease-[cubic-bezier(0.22,1,0.36,1)]"
                    :style="'width:' + (unitStats.useArea ? (unitStats.verkauftArea / unitStats.totalArea * 100) : (unitStats.total > 0 ? (unitStats.verkauft / unitStats.total * 100) : 0)) + '%;background:linear-gradient(90deg,#10b981,#D4622B)'"></div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3 mt-4">
                  <div class="text-center p-2 rounded-xl bg-zinc-50">
                    <div class="text-lg font-bold text-zinc-900">{{ unitStats.useArea ? unitStats.totalArea.toFixed(0) : unitStats.total }}</div>
                    <div class="text-[10px] text-zinc-500">{{ unitStats.useArea ? 'Gesamt m²' : 'Gesamt' }}</div>
                  </div>
                  <div class="text-center p-2 rounded-xl" style="background:rgba(16,185,129,0.06)">
                    <div class="text-lg font-bold" style="color:#10b981">{{ unitStats.useArea ? unitStats.freiArea.toFixed(0) : unitStats.frei }}</div>
                    <div class="text-[10px] text-zinc-500">{{ unitStats.useArea ? 'Frei m²' : 'Frei' }}</div>
                  </div>
                  <div class="text-center p-2 rounded-xl" style="background:rgba(245,158,11,0.06)">
                    <div class="text-lg font-bold" style="color:#f59e0b">{{ unitStats.useArea ? unitStats.reserviertArea.toFixed(0) : unitStats.reserviert }}</div>
                    <div class="text-[10px] text-zinc-500">{{ unitStats.useArea ? 'Res. m²' : 'Reserviert' }}</div>
                  </div>
                  <div class="text-center p-2 rounded-xl" style="background:rgba(239,68,68,0.06)">
                    <div class="text-lg font-bold" style="color:#ef4444">{{ unitStats.useArea ? unitStats.verkauftArea.toFixed(0) : unitStats.verkauft }}</div>
                    <div class="text-[10px] text-zinc-500">{{ unitStats.useArea ? 'Verk. m²' : 'Verkauft' }}</div>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- Fixed Footer -->
          <div class="bg-zinc-50 rounded-b-3xl px-4 sm:px-6" style="border:1px solid rgba(228,228,231,0.8);border-top:1px solid rgba(228,228,231,0.4);flex-shrink:0">
            <div class="flex items-center justify-between py-2.5">
              <button @click="$emit('close')"
                class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-zinc-500 hover:text-zinc-700 hover:bg-white rounded-lg transition-all duration-200">
                <ArrowLeft class="w-3.5 h-3.5" /> Zurueck zur Liste
              </button>
              <button @click="$emit('openEditor', property.id)"
                class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white rounded-xl transition-all duration-200 active:scale-[0.97]"
                style="background:#18181b">
                <Pencil class="w-3.5 h-3.5" /> Objekt bearbeiten
              </button>
            </div>
          </div>

        </div>
      </Transition>
    </div>
  </Transition>

  <!-- ═══ EIGENTUEMER & PORTALZUGANG POP-UP ═══ -->
  <Transition
    enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
    enter-from-class="opacity-0" enter-to-class="opacity-100"
    leave-active-class="transition duration-200 ease-in"
    leave-from-class="opacity-100" leave-to-class="opacity-0"
  >
    <div v-if="portalPopupOpen" class="fixed inset-0 z-[310] flex items-center justify-center" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)" @click.self="portalPopupOpen = false">
      <Transition
        enter-active-class="transition duration-400 ease-[cubic-bezier(0.22,1,0.36,1)]"
        enter-from-class="opacity-0 scale-[0.95] translate-y-4" enter-to-class="opacity-100 scale-100 translate-y-0"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95"
      >
        <div v-if="portalPopupOpen" class="bg-white rounded-2xl sm:rounded-3xl shadow-2xl w-full max-w-lg mx-3 sm:mx-4 overflow-hidden" style="border:1px solid rgba(228,228,231,0.6)" @click.stop>

          <!-- Header -->
          <div class="px-7 pt-6 pb-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(212,98,43,0.08)">
                <Key class="w-5 h-5" style="color:#D4622B" />
              </div>
              <div>
                <h3 class="text-base font-bold text-zinc-900">Eigentuemer & Portal</h3>
                <p class="text-xs text-zinc-500">{{ property?.project_name || property?.address }}</p>
              </div>
            </div>
            <button @click="portalPopupOpen = false"
              class="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-zinc-100 transition-all duration-200 active:scale-[0.97]">
              <X class="w-4 h-4 text-zinc-500" />
            </button>
          </div>

          <div class="px-7 pb-7 space-y-5">

            <!-- ─── Eigentuemer Section ─── -->
            <div>
              <h4 class="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider mb-3">Eigentuemer</h4>

              <!-- Has owner -->
              <div v-if="ownerData.customer_id" class="p-4 rounded-2xl space-y-3" style="background:rgba(16,185,129,0.04);border:1px solid rgba(16,185,129,0.15)">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full flex items-center justify-center" style="background:#10b981;color:white">
                    <Users class="w-4 h-4" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-zinc-900">{{ ownerData.owner_name }}</div>
                    <div class="text-xs text-zinc-500">{{ ownerData.owner_email || "Keine E-Mail" }}{{ ownerData.owner_phone ? " · " + ownerData.owner_phone : "" }}</div>
                  </div>
                  <button @click="unlinkCustomer"
                    class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-red-50 transition-all duration-200 active:scale-[0.97]" title="Verknuepfung loesen">
                    <Unlink class="w-3.5 h-3.5 text-red-400" />
                  </button>
                </div>
              </div>

              <!-- No owner -->
              <div v-else class="space-y-3">
                <div class="p-4 rounded-2xl" style="background:#fafafa;border:1px solid rgba(228,228,231,0.6)">
                  <p class="text-xs text-zinc-500 mb-3">Eigentuemer aus Kontakten waehlen oder neu anlegen</p>
                  <select v-model="selectedCustomerId" @change="selectExistingOwner"
                    class="w-full px-3 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 transition-all">
                    <option value="">-- Kontakt waehlen --</option>
                    <option v-for="c in customersList" :key="c.id" :value="c.id">{{ c.name }} · {{ c.email || "keine Email" }}</option>
                  </select>
                  <button @click="showCreateOwnerForm = !showCreateOwnerForm"
                    class="mt-3 inline-flex items-center gap-2 text-xs font-medium px-3 py-1.5 rounded-lg transition-all duration-200 active:scale-[0.97]"
                    style="background:#18181b;color:white">
                    <UserPlus class="w-3.5 h-3.5" /> Neuen Eigentuemer anlegen
                  </button>
                </div>

                <!-- Create new owner form -->
                <div v-if="showCreateOwnerForm" class="p-4 rounded-2xl space-y-3" style="background:rgba(212,98,43,0.04);border:1px solid rgba(212,98,43,0.15)">
                  <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:#D4622B">Neuen Eigentuemer anlegen</div>
                  <input v-model="newOwnerForm.name" type="text" placeholder="Vor- und Nachname *"
                    class="w-full px-3 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input v-model="newOwnerForm.email" type="email" placeholder="E-Mail"
                      class="w-full px-3 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 transition-all" />
                    <input v-model="newOwnerForm.phone" type="tel" placeholder="Telefon"
                      class="w-full px-3 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 transition-all" />
                  </div>
                  <div class="flex gap-2">
                    <button @click="showCreateOwnerForm = false"
                      class="flex-1 px-3 py-2 text-xs rounded-xl border border-zinc-200 hover:bg-zinc-50 transition-all">Abbrechen</button>
                    <button @click="createNewOwner" :disabled="newOwnerSaving || !newOwnerForm.name"
                      class="flex-1 px-3 py-2 text-xs rounded-xl font-medium text-white transition-all duration-200 active:scale-[0.97] disabled:opacity-50"
                      style="background:#D4622B">
                      {{ newOwnerSaving ? "Wird angelegt..." : "Anlegen & Zuweisen" }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- ─── Portalzugang Section ─── -->
            <div>
              <h4 class="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider mb-3">Portalzugang</h4>

              <!-- Portal active -->
              <div v-if="portalUser" class="p-4 rounded-2xl flex items-center gap-3" style="background:rgba(16,185,129,0.04);border:1px solid rgba(16,185,129,0.15)">
                <div class="w-9 h-9 rounded-full flex items-center justify-center" style="background:#10b981;color:white">
                  <Check class="w-4 h-4" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-semibold text-zinc-900">{{ portalUser.name }}</div>
                  <div class="text-xs text-zinc-500">{{ portalUser.email }}</div>
                </div>
                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full text-white" style="background:#10b981">Aktiv</span>
              </div>

              <!-- No portal - needs owner first -->
              <div v-else-if="!ownerData.customer_id" class="p-4 rounded-2xl" style="background:#fafafa;border:1px solid rgba(228,228,231,0.6)">
                <p class="text-xs text-zinc-400">Zuerst einen Eigentuemer zuweisen, um einen Portalzugang zu erstellen.</p>
              </div>

              <!-- No portal - can create -->
              <div v-else class="space-y-3">
                <div v-if="!showPortalForm" class="p-4 rounded-2xl flex items-center gap-3" style="background:#fafafa;border:1px solid rgba(228,228,231,0.6)">
                  <div class="w-9 h-9 rounded-full flex items-center justify-center bg-zinc-200">
                    <Key class="w-4 h-4 text-zinc-500" />
                  </div>
                  <div class="flex-1">
                    <div class="text-sm text-zinc-500">Kein Portalzugang</div>
                    <div class="text-xs text-zinc-400">{{ ownerData.owner_email }}</div>
                  </div>
                  <button @click="showPortalForm = true"
                    class="text-xs font-medium px-4 py-2 rounded-xl transition-all duration-200 active:scale-[0.97]"
                    style="background:#18181b;color:white">
                    Zugang erstellen
                  </button>
                </div>

                <!-- Create portal form -->
                <div v-else class="p-4 rounded-2xl space-y-3" style="background:rgba(212,98,43,0.04);border:1px solid rgba(212,98,43,0.15)">
                  <div class="text-sm text-zinc-700">
                    <span class="text-zinc-400">Login:</span> <span class="font-medium">{{ ownerData.owner_email }}</span>
                  </div>
                  <input v-model="portalForm.password" type="text" placeholder="Initiales Passwort vergeben"
                    class="w-full px-3 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 transition-all" />
                  <div v-if="portalError" class="text-xs text-red-500">{{ portalError }}</div>
                  <div v-if="portalSuccess" class="text-xs" style="color:#10b981">{{ portalSuccess }}</div>
                  <div class="flex gap-2">
                    <button @click="showPortalForm = false; portalError = ''"
                      class="flex-1 px-3 py-2 text-xs rounded-xl border border-zinc-200 hover:bg-zinc-50 transition-all">Abbrechen</button>
                    <button @click="createPortalAccess" :disabled="portalCreating || !portalForm.password"
                      class="flex-1 px-3 py-2 text-xs rounded-xl font-medium text-white transition-all duration-200 active:scale-[0.97] disabled:opacity-50"
                      style="background:#D4622B">
                      {{ portalCreating ? "Wird erstellt..." : "Zugang erstellen" }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </Transition>
    </div>
  </Transition>

  <!-- ═══ EXPOSE KI POP-UP ═══ -->
  <Transition
    enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
    enter-from-class="opacity-0" enter-to-class="opacity-100"
    leave-active-class="transition duration-200 ease-in"
    leave-from-class="opacity-100" leave-to-class="opacity-0"
  >
    <div v-if="exposePopupOpen" class="fixed inset-0 z-[310] flex items-center justify-center" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)" @click.self="exposePopupOpen = false">
      <Transition
        enter-active-class="transition duration-400 ease-[cubic-bezier(0.22,1,0.36,1)]"
        enter-from-class="opacity-0 scale-[0.95] translate-y-4" enter-to-class="opacity-100 scale-100 translate-y-0"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95"
      >
        <div v-if="exposePopupOpen" class="bg-white rounded-2xl sm:rounded-3xl shadow-2xl w-full max-w-lg mx-3 sm:mx-4 overflow-hidden" style="border:1px solid rgba(228,228,231,0.6)" @click.stop>

          <!-- Header -->
          <div class="px-7 pt-6 pb-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(139,92,246,0.08)">
                <Sparkles class="w-5 h-5" style="color:#8b5cf6" />
              </div>
              <div>
                <h3 class="text-base font-bold text-zinc-900">Expose KI</h3>
                <p class="text-xs text-zinc-500">{{ property?.project_name || property?.address }}</p>
              </div>
            </div>
            <button @click="exposePopupOpen = false"
              class="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-zinc-100 transition-all duration-200 active:scale-[0.97]">
              <X class="w-4 h-4 text-zinc-500" />
            </button>
          </div>

          <div class="px-7 pb-7 space-y-4">

            <p class="text-xs text-zinc-500 leading-relaxed">Die KI analysiert das hochgeladene Expose (aus Wissen oder Dateien) und kann die Ergebnisse auf zwei Arten verwenden:</p>

            <!-- No result yet — show two action buttons -->
            <div v-if="!exposeResult" class="space-y-3">

              <!-- Option 1: Wissens-DB fuellen -->
              <button @click="runExpose('kb')" :disabled="exposeLoading"
                class="w-full p-4 rounded-2xl text-left transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:shadow-lg hover:-translate-y-0.5 active:scale-[0.97]"
                style="background:rgba(5,150,105,0.04);border:1px solid rgba(5,150,105,0.15)">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(5,150,105,0.08)">
                    <BookOpen class="w-5 h-5" style="color:#059669" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-zinc-900">Wissens-DB fuellen</div>
                    <div class="text-xs text-zinc-500 mt-0.5">Extrahierte Daten als Wissenseintraege speichern. Sherlock kann diese Infos dann in Antworten verwenden.</div>
                  </div>
                  <ChevronRight class="w-4 h-4 text-zinc-300 flex-shrink-0" />
                </div>
              </button>

              <!-- Option 2: Objektdaten ausfuellen -->
              <button @click="runExpose('fields')" :disabled="exposeLoading"
                class="w-full p-4 rounded-2xl text-left transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:shadow-lg hover:-translate-y-0.5 active:scale-[0.97]"
                style="background:rgba(139,92,246,0.04);border:1px solid rgba(139,92,246,0.15)">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(139,92,246,0.08)">
                    <Pencil class="w-5 h-5" style="color:#8b5cf6" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-zinc-900">Objektdaten ausfuellen</div>
                    <div class="text-xs text-zinc-500 mt-0.5">Felder wie Flaeche, Zimmer, Preis, Ausstattung etc. automatisch aus dem Expose befuellen. Bei Neubauprojekten auch Einheiten.</div>
                  </div>
                  <ChevronRight class="w-4 h-4 text-zinc-300 flex-shrink-0" />
                </div>
              </button>

              <!-- Loading state -->
              <div v-if="exposeLoading" class="flex items-center justify-center gap-3 py-4">
                <span class="w-5 h-5 border-2 border-zinc-300 border-t-violet-500 rounded-full animate-spin"></span>
                <span class="text-sm text-zinc-500">KI analysiert Expose...</span>
              </div>
            </div>

            <!-- Results preview -->
            <div v-if="exposeResult" class="space-y-4">
              <div class="text-xs font-semibold flex items-center gap-2">
                <span class="w-2 h-2 rounded-full" :style="exposeResult.confidence === 'high' ? 'background:#10b981' : exposeResult.confidence === 'medium' ? 'background:#f59e0b' : 'background:#ef4444'"></span>
                Erkannte Daten ({{ exposeResult.confidence || 'medium' }})
              </div>

              <!-- Fields preview -->
              <div v-if="exposeResult.fields" class="rounded-2xl overflow-hidden max-h-48 overflow-y-auto" style="border:1px solid rgba(228,228,231,0.6)">
                <div v-for="(val, key) in exposeResult.fields" :key="key" class="px-4 py-2 flex items-center justify-between text-xs border-b border-zinc-100 last:border-b-0">
                  <span class="text-zinc-500">{{ key }}</span>
                  <span class="font-medium text-zinc-900">{{ val }}</span>
                </div>
              </div>

              <!-- Units preview -->
              <div v-if="exposeResult.units && exposeResult.units.length" class="rounded-2xl overflow-hidden" style="border:1px solid rgba(139,92,246,0.2)">
                <div class="px-4 py-2 text-xs font-semibold" style="background:rgba(139,92,246,0.04)">{{ exposeResult.units.length }} Einheiten erkannt</div>
                <div v-for="(u, i) in exposeResult.units.slice(0, 5)" :key="i" class="px-4 py-1.5 text-[11px] border-b border-zinc-100 last:border-b-0 flex gap-3">
                  <span class="font-medium w-8">{{ u.unit_number }}</span>
                  <span>{{ u.rooms_amount || '?' }} Zi</span>
                  <span>{{ u.area_m2 || '?' }}m2</span>
                  <span>{{ u.price ? Number(u.price).toLocaleString('de-DE') + ' EUR' : '?' }}</span>
                </div>
                <div v-if="exposeResult.units.length > 5" class="px-4 py-1.5 text-[10px] text-zinc-400">... und {{ exposeResult.units.length - 5 }} weitere</div>
              </div>

              <!-- Warnings -->
              <div v-if="exposeResult.warnings && exposeResult.warnings.length" class="text-[11px] text-amber-600 space-y-0.5">
                <div v-for="(w, i) in exposeResult.warnings" :key="i">{{ w }}</div>
              </div>

              <!-- Action buttons -->
              <div class="flex gap-3">
                <button @click="exposeMode === 'kb' ? applyExposeToKB() : applyExposeToFields()"
                  class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 text-xs font-medium text-white rounded-xl transition-all duration-200 active:scale-[0.97]"
                  style="background:#18181b">
                  <Check class="w-3.5 h-3.5" />
                  {{ exposeMode === 'kb' ? 'In Wissens-DB speichern' : 'Objektdaten uebernehmen' }}
                </button>
                <button @click="exposeResult = null"
                  class="px-4 py-2.5 text-xs font-medium rounded-xl border border-zinc-200 hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]">
                  Verwerfen
                </button>
              </div>
            </div>

          </div>
        </div>
      </Transition>
    </div>
  </Transition>

    <!-- File Selection Popup for Expose -->
    <div v-if="exposeFileSelect" class="fixed inset-0 z-[310] flex items-center justify-center bg-black/50" @click.self="exposeFileSelect = false">
      <div class="bg-white rounded-2xl shadow-xl p-6 max-w-md w-full mx-4 border border-zinc-200">
        <h3 class="text-sm font-semibold text-zinc-800 mb-3">Dateien zum Auslesen</h3>
        <p class="text-xs text-zinc-500 mb-4">Vorhandene Dateien auswaehlen oder neue hochladen.</p>

        <!-- Upload Button -->
        <label class="flex items-center justify-center gap-2 px-4 py-3 mb-3 rounded-xl border-2 border-dashed cursor-pointer transition-all hover:border-zinc-400 hover:bg-zinc-50"
          :style="exposeUploading ? 'border-color:#6366f1;background:rgba(99,102,241,0.04)' : 'border-color:#d4d4d8'">
          <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="hidden" @change="uploadExposeFiles" :disabled="exposeUploading" />
          <Upload v-if="!exposeUploading" class="w-4 h-4" style="color:#71717a" />
          <span v-if="!exposeUploading" class="text-xs font-medium" style="color:#555">Dateien hochladen (mehrere moeglich)</span>
          <span v-else class="flex items-center gap-2 text-xs font-medium" style="color:#6366f1">
            <span class="w-3.5 h-3.5 border-2 border-indigo-300 border-t-indigo-600 rounded-full animate-spin"></span>
            Lade hoch...
          </span>
        </label>

        <div class="space-y-2 max-h-60 overflow-y-auto mb-4">
          <label v-for="f in exposeFiles" :key="f.id"
            class="flex items-center gap-3 p-2 rounded-lg hover:bg-zinc-50 cursor-pointer transition-colors">
            <input type="checkbox" :value="f.id" v-model="exposeSelectedFiles"
              class="w-4 h-4 rounded border-zinc-300 text-zinc-800 focus:ring-zinc-500" />
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-zinc-700 truncate">{{ f.label || f.filename }}</div>
              <div class="text-xs text-zinc-400 truncate">{{ f.filename }}</div>
            </div>
            <button v-if="f.source === 'property_files'" @click.prevent.stop="toggleWebsiteDownload(f)"
              :title="f.is_website_download ? 'Download auf Website aktiv' : 'Auf Website zum Download freigeben'"
              class="flex-shrink-0 p-1.5 rounded-lg transition-all duration-200"
              :class="f.is_website_download ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'text-zinc-300 hover:text-zinc-500 hover:bg-zinc-100'">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
              </svg>
            </button>
          </label>
          <div v-if="!exposeFiles.length && !exposeUploading" class="text-sm text-zinc-400 text-center py-4">Noch keine Dateien. Lade Expose, Preisliste etc. hoch.</div>
        </div>
        <div class="flex items-center gap-2">
          <button @click="exposeFileSelect = false; runExpose(exposeMode)" :disabled="!exposeSelectedFiles.length || exposeUploading"
            class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97]"
            :class="exposeSelectedFiles.length && !exposeUploading ? 'bg-zinc-800 text-white hover:bg-zinc-700' : 'bg-zinc-100 text-zinc-400 cursor-not-allowed'">
            <Sparkles class="w-3.5 h-3.5" />
            {{ exposeSelectedFiles.length }} Datei(en) auslesen
          </button>
          <button @click="exposeFileSelect = false" class="px-3 py-2 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all">Abbrechen</button>
        </div>
      </div>
    </div>

    <!-- Projektgruppe Popup -->
    <div v-if="projectGroupPopup" class="fixed inset-0 z-[320] flex items-center justify-center bg-black/40" @click.self="projectGroupPopup = false">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
        <div class="px-6 py-4 border-b border-zinc-100 flex items-center justify-between">
          <h3 class="text-base font-semibold text-zinc-900">Projektgruppe</h3>
          <button @click="projectGroupPopup = false" class="w-7 h-7 rounded-lg hover:bg-zinc-100 flex items-center justify-center">
            <X class="w-4 h-4 text-zinc-400" />
          </button>
        </div>
        <div class="px-6 py-5 space-y-4">
          <p class="text-xs text-zinc-500">Mehrere Objekte im Kundenportal unter einem Projektnamen zusammenfassen.</p>
          <div>
            <label class="block text-xs font-medium text-zinc-500 mb-1.5">Projektgruppe zuweisen</label>
            <select :value="property?.project_group_id || ''" @change="assignProjectGroup($event.target.value ? Number($event.target.value) : null)" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-400">
              <option value="">\u2013 Keine Gruppe \u2013</option>
              <option v-for="g in projectGroups.filter(x => !property?.customer_id || !x.customer_id || x.customer_id == property.customer_id)" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
          <div v-if="!showNewGroupForm">
            <button @click="showNewGroupForm = true" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-teal-700 bg-teal-50 border border-teal-200 rounded-lg hover:bg-teal-100 transition-all">
              <Plus class="w-3.5 h-3.5" /> Neue Gruppe erstellen
            </button>
          </div>
          <div v-if="showNewGroupForm" class="border border-teal-200 rounded-xl p-4 bg-teal-50/50 space-y-3">
            <input v-model="newGroupName" type="text" placeholder="Gruppenname (z.B. Eggelsberg Wohnkultur)" class="w-full px-3 py-2 bg-white border border-zinc-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20" />
            <input v-model="newGroupDesc" type="text" placeholder="Beschreibung (optional)" class="w-full px-3 py-2 bg-white border border-zinc-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20" />
            <div class="flex gap-2">
              <button @click="createAndAssignGroup" class="px-3 py-1.5 text-xs font-medium text-white bg-teal-600 rounded-lg hover:bg-teal-700">Erstellen & Zuweisen</button>
              <button @click="showNewGroupForm = false" class="px-3 py-1.5 text-xs font-medium text-zinc-500 bg-white border border-zinc-200 rounded-lg hover:bg-zinc-50">Abbrechen</button>
            </div>
          </div>
        </div>
      </div>
    </div>


    <!-- Child Create Modal -->
    <div v-if="childCreateModal" class="fixed inset-0 z-[320] flex items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px)">
      <div class="relative w-[480px] rounded-2xl shadow-2xl overflow-hidden" style="background:white;border:1px solid #eaeaea">
        <div class="flex items-center justify-between px-5 py-4" style="border-bottom:1px solid #eaeaea">
          <div>
            <div class="text-[14px] font-semibold" style="color:#111">Unterobjekte anlegen</div>
            <div class="text-[11px] mt-0.5" style="color:#787774">Kategorien aus Einheiten oder manuell</div>
          </div>
          <button @click="childCreateModal = false" class="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-zinc-100 transition-all">
            <X class="w-4 h-4" style="color:#a1a1aa" />
          </button>
        </div>

        <!-- Mode Toggle -->
        <div class="flex gap-1 mx-5 mt-3 p-0.5 rounded-lg" style="background:#f4f4f5">
          <button @click="childMode = 'categories'" class="flex-1 px-3 py-1.5 text-[11px] font-medium rounded-md transition-all"
            :style="childMode === 'categories' ? 'background:white;color:#111;box-shadow:0 1px 2px rgba(0,0,0,0.06)' : 'color:#71717a'">
            Aus Einheiten
          </button>
          <button @click="childMode = 'manual'" class="flex-1 px-3 py-1.5 text-[11px] font-medium rounded-md transition-all"
            :style="childMode === 'manual' ? 'background:white;color:#111;box-shadow:0 1px 2px rgba(0,0,0,0.06)' : 'color:#71717a'">
            Manuell
          </button>
        </div>

        <!-- Categories Mode -->
        <div v-if="childMode === 'categories'" class="px-5 py-4">
          <div v-if="childCategoriesLoading" class="flex items-center justify-center py-8">
            <div class="w-5 h-5 border-2 border-zinc-300 border-t-[#6366f1] rounded-full animate-spin"></div>
          </div>
          <div v-else-if="!childCategories.length" class="text-center py-6">
            <div class="text-[12px]" style="color:#a1a1aa">Keine Einheiten mit Zimmerzahl gefunden</div>
            <div class="text-[11px] mt-1" style="color:#a1a1aa">Erstelle zuerst Einheiten im Master-Objekt</div>
          </div>
          <div v-else class="space-y-2">
            <div v-for="cat in childCategories" :key="cat.rooms"
              @click="toggleCategory(cat.rooms)"
              class="flex items-center gap-3 px-3 py-2.5 rounded-xl cursor-pointer transition-all"
              :style="childSelected.has(cat.rooms)
                ? 'background:rgba(99,102,241,0.06);border:1.5px solid #6366f1'
                : 'background:#fafaf9;border:1.5px solid #eaeaea;'">
              <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 transition-all"
                :style="childSelected.has(cat.rooms) ? 'background:#6366f1' : 'background:white;border:1.5px solid #d4d4d8'">
                <Check v-if="childSelected.has(cat.rooms)" class="w-3 h-3" style="color:white" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-[13px] font-medium" style="color:#111">{{ Math.floor(cat.rooms) }}-Zimmer</span>
                  <span class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:#f0fdf4;color:#15803d">{{ cat.unit_count }} Einheiten</span>
                  <span v-if="cat.frei > 0" class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:#eff6ff;color:#2563eb">{{ cat.frei }} frei</span>
                </div>
                <div class="text-[11px] mt-0.5" style="color:#787774">
                  ab {{ formatPrice(cat.min_price) }}
                  <span v-if="cat.min_price != cat.max_price"> bis {{ formatPrice(cat.max_price) }}</span>
                  <span class="mx-1">&middot;</span>
                  {{ cat.min_area }}{{ cat.min_area != cat.max_area ? ' - ' + cat.max_area : '' }} m&sup2;
                </div>
              </div>
            </div>
            <div class="text-[11px] rounded-lg px-3 py-2 mt-2" style="background:#f5f3ff;color:#6366f1;border:1px solid rgba(99,102,241,0.15)">
              Pro Kategorie wird ein Unterobjekt mit dem guenstigsten Preis erstellt. Bilder und Beschreibungen danach im Editor pflegen.
            </div>
          </div>
        </div>

        <!-- Manual Mode -->
        <div v-if="childMode === 'manual'" class="px-5 py-4 space-y-3">
          <div>
            <label class="block text-[11px] font-medium mb-1" style="color:#555">Titel *</label>
            <input v-model="childManualTitle" type="text" placeholder="z.B. Penthouse-Wohnungen"
              class="w-full px-3 py-2 text-[13px] rounded-lg border outline-none transition-all duration-200 focus:ring-2 focus:ring-[#6366f1]/20"
              style="border-color:#eaeaea;background:#f9f9f8;color:#111"
              @keydown.enter="createChildManual" />
          </div>
          <div class="text-[11px] rounded-lg px-3 py-2" style="background:#f5f3ff;color:#6366f1;border:1px solid rgba(99,102,241,0.15)">
            Basisdaten werden vom Master uebernommen.
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-2 px-5 py-3" style="border-top:1px solid #eaeaea;background:#fafaf9">
          <button @click="childCreateModal = false" class="px-3 py-1.5 text-[12px] rounded-lg hover:bg-zinc-100 transition-all" style="color:#555">Abbrechen</button>
          <button v-if="childMode === 'categories'"
            @click="createChildrenFromCategories"
            :disabled="!childSelected.size || childCreateLoading"
            class="px-4 py-1.5 text-[12px] font-medium rounded-lg transition-all disabled:opacity-40"
            style="background:#6366f1;color:white">
            <span v-if="childCreateLoading" class="flex items-center gap-1.5">
              <span class="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              Erstelle...
            </span>
            <span v-else>{{ childSelected.size }} Kategorie{{ childSelected.size !== 1 ? 'n' : '' }} erstellen</span>
          </button>
          <button v-else
            @click="createChildManual"
            :disabled="!childManualTitle.trim() || childCreateLoading"
            class="px-4 py-1.5 text-[12px] font-medium rounded-lg transition-all disabled:opacity-40"
            style="background:#6366f1;color:white">
            <span v-if="childCreateLoading" class="flex items-center gap-1.5">
              <span class="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              Erstelle...
            </span>
            <span v-else>Erstellen</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Historie Modal -->
    <div v-if="historyOpen" class="fixed inset-0 z-[310] flex items-center justify-center" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)" @click.self="historyOpen = false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[85vh] overflow-hidden" style="border:1px solid rgba(228,228,231,0.6)" @click.stop>
        <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid rgba(228,228,231,0.6)">
          <h2 class="text-lg font-bold text-zinc-900">Historie</h2>
          <div class="flex items-center gap-2">
            <button @click="historyAdding = !historyAdding" class="text-xs font-medium px-3 py-1.5 rounded-lg transition-all" :class="historyAdding ? 'bg-zinc-200 text-zinc-600' : 'bg-zinc-900 text-white hover:bg-zinc-800'">
              {{ historyAdding ? 'Abbrechen' : '+ Eintrag' }}
            </button>
            <button @click="historyOpen = false" class="w-8 h-8 rounded-xl flex items-center justify-center bg-zinc-100 hover:bg-zinc-200 transition-all"><X class="w-4 h-4 text-zinc-500" /></button>
          </div>
        </div>
        <div class="px-6 py-5 overflow-y-auto" style="max-height:calc(85vh - 64px)">
          <!-- Add form -->
          <div v-if="historyAdding" class="mb-6 p-4 bg-zinc-50 rounded-xl border border-zinc-200 space-y-3">
            <div class="flex gap-3">
              <input v-model="historyNew.year" type="text" placeholder="Jahr" class="w-20 px-3 py-2 text-sm bg-white border border-zinc-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
              <input v-model="historyNew.title" type="text" placeholder="Titel" class="flex-1 px-3 py-2 text-sm bg-white border border-zinc-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            </div>
            <input v-model="historyNew.description" type="text" placeholder="Beschreibung (optional)" class="w-full px-3 py-2 text-sm bg-white border border-zinc-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            <button @click="historyAddEntry()" :disabled="!historyNew.year || !historyNew.title" class="px-4 py-2 text-xs font-medium bg-zinc-900 text-white rounded-lg hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed">Hinzufuegen</button>
          </div>

          <!-- Timeline -->
          <div class="relative">
            <div v-if="historyItems.length" class="absolute left-[28px] top-0 bottom-0 w-[2px]" style="background:linear-gradient(to bottom, #D4743B, rgba(212,116,59,0.1))"></div>
            <div v-for="(h, i) in historyItems" :key="i" class="group relative flex gap-5 mb-6">
              <div class="flex-shrink-0 relative z-10">
                <div class="w-[56px] h-[56px] rounded-2xl flex items-center justify-center text-xs font-black text-white shadow-md" style="background:linear-gradient(135deg, #D4743B, #B85A2A)">{{ h.year }}</div>
              </div>
              <div class="flex-1 pt-1.5">
                <div class="text-sm font-bold text-zinc-900">{{ h.title }}</div>
                <p v-if="h.description && h.description !== h.title" class="text-xs text-zinc-500 mt-1 leading-relaxed">{{ h.description }}</p>
              </div>
              <button @click="historyDeleteEntry(i)" class="opacity-0 group-hover:opacity-100 transition-opacity p-1.5 text-zinc-300 hover:text-red-500 rounded-lg flex-shrink-0 self-center">
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </div>
            <div v-if="!historyItems.length" class="text-center py-8 text-sm text-zinc-400">Keine Historie-Eintraege vorhanden</div>
          </div>
        </div>
      </div>
    </div>

  </Teleport>
</template>