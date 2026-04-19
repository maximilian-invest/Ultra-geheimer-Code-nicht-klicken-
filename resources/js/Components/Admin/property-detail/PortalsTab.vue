<script setup>
import { ref, computed, onMounted, inject } from "vue";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";

const props = defineProps({
  property: { type: Object, required: true },
});

const emit = defineEmits(["dirty"]);

const API = inject("API");
const toast = inject("toast");

// ─── Portal list (SR-Homes) ───
const portals = ref([]);

// ─── Immoji state ───
const immojiConnected = ref(false);
const immojiEmail = ref("");
const immojiPassword = ref("");
const immojiConnecting = ref(false);
const immojiPushing = ref(false);
const immojiPortals = ref(null);
const immojiPortalLoading = ref(false);
const immojiPortalSaving = ref({});
const immojiCapacity = ref(null);

// ─── Computed ───

// ─── Pflichtfelder für Veröffentlichung ───
const BASE_REQUIRED_FIELDS = [
  { key: 'ref_id', label: 'Ref-ID' },
  { key: 'address', label: 'Adresse' },
  { key: 'city', label: 'Stadt' },
  { key: 'object_type', label: 'Objekttyp' },
  { key: 'realty_description', label: 'Beschreibung' },
];

const STANDARD_REQUIRED_FIELDS = [
  { key: 'purchase_price', label: 'Kaufpreis' },
  { key: 'living_area', label: 'Wohnfläche' },
  { key: 'rooms_amount', label: 'Zimmer' },
];

const isNewbuild = computed(() => {
  const category = String(props.property?.property_category || '').toLowerCase();
  if (category === 'newbuild') return true;

  const objectType = String(props.property?.object_type || props.property?.type || '').toLowerCase();
  return objectType === 'neubauprojekt' || objectType === 'neubau';
});

const requiredFields = computed(() => {
  if (isNewbuild.value) {
    return BASE_REQUIRED_FIELDS;
  }
  return [...BASE_REQUIRED_FIELDS, ...STANDARD_REQUIRED_FIELDS];
});

const missingFields = computed(() => {
  const p = props.property;
  if (!p) return requiredFields.value.map(f => f.label);
  return requiredFields.value.filter(f => {
    const v = p[f.key];
    const alt = f.altKey ? p[f.altKey] : null;
    return !v && v !== 0 && !alt && alt !== 0;
  }).map(f => f.label);
});

const canPublish = computed(() => missingFields.value.length === 0);

function validateBeforePublish() {
  if (!canPublish.value) {
    toast("Pflichtfelder fehlen: " + missingFields.value.join(", "));
    return false;
  }
  return true;
}


const PORTAL_TO_IMMOJI = {
  willhaben: "willhabenExportEnabled",
  immowelt: "immoweltExportEnabled",
  immoscout24: "immoscoutExportEnabled",
};

const IMMOJI_PORTALS = [
  { key: "willhabenExportEnabled",  label: "willhaben.at",       color: "#ea580c", lastKey: "willhabenLastExport",  capKey: "willhaben" },
  { key: "immoweltExportEnabled",   label: "immowelt.at",        color: "#dc2626", lastKey: "immoweltLastExport",   capKey: "immowelt" },
  { key: "immoscoutExportEnabled",  label: "ImmobilienScout24",  color: "#2563eb", lastKey: "immoscoutLastExport",  capKey: "immoscout" },
  { key: "dibeoExportEnabled",      label: "Dibeo",              color: "#0891b2", lastKey: "dibeoLastExport",      capKey: "dibeo" },
  { key: "kurierExportEnabled",     label: "Kurier",             color: "#b91c1c", lastKey: "kurierLastExport",     capKey: "kurier" },
  { key: "immoSNExportEnabled",     label: "Immo SN",            color: "#7c3aed", lastKey: "immoSNLastExport",     capKey: "immoSN" },
  { key: "allesKralleExportEnabled",label: "Alles Kralle",       color: "#65a30d",                                  capKey: "allesKralle" },
  { key: "homepageExportEnabled",   label: "Immoji Homepage",    color: "#8b5cf6" },
];

// ─── SR-Homes portal helpers ───
function isPortalEnabled(name) {
  const p = portals.value.find(x => x.portal_name === name);
  return p ? !!p.sync_enabled : false;
}

const srHomesEnabled = computed(() => isPortalEnabled("sr-homes"));

async function loadPortals() {
  if (!props.property?.id) return;
  try {
    const res = await fetch(API.value + "&action=list_property_portals&property_id=" + props.property.id);
    const data = await res.json();
    portals.value = data.portals || [];
  } catch (e) { /* silent */ }
}

async function savePortal(portalName, enabled) {
  if (!props.property?.id) return;
  try {
    const r = await fetch(API.value + "&action=save_property_portal", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        portal_name: portalName,
        sync_enabled: enabled ? 1 : 0,
        status: enabled ? "active" : "draft",
      }),
    });
    const d = await r.json();
    if (d.success) {
      const res = await fetch(API.value + "&action=list_property_portals&property_id=" + props.property.id).then(r => r.json());
      portals.value = res.portals || [];
      toast("Portal-Status aktualisiert");

      // Sync to Immoji if connected and mapped
      const immojiField = PORTAL_TO_IMMOJI[portalName];
      if (immojiField && immojiConnected.value && props.property?.openimmo_id) {
        try {
          await fetch(API.value + "&action=immoji_set_portals", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: props.property.id, portals: { [immojiField]: enabled } }),
          });
          if (immojiPortals.value) immojiPortals.value[immojiField] = enabled;
          toast("Immoji " + portalName + (enabled ? " aktiviert" : " deaktiviert"));
        } catch (e) { /* silent */ }
      }
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

function toggleSrHomes(val) {
  if (val && !validateBeforePublish()) return;
  emit("dirty");
  savePortal("sr-homes", val);
}

// ─── Immoji functions ───
async function checkImmojiStatus() {
  try {
    const r = await fetch(API.value + "&action=immoji_status");
    const d = await r.json();
    immojiConnected.value = d.connected || false;
    if (immojiConnected.value) {
      loadImmojiPortals();
      loadImmojiCapacity();
    }
  } catch (e) {
    immojiConnected.value = false;
  }
}

async function connectImmoji() {
  if (!immojiEmail.value.trim() || !immojiPassword.value.trim()) return;
  immojiConnecting.value = true;
  try {
    const r = await fetch(API.value + "&action=immoji_connect", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email: immojiEmail.value.trim(), password: immojiPassword.value.trim() }),
    });
    const d = await r.json();
    if (d.success) {
      immojiConnected.value = true;
      immojiEmail.value = "";
      immojiPassword.value = "";
      toast(d.message || "Verbunden");
      loadImmojiPortals();
      loadImmojiCapacity();
    } else {
      toast(d.message || "Fehler beim Verbinden");
    }
  } catch (e) {
    toast("Verbindungsfehler");
  }
  immojiConnecting.value = false;
}

async function disconnectImmoji() {
  try {
    await fetch(API.value + "&action=immoji_disconnect", { method: "POST" });
    immojiConnected.value = false;
    immojiPortals.value = null;
    toast("Immoji getrennt");
  } catch (e) { /* silent */ }
}

async function pushToImmoji() {
  if (!props.property?.id) return;
  if (!validateBeforePublish()) return;
  immojiPushing.value = true;
  try {
    const r = await fetch(API.value + "&action=immoji_push", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id }),
    });
    const d = await r.json();
    if (d.success) {
      toast(d.message || "Erfolgreich hochgeladen");
      const pr = await fetch(API.value + "&action=list_property_portals&property_id=" + props.property.id);
      const pd = await pr.json();
      portals.value = pd.portals || [];
      await loadImmojiPortals();
    } else {
      toast(d.message || "Fehler");
    }
  } catch (e) {
    toast("Upload fehlgeschlagen");
  }
  immojiPushing.value = false;
}

async function loadImmojiPortals() {
  if (!props.property?.id || !immojiConnected.value) return;
  immojiPortalLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=immoji_portal_status&property_id=" + props.property.id);
    const d = await r.json();
    if (d.success && d.portals) {
      immojiPortals.value = d.portals;
    } else {
      immojiPortals.value = null;
    }
  } catch (e) {
    immojiPortals.value = null;
  }
  immojiPortalLoading.value = false;
}

async function loadImmojiCapacity() {
  if (!immojiConnected.value) return;
  try {
    const r = await fetch(API.value + "&action=immoji_capacity");
    const d = await r.json();
    if (d.success && d.capacity) {
      immojiCapacity.value = d.capacity;
    }
  } catch (e) { /* silent */ }
}

async function togglePortal(fieldKey, currentValue) {
  if (!props.property?.id) return;
  immojiPortalSaving.value = { ...immojiPortalSaving.value, [fieldKey]: true };
  try {
    const r = await fetch(API.value + "&action=immoji_set_portals", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        portals: { [fieldKey]: !currentValue },
      }),
    });
    const d = await r.json();
    if (d.success) {
      if (immojiPortals.value) {
        immojiPortals.value[fieldKey] = !currentValue;
      }
      toast("Portal " + (!currentValue ? "aktiviert" : "deaktiviert"));
    } else {
      toast(d.message || "Fehler");
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  immojiPortalSaving.value = { ...immojiPortalSaving.value, [fieldKey]: false };
}

function formatLastExport(ts) {
  if (!ts) return null;
  const d = new Date(ts);
  return d.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "2-digit", hour: "2-digit", minute: "2-digit" });
}

// ─── Expose save() for parent ───
async function save() {
  // Portal changes are saved immediately on toggle; nothing to batch-save
}

defineExpose({ save });

// ─── Init ───
onMounted(() => {
  loadPortals();
  checkImmojiStatus();
});
</script>

<template>
  <div class="max-w-2xl space-y-4">

    <!-- Pflichtfelder-Warnung -->
    <div v-if="missingFields.length" class="rounded-lg p-3 text-xs space-y-1.5" style="background:hsl(0 84% 97%);border:1px solid hsl(0 84% 92%)">
      <div class="font-medium" style="color:hsl(0 72% 51%)">Pflichtfelder fehlen für die Veröffentlichung:</div>
      <div class="flex flex-wrap gap-1.5">
        <span v-for="f in missingFields" :key="f" class="px-2 py-0.5 rounded-full text-[11px] font-medium" style="background:hsl(0 84% 92%);color:hsl(0 72% 40%)">{{ f }}</span>
      </div>
      <div class="text-[11px]" style="color:hsl(0 40% 50%)">Bitte im Bearbeiten-Tab ausfüllen, bevor du auf Portalen veröffentlichst.</div>
    </div>

    <!-- SR-Homes Website Toggle -->
    <div class="flex items-center justify-between py-3 border-b border-border/50">
      <div>
        <div class="text-sm font-medium">SR-Homes Website</div>
        <div class="text-xs text-muted-foreground">Auf sr-homes.at veröffentlichen</div>
      </div>
      <Switch
        :checked="srHomesEnabled"
        @update:checked="toggleSrHomes"
      />
    </div>

    <!-- Immoji Section -->
    <div class="space-y-4 pt-1">
      <div class="flex items-center gap-2.5">
        <div class="w-2.5 h-2.5 rounded-full bg-violet-500 shrink-0" />
        <span class="text-sm font-semibold">Immoji</span>
        <Badge v-if="immojiConnected" class="ml-auto text-[11px] bg-emerald-100 text-emerald-700 border-emerald-200">
          Verbunden
        </Badge>
        <span v-else class="ml-auto text-xs text-muted-foreground">Nicht verbunden</span>
      </div>

      <!-- Disconnected: login form -->
      <div v-if="!immojiConnected" class="space-y-2">
        <Input v-model="immojiEmail" type="email" placeholder="Immoji E-Mail" />
        <Input v-model="immojiPassword" type="password" placeholder="Immoji Passwort" />
        <Button
          class="w-full"
          :disabled="immojiConnecting || !immojiEmail.trim() || !immojiPassword.trim()"
          @click="connectImmoji"
        >
          {{ immojiConnecting ? "Verbinde..." : "Verbinden" }}
        </Button>
      </div>

      <!-- Connected -->
      <div v-else class="space-y-4">
        <div class="flex items-center gap-2">
          <Button variant="outline" size="sm" @click="disconnectImmoji">Trennen</Button>
          <Button
            v-if="property?.id"
            size="sm"
            :disabled="immojiPushing"
            @click="pushToImmoji"
          >
            {{ immojiPushing ? "Sync..." : (property?.openimmo_id ? "Erneut syncen" : "Zu Immoji hochladen") }}
          </Button>
        </div>

        <!-- Portal toggles -->
        <div v-if="immojiPortals !== null" class="border border-border/50 rounded-lg overflow-hidden">
          <div
            v-for="portal in IMMOJI_PORTALS"
            :key="portal.key"
            class="flex items-center justify-between py-2.5 px-3 border-b border-border/50 last:border-0"
          >
            <div class="flex items-center gap-2.5">
              <div class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: portal.color }" />
              <span class="text-sm">{{ portal.label }}</span>
            </div>
            <div class="flex items-center gap-3">
              <Badge
                v-if="portal.capKey && immojiCapacity && immojiCapacity[portal.capKey] && immojiCapacity[portal.capKey].limit"
                variant="outline"
                class="text-[10px]"
              >
                Limit: {{ immojiCapacity[portal.capKey].limit }}
              </Badge>
              <span
                v-if="portal.lastKey && immojiPortals[portal.lastKey]"
                class="text-[10px] text-muted-foreground"
              >
                {{ formatLastExport(immojiPortals[portal.lastKey]) }}
              </span>
              <Switch
                :checked="!!immojiPortals[portal.key]"
                :disabled="!!immojiPortalSaving[portal.key]"
                @update:checked="togglePortal(portal.key, immojiPortals[portal.key])"
              />
            </div>
          </div>
        </div>

        <!-- Loading portals -->
        <div v-else-if="immojiPortalLoading" class="flex items-center gap-2 text-xs text-muted-foreground py-2">
          <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Lade Portale...
        </div>

        <!-- Not yet pushed -->
        <div v-else-if="!property?.openimmo_id" class="text-xs text-muted-foreground py-2">
          Objekt zuerst hochladen um Portal-Export zu steuern.
        </div>
      </div>
    </div>

  </div>
</template>
