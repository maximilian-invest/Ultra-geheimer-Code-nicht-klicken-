<script setup>
import { ref, computed, inject, watch } from "vue";
import {
  X, Pencil, Users, Key, Building2, ClipboardList, BookOpen,
  FileText, MessageCircle, Sparkles, ChevronRight,
  MapPin, ArrowLeft, Pause, Play, Trash2,
  ShoppingCart, ParkingSquare, FolderOpen, Plus, Check, UserPlus, Unlink, Link2,
  Upload, Clock, ChevronDown, ChevronUp, Home, Search
} from "lucide-vue-next";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

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
const toast = inject("toast");
const kbCounts = inject("kbCounts");

// ─── New state for tabs + collapsibles ─────────────────
const activeTab = ref('objekt');
const openSections = ref({
  objektdaten: true,
  eigentuemerPortal: true,
  einheiten: true,
  stellplaetze: false,
  wissensdb: false,
  dateien: false,
  historie: false,
  unterobjekt: false,
  hierarchie: false,
  protokoll: false,
  nachrichten: false,
});

// Unit filter/search for Einheiten table
const unitFilter = ref('alle');
const unitSearch = ref('');
const allUnits = ref([]);

const filteredUnits = computed(() => {
  let units = allUnits.value.filter(u => !u.is_parking);
  if (unitFilter.value !== 'alle') {
    units = units.filter(u => u.status === unitFilter.value);
  }
  if (unitSearch.value.trim()) {
    const q = unitSearch.value.trim().toLowerCase();
    units = units.filter(u =>
      (u.unit_number || '').toLowerCase().includes(q) ||
      (u.unit_type || '').toLowerCase().includes(q) ||
      (u.top_number || '').toLowerCase().includes(q)
    );
  }
  return units;
});

// ─── Child property creation ───────────────────────────
const childCreateLoading = ref(false);
const childCreateModal = ref(false);
const childCategories = ref([]);
const childCategoriesLoading = ref(false);
const childSelected = ref(new Set());
const childManualTitle = ref('');
const childMode = ref('categories');

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
const exposeLoading = ref(false);
const exposeMode = ref(null);
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
    if (d.error) { toast(d.error); }
    else {
      exposeResult.value = d.extracted || d;
      const savedMsg = d.fields_saved ? d.fields_saved + " Felder gespeichert" : "";
      const unitsMsg = (d.units_created || d.units_updated) ? (d.units_created + " Einheiten importiert, " + d.units_updated + " aktualisiert") : "";
      const msg = [savedMsg, unitsMsg].filter(Boolean).join(", ");
      toast(msg || "Expose analysiert!");
      if (mode === 'kb') {
        await applyExposeToKB();
      } else if (mode === 'fields') {
        emit("close");
        setTimeout(() => emit("openEditor", props.property.id), 300);
      }
    }
  } catch (e) { toast("Fehler: " + e.message); }
  exposeLoading.value = false;
}

async function applyExposeToKB() {
  if (!exposeResult.value) return;
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
  if (!exposeResult.value) { return; }
  const result = exposeResult.value;
  if (result.fields) {
    try {
      const payload = { property_id: props.property.id, ...result.fields };
      const r = await fetch(API.value + "&action=save_property_settings", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const d = await r.json();
      if (d.success) {
        toast("Objektdaten aktualisiert (" + Object.keys(result.fields).length + " Felder gespeichert)");
        emit("close");
        setTimeout(() => emit("openEditor", props.property.id), 300);
      } else { toast("Fehler beim Speichern: " + (d.error || "Unbekannt")); }
    } catch (e) { console.error("[Expose] save error:", e); toast("Fehler: " + e.message); }
  }
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

// ─── Project Groups ─────────────────────────────────────
const projectGroups = ref([]);
const projectGroupPopup = ref(false);
const newGroupName = ref('');
const newGroupDesc = ref('');
const showNewGroupForm = ref(false);

// ─── History count helper ───────────────────────────────
const historyCount = computed(() => {
  const p = props.property;
  if (!p) return 0;
  let d = p.property_history;
  if (typeof d === 'string') { try { d = JSON.parse(d); } catch(e) { return 0; } }
  return Array.isArray(d) ? d.length : 0;
});

// ─── Watch visibility ───────────────────────────────────
watch(() => props.visible, async (v) => {
  if (v && props.property) {
    activeTab.value = 'objekt';
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
      allUnits.value = units;
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

function unitRowClass(status) {
  if (status === 'verkauft') return 'bg-red-50/50';
  if (status === 'reserviert') return 'bg-amber-50/50';
  if (status === 'frei') return 'bg-emerald-50/30';
  return '';
}
</script>

<template>
  <!-- ═══ MAIN DIALOG ═══ -->
  <Dialog :open="visible && !!property" @update:open="val => { if (!val) $emit('close') }">
    <DialogContent class="max-w-[950px] p-0 gap-0 max-h-[92vh] flex flex-col overflow-hidden">

      <!-- ─── Header ─── -->
      <div class="px-6 pt-5 pb-4 flex-shrink-0 border-b border-border">
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-3 min-w-0">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-muted flex-shrink-0 mt-0.5">
              <Home class="w-5 h-5 text-muted-foreground" />
            </div>
            <div class="min-w-0">
              <DialogTitle class="text-base font-bold text-foreground tracking-tight truncate">
                {{ property?.project_name || property?.address }}
              </DialogTitle>
              <DialogDescription class="text-sm text-muted-foreground mt-0.5">
                {{ property?.city }}{{ property?.zip ? ', ' + property.zip : '' }}
                <template v-if="property?.property_category">
                  &bull; {{ property.property_category === 'newbuild' ? 'Neubauprojekt' : 'Bestandsobjekt' }}
                </template>
                <template v-if="property?.children?.length">
                  &bull; {{ property.children.length }} Einheiten
                </template>
              </DialogDescription>
              <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                <span v-if="property?.purchase_price" class="text-base font-bold text-foreground tabular-nums">
                  {{ Number(property.purchase_price).toLocaleString("de-DE") }} EUR
                </span>
                <span v-if="property?.total_area" class="text-sm text-muted-foreground">{{ property.total_area }} m2</span>
                <span v-if="property?.rooms_amount" class="text-sm text-muted-foreground">{{ property.rooms_amount }} Zi.</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-1.5 flex-shrink-0">
            <Button variant="outline" size="sm" @click="$emit('openEditor', property?.id)">
              <Pencil class="w-3.5 h-3.5 mr-1.5" />
              Bearbeiten
            </Button>
            <Button v-if="!property?.on_hold" variant="outline" size="icon" class="h-8 w-8" @click.stop="$emit('toggleOnHold', property)" title="Pausieren">
              <Pause class="w-3.5 h-3.5 text-amber-600" />
            </Button>
            <Button v-else variant="outline" size="icon" class="h-8 w-8" @click.stop="$emit('toggleOnHold', property)" title="Aktivieren">
              <Play class="w-3.5 h-3.5 text-emerald-600" />
            </Button>
            <Button variant="outline" size="icon" class="h-8 w-8" @click.stop="$emit('deleteProperty', property)" title="Loeschen">
              <Trash2 class="w-3.5 h-3.5 text-red-500" />
            </Button>
            <Button variant="ghost" size="icon" class="h-8 w-8" @click="$emit('close')">
              <X class="w-4 h-4 text-muted-foreground" />
            </Button>
          </div>
        </div>
      </div>

      <!-- ─── Tabs ─── -->
      <Tabs v-model="activeTab" class="flex-1 min-h-0 flex flex-col">
        <TabsList class="w-full justify-start rounded-none border-b bg-transparent h-auto px-6 pt-2 pb-0 gap-0">
          <TabsTrigger value="objekt" class="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none px-4 pb-2.5 pt-1.5 text-[13px]">
            Objekt
          </TabsTrigger>
          <TabsTrigger value="aktivitaeten" class="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none px-4 pb-2.5 pt-1.5 text-[13px]">
            Aktivitaeten
          </TabsTrigger>
          <TabsTrigger value="kaufanbote" class="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none px-4 pb-2.5 pt-1.5 text-[13px]">
            Kaufanbote
          </TabsTrigger>
        </TabsList>

        <!-- ════════════════════════════════════════════════ -->
        <!-- TAB: OBJEKT                                     -->
        <!-- ════════════════════════════════════════════════ -->
        <TabsContent value="objekt" class="flex-1 min-h-0 mt-0 data-[state=inactive]:hidden">
          <ScrollArea class="h-full">
            <div class="px-6 py-5 space-y-4">

              <!-- KPIs (5 columns) -->
              <div class="grid grid-cols-5 gap-2.5 mb-4">
                <div class="p-3 bg-muted rounded-lg">
                  <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Kaufpreis</div>
                  <div class="text-base font-bold text-foreground tabular-nums">
                    {{ property?.purchase_price ? Number(property.purchase_price).toLocaleString('de-DE') + ' €' : '–' }}
                  </div>
                </div>
                <div class="p-3 bg-muted rounded-lg">
                  <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Rendite</div>
                  <div class="text-base font-bold text-foreground tabular-nums">
                    {{ property?.yield_percent ? property.yield_percent + ' %' : '–' }}
                  </div>
                </div>
                <div class="p-3 bg-muted rounded-lg">
                  <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Fläche</div>
                  <div class="text-base font-bold text-foreground tabular-nums">
                    {{ property?.total_area ? property.total_area + ' m²' : '–' }}
                  </div>
                </div>
                <div class="p-3 bg-muted rounded-lg">
                  <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Einheiten</div>
                  <div class="text-base font-bold text-foreground tabular-nums">
                    {{ property?.children?.length || unitStats?.total || '–' }}
                  </div>
                </div>
                <div class="p-3 bg-muted rounded-lg">
                  <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Provision</div>
                  <div class="text-base font-bold text-foreground tabular-nums">
                    {{ property?.commission_percent ? property.commission_percent + ' %' : '–' }}
                  </div>
                </div>
              </div>

              <!-- ══════ COLLAPSIBLE: Objektdaten ══════ -->
              <Collapsible v-model:open="openSections.objektdaten" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.objektdaten" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <Pencil class="w-3.5 h-3.5 text-foreground" />
                    <span class="text-[13px] font-semibold text-foreground">Objektdaten</span>
                  </div>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-3">
                    <p class="text-xs text-muted-foreground">Alle Felder bearbeiten, Daten verwalten.</p>
                    <div class="flex items-center gap-2">
                      <Button size="sm" class="h-8 text-xs" @click="$emit('openEditor', property.id)">
                        <Pencil class="w-3 h-3 mr-1.5" /> Objekt bearbeiten
                      </Button>
                      <Button size="sm" variant="outline" class="h-8 text-xs gap-1.5"
                        :disabled="exposeLoading && exposeMode === 'fields'"
                        @click="async () => { if (!exposeLoading) { await loadExposeFiles(); exposeFileSelect = true; exposeMode = 'fields'; } }">
                        <Sparkles class="w-3 h-3" />
                        <span v-if="exposeLoading && exposeMode === 'fields'">KI analysiert...</span>
                        <span v-else>Dateien auslesen</span>
                      </Button>
                    </div>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Eigentuemer & Portal ══════ -->
              <Collapsible v-if="!property?.parent_id" v-model:open="openSections.eigentuemerPortal" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.eigentuemerPortal" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <Key class="w-3.5 h-3.5" style="color:#D4622B" />
                    <span class="text-[13px] font-semibold text-foreground">Eigentuemer & Portal</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <Badge v-if="portalUser" class="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 text-[10px]">Aktiv</Badge>
                    <Badge v-else-if="!ownerData.customer_id" variant="outline" class="text-amber-600 border-amber-300 text-[10px]">!</Badge>
                  </div>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-2">
                    <div class="text-xs text-muted-foreground">
                      <span v-if="ownerData.owner_name">
                        {{ ownerData.owner_name }}
                        <span v-if="portalUser"> — Zugang aktiv</span>
                        <span v-else> — kein Zugang</span>
                      </span>
                      <span v-else>Eigentuemer zuweisen</span>
                    </div>
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="portalPopupOpen = true">
                      <Key class="w-3 h-3 mr-1.5" style="color:#D4622B" /> Verwalten
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Einheiten (newbuild only) ══════ -->
              <Collapsible v-if="property?.property_category === 'newbuild' && !property?.parent_id" v-model:open="openSections.einheiten" class="border border-orange-200 rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.einheiten" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <Building2 class="w-3.5 h-3.5 text-orange-600" />
                    <span class="text-[13px] font-semibold text-foreground">Einheiten</span>
                  </div>
                  <Badge v-if="unitStats" class="bg-orange-100 text-orange-700 hover:bg-orange-100 text-[10px]">{{ unitStats.total }}</Badge>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-3">
                    <!-- Filter row -->
                    <div class="flex items-center gap-2">
                      <select v-model="unitFilter" class="h-8 px-2 text-xs border border-border rounded-md bg-background focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="alle">Alle Status</option>
                        <option value="frei">Frei</option>
                        <option value="reserviert">Reserviert</option>
                        <option value="verkauft">Verkauft</option>
                      </select>
                      <div class="relative flex-1">
                        <Search class="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground" />
                        <Input v-model="unitSearch" placeholder="Top, Typ suchen..." class="h-8 pl-8 text-xs" />
                      </div>
                      <Button size="sm" class="h-8 text-xs" @click="$emit('openSettings', property.id)">
                        Verwalten
                      </Button>
                    </div>
                    <!-- Table -->
                    <ScrollArea class="max-h-[280px]">
                      <Table>
                        <TableHeader>
                          <TableRow>
                            <TableHead class="text-[11px] h-8">Top</TableHead>
                            <TableHead class="text-[11px] h-8">Typ</TableHead>
                            <TableHead class="text-[11px] h-8 text-right">Zimmer</TableHead>
                            <TableHead class="text-[11px] h-8 text-right">Flaeche</TableHead>
                            <TableHead class="text-[11px] h-8 text-right">Preis</TableHead>
                            <TableHead class="text-[11px] h-8 text-right">EUR/m2</TableHead>
                            <TableHead class="text-[11px] h-8">Status</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          <TableRow v-for="u in filteredUnits" :key="u.id" :class="unitRowClass(u.status)">
                            <TableCell class="text-xs py-1.5">{{ u.top_number || u.unit_number || '-' }}</TableCell>
                            <TableCell class="text-xs py-1.5">{{ u.unit_type || '-' }}</TableCell>
                            <TableCell class="text-xs py-1.5 text-right tabular-nums">{{ u.rooms_amount || '-' }}</TableCell>
                            <TableCell class="text-xs py-1.5 text-right tabular-nums">{{ u.area_m2 ? parseFloat(u.area_m2).toFixed(1) : '-' }}</TableCell>
                            <TableCell class="text-xs py-1.5 text-right tabular-nums">{{ u.price ? Number(u.price).toLocaleString('de-DE') : '-' }}</TableCell>
                            <TableCell class="text-xs py-1.5 text-right tabular-nums">{{ u.price && u.area_m2 ? Math.round(Number(u.price) / parseFloat(u.area_m2)).toLocaleString('de-DE') : '-' }}</TableCell>
                            <TableCell class="text-xs py-1.5">
                              <Badge :class="u.status === 'verkauft' ? 'bg-red-100 text-red-700 hover:bg-red-100' : u.status === 'reserviert' ? 'bg-amber-100 text-amber-700 hover:bg-amber-100' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-100'" class="text-[10px]">
                                {{ u.status || 'frei' }}
                              </Badge>
                            </TableCell>
                          </TableRow>
                          <TableRow v-if="!filteredUnits.length">
                            <TableCell colspan="7" class="text-center text-xs text-muted-foreground py-4">Keine Einheiten gefunden</TableCell>
                          </TableRow>
                        </TableBody>
                      </Table>
                    </ScrollArea>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Stellplaetze (newbuild only) ══════ -->
              <Collapsible v-if="property?.property_category === 'newbuild' && !property?.parent_id" v-model:open="openSections.stellplaetze" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.stellplaetze" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <ParkingSquare class="w-3.5 h-3.5 text-indigo-600" />
                    <span class="text-[13px] font-semibold text-foreground">Stellplaetze</span>
                  </div>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3">
                    <p class="text-xs text-muted-foreground mb-2">Parkplaetze & Garagen verwalten</p>
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="$emit('openSettings', property.id)">
                      <ParkingSquare class="w-3 h-3 mr-1.5" /> Stellplaetze oeffnen
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Wissens-DB ══════ -->
              <Collapsible v-if="!property?.parent_id" v-model:open="openSections.wissensdb" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.wissensdb" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <BookOpen class="w-3.5 h-3.5 text-emerald-600" />
                    <span class="text-[13px] font-semibold text-foreground">Wissens-DB</span>
                  </div>
                  <Badge v-if="kbCounts?.[property?.id]" class="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 text-[10px]">{{ kbCounts[property.id] }}</Badge>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-2">
                    <p class="text-xs text-muted-foreground">{{ (kbCounts?.[property?.id] || 0) }} Eintraege in der Wissens-Datenbank.</p>
                    <div class="flex items-center gap-2">
                      <Button size="sm" variant="outline" class="h-8 text-xs" @click="$emit('openKnowledge', property.id, property.address)">
                        <BookOpen class="w-3 h-3 mr-1.5" /> Wissens-DB oeffnen
                      </Button>
                      <Button size="sm" variant="outline" class="h-8 text-xs gap-1.5"
                        :disabled="exposeLoading && exposeMode === 'kb'"
                        @click="async () => { if (!exposeLoading) { await loadExposeFiles(); exposeFileSelect = true; exposeMode = 'kb'; } }">
                        <Sparkles class="w-3 h-3" />
                        <span v-if="exposeLoading && exposeMode === 'kb'">KI analysiert...</span>
                        <span v-else>Dateien auslesen</span>
                      </Button>
                    </div>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Dateien ══════ -->
              <Collapsible v-if="!property?.parent_id" v-model:open="openSections.dateien" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.dateien" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <FileText class="w-3.5 h-3.5 text-red-600" />
                    <span class="text-[13px] font-semibold text-foreground">Dateien</span>
                  </div>
                  <Badge v-if="property?.files_count" class="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 text-[10px]">{{ property.files_count }}</Badge>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-2">
                    <p class="text-xs text-muted-foreground">{{ property?.files_count || 0 }} Dokumente gespeichert.</p>
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="$emit('openFiles', property.id, property.address)">
                      <FileText class="w-3 h-3 mr-1.5" /> Dateien oeffnen
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Historie (non-newbuild) ══════ -->
              <Collapsible v-if="property?.property_category !== 'newbuild' && !property?.parent_id" v-model:open="openSections.historie" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.historie" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <Clock class="w-3.5 h-3.5" style="color:#D4743B" />
                    <span class="text-[13px] font-semibold text-foreground">Historie</span>
                  </div>
                  <Badge v-if="historyCount" class="text-[10px]" style="background:rgba(212,116,59,0.1);color:#D4743B">{{ historyCount }}</Badge>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-2">
                    <p class="text-xs text-muted-foreground">{{ historyCount ? historyCount + ' Eintraege' : 'Keine Eintraege' }}</p>
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="openHistory()">
                      <Clock class="w-3 h-3 mr-1.5" /> Historie oeffnen
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Unterobjekt anlegen ══════ -->
              <Collapsible v-if="!property?.parent_id" v-model:open="openSections.unterobjekt" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.unterobjekt" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <Plus class="w-3.5 h-3.5 text-indigo-600" />
                    <span class="text-[13px] font-semibold text-foreground">Unterobjekt anlegen</span>
                  </div>
                  <Badge v-if="property?.children?.length" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-100 text-[10px]">{{ property.children.length }} vorhanden</Badge>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3">
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="openChildCreateModal()">
                      <Plus class="w-3 h-3 mr-1.5" /> Unterobjekt erstellen
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Hierarchie ══════ -->
              <Collapsible v-model:open="openSections.hierarchie" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.hierarchie" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <Link2 class="w-3.5 h-3.5 text-indigo-500" />
                    <span class="text-[13px] font-semibold text-foreground">Hierarchie</span>
                  </div>
                  <Badge v-if="property?.parent_id" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-100 text-[10px]">Kind</Badge>
                  <Badge v-else-if="property?.children?.length" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-100 text-[10px]">{{ property.children.length }}</Badge>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3 space-y-2">
                    <p class="text-xs text-muted-foreground">
                      <span v-if="property?.parent_id">Ist Unterobjekt</span>
                      <span v-else-if="property?.children?.length">{{ property.children.length }} Unterobjekt{{ property.children.length > 1 ? 'e' : '' }}</span>
                      <span v-else>Zuordnung verwalten</span>
                    </p>
                    <div class="flex items-center gap-2">
                      <Button size="sm" variant="outline" class="h-8 text-xs" @click="() => { $emit('assignParent', property); $emit('close'); }">
                        <Link2 class="w-3 h-3 mr-1.5" /> Hierarchie verwalten
                      </Button>
                      <Button v-if="!property?.parent_id" size="sm" variant="outline" class="h-8 text-xs" @click="projectGroupPopup = true">
                        <FolderOpen class="w-3 h-3 mr-1.5" /> Projektgruppe
                      </Button>
                    </div>
                  </div>
                </CollapsibleContent>
              </Collapsible>

            </div>
          </ScrollArea>
        </TabsContent>

        <!-- ════════════════════════════════════════════════ -->
        <!-- TAB: AKTIVITAETEN                               -->
        <!-- ════════════════════════════════════════════════ -->
        <TabsContent value="aktivitaeten" class="flex-1 min-h-0 mt-0 data-[state=inactive]:hidden">
          <ScrollArea class="h-full">
            <div class="px-6 py-5 space-y-4">

              <!-- ══════ COLLAPSIBLE: Protokoll & Eintraege ══════ -->
              <Collapsible v-model:open="openSections.protokoll" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.protokoll" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <ClipboardList class="w-3.5 h-3.5 text-cyan-600" />
                    <span class="text-[13px] font-semibold text-foreground">Protokoll & Eintraege</span>
                  </div>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3">
                    <p class="text-xs text-muted-foreground mb-2">Aktivitaeten, Notizen und Protokoll verwalten.</p>
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="$emit('openActivities', property.id, property.address)">
                      <ClipboardList class="w-3 h-3 mr-1.5" /> Aktivitaeten oeffnen
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

              <!-- ══════ COLLAPSIBLE: Nachrichten ══════ -->
              <Collapsible v-model:open="openSections.nachrichten" class="border border-border rounded-lg">
                <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
                  <div class="flex items-center gap-2">
                    <ChevronDown v-if="!openSections.nachrichten" class="w-3 h-3 text-muted-foreground" />
                    <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
                    <MessageCircle class="w-3.5 h-3.5 text-blue-600" />
                    <span class="text-[13px] font-semibold text-foreground">Nachrichten</span>
                  </div>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div class="px-4 pb-3">
                    <p class="text-xs text-muted-foreground mb-2">Portal-Kommunikation und Nachrichten.</p>
                    <Button size="sm" variant="outline" class="h-8 text-xs" @click="$emit('openMessages', property.id, property.address)">
                      <MessageCircle class="w-3 h-3 mr-1.5" /> Nachrichten oeffnen
                    </Button>
                  </div>
                </CollapsibleContent>
              </Collapsible>

            </div>
          </ScrollArea>
        </TabsContent>

        <!-- ════════════════════════════════════════════════ -->
        <!-- TAB: KAUFANBOTE                                 -->
        <!-- ════════════════════════════════════════════════ -->
        <TabsContent value="kaufanbote" class="flex-1 min-h-0 mt-0 data-[state=inactive]:hidden">
          <ScrollArea class="h-full">
            <div class="px-6 py-5">
              <div class="text-center py-12 space-y-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto bg-pink-50">
                  <ShoppingCart class="w-7 h-7 text-pink-600" />
                </div>
                <div>
                  <h3 class="text-sm font-semibold text-foreground">Kaufanbote verwalten</h3>
                  <p class="text-xs text-muted-foreground mt-1">Angebote einsehen, bearbeiten und verwalten.</p>
                </div>
                <Button size="sm" class="h-9 text-xs" @click="$emit('openSettings', property.id)">
                  <ShoppingCart class="w-3.5 h-3.5 mr-1.5" /> Kaufanbote oeffnen
                </Button>
              </div>
            </div>
          </ScrollArea>
        </TabsContent>
      </Tabs>

    </DialogContent>
  </Dialog>

  <!-- ═══ SUB-MODALS (preserved from old code) ═══ -->

  <!-- ═══ EIGENTUEMER & PORTALZUGANG POP-UP ═══ -->
  <Teleport to="body">
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

              <!-- Eigentuemer Section -->
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

              <!-- Portalzugang Section -->
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
              <option value="">– Keine Gruppe –</option>
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
