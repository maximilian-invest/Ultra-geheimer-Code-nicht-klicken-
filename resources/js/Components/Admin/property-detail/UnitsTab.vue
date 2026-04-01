<script setup>
import { ref, computed, inject, reactive, onMounted } from "vue";
import { Plus, Check, Trash2, Save, ChevronDown, ChevronUp, Building2, ParkingSquare, Search } from "lucide-vue-next";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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
  if (s === "reserviert") return "bg-amber-100 text-amber-700";
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
          <Button size="sm" variant="outline" class="h-7 text-[11px] gap-1" @click="addUnitRow">
            <Plus class="w-3 h-3" />
            Neue Einheit
          </Button>
        </div>
      </div>

      <!-- Units Table -->
      <div class="rounded-md border border-border overflow-hidden">
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
                    v-model.number="unit.purchase_price"
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

      <!-- Unit Generator (Collapsible) -->
      <Collapsible v-model:open="unitGenOpen" class="mt-3">
        <CollapsibleTrigger class="flex items-center gap-1.5 text-[11px] text-muted-foreground hover:text-foreground transition-colors group">
          <component :is="unitGenOpen ? ChevronUp : ChevronDown" class="w-3 h-3" />
          <span>Einheiten-Generator</span>
        </CollapsibleTrigger>
        <CollapsibleContent>
          <div class="mt-3 p-4 bg-violet-50 border border-violet-200 rounded-lg space-y-3">
            <h4 class="text-xs font-semibold text-violet-900">Einheiten generieren</h4>
            <div class="flex flex-wrap items-end gap-3">
              <div>
                <label class="block text-[10px] font-medium text-violet-700 mb-1">Prefix</label>
                <input v-model="unitGen.prefix" type="text" class="w-20 px-2.5 py-1.5 bg-white border border-violet-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-violet-400" />
              </div>
              <div>
                <label class="block text-[10px] font-medium text-violet-700 mb-1">Start-Nr.</label>
                <input v-model.number="unitGen.from" type="number" min="1" class="w-16 px-2.5 py-1.5 bg-white border border-violet-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-violet-400" />
              </div>
              <div>
                <label class="block text-[10px] font-medium text-violet-700 mb-1">Anzahl</label>
                <input v-model.number="unitGen.count" type="number" min="1" class="w-16 px-2.5 py-1.5 bg-white border border-violet-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-violet-400" />
              </div>
              <div>
                <label class="block text-[10px] font-medium text-violet-700 mb-1">Stockwerke</label>
                <input v-model.number="unitGen.floors" type="number" min="1" class="w-16 px-2.5 py-1.5 bg-white border border-violet-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-violet-400" />
              </div>
              <button
                @click="generateUnitRows"
                class="px-3 py-1.5 bg-violet-600 text-white text-xs font-medium rounded-lg hover:bg-violet-500 transition-colors active:scale-[0.97] flex items-center gap-1"
              >
                <Plus class="w-3 h-3" /> Generieren
              </button>
              <button
                v-if="realUnits.some(u => u._isNew)"
                @click="bulkImportUnits"
                class="px-3 py-1.5 bg-violet-900 text-white text-xs font-medium rounded-lg hover:bg-violet-800 transition-colors active:scale-[0.97] flex items-center gap-1"
              >
                <Save class="w-3 h-3" /> Alle speichern
              </button>
            </div>
            <p class="text-[10px] text-violet-600">
              Generiert {{ unitGen.count }} Einheiten verteilt auf {{ unitGen.floors }} Stockwerke, beginnend bei "{{ unitGen.prefix }} {{ unitGen.from }}".
            </p>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

    <!-- ═══════ STELLPLÄTZE SECTION ═══════ -->
    <div class="border-t border-border mt-6 pt-4">
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
      <div class="rounded-md border border-border overflow-hidden">
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

      <!-- Parking Generator (Collapsible) -->
      <Collapsible v-model:open="parkingGenOpen" class="mt-3">
        <CollapsibleTrigger class="flex items-center gap-1.5 text-[11px] text-muted-foreground hover:text-foreground transition-colors">
          <component :is="parkingGenOpen ? ChevronUp : ChevronDown" class="w-3 h-3" />
          <span>Stellplatz-Generator</span>
        </CollapsibleTrigger>
        <CollapsibleContent>
          <div class="mt-3 p-4 bg-amber-50 border border-amber-200 rounded-lg space-y-3">
            <h4 class="text-xs font-semibold text-amber-900">Stellplätze generieren</h4>
            <div class="flex flex-wrap items-end gap-3">
              <div>
                <label class="block text-[10px] font-medium text-amber-700 mb-1">Prefix</label>
                <input v-model="parkingGen.prefix" type="text" class="w-24 px-2.5 py-1.5 bg-white border border-amber-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-amber-400" />
              </div>
              <div>
                <label class="block text-[10px] font-medium text-amber-700 mb-1">Typ</label>
                <select v-model="parkingGen.type" class="w-28 px-2.5 py-1.5 bg-white border border-amber-200 rounded-lg text-xs focus:outline-none">
                  <option>Tiefgarage</option>
                  <option>Freiplatz</option>
                  <option>Carport</option>
                  <option>Garage</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] font-medium text-amber-700 mb-1">Von Nr.</label>
                <input v-model.number="parkingGen.from" type="number" min="1" class="w-16 px-2.5 py-1.5 bg-white border border-amber-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-amber-400" />
              </div>
              <div>
                <label class="block text-[10px] font-medium text-amber-700 mb-1">Bis Nr.</label>
                <input v-model.number="parkingGen.to" type="number" min="1" class="w-16 px-2.5 py-1.5 bg-white border border-amber-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-amber-400" />
              </div>
              <div>
                <label class="block text-[10px] font-medium text-amber-700 mb-1">Preis/Stk.</label>
                <input v-model.number="parkingGen.price" type="number" step="100" class="w-20 px-2.5 py-1.5 bg-white border border-amber-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-amber-400" />
              </div>
              <button
                @click="generateParkingRows"
                class="px-3 py-1.5 bg-amber-600 text-white text-xs font-medium rounded-lg hover:bg-amber-500 transition-colors active:scale-[0.97] flex items-center gap-1"
              >
                <Plus class="w-3 h-3" /> Generieren
              </button>
              <button
                v-if="parkingUnits.some(u => u._isNew)"
                @click="bulkImportParking"
                class="px-3 py-1.5 bg-amber-900 text-white text-xs font-medium rounded-lg hover:bg-amber-800 transition-colors active:scale-[0.97] flex items-center gap-1"
              >
                <Save class="w-3 h-3" /> Alle speichern
              </button>
            </div>
            <p class="text-[10px] text-amber-600">
              Generiert Stellplätze {{ parkingGen.prefix }} {{ parkingGen.from }} bis {{ parkingGen.prefix }} {{ parkingGen.to }} als {{ parkingGen.type }}.
            </p>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>

  </div>
</template>
