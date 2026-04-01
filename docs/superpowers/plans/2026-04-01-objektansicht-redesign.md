# Objektansicht Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 8-12 tile popup layout in PropertyDetailView.vue with a single 950px Dialog containing 3 tabs (Objekt, Aktivitäten, Kaufanbote) and Collapsible sections.

**Architecture:** Rewrite PropertyDetailView.vue as a shadcn Dialog with Tabs + Collapsible. All existing business logic (API calls, emits, state management) is preserved. The template is completely rewritten; the script section is refactored but functionally identical.

**Tech Stack:** Vue 3, shadcn-vue (Dialog, Tabs, Collapsible, Table, ScrollArea, Badge, Button, Input), Lucide icons, Tailwind CSS

---

### Task 1: Install shadcn Dialog component

**Files:**
- Create: `resources/js/components/ui/dialog/Dialog.vue`
- Create: `resources/js/components/ui/dialog/DialogContent.vue`
- Create: `resources/js/components/ui/dialog/DialogDescription.vue`
- Create: `resources/js/components/ui/dialog/DialogFooter.vue`
- Create: `resources/js/components/ui/dialog/DialogHeader.vue`
- Create: `resources/js/components/ui/dialog/DialogTitle.vue`
- Create: `resources/js/components/ui/dialog/DialogTrigger.vue`
- Create: `resources/js/components/ui/dialog/DialogScrollContent.vue`
- Create: `resources/js/components/ui/dialog/DialogClose.vue`
- Create: `resources/js/components/ui/dialog/index.js`

- [ ] **Step 1: Install Dialog via shadcn-vue CLI**

```bash
cd /var/www/srhomes && npx shadcn-vue@latest add dialog
```

If the CLI doesn't work, manually create the files using the shadcn-vue Dialog source from the reka-ui DialogRoot/DialogPortal/DialogOverlay/DialogContent primitives. The existing Sheet component in `resources/js/components/ui/sheet/` can serve as a reference for the pattern.

- [ ] **Step 2: Verify Dialog component files exist**

```bash
ls resources/js/components/ui/dialog/
```

Expected: Dialog.vue, DialogContent.vue, DialogHeader.vue, DialogTitle.vue, DialogDescription.vue, DialogFooter.vue, DialogTrigger.vue, DialogClose.vue, index.js

- [ ] **Step 3: Customize DialogContent max-width**

Edit `resources/js/components/ui/dialog/DialogContent.vue` — ensure the default variant supports a `class` prop so we can pass `max-w-[950px]` from the parent. The shadcn default is `sm:max-w-lg`, we need to override it.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/ui/dialog/
git commit -m "feat: install shadcn Dialog component for PropertyDetailView redesign"
```

### Task 2: Rewrite PropertyDetailView.vue — Script Section

**Files:**
- Modify: `resources/js/Components/Admin/PropertyDetailView.vue`

The script section keeps ALL existing business logic but is reorganized for the new template structure. No functional changes — just reorganization and adding a few new refs.

- [ ] **Step 1: Add new imports and refs**

Add at the top of `<script setup>`, alongside existing imports:

```javascript
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from '@/components/ui/collapsible';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  ChevronDown, ChevronUp, Home, Users, Building2, ParkingSquare,
  BookOpen, FileText, Clock, Plus, Link2, ClipboardList, MessageCircle,
  ShoppingCart, Pencil, X, MapPin, Pause, Play, Trash2, Upload, Search
} from 'lucide-vue-next';
```

Add new refs for the tab/collapsible state:

```javascript
// Tab + Collapsible state
const activeTab = ref('objekt');
const openSections = ref({
  objektdaten: true,
  eigentuemer: true,
  einheiten: true,
  stellplaetze: false,
  wissensdb: false,
  dateien: false,
  historie: false,
  unterobjekt: false,
  hierarchie: false,
  aktivitaeten: true,
  nachrichten: false,
});

// Units filter
const unitFilter = ref('alle'); // 'alle', 'frei', 'reserviert', 'verkauft'
const unitSearch = ref('');

const filteredUnits = computed(() => {
  if (!unitStats.value) return [];
  // unitStats doesn't contain actual units, we need to load them
  // This will be populated from the API response
  return allUnits.value
    .filter(u => !u.is_parking)
    .filter(u => unitFilter.value === 'alle' || u.status === unitFilter.value)
    .filter(u => !unitSearch.value || 
      String(u.unit_number || u.top_nr || '').toLowerCase().includes(unitSearch.value.toLowerCase()));
});

const allUnits = ref([]);
```

- [ ] **Step 2: Extend loadUnitStats to store individual units**

Modify the existing `loadUnitStats` function to also store the raw units array:

```javascript
async function loadUnitStats() {
  try {
    const r = await fetch(API.value + "&action=get_property_settings&property_id=" + props.property.id);
    const d = await r.json();
    if (d.units) {
      const units = d.units;
      allUnits.value = units; // Store raw units for table display
      const realUnits = units.filter(u => !u.is_parking);
      const parkingUnits = units.filter(u => u.is_parking);
      unitStats.value = {
        total: realUnits.length,
        frei: realUnits.filter(u => u.status === "frei").length,
        reserviert: realUnits.filter(u => u.status === "reserviert").length,
        verkauft: realUnits.filter(u => u.status === "verkauft").length,
        parking: parkingUnits.length,
        parkingFrei: parkingUnits.filter(u => u.status === "frei").length,
      };
    }
  } catch (e) {}
}
```

- [ ] **Step 3: Remove the `tiles` computed property and `iconMap`**

Delete the entire `tiles` computed (lines ~561-676) and `iconMap` object (lines ~678-681). These are no longer needed — each section is now its own Collapsible with inline logic.

- [ ] **Step 4: Remove old body scroll lock logic**

In the `watch(() => props.visible, ...)` watcher, remove the manual `document.body.style.overflow` and `querySelectorAll` logic. The shadcn Dialog handles scroll locking via the reka-ui DialogOverlay automatically.

Keep the rest of the watcher logic (state reset, loadPortalAccess, loadCustomersList, loadUnitStats, loadProjectGroups).

- [ ] **Step 5: Verify no functional logic was lost**

Check that all these functions still exist and are unchanged:
- `openChildCreateModal`, `toggleCategory`, `createChildrenFromCategories`, `createChildManual`
- `openHistory`, `historyAddEntry`, `historyDeleteEntry`, `saveHistory`
- `loadPortalAccess`, `selectExistingOwner`, `createNewOwner`, `unlinkCustomer`, `createPortalAccess`
- `loadExposeFiles`, `uploadExposeFiles`, `runExpose`, `applyExposeToKB`, `applyExposeToFields`
- `loadUnitStats`, `loadProjectGroups`, `assignProjectGroup`, `createAndAssignGroup`
- `formatPrice`

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Admin/PropertyDetailView.vue
git commit -m "refactor: reorganize PropertyDetailView script for tab/collapsible layout"
```

### Task 3: Rewrite PropertyDetailView.vue — Template: Dialog + Header + Tabs

**Files:**
- Modify: `resources/js/Components/Admin/PropertyDetailView.vue`

Replace the entire `<template>` section. This task covers the Dialog wrapper, header, and tab structure.

- [ ] **Step 1: Replace the template with Dialog + Header + Tabs skeleton**

Delete everything inside `<template>` and replace with:

```vue
<template>
  <Dialog :open="visible" @update:open="val => { if (!val) $emit('close') }">
    <DialogContent class="max-w-[950px] p-0 gap-0 max-h-[92vh] flex flex-col overflow-hidden">
      
      <!-- Header -->
      <div class="flex items-start justify-between px-6 pt-5 pb-4 border-b border-border flex-shrink-0">
        <div class="flex items-center gap-3.5">
          <div class="w-11 h-11 bg-orange-50 rounded-lg flex items-center justify-center flex-shrink-0">
            <Home class="w-5 h-5 text-orange-600" />
          </div>
          <div class="min-w-0">
            <DialogTitle class="text-lg font-bold text-foreground tracking-tight truncate">
              {{ property?.project_name || property?.address }}
            </DialogTitle>
            <DialogDescription class="text-sm text-muted-foreground mt-0.5">
              {{ property?.city }}{{ property?.zip ? ', ' + property.zip : '' }}
              <template v-if="property?.property_category">
                &bull; {{ property.property_category === 'newbuild' ? 'Neubauprojekt' : 'Bestandsobjekt' }}
              </template>
              <template v-if="property?.children?.length">
                &bull; {{ property.children.length }} Einheiten
              </template>
            </DialogDescription>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <Badge v-if="property?.realty_status" :class="[
            property.realty_status === 'verkauft' ? 'bg-red-50 text-red-700 border-red-200' :
            property.realty_status === 'aktiv' || property.realty_status === 'auftrag' ? 'bg-green-50 text-green-700 border-green-200' :
            'bg-orange-50 text-orange-700 border-orange-200'
          ]" variant="outline">{{ property.realty_status }}</Badge>
          <Badge v-if="property?.on_hold" variant="outline" class="bg-zinc-50 text-zinc-600 border-zinc-200">Pausiert</Badge>
          <Button variant="outline" size="sm" @click="$emit('openEditor', property?.id)">
            <Pencil class="w-3.5 h-3.5 mr-1.5" />
            Bearbeiten
          </Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" @click="$emit('toggleOnHold', property)" :title="property?.on_hold ? 'Aktivieren' : 'Pausieren'">
            <Play v-if="property?.on_hold" class="w-4 h-4 text-green-600" />
            <Pause v-else class="w-4 h-4 text-amber-600" />
          </Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" @click="$emit('deleteProperty', property)" title="Löschen">
            <Trash2 class="w-4 h-4 text-red-500" />
          </Button>
        </div>
      </div>

      <!-- Tabs -->
      <Tabs v-model="activeTab" class="flex-1 min-h-0 flex flex-col">
        <div class="px-6 pt-4 flex-shrink-0">
          <TabsList>
            <TabsTrigger value="objekt">Objekt</TabsTrigger>
            <TabsTrigger value="aktivitaeten">Aktivitäten</TabsTrigger>
            <TabsTrigger value="kaufanbote">Kaufanbote</TabsTrigger>
          </TabsList>
        </div>

        <!-- Tab: Objekt -->
        <TabsContent value="objekt" class="flex-1 min-h-0 mt-0 data-[state=inactive]:hidden">
          <ScrollArea class="h-full">
            <div class="px-6 py-4 space-y-2">
              <!-- KPIs + Collapsible sections go here (Task 4) -->
              <p class="text-muted-foreground text-sm">Objekt-Tab Inhalt wird in Task 4 implementiert.</p>
            </div>
          </ScrollArea>
        </TabsContent>

        <!-- Tab: Aktivitäten -->
        <TabsContent value="aktivitaeten" class="flex-1 min-h-0 mt-0 data-[state=inactive]:hidden">
          <ScrollArea class="h-full">
            <div class="px-6 py-4 space-y-2">
              <!-- Aktivitäten + Nachrichten sections go here (Task 5) -->
              <p class="text-muted-foreground text-sm">Aktivitäten-Tab Inhalt wird in Task 5 implementiert.</p>
            </div>
          </ScrollArea>
        </TabsContent>

        <!-- Tab: Kaufanbote -->
        <TabsContent value="kaufanbote" class="flex-1 min-h-0 mt-0 data-[state=inactive]:hidden">
          <ScrollArea class="h-full">
            <div class="px-6 py-4">
              <!-- Kaufanbote list goes here (Task 6) -->
              <p class="text-muted-foreground text-sm">Kaufanbote-Tab Inhalt wird in Task 6 implementiert.</p>
            </div>
          </ScrollArea>
        </TabsContent>
      </Tabs>

    </DialogContent>
  </Dialog>

  <!-- Keep existing sub-modals (expose file select, child create, etc.) -->
</template>
```

- [ ] **Step 2: Move existing sub-modal templates after the Dialog**

The existing template has several sub-modals (expose file select popup, child create modal, project groups popup). These should be kept as-is after the `</Dialog>` closing tag, still inside `<template>`. They are independent Teleport/overlay elements that work alongside the main Dialog.

Find and preserve these sections from the old template:
1. Expose file select popup (`exposeFileSelect`)
2. Child create modal (`childCreateModal`)
3. Project groups popup (`projectGroupPopup`)
4. History popup (`historyOpen`)
5. Portal/Owner popup (`portalPopupOpen`)

- [ ] **Step 3: Build and verify no errors**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -20
```

Expected: Build succeeds. The page should show the Dialog with header and 3 placeholder tabs.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Admin/PropertyDetailView.vue
git commit -m "feat: PropertyDetailView Dialog + Header + Tabs skeleton"
```

### Task 4: Tab "Objekt" — KPIs + Collapsible Sections

**Files:**
- Modify: `resources/js/Components/Admin/PropertyDetailView.vue`

Replace the placeholder in the Objekt TabsContent with KPIs and all Collapsible sections.

- [ ] **Step 1: Add KPI row**

Inside the Objekt TabsContent `<div class="px-6 py-4 space-y-2">`, replace placeholder with:

```vue
<!-- KPIs -->
<div class="grid grid-cols-5 gap-2.5 mb-4">
  <div class="p-3 bg-muted rounded-lg">
    <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Kaufpreis</div>
    <div class="text-base font-bold text-foreground tabular-nums">
      {{ property?.purchase_price ? Number(property.purchase_price).toLocaleString('de-DE') + ' €' : '–' }}
    </div>
  </div>
  <div class="p-3 bg-muted rounded-lg">
    <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Rendite</div>
    <div class="text-base font-bold text-foreground tabular-nums">
      {{ property?.yield_percent ? property.yield_percent + ' %' : '–' }}
    </div>
  </div>
  <div class="p-3 bg-muted rounded-lg">
    <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Fläche</div>
    <div class="text-base font-bold text-foreground tabular-nums">
      {{ property?.total_area ? property.total_area + ' m²' : '–' }}
    </div>
  </div>
  <div class="p-3 bg-muted rounded-lg">
    <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Einheiten</div>
    <div class="text-base font-bold text-foreground tabular-nums">
      {{ property?.children?.length || unitStats?.total || '–' }}
    </div>
  </div>
  <div class="p-3 bg-muted rounded-lg">
    <div class="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-0.5">Provision</div>
    <div class="text-base font-bold text-foreground tabular-nums">
      {{ property?.commission_percent ? property.commission_percent + ' %' : '–' }}
    </div>
  </div>
</div>
```

- [ ] **Step 2: Add Objektdaten Collapsible**

```vue
<!-- Objektdaten -->
<Collapsible v-model:open="openSections.objektdaten" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.objektdaten" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <Pencil class="w-3.5 h-3.5 text-foreground" />
      <span class="text-[13px] font-semibold text-foreground">Objektdaten</span>
    </div>
    <div class="flex items-center gap-1.5" @click.stop>
      <Button variant="ghost" size="sm" class="h-7 text-[10px] text-violet-600 hover:text-violet-700"
        :disabled="exposeLoading" @click="loadExposeFiles().then(() => { exposeFileSelect = true; exposeMode = 'fields'; })">
        <Sparkles class="w-3 h-3 mr-1" />
        {{ exposeLoading && exposeMode === 'fields' ? 'KI analysiert...' : 'KI auslesen' }}
      </Button>
      <Button variant="ghost" size="sm" class="h-7 text-[10px]" @click="$emit('openEditor', property?.id)">
        Bearbeiten
      </Button>
    </div>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <div class="grid grid-cols-3 gap-x-4 gap-y-1 text-[12px]" style="grid-template-columns: 130px 1fr 130px 1fr 130px 1fr;">
        <span class="text-muted-foreground">Baujahr</span><span class="text-foreground">{{ property?.construction_year || '–' }}</span>
        <span class="text-muted-foreground">Grundstück</span><span class="text-foreground">{{ property?.lot_size ? property.lot_size + ' m²' : '–' }}</span>
        <span class="text-muted-foreground">Zustand</span><span class="text-foreground">{{ property?.condition || '–' }}</span>
        <span class="text-muted-foreground">Heizung</span><span class="text-foreground">{{ property?.heating_type || '–' }}</span>
        <span class="text-muted-foreground">Aufzug</span><span class="text-foreground">{{ property?.elevator ? 'Ja' : 'Nein' }}</span>
        <span class="text-muted-foreground">HWB</span><span class="text-foreground">{{ property?.hwb || '–' }}</span>
        <span class="text-muted-foreground">Keller</span><span class="text-foreground">{{ property?.cellar ? 'Ja' : 'Nein' }}</span>
        <span class="text-muted-foreground">Stockwerke</span><span class="text-foreground">{{ property?.floors || '–' }}</span>
        <span class="text-muted-foreground">Zimmer</span><span class="text-foreground">{{ property?.rooms_amount || '–' }}</span>
      </div>
    </div>
  </CollapsibleContent>
</Collapsible>
```

- [ ] **Step 3: Add Eigentümer & Portal Collapsible**

```vue
<!-- Eigentümer & Portal (not for child properties) -->
<Collapsible v-if="!property?.parent_id" v-model:open="openSections.eigentuemer" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.eigentuemer" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <Users class="w-3.5 h-3.5 text-orange-600" />
      <span class="text-[13px] font-semibold text-foreground">Eigentümer & Portal</span>
    </div>
    <Badge v-if="portalUser" variant="outline" class="bg-green-50 text-green-700 border-green-200 text-[9px]">Zugang aktiv</Badge>
    <Badge v-else-if="ownerData.customer_id" variant="outline" class="text-[9px]">Kein Zugang</Badge>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <!-- Owner info or assignment UI -->
      <div v-if="ownerData.owner_name" class="grid grid-cols-3 gap-2">
        <div class="p-2.5 border border-border rounded-md">
          <div class="text-[12px] font-semibold text-foreground">{{ ownerData.owner_name }}</div>
          <div class="text-[11px] text-muted-foreground">{{ ownerData.owner_email || 'Keine E-Mail' }}</div>
          <div class="text-[11px] text-muted-foreground">{{ ownerData.owner_phone || 'Kein Telefon' }}</div>
        </div>
        <div v-if="portalUser" class="p-2.5 border border-border rounded-md">
          <div class="text-[12px] font-semibold text-foreground">Portal-Login</div>
          <div class="text-[11px] text-muted-foreground">{{ portalUser.email }}</div>
          <div class="text-[11px] text-muted-foreground">Aktiv seit {{ portalUser.created_at?.substring(0, 10) }}</div>
        </div>
      </div>
      <div v-else class="text-[12px] text-muted-foreground">
        Kein Eigentümer zugewiesen.
      </div>
      <Button variant="outline" size="sm" class="mt-2 text-[11px] h-7" @click="portalPopupOpen = true">
        {{ ownerData.owner_name ? 'Eigentümer verwalten' : 'Eigentümer zuweisen' }}
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>
```

- [ ] **Step 4: Add Einheiten Collapsible (newbuild only)**

```vue
<!-- Einheiten (newbuild only) -->
<Collapsible v-if="property?.property_category === 'newbuild' && !property?.parent_id"
  v-model:open="openSections.einheiten" class="border border-orange-200 rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.einheiten" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <Building2 class="w-3.5 h-3.5 text-orange-600" />
      <span class="text-[13px] font-semibold text-foreground">Einheiten</span>
      <Badge variant="outline" class="bg-orange-50 text-orange-700 border-orange-200 text-[8px]">Neubau</Badge>
    </div>
    <div class="flex gap-1" v-if="unitStats">
      <Badge variant="outline" class="bg-green-50 text-green-700 border-green-200 text-[9px]">{{ unitStats.frei }} frei</Badge>
      <Badge variant="outline" class="bg-orange-50 text-orange-700 border-orange-200 text-[9px]">{{ unitStats.reserviert }} res.</Badge>
      <Badge variant="outline" class="bg-red-50 text-red-700 border-red-200 text-[9px]">{{ unitStats.verkauft }} verk.</Badge>
    </div>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <!-- Filter row -->
      <div class="flex gap-2 mb-2.5">
        <select v-model="unitFilter" class="text-[11px] border border-border rounded-md px-2.5 py-1.5 bg-white text-muted-foreground">
          <option value="alle">Alle Status</option>
          <option value="frei">Frei</option>
          <option value="reserviert">Reserviert</option>
          <option value="verkauft">Verkauft</option>
        </select>
        <div class="flex-1 relative">
          <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="unitSearch" placeholder="Suche nach Top-Nr..." class="h-7 text-[11px] pl-8" />
        </div>
      </div>
      <!-- Table -->
      <ScrollArea class="max-h-[280px] border border-border rounded-md">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead class="text-[10px]">Top</TableHead>
              <TableHead class="text-[10px]">Typ</TableHead>
              <TableHead class="text-[10px]">Zimmer</TableHead>
              <TableHead class="text-[10px]">Fläche</TableHead>
              <TableHead class="text-[10px]">Preis</TableHead>
              <TableHead class="text-[10px]">€/m²</TableHead>
              <TableHead class="text-[10px]">Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="unit in filteredUnits" :key="unit.id"
              :class="unit.status === 'reserviert' ? 'bg-orange-50/50' : unit.status === 'verkauft' ? 'bg-red-50/50' : ''">
              <TableCell class="font-semibold text-[12px]">{{ unit.unit_number || unit.top_nr || '–' }}</TableCell>
              <TableCell class="text-[12px]">{{ unit.unit_type || 'Wohnung' }}</TableCell>
              <TableCell class="text-[12px]">{{ unit.rooms || '–' }}</TableCell>
              <TableCell class="text-[12px]">{{ unit.area_m2 ? unit.area_m2 + ' m²' : '–' }}</TableCell>
              <TableCell class="text-[12px] tabular-nums">{{ unit.price ? formatPrice(unit.price) : '–' }}</TableCell>
              <TableCell class="text-[12px] tabular-nums">{{ unit.price && unit.area_m2 ? formatPrice(Math.round(unit.price / unit.area_m2)) : '–' }}</TableCell>
              <TableCell>
                <Badge variant="outline" :class="[
                  unit.status === 'frei' ? 'bg-green-50 text-green-700 border-green-200' :
                  unit.status === 'reserviert' ? 'bg-orange-50 text-orange-700 border-orange-200' :
                  'bg-red-50 text-red-700 border-red-200'
                ]" class="text-[8px]">{{ unit.status }}</Badge>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </ScrollArea>
    </div>
  </CollapsibleContent>
</Collapsible>
```

- [ ] **Step 5: Add remaining Collapsible sections**

Add these Collapsible sections for: Stellplätze (newbuild, similar to Einheiten but filtered for `is_parking`), Wissens-DB, Dateien, Historie (non-newbuild), Unterobjekt anlegen, Hierarchie.

Each follows the same pattern as Objektdaten: Collapsible wrapper with trigger (chevron + icon + title + badge) and content. The content for Wissens-DB, Dateien, Nachrichten etc. contains a Button that emits the existing event:

```vue
<!-- Wissens-DB -->
<Collapsible v-if="!property?.parent_id" v-model:open="openSections.wissensdb" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.wissensdb" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <BookOpen class="w-3.5 h-3.5 text-green-700" />
      <span class="text-[13px] font-semibold text-foreground">Wissens-Datenbank</span>
    </div>
    <div class="flex items-center gap-1.5" @click.stop>
      <Button variant="ghost" size="sm" class="h-7 text-[10px] text-violet-600"
        :disabled="exposeLoading" @click="loadExposeFiles().then(() => { exposeFileSelect = true; exposeMode = 'kb'; })">
        <Sparkles class="w-3 h-3 mr-1" />
        KI auslesen
      </Button>
      <Badge variant="outline" class="text-[9px]">{{ kbCounts?.[property?.id] || 0 }} Einträge</Badge>
    </div>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="$emit('openKnowledge', property?.id, property?.address)">
        Wissens-DB öffnen
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>

<!-- Dateien -->
<Collapsible v-if="!property?.parent_id" v-model:open="openSections.dateien" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.dateien" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <FileText class="w-3.5 h-3.5 text-red-600" />
      <span class="text-[13px] font-semibold text-foreground">Dateien</span>
    </div>
    <Badge v-if="property?.files_count" variant="outline" class="bg-green-50 text-green-700 border-green-200 text-[9px]">{{ property.files_count }}</Badge>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="$emit('openFiles', property?.id, property?.address)">
        Dateien öffnen
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>

<!-- Historie (non-newbuild only) -->
<Collapsible v-if="property?.property_category !== 'newbuild' && !property?.parent_id"
  v-model:open="openSections.historie" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.historie" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <Clock class="w-3.5 h-3.5 text-orange-700" />
      <span class="text-[13px] font-semibold text-foreground">Historie</span>
    </div>
    <Badge variant="outline" class="text-[9px]">
      {{ (() => { let d = property?.property_history; if (typeof d === 'string') { try { d = JSON.parse(d); } catch(e) { d = []; } } return (Array.isArray(d) ? d.length : 0); })() }} Einträge
    </Badge>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="openHistory()">
        Historie anzeigen
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>

<!-- Unterobjekt anlegen -->
<Collapsible v-if="!property?.parent_id" v-model:open="openSections.unterobjekt" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.unterobjekt" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <Plus class="w-3.5 h-3.5 text-violet-600" />
      <span class="text-[13px] font-semibold text-foreground">Unterobjekt anlegen</span>
    </div>
    <Badge v-if="property?.children?.length" variant="outline" class="bg-violet-50 text-violet-700 border-violet-200 text-[9px]">{{ property.children.length }} vorhanden</Badge>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="openChildCreateModal()">
        Neues Unterobjekt
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>

<!-- Hierarchie -->
<Collapsible v-model:open="openSections.hierarchie" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.hierarchie" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <Link2 class="w-3.5 h-3.5 text-violet-600" />
      <span class="text-[13px] font-semibold text-foreground">Hierarchie</span>
    </div>
    <Badge variant="outline" class="text-[9px]">
      {{ property?.parent_id ? 'Unterobjekt' : 'Hauptobjekt' }}
    </Badge>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="$emit('assignParent', property); $emit('close')">
        Zuordnung verwalten
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>
```

- [ ] **Step 6: Build and test**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -20
```

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Admin/PropertyDetailView.vue
git commit -m "feat: Objekt tab with KPIs and all Collapsible sections"
```

### Task 5: Tab "Aktivitäten" — Protokoll + Nachrichten

**Files:**
- Modify: `resources/js/Components/Admin/PropertyDetailView.vue`

- [ ] **Step 1: Replace Aktivitäten tab placeholder**

Replace the placeholder inside the Aktivitäten TabsContent:

```vue
<!-- Aktivitäten (Protokoll) -->
<Collapsible v-model:open="openSections.aktivitaeten" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.aktivitaeten" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <ClipboardList class="w-3.5 h-3.5 text-cyan-700" />
      <span class="text-[13px] font-semibold text-foreground">Protokoll & Einträge</span>
    </div>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="$emit('openActivities', property?.id, property?.address)">
        Aktivitäten öffnen
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>

<!-- Nachrichten -->
<Collapsible v-model:open="openSections.nachrichten" class="border border-border rounded-lg">
  <CollapsibleTrigger class="flex items-center justify-between w-full px-4 py-2.5 hover:bg-muted/50 rounded-lg">
    <div class="flex items-center gap-2">
      <ChevronDown v-if="!openSections.nachrichten" class="w-3 h-3 text-muted-foreground" />
      <ChevronUp v-else class="w-3 h-3 text-muted-foreground" />
      <MessageCircle class="w-3.5 h-3.5 text-blue-600" />
      <span class="text-[13px] font-semibold text-foreground">Nachrichten</span>
    </div>
  </CollapsibleTrigger>
  <CollapsibleContent>
    <div class="px-4 pb-3">
      <Button variant="outline" size="sm" class="text-[11px] h-7" @click="$emit('openMessages', property?.id, property?.address)">
        Nachrichten öffnen
      </Button>
    </div>
  </CollapsibleContent>
</Collapsible>
```

- [ ] **Step 2: Build and commit**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -20
git add resources/js/Components/Admin/PropertyDetailView.vue
git commit -m "feat: Aktivitäten tab with Protokoll + Nachrichten sections"
```

### Task 6: Tab "Kaufanbote"

**Files:**
- Modify: `resources/js/Components/Admin/PropertyDetailView.vue`

- [ ] **Step 1: Replace Kaufanbote tab placeholder**

```vue
<div class="space-y-2">
  <p class="text-[13px] text-muted-foreground mb-3">Kaufanbote und Angebote für dieses Objekt.</p>
  <Button variant="outline" class="w-full justify-center text-[12px]" @click="$emit('openSettings', property?.id)">
    <ShoppingCart class="w-3.5 h-3.5 mr-1.5" />
    Kaufanbote verwalten
  </Button>
</div>
```

Note: The existing Kaufanbote page opens via `openSettings` emit and is handled by the parent component (Dashboard.vue). For V1, we keep this behavior and provide a clear entry point. Inline Kaufanbote list can be a follow-up enhancement.

- [ ] **Step 2: Build and commit**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -20
git add resources/js/Components/Admin/PropertyDetailView.vue
git commit -m "feat: Kaufanbote tab with link to existing management view"
```

### Task 7: Clean up and final verification

**Files:**
- Modify: `resources/js/Components/Admin/PropertyDetailView.vue`

- [ ] **Step 1: Remove unused old template code**

Verify no old template code remains (tile grid, old overlay, old header). Search for patterns that should no longer exist:

```bash
cd /var/www/srhomes && grep -n "tiles\|tile\|iconMap\|max-w-3xl\|rounded-t-3xl" resources/js/Components/Admin/PropertyDetailView.vue
```

Expected: No matches (or only false positives in comments).

- [ ] **Step 2: Remove unused imports**

Check for Lucide icons that were only used in the tile grid and are no longer needed. Remove any that aren't used in the new template.

- [ ] **Step 3: Verify tailwind content scanning**

Ensure `tailwind.config.js` still includes `.js` files (fixed in previous session):

```bash
grep "\.js" /var/www/srhomes/tailwind.config.js
```

- [ ] **Step 4: Full build test**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -30
```

- [ ] **Step 5: Manual testing checklist**

Test in browser:
1. Open any property → Dialog opens at 950px width
2. Header shows address, status badge, action buttons
3. Tab "Objekt": KPIs visible, Collapsible sections open/close
4. For Neubauprojekt: Einheiten + Stellplätze sections appear with orange border
5. For Bestandsobjekt: Historie section appears, no Einheiten/Stellplätze
6. Tab "Aktivitäten": Protocol + Nachrichten sections
7. Tab "Kaufanbote": Button to manage offers
8. "Bearbeiten" opens PropertyEditor
9. "KI auslesen" opens file select dialog
10. Close button and overlay click close the Dialog
11. All sub-modals (portal, history, child create) still work

- [ ] **Step 6: Final commit**

```bash
git add resources/js/Components/Admin/PropertyDetailView.vue
git commit -m "chore: clean up unused code from PropertyDetailView redesign"
```

## Verification

```bash
cd /var/www/srhomes && npm run build && echo "BUILD OK"
```

Open browser → Admin Dashboard → Click any property → Verify Dialog with 3 tabs opens correctly.
