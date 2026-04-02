<script setup>
import { ref, computed, onMounted, inject } from "vue";
import { ClipboardList, Plus, RefreshCw, Search, ChevronDown, X, Calendar, User, Tag } from "lucide-vue-next";
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
const activities = ref([]);
const loading = ref(false);
const saving = ref(false);
const showDialog = ref(false);
const searchQuery = ref("");
const filterCategory = ref("");

const form = ref(freshForm());

function freshForm() {
  return {
    stakeholder: "",
    activity: "",
    result: "",
    category: "sonstiges",
    activity_date: new Date().toISOString().slice(0, 10),
  };
}

// ─── Categories ──────────────────────────────────────────
const categories = [
  { value: "anfrage", label: "Anfrage", color: "hsl(217 91% 60%)", bg: "hsl(217 91% 95%)" },
  { value: "besichtigung", label: "Besichtigung", color: "hsl(280 67% 50%)", bg: "hsl(280 67% 95%)" },
  { value: "expose", label: "Expose", color: "hsl(142 72% 40%)", bg: "hsl(142 76% 95%)" },
  { value: "kaufanbot", label: "Kaufanbot", color: "hsl(48 96% 40%)", bg: "hsl(48 96% 94%)" },
  { value: "nachfassen", label: "Nachfassen", color: "hsl(25 95% 50%)", bg: "hsl(25 95% 95%)" },
  { value: "email-in", label: "E-Mail rein", color: "hsl(200 80% 50%)", bg: "hsl(200 80% 95%)" },
  { value: "email-out", label: "E-Mail raus", color: "hsl(200 60% 45%)", bg: "hsl(200 60% 93%)" },
  { value: "absage", label: "Absage", color: "hsl(0 84% 60%)", bg: "hsl(0 84% 95%)" },
  { value: "eigentuemer", label: "Eigentuemer", color: "hsl(330 80% 50%)", bg: "hsl(330 80% 95%)" },
  { value: "sonstiges", label: "Sonstiges", color: "hsl(240 3.8% 46%)", bg: "hsl(240 4.8% 95.9%)" },
  { value: "update", label: "Update", color: "hsl(240 3.8% 46%)", bg: "hsl(240 4.8% 95.9%)" },
  { value: "partner", label: "Partner", color: "hsl(160 60% 45%)", bg: "hsl(160 60% 95%)" },
  { value: "bounce", label: "Bounce", color: "hsl(0 50% 55%)", bg: "hsl(0 50% 95%)" },
  { value: "intern", label: "Intern", color: "hsl(240 20% 50%)", bg: "hsl(240 20% 95%)" },
];

function catInfo(val) {
  return categories.find(c => c.value === val) || { label: val || "—", color: "hsl(240 3.8% 46%)", bg: "hsl(240 4.8% 95.9%)" };
}

// ─── Computed ────────────────────────────────────────────
const filtered = computed(() => {
  let list = activities.value;
  if (filterCategory.value) list = list.filter(a => a.category === filterCategory.value);
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(a =>
      (a.stakeholder || "").toLowerCase().includes(q) ||
      (a.activity || "").toLowerCase().includes(q) ||
      (a.result || "").toLowerCase().includes(q)
    );
  }
  return list;
});

const activeCats = computed(() => {
  const counts = {};
  activities.value.forEach(a => {
    const cat = a.category || "sonstiges";
    counts[cat] = (counts[cat] || 0) + 1;
  });
  return Object.entries(counts)
    .map(([k, v]) => ({ ...catInfo(k), value: k, count: v }))
    .sort((a, b) => b.count - a.count);
});

function formatDate(d) {
  if (!d) return "—";
  try {
    return new Date(d).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" });
  } catch { return d; }
}

// ─── API ─────────────────────────────────────────────────
async function loadActivities() {
  loading.value = true;
  try {
    const res = await fetch(API.value + "&action=list_activities&property_id=" + props.property.id);
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    activities.value = data.activities ?? [];
  } catch {
    activities.value = [];
  } finally {
    loading.value = false;
  }
}

async function saveActivity() {
  if (!form.value.stakeholder.trim() || !form.value.activity.trim()) {
    toast?.("Kontakt und Aktivitaet sind Pflichtfelder.", "error");
    return;
  }
  saving.value = true;
  try {
    const fd = new FormData();
    fd.append("property_id", props.property.id);
    fd.append("stakeholder", form.value.stakeholder.trim());
    fd.append("activity", form.value.activity.trim());
    fd.append("result", form.value.result.trim());
    fd.append("category", form.value.category);
    fd.append("activity_date", form.value.activity_date);
    const res = await fetch(API.value + "&action=add_activity", { method: "POST", body: fd });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (data.success || data.id) {
      toast?.("Aktivitaet gespeichert.", "success");
      showDialog.value = false;
      form.value = freshForm();
      await loadActivities();
    } else {
      toast?.(data.error ?? "Fehler beim Speichern.", "error");
    }
  } catch {
    toast?.("Aktivitaet konnte nicht gespeichert werden.", "error");
  } finally {
    saving.value = false;
  }
}

function openDialog() {
  form.value = freshForm();
  showDialog.value = true;
}

onMounted(loadActivities);
</script>

<template>
  <div class="space-y-5">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-[15px] font-semibold">
          Aktivitaeten
          <span class="text-muted-foreground font-normal text-[13px] ml-1">({{ activities.length }})</span>
        </h2>
        <p class="text-xs text-muted-foreground mt-0.5">Protokoll aller Aktivitaeten fuer dieses Objekt</p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" :disabled="loading" @click="loadActivities">
          <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />
        </Button>
        <Button size="sm" @click="openDialog">
          <Plus class="w-3.5 h-3.5 mr-1.5" />
          Neue Aktivitaet
        </Button>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex items-center gap-3">
      <div class="relative flex-1 max-w-xs">
        <Search class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="searchQuery" placeholder="Suchen..." class="h-8 text-sm pl-9" />
      </div>
      <select
        v-model="filterCategory"
        class="h-8 px-3 text-[12px] rounded-md outline-none appearance-none cursor-pointer"
        style="border:1px solid hsl(240 5.9% 90%); background:transparent; min-width:160px"
      >
        <option value="">Alle Kategorien ({{ activities.length }})</option>
        <option v-for="cat in activeCats" :key="cat.value" :value="cat.value">
          {{ cat.label }} ({{ cat.count }})
        </option>
      </select>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="space-y-2">
      <div v-for="i in 5" :key="i" class="h-12 rounded-md bg-muted animate-pulse" />
    </div>

    <!-- Table -->
    <div v-else-if="filtered.length > 0" class="rounded-lg overflow-hidden" style="border:1px solid hsl(240 5.9% 90%)">
      <Table>
        <TableHeader>
          <TableRow style="background:hsl(240 4.8% 95.9% / 0.4)">
            <TableHead class="text-xs font-medium w-24">Datum</TableHead>
            <TableHead class="text-xs font-medium w-28">Kategorie</TableHead>
            <TableHead class="text-xs font-medium w-40">Kontakt</TableHead>
            <TableHead class="text-xs font-medium">Aktivitaet</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="act in filtered.slice(0, 100)" :key="act.id" class="hover:bg-muted/30">
            <TableCell class="text-[12px] text-muted-foreground">{{ formatDate(act.activity_date) }}</TableCell>
            <TableCell>
              <span
                class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium"
                :style="'background:' + catInfo(act.category).bg + '; color:' + catInfo(act.category).color"
              >{{ catInfo(act.category).label }}</span>
            </TableCell>
            <TableCell class="text-[13px] font-medium">{{ act.stakeholder }}</TableCell>
            <TableCell>
              <div class="text-[12px] text-muted-foreground line-clamp-2">{{ act.activity }}</div>
              <div v-if="act.result" class="text-[11px] text-muted-foreground/70 mt-0.5 line-clamp-1">{{ act.result }}</div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
      <div v-if="filtered.length > 100" class="px-4 py-3 text-center text-xs text-muted-foreground" style="border-top:1px solid hsl(240 5.9% 90%)">
        {{ filtered.length - 100 }} weitere Eintraege nicht angezeigt
      </div>
    </div>

    <!-- Empty -->
    <div v-else class="text-center py-14 space-y-3">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center mx-auto" style="background:hsl(200 80% 95%)">
        <ClipboardList class="w-6 h-6" style="color:hsl(200 80% 50%)" />
      </div>
      <div>
        <p class="text-sm font-medium" style="color:hsl(240 10% 3.9%)">Keine Aktivitaeten</p>
        <p class="text-xs text-muted-foreground mt-1">{{ searchQuery || filterCategory ? 'Keine Treffer fuer diesen Filter.' : 'Erste Aktivitaet ueber den Button oben erfassen.' }}</p>
      </div>
    </div>

    <!-- ═══ DIALOG ═══ -->
    <Dialog :open="showDialog" @update:open="showDialog = $event">
      <DialogContent class="max-w-md">
        <DialogHeader>
          <DialogTitle class="text-[15px]">Neue Aktivitaet</DialogTitle>
          <DialogDescription class="text-xs">Eintrag im Protokoll fuer {{ property.address || property.title }}</DialogDescription>
        </DialogHeader>

        <div class="space-y-4 pt-2">
          <!-- Date + Category -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-muted-foreground mb-1.5 block">Datum *</label>
              <Input v-model="form.activity_date" type="date" class="h-9 text-sm" />
            </div>
            <div>
              <label class="text-xs text-muted-foreground mb-1.5 block">Kategorie</label>
              <select
                v-model="form.category"
                class="w-full h-9 px-3 text-sm rounded-md outline-none"
                style="border:1px solid hsl(240 5.9% 90%); background:transparent"
              >
                <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
              </select>
            </div>
          </div>

          <!-- Stakeholder -->
          <div>
            <label class="text-xs text-muted-foreground mb-1.5 block">Kontakt / Person *</label>
            <Input v-model="form.stakeholder" placeholder="z.B. Max Mustermann" class="h-9 text-sm" />
          </div>

          <!-- Activity -->
          <div>
            <label class="text-xs text-muted-foreground mb-1.5 block">Aktivitaet *</label>
            <textarea
              v-model="form.activity"
              placeholder="Was wurde gemacht / besprochen?"
              class="w-full px-3 py-2 text-sm rounded-md outline-none resize-none"
              style="border:1px solid hsl(240 5.9% 90%); min-height:80px"
            ></textarea>
          </div>

          <!-- Result -->
          <div>
            <label class="text-xs text-muted-foreground mb-1.5 block">Ergebnis / Notiz</label>
            <Input v-model="form.result" placeholder="Optional" class="h-9 text-sm" />
          </div>

          <!-- Buttons -->
          <div class="flex justify-end gap-2 pt-1">
            <Button variant="outline" size="sm" @click="showDialog = false">Abbrechen</Button>
            <Button size="sm" :disabled="saving" @click="saveActivity">
              {{ saving ? "Speichern ..." : "Aktivitaet speichern" }}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>
