# Feld-Export-Indikatoren Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jedes Feld im Property-Editor bekommt subtile Icons die anzeigen wohin es exportiert wird (Immoji, Website, Kundenportal, oder nur intern). Hover zeigt präzisen Tooltip.

**Architecture:** Eine zentrale JS-Mapping-Datei (`propertyFieldExports.js`) als Single Source of Truth. Eine wiederverwendbare Vue-Komponente (`FieldExportBadges.vue`) die Icons + Tooltip rendert. Integration in alle 5 Sub-Tabs des Objekt-Editors.

**Tech Stack:** Vue 3, shadcn-vue, lucide-vue-next (Home/Globe/Users/Lock Icons), Vitest für Tests.

**Spec:** [`docs/superpowers/specs/2026-04-21-field-export-indicators-design.md`](../specs/2026-04-21-field-export-indicators-design.md)

---

## File Structure

**Create:**
- `resources/js/utils/propertyFieldExports.js` — Mapping + Helper
- `resources/js/Components/Admin/property-detail/FieldExportBadges.vue` — Icon-Komponente
- `tests/js/propertyFieldExports.spec.js` — Vitest für Helper

**Modify:**
- `resources/js/Components/Admin/property-detail/EditTab.vue` — alte `fieldVis`-Map entfernen, Komponente nutzen
- `resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue` — Badges an allen ~35 Labels
- `resources/js/Components/Admin/property-detail/EditTabKosten.vue` — Badges an allen ~25 Labels
- `resources/js/Components/Admin/property-detail/EditTabFlaechen.vue` — Badges an allen ~15 Labels
- `resources/js/Components/Admin/property-detail/EditTabEnergie.vue` — Badges an allen ~8 Labels

**Bestehende Infrastruktur (nicht ändern):**
- `app/Services/ImmojiUploadService.php` (Referenz-Quelle für Mapping)
- `app/Http/Controllers/WebsiteApiController.php` (Referenz-Quelle für Mapping)

---

## Task 1: Zentrale Mapping-Datei

**Files:**
- Create: `resources/js/utils/propertyFieldExports.js`

- [ ] **Step 1: Datei anlegen**

Create `resources/js/utils/propertyFieldExports.js`:

```javascript
/**
 * Zentrale Mapping-Tabelle: Wohin wird jedes Property-Feld exportiert?
 *
 * Ziel-Kürzel:
 *   i = Immoji (indirekt Willhaben, ImmoScout24, ImmoWelt)
 *   w = SR-Homes Website (sr-homes.at)
 *   p = Kundenportal (kundenportal.sr-homes.at)
 *   l = Nur intern (nirgendwo exportiert)
 *
 * Quellen für die Mapping-Entscheidungen:
 *   - Immoji:   app/Services/ImmojiUploadService.php (mapPropertyToImmoji* Methoden)
 *   - Website:  app/Http/Controllers/WebsiteApiController.php (select-Liste in properties())
 *
 * Pflege-Hinweis: Bei Schema-Änderungen in einer der Backend-Quellen hier
 * manuell nachziehen. Fehlende Felder zeigen kein Icon (defensive default).
 */

export const FIELD_EXPORTS = {
  // === OBJEKT ===
  ref_id:              { targets: ['i', 'w'], tips: { i: 'Immoji: Objektnummer', w: 'Website: Referenz-ID' } },
  object_type:         { targets: ['i', 'w'], tips: { i: 'Immoji: Objekttyp', w: 'Website: Typ-Filter' } },
  object_subtype:      { targets: ['i'],      tips: { i: 'Immoji: Objekt-Subtyp' } },
  marketing_type:      { targets: ['i', 'w'], tips: { i: 'Immoji: Kauf/Miete', w: 'Website: Kauf/Miete-Filter' } },
  property_category:   { targets: ['w'],      tips: { w: 'Website: Kategorie (Bestand/Neubau)' } },
  project_name:        { targets: ['i', 'w'], tips: { w: 'Website: Projekt-Titel' } },
  title:               { targets: ['i'],      tips: { i: 'Immoji: Objekt-Titel' } },
  subtitle:            { targets: ['i'],      tips: { i: 'Immoji: Objekt-Untertitel' } },

  // === ADRESSE ===
  address:             { targets: ['i', 'w', 'p'] },
  house_number:        { targets: ['i'] },
  zip:                 { targets: ['i', 'w', 'p'] },
  city:                { targets: ['i', 'w', 'p'] },
  staircase:           { targets: ['i'] },
  door:                { targets: ['i'] },
  entrance:            { targets: ['i'] },
  address_floor:       { targets: ['i'] },
  latitude:            { targets: ['i'] },
  longitude:           { targets: ['i'] },

  // === ZUORDNUNGEN ===
  broker_id:           { targets: ['i', 'w'], tips: { i: 'Immoji: realtyManager', w: 'Website: Makler-Name' } },
  property_manager_id: { targets: ['l'],      tips: { l: 'Nur intern (HV-Kontakt)' } },
  property_manager:    { targets: ['i'],      tips: { i: 'Immoji: Hausverwaltung (legacy)' } },
  builder_company:     { targets: ['i'],      tips: { i: 'Immoji: Bauträger' } },

  // === STATUS ===
  status:              { targets: ['i'],      tips: { i: 'Immoji: realtyStatus (Aktiv/Inaktiv/Verkauft)' } },
  realty_status:       { targets: ['i', 'w'] },
  inserat_since:       { targets: ['l'],      tips: { l: 'Nur intern (Inserat-Beginn)' } },
  platforms:           { targets: ['l'] },
  available_from:      { targets: ['i'] },
  available_text:      { targets: ['i'] },
  construction_start:  { targets: ['l'] },
  construction_end:    { targets: ['l'] },
  closing_date:        { targets: ['i'] },
  sold_at:             { targets: ['w'] },

  // === BAU & ZUSTAND ===
  construction_type:   { targets: ['i'] },
  construction_year:   { targets: ['i', 'w'] },
  year_renovated:      { targets: ['w'] },
  realty_condition:    { targets: ['i'] },
  quality:             { targets: ['l'] },
  ownership_type:      { targets: ['l'],      tips: { l: 'Aktuell nicht exportiert (Immoji-Schema entfernt)' } },
  total_units:         { targets: ['i', 'w'], tips: { i: 'Immoji: Residentialeinheiten', w: 'Website: Anzahl Einheiten' } },
  unit_count:          { targets: ['i'] },

  // === RÄUME ===
  rooms_amount:        { targets: ['i', 'w'] },
  bedrooms:            { targets: ['i'] },
  bathrooms:           { targets: ['i', 'w'] },
  toilets:             { targets: ['i'] },
  floor_number:        { targets: ['l'] },
  floor_count:         { targets: ['l'] },

  // === PREISE ===
  purchase_price:      { targets: ['i', 'w'], tips: { i: 'Immoji: Kaufpreis', w: 'Website: Preis' } },
  rental_price:        { targets: ['i', 'w'] },
  rent_warm:           { targets: ['l'] },
  rent_deposit:        { targets: ['l'] },
  price_per_m2:        { targets: ['l'],      tips: { l: 'Nur intern (Berechnung)' } },
  parking_price:       { targets: ['l'] },
  monthly_costs:       { targets: ['l'] },

  // === BETRIEBSKOSTEN ===
  operating_costs:     { targets: ['i'] },
  heating_costs:       { targets: ['i'] },
  warm_water_costs:    { targets: ['i'] },
  cooling_costs:       { targets: ['i'] },
  maintenance_reserves:{ targets: ['i'] },
  admin_costs:         { targets: ['i'] },
  elevator_costs:      { targets: ['i'] },
  parking_costs_monthly:{ targets: ['i'] },
  other_costs:         { targets: ['i'] },

  // === PROVISIONEN ===
  buyer_commission_percent: { targets: ['i'], tips: { i: 'Immoji: Käufer-Provision' } },
  buyer_commission_text:    { targets: ['l'] },
  buyer_commission_free:    { targets: ['i'] },
  commission_percent:       { targets: ['i', 'l'], tips: { i: 'Immoji: Verkäufer-Provision', l: 'Im Cockpit sichtbar' } },
  commission_total:         { targets: ['l'] },
  commission_makler:        { targets: ['l'] },
  commission_note:          { targets: ['l'] },

  // === STEUERN/FEES ===
  land_register_fee_pct:    { targets: ['l'] },
  land_transfer_tax_pct:    { targets: ['l'] },
  contract_fee_pct:         { targets: ['l'] },

  // === FLÄCHEN ===
  living_area:         { targets: ['i', 'w'] },
  free_area:           { targets: ['i', 'w'] },
  realty_area:         { targets: ['i'] },
  total_area:          { targets: ['w'] },
  office_space:        { targets: ['i'] },
  area_balcony:        { targets: ['i'] },
  area_terrace:        { targets: ['i'] },
  area_garden:         { targets: ['i'] },
  area_loggia:         { targets: ['i'] },
  area_basement:       { targets: ['i'] },
  area_garage:         { targets: ['l'] },

  // === PARKEN ===
  garage_spaces:       { targets: ['w'] },
  parking_spaces:      { targets: ['w'] },
  parking_type:        { targets: ['l'] },

  // === ENERGIE ===
  energy_certificate:  { targets: ['i', 'w'] },
  heating_demand_value:{ targets: ['i', 'w'] },
  heating_demand_class:{ targets: ['i'] },
  energy_efficiency_value: { targets: ['i'] },
  energy_primary_source:   { targets: ['l'] },
  energy_valid_until:      { targets: ['l'] },
  energy_type:             { targets: ['l'] },
  heating:             { targets: ['i'] },

  // === AUSSTATTUNG (Booleans) ===
  has_balcony:         { targets: ['i', 'w'] },
  has_terrace:         { targets: ['i', 'w'] },
  has_loggia:          { targets: ['i', 'w'] },
  has_garden:          { targets: ['i', 'w'] },
  has_basement:        { targets: ['i', 'w'] },
  has_cellar:          { targets: ['l'] },
  has_elevator:        { targets: ['i', 'w'] },
  has_fitted_kitchen:  { targets: ['i'] },
  has_air_conditioning:{ targets: ['i'] },
  has_pool:            { targets: ['i'] },
  has_sauna:           { targets: ['i'] },
  has_fireplace:       { targets: ['l'] },
  has_alarm:           { targets: ['l'] },
  has_barrier_free:    { targets: ['l'] },
  has_guest_wc:        { targets: ['l'] },
  has_storage_room:    { targets: ['l'] },
  has_washing_connection:  { targets: ['l'] },
  has_photovoltaik:    { targets: ['w'] },
  has_charging_station:{ targets: ['w'] },

  // === INNENEIGENSCHAFTEN ===
  kitchen_type:        { targets: ['l'] },
  flooring:            { targets: ['l'] },
  bathroom_equipment:  { targets: ['l'] },
  orientation:         { targets: ['l'] },
  furnishing:          { targets: ['i'] },
  condition_note:      { targets: ['w'] },
  common_areas:        { targets: ['w'] },

  // === BESCHREIBUNG ===
  realty_description:  { targets: ['i', 'w'], tips: { i: 'Immoji: Hauptbeschreibung', w: 'Website: Objektbeschreibung' } },
  highlights:          { targets: ['w'] },
  ad_tag:              { targets: ['i'] },
  internal_rating:     { targets: ['l'] },

  // === MEDIEN ===
  main_image_id:       { targets: ['w'] },
  website_gallery_ids: { targets: ['w'] },
  external_image_url:  { targets: ['w'] },
};

const TARGET_NAMES = {
  i: 'Immoji (Inserats-Portale)',
  w: 'SR-Homes Website',
  p: 'Kundenportal',
  l: 'Nur intern',
};

/**
 * Liefert { icons: string[], tooltip: string } für ein Feld.
 * icons ist die Liste der Ziel-Kürzel (i/w/p/l).
 * Leeres icons-Array bedeutet: kein Badge anzeigen.
 */
export function visForField(key) {
  const entry = FIELD_EXPORTS[key];
  if (!entry || !entry.targets?.length) {
    return { icons: [], tooltip: '' };
  }
  return {
    icons: entry.targets,
    tooltip: buildTooltip(entry),
  };
}

function buildTooltip(entry) {
  return entry.targets.map(t => {
    const specific = entry.tips?.[t];
    return specific || TARGET_NAMES[t];
  }).join(' · ');
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/utils/propertyFieldExports.js
git commit -m "feat(editor): add zentrale propertyFieldExports mapping + visForField helper"
```

---

## Task 2: Vitest für Helper

**Files:**
- Create: `tests/js/propertyFieldExports.spec.js`

- [ ] **Step 1: Test-Setup prüfen**

Run: `ls tests/js/ 2>/dev/null`

Falls das Verzeichnis nicht existiert: `mkdir -p tests/js`

Run: `cat vitest.config.js package.json | grep -E "vitest|test:" | head -5`

Wenn Vitest nicht konfiguriert ist, diesen Task überspringen und stattdessen in Task 12 Manual-QA ausführlicher machen. Der Rest des Plans funktioniert ohne Vitest.

- [ ] **Step 2: Test schreiben**

Create `tests/js/propertyFieldExports.spec.js`:

```javascript
import { describe, it, expect } from 'vitest'
import { visForField } from '../../resources/js/utils/propertyFieldExports.js'

describe('visForField', () => {
  it('returns empty icons for unknown field', () => {
    const r = visForField('this_field_does_not_exist')
    expect(r.icons).toEqual([])
    expect(r.tooltip).toBe('')
  })

  it('returns immoji+website for ref_id', () => {
    const r = visForField('ref_id')
    expect(r.icons).toEqual(['i', 'w'])
    expect(r.tooltip).toContain('Immoji')
    expect(r.tooltip).toContain('Website')
  })

  it('uses specific tip text when provided', () => {
    const r = visForField('object_type')
    expect(r.tooltip).toContain('Objekttyp')
  })

  it('falls back to generic target name when no specific tip', () => {
    const r = visForField('address')
    expect(r.tooltip).toContain('Immoji')
    expect(r.tooltip).toContain('Website')
    expect(r.tooltip).toContain('Kundenportal')
  })

  it('marks commission as internal-only with extra immoji flag', () => {
    const r = visForField('commission_percent')
    expect(r.icons).toContain('l')
    expect(r.icons).toContain('i')
  })

  it('marks property_manager_id as internal-only', () => {
    const r = visForField('property_manager_id')
    expect(r.icons).toEqual(['l'])
  })

  it('marks operating_costs as immoji-only (fixes old bug)', () => {
    const r = visForField('operating_costs')
    expect(r.icons).toContain('i')
    expect(r.icons).not.toContain('w')
  })

  it('marks total_units as immoji + website', () => {
    const r = visForField('total_units')
    expect(r.icons).toContain('i')
    expect(r.icons).toContain('w')
  })

  it('marks pure internal fields with only l', () => {
    const r = visForField('commission_total')
    expect(r.icons).toEqual(['l'])
  })
})
```

- [ ] **Step 3: Run tests**

Run: `npx vitest run tests/js/propertyFieldExports.spec.js 2>&1 | tail -10`

Expected: Alle 9 Tests PASS. Falls Vitest nicht installiert ist, schlägt es mit "command not found vitest" fehl — dann diesen Task skippen.

- [ ] **Step 4: Commit**

```bash
git add tests/js/propertyFieldExports.spec.js
git commit -m "test(editor): add vitest for propertyFieldExports helper"
```

---

## Task 3: FieldExportBadges Komponente

**Files:**
- Create: `resources/js/Components/Admin/property-detail/FieldExportBadges.vue`

- [ ] **Step 1: Komponente schreiben**

Create `resources/js/Components/Admin/property-detail/FieldExportBadges.vue`:

```vue
<script setup>
import { computed } from 'vue'
import { Home, Globe, Users, Lock } from 'lucide-vue-next'
import { visForField } from '@/utils/propertyFieldExports.js'

const props = defineProps({
  field: { type: String, required: true },
})

const ICON_MAP = {
  i: Home,    // Immoji
  w: Globe,   // Website
  p: Users,   // Kundenportal
  l: Lock,    // Intern
}

const data = computed(() => visForField(props.field))
</script>

<template>
  <span
    v-if="data.icons.length"
    class="inline-flex items-center gap-0.5 cursor-help"
    :title="data.tooltip"
  >
    <component
      v-for="t in data.icons"
      :key="t"
      :is="ICON_MAP[t]"
      class="w-3 h-3 text-orange-400 shrink-0"
    />
  </span>
</template>
```

- [ ] **Step 2: Build prüfen**

Run: `npm run build 2>&1 | tail -5`

Expected: Build succeeds ohne Fehler.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/property-detail/FieldExportBadges.vue
git commit -m "feat(editor): add FieldExportBadges Vue component"
```

---

## Task 4: EditTab.vue — alte fieldVis-Map entfernen

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTab.vue`

- [ ] **Step 1: Alte Map + iconMap + vis-Funktion löschen**

In `resources/js/Components/Admin/property-detail/EditTab.vue` suche den Block der mit `// ─── Field visibility map ───` beginnt (etwa Zeile 49) und endet nach `function vis(key) {...}` (etwa Zeile 149).

Lösche diesen kompletten Block inklusive:
- `const fieldVis = { ... }` (ca. Zeile 51-144)
- `const iconMap = { globe: Globe, users: Users, lock: Lock };` (ca. Zeile 146)
- `function vis(key) { return fieldVis[key] || { icons: [], tip: '' }; }` (ca. Zeile 147-149)

Falls `Globe`, `Users`, `Lock` nur hier importiert waren, diese auch aus dem `lucide-vue-next` Import ganz oben entfernen.

Run: `grep -n "Globe\|Users\|Lock" resources/js/Components/Admin/property-detail/EditTab.vue | head -10`

Wenn die Namen nicht mehr vorkommen außerhalb des Imports, aus dem Import entfernen.

- [ ] **Step 2: FieldExportBadges importieren**

Am Anfang des `<script setup>`-Blocks bei den anderen Imports ergänzen:

```javascript
import FieldExportBadges from './FieldExportBadges.vue'
```

- [ ] **Step 3: Alle `vis()`-Aufrufe im Template durch `<FieldExportBadges>` ersetzen**

Run: `grep -n "vis(" resources/js/Components/Admin/property-detail/EditTab.vue | head -20`

Für jede gefundene Stelle: das Pattern
```vue
<span v-if="vis('FELDNAME').icons.length" class="inline-flex gap-0.5">
  <component v-for="ic in vis('FELDNAME').icons" :key="ic" :is="iconMap[ic]"
    class="w-3 h-3 text-orange-400 flex-shrink-0 cursor-help"
    :title="vis('FELDNAME').tip" />
</span>
```

ersetzen durch:

```vue
<FieldExportBadges field="FELDNAME" />
```

Beispiel — die Zeile:
```vue
<label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Ref-ID <span v-if="vis('ref_id').icons.length" class="inline-flex gap-0.5"><component v-for="ic in vis('ref_id').icons" :key="ic" :is="iconMap[ic]" class="w-3 h-3 text-orange-400 flex-shrink-0 cursor-help" :title="vis('ref_id').tip" /></span></label>
```

wird zu:
```vue
<label class="text-[10px] text-muted-foreground mb-0.5 flex items-center gap-1">Ref-ID <FieldExportBadges field="ref_id" /></label>
```

Gehe durch alle Treffer von `grep -n "vis(" ...`. Bei etwa 30-50 Stellen.

- [ ] **Step 4: `:vis` und `:icon-map` Props aus Child-Komponenten entfernen**

Run: `grep -n ":vis=\|:icon-map=" resources/js/Components/Admin/property-detail/EditTab.vue`

Bei jedem Treffer die zwei Props aus dem Child-Tag entfernen. Beispiel:

```vue
<EditTabAllgemeines :form="form" :broker-list="brokerList" :is-newbuild="isNewbuild" :is-child="isChild" :features="features" :vis="vis" :icon-map="iconMap" />
```

wird zu:

```vue
<EditTabAllgemeines :form="form" :broker-list="brokerList" :is-newbuild="isNewbuild" :is-child="isChild" :features="features" />
```

- [ ] **Step 5: Build prüfen**

Run: `npm run build 2>&1 | tail -5`

Expected: Build succeeds. Falls Fehler „vis is not defined" oder ähnlich: zurück zu Step 3 und verbleibende `vis(`-Aufrufe finden.

Run: `grep -n "vis(\|iconMap" resources/js/Components/Admin/property-detail/EditTab.vue`
Expected: Keine Treffer mehr.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Admin/property-detail/EditTab.vue
git commit -m "refactor(editor): replace EditTab fieldVis with FieldExportBadges"
```

---

## Task 5: EditTabAllgemeines — Badges in allen Labels

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue`

- [ ] **Step 1: Import hinzufügen**

Am Anfang des `<script setup>` bei den Imports:

```javascript
import FieldExportBadges from './FieldExportBadges.vue'
```

- [ ] **Step 2: Badges an jedem Label ergänzen**

Die alten Props `:vis` und `:icon-map` gibt es in der Komponente gar nicht — d. h. Labels sehen aktuell so aus:

```vue
<label :class="labelCls">Hausverwaltung</label>
```

Jede solche Label-Zeile wird zu:

```vue
<label :class="labelCls" class="flex items-center gap-1">
  Hausverwaltung
  <FieldExportBadges field="property_manager_id" />
</label>
```

Die Liste der Felder in EditTabAllgemeines.vue (alle `v-model="form.X"`-Bindings):

```
address, address_floor, available_from, available_text, broker_id,
builder_company, city, construction_end, construction_type,
construction_year, door, floor_count, floor_number, furnishing,
heating, house_number, inserat_since, latitude, longitude,
marketing_type, object_subtype, object_type, orientation, ownership_type,
platforms, project_name, property_manager, property_manager_id,
quality, realty_condition, ref_id, staircase, status, subtitle, title,
total_units, year_renovated, zip
```

Vorgehen:

a) Grep alle Labels: `grep -n ":class=\"labelCls\"" resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue`

b) Gehe chronologisch durch jede Treffer-Zeile und schaue darunter/oberhalb welches Feld via v-model gebunden ist (typischerweise die nächste Zeile mit `v-model="form.X"`).

c) Label wird zu:
```vue
<label :class="labelCls" class="flex items-center gap-1">
  <span>Hausverwaltung</span>
  <FieldExportBadges field="property_manager_id" />
</label>
```

Beispiel für den Status-Picker:
```vue
<label :class="labelCls" class="flex items-center gap-1">
  <span>Status</span>
  <FieldExportBadges field="status" />
</label>
<Select v-model="form.status">...</Select>
```

**Wichtig:** Die `class="flex items-center gap-1"` Ergänzung ist optional — falls `labelCls` das bereits via computed enthält, nicht doppelt setzen. Prüfe oben im Script:

Run: `grep -n "labelCls\s*=" resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue`

Falls `labelCls` bereits `flex` enthält, nur den Span + Badge einfügen ohne weitere Klassen.

- [ ] **Step 3: Build prüfen**

Run: `npm run build 2>&1 | tail -5`
Expected: ok

Manual: lokal die Seite öffnen, in ein Objekt gehen, Bearbeiten → Allgemeines. Icons erscheinen bei jedem Feld mit Export-Ziel.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Admin/property-detail/EditTabAllgemeines.vue
git commit -m "feat(editor): add FieldExportBadges to all labels in EditTabAllgemeines"
```

---

## Task 6: EditTabKosten — Badges in allen Labels

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTabKosten.vue`

- [ ] **Step 1: Import hinzufügen**

```javascript
import FieldExportBadges from './FieldExportBadges.vue'
```

- [ ] **Step 2: Felder in EditTabKosten.vue**

```
admin_costs, buyer_commission_percent, buyer_commission_text, commission_makler,
commission_note, commission_percent, commission_total, contract_fee_pct,
cooling_costs, elevator_costs, heating_costs, land_register_fee_pct,
land_transfer_tax_pct, maintenance_reserves, monthly_costs, operating_costs,
other_costs, parking_costs_monthly, parking_price, purchase_price,
warm_water_costs
```

Für jedes Label dasselbe Pattern wie Task 5 anwenden:
```vue
<label :class="labelCls" class="flex items-center gap-1">
  <span>Bezeichnung</span>
  <FieldExportBadges field="form_field_name" />
</label>
```

- [ ] **Step 3: Build + Commit**

Run: `npm run build 2>&1 | tail -3`
Expected: ok

```bash
git add resources/js/Components/Admin/property-detail/EditTabKosten.vue
git commit -m "feat(editor): add FieldExportBadges to all labels in EditTabKosten"
```

---

## Task 7: EditTabFlaechen — Badges in allen Labels

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTabFlaechen.vue`

- [ ] **Step 1: Felder in EditTabFlaechen.vue**

```
bathrooms, bedrooms, rooms_amount, toilets
```

Plus Flächen-Felder (die in Flaechen gepflegt werden — wird dort ggf. anders sein, prüfe mit grep):

Run: `grep "v-model=\"form\." resources/js/Components/Admin/property-detail/EditTabFlaechen.vue`

Die gefundenen Felder bekommen jeweils ein Label mit `<FieldExportBadges field="..." />`.

- [ ] **Step 2: Build + Commit**

Run: `npm run build 2>&1 | tail -3`

```bash
git add resources/js/Components/Admin/property-detail/EditTabFlaechen.vue
git commit -m "feat(editor): add FieldExportBadges to all labels in EditTabFlaechen"
```

---

## Task 8: EditTabEnergie — Badges in allen Labels

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTabEnergie.vue`

- [ ] **Step 1: Felder in EditTabEnergie.vue**

```
energy_efficiency_value, energy_valid_until, heating_demand_class, heating_demand_value
```

Plus alle weiteren Energie-Felder aus:

Run: `grep "v-model=\"form\." resources/js/Components/Admin/property-detail/EditTabEnergie.vue`

Jedes Label mit `<FieldExportBadges field="..." />` ausstatten.

- [ ] **Step 2: Build + Commit**

Run: `npm run build 2>&1 | tail -3`

```bash
git add resources/js/Components/Admin/property-detail/EditTabEnergie.vue
git commit -m "feat(editor): add FieldExportBadges to all labels in EditTabEnergie"
```

---

## Task 9: EditTab Root — Boolean-Features + weitere Labels

**Files:**
- Modify: `resources/js/Components/Admin/property-detail/EditTab.vue`

- [ ] **Step 1: Feature-Toggles ergänzen**

Das Root-EditTab hat einen Bereich mit Boolean-Features (`has_balcony`, `has_terrace` etc.). Nach Task 4 sind die alten `vis()`-Aufrufe entfernt aber die Feature-Toggles haben noch keine Badges.

Run: `grep -n "has_balcony\|has_terrace\|has_loggia\|has_garden\|has_basement\|has_elevator\|has_fitted_kitchen" resources/js/Components/Admin/property-detail/EditTab.vue | head -20`

Für jede Feature-Checkbox/Label suche die Label-Stelle und ergänze `<FieldExportBadges field="has_xxx" />`:

```vue
<label class="flex items-center gap-1">
  <input type="checkbox" v-model="form.has_balcony" />
  Balkon
  <FieldExportBadges field="has_balcony" />
</label>
```

- [ ] **Step 2: Build + Commit**

Run: `npm run build 2>&1 | tail -3`

```bash
git add resources/js/Components/Admin/property-detail/EditTab.vue
git commit -m "feat(editor): add FieldExportBadges to feature toggles in EditTab"
```

---

## Task 10: Quick-Check — keine vergessenen Labels

**Files:**
- Alle EditTab*.vue Dateien

- [ ] **Step 1: Alle Felder vs. alle Badge-Aufrufe vergleichen**

Run: `grep -hoE "v-model=\"form\.[a-z_]+\"" resources/js/Components/Admin/property-detail/EditTab*.vue | sed 's/.*form\.//' | sed 's/\"//' | sort -u > /tmp/fields.txt`

Run: `grep -hoE "FieldExportBadges field=\"[a-z_]+\"" resources/js/Components/Admin/property-detail/EditTab*.vue | sed 's/.*field="//' | sed 's/\"//' | sort -u > /tmp/badges.txt`

Run: `comm -23 /tmp/fields.txt /tmp/badges.txt`
Expected: Leere Ausgabe — alle Felder haben Badges.

Falls Felder fehlen: in die entsprechende Sub-Tab-Datei und `<FieldExportBadges>` ergänzen.

- [ ] **Step 2: Auch prüfen ob Badge-Felder im Mapping existieren**

Run: `grep -hoE "FieldExportBadges field=\"[a-z_]+\"" resources/js/Components/Admin/property-detail/EditTab*.vue | sed 's/.*field="//' | sed 's/\"//' | sort -u > /tmp/badges.txt`

Run: `grep -oE "^\s+[a-z_]+:" resources/js/utils/propertyFieldExports.js | sed 's/^\s*//' | sed 's/://' | sort -u > /tmp/mapping.txt`

Run: `comm -23 /tmp/badges.txt /tmp/mapping.txt`
Expected: Leere Ausgabe — alle Badges haben Einträge im Mapping.

Falls Badges ohne Mapping: entweder Mapping ergänzen oder Badge-Aufruf entfernen (wenn es das Feld gar nicht gibt).

- [ ] **Step 3: Commit falls Korrekturen nötig**

```bash
git status
# Falls Änderungen: einzeln committen, sonst fertig.
```

---

## Task 11: Deploy

- [ ] **Step 1: Lint-Check Vue-Dateien (Syntax via Build)**

Run: `npm run build 2>&1 | tail -5`
Expected: `built in X.XXs` ohne rote Fehler.

- [ ] **Step 2: Falls Vitest verfügbar, Tests laufen**

Run: `npx vitest run tests/js/propertyFieldExports.spec.js 2>&1 | tail -5`
Expected: PASS (oder Vitest nicht installiert — dann skippen).

- [ ] **Step 3: Push**

```bash
git push origin main
```

- [ ] **Step 4: Deploy Production**

```bash
ssh root@187.124.166.153 "cd /var/www/srhomes && bash deploy.sh"
```

Expected: `DEPLOY COMPLETE`.

- [ ] **Step 5: Browser-Smoketest**

Öffne https://kundenportal.sr-homes.at/admin → Objekte → ein beliebiges Objekt → Bearbeiten.

Check in jedem Sub-Tab:

- [ ] **Allgemeines**: Ref-ID zeigt 🏠+🌐 Icons, Hover-Tooltip sagt „Immoji: Objektnummer · Website: Referenz-ID"
- [ ] **Allgemeines**: Status zeigt 🏠 Icon mit Tooltip „Immoji: realtyStatus"
- [ ] **Allgemeines**: Wohneinheiten/Total Units zeigt 🏠+🌐 Icons
- [ ] **Kosten**: Kaufpreis zeigt 🏠+🌐
- [ ] **Kosten**: Betriebskosten zeigt 🏠 (**Fix für alten Bug**: vorher stand fälschlich „nirgends")
- [ ] **Kosten**: Provision intern zeigt 🏠+🔒
- [ ] **Flächen**: Wohnfläche zeigt 🏠+🌐
- [ ] **Flächen**: Balkon-Fläche zeigt 🏠 (**Fix für alten Bug**: vorher stand „nirgends")
- [ ] **Energie**: Energieausweis zeigt 🏠+🌐
- [ ] **Features**: Balkon-Boolean zeigt 🏠+🌐
- [ ] Feld ohne Export (z. B. Provision-Notiz) zeigt nur 🔒
- [ ] Bei Hover erscheint Tooltip mit Text

---

## Self-Review

**1. Spec coverage:**
- ✅ Zentrale Mapping-Datei `propertyFieldExports.js` → Task 1
- ✅ Vitest → Task 2
- ✅ FieldExportBadges Komponente mit Home/Globe/Users/Lock + orange 12px → Task 3
- ✅ Alte `fieldVis`-Map aus EditTab.vue entfernt → Task 4
- ✅ Alle Sub-Tabs bekommen Badges → Tasks 5, 6, 7, 8
- ✅ Feature-Toggles im Root-EditTab → Task 9
- ✅ Verification keine Felder vergessen → Task 10
- ✅ Tooltip-System mit native `title` + spezifische Tips je Target → Task 1 + 3
- ✅ Deploy + Manual QA → Task 11

**2. Placeholder scan:** Keine TBDs, keine „siehe Task N" ohne Code-Wiederholung. Einzig Task 7 und 8 verweisen auf grep, weil die Labels dort weniger systematisch sind — aber das grep-Kommando ist exakt angegeben.

**3. Type consistency:**
- Export-Target-Kürzel einheitlich: `i`, `w`, `p`, `l` über Mapping + Helper + Komponente
- Komponenten-Prop-Name konsistent: `field` (String)
- Funktions-Signatur `visForField(key)` liefert immer `{ icons: string[], tooltip: string }`
- Icon-Mapping: `i→Home`, `w→Globe`, `p→Users`, `l→Lock` überall gleich

**4. Ambiguity check:** Falls Vitest nicht installiert ist, skippen wir Task 2 und machen Manual-QA. Das ist in Task 2 Step 1 explizit dokumentiert — kein Überraschungs-Blocker.
