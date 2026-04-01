<script setup>
import { ref, computed, onMounted, inject } from "vue";
import { ShoppingCart, Plus, RefreshCw, Euro, Calendar, User } from "lucide-vue-next";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const props = defineProps({
  property: { type: Object, required: true },
});

const API = inject("API");
const toast = inject("toast");

// ─── State ───────────────────────────────────────────────
const offers = ref([]);
const loading = ref(false);
const showCreateDialog = ref(false);
const saving = ref(false);

const newOffer = ref({
  unit: "",
  contact_name: "",
  contact_email: "",
  contact_phone: "",
  amount: "",
  date: new Date().toISOString().slice(0, 10),
  status: "offen",
  notes: "",
});

// ─── Computed ────────────────────────────────────────────
const offerCount = computed(() => offers.value.length);

function statusLabel(s) {
  const map = { offen: "Offen", angenommen: "Angenommen", abgelehnt: "Abgelehnt" };
  return map[s] ?? s;
}

function statusVariant(s) {
  const map = {
    offen: "bg-amber-100 text-amber-700 border-amber-200",
    angenommen: "bg-emerald-100 text-emerald-700 border-emerald-200",
    abgelehnt: "bg-red-100 text-red-700 border-red-200",
  };
  return map[s] ?? "";
}

function formatAmount(v) {
  if (!v) return "—";
  const n = parseFloat(v);
  if (isNaN(n)) return v;
  return new Intl.NumberFormat("de-AT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(n);
}

function formatDate(d) {
  if (!d) return "—";
  try {
    return new Date(d).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" });
  } catch {
    return d;
  }
}

// ─── API ─────────────────────────────────────────────────
async function loadOffers() {
  loading.value = true;
  try {
    const res = await fetch(API.value + "&action=get_offers&property_id=" + props.property.id);
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (data.success) {
      offers.value = data.offers ?? [];
    } else {
      // API exists but returned error — stay empty, don't toast (could just be "none yet")
      offers.value = [];
    }
  } catch {
    // No API endpoint yet — show empty state silently
    offers.value = [];
  } finally {
    loading.value = false;
  }
}

async function createOffer() {
  if (!newOffer.value.contact_name || !newOffer.value.amount) {
    toast?.("Interessent und Anbot-Betrag sind Pflichtfelder.", "error");
    return;
  }
  saving.value = true;
  try {
    const fd = new FormData();
    fd.append("property_id", props.property.id);
    Object.entries(newOffer.value).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(API.value + "&action=create_offer", { method: "POST", body: fd });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (data.success) {
      toast?.("Kaufanbot wurde gespeichert.", "success");
      showCreateDialog.value = false;
      resetForm();
      await loadOffers();
    } else {
      toast?.(data.error ?? "Fehler beim Speichern.", "error");
    }
  } catch {
    toast?.("Kaufanbot konnte nicht gespeichert werden.", "error");
  } finally {
    saving.value = false;
  }
}

async function updateStatus(offer, status) {
  try {
    const fd = new FormData();
    fd.append("offer_id", offer.id);
    fd.append("status", status);
    const res = await fetch(API.value + "&action=update_offer_status", { method: "POST", body: fd });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (data.success) {
      offer.status = status;
      toast?.("Status aktualisiert.", "success");
    } else {
      toast?.(data.error ?? "Fehler.", "error");
    }
  } catch {
    toast?.("Status konnte nicht aktualisiert werden.", "error");
  }
}

function resetForm() {
  newOffer.value = {
    unit: "",
    contact_name: "",
    contact_email: "",
    contact_phone: "",
    amount: "",
    date: new Date().toISOString().slice(0, 10),
    status: "offen",
    notes: "",
  };
}

onMounted(loadOffers);
</script>

<template>
  <div class="space-y-5">
    <!-- Header row -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-[15px] font-semibold">
          Kaufanbote
          <span class="text-muted-foreground font-normal text-[13px] ml-1">({{ offerCount }})</span>
        </h2>
        <p class="text-xs text-muted-foreground mt-0.5">Eingehende Kaufangebote für dieses Objekt</p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" :disabled="loading" @click="loadOffers">
          <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />
        </Button>
        <Button size="sm" @click="showCreateDialog = true">
          <Plus class="w-3.5 h-3.5 mr-1.5" />
          Neues Anbot
        </Button>
      </div>
    </div>

    <!-- Create dialog (inline panel) -->
    <div v-if="showCreateDialog" class="border border-border rounded-lg p-5 bg-muted/30 space-y-4">
      <div class="flex items-center justify-between mb-1">
        <h3 class="text-sm font-semibold">Neues Kaufanbot erfassen</h3>
        <button class="text-muted-foreground hover:text-foreground text-lg leading-none" @click="showCreateDialog = false; resetForm()">×</button>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="space-y-1">
          <label class="text-xs text-muted-foreground">Einheit / Objekt</label>
          <Input v-model="newOffer.unit" placeholder="z.B. Top 3, EG" class="h-8 text-sm" />
        </div>
        <div class="space-y-1">
          <label class="text-xs text-muted-foreground">Anbot (€) *</label>
          <Input v-model="newOffer.amount" type="number" placeholder="350000" class="h-8 text-sm" />
        </div>
        <div class="space-y-1">
          <label class="text-xs text-muted-foreground">Interessent (Name) *</label>
          <Input v-model="newOffer.contact_name" placeholder="Max Mustermann" class="h-8 text-sm" />
        </div>
        <div class="space-y-1">
          <label class="text-xs text-muted-foreground">E-Mail</label>
          <Input v-model="newOffer.contact_email" type="email" placeholder="max@beispiel.at" class="h-8 text-sm" />
        </div>
        <div class="space-y-1">
          <label class="text-xs text-muted-foreground">Telefon</label>
          <Input v-model="newOffer.contact_phone" placeholder="+43 664 …" class="h-8 text-sm" />
        </div>
        <div class="space-y-1">
          <label class="text-xs text-muted-foreground">Datum</label>
          <Input v-model="newOffer.date" type="date" class="h-8 text-sm" />
        </div>
        <div class="col-span-2 space-y-1">
          <label class="text-xs text-muted-foreground">Notizen</label>
          <Input v-model="newOffer.notes" placeholder="Optionale Anmerkungen …" class="h-8 text-sm" />
        </div>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <Button variant="outline" size="sm" @click="showCreateDialog = false; resetForm()">Abbrechen</Button>
        <Button size="sm" :disabled="saving" @click="createOffer">
          {{ saving ? "Speichern …" : "Anbot speichern" }}
        </Button>
      </div>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="space-y-2">
      <div v-for="i in 3" :key="i" class="h-12 rounded-md bg-muted animate-pulse" />
    </div>

    <!-- Table -->
    <div v-else-if="offerCount > 0" class="border border-border rounded-lg overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow class="bg-muted/40">
            <TableHead class="text-xs font-medium w-28">Einheit</TableHead>
            <TableHead class="text-xs font-medium">Interessent</TableHead>
            <TableHead class="text-xs font-medium text-right w-36">Anbot</TableHead>
            <TableHead class="text-xs font-medium w-28">Datum</TableHead>
            <TableHead class="text-xs font-medium w-32">Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="offer in offers" :key="offer.id" class="hover:bg-muted/30">
            <TableCell class="text-[13px] text-muted-foreground">{{ offer.unit || "—" }}</TableCell>
            <TableCell>
              <div class="text-[13px] font-medium">{{ offer.contact_name }}</div>
              <div class="text-[11px] text-muted-foreground leading-tight">
                <span v-if="offer.contact_email">{{ offer.contact_email }}</span>
                <span v-if="offer.contact_email && offer.contact_phone"> · </span>
                <span v-if="offer.contact_phone">{{ offer.contact_phone }}</span>
              </div>
            </TableCell>
            <TableCell class="text-[13px] font-semibold text-right">{{ formatAmount(offer.amount) }}</TableCell>
            <TableCell class="text-[13px] text-muted-foreground">{{ formatDate(offer.date) }}</TableCell>
            <TableCell>
              <div class="flex items-center gap-1.5">
                <Badge class="text-[11px] border" :class="statusVariant(offer.status)">
                  {{ statusLabel(offer.status) }}
                </Badge>
                <!-- Quick status toggle menu -->
                <div class="flex gap-0.5">
                  <button
                    v-if="offer.status !== 'angenommen'"
                    class="text-[10px] text-emerald-600 hover:underline px-0.5"
                    title="Als Angenommen markieren"
                    @click="updateStatus(offer, 'angenommen')"
                  >✓</button>
                  <button
                    v-if="offer.status !== 'abgelehnt'"
                    class="text-[10px] text-red-500 hover:underline px-0.5"
                    title="Als Abgelehnt markieren"
                    @click="updateStatus(offer, 'abgelehnt')"
                  >✗</button>
                  <button
                    v-if="offer.status !== 'offen'"
                    class="text-[10px] text-amber-600 hover:underline px-0.5"
                    title="Wieder öffnen"
                    @click="updateStatus(offer, 'offen')"
                  >↺</button>
                </div>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- Empty state -->
    <div v-else class="text-center py-14 space-y-3">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center mx-auto" style="background:hsl(330 80% 96%)">
        <ShoppingCart class="w-6 h-6" style="color:hsl(330 80% 50%)" />
      </div>
      <div>
        <p class="text-sm font-medium" style="color:hsl(240 10% 3.9%)">Noch keine Kaufanbote</p>
        <p class="text-xs text-muted-foreground mt-1">Erfasse das erste Angebot über "+ Neues Anbot".</p>
      </div>
    </div>
  </div>
</template>
