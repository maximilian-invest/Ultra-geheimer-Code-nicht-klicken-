<script setup>
import { ref, computed, onMounted, inject, nextTick } from "vue";
import { FileText, RefreshCw, Plus, Trash2, ExternalLink, Upload, ChevronDown, X, Check } from "lucide-vue-next";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");

// ─── State ───────────────────────────────────────────────
const kaufanbote = ref([]);
const loading = ref(false);
const saving = ref(false);
const showDialog = ref(false);

// All units for selection
const allUnits = ref([]);
const allParking = ref([]);

// Dialog form
const form = ref(resetForm());

// Dropdown open states
const unitDropdownOpen = ref(false);
const parkingDropdownOpen = ref(false);

// ─── Computed ────────────────────────────────────────────
const count = computed(() => kaufanbote.value.length);

const soldUnitIds = computed(() => {
  const ids = new Set();
  kaufanbote.value.forEach(ka => {
    (ka.unit_ids || []).forEach(id => ids.add(id));
  });
  return ids;
});

const soldParkingIds = computed(() => {
  const ids = new Set();
  kaufanbote.value.forEach(ka => {
    (ka.parking_ids || []).forEach(id => ids.add(id));
  });
  return ids;
});

const availableUnits = computed(() =>
  allUnits.value.map(u => ({
    ...u,
    sold: soldUnitIds.value.has(u.id),
    soldTo: soldUnitIds.value.has(u.id)
      ? kaufanbote.value.find(ka => (ka.unit_ids || []).includes(u.id))?.buyer_name
      : null,
  }))
);

const availableParking = computed(() =>
  allParking.value.map(p => ({
    ...p,
    sold: soldParkingIds.value.has(p.id),
    soldTo: soldParkingIds.value.has(p.id)
      ? kaufanbote.value.find(ka => (ka.parking_ids || []).includes(p.id))?.buyer_name
      : null,
  }))
);

const selectedUnitNames = computed(() =>
  form.value.unit_ids.map(id => allUnits.value.find(u => u.id === id)?.unit_number).filter(Boolean)
);

const selectedParkingNames = computed(() =>
  form.value.parking_ids.map(id => allParking.value.find(p => p.id === id)?.unit_number).filter(Boolean)
);

const calcTotal = computed(() => {
  let total = 0;
  for (const id of form.value.unit_ids) {
    const u = allUnits.value.find(u => u.id === id);
    if (u) total += parseFloat(u.price || 0);
  }
  for (const id of form.value.parking_ids) {
    const p = allParking.value.find(p => p.id === id);
    if (p) total += parseFloat(p.price || 0);
  }
  return total;
});

// ─── Helpers ─────────────────────────────────────────────
function formatPrice(v) {
  if (!v) return "—";
  const n = parseFloat(v);
  if (isNaN(n)) return v;
  return new Intl.NumberFormat("de-AT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(n);
}

function formatDate(d) {
  if (!d) return "—";
  try {
    return new Date(d).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" });
  } catch { return d; }
}

function pdfUrl(path) {
  if (!path) return "#";
  return "/storage/" + path;
}

function resetForm() {
  return {
    buyer_name: "",
    kaufanbot_date: new Date().toISOString().slice(0, 10),
    unit_ids: [],
    parking_ids: [],
    pdf: null,
    pdf_name: "",
  };
}

function toggleUnit(id) {
  const idx = form.value.unit_ids.indexOf(id);
  if (idx >= 0) form.value.unit_ids.splice(idx, 1);
  else form.value.unit_ids.push(id);
}

function removeUnit(id) {
  const idx = form.value.unit_ids.indexOf(id);
  if (idx >= 0) form.value.unit_ids.splice(idx, 1);
}

function toggleParking(id) {
  const idx = form.value.parking_ids.indexOf(id);
  if (idx >= 0) form.value.parking_ids.splice(idx, 1);
  else form.value.parking_ids.push(id);
}

function removeParking(id) {
  const idx = form.value.parking_ids.indexOf(id);
  if (idx >= 0) form.value.parking_ids.splice(idx, 1);
}

function onFileSelect(e) {
  const file = e.target.files?.[0];
  if (file) {
    form.value.pdf = file;
    form.value.pdf_name = file.name;
  }
}

function openDialog() {
  form.value = resetForm();
  unitDropdownOpen.value = false;
  parkingDropdownOpen.value = false;
  showDialog.value = true;
}

// ─── API ─────────────────────────────────────────────────
async function loadKaufanbote() {
  loading.value = true;
  try {
    const res = await fetch(API.value + "&action=list_property_kaufanbote&property_id=" + props.property.id);
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    kaufanbote.value = data.kaufanbote ?? [];
  } catch {
    kaufanbote.value = [];
  } finally {
    loading.value = false;
  }
}

async function loadUnits() {
  try {
    const res = await fetch(API.value + "&action=get_units&property_id=" + props.property.id);
    if (!res.ok) return;
    const data = await res.json();
    const units = data.units ?? [];
    allUnits.value = units.filter(u => !u.is_parking);
    allParking.value = units.filter(u => u.is_parking);
  } catch {
    allUnits.value = [];
    allParking.value = [];
  }
}

async function saveKaufanbot() {
  if (!form.value.buyer_name.trim()) {
    toast?.("Kaeufer-Name ist ein Pflichtfeld.", "error");
    return;
  }
  if (!form.value.pdf) {
    toast?.("Bitte ein PDF hochladen.", "error");
    return;
  }
  if (form.value.unit_ids.length === 0) {
    toast?.("Bitte mindestens eine Einheit zuordnen.", "error");
    return;
  }

  saving.value = true;
  try {
    const fd = new FormData();
    fd.append("property_id", props.property.id);
    fd.append("buyer_name", form.value.buyer_name.trim());
    fd.append("amount", calcTotal.value || "");
    fd.append("kaufanbot_date", form.value.kaufanbot_date || "");
    fd.append("unit_ids", JSON.stringify(form.value.unit_ids));
    fd.append("parking_ids", JSON.stringify(form.value.parking_ids));
    fd.append("pdf", form.value.pdf);

    const res = await fetch(API.value + "&action=upload_property_kaufanbot", { method: "POST", body: fd });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (data.success) {
      toast?.("Kaufanbot gespeichert. Einheiten als verkauft markiert.", "success");
      showDialog.value = false;
      await loadKaufanbote();
      await loadUnits();
    } else {
      toast?.(data.error ?? "Fehler beim Speichern.", "error");
    }
  } catch {
    toast?.("Kaufanbot konnte nicht gespeichert werden.", "error");
  } finally {
    saving.value = false;
  }
}

async function deleteKaufanbot(ka) {
  if (!confirm("Kaufanbot von \"" + ka.buyer_name + "\" wirklich entfernen? Die zugeordneten Einheiten werden wieder als frei markiert.")) return;
  try {
    const fd = new FormData();
    fd.append("kaufanbot_id", ka.id);
    const res = await fetch(API.value + "&action=delete_property_kaufanbot", { method: "POST", body: fd });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (data.success) {
      toast?.("Kaufanbot entfernt. Einheiten wieder frei.", "success");
      await loadKaufanbote();
      await loadUnits();
    } else {
      toast?.(data.error ?? "Fehler.", "error");
    }
  } catch {
    toast?.("Fehler beim Entfernen.", "error");
  }
}

// Close dropdowns on outside click
function onClickOutside(e) {
  if (!e.target.closest(".unit-dropdown-wrap")) unitDropdownOpen.value = false;
  if (!e.target.closest(".parking-dropdown-wrap")) parkingDropdownOpen.value = false;
}

onMounted(() => {
  loadKaufanbote();
  loadUnits();
  document.addEventListener("click", onClickOutside);
});
</script>

<template>
  <div class="space-y-5">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-[15px] font-semibold">
          Kaufanbote
          <span class="text-muted-foreground font-normal text-[13px] ml-1">({{ count }})</span>
        </h2>
        <p class="text-xs text-muted-foreground mt-0.5">Hochgeladene Kaufanbote mit zugeordneten Einheiten</p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" :disabled="loading" @click="loadKaufanbote(); loadUnits()">
          <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />
        </Button>
        <Button size="sm" @click="openDialog">
          <Plus class="w-3.5 h-3.5 mr-1.5" />
          Neues Kaufanbot
        </Button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-2">
      <div v-for="i in 3" :key="i" class="h-14 rounded-md bg-muted animate-pulse" />
    </div>

    <!-- Table -->
    <div v-else-if="count > 0" class="rounded-lg overflow-hidden" style="border:1px solid hsl(240 5.9% 90%)">
      <Table>
        <TableHeader>
          <TableRow style="background:hsl(240 4.8% 95.9% / 0.4)">
            <TableHead class="text-xs font-medium">Kaeufer / Einheiten</TableHead>
            <TableHead class="text-xs font-medium text-right w-32">Kaufpreis</TableHead>
            <TableHead class="text-xs font-medium w-28">Datum</TableHead>
            <TableHead class="text-xs font-medium w-24">PDF</TableHead>
            <TableHead class="text-xs font-medium w-12"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="ka in kaufanbote" :key="ka.id" class="hover:bg-muted/30">
            <TableCell>
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:hsl(142 76% 95%)">
                  <FileText class="w-[18px] h-[18px]" style="color:hsl(142 72% 40%)" />
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="text-[13px] font-semibold">{{ ka.buyer_name }}</span>
                    <Badge class="text-[11px] border" style="background:hsl(142 76% 95%); color:hsl(142 72% 29%); border-color:hsl(142 76% 85%)">verkauft</Badge>
                  </div>
                  <div class="text-[12px] text-muted-foreground mt-0.5">
                    Einheiten: <span class="font-medium text-foreground">{{ (ka.unit_names || []).join(', ') || '—' }}</span>
                    <template v-if="(ka.parking_names || []).length > 0">
                      &middot; Stellplaetze: <span class="font-medium text-foreground">{{ ka.parking_names.join(', ') }}</span>
                    </template>
                  </div>
                </div>
              </div>
            </TableCell>
            <TableCell class="text-[13px] font-semibold text-right">{{ formatPrice(ka.amount) }}</TableCell>
            <TableCell class="text-[13px] text-muted-foreground">{{ formatDate(ka.kaufanbot_date) }}</TableCell>
            <TableCell>
              <a
                :href="pdfUrl(ka.pdf_path)"
                target="_blank"
                class="inline-flex items-center gap-1.5 text-[12px] hover:underline"
                style="color:hsl(217 91% 60%)"
              >
                <ExternalLink class="w-3.5 h-3.5" />
                PDF
              </a>
            </TableCell>
            <TableCell>
              <button
                class="p-1.5 rounded-md transition-colors text-muted-foreground hover:text-red-600"
                style="hover:background:hsl(0 84% 95%)"
                title="Kaufanbot entfernen"
                @click="deleteKaufanbot(ka)"
              >
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- Empty -->
    <div v-else class="text-center py-14 space-y-3">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center mx-auto" style="background:hsl(142 76% 95%)">
        <FileText class="w-6 h-6" style="color:hsl(142 72% 40%)" />
      </div>
      <div>
        <p class="text-sm font-medium" style="color:hsl(240 10% 3.9%)">Noch keine Kaufanbote</p>
        <p class="text-xs text-muted-foreground mt-1">Neues Kaufanbot ueber den Button oben hochladen.</p>
      </div>
    </div>

    <!-- ═══ DIALOG ═══ -->
    <Dialog :open="showDialog" @update:open="showDialog = $event">
      <DialogContent class="max-w-lg">
        <DialogHeader>
          <DialogTitle class="text-[15px]">Neues Kaufanbot</DialogTitle>
          <DialogDescription class="text-xs">PDF hochladen und Einheiten / Stellplaetze zuordnen</DialogDescription>
        </DialogHeader>

        <div class="space-y-5 pt-2">
          <!-- PDF Upload -->
          <div>
            <label class="text-xs text-muted-foreground mb-1.5 block">Kaufanbot PDF *</label>
            <label
              class="flex flex-col items-center justify-center rounded-lg cursor-pointer transition-colors py-6"
              :style="form.pdf
                ? 'border:2px solid hsl(142 72% 40%); background:hsl(142 76% 95% / 0.3)'
                : 'border:2px dashed hsl(240 5.9% 90%); background:hsl(240 4.8% 95.9% / 0.3)'"
              @dragover.prevent
              @drop.prevent="form.pdf = $event.dataTransfer.files[0]; form.pdf_name = form.pdf.name"
            >
              <Upload class="w-5 h-5 text-muted-foreground mb-1.5" />
              <span v-if="form.pdf" class="text-[13px] font-medium text-foreground">{{ form.pdf_name }}</span>
              <span v-else class="text-[13px] text-muted-foreground">PDF hierher ziehen oder <span style="color:hsl(217 91% 60%)">auswaehlen</span></span>
              <input type="file" accept=".pdf" class="hidden" @change="onFileSelect" />
            </label>
          </div>

          <!-- Buyer -->
          <div>
            <label class="text-xs text-muted-foreground mb-1.5 block">Kaeufer (Name) *</label>
            <Input v-model="form.buyer_name" placeholder="z.B. Familie Mustermann" class="h-9 text-sm" />
          </div>

          <!-- Date -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-muted-foreground mb-1.5 block">Datum</label>
              <Input v-model="form.kaufanbot_date" type="date" class="h-9 text-sm" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground mb-1.5 block">Kaufpreis (berechnet)</label>
              <div class="h-9 px-3 flex items-center text-sm font-semibold rounded-md" style="background:hsl(240 4.8% 95.9%); border:1px solid hsl(240 5.9% 90%)">
                {{ calcTotal > 0 ? formatPrice(calcTotal) : '—' }}
              </div>
            </div>
          </div>

          <div style="height:1px; background:hsl(240 5.9% 90%);"></div>

          <!-- Units Multi-Select -->
          <div class="unit-dropdown-wrap relative">
            <label class="text-xs text-muted-foreground mb-1.5 block">Einheiten zuordnen *</label>
            <div
              class="flex items-center flex-wrap gap-1 min-h-[36px] px-3 py-1.5 rounded-md cursor-pointer text-sm"
              style="border:1px solid hsl(240 5.9% 90%)"
              @click.stop="unitDropdownOpen = !unitDropdownOpen; parkingDropdownOpen = false"
            >
              <span
                v-for="id in form.unit_ids" :key="id"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium"
                style="background:hsl(240 4.8% 95.9%); border:1px solid hsl(240 5.9% 90%)"
              >
                {{ allUnits.find(u => u.id === id)?.unit_number }}
                <button class="hover:text-red-600" @click.stop="removeUnit(id)"><X class="w-3 h-3" /></button>
              </span>
              <span v-if="form.unit_ids.length === 0" class="text-muted-foreground text-[13px]">Einheiten auswaehlen...</span>
              <ChevronDown class="w-4 h-4 text-muted-foreground ml-auto flex-shrink-0" />
            </div>
            <!-- Dropdown -->
            <div
              v-if="unitDropdownOpen"
              class="absolute z-50 left-0 right-0 mt-1 rounded-lg overflow-hidden max-h-[240px] overflow-y-auto"
              style="border:1px solid hsl(240 5.9% 90%); background:white; box-shadow:0 8px 30px hsl(0 0% 0% / 0.12)"
            >
              <template v-for="unit in availableUnits" :key="unit.id">
                <div
                  v-if="!unit.sold"
                  class="flex items-center gap-2.5 px-3 py-2 cursor-pointer transition-colors hover:bg-muted/60"
                  :style="form.unit_ids.includes(unit.id) ? 'background:hsl(142 76% 95% / 0.5)' : ''"
                  @click.stop="toggleUnit(unit.id)"
                >
                  <div
                    class="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                    :style="form.unit_ids.includes(unit.id)
                      ? 'background:hsl(240 5.9% 10%); border:1px solid hsl(240 5.9% 10%)'
                      : 'border:1px solid hsl(240 5.9% 90%); background:white'"
                  >
                    <Check v-if="form.unit_ids.includes(unit.id)" class="w-3 h-3 text-white" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-medium">{{ unit.unit_number }}</div>
                    <div class="text-[11px] text-muted-foreground">{{ unit.floor != null ? unit.floor + '. OG' : '' }} · {{ unit.area_m2 }} m² · {{ unit.rooms }} Zi.</div>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <div class="text-[12px] font-semibold">{{ formatPrice(unit.price) }}</div>
                  </div>
                </div>
              </template>
              <!-- Sold separator -->
              <template v-if="availableUnits.some(u => u.sold)">
                <div style="height:1px; background:hsl(240 5.9% 90%); margin:4px 0;"></div>
                <div class="px-3 py-1">
                  <span class="text-[10px] text-muted-foreground uppercase tracking-wider">Bereits verkauft</span>
                </div>
                <div
                  v-for="unit in availableUnits.filter(u => u.sold)" :key="'sold-'+unit.id"
                  class="flex items-center gap-2.5 px-3 py-2 opacity-45"
                >
                  <div class="w-4 h-4 rounded flex-shrink-0" style="border:1px solid hsl(240 5.9% 90%); background:hsl(240 4.8% 95.9%)"></div>
                  <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-medium line-through">{{ unit.unit_number }}</div>
                    <div class="text-[11px] text-muted-foreground">KA: {{ unit.soldTo }}</div>
                  </div>
                  <div class="text-[11px] text-muted-foreground">&#x1f512; verkauft</div>
                </div>
              </template>
            </div>
          </div>

          <!-- Parking Multi-Select -->
          <div class="parking-dropdown-wrap relative">
            <label class="text-xs text-muted-foreground mb-1.5 block">Stellplaetze zuordnen</label>
            <div
              class="flex items-center flex-wrap gap-1 min-h-[36px] px-3 py-1.5 rounded-md cursor-pointer text-sm"
              style="border:1px solid hsl(240 5.9% 90%)"
              @click.stop="parkingDropdownOpen = !parkingDropdownOpen; unitDropdownOpen = false"
            >
              <span
                v-for="id in form.parking_ids" :key="id"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium"
                style="background:hsl(240 4.8% 95.9%); border:1px solid hsl(240 5.9% 90%)"
              >
                {{ allParking.find(p => p.id === id)?.unit_number }}
                <button class="hover:text-red-600" @click.stop="removeParking(id)"><X class="w-3 h-3" /></button>
              </span>
              <span v-if="form.parking_ids.length === 0" class="text-muted-foreground text-[13px]">Stellplaetze auswaehlen...</span>
              <ChevronDown class="w-4 h-4 text-muted-foreground ml-auto flex-shrink-0" />
            </div>
            <!-- Dropdown -->
            <div
              v-if="parkingDropdownOpen"
              class="absolute z-50 left-0 right-0 mt-1 rounded-lg overflow-hidden max-h-[200px] overflow-y-auto"
              style="border:1px solid hsl(240 5.9% 90%); background:white; box-shadow:0 8px 30px hsl(0 0% 0% / 0.12)"
            >
              <template v-for="p in availableParking" :key="p.id">
                <div
                  v-if="!p.sold"
                  class="flex items-center gap-2.5 px-3 py-2 cursor-pointer transition-colors hover:bg-muted/60"
                  :style="form.parking_ids.includes(p.id) ? 'background:hsl(142 76% 95% / 0.5)' : ''"
                  @click.stop="toggleParking(p.id)"
                >
                  <div
                    class="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                    :style="form.parking_ids.includes(p.id)
                      ? 'background:hsl(240 5.9% 10%); border:1px solid hsl(240 5.9% 10%)'
                      : 'border:1px solid hsl(240 5.9% 90%); background:white'"
                  >
                    <Check v-if="form.parking_ids.includes(p.id)" class="w-3 h-3 text-white" />
                  </div>
                  <div class="flex-1">
                    <div class="text-[13px] font-medium">{{ p.unit_number }}</div>
                  </div>
                  <div class="text-[12px] font-semibold">{{ formatPrice(p.price) }}</div>
                </div>
              </template>
              <template v-if="availableParking.some(p => p.sold)">
                <div style="height:1px; background:hsl(240 5.9% 90%); margin:4px 0;"></div>
                <div class="px-3 py-1">
                  <span class="text-[10px] text-muted-foreground uppercase tracking-wider">Bereits verkauft</span>
                </div>
                <div
                  v-for="p in availableParking.filter(pp => pp.sold)" :key="'sold-p-'+p.id"
                  class="flex items-center gap-2.5 px-3 py-2 opacity-45"
                >
                  <div class="w-4 h-4 rounded flex-shrink-0" style="border:1px solid hsl(240 5.9% 90%); background:hsl(240 4.8% 95.9%)"></div>
                  <div class="flex-1">
                    <div class="text-[13px] font-medium line-through">{{ p.unit_number }}</div>
                    <div class="text-[11px] text-muted-foreground">KA: {{ p.soldTo }}</div>
                  </div>
                  <div class="text-[11px] text-muted-foreground">&#x1f512;</div>
                </div>
              </template>
            </div>
          </div>

          <!-- Warning -->
          <div
            class="flex items-center gap-2 rounded-lg px-3.5 py-2.5 text-[12px]"
            style="border:1px solid hsl(48 96% 80%); background:hsl(48 96% 94%); color:hsl(32 95% 35%)"
          >
            <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Ausgewaehlte Einheiten + Stellplaetze werden automatisch als <strong>&nbsp;"verkauft"&nbsp;</strong> markiert.
          </div>

          <!-- Buttons -->
          <div class="flex justify-end gap-2 pt-1">
            <Button variant="outline" size="sm" @click="showDialog = false">Abbrechen</Button>
            <Button size="sm" :disabled="saving" @click="saveKaufanbot">
              {{ saving ? "Speichern ..." : "Kaufanbot speichern" }}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>
