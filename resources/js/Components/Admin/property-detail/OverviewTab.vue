<script setup>
import { ref, computed, onMounted, inject } from "vue";
import {
  Users, Key, Link2, Plus, Check, UserPlus, Unlink, ChevronRight,
  ArrowLeft, Home, X
} from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const props = defineProps({
  property: { type: Object, required: true },
});

const emit = defineEmits(["owner-changed", "property-created"]);

const API = inject("API");
const toast = inject("toast");

// ─── Owner state ────────────────────────────────────────
const ownerData = ref({
  customer_id: props.property.customer_id || null,
  owner_name: props.property.owner_name || "",
  owner_email: props.property.owner_email || "",
  owner_phone: props.property.owner_phone || "",
});

const customersList = ref([]);
const customersLoaded = ref(false);
const selectedCustomerId = ref("");
const showCreateOwnerForm = ref(false);
const newOwnerForm = ref({ name: "", email: "", phone: "" });
const newOwnerSaving = ref(false);

// ─── Portal state ────────────────────────────────────────
const portalUser = ref(null);
const portalLoading = ref(false);
const showPortalForm = ref(false);
const portalForm = ref({ password: "" });
const portalCreating = ref(false);
const portalError = ref("");
const portalSuccess = ref("");

// ─── Project group state ─────────────────────────────────
const projectGroups = ref([]);
const projectGroupPopup = ref(false);
const newGroupName = ref("");
const newGroupDesc = ref("");
const showNewGroupForm = ref(false);

// ─── Child create state ──────────────────────────────────
const childCreateModal = ref(false);
const childCreateLoading = ref(false);
const childCategories = ref([]);
const childCategoriesLoading = ref(false);
const childSelected = ref(new Set());
const childManualTitle = ref("");
const childMode = ref("categories");

// ─── Computed ────────────────────────────────────────────
const isNewbuild = computed(() => props.property?.property_category === "newbuild");
const hasOwner = computed(() => !!ownerData.value.customer_id);

function formatPrice(val) {
  if (!val) return "–";
  return new Intl.NumberFormat("de-AT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(val);
}

function formatNum(val) {
  if (!val) return "–";
  return new Intl.NumberFormat("de-AT").format(val);
}

// ─── Init ────────────────────────────────────────────────
onMounted(() => {
  loadPortalAccess();
  loadCustomersList();
  loadProjectGroups();
});

// ─── API: Customers ──────────────────────────────────────
async function loadCustomersList() {
  if (customersLoaded.value) return;
  try {
    const r = await fetch(API.value + "&action=list_customers");
    const d = await r.json();
    customersList.value = d.customers || [];
    customersLoaded.value = true;
  } catch (e) {}
}

async function selectExistingOwner() {
  const id = Number(selectedCustomerId.value);
  if (!id) return;
  const c = customersList.value.find(x => x.id === id);
  if (!c) return;
  try {
    const r = await fetch(API.value + "&action=save_property_settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: props.property.id,
        customer_id: c.id,
        owner_name: c.name,
        owner_email: c.email || "",
        owner_phone: c.phone || "",
      }),
    });
    const d = await r.json();
    if (d.success) {
      ownerData.value = { customer_id: c.id, owner_name: c.name, owner_email: c.email || "", owner_phone: c.phone || "" };
      emit("owner-changed", { propertyId: props.property.id, ...ownerData.value });
      toast("Eigentümer zugewiesen: " + c.name);
      loadPortalAccess();
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  selectedCustomerId.value = "";
}

async function createNewOwner() {
  const f = newOwnerForm.value;
  if (!f.name) { toast("Bitte Name eingeben"); return; }
  newOwnerSaving.value = true;
  try {
    const r = await fetch(API.value + "&action=create_customer", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: f.name, email: f.email, phone: f.phone }),
    });
    const d = await r.json();
    if (d.success && d.customer) {
      customersLoaded.value = false;
      await loadCustomersList();
      await fetch(API.value + "&action=save_property_settings", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          property_id: props.property.id,
          customer_id: d.customer.id,
          owner_name: d.customer.name,
          owner_email: d.customer.email || "",
          owner_phone: d.customer.phone || "",
        }),
      });
      ownerData.value = {
        customer_id: d.customer.id,
        owner_name: d.customer.name,
        owner_email: d.customer.email || "",
        owner_phone: d.customer.phone || "",
      };
      emit("owner-changed", { propertyId: props.property.id, ...ownerData.value });
      toast("Eigentümer angelegt & zugewiesen");
      showCreateOwnerForm.value = false;
      newOwnerForm.value = { name: "", email: "", phone: "" };
      loadPortalAccess();
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  newOwnerSaving.value = false;
}

async function unlinkCustomer() {
  if (!confirm("Eigentümer-Verknüpfung wirklich lösen?")) return;
  try {
    const r = await fetch(API.value + "&action=save_property_settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: props.property.id, customer_id: 0, owner_name: "", owner_email: "", owner_phone: "" }),
    });
    const d = await r.json();
    if (d.success) {
      ownerData.value = { customer_id: null, owner_name: "", owner_email: "", owner_phone: "" };
      portalUser.value = null;
      emit("owner-changed", { propertyId: props.property.id, ...ownerData.value });
      toast("Eigentümer-Verknüpfung gelöst");
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

// ─── API: Portal ─────────────────────────────────────────
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

async function createPortalAccess() {
  if (!ownerData.value.owner_name || !ownerData.value.owner_email || !portalForm.value.password) {
    portalError.value = "Eigentümer-Daten und Passwort erforderlich";
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
    } else {
      portalError.value = d.error || "Fehler beim Erstellen";
    }
  } catch (e) {
    portalError.value = e.message;
  }
  portalCreating.value = false;
}

// ─── API: Project groups ─────────────────────────────────
async function loadProjectGroups() {
  try {
    const res = await fetch(`${API.value}&action=list_project_groups`);
    const data = await res.json();
    if (data.success) projectGroups.value = data.groups || [];
  } catch (e) {}
}

async function assignProjectGroup(groupId) {
  const p = props.property;
  if (!p) return;
  try {
    await fetch(`${API.value}&action=update_property`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ property_id: p.id, project_group_id: groupId || null }),
    });
    p.project_group_id = groupId;
    toast("Projektgruppe " + (groupId ? "zugewiesen" : "entfernt"));
  } catch (e) {}
}

async function createAndAssignGroup() {
  if (!newGroupName.value.trim()) return;
  const p = props.property;
  try {
    const fd = new FormData();
    fd.append("name", newGroupName.value.trim());
    if (newGroupDesc.value.trim()) fd.append("description", newGroupDesc.value.trim());
    if (p?.customer_id) fd.append("customer_id", p.customer_id);
    const res = await fetch(`${API.value}&action=create_project_group`, { method: "POST", body: fd });
    const data = await res.json();
    if (data.success && data.group) {
      projectGroups.value.push(data.group);
      await assignProjectGroup(data.group.id);
      showNewGroupForm.value = false;
      newGroupName.value = "";
      newGroupDesc.value = "";
      toast("Projektgruppe erstellt & zugewiesen");
    }
  } catch (e) {}
}

// ─── API: Child create ───────────────────────────────────
async function openChildCreateModal() {
  childCreateModal.value = true;
  childMode.value = "categories";
  childManualTitle.value = "";
  childSelected.value = new Set();
  childCategories.value = [];
  const p = props.property;
  if (!p) return;
  childCategoriesLoading.value = true;
  try {
    const res = await fetch(API.value + "&action=get_unit_categories&property_id=" + p.id);
    const d = await res.json();
    if (d.success && d.categories?.length) {
      childCategories.value = d.categories.map(c => ({
        ...c,
        rooms: parseFloat(c.rooms),
        selected: false,
        title: Math.floor(parseFloat(c.rooms)) + "-Zimmer Wohnungen",
      }));
    }
  } catch (e) {}
  childCategoriesLoading.value = false;
}

function toggleCategory(rooms) {
  const s = new Set(childSelected.value);
  if (s.has(rooms)) s.delete(rooms);
  else s.add(rooms);
  childSelected.value = s;
}

async function createChildrenFromCategories() {
  const p = props.property;
  if (!p) return;
  childCreateLoading.value = true;
  try {
    const cats = childCategories.value
      .filter(c => childSelected.value.has(c.rooms))
      .map(c => ({ rooms: c.rooms, title: c.title, min_price: c.min_price, min_area: c.min_area, max_area: c.max_area }));
    const res = await fetch(API.value + "&action=create_children_from_categories", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ parent_id: p.id, categories: cats }),
    });
    const d = await res.json();
    if (d.success) {
      toast(d.message);
      childCreateModal.value = false;
      emit("property-created");
      window.location.reload();
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  childCreateLoading.value = false;
}

async function createChildManual() {
  const p = props.property;
  if (!p || !childManualTitle.value.trim()) return;
  childCreateLoading.value = true;
  try {
    const res = await fetch(API.value + "&action=create_child_property", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ parent_id: p.id, title: childManualTitle.value.trim() }),
    });
    const d = await res.json();
    if (d.success) {
      toast("Unterobjekt erstellt");
      childCreateModal.value = false;
      emit("property-created");
      window.location.reload();
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  childCreateLoading.value = false;
}

function ownerInitials(name) {
  if (!name) return "?";
  return name.split(" ").map(n => n[0]).join("").toUpperCase().slice(0, 2);
}
</script>

<template>
  <div class="space-y-6">

    <!-- ── KPI Cards ── -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
      <!-- Kaufpreis -->
      <div class="rounded-lg px-4 py-3" style="border:1px solid hsl(240 5.9% 94%);background:hsl(240 4.8% 95.9%)">
        <div class="text-[10px] font-medium uppercase tracking-wider mb-1" style="color:hsl(240 3.8% 46.1%)">Kaufpreis ab</div>
        <div class="text-[15px] sm:text-[17px] font-bold tabular-nums leading-tight" style="color:hsl(240 10% 3.9%)">
          {{ property.purchase_price ? formatPrice(property.purchase_price) : '–' }}
        </div>
        <div v-if="property.price_per_m2" class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
          {{ formatNum(property.price_per_m2) }} €/m²
        </div>
      </div>

      <!-- Fläche / Einheiten -->
      <div class="rounded-lg px-4 py-3" style="border:1px solid hsl(240 5.9% 94%);background:hsl(240 4.8% 95.9%)">
        <div class="text-[10px] font-medium uppercase tracking-wider mb-1" style="color:hsl(240 3.8% 46.1%)">
          {{ isNewbuild ? 'Einheiten' : 'Fläche' }}
        </div>
        <div class="text-[15px] sm:text-[17px] font-bold tabular-nums leading-tight" style="color:hsl(240 10% 3.9%)">
          {{ isNewbuild
            ? (property.unit_count || property.children?.length || '–')
            : (property.total_area ? property.total_area + ' m²' : '–') }}
        </div>
        <div v-if="isNewbuild && property.total_area" class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
          {{ property.total_area }} m² gesamt
        </div>
        <div v-if="!isNewbuild && property.living_area" class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
          {{ property.living_area }} m² Wohnfl.
        </div>
      </div>

      <!-- Provision -->
      <div class="rounded-lg px-4 py-3" style="border:1px solid hsl(240 5.9% 94%);background:hsl(240 4.8% 95.9%)">
        <div class="text-[10px] font-medium uppercase tracking-wider mb-1" style="color:hsl(240 3.8% 46.1%)">Provision</div>
        <div class="text-[15px] sm:text-[17px] font-bold tabular-nums leading-tight" style="color:hsl(240 10% 3.9%)">
          {{ property.commission_percent ? property.commission_percent + '%' : '–' }}
        </div>
        <div v-if="property.buyer_commission_percent" class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
          Käufer: {{ property.buyer_commission_percent }}%
        </div>
      </div>

      <!-- Portale / Status -->
      <div class="rounded-lg px-4 py-3" style="border:1px solid hsl(240 5.9% 94%);background:hsl(240 4.8% 95.9%)">
        <div class="text-[10px] font-medium uppercase tracking-wider mb-1" style="color:hsl(240 3.8% 46.1%)">Status</div>
        <div class="text-[15px] sm:text-[17px] font-bold leading-tight" style="color:hsl(240 10% 3.9%)">
          {{ property.on_hold ? 'Pausiert' : 'Aktiv' }}
        </div>
        <div class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
          {{ property.ref_id || property.object_type || '–' }}
        </div>
      </div>
    </div>

    <!-- ── Projektdaten Grid ── -->
    <div>
      <div class="text-[13px] font-semibold mb-2.5" style="color:hsl(240 10% 3.9%)">Projektdaten</div>
      <div class="rounded-lg overflow-hidden" style="border:1px solid hsl(240 5.9% 94%)">
        <div class="grid grid-cols-[110px_1fr] sm:grid-cols-[130px_1fr_130px_1fr]">

          <!-- Row: Straße / PLZ+Ort -->
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">Straße</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">{{ property.address || '–' }}</div>
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">PLZ / Ort</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%)">{{ [property.zip, property.city].filter(Boolean).join(' ') || '–' }}</div>

          <!-- Row: Fläche / Typ -->
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">Fläche</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">
            <template v-if="isNewbuild && property.total_area">{{ property.total_area }} m²</template>
            <template v-else-if="property.total_area">{{ property.total_area }} m²</template>
            <template v-else>–</template>
          </div>
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">Objekttyp</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%)">{{ property.object_type || '–' }}</div>

          <!-- Row: Heizung / Aufzug -->
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">Heizung</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">{{ property.heating || property.heating_type || '–' }}</div>
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">Aufzug</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%)">{{ property.has_elevator || property.elevator ? 'Ja' : 'Nein' }}</div>

          <!-- Row: HWB / Stockwerke -->
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">HWB</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">
            {{ property.heating_demand_value ? property.heating_demand_value + ' kWh/m²a' : (property.hwb || '–') }}
            <span v-if="property.heating_demand_class" class="ml-1 text-[10px] font-semibold px-1.5 rounded" style="background:hsl(142 76% 96%);color:hsl(142 72% 29%)">{{ property.heating_demand_class }}</span>
          </div>
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-bottom:1px solid hsl(240 5.9% 94%)">Stockwerke</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-bottom:1px solid hsl(240 5.9% 94%)">{{ property.floor_count || property.floors || '–' }}</div>

          <!-- Row: Stellplätze / Garage -->
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);" :class="!isNewbuild ? 'border-bottom:1px solid hsl(240 5.9% 94%)' : ''">Stellplätze</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%);border-right:1px solid hsl(240 5.9% 94%)" :style="!isNewbuild ? 'border-bottom:1px solid hsl(240 5.9% 94%)' : ''">{{ property.parking_spaces || '–' }}</div>
          <div class="px-3 py-2 text-[11px] font-medium" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%)">Garage</div>
          <div class="px-3 py-2 text-[12px]" style="color:hsl(240 10% 3.9%)">{{ property.garage_spaces || property.garage || '–' }}</div>

          <!-- Newbuild only: Bauträger / Fertigstellung -->
          <template v-if="isNewbuild">
            <div class="px-3 py-2 text-[11px] font-medium border-t" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-color:hsl(240 5.9% 94%);border-bottom:1px solid hsl(240 5.9% 94%)">Bauträger</div>
            <div class="px-3 py-2 text-[12px] border-t border-b" style="color:hsl(240 10% 3.9%);border-color:hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">{{ property.builder_company || '–' }}</div>
            <div class="px-3 py-2 text-[11px] font-medium border-t" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-color:hsl(240 5.9% 94%);border-bottom:1px solid hsl(240 5.9% 94%)">Fertigstellung</div>
            <div class="px-3 py-2 text-[12px] border-t border-b" style="color:hsl(240 10% 3.9%);border-color:hsl(240 5.9% 94%)">{{ property.construction_end || '–' }}</div>
          </template>

          <!-- Standard only: Baujahr / Zimmer / Zustand / Grundstück -->
          <template v-if="!isNewbuild">
            <div class="px-3 py-2 text-[11px] font-medium border-t" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-color:hsl(240 5.9% 94%);border-bottom:1px solid hsl(240 5.9% 94%)">Baujahr</div>
            <div class="px-3 py-2 text-[12px] border-t border-b" style="color:hsl(240 10% 3.9%);border-color:hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">{{ property.construction_year || '–' }}</div>
            <div class="px-3 py-2 text-[11px] font-medium border-t" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-color:hsl(240 5.9% 94%);border-bottom:1px solid hsl(240 5.9% 94%)">Zimmer</div>
            <div class="px-3 py-2 text-[12px] border-t border-b" style="color:hsl(240 10% 3.9%);border-color:hsl(240 5.9% 94%)">{{ property.rooms_amount || '–' }}</div>
            <div class="px-3 py-2 text-[11px] font-medium border-t" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-color:hsl(240 5.9% 94%);border-bottom:1px solid hsl(240 5.9% 94%)">Zustand</div>
            <div class="px-3 py-2 text-[12px] border-t border-b" style="color:hsl(240 10% 3.9%);border-color:hsl(240 5.9% 94%);border-right:1px solid hsl(240 5.9% 94%)">{{ property.realty_condition || property.condition || '–' }}</div>
            <div class="px-3 py-2 text-[11px] font-medium border-t" style="color:hsl(240 3.8% 46.1%);background:hsl(240 4.8% 95.9%);border-color:hsl(240 5.9% 94%)">Grundstück</div>
            <div class="px-3 py-2 text-[12px] border-t" style="color:hsl(240 10% 3.9%);border-color:hsl(240 5.9% 94%)">{{ property.free_area || property.plot_area ? (property.free_area || property.plot_area) + ' m²' : '–' }}</div>
          </template>

        </div>
      </div>
    </div>

    <!-- ── Eigentümer & Kontakt ── -->
    <div>
      <div class="text-[13px] font-semibold mb-2.5" style="color:hsl(240 10% 3.9%)">Eigentümer & Kontakt</div>
      <div class="rounded-lg p-4" style="border:1px solid hsl(240 5.9% 94%)">

        <!-- Owner exists -->
        <div v-if="hasOwner" class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white" style="background:hsl(21 90% 48%)">
            {{ ownerInitials(ownerData.owner_name) }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-[13px] font-semibold" style="color:hsl(240 10% 3.9%)">{{ ownerData.owner_name }}</div>
            <div class="text-[11px]" style="color:hsl(240 3.8% 46.1%)">
              {{ ownerData.owner_email || 'Keine E-Mail' }}
              <template v-if="ownerData.owner_phone"> · {{ ownerData.owner_phone }}</template>
            </div>
          </div>
          <div class="flex items-center gap-1.5 flex-shrink-0">
            <a v-if="ownerData.owner_email" :href="'mailto:' + ownerData.owner_email"
              class="inline-flex items-center gap-1 text-[11px] font-medium px-2.5 py-1.5 rounded-md transition-colors hover:bg-zinc-100" style="color:hsl(240 3.8% 46.1%);border:1px solid hsl(240 5.9% 94%)">
              Mail
            </a>
            <a v-if="ownerData.owner_phone" :href="'tel:' + ownerData.owner_phone"
              class="inline-flex items-center gap-1 text-[11px] font-medium px-2.5 py-1.5 rounded-md transition-colors hover:bg-zinc-100" style="color:hsl(240 3.8% 46.1%);border:1px solid hsl(240 5.9% 94%)">
              Anrufen
            </a>
            <button @click="unlinkCustomer" title="Verknüpfung lösen"
              class="inline-flex items-center gap-1 text-[11px] font-medium px-2.5 py-1.5 rounded-md transition-colors hover:bg-red-50" style="color:hsl(0 72% 51%);border:1px solid hsl(0 93% 90%)">
              <Unlink class="w-3 h-3" /> Lösen
            </button>
          </div>
        </div>

        <!-- No owner -->
        <div v-else class="space-y-3">
          <p class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">Kein Eigentümer zugewiesen. Aus Kontakten wählen oder neu anlegen.</p>
          <div class="flex flex-col sm:flex-row gap-2">
            <select v-model="selectedCustomerId" @change="selectExistingOwner"
              class="flex-1 px-3 py-2 rounded-md text-[12px] focus:outline-none focus:ring-1 focus:ring-zinc-400"
              style="border:1px solid hsl(240 5.9% 94%);background:white;color:hsl(240 10% 3.9%)">
              <option value="">– Kontakt wählen –</option>
              <option v-for="c in customersList" :key="c.id" :value="c.id">{{ c.name }} · {{ c.email || 'keine Email' }}</option>
            </select>
            <Button variant="outline" size="sm" @click="showCreateOwnerForm = !showCreateOwnerForm">
              <UserPlus class="w-3.5 h-3.5 mr-1.5" /> Neu anlegen
            </Button>
          </div>

          <!-- New owner form -->
          <div v-if="showCreateOwnerForm" class="p-3.5 rounded-lg space-y-2.5" style="background:hsl(240 4.8% 95.9%);border:1px solid hsl(240 5.9% 94%)">
            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:hsl(21 90% 48%)">Neuen Eigentümer anlegen</div>
            <Input v-model="newOwnerForm.name" placeholder="Vor- und Nachname *" class="h-8 text-[12px]" />
            <div class="grid grid-cols-2 gap-2">
              <Input v-model="newOwnerForm.email" type="email" placeholder="E-Mail" class="h-8 text-[12px]" />
              <Input v-model="newOwnerForm.phone" type="tel" placeholder="Telefon" class="h-8 text-[12px]" />
            </div>
            <div class="flex gap-2">
              <Button variant="outline" size="sm" @click="showCreateOwnerForm = false">Abbrechen</Button>
              <Button size="sm" :disabled="newOwnerSaving || !newOwnerForm.name" @click="createNewOwner">
                {{ newOwnerSaving ? 'Wird angelegt...' : 'Anlegen & Zuweisen' }}
              </Button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Portalzugang (only master/standalone) ── -->
    <div v-if="!property.parent_id">
      <div class="text-[13px] font-semibold mb-2.5 flex items-center gap-2" style="color:hsl(240 10% 3.9%)">
        Portalzugang
        <Badge v-if="portalUser" class="text-[10px] px-2 py-0" style="background:hsl(142 76% 96%);color:hsl(142 72% 29%);border:1px solid hsl(142 76% 85%)">Aktiv</Badge>
        <Badge v-else-if="hasOwner" variant="outline" class="text-[10px] px-2 py-0">Kein Zugang</Badge>
      </div>
      <div class="rounded-lg p-4" style="border:1px solid hsl(240 5.9% 94%)">

        <!-- Active portal -->
        <div v-if="portalUser" class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0" style="background:hsl(142 72% 29%);color:white">
            <Check class="w-4 h-4" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-[13px] font-semibold" style="color:hsl(240 10% 3.9%)">{{ portalUser.name }}</div>
            <div class="text-[11px]" style="color:hsl(240 3.8% 46.1%)">{{ portalUser.email }}</div>
          </div>
          <span class="text-[10px] font-bold px-2.5 py-1 rounded-full text-white" style="background:hsl(142 72% 29%)">Aktiv</span>
        </div>

        <!-- No portal, no owner -->
        <p v-else-if="!hasOwner" class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">
          Zuerst einen Eigentümer zuweisen, um einen Portalzugang zu erstellen.
        </p>

        <!-- No portal, has owner -->
        <div v-else class="space-y-3">
          <div v-if="!showPortalForm" class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 bg-zinc-200">
              <Key class="w-4 h-4 text-zinc-500" />
            </div>
            <div class="flex-1">
              <div class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">Kein Portalzugang</div>
              <div class="text-[11px]" style="color:hsl(240 3.8% 46.1%)">{{ ownerData.owner_email }}</div>
            </div>
            <Button size="sm" @click="showPortalForm = true">Zugang erstellen</Button>
          </div>

          <!-- Create portal form -->
          <div v-else class="p-3.5 rounded-lg space-y-2.5" style="background:hsl(240 4.8% 95.9%);border:1px solid hsl(240 5.9% 94%)">
            <div class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">
              Login: <span class="font-medium" style="color:hsl(240 10% 3.9%)">{{ ownerData.owner_email }}</span>
            </div>
            <Input v-model="portalForm.password" type="text" placeholder="Initiales Passwort vergeben" class="h-8 text-[12px]" />
            <div v-if="portalError" class="text-[11px] text-red-500">{{ portalError }}</div>
            <div v-if="portalSuccess" class="text-[11px]" style="color:hsl(142 72% 29%)">{{ portalSuccess }}</div>
            <div class="flex gap-2">
              <Button variant="outline" size="sm" @click="showPortalForm = false; portalError = ''">Abbrechen</Button>
              <Button size="sm" :disabled="portalCreating || !portalForm.password" @click="createPortalAccess">
                {{ portalCreating ? 'Wird erstellt...' : 'Zugang erstellen' }}
              </Button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Hierarchie & Projekt ── -->
    <div v-if="!property.parent_id">
      <div class="text-[13px] font-semibold mb-2.5 flex items-center gap-2" style="color:hsl(240 10% 3.9%)">
        Hierarchie & Projekt
        <Badge class="text-[10px] px-2 py-0" style="background:hsl(142 76% 96%);color:hsl(142 72% 29%);border:1px solid hsl(142 76% 85%)">Master-Objekt</Badge>
      </div>
      <div class="rounded-lg p-4 space-y-4" style="border:1px solid hsl(240 5.9% 94%)">

        <!-- Children list -->
        <div v-if="property.children?.length">
          <div class="text-[11px] font-medium mb-1.5" style="color:hsl(240 3.8% 46.1%)">{{ property.children.length }} Unterobjekt(e)</div>
          <div class="space-y-1">
            <div v-for="child in property.children" :key="child.id"
              class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-zinc-50 transition-colors" style="font-size:12px">
              <ChevronRight class="w-3 h-3 text-indigo-400 flex-shrink-0" />
              <span class="flex-1 truncate" style="color:hsl(240 10% 3.9%)">{{ child.project_name || child.address || 'Unterobjekt #' + child.id }}</span>
              <span v-if="child.purchase_price" class="text-[11px] tabular-nums flex-shrink-0" style="color:hsl(240 3.8% 46.1%)">{{ formatPrice(child.purchase_price) }}</span>
            </div>
          </div>
        </div>
        <p v-else class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">Keine Unterobjekte zugeordnet.</p>

        <!-- Project group -->
        <div v-if="property.project_group_id" class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">
          Projektgruppe: <span class="font-medium" style="color:hsl(240 10% 3.9%)">{{ projectGroups.find(g => g.id == property.project_group_id)?.name || '#' + property.project_group_id }}</span>
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap gap-2 pt-1">
          <Button variant="outline" size="sm" @click="openChildCreateModal">
            <Plus class="w-3.5 h-3.5 mr-1.5" /> Unterobjekt anlegen
          </Button>
          <Button variant="outline" size="sm" @click="projectGroupPopup = true">
            <Link2 class="w-3.5 h-3.5 mr-1.5" /> Projektgruppe verwalten
          </Button>
        </div>

      </div>
    </div>

    <!-- If child: show parent info -->
    <div v-if="property.parent_id">
      <div class="text-[13px] font-semibold mb-2.5 flex items-center gap-2" style="color:hsl(240 10% 3.9%)">
        Hierarchie
        <Badge variant="outline" class="text-[10px] px-2 py-0">Kind-Objekt</Badge>
      </div>
      <div class="rounded-lg p-4 flex items-center gap-3" style="border:1px solid hsl(240 5.9% 94%);background:hsl(263 70% 99%)">
        <ArrowLeft class="w-4 h-4 flex-shrink-0 text-indigo-500" />
        <div>
          <div class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">Gehört zu</div>
          <div class="text-[13px] font-semibold" style="color:hsl(240 10% 3.9%)">{{ property.parent_name || 'Hauptobjekt #' + property.parent_id }}</div>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Projektgruppe Popup ── -->
  <Teleport to="body">
    <div v-if="projectGroupPopup" class="fixed inset-0 z-[320] flex items-center justify-center bg-black/40" @click.self="projectGroupPopup = false">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden" style="border:1px solid hsl(240 5.9% 94%)">
        <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid hsl(240 5.9% 94%)">
          <h3 class="text-[14px] font-semibold" style="color:hsl(240 10% 3.9%)">Projektgruppe</h3>
          <button @click="projectGroupPopup = false" class="w-7 h-7 rounded-lg hover:bg-zinc-100 flex items-center justify-center">
            <X class="w-4 h-4 text-zinc-400" />
          </button>
        </div>
        <div class="px-6 py-5 space-y-4">
          <p class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">Mehrere Objekte im Kundenportal unter einem Projektnamen zusammenfassen.</p>
          <div>
            <label class="block text-[11px] font-medium mb-1.5" style="color:hsl(240 3.8% 46.1%)">Projektgruppe zuweisen</label>
            <select :value="property.project_group_id || ''"
              @change="assignProjectGroup($event.target.value ? Number($event.target.value) : null)"
              class="w-full px-3 py-2.5 rounded-xl text-[13px] focus:outline-none focus:ring-2" style="background:hsl(240 4.8% 95.9%);border:1px solid hsl(240 5.9% 94%);color:hsl(240 10% 3.9%)">
              <option value="">– Keine Gruppe –</option>
              <option v-for="g in projectGroups.filter(x => !property.customer_id || !x.customer_id || x.customer_id == property.customer_id)" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
          <div v-if="!showNewGroupForm">
            <button @click="showNewGroupForm = true"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium rounded-lg hover:bg-teal-100 transition-all"
              style="color:hsl(168 72% 29%);background:hsl(168 72% 96%);border:1px solid hsl(168 72% 85%)">
              <Plus class="w-3.5 h-3.5" /> Neue Gruppe erstellen
            </button>
          </div>
          <div v-if="showNewGroupForm" class="rounded-xl p-4 space-y-3" style="border:1px solid hsl(168 72% 85%);background:hsl(168 72% 97%)">
            <Input v-model="newGroupName" placeholder="Gruppenname (z.B. Eggelsberg Wohnkultur)" class="h-8 text-[12px]" />
            <Input v-model="newGroupDesc" placeholder="Beschreibung (optional)" class="h-8 text-[12px]" />
            <div class="flex gap-2">
              <Button size="sm" @click="createAndAssignGroup">Erstellen & Zuweisen</Button>
              <Button variant="outline" size="sm" @click="showNewGroupForm = false">Abbrechen</Button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Child Create Modal ── -->
    <div v-if="childCreateModal" class="fixed inset-0 z-[320] flex items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px)">
      <div class="relative w-[480px] max-w-[calc(100vw-2rem)] rounded-2xl shadow-2xl overflow-hidden bg-white" style="border:1px solid hsl(240 5.9% 94%)">
        <div class="flex items-center justify-between px-5 py-4" style="border-bottom:1px solid hsl(240 5.9% 94%)">
          <div>
            <div class="text-[14px] font-semibold" style="color:hsl(240 10% 3.9%)">Unterobjekte anlegen</div>
            <div class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">Kategorien aus Einheiten oder manuell</div>
          </div>
          <button @click="childCreateModal = false" class="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-zinc-100">
            <X class="w-4 h-4 text-zinc-400" />
          </button>
        </div>

        <!-- Mode toggle -->
        <div class="flex gap-1 mx-5 mt-3 p-0.5 rounded-lg" style="background:hsl(240 4.8% 95.9%)">
          <button @click="childMode = 'categories'" class="flex-1 px-3 py-1.5 text-[11px] font-medium rounded-md transition-all"
            :style="childMode === 'categories' ? 'background:white;color:hsl(240 10% 3.9%);box-shadow:0 1px 2px rgba(0,0,0,0.06)' : 'color:hsl(240 3.8% 46.1%)'">
            Aus Einheiten
          </button>
          <button @click="childMode = 'manual'" class="flex-1 px-3 py-1.5 text-[11px] font-medium rounded-md transition-all"
            :style="childMode === 'manual' ? 'background:white;color:hsl(240 10% 3.9%);box-shadow:0 1px 2px rgba(0,0,0,0.06)' : 'color:hsl(240 3.8% 46.1%)'">
            Manuell
          </button>
        </div>

        <!-- Categories mode -->
        <div v-if="childMode === 'categories'" class="px-5 py-4">
          <div v-if="childCategoriesLoading" class="flex items-center justify-center py-8">
            <div class="w-5 h-5 border-2 border-zinc-300 border-t-indigo-500 rounded-full animate-spin"></div>
          </div>
          <div v-else-if="!childCategories.length" class="text-center py-6">
            <div class="text-[12px]" style="color:hsl(240 3.8% 46.1%)">Keine Einheiten mit Zimmerzahl gefunden</div>
            <div class="text-[11px] mt-1" style="color:hsl(240 3.8% 46.1%)">Erstelle zuerst Einheiten im Master-Objekt</div>
          </div>
          <div v-else class="space-y-2">
            <div v-for="cat in childCategories" :key="cat.rooms"
              @click="toggleCategory(cat.rooms)"
              class="flex items-center gap-3 px-3 py-2.5 rounded-xl cursor-pointer transition-all"
              :style="childSelected.has(cat.rooms) ? 'background:rgba(99,102,241,0.06);border:1.5px solid #6366f1' : 'background:hsl(240 4.8% 95.9%);border:1.5px solid hsl(240 5.9% 94%)'">
              <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 transition-all"
                :style="childSelected.has(cat.rooms) ? 'background:#6366f1' : 'background:white;border:1.5px solid hsl(240 5.9% 94%)'">
                <Check v-if="childSelected.has(cat.rooms)" class="w-3 h-3 text-white" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-[13px] font-medium" style="color:hsl(240 10% 3.9%)">{{ Math.floor(cat.rooms) }}-Zimmer</span>
                  <span class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:hsl(142 76% 96%);color:hsl(142 72% 29%)">{{ cat.unit_count }} Einheiten</span>
                  <span v-if="cat.frei > 0" class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:hsl(217 91% 96%);color:hsl(217 91% 40%)">{{ cat.frei }} frei</span>
                </div>
                <div class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
                  ab {{ formatPrice(cat.min_price) }}
                  <template v-if="cat.min_price != cat.max_price"> bis {{ formatPrice(cat.max_price) }}</template>
                  &middot; {{ cat.min_area }}{{ cat.min_area != cat.max_area ? ' – ' + cat.max_area : '' }} m²
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Manual mode -->
        <div v-if="childMode === 'manual'" class="px-5 py-4 space-y-3">
          <div>
            <label class="block text-[11px] font-medium mb-1" style="color:hsl(240 3.8% 46.1%)">Titel *</label>
            <Input v-model="childManualTitle" placeholder="z.B. Penthouse-Wohnungen" class="h-8 text-[12px]" @keydown.enter="createChildManual" />
          </div>
          <div class="text-[11px] rounded-lg px-3 py-2" style="background:hsl(263 70% 96%);color:hsl(263 70% 50%);border:1px solid hsl(263 70% 88%)">
            Basisdaten werden vom Master übernommen.
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-2 px-5 py-3" style="border-top:1px solid hsl(240 5.9% 94%);background:hsl(240 4.8% 95.9%)">
          <Button variant="outline" size="sm" @click="childCreateModal = false">Abbrechen</Button>
          <Button v-if="childMode === 'categories'"
            size="sm"
            :disabled="!childSelected.size || childCreateLoading"
            @click="createChildrenFromCategories">
            <span v-if="childCreateLoading" class="flex items-center gap-1.5">
              <span class="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              Erstelle...
            </span>
            <span v-else>{{ childSelected.size }} Kategorie{{ childSelected.size !== 1 ? 'n' : '' }} erstellen</span>
          </Button>
          <Button v-else
            size="sm"
            :disabled="!childManualTitle.trim() || childCreateLoading"
            @click="createChildManual">
            <span v-if="childCreateLoading" class="flex items-center gap-1.5">
              <span class="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              Erstelle...
            </span>
            <span v-else>Erstellen</span>
          </Button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
