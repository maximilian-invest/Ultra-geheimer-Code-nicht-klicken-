<script setup>
import { ref, computed, inject, watch, onMounted } from "vue";
import { Pause, Play, ArrowLeft, CircleOff, Power, Trash2 } from "lucide-vue-next";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from "@/components/ui/breadcrumb";
import OverviewTab from '@/Components/Admin/property-detail/OverviewTab.vue';
import EditTab from '@/Components/Admin/property-detail/EditTab.vue';
import UnitsTab from '@/Components/Admin/property-detail/UnitsTab.vue';
import OffersTab from '@/Components/Admin/property-detail/OffersTab.vue';
import MediaTab from '@/Components/Admin/property-detail/MediaTab.vue';
import PortalsTab from "@/Components/Admin/property-detail/PortalsTab.vue";
import KnowledgeTab from "@/Components/Admin/property-detail/KnowledgeTab.vue";
import FilesTab from "@/Components/Admin/property-detail/FilesTab.vue";
import ActivityTab from "@/Components/Admin/property-detail/ActivityTab.vue";
import PropertyLinksTab from '@/Components/Admin/Property/PropertyLinksTab.vue';
import ExposeParser from '@/Components/Admin/property-detail/ExposeParser.vue';
import TypeSelector from '@/Components/Admin/property-detail/TypeSelector.vue';

const props = defineProps({
  property: { type: Object, required: true },
  isNew: { type: Boolean, default: false },
});

const emit = defineEmits([
  "back",
  "toggleOnHold",
  "deleteProperty",
  "propertyCreated",
  "ownerChanged",
  "saved",
  "switchTab",
]);

const API = inject("API");
const toast = inject("toast");
const switchTabFn = inject("switchTab", null);

const activeTab = ref(localStorage.getItem("sr-property-tab") || "uebersicht");
const tabChangeGuardActive = ref(false);
const pendingTabChange = ref(null);
const showUnsavedChangesDialog = ref(false);

const isNewbuild = computed(() => props.property?.property_category === 'newbuild');

// The property list feeds us a partial row (only the columns the list SELECTs).
// Fetch the full row on open so Übersicht and Bearbeiten see every field —
// and so the owner contact info reflects the current customers.* values
// rather than whatever was cached on the properties row at list time.
async function refreshFullProperty() {
  const id = props.property?.id;
  if (!id) return;
  try {
    const r = await fetch(API.value + '&action=get_property&property_id=' + id);
    const d = await r.json();
    if (d && d.property) {
      Object.assign(props.property, d.property);
    }
  } catch (e) { /* silent — existing partial data stays */ }
}

onMounted(refreshFullProperty);
watch(() => props.property?.id, refreshFullProperty);

const tabs = computed(() => {
  const t = [
    { value: 'uebersicht', label: 'Übersicht' },
    { value: 'bearbeiten', label: 'Bearbeiten' },
  ];
  if (isNewbuild.value) t.push({ value: 'einheiten', label: 'Einheiten' });
  t.push({ value: 'kaufanbote', label: 'Kaufanbote' });
  t.push({ value: 'portale', label: 'Portale' });
  t.push({ value: 'wissen', label: 'Wissen' });
  t.push({ value: 'dateien', label: 'Dateien' });
  t.push({ value: 'links', label: 'Links' });
  t.push({ value: 'aktivitaeten', label: 'Aktivitäten' });
  return t;
});

const editableTabs = ['bearbeiten', 'einheiten', 'portale'];
const showTypeSelector = computed(() => props.isNew && !props.property.object_type && !props.property.type);
const showFooter = computed(() => !showTypeSelector.value && editableTabs.includes(activeTab.value));

const title = computed(() => {
  if (props.isNew) return 'Neues Objekt';
  return props.property?.project_name || props.property?.address || 'Objekt';
});

const subtitle = computed(() => {
  const p = props.property;
  if (!p || props.isNew) return '';
  const parts = [p.ref_id, p.object_type];
  if (isNewbuild.value && p.unit_count) parts.push(p.unit_count + ' Einheiten');
  if (p.broker_name) parts.push(p.broker_name);
  return parts.filter(Boolean).join(' · ');
});

if (props.isNew) activeTab.value = 'bearbeiten';

const isDirty = ref(false);

function handleBack() {
  if (isDirty.value) {
    handleDiscard();
  }
  emit('back');
}

const statusMenuOpen = ref(false);

function getStatusInfo() {
  const p = props.property;
  if (p.realty_status === 'inaktiv') return { label: 'Inaktiv', class: 'border-red-400 text-red-500', variant: 'outline' };
  if (p.realty_status === 'verkauft') return { label: 'Verkauft', class: 'bg-blue-100 text-blue-700 border-blue-200', variant: 'default' };
  if (p.on_hold) return { label: 'Pausiert', class: 'border-amber-500 text-amber-600', variant: 'outline' };
  return { label: 'Aktiv', class: 'bg-emerald-100 text-emerald-700 border-emerald-200', variant: 'default' };
}

const statusInfo = computed(() => getStatusInfo());

async function setPropertyStatus(action) {
  statusMenuOpen.value = false;
  if (!props.property?.id) return;
  try {
    let apiAction, body;
    if (action === 'inaktiv') {
      apiAction = 'set_inactive';
      body = { property_id: props.property.id };
    } else if (action === 'pausieren') {
      apiAction = 'set_on_hold';
      body = { property_id: props.property.id, on_hold: 1, reason: '' };
    } else if (action === 'aktivieren') {
      // Reactivate from inactive or unpause
      if (props.property.realty_status === 'inaktiv') {
        apiAction = 'reactivate_property';
        body = { property_id: props.property.id, realty_status: 'auftrag' };
      } else {
        apiAction = 'set_on_hold';
        body = { property_id: props.property.id, on_hold: 0 };
      }
    }
    const r = await fetch(API.value + '&action=' + apiAction, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const d = await r.json();
    if (d.success) {
      if (action === 'inaktiv') {
        props.property.realty_status = 'inaktiv';
        props.property.on_hold = false;
      } else if (action === 'pausieren') {
        props.property.on_hold = true;
      } else if (action === 'aktivieren') {
        props.property.realty_status = 'auftrag';
        props.property.on_hold = false;
      }
      toast(d.message || 'Status aktualisiert');
    } else {
      toast(d.message || 'Fehler');
    }
  } catch (e) { toast('Fehler: ' + e.message); }
}

function markDirty() { isDirty.value = true; }
function markClean() { isDirty.value = false; }

const editTabRef = ref(null);
const mediaTabRef = ref(null);
const unitsTabRef = ref(null);
const portalsTabRef = ref(null);

async function handleSave() {
  if (activeTab.value === 'bearbeiten' && editTabRef.value?.save) {
    await editTabRef.value.save();
  } else if (activeTab.value === 'einheiten' && unitsTabRef.value?.save) {
    await unitsTabRef.value.save();
  } else if (activeTab.value === 'medien' && mediaTabRef.value?.save) {
    await mediaTabRef.value.save();
  } else if (activeTab.value === 'portale' && portalsTabRef.value?.save) {
    await portalsTabRef.value.save();
  }
  isDirty.value = false;
}

function handleDiscard() {
  if (activeTab.value === 'bearbeiten' && editTabRef.value?.discard) {
    editTabRef.value.discard();
  } else if (activeTab.value === 'einheiten' && unitsTabRef.value?.discard) {
    unitsTabRef.value.discard();
  }
  isDirty.value = false;
}

async function confirmSaveAndSwitch() {
  const targetTab = pendingTabChange.value;
  showUnsavedChangesDialog.value = false;
  pendingTabChange.value = null;
  await handleSave();
  if (targetTab) {
    tabChangeGuardActive.value = true;
    activeTab.value = targetTab;
    tabChangeGuardActive.value = false;
    localStorage.setItem("sr-property-tab", targetTab);
  }
}

function confirmDiscardAndSwitch() {
  const targetTab = pendingTabChange.value;
  showUnsavedChangesDialog.value = false;
  pendingTabChange.value = null;
  handleDiscard();
  if (targetTab) {
    tabChangeGuardActive.value = true;
    activeTab.value = targetTab;
    tabChangeGuardActive.value = false;
    localStorage.setItem("sr-property-tab", targetTab);
  }
}

function cancelTabChange() {
  showUnsavedChangesDialog.value = false;
  pendingTabChange.value = null;
}

watch(activeTab, async (newTab, oldTab) => {
  if (tabChangeGuardActive.value) {
    localStorage.setItem("sr-property-tab", newTab);
    return;
  }

  if (oldTab === 'bearbeiten' && newTab !== oldTab && isDirty.value) {
    tabChangeGuardActive.value = true;
    pendingTabChange.value = newTab;
    activeTab.value = oldTab;
    tabChangeGuardActive.value = false;
    showUnsavedChangesDialog.value = true;
    return;
  }

  localStorage.setItem("sr-property-tab", newTab);
});

const showExposeParser = ref(false);
const creatingFromType = ref(false);

async function handleTypeSelected(typeInfo) {
  if (creatingFromType.value) return;
  props.property.type = typeInfo.type;
  props.property.object_type = typeInfo.type;
  props.property.property_category = typeInfo.category;
  activeTab.value = 'bearbeiten';

  if (!props.isNew) return;

  creatingFromType.value = true;
  try {
    const payload = { ...props.property };
    const r = await fetch(API.value + "&action=save_full_property", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success && d.property?.id) {
      Object.assign(props.property, d.property);
      isDirty.value = false;
      emit('saved', d.property);
      emit('propertyCreated', d.property);
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  } finally {
    creatingFromType.value = false;
  }
}

function handleExposeParsed(result) {
  if (result.fields) {
    for (const [k, v] of Object.entries(result.fields)) {
      if (v !== null && v !== undefined && v !== '') {
        props.property[k] = v;
      }
    }
  }
  showExposeParser.value = false;
  toast('Felder aktualisiert!');
}
</script>

<template>
  <div class="flex flex-col h-full">
    <Dialog :open="showUnsavedChangesDialog" @update:open="(open) => { if (!open) cancelTabChange(); }">
      <DialogContent class="max-w-md">
        <DialogHeader>
          <DialogTitle>Ungespeicherte Änderungen</DialogTitle>
          <DialogDescription>
            Du hast im Tab `Bearbeiten` noch nicht gespeicherte Änderungen. Möchtest du sie speichern, bevor du den Tab wechselst?
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2 sm:justify-end">
          <Button variant="outline" @click="cancelTabChange">Abbrechen</Button>
          <Button variant="outline" @click="confirmDiscardAndSwitch">Verwerfen</Button>
          <Button class="bg-zinc-900 text-white hover:bg-zinc-800" @click="confirmSaveAndSwitch">Speichern</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <template v-if="showTypeSelector">
      <div class="px-6 py-3 flex items-center justify-between shrink-0" style="border-bottom:1px solid hsl(240 5.9% 90%)">
        <div>
          <div class="text-[17px] font-semibold">Neues Objekt</div>
          <div class="text-xs text-muted-foreground mt-0.5">Wähle zuerst aus, was du anlegen möchtest.</div>
        </div>
        <Button variant="outline" size="sm" @click="handleBack">
          <ArrowLeft class="w-3.5 h-3.5 mr-1.5" />
          Zurück
        </Button>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-8">
        <TypeSelector :loading="creatingFromType" @selected="handleTypeSelected" />
      </div>
    </template>

    <template v-else>
    <!-- Detail Header -->
    <div class="px-6 py-3 flex items-center justify-between shrink-0" style="border-bottom:1px solid hsl(240 5.9% 90%)">
      <div class="flex items-center gap-3.5">
        <img v-if="property.thumbnail_url" :src="property.thumbnail_url" class="w-[52px] h-10 rounded-md object-cover shrink-0" />
        <div v-else class="w-[52px] h-10 rounded-md bg-gradient-to-br from-blue-200 to-indigo-200 shrink-0" />
        <div>
          <div class="flex items-center gap-2">
            <span class="text-[17px] font-semibold">{{ title }}</span>
            <Badge v-if="!isNew" :variant="statusInfo.variant"
              :class="statusInfo.class"
              class="text-[11px]">
              {{ statusInfo.label }}
            </Badge>
          </div>
          <div class="text-xs text-muted-foreground mt-0.5">{{ subtitle }}</div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <div v-if="!isNew" class="relative">
          <Button variant="outline" size="sm" @click="statusMenuOpen = !statusMenuOpen">
            <component :is="property.on_hold || property.realty_status === 'inaktiv' ? Play : Pause" class="w-3.5 h-3.5 mr-1.5" />
            Status ändern
          </Button>
          <div v-if="statusMenuOpen" class="absolute right-0 top-full mt-1 bg-background rounded-lg shadow-lg py-1 z-50 min-w-[160px]" style="border:1px solid hsl(240 5.9% 90%)">
            <button v-if="property.on_hold || property.realty_status === 'inaktiv'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-muted/50 flex items-center gap-2" @click="setPropertyStatus('aktivieren')">
              <Power class="w-3.5 h-3.5 text-emerald-600" /> Aktivieren
            </button>
            <button v-if="!property.on_hold && property.realty_status !== 'inaktiv'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-muted/50 flex items-center gap-2" @click="setPropertyStatus('pausieren')">
              <Pause class="w-3.5 h-3.5 text-amber-500" /> Pausieren
            </button>
            <button v-if="property.realty_status !== 'inaktiv'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-muted/50 flex items-center gap-2" @click="setPropertyStatus('inaktiv')">
              <CircleOff class="w-3.5 h-3.5 text-red-500" /> Inaktiv setzen
            </button>
            <div class="my-1 mx-2" style="border-top:1px solid hsl(240 5.9% 90%)"></div>
            <button class="w-full text-left px-3 py-1.5 text-xs hover:bg-red-50 flex items-center gap-2 text-red-600" @click="statusMenuOpen = false; emit('deleteProperty')">
              <Trash2 class="w-3.5 h-3.5" /> Objekt loeschen
            </button>
          </div>
        </div>
        <Button variant="outline" size="sm" @click="handleBack">
          <ArrowLeft class="w-3.5 h-3.5 mr-1.5" />
          Zurück
        </Button>
      </div>
    </div>

    <!-- Tab Bar -->
    <Tabs v-model="activeTab" class="flex-1 flex flex-col min-h-0">
      <TabsList class="w-full justify-start rounded-none bg-transparent h-auto p-0 px-6 shrink-0 overflow-x-auto" style="border-bottom:1px solid hsl(240 5.9% 90%)">
        <TabsTrigger
          v-for="tab in tabs" :key="tab.value" :value="tab.value"
          class="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none px-4 py-2.5 text-[13px]"
        >
          {{ tab.label }}
        </TabsTrigger>
      </TabsList>

      <div class="flex-1 overflow-y-auto px-6 pb-6" :class="activeTab === 'bearbeiten' ? 'pt-0' : 'pt-6'">
        <TypeSelector v-if="showTypeSelector" :loading="creatingFromType" @selected="handleTypeSelected" />
        <OverviewTab v-if="activeTab === 'uebersicht'" :property="property"
          @owner-changed="(data) => emit('ownerChanged', data)"
          @property-created="(data) => emit('propertyCreated', data)" />
        <EditTab
          v-else-if="activeTab === 'bearbeiten'"
          ref="editTabRef"
          :property="property"
          :is-new="isNew"
          @dirty="isDirty = true"
          @clean="isDirty = false"
          @saved="(p) => { isDirty = false; emit('saved', p); }"
          @property-created="(p) => { isDirty = false; emit('propertyCreated', p); }"
        />
        <UnitsTab v-else-if="activeTab === 'einheiten'" ref="unitsTabRef" :property="property" />
        <OffersTab v-else-if="activeTab === 'kaufanbote'" :property="property" />
        <!-- Medien & Beschreibung sind jetzt Subtabs im Bearbeiten-Tab -->
        <PortalsTab v-else-if="activeTab === 'portale'" ref="portalsTabRef" :property="property" @dirty="isDirty = true" />
        <KnowledgeTab v-else-if="activeTab === 'wissen'" :property="property" />
        <FilesTab v-else-if="activeTab === 'dateien'" :property="property" />
        <PropertyLinksTab v-else-if="activeTab === 'links'" :property-id="property.id" />
        <ActivityTab v-else-if="activeTab === 'aktivitaeten'" :property="property" @open-activities="(id, addr) => { if (switchTabFn) switchTabFn('activities'); }" @open-messages="(id, addr) => { if (switchTabFn) switchTabFn('inbox'); }" />
        <div v-else class="text-muted-foreground text-sm">Tab: {{ activeTab }}</div>
      </div>
    </Tabs>

    <!-- Sticky Footer (only for editable tabs) -->
    <div v-if="showFooter" class="px-6 py-3 flex items-center justify-between shrink-0 bg-background" style="border-top:1px solid hsl(240 5.9% 90%)">
      <div></div>
      <div class="flex gap-2">
        <Button variant="outline" size="sm" @click="handleDiscard">Verwerfen</Button>
        <Button size="sm" class="bg-zinc-900 text-white hover:bg-zinc-800 border border-zinc-900 shadow-sm" @click="handleSave">Speichern</Button>
      </div>
    </div>

    <!-- Expose Parser Panel -->
    <ExposeParser v-if="showExposeParser && !showTypeSelector" :property="property" :visible="showExposeParser"
      @parsed="handleExposeParsed" @close="showExposeParser = false" />
    </template>
  </div>
</template>
