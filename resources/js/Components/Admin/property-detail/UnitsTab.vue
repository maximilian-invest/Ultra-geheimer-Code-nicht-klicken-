<script setup>
import { ref, computed, inject, reactive, onMounted } from "vue";
import { Plus, Check, Trash2, Save, ChevronDown, ChevronUp, Building2, ParkingSquare, Search, Sparkles, Upload, X } from "lucide-vue-next";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");

// ─── State ─────────────────────────────────────────────
const units = ref([]);
const unitsLoading = ref(false);
const unitSaving = ref({});
const unitFilter = ref("alle");
const unitSearch = ref("");
const unitGenOpen = ref(false);
const parkingGenOpen = ref(false);

const unitGen = reactive({ prefix: "TOP", from: 1, count: 5, floors: 3 });
const parkingGen = reactive({ prefix: "Stellplatz", from: 1, to: 10, price: null, type: "Tiefgarage" });

// ─── Parse Units ───
const unitParseOpen = ref(false);
const unitParseLoading = ref(false);
const unitParseFiles = ref([]);
const unitParseSelectedFiles = ref([]);
const unitParseUploading = ref(false);

// ─── Computed ───────────────────────────────────────────
const realUnits = computed(() => units.value.filter(u => !u.is_parking));
const parkingUnits = computed(() => units.value.filter(u => u.is_parking));

const freeCount = computed(() => realUnits.value.filter(u => u.status === "frei").length);
const reservedCount = computed(() => realUnits.value.filter(u => u.status === "reserviert").length);
const soldCount = computed(() => realUnits.value.filter(u => u.status === "verkauft").length);

const parkingFreeCount = computed(() => parkingUnits.value.filter(u => u.status === "frei").length);
const parkingReservedCount = computed(() => parkingUnits.value.filter(u => u.status === "reserviert").length);
const parkingSoldCount = computed(() => parkingUnits.value.filter(u => u.status === "verkauft").length);

const filteredUnits = computed(() => {
  let result = realUnits.value;
  if (unitFilter.value !== "alle") {
    result = result.filter(u => u.status === unitFilter.value);
  }
  if (unitSearch.value.trim()) {
    const q = unitSearch.value.trim().toLowerCase();
    result = result.filter(u =>
      (u.unit_number || "").toLowerCase().includes(q) ||
      (u.unit_type || "").toLowerCase().includes(q) ||
      (u.top_number || "").toLowerCase().includes(q)
    );
  }
  return result;
});

// ─── Helpers ────────────────────────────────────────────
const statusColor = (s) => {
  if (s === "verkauft") return "bg-red-100 text-red-700";
  if (s === "reserviert") return "bg-amber-100 text-muted-foreground";
  return "bg-emerald-100 text-emerald-700";
};

const statusBadgeStyle = (s) => {
  if (s === "verkauft") return "background:hsl(0 93% 97%);color:hsl(0 72% 51%);border:1px solid hsl(0 93% 90%)";
  if (s === "reserviert") return "background:hsl(33 100% 96%);color:hsl(21 90% 48%);border:1px solid hsl(33 100% 90%)";
  return "background:hsl(142 76% 96%);color:hsl(142 72% 29%);border:1px solid hsl(142 76% 85%)";
};

const floorLabel = (f) => {
  if (f === -1 || f === "-1") return "UG";
  if (f === 0 || f === "0") return "EG";
  return f + ". OG";
};

// ─── Load ───────────────────────────────────────────────
async function loadUnits() {
  unitsLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=get_units&property_id=" + props.property.id);
    const d = await r.json();
    if (d.units) units.value = d.units;
  } catch (e) {
    console.error("loadUnits error:", e);
  }
  unitsLoading.value = false;
}

// ─── Unit CRUD ──────────────────────────────────────────
async function saveUnit(unit) {
  const key = unit.id || unit.unit_number;
  unitSaving.value[key] = true;
  try {
    const payload = {
      property_id: props.property.id,
      id: unit.id || null,
      unit_number: unit.unit_number,
      unit_type: unit.unit_type,
      floor: unit.floor,
      area_m2: unit.area_m2,
      rooms_amount: unit.rooms || unit.rooms_amount,
      purchase_price: unit.price || unit.purchase_price,
      status: unit.status || "frei",
      balcony_terrace_m2: unit.balcony_terrace_m2,
      garden_m2: unit.garden_m2,
      parking: unit.parking,
      notes: unit.notes,
      assigned_parking: unit.assigned_parking,
      is_parking: unit.is_parking || 0,
    };
    const r = await fetch(API.value + "&action=save_property_unit", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
      if (d.unit?.id) unit.id = d.unit.id;
      unit._isNew = false;
      unit._dirty = false;
      toast("Einheit gespeichert");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  unitSaving.value[key] = false;
}

async function saveAllUnits() {
  const unsaved = realUnits.value.filter(u => u._isNew || u._dirty);
  for (const u of unsaved) {
    await saveUnit(u);
  }
  toast("Alle Einheiten gespeichert");
}

async function deleteUnit(unit) {
  if (unit.id) {
    if (!confirm("Einheit wirklich löschen?")) return;
    try {
      const r = await fetch(API.value + "&action=delete_property_unit", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ unit_id: unit.id }),
      });
      const d = await r.json();
      if (!d.success) {
        toast("Fehler beim Löschen: " + (d.error || ""));
        return;
      }
    } catch (e) {
      toast("Fehler: " + e.message);
      return;
    }
  }
  units.value.splice(units.value.indexOf(unit), 1);
  toast("Einheit entfernt");
}

function addUnitRow() {
  const maxNum = realUnits.value.reduce((max, u) => {
    const m = (u.unit_number || "").match(/(\d+)/);
    return m ? Math.max(max, parseInt(m[1])) : max;
  }, 0);
  units.value.push({
    _isNew: true,
    unit_number: "TOP " + (maxNum + 1),
    unit_type: "Wohnung",
    floor: 0,
    area_m2: null,
    rooms_amount: null,
    price: null,
    balcony_terrace_m2: null,
    garden_m2: null,
    status: "frei",
    notes: "",
    is_parking: 0,
  });
}

// ─── Parse Units ─────────────────────────────────────
async function loadUnitParseFiles() {
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + props.property.id);
    const d = await r.json();
    unitParseFiles.value = d.files || [];
    unitParseSelectedFiles.value = unitParseFiles.value
      .filter(f => /preis/i.test(f.filename) || /excel/i.test(f.label || '') || /\.xlsx?$/i.test(f.filename))
      .map(f => f.id);
  } catch (e) { unitParseFiles.value = []; }
}

async function uploadUnitParseFiles(event) {
  const files = event.target.files;
  if (!files || !files.length) return;
  unitParseUploading.value = true;
  for (const file of files) {
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('property_id', props.property.id);
      fd.append('label', file.name.replace(/\.[^.]+$/, ''));
      const r = await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success && d.file) {
        unitParseFiles.value.push(d.file);
        unitParseSelectedFiles.value.push(d.file.id);
      }
    } catch (e) { console.error(e); }
  }
  event.target.value = '';
  unitParseUploading.value = false;
  toast(files.length + ' Datei(en) hochgeladen');
}

async function runParseUnits() {
  unitParseLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=parse_units", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id, file_ids: unitParseSelectedFiles.value }),
    });
    const txt = await r.text();
    if (txt.startsWith("<!") || txt.startsWith("<html")) { toast("Session abgelaufen"); unitParseLoading.value = false; return; }
    const d = JSON.parse(txt);
    if (d.error) { toast(d.error, "error"); }
    else {
      const parts = [];
      if (d.units_created) parts.push(d.units_created + " erstellt");
      if (d.units_updated) parts.push(d.units_updated + " aktualisiert");
      if (d.units_skipped) parts.push(d.units_skipped + " uebersprungen (Kaufanbot)");
      if (d.parking_created) parts.push(d.parking_created + " Stellplaetze erstellt");
      if (d.parking_updated) parts.push(d.parking_updated + " Stellplaetze aktualisiert");
      toast(parts.join(", ") || "Keine Einheiten gefunden", parts.length ? "success" : "warning");
      unitParseOpen.value = false;
      await loadUnits();
    }
  } catch (e) { toast("Fehler: " + e.message, "error"); }
  unitParseLoading.value = false;
}

// ─── Unit Generator ─────────────────────────────────────
function generateUnitRows() {
  const newUnits = [];
  const unitsPerFloor = Math.ceil(unitGen.count / unitGen.floors);
  let num = unitGen.from;
  for (let f = 0; f < unitGen.floors && newUnits.length < unitGen.count; f++) {
    for (let u = 0; u < unitsPerFloor && newUnits.length < unitGen.count; u++) {
      newUnits.push({
        _isNew: true,
        unit_number: unitGen.prefix + " " + num,
        unit_type: "Wohnung",
        floor: f,
        area_m2: null,
        rooms_amount: null,
        price: null,
        balcony_terrace_m2: null,
        garden_m2: null,
        status: "frei",
        notes: "",
        is_parking: 0,
      });
      num++;
    }
  }
  units.value.push(...newUnits);
}

async function bulkImportUnits() {
  const newUnits = realUnits.value.filter(u => u._isNew);
  if (!newUnits.length) { toast("Keine neuen Einheiten zum Importieren"); return; }
  try {
    const r = await fetch(API.value + "&action=bulk_import_units", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        units: newUnits.map(u => ({
          unit_number: u.unit_number,
          unit_type: u.unit_type,
          floor: u.floor,
          area_m2: u.area_m2,
          rooms_amount: u.rooms_amount,
          purchase_price: u.price || u.purchase_price,
          status: u.status || "frei",
          balcony_terrace_m2: u.balcony_terrace_m2,
          garden_m2: u.garden_m2,
          is_parking: 0,
        })),
      }),
    });
    const d = await r.json();
    if (d.success) {
      toast((d.created || 0) + " Einheiten importiert, " + (d.updated || 0) + " aktualisiert");
      await loadUnits();
    } else {
      toast("Fehler: " + (d.error || ""));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

// ─── Parking CRUD ───────────────────────────────────────
async function saveParking(unit) {
  await saveUnit(unit);
}

async function deleteParking(unit) {
  await deleteUnit(unit);
}

function addParkingRow() {
  const maxNum = parkingUnits.value.reduce((max, u) => {
    const m = (u.unit_number || "").match(/(\d+)/);
    return m ? Math.max(max, parseInt(m[1])) : max;
  }, 0);
  units.value.push({
    _isNew: true,
    unit_number: "Stellplatz " + (maxNum + 1),
    unit_type: "Tiefgarage",
    floor: -1,
    price: null,
    status: "frei",
    is_parking: 1,
  });
}

// ─── Parking Generator ──────────────────────────────────
async function generateParkingRows() {
  if (parkingGen.to < parkingGen.from) { toast("Bis-Nr. muss größer als Von-Nr. sein"); return; }
  const newParking = [];
  for (let i = parkingGen.from; i <= parkingGen.to; i++) {
    newParking.push({
      _isNew: true,
      unit_number: parkingGen.prefix + " " + i,
      unit_type: parkingGen.type,
      floor: -1,
      price: parkingGen.price || null,
      status: "frei",
      is_parking: 1,
    });
  }
  units.value.push(...newParking);
}

async function bulkImportParking() {
  const newParking = parkingUnits.value.filter(u => u._isNew);
  if (!newParking.length) { toast("Keine neuen Stellplätze zum Importieren"); return; }
  try {
    const r = await fetch(API.value + "&action=bulk_import_units", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        units: newParking.map(u => ({
          unit_number: u.unit_number,
          unit_type: u.unit_type,
          floor: u.floor,
          area_m2: null,
          rooms_amount: null,
          purchase_price: u.price || null,
          status: u.status || "frei",
          is_parking: 1,
        })),
      }),
    });
    const d = await r.json();
    if (d.success) {
      toast((d.created || 0) + " Stellplätze importiert, " + (d.updated || 0) + " aktualisiert");
      await loadUnits();
    } else {
      toast("Fehler: " + (d.error || ""));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

// ─── Mount ──────────────────────────────────────────────
onMounted(() => {
  loadUnits();
});
</script>

<template>
  <div class="space-y-6">

    <!-- ═══════ EINHEITEN SECTION ═══════ -->
    <div>
      <!-- Header row -->
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-1.5">
            <Building2 class="w-4 h-4 text-muted-foreground" />
            <span class="text-[15px] font-semibold">Einheiten ({{ realUnits.length }})</span>
          </div>
          <!-- ToggleGroup filter pills -->
          <ToggleGroup type="single" v-model="unitFilter" class="bg-muted rounded-[calc(var(--radius)-2px)] p-0.5 h-auto gap-0">
            <ToggleGroupItem
              value="alle"
              class="px-2.5 py-1 text-[11px] h-auto rounded-[calc(var(--radius)-2px)] data-[state=on]:bg-background data-[state=on]:shadow-sm"
            >
              Alle {{ realUnits.length }}
            </ToggleGroupItem>
            <ToggleGroupItem
              value="frei"
              class="px-2.5 py-1 text-[11px] h-auto rounded-[calc(var(--radius)-2px)] data-[state=on]:bg-background data-[state=on]:shadow-sm"
            >
              Frei {{ freeCount }}
            </ToggleGroupItem>
            <ToggleGroupItem
              value="reserviert"
              class="px-2.5 py-1 text-[11px] h-auto rounded-[calc(var(--radius)-2px)] data-[state=on]:bg-background data-[state=on]:shadow-sm"
            >
              Reserv. {{ reservedCount }}
            </ToggleGroupItem>
            <ToggleGroupItem
              value="verkauft"
              class="px-2.5 py-1 text-[11px] h-auto rounded-[calc(var(--radius)-2px)] data-[state=on]:bg-background data-[state=on]:shadow-sm"
            >
              Verk. {{ soldCount }}
            </ToggleGroupItem>
          </ToggleGroup>
        </div>
        <div class="flex items-center gap-2">
          <!-- Search -->
          <div class="relative">
            <Search class="absolute left-2 top-1/2 -translate-y-1/2 w-3 h-3 text-muted-foreground" />
            <Input v-model="unitSearch" placeholder="Top, Typ suchen..." class="h-7 pl-7 text-[11px] w-40" />
          </div>
          <Button size="sm" variant="outline" class="h-7 text-[11px] gap-1" @click="unitParseOpen = !unitParseOpen; if (unitParseOpen && !unitParseFiles.length) loadUnitParseFiles()">
            <Sparkles class="w-3 h-3" />
            Einheiten auslesen
          </Button>
          <Button size="sm" variant="outline" class="h-7 text-[11px] gap-1" @click="unitGenOpen = !unitGenOpen">
            <Building2 class="w-3 h-3" />
            Generator
          </Button>
          <Button size="sm" variant="outline" class="h-7 text-[11px] gap-1" @click="addUnitRow">
            <Plus class="w-3 h-3" />
            Neue Einheit
          </Button>
        </div>
      </div>

      <!-- Einheiten auslesen Panel -->
      <div v-if="unitParseOpen" class="rounded-lg p-4 space-y-3 mb-3" style="border:1px solid hsl(240 5.9% 90%); background:hsl(240 4.8% 95.9% / 0.3)">
        <div class="flex items-center justify-between">
          <h3 class="text-[13px] font-semibold">Einheiten aus Dokumenten auslesen</h3>
          <button @click="unitParseOpen = false" class="text-muted-foreground hover:text-foreground">
            <X class="w-4 h-4" />
          </button>
        </div>
        <p class="text-[11px] text-muted-foreground">Existierende Einheiten werden per TOP-Nr. aktualisiert. Verkaufte Einheiten (via Kaufanbot) behalten ihren Status.</p>

        <label class="flex items-center gap-2 p-3 rounded-lg cursor-pointer hover:bg-muted/50" style="border:1px dashed hsl(240 5.9% 85%)">
          <Upload class="w-4 h-4 text-muted-foreground" />
          <span class="text-[11px] text-muted-foreground">{{ unitParseUploading ? 'Wird hochgeladen...' : 'Preisliste / Expose hochladen' }}</span>
          <input type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xlsx,.xls" class="sr-only" @change="uploadUnitParseFiles" :disabled="unitParseUploading" />
        </label>

        <div class="space-y-1 max-h-40 overflow-y-auto">
          <label v-for="f in unitParseFiles" :key="f.id" class="flex items-center gap-2 p-2 rounded hover:bg-muted/50 cursor-pointer">
            <input type="checkbox" :value="f.id" v-model="unitParseSelectedFiles" class="rounded border-border" />
            <span class="text-[11px] flex-1 truncate">{{ f.label || f.filename }}</span>
            <span class="text-[9px] text-muted-foreground uppercase">{{ f.filename?.split('.').pop() }}</span>
          </label>
        </div>
        <div v-if="!unitParseFiles.length" class="text-[11px] text-muted-foreground py-2">Noch keine Dateien. Bitte oben hochladen.</div>

        <Button size="sm" :disabled="!unitParseSelectedFiles.length || unitParseLoading" @click="runParseUnits">
          <Sparkles v-if="!unitParseLoading" class="w-3.5 h-3.5 mr-1.5" />
          <div v-else class="w-3.5 h-3.5 mr-1.5 border-2 border-current border-t-transparent rounded-full animate-spin" />
          {{ unitParseLoading ? 'Wird analysiert (Sonnet)...' : unitParseSelectedFiles.length + ' Datei(en) auslesen' }}
        </Button>
      </div>

      <!-- Units Table -->
      <div class="rounded-md border border-border/50 overflow-hidden">
        <div v-if="unitsLoading" class="py-8 text-center text-sm text-muted-foreground">
          Einheiten werden geladen...
        </div>
        <div v-else class="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow class="bg-muted/40">
                <TableHead class="text-[11px] h-8 font-medium">Einheit</TableHead>
                <TableHead class="text-[11px] h-8 font-medium">Typ</TableHead>
                <TableHead class="text-[11px] h-8 font-medium text-right">Fläche</TableHead>
                <TableHead class="text-[11px] h-8 font-medium text-right">Zimmer</TableHead>
                <TableHead class="text-[11px] h-8 font-medium text-center">OG</TableHead>
                <TableHead class="text-[11px] h-8 font-medium">Status</TableHead>
                <TableHead class="text-[11px] h-8 font-medium text-right">Preis</TableHead>
                <TableHead class="text-[11px] h-8 w-16"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow
                v-for="unit in filteredUnits"
                :key="unit.id || unit.unit_number"
                class="hover:bg-muted/20"
              >
                <!-- Einheit Nr. -->
                <TableCell class="py-1.5 px-2">
                  <input
                    v-model="unit.unit_number"
                    type="text"
                    class="w-20 px-2 py-1 bg-muted/50 border border-border rounded text-xs focus:outline-none focus:ring-1 focus:ring-ring"
                    @input="unit._dirty = true"
                  />
                </TableCell>
                <!-- Typ -->
                <TableCell class="py-1.5 px-2">
                  <select
                    v-model="unit.unit_type"
                    class="w-28 px-2 py-1 bg-muted/50 border border-border rounded text-xs focus:outline-none"
                    @change="unit._dirty = true"
                  >
                    <option>Wohnung</option>
                    <option>Reihenhaus</option>
                    <option>Doppelhaus</option>
                    <option>Penthouse</option>
                    <option>Maisonette</option>
                    <option>Geschäft</option>
                    <option>Büro</option>
                  </select>
                </TableCell>
                <!-- Fläche -->
                <TableCell class="py-1.5 px-2 text-right">
                  <input
                    v-model.number="unit.area_m2"
                    type="number"
                    step="0.1"
                    placeholder="m²"
                    class="w-16 px-2 py-1 bg-muted/50 border border-border rounded text-xs text-right focus:outline-none focus:ring-1 focus:ring-ring"
                    @input="unit._dirty = true"
                  />
                </TableCell>
                <!-- Zimmer -->
                <TableCell class="py-1.5 px-2 text-right">
                  <input
                    v-model.number="unit.rooms_amount"
                    type="number"
                    step="0.5"
                    placeholder="Zi."
                    class="w-14 px-2 py-1 bg-muted/50 border border-border rounded text-xs text-right focus:outline-none focus:ring-1 focus:ring-ring"
                    @input="unit._dirty = true"
                  />
                </TableCell>
                <!-- OG -->
                <TableCell class="py-1.5 px-2 text-center">
                  <select
                    v-model.number="unit.floor"
                    class="w-16 px-2 py-1 bg-muted/50 border border-border rounded text-xs focus:outline-none"
                    @change="unit._dirty = true"
                  >
                    <option :value="-1">UG</option>
                    <option :value="0">EG</option>
                    <option v-for="f in 10" :key="f" :value="f">{{ f }}. OG</option>
                  </select>
                </TableCell>
                <!-- Status -->
                <TableCell class="py-1.5 px-2">
                  <select
                    v-model="unit.status"
                    :class="['px-2 py-1 rounded text-xs font-medium border-0 focus:outline-none', statusColor(unit.status)]"
                    @change="unit._dirty = true"
                  >
                    <option value="frei">Frei</option>
                    <option value="reserviert">Reserviert</option>
                    <option value="verkauft">Verkauft</option>
                  </select>
                </TableCell>
                <!-- Preis -->
                <TableCell class="py-1.5 px-2 text-right">
                  <input
                    v-model.number="unit.price"
                    type="number"
                    step="100"
                    placeholder="€"
                    class="w-24 px-2 py-1 bg-muted/50 border border-border rounded text-xs text-right focus:outline-none focus:ring-1 focus:ring-ring"
                    @input="unit._dirty = true"
                  />
                </TableCell>
                <!-- Actions -->
                <TableCell class="py-1.5 px-2">
                  <div class="flex items-center gap-0.5 justify-end">
                    <button
                      @click="saveUnit(unit)"
                      :disabled="unitSaving[unit.id || unit.unit_number]"
                      class="p-1 text-emerald-600 hover:bg-emerald-50 rounded transition-colors disabled:opacity-50"
                      title="Speichern"
                    >
                      <Check class="w-3.5 h-3.5" />
                    </button>
                    <button
                      @click="deleteUnit(unit)"
                      class="p-1 text-red-500 hover:bg-red-50 rounded transition-colors"
                      title="Löschen"
                    >
                      <Trash2 class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </TableCell>
              </TableRow>
              <TableRow v-if="!filteredUnits.length && !unitsLoading">
                <TableCell colspan="8" class="text-center text-xs py-6 text-muted-foreground">
                  {{ realUnits.length === 0 ? 'Noch keine Einheiten. Nutze den Generator oder "+ Neue Einheit".' : 'Keine Einheiten für diesen Filter.' }}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </div>

      <!-- Unit Generator Dialog -->
      <Dialog :open="unitGenOpen" @update:open="unitGenOpen = $event">
        <DialogContent class="max-w-md">
          <DialogHeader>
            <DialogTitle class="text-sm">Einheiten generieren</DialogTitle>
          </DialogHeader>
          <div class="space-y-4 pt-2">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Prefix</label>
                <Input v-model="unitGen.prefix" class="h-8 text-[13px]" />
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Start-Nr.</label>
                <Input v-model.number="unitGen.from" type="number" min="1" class="h-8 text-[13px]" />
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Anzahl</label>
                <Input v-model.number="unitGen.count" type="number" min="1" class="h-8 text-[13px]" />
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Stockwerke</label>
                <Input v-model.number="unitGen.floors" type="number" min="1" class="h-8 text-[13px]" />
              </div>
            </div>
            <p class="text-[11px] text-muted-foreground">
              Generiert {{ unitGen.count }} Einheiten auf {{ unitGen.floors }} Stockwerke, ab "{{ unitGen.prefix }} {{ unitGen.from }}".
            </p>
            <div class="flex justify-end gap-2">
              <Button variant="outline" size="sm" @click="unitGenOpen = false">Abbrechen</Button>
              <Button size="sm" @click="generateUnitRows(); unitGenOpen = false">
                <Plus class="w-3.5 h-3.5 mr-1.5" /> Generieren
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>

    <!-- ═══════ STELLPLÄTZE SECTION ═══════ -->
    <div class="border-t border-border/50 mt-6 pt-4">
      <!-- Header row -->
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-1.5">
            <ParkingSquare class="w-4 h-4 text-indigo-600" />
            <span class="text-[15px] font-semibold">Stellplätze ({{ parkingUnits.length }})</span>
          </div>
          <!-- Status count badges -->
          <div class="flex items-center gap-1">
            <span
              v-if="parkingFreeCount > 0"
              class="text-[9px] font-medium px-1.5 py-0.5 rounded-full"
              style="background:hsl(142 76% 96%);color:hsl(142 72% 29%);border:1px solid hsl(142 76% 85%)"
            >
              {{ parkingFreeCount }} frei
            </span>
            <span
              v-if="parkingReservedCount > 0"
              class="text-[9px] font-medium px-1.5 py-0.5 rounded-full"
              style="background:hsl(33 100% 96%);color:hsl(21 90% 48%);border:1px solid hsl(33 100% 90%)"
            >
              {{ parkingReservedCount }} res.
            </span>
            <span
              v-if="parkingSoldCount > 0"
              class="text-[9px] font-medium px-1.5 py-0.5 rounded-full"
              style="background:hsl(0 93% 97%);color:hsl(0 72% 51%);border:1px solid hsl(0 93% 90%)"
            >
              {{ parkingSoldCount }} verk.
            </span>
          </div>
        </div>
        <Button size="sm" variant="outline" class="h-7 text-[11px] gap-1" @click="addParkingRow">
          <Plus class="w-3 h-3" />
          Stellplatz
        </Button>
      </div>

      <!-- Parking Table -->
      <div class="rounded-md border border-border/50 overflow-hidden">
        <div class="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow class="bg-muted/40">
                <TableHead class="text-[11px] h-8 font-medium">Nr.</TableHead>
                <TableHead class="text-[11px] h-8 font-medium">Typ</TableHead>
                <TableHead class="text-[11px] h-8 font-medium">Status</TableHead>
                <TableHead class="text-[11px] h-8 font-medium text-right">Preis</TableHead>
                <TableHead class="text-[11px] h-8 w-16"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow
                v-for="unit in parkingUnits"
                :key="unit.id || unit.unit_number"
                class="hover:bg-muted/20"
              >
                <!-- Nr. -->
                <TableCell class="py-1.5 px-2">
                  <input
                    v-model="unit.unit_number"
                    type="text"
                    class="w-32 px-2 py-1 bg-muted/50 border border-border rounded text-xs focus:outline-none focus:ring-1 focus:ring-ring"
                    @input="unit._dirty = true"
                  />
                </TableCell>
                <!-- Typ -->
                <TableCell class="py-1.5 px-2">
                  <select
                    v-model="unit.unit_type"
                    class="w-28 px-2 py-1 bg-muted/50 border border-border rounded text-xs focus:outline-none"
                    @change="unit._dirty = true"
                  >
                    <option>Tiefgarage</option>
                    <option>Freiplatz</option>
                    <option>Carport</option>
                    <option>Garage</option>
                    <option>Stellplatz</option>
                  </select>
                </TableCell>
                <!-- Status -->
                <TableCell class="py-1.5 px-2">
                  <select
                    v-model="unit.status"
                    :class="['px-2 py-1 rounded text-xs font-medium border-0 focus:outline-none', statusColor(unit.status)]"
                    @change="unit._dirty = true"
                  >
                    <option value="frei">Frei</option>
                    <option value="reserviert">Reserviert</option>
                    <option value="verkauft">Verkauft</option>
                  </select>
                </TableCell>
                <!-- Preis -->
                <TableCell class="py-1.5 px-2 text-right">
                  <input
                    v-model.number="unit.price"
                    type="number"
                    step="100"
                    placeholder="€"
                    class="w-24 px-2 py-1 bg-muted/50 border border-border rounded text-xs text-right focus:outline-none focus:ring-1 focus:ring-ring"
                    @input="unit._dirty = true"
                  />
                </TableCell>
                <!-- Actions -->
                <TableCell class="py-1.5 px-2">
                  <div class="flex items-center gap-0.5 justify-end">
                    <button
                      @click="saveParking(unit)"
                      :disabled="unitSaving[unit.id || unit.unit_number]"
                      class="p-1 text-emerald-600 hover:bg-emerald-50 rounded transition-colors disabled:opacity-50"
                      title="Speichern"
                    >
                      <Check class="w-3.5 h-3.5" />
                    </button>
                    <button
                      @click="deleteParking(unit)"
                      class="p-1 text-red-500 hover:bg-red-50 rounded transition-colors"
                      title="Löschen"
                    >
                      <Trash2 class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </TableCell>
              </TableRow>
              <TableRow v-if="!parkingUnits.length">
                <TableCell colspan="5" class="text-center text-xs py-6 text-muted-foreground">
                  Noch keine Stellplätze. Nutze den Generator oder "+ Stellplatz".
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </div>

      <!-- Parking Generator Dialog -->
      <Dialog :open="parkingGenOpen" @update:open="parkingGenOpen = $event">
        <DialogContent class="max-w-md">
          <DialogHeader>
            <DialogTitle class="text-sm">Stellplaetze generieren</DialogTitle>
          </DialogHeader>
          <div class="space-y-4 pt-2">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Prefix</label>
                <Input v-model="parkingGen.prefix" class="h-8 text-[13px]" />
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Typ</label>
                <Select v-model="parkingGen.type">
                  <SelectTrigger class="h-8 text-[13px]"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Tiefgarage">Tiefgarage</SelectItem>
                    <SelectItem value="Freiplatz">Freiplatz</SelectItem>
                    <SelectItem value="Carport">Carport</SelectItem>
                    <SelectItem value="Garage">Garage</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Von Nr.</label>
                <Input v-model.number="parkingGen.from" type="number" min="1" class="h-8 text-[13px]" />
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Bis Nr.</label>
                <Input v-model.number="parkingGen.to" type="number" min="1" class="h-8 text-[13px]" />
              </div>
              <div>
                <label class="text-[11px] text-muted-foreground mb-1 block">Preis/Stk.</label>
                <Input v-model.number="parkingGen.price" type="number" step="100" class="h-8 text-[13px]" />
              </div>
            </div>
            <p class="text-[11px] text-muted-foreground">
              Generiert Stellplaetze {{ parkingGen.prefix }} {{ parkingGen.from }} bis {{ parkingGen.prefix }} {{ parkingGen.to }} als {{ parkingGen.type }}.
            </p>
            <div class="flex justify-end gap-2">
              <Button variant="outline" size="sm" @click="parkingGenOpen = false">Abbrechen</Button>
              <Button size="sm" @click="generateParkingRows(); parkingGenOpen = false">
                <Plus class="w-3.5 h-3.5 mr-1.5" /> Generieren
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>

  </div>
</template>
