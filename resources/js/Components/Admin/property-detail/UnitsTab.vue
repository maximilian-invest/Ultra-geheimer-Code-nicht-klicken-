<script setup>
import { ref, computed, inject, onMounted } from "vue";
import { Plus, Save, Search, ChevronDown, ChevronRight, Upload, Loader2 } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");

// ─── State ──────────────────────────────────────────────
const units = ref([]);
const unitsLoading = ref(false);
const unitSaving = ref({});
const unitSyncing = ref({});
const unitSearch = ref("");
const expandedUnit = ref(null);
const openGroups = ref({});

// ─── Floor colors ────────────────────────────────────────
const floorColors = [
  "#3b82f6",
  "#8b5cf6",
  "#22c55e",
  "#ea580c",
  "#f59e0b",
  "#06b6d4",
  "#ec4899",
];

// ─── Portal config ───────────────────────────────────────
const portalOptions = [
  { key: "immoji",      label: "immoji",      color: "#ea580c" },
  { key: "willhaben",   label: "willhaben",   color: "#dc2626" },
  { key: "immowelt",    label: "immowelt",    color: "#2563eb" },
  { key: "immoscout24", label: "ImmoScout24", color: "#16a34a" },
  { key: "alleskralle", label: "alleskralle", color: "#7c3aed" },
  { key: "dibon",       label: "dibon",       color: "#0891b2" },
];

const portalShorts = {
  immoji:      "IMJ",
  willhaben:   "WH",
  immowelt:    "IW",
  immoscout24: "IS",
  alleskralle: "AK",
  dibon:       "DB",
};

// ─── Computed ────────────────────────────────────────────
const realUnits = computed(() => units.value.filter((u) => !u.is_parking));

const freeCount     = computed(() => realUnits.value.filter((u) => u.status === "frei").length);
const reservedCount = computed(() => realUnits.value.filter((u) => u.status === "reserviert").length);
const soldCount     = computed(() => realUnits.value.filter((u) => u.status === "verkauft").length);

const floorGroups = computed(() => {
  const map = new Map();

  for (const unit of realUnits.value) {
    const floor = unit.floor;
    const key =
      floor === null || floor === undefined || floor === ""
        ? "__none__"
        : String(floor);
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(unit);
  }

  const sortedKeys = [...map.keys()].sort((a, b) => {
    if (a === "__none__") return 1;
    if (b === "__none__") return -1;
    return Number(a) - Number(b);
  });

  return sortedKeys.map((key) => ({
    floor: key,
    label: floorLabel(key === "__none__" ? null : Number(key)),
    units: map.get(key),
  }));
});

const filteredFloorGroups = computed(() => {
  const q = unitSearch.value.trim().toLowerCase();
  if (!q) return floorGroups.value;

  return floorGroups.value
    .map((group) => ({
      ...group,
      units: group.units.filter(
        (u) =>
          (u.unit_number || "").toLowerCase().includes(q) ||
          (u.unit_type || "").toLowerCase().includes(q)
      ),
    }))
    .filter((group) => group.units.length > 0);
});

// ─── Helpers ─────────────────────────────────────────────
function floorLabel(floor) {
  if (floor === null || floor === undefined || floor === "") return "Ohne Stockwerk";
  const f = Number(floor);
  if (f < 0) return "Untergeschoss";
  if (f === 0) return "Erdgeschoss";
  if (f === 1) return "1. Obergeschoss";
  if (f === 2) return "2. Obergeschoss";
  if (f === 3) return "3. Obergeschoss";
  return f + ". Obergeschoss";
}

function isGroupOpen(floor) {
  return openGroups.value[floor] !== false;
}

function toggleGroup(floor) {
  openGroups.value[floor] = !isGroupOpen(floor);
}

function unitKey(unit) {
  return unit.id || unit._tempId;
}

function toggleUnit(unit) {
  const k = unitKey(unit);
  expandedUnit.value = expandedUnit.value === k ? null : k;
}

function isExpanded(unit) {
  return expandedUnit.value === unitKey(unit);
}

function formatPrice(val) {
  if (!val) return "—";
  return Number(val).toLocaleString("de-AT", { minimumFractionDigits: 0 });
}

function isPortalActive(unit, key) {
  const exports = unit.portal_exports;
  if (!exports) return false;
  const parsed = typeof exports === "string" ? JSON.parse(exports) : exports;
  return !!parsed[key];
}

function togglePortal(unit, key) {
  let exports = unit.portal_exports;
  if (!exports || typeof exports === "string") {
    exports = exports ? JSON.parse(exports) : {};
  }
  exports[key] = !exports[key];
  // Replace the entire object to trigger Vue reactivity
  unit.portal_exports = { ...exports };
}

function activePortals(unit) {
  const exports = unit.portal_exports;
  if (!exports) return [];
  const parsed = typeof exports === "string" ? JSON.parse(exports) : exports;
  return portalOptions
    .filter((p) => parsed[p.key])
    .map((p) => ({ ...p, short: portalShorts[p.key] }));
}

// ─── API ─────────────────────────────────────────────────
async function loadUnits() {
  unitsLoading.value = true;
  try {
    const r = await fetch(
      API.value + "&action=get_units&property_id=" + props.property.id
    );
    const d = await r.json();
    if (d.units) units.value = d.units;
  } catch (e) {
    console.error("loadUnits error:", e);
  }
  unitsLoading.value = false;
}

async function saveUnit(unit) {
  const key = unitKey(unit);
  unitSaving.value[key] = true;
  try {
    const payload = {
      property_id: props.property.id,
      unit_id: unit.id || null,
      unit_number: unit.unit_number,
      unit_type: unit.unit_type,
      floor: unit.floor,
      area_m2: unit.area_m2,
      rooms_amount: unit.rooms || unit.rooms_amount,
      purchase_price: unit.price || unit.purchase_price,
      status: unit.status || "frei",
      portal_exports: unit.portal_exports,
      is_parking: 0,
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
      expandedUnit.value = null;
      toast("Einheit gespeichert");

      // Auto-sync to immoji if portal is enabled
      const exports = typeof unit.portal_exports === "string" ? JSON.parse(unit.portal_exports) : (unit.portal_exports || {});
      if (exports.immoji) {
        syncUnitToImmoji();
      }
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  unitSaving.value[key] = false;
}

async function syncUnitToImmoji() {
  try {
    const r = await fetch(API.value + "&action=immoji_push&property_id=" + props.property.id, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
    });
    const d = await r.json();
    if (d.success) {
      toast("Immoji synchronisiert");
      loadUnits();
    }
  } catch (e) {
    console.error("Immoji sync error:", e);
  }
}

// Quick export: enable immoji, save, sync — one click
async function quickExport(unit, event) {
  event.stopPropagation(); // don't toggle expand
  const key = unitKey(unit);
  unitSyncing.value[key] = true;

  // 1. Enable immoji
  let exports = unit.portal_exports;
  if (!exports || typeof exports === "string") {
    exports = exports ? JSON.parse(exports) : {};
  }
  exports.immoji = true;
  unit.portal_exports = { ...exports };

  // 2. Save unit
  try {
    const payload = {
      property_id: props.property.id,
      unit_id: unit.id || null,
      unit_number: unit.unit_number,
      unit_type: unit.unit_type,
      floor: unit.floor,
      area_m2: unit.area_m2,
      rooms_amount: unit.rooms || unit.rooms_amount,
      purchase_price: unit.price || unit.purchase_price,
      status: unit.status || "frei",
      portal_exports: unit.portal_exports,
      is_parking: 0,
    };
    const r = await fetch(API.value + "&action=save_property_unit", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
      if (d.unit?.id) unit.id = d.unit.id;
      // 3. Sync to immoji
      await syncUnitToImmoji();
      toast("Einheit auf immoji exportiert");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  unitSyncing.value[key] = false;
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
  const k = unitKey(unit);
  units.value.splice(units.value.indexOf(unit), 1);
  if (expandedUnit.value === k) expandedUnit.value = null;
  toast("Einheit entfernt");
}

let _tempIdCounter = 0;
function addUnitRow() {
  const maxNum = realUnits.value.reduce((max, u) => {
    const m = (u.unit_number || "").match(/(\d+)/);
    return m ? Math.max(max, parseInt(m[1])) : max;
  }, 0);
  const newUnit = {
    _isNew: true,
    _tempId: "new_" + ++_tempIdCounter,
    unit_number: "TOP " + (maxNum + 1),
    unit_type: "Wohnung",
    floor: 0,
    area_m2: null,
    rooms: null,
    price: null,
    status: "frei",
    portal_exports: {},
    is_parking: 0,
  };
  units.value.push(newUnit);
  expandedUnit.value = newUnit._tempId;
}

onMounted(() => {
  loadUnits();
});
</script>

<template>
  <div>
    <!-- Header bar -->
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <span class="text-[15px] font-bold tracking-tight">{{ realUnits.length }} Einheiten</span>
        <div class="flex gap-1.5">
          <span class="bg-green-100 text-green-800 px-2.5 py-0.5 rounded-full text-[11px] font-semibold">{{ freeCount }} frei</span>
          <span class="bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full text-[11px] font-semibold">{{ reservedCount }} reserviert</span>
          <span class="bg-red-100 text-red-800 px-2.5 py-0.5 rounded-full text-[11px] font-semibold">{{ soldCount }} verkauft</span>
        </div>
      </div>
      <div class="flex gap-2">
        <div class="relative">
          <Search class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            v-model="unitSearch"
            placeholder="Suchen..."
            class="h-9 pl-9 w-44 text-[13px] border border-input rounded-lg"
          />
        </div>
        <Button size="sm" variant="outline" @click="addUnitRow" class="h-9 text-[13px]">
          <Plus class="w-3.5 h-3.5 mr-1.5" /> Einheit
        </Button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="unitsLoading" class="text-center py-8 text-sm text-muted-foreground">
      Laden...
    </div>

    <!-- Floor groups -->
    <div v-else class="flex flex-col gap-3">
      <div
        v-for="(group, idx) in filteredFloorGroups"
        :key="group.floor"
        class="rounded-xl border border-border overflow-hidden"
        style="box-shadow: 0 1px 3px rgba(0,0,0,0.04)"
      >
        <!-- Floor accordion header -->
        <div
          class="w-full flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-zinc-50/50 transition-colors select-none"
          :class="isGroupOpen(group.floor) ? 'bg-gradient-to-b from-zinc-50 to-zinc-100/50 border-b border-border/50' : ''"
          @click="toggleGroup(group.floor)"
        >
          <div class="flex items-center gap-2">
            <div
              class="w-1.5 h-1.5 rounded-full flex-shrink-0"
              :style="{ background: floorColors[idx % floorColors.length] }"
            ></div>
            <span class="text-[13px] font-semibold text-foreground tracking-tight">
              {{ group.label }}
            </span>
            <span class="text-[12px] text-muted-foreground ml-0.5">
              ({{ group.units.length }})
            </span>
          </div>
          <component
            :is="isGroupOpen(group.floor) ? ChevronDown : ChevronRight"
            class="w-3.5 h-3.5 text-muted-foreground"
          />
        </div>

        <!-- Units list -->
        <div v-if="isGroupOpen(group.floor)" class="flex flex-col">
          <div
            v-for="(unit, uidx) in group.units"
            :key="unitKey(unit)"
            class="bg-zinc-50/60 transition-opacity"
            :class="[
              unit.status === 'verkauft' ? 'opacity-55' : '',
              uidx < group.units.length - 1 ? 'border-b border-zinc-200/60' : ''
            ]"
          >
            <!-- Collapsed summary row -->
            <div
              class="px-3 py-2 flex items-center cursor-pointer transition-colors hover:bg-gradient-to-r hover:from-orange-100/70 hover:to-transparent"
              @click="toggleUnit(unit)"
            >
              <div class="flex-1 flex items-center gap-4 min-w-0 overflow-hidden">
                <span class="text-[13px] font-semibold text-foreground min-w-[60px] truncate">
                  {{ unit.unit_number || "—" }}
                </span>
                <span class="text-[12px] text-zinc-600 bg-zinc-100 px-2.5 py-0.5 rounded font-medium whitespace-nowrap">
                  {{ unit.unit_type || "—" }}
                </span>
                <span class="text-[13px] text-foreground min-w-[70px] whitespace-nowrap hidden sm:inline">
                  {{ unit.area_m2 ? unit.area_m2 + " m²" : "—" }}
                </span>
                <span class="text-[13px] font-semibold text-foreground min-w-[110px] whitespace-nowrap hidden md:inline">
                  {{ (unit.price || unit.purchase_price) ? "EUR " + formatPrice(unit.price || unit.purchase_price) : "—" }}
                </span>
              </div>
              <div class="flex items-center gap-2.5 shrink-0">
                <!-- Status badge -->
                <span
                  class="px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wide whitespace-nowrap"
                  :class="{
                    'bg-green-100 text-green-800':   unit.status === 'frei',
                    'bg-amber-100 text-amber-800':   unit.status === 'reserviert',
                    'bg-red-100   text-red-800':     unit.status === 'verkauft',
                  }"
                >
                  {{
                    unit.status === 'frei'       ? 'FREI'       :
                    unit.status === 'reserviert' ? 'RESERVIERT' :
                    unit.status === 'verkauft'   ? 'VERKAUFT'   :
                    unit.status
                  }}
                </span>

                <!-- Portal dots -->
                <div class="flex gap-0.5">
                  <span
                    v-for="p in activePortals(unit)"
                    :key="p.key"
                    class="w-[22px] h-[22px] rounded-full text-white text-[8px] font-bold flex items-center justify-center"
                    :style="{ background: p.color }"
                    :title="p.label"
                  >{{ p.short }}</span>
                </div>

                <!-- Quick export button -->
                <button
                  v-if="unit.id && unit.status !== 'verkauft' && !isPortalActive(unit, 'immoji')"
                  @click="quickExport(unit, $event)"
                  :disabled="unitSyncing[unitKey(unit)]"
                  class="flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium bg-orange-500 text-white hover:bg-orange-600 transition-colors disabled:opacity-50"
                  title="Auf immoji exportieren"
                >
                  <Loader2 v-if="unitSyncing[unitKey(unit)]" class="w-3 h-3 animate-spin" />
                  <Upload v-else class="w-3 h-3" />
                  <span class="hidden lg:inline">Export</span>
                </button>

                <ChevronRight v-if="!isExpanded(unit)" class="w-3.5 h-3.5 text-zinc-500" />
                <ChevronDown  v-else                   class="w-3.5 h-3.5 text-zinc-800" />
              </div>
            </div>

            <!-- Expanded edit panel -->
            <div
              v-if="isExpanded(unit)"
              class="px-4 py-4 border-t border-border bg-background"
              @click.stop
            >
              <div class="grid grid-cols-3 max-sm:grid-cols-2 gap-4 gap-x-3.5 mb-4">
                <!-- Bezeichnung -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Bezeichnung</label>
                  <Input
                    v-model="unit.unit_number"
                    class="h-9 text-[13px] border border-input rounded-lg bg-background"
                    placeholder="z.B. TOP 1"
                  />
                </div>

                <!-- Typ -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Typ</label>
                  <Select v-model="unit.unit_type">
                    <SelectTrigger class="h-9 text-[13px] border border-input rounded-lg bg-background">
                      <SelectValue placeholder="Typ wählen" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="Wohnung">Wohnung</SelectItem>
                      <SelectItem value="Gewerbe">Gewerbe</SelectItem>
                      <SelectItem value="Büro">Büro</SelectItem>
                      <SelectItem value="Lager">Lager</SelectItem>
                      <SelectItem value="Sonstige">Sonstige</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <!-- Stockwerk -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Stockwerk</label>
                  <Input
                    v-model.number="unit.floor"
                    type="number"
                    class="h-9 text-[13px] border border-input rounded-lg bg-background"
                    placeholder="0 = EG"
                  />
                </div>

                <!-- Zimmer -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Zimmer</label>
                  <Input
                    v-model.number="unit.rooms"
                    type="number"
                    step="0.5"
                    class="h-9 text-[13px] border border-input rounded-lg bg-background"
                    placeholder="z.B. 3"
                  />
                </div>

                <!-- Wohnfläche -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Wohnfläche (m²)</label>
                  <Input
                    v-model.number="unit.area_m2"
                    type="number"
                    step="0.01"
                    class="h-9 text-[13px] border border-input rounded-lg bg-background"
                    placeholder="z.B. 75"
                  />
                </div>

                <!-- Kaufpreis -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Kaufpreis (EUR)</label>
                  <Input
                    v-model.number="unit.price"
                    type="number"
                    class="h-9 text-[13px] border border-input rounded-lg bg-background"
                    placeholder="z.B. 350000"
                  />
                </div>

                <!-- Status -->
                <div>
                  <label class="text-[12px] text-zinc-600 font-medium mb-1.5 block">Status</label>
                  <Select v-model="unit.status">
                    <SelectTrigger class="h-9 text-[13px] border border-input rounded-lg bg-background">
                      <SelectValue placeholder="Status wählen" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="frei">Frei</SelectItem>
                      <SelectItem value="reserviert">Reserviert</SelectItem>
                      <SelectItem value="verkauft">Verkauft</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <!-- Portal toggles -->
              <div class="mb-4">
                <label class="text-[12px] text-zinc-600 font-medium mb-2 block">Portale</label>
                <div class="flex gap-2 flex-wrap">
                  <button
                    v-for="p in portalOptions"
                    :key="p.key"
                    type="button"
                    @click="togglePortal(unit, p.key)"
                    class="px-3 py-1 rounded-lg text-[12px] font-medium transition-colors"
                    :class="
                      isPortalActive(unit, p.key)
                        ? 'bg-zinc-900 text-white'
                        : 'border border-border text-foreground hover:bg-zinc-50'
                    "
                  >{{ p.label }}</button>
                </div>
              </div>

              <!-- Actions row -->
              <div class="flex items-center justify-between">
                <button
                  type="button"
                  class="text-[12px] text-red-500 hover:text-red-700 transition-colors"
                  @click="deleteUnit(unit)"
                >
                  Löschen
                </button>
                <div class="flex gap-2">
                  <Button variant="outline" size="sm" @click="expandedUnit = null">
                    Abbrechen
                  </Button>
                  <Button
                    size="sm"
                    @click="saveUnit(unit)"
                    :disabled="unitSaving[unitKey(unit)]"
                  >
                    <Save class="w-3.5 h-3.5 mr-1.5" />
                    Speichern
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div
        v-if="filteredFloorGroups.length === 0"
        class="text-center py-12 text-sm text-muted-foreground"
      >
        <p class="font-medium mb-1">Keine Einheiten gefunden</p>
        <p v-if="unitSearch" class="text-[13px]">Suche nach „{{ unitSearch }}" ergab keine Treffer.</p>
        <p v-else class="text-[13px]">Klicke auf „+ Einheit" um die erste Einheit anzulegen.</p>
      </div>
    </div>
  </div>
</template>
