<script setup>
import { ref, computed, inject, watch } from "vue";
import { Pause, Play, ArrowLeft } from "lucide-vue-next";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from "@/components/ui/breadcrumb";
import OverviewTab from '@/Components/Admin/property-detail/OverviewTab.vue';
import EditTab from '@/Components/Admin/property-detail/EditTab.vue';
import UnitsTab from '@/Components/Admin/property-detail/UnitsTab.vue';
import OffersTab from '@/Components/Admin/property-detail/OffersTab.vue';
import MediaTab from '@/Components/Admin/property-detail/MediaTab.vue';

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

const activeTab = ref("uebersicht");

const isNewbuild = computed(() => props.property?.property_category === 'newbuild');
const isChild = computed(() => !!props.property?.parent_id);
const isMaster = computed(() => isNewbuild.value && !isChild.value);

const tabs = computed(() => {
  const t = [
    { value: 'uebersicht', label: 'Übersicht' },
    { value: 'bearbeiten', label: 'Bearbeiten' },
  ];
  if (isMaster.value) t.push({ value: 'einheiten', label: 'Einheiten' });
  t.push({ value: 'kaufanbote', label: 'Kaufanbote' });
  t.push({ value: 'medien', label: 'Medien & Texte' });
  t.push({ value: 'portale', label: 'Portale' });
  if (!isChild.value) {
    t.push({ value: 'wissen', label: 'Wissen' });
    t.push({ value: 'dateien', label: 'Dateien' });
  }
  t.push({ value: 'aktivitaeten', label: 'Aktivitäten' });
  return t;
});

const editableTabs = ['bearbeiten', 'medien', 'portale'];
const showFooter = computed(() => editableTabs.includes(activeTab.value));

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
  if (isDirty.value && !confirm('Ungespeicherte Änderungen verwerfen?')) return;
  emit('back');
}

function handleToggleOnHold() {
  emit('toggleOnHold');
}

function markDirty() { isDirty.value = true; }
function markClean() { isDirty.value = false; }

const editTabRef = ref(null);
const mediaTabRef = ref(null);
const portalsTabRef = ref(null);

async function handleSave() {
  if (activeTab.value === 'bearbeiten' && editTabRef.value?.save) {
    await editTabRef.value.save();
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
  }
  isDirty.value = false;
}

const showExposeParser = ref(false);
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Breadcrumb topbar -->
    <div class="h-12 border-b border-border flex items-center px-6 shrink-0">
      <Breadcrumb>
        <BreadcrumbList>
          <BreadcrumbItem>
            <BreadcrumbLink class="cursor-pointer" @click="handleBack">Objekte</BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage>{{ title }}</BreadcrumbPage>
          </BreadcrumbItem>
        </BreadcrumbList>
      </Breadcrumb>
    </div>

    <!-- Detail Header -->
    <div class="px-6 py-4 border-b border-border flex items-center justify-between shrink-0">
      <div class="flex items-center gap-3.5">
        <div class="w-[52px] h-10 rounded-md bg-gradient-to-br from-blue-200 to-indigo-200 shrink-0" />
        <div>
          <div class="text-[17px] font-semibold">{{ title }}</div>
          <div class="text-xs text-muted-foreground mt-0.5">{{ subtitle }}</div>
        </div>
        <Badge v-if="!isNew" :variant="property.on_hold ? 'outline' : 'default'"
          :class="property.on_hold ? 'border-amber-500 text-amber-600' : 'bg-emerald-100 text-emerald-700 border-emerald-200'"
          class="ml-2 text-[11px]">
          {{ property.on_hold ? 'Pausiert' : 'Aktiv' }}
        </Badge>
      </div>
      <div class="flex items-center gap-2">
        <Button v-if="!isNew" variant="outline" size="sm" @click="handleToggleOnHold">
          <component :is="property.on_hold ? Play : Pause" class="w-3.5 h-3.5 mr-1.5" />
          {{ property.on_hold ? 'Aktivieren' : 'Pausieren' }}
        </Button>
        <Button variant="outline" size="sm" @click="handleBack">
          <ArrowLeft class="w-3.5 h-3.5 mr-1.5" />
          Zurück
        </Button>
      </div>
    </div>

    <!-- Tab Bar -->
    <Tabs v-model="activeTab" class="flex-1 flex flex-col min-h-0">
      <TabsList class="w-full justify-start rounded-none border-b border-border bg-transparent h-auto p-0 px-6 shrink-0 overflow-x-auto">
        <TabsTrigger
          v-for="tab in tabs" :key="tab.value" :value="tab.value"
          class="rounded-none border-b-2 border-transparent data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none px-4 py-2.5 text-[13px]"
        >
          {{ tab.label }}
        </TabsTrigger>
      </TabsList>

      <div class="flex-1 overflow-y-auto p-6">
        <OverviewTab v-if="activeTab === 'uebersicht'" :property="property"
          @owner-changed="(data) => emit('ownerChanged', data)"
          @property-created="(data) => emit('propertyCreated', data)" />
        <EditTab v-else-if="activeTab === 'bearbeiten'" ref="editTabRef" :property="property" :is-new="isNew" @dirty="isDirty = true" @saved="(p) => { isDirty = false; emit('saved', p); }" />
        <UnitsTab v-else-if="activeTab === 'einheiten'" :property="property" />
        <OffersTab v-else-if="activeTab === 'kaufanbote'" :property="property" />
        <MediaTab v-else-if="activeTab === 'medien'" ref="mediaTabRef" :property="property" @dirty="isDirty = true" />
        <div v-else class="text-muted-foreground text-sm">Tab: {{ activeTab }}</div>
      </div>
    </Tabs>

    <!-- Sticky Footer (only for editable tabs) -->
    <div v-if="showFooter" class="border-t border-border px-6 py-3 flex items-center justify-between shrink-0 bg-background">
      <Button variant="outline" size="sm" @click="showExposeParser = !showExposeParser">
        Exposé auslesen
      </Button>
      <div class="flex gap-2">
        <Button variant="outline" size="sm" @click="handleDiscard">Verwerfen</Button>
        <Button size="sm" @click="handleSave">Speichern</Button>
      </div>
    </div>
  </div>
</template>
