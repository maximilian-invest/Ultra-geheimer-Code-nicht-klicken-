# Feld-Export-Indikatoren im Objekt-Editor

Jedes Feld im Property-Edit-Formular bekommt **subtile Icons** neben dem Label die anzeigen wohin das Feld exportiert wird. Hover zeigt einen präzisen Tooltip.

## Problem

Der Objekt-Editor hat ~120+ Felder verteilt auf 5 Sub-Tabs (Allgemeines, Kosten, Flächen, Energie, Beschreibung). Der Makler weiß bei vielen Feldern nicht, ob seine Eingabe:

- auf der **SR-Homes-Website** (sr-homes.at) angezeigt wird
- an **Immoji** exportiert wird (und damit auf den Plattformen landet die darüber gesteuert werden: Willhaben, ImmoScout24, ImmoWelt)
- im **Kundenportal** für Interessenten sichtbar ist
- rein **intern** bleibt (Provisionen, Notizen, Status)

Beispiel aus dem User-Request: *„38 Wohneinheiten, wohin wird das exportiert? Die Eigentumsform, ist das nur intern?"*

Es gibt schon eine bestehende `fieldVis`-Map in `EditTab.vue`, aber:
- Nur 3 von 4 nötigen Icons (Globe/Users/Lock — **kein Immoji**)
- ~40 Felder sind falsch als „Wird nirgends angezeigt" markiert, obwohl sie an Immoji gehen
- Sub-Tabs (EditTabAllgemeines/Kosten/Flächen/Energie) nutzen die Funktion gar nicht — kommen ohne Icons daher

## Lösung

1. **Zentrale Mapping-Datei** `resources/js/utils/propertyFieldExports.js` — autoritative Quelle für alle Feld-zu-Export-Zuordnungen
2. **Wiederverwendbare Komponente** `FieldExportBadges.vue` — rendert Icons aus dem Mapping
3. **Integration in allen Sub-Tabs** — jedes Feld-Label kriegt die Komponente
4. **Vier Icon-Typen**:
   - 🏠 Home → **Immoji** (und damit indirekt Willhaben/ImmoScout/ImmoWelt)
   - 🌐 Globe → **SR-Homes-Website** (sr-homes.at)
   - 👥 Users → **Kundenportal** (kundenportal.sr-homes.at Käufer-Ansicht)
   - 🔒 Lock → **Nur intern** (nirgendwo exportiert)

Design-Stil: **orange, 12px, 2px Strich-Breite** (bestehender Stil bleibt) — Makler wünschen bewusst Farbakzent als visuelle Anker.

## User Flow

1. Makler öffnet Objekt → Bearbeiten → Allgemeines → sieht Feld „Wohneinheiten"
2. Rechts neben dem Label: ein 🏠-Icon
3. Maus-Hover über das Icon zeigt Tooltip: *„Wird zu Immoji exportiert (Residentialeinheiten)"*
4. Makler weiß sofort: dieses Feld wirkt sich auf Immoji aus, nicht auf Website
5. Bei Feldern ohne Icons (z. B. Notiz-Felder): keine Info = rein intern

## Datenmodell — das zentrale Mapping

Datei: `resources/js/utils/propertyFieldExports.js`

```javascript
// Kürzel: i = Immoji, w = Website, p = Kundenportal, l = Lokal/Intern
export const FIELD_EXPORTS = {
  // === OBJEKT (Sektion Objekt im Allgemeines-Tab) ===
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

  // === ALLGEMEINES — Bau & Zustand ===
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

  // === KOSTEN — Preise ===
  purchase_price:      { targets: ['i', 'w'], tips: { i: 'Immoji: Kaufpreis', w: 'Website: Preis' } },
  rental_price:        { targets: ['i', 'w'] },
  rent_warm:           { targets: ['l'] },
  rent_deposit:        { targets: ['l'] },
  price_per_m2:        { targets: ['l'],      tips: { l: 'Nur intern (Berechnung)' } },
  parking_price:       { targets: ['l'] },
  monthly_costs:       { targets: ['l'] },

  // === KOSTEN — Betriebskosten ===
  operating_costs:     { targets: ['i'] },
  heating_costs:       { targets: ['i'] },
  warm_water_costs:    { targets: ['i'] },
  cooling_costs:       { targets: ['i'] },
  maintenance_reserves:{ targets: ['i'] },
  admin_costs:         { targets: ['i'] },
  elevator_costs:      { targets: ['i'] },
  parking_costs_monthly:{ targets: ['i'] },
  other_costs:         { targets: ['i'] },

  // === KOSTEN — Provisionen ===
  buyer_commission_percent: { targets: ['i'], tips: { i: 'Immoji: Kaufer-Provision' } },
  buyer_commission_text:    { targets: ['l'] },
  buyer_commission_free:    { targets: ['i'] },
  commission_percent:       { targets: ['i', 'l'], tips: { i: 'Immoji: Verkäufer-Provision', l: 'Im Cockpit sichtbar' } },
  commission_total:         { targets: ['l'] },
  commission_makler:        { targets: ['l'] },
  commission_note:          { targets: ['l'] },

  // === KOSTEN — Steuern/Fees ===
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

  // === AUSSTATTUNG (Boolean Features) ===
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

  // === BESCHREIBUNG / TEXT ===
  realty_description:  { targets: ['i', 'w'], tips: { i: 'Immoji: Hauptbeschreibung', w: 'Website: Objektbeschreibung' } },
  highlights:          { targets: ['w'] },
  ad_tag:              { targets: ['i'] },
  internal_rating:     { targets: ['l'] },

  // === RATING & MEDIEN ===
  main_image_id:       { targets: ['w'] },
  website_gallery_ids: { targets: ['w'] },
  external_image_url:  { targets: ['w'] },
};

// Helper: liefert Icons + Tooltip-Text für ein Feld
export function visForField(key) {
  const entry = FIELD_EXPORTS[key];
  if (!entry || !entry.targets?.length) {
    return { icons: [], tooltip: '' };
  }
  return {
    icons: entry.targets, // ['i', 'w', 'p', 'l']
    tooltip: buildTooltip(entry),
  };
}

const TARGET_NAMES = {
  i: 'Immoji (Inserats-Portale)',
  w: 'SR-Homes Website',
  p: 'Kundenportal',
  l: 'Nur intern',
};

function buildTooltip(entry) {
  return entry.targets.map(t => {
    const specific = entry.tips?.[t];
    return specific || TARGET_NAMES[t];
  }).join(' · ');
}
```

## Helfer-Komponente

Datei: `resources/js/Components/Admin/property-detail/FieldExportBadges.vue`

```vue
<script setup>
import { computed } from 'vue'
import { Home, Globe, Users, Lock } from 'lucide-vue-next'
import { visForField } from '@/utils/propertyFieldExports.js'

const props = defineProps({
  field: { type: String, required: true },
})

const ICON_MAP = { i: Home, w: Globe, p: Users, l: Lock }

const data = computed(() => visForField(props.field))
</script>

<template>
  <span v-if="data.icons.length" class="inline-flex items-center gap-0.5 cursor-help" :title="data.tooltip">
    <component
      v-for="t in data.icons"
      :key="t"
      :is="ICON_MAP[t]"
      class="w-3 h-3 text-orange-400 shrink-0"
      stroke-width="2"
    />
  </span>
</template>
```

Nutzung in allen Subtabs:

```vue
<label class="...">
  Wohneinheiten
  <FieldExportBadges field="total_units" />
</label>
```

## Integration pro Sub-Tab

Jede Subtab-Komponente importiert `FieldExportBadges` einmal und fügt die Komponente nach jedem Label-Text ein:

- `EditTabAllgemeines.vue` — ~35 Felder bekommen Badges
- `EditTabKosten.vue` — ~25 Felder (Preise, BK, Provisionen, Steuern)
- `EditTabFlaechen.vue` — ~15 Felder
- `EditTabEnergie.vue` — ~8 Felder
- `EditTab.vue` Root — ~20 Boolean-Features + Status

Die existierende `fieldVis`-Map im Haupt-EditTab wird **entfernt** — alle Subtabs nutzen die zentrale Datei.

## Darstellung — Details

**Position**: direkt rechts neben dem Label-Text, kein Zeilenumbruch, gap-0.5 (2px).

**Tooltip**: nutzt native `title`-Attribute (browser-standard). Kein custom Tooltip-Popover nötig für v1. Format:
- Ein Ziel: `"Immoji: Objekt-Titel"` bzw. `"Nur intern"`
- Mehrere Ziele: `"Immoji (Inserats-Portale) · SR-Homes Website"` bzw. mit spezifischen Tips: `"Immoji: Objektnummer · Website: Referenz-ID"`

**Fehlende Felder**: Wenn ein Feld nicht in der Mapping-Datei ist → keine Icons (defensive default). Beim Code-Review sieht man's am Git-Diff.

## Edge Cases

- **Conditional Felder** (z. B. `parking_spaces` nur bei Newbuild sichtbar): Badge zeigt immer gleich, unabhängig vom Objekt-Typ. Info bleibt konsistent.
- **Berechnete Werte** (z. B. `price_per_m2`): als `l` markiert — werden nicht direkt exportiert, nur angezeigt.
- **Legacy-Felder** (z. B. `ownership_type`): explizit mit Hinweis im Tooltip warum's nicht exportiert.
- **Neue Felder**: Entwickler die ein neues Feld hinzufügen müssen einen Eintrag in `FIELD_EXPORTS` ergänzen, sonst kein Badge. Code-Review fängt das ab.

## Testing

Keine neuen Backend-Tests (pure Frontend-Änderung).

**Vitest** für die Helper-Funktion (`tests/js/propertyFieldExports.spec.js`):

```javascript
import { visForField } from '@/utils/propertyFieldExports.js'

describe('visForField', () => {
  it('returns empty for unknown field', () => {
    const r = visForField('does_not_exist')
    expect(r.icons).toEqual([])
  })
  it('returns immoji+website for ref_id', () => {
    const r = visForField('ref_id')
    expect(r.icons).toEqual(['i', 'w'])
    expect(r.tooltip).toMatch(/Immoji.*Website|Website.*Immoji/)
  })
  it('uses specific tips when provided', () => {
    const r = visForField('object_type')
    expect(r.tooltip).toContain('Objekttyp')
  })
})
```

**Manual QA**:
- Jeden Sub-Tab öffnen → Icons erscheinen an jedem erwarteten Label
- Hover jedes Icons → Tooltip erscheint mit passendem Text
- Dark-Mode: Icons bleiben orange (text-orange-400 funktioniert in beiden Modi)

## Out of Scope (v1)

- Custom Tooltip-Popover mit Formatierung (aktuell: native `title`)
- Editierbarkeit des Mappings über UI (nur über Code)
- Auto-Sync mit Immoji-Schema-Änderungen (Mapping wird manuell gepflegt, Kommentare in `ImmojiUploadService.php` zeigen's)
- Animationen beim Hover
- Einstellung „alle Icons ausblenden" für erfahrene User

## Wartung / Pflege-Hinweis

Wenn Immoji-Schema oder Website-Export sich ändert, **nur** `propertyFieldExports.js` anpassen:

- **Immoji**: Änderungen in `app/Services/ImmojiUploadService.php` → Felder ergänzen/entfernen in Mapping
- **Website**: Änderungen in `app/Http/Controllers/WebsiteApiController.php` (select-Liste) → Mapping anpassen
- **Intern-Only**: Neue Commission-/Notiz-Felder → `targets: ['l']` setzen

Ein kurzer Kommentar-Header in `propertyFieldExports.js` nennt die beiden Backend-Quellen als Referenz.

## Erfolgskriterien

- Makler kann bei jedem Feld auf einen Blick sehen wohin es exportiert wird
- Keine falschen Einträge (kein Feld zeigt „Immoji" obwohl nicht exportiert)
- Design bleibt konsistent mit bestehendem Orange-Akzent
- Keine negative Performance-Auswirkung (Icons sind reine SVG, 12px)
- Pflege bleibt zentral an **einer** Stelle
