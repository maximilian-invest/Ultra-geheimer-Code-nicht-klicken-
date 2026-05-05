<script setup>
import { ref, inject, computed, watch } from "vue";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import AccordionSection from "./AccordionSection.vue";
import FieldExportBadges from "./FieldExportBadges.vue";
import PropertyManagerPicker from "./PropertyManagerPicker.vue";
import MapPreview from "./MapPreview.vue";

function onManagerAssigned(form, manager) {
  if (manager) {
    form.property_manager_id = manager.id;
    form.property_manager = manager.company_name;
  } else {
    form.property_manager_id = null;
    form.property_manager = '';
  }
}

const props = defineProps({
  form: { type: Object, required: true },
  brokerList: { type: Array, default: () => [] },
  isNewbuild: { type: Boolean, default: false },
  isChild: { type: Boolean, default: false },
  features: { type: Array, default: () => [] },
});

// OpenStreetMap Autocomplete (Nominatim, server-side mit Cache)
const API = inject("API");
const suggestions = ref([]);
const showSuggestions = ref(false);
const loadingSuggestions = ref(false);
let debounceTimer = null;

// Extrahiert "Handelsstraße 6" / "Musterweg 4a" / "Straße 5-7" in
// { street, houseNumber }. Nicht-destruktiv: liefert street=value unveraendert
// zurueck, wenn kein Muster erkannt wird.
function splitStreetAndNumber(value) {
  const str = String(value || '');
  const m = str.match(/^(.+?)[,\s]+(\d+[a-zA-Z]?(?:[-\/]\d+[a-zA-Z]?)?)\s*$/);
  if (m && m[1].trim().length >= 2) {
    return { street: m[1].trim(), houseNumber: m[2] };
  }
  return { street: str, houseNumber: null };
}

// Erste Verteidigungslinie: Ziffern-Tasten direkt blocken bevor sie ins
// Input landen. Fuer Tippfaelle ohne Whitespace ("Bundesstraße33") greift
// sonst nur die Sanitize-Logik im onAddressInput, die in seltenen Faellen
// (Race-Conditions im v-model, alte Daten aus Backend) ueberlistet werden
// kann. Zwei Schutzschichten — keine Ziffer kommt durch.
function onAddressBeforeInput(e) {
  if (e.data && /\d/.test(e.data)) {
    // Ziffer blocken; user merkt: nichts passiert beim Tippen einer Zahl.
    e.preventDefault();
  }
}

function onAddressInput(v) {
  // Mitarbeiter haben oefter die Hausnummer im Strassen-Feld eingetragen
  // und auch separat im Hausnummer-Feld — Doppel-Eintrag. Loesung: Live-
  // Filter beim Tippen.
  //   - Ziffern AM ENDE des Strings (typisches Hausnummer-Pattern, mit
  //     ODER ohne Whitespace davor: "Handelsstrasse 6", "Bundesstraße33",
  //     "Musterweg 4a", "Straße 5-7") werden ins Hausnummer-Feld
  //     verschoben (nur wenn dort noch nichts steht).
  //   - Sonstige Ziffern werden stumm entfernt (Mitarbeiter sieht: Ziffer
  //     verschwindet → klar dass sie woanders hin muss).
  const raw = String(v || "");
  let cleaned = raw;
  // Whitespace VOR der Hausnummer optional — fängt auch "Bundesstraße33".
  const tail = raw.match(/^(.+?)[\s,]*?(\d+[a-zA-Z]?(?:[\-\/]\d+[a-zA-Z]?)?)\s*$/);
  if (tail && tail[1].trim().length >= 2) {
    cleaned = tail[1].trim();
    if (!props.form.house_number || props.form.house_number === '') {
      props.form.house_number = tail[2];
    }
  } else {
    // Keine Hausnummer am Ende erkennbar — alle Ziffern stumm rauswerfen.
    cleaned = raw.replace(/\d+/g, '');
  }
  // Doppelte Spaces nach dem Trim aufraeumen.
  cleaned = cleaned.replace(/\s{2,}/g, ' ').replace(/\s+$/, '');
  props.form.address = cleaned;

  if (debounceTimer) clearTimeout(debounceTimer);
  if (cleaned.trim().length < 3) {
    suggestions.value = [];
    showSuggestions.value = false;
    return;
  }
  debounceTimer = setTimeout(async () => {
    loadingSuggestions.value = true;
    try {
      // Fuer die Autocomplete-Query nehmen wir den Text wie er ist —
      // Nominatim kommt mit "Handelsstraße 6" bestens zurecht und kann
      // die Hausnummer im Ergebnis zurueckliefern.
      const q = [cleaned, props.form.house_number, props.form.zip, props.form.city].filter(Boolean).join(" ");
      const r = await fetch(API.value + "&action=geocode_autocomplete&q=" + encodeURIComponent(q));
      const d = await r.json();
      suggestions.value = Array.isArray(d.results) ? d.results : [];
      showSuggestions.value = suggestions.value.length > 0;
    } catch (e) {
      suggestions.value = [];
      showSuggestions.value = false;
    }
    loadingSuggestions.value = false;
  }, 400);
}

// Erst beim Verlassen des Felds die Hausnummer ausgliedern — nervt nicht
// waehrend des Tippens, sorgt aber dafuer, dass beim Speichern alles in den
// richtigen DB-Spalten landet.
function onAddressBlur() {
  // Suggestions-Panel mit kleiner Verzoegerung schliessen, damit Click
  // auf einen Vorschlag noch durchgeht.
  setTimeout(() => { showSuggestions.value = false; }, 200);

  const { street, houseNumber } = splitStreetAndNumber(props.form.address);
  if (houseNumber) {
    props.form.address = street;
    if (!props.form.house_number || props.form.house_number === '') {
      props.form.house_number = houseNumber;
    }
  }
}

function pickSuggestion(s) {
  if (s.street)       props.form.address = s.street;
  // Hausnummer: OSM hat Vorrang; sonst behalten wir was der User schon eingetippt hat.
  if (s.house_number) props.form.house_number = s.house_number;
  if (s.zip)          props.form.zip = s.zip;
  if (s.city)         props.form.city = s.city;
  if (s.lat != null)  props.form.latitude = s.lat;
  if (s.lng != null)  props.form.longitude = s.lng;
  suggestions.value = [];
  showSuggestions.value = false;
}

// Feld-Zaehler pro Sektion: zaehlt alle benutzten Felder die einen Wert haben.
function countFilled(keys) {
  let filled = 0;
  for (const k of keys) {
    const v = props.form?.[k];
    if (v === null || v === undefined) continue;
    if (typeof v === 'string' && v.trim() === '') continue;
    if (typeof v === 'boolean' && v === false) continue;
    if (Array.isArray(v) && v.length === 0) continue;
    filled++;
  }
  return filled;
}

// Felder pro Sektion (entsprechend dem Template weiter unten).
const SECTION_FIELDS = {
  objekt:       ['object_type', 'property_category', 'marketing_type', 'title', 'subtitle', 'ref_id'],
  adresse:      ['address', 'house_number', 'zip', 'city', 'staircase', 'door', 'address_floor', 'latitude', 'longitude'],
  allgemeines:  ['condition_note', 'realty_condition', 'construction_type', 'quality', 'furnishing', 'ownership_type', 'year_built', 'conversions_additions', 'year_renovated', 'available_from', 'move_in_date'],
  zuordnung:    ['broker_id', 'customer_id', 'property_manager_id'],
  neubau:       ['builder_company', 'construction_start', 'construction_end', 'total_units'],
};

const sectionCounts = computed(() => {
  const out = {};
  for (const [key, fields] of Object.entries(SECTION_FIELDS)) {
    out[key] = { filled: countFilled(fields), total: fields.length };
  }
  return out;
});

const inputCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const selectCls = "h-9 text-[13px] border-0 rounded-lg bg-zinc-100/80";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Objekt -->
      <AccordionSection title="Objekt" color="#ea580c" :default-open="true" :filled="sectionCounts.objekt.filled" :total="sectionCounts.objekt.total">
        <div>
          <label :class="labelCls">Status <FieldExportBadges field="status" /></label>
          <Select v-model="form.status">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="aktiv">Aktiv</SelectItem>
              <SelectItem value="inaktiv">Inaktiv</SelectItem>
              <SelectItem value="verkauft">Verkauft</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Vermarktungsart <FieldExportBadges field="marketing_type" /></label>
          <Select v-model="form.marketing_type">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="kauf">Kauf</SelectItem>
              <SelectItem value="miete">Miete</SelectItem>
              <SelectItem value="pacht">Pacht</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Titel <FieldExportBadges field="title" /></label>
          <Input v-model="form.title" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Untertitel <FieldExportBadges field="subtitle" /></label>
          <Input v-model="form.subtitle" :class="inputCls" placeholder="z.B. Wohnung in Mondsee" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Ref-ID <FieldExportBadges field="ref_id" /></label>
          <Input v-model="form.ref_id" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Adresse -->
      <AccordionSection title="Adresse" color="#3b82f6" :default-open="true" :filled="sectionCounts.adresse.filled" :total="sectionCounts.adresse.total">
        <div class="col-span-2 text-[11px] text-muted-foreground bg-blue-50 border border-blue-200 rounded-lg px-3 py-1.5 mb-1">
          Auf Portalen und der Website wird nur die Stadt angezeigt, nicht die genaue Adresse.
        </div>
        <div class="col-span-2 relative">
          <label :class="labelCls">Strasse <FieldExportBadges field="address" /></label>
          <Input
            :model-value="form.address"
            @update:model-value="onAddressInput"
            @beforeinput="onAddressBeforeInput"
            @focus="showSuggestions = suggestions.length > 0"
            @blur="onAddressBlur"
            :class="inputCls"
            placeholder="Strassenname ohne Hausnummer (Hausnummer ins Feld rechts)"
            autocomplete="off"
            inputmode="text"
          />
          <!-- Autocomplete-Dropdown -->
          <div v-if="showSuggestions"
               class="absolute left-0 right-0 top-full mt-1 bg-white border border-zinc-200 rounded-lg shadow-lg z-50 max-h-64 overflow-y-auto">
            <button
              v-for="(s, i) in suggestions"
              :key="i"
              type="button"
              @mousedown.prevent="pickSuggestion(s)"
              class="w-full text-left px-3 py-2 hover:bg-zinc-50 border-b border-zinc-100 last:border-b-0 text-[12px]"
            >
              <div class="font-medium text-zinc-900 truncate">
                {{ s.street || s.display_name.split(',')[0] }}
                <span v-if="s.house_number" class="text-muted-foreground font-normal">{{ s.house_number }}</span>
              </div>
              <div class="text-[10.5px] text-muted-foreground truncate">
                {{ [s.zip, s.city].filter(Boolean).join(' ') || s.display_name }}
              </div>
            </button>
          </div>
          <p v-if="loadingSuggestions" class="absolute right-2 top-7 text-[10px] text-muted-foreground">sucht…</p>
        </div>
        <div>
          <label :class="labelCls">Hausnummer <FieldExportBadges field="house_number" /></label>
          <Input v-model="form.house_number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">PLZ <FieldExportBadges field="zip" /></label>
          <Input v-model="form.zip" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stadt <FieldExportBadges field="city" /></label>
          <Input v-model="form.city" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stiege <FieldExportBadges field="staircase" /></label>
          <Input v-model="form.staircase" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Tür <FieldExportBadges field="door" /></label>
          <Input v-model="form.door" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Etage <FieldExportBadges field="address_floor" /></label>
          <Input v-model="form.address_floor" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Breitengrad <FieldExportBadges field="latitude" /></label>
          <Input v-model="form.latitude" type="number" step="0.0000001" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Längengrad <FieldExportBadges field="longitude" /></label>
          <Input v-model="form.longitude" type="number" step="0.0000001" :class="inputCls" />
        </div>
        <div v-if="form.latitude && form.longitude" class="col-span-2">
          <div class="text-[10.5px] text-muted-foreground mb-1 flex items-center gap-1.5">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Vorschau: So wird die Lage auf der Website angezeigt — nur Umkreis (ca. 350 m), nicht die genaue Adresse.
          </div>
          <MapPreview :lat="Number(form.latitude)" :lng="Number(form.longitude)" />
        </div>
      </AccordionSection>

    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Allgemeines -->
      <AccordionSection title="Allgemeines" color="#8b5cf6" :default-open="true" :filled="sectionCounts.allgemeines.filled" :total="sectionCounts.allgemeines.total">
        <div>
          <label :class="labelCls">Objektart <FieldExportBadges field="object_type" /></label>
          <Select v-model="form.object_type">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="Eigentumswohnung">Eigentumswohnung</SelectItem>
              <SelectItem value="Haus">Haus</SelectItem>
              <SelectItem value="Einfamilienhaus">Einfamilienhaus</SelectItem>
              <SelectItem value="Grundstueck">Grundstück</SelectItem>
              <SelectItem value="Neubauprojekt">Neubauprojekt</SelectItem>
              <SelectItem value="Gartenwohnung">Gartenwohnung</SelectItem>
              <SelectItem value="Dachgeschosswohnung">Dachgeschosswohnung</SelectItem>
              <SelectItem value="Penthouse">Penthouse</SelectItem>
              <SelectItem value="Maisonette">Maisonette</SelectItem>
              <SelectItem value="Reihenhaus">Reihenhaus</SelectItem>
              <SelectItem value="Doppelhaushaelfte">Doppelhaushälfte</SelectItem>
              <SelectItem value="Gewerbe">Gewerbe</SelectItem>
              <SelectItem value="Buero">Büro</SelectItem>
              <SelectItem value="Anlage">Anlage</SelectItem>
              <SelectItem value="Sonstiges">Sonstiges</SelectItem>
              <SelectItem value="Neubau">Neubau</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Unterobjektart <FieldExportBadges field="object_subtype" /></label>
          <Select v-model="form.object_subtype">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="etagenwohnung">Etagenwohnung</SelectItem>
              <SelectItem value="penthouse">Penthouse</SelectItem>
              <SelectItem value="maisonette">Maisonette</SelectItem>
              <SelectItem value="dachgeschosswohnung">Dachgeschosswohnung</SelectItem>
              <SelectItem value="gartenwohnung">Gartenwohnung</SelectItem>
              <SelectItem value="erdgeschosswohnung">Erdgeschosswohnung</SelectItem>
              <SelectItem value="doppelhaushaelfte">Doppelhaushälfte</SelectItem>
              <SelectItem value="einfamilienhaus">Einfamilienhaus</SelectItem>
              <SelectItem value="reihenhaus">Reihenhaus</SelectItem>
              <SelectItem value="bungalow">Bungalow</SelectItem>
              <SelectItem value="villa">Villa</SelectItem>
              <SelectItem value="mehrfamilienhaus">Mehrfamilienhaus</SelectItem>
              <SelectItem value="bauernhaus">Bauernhaus</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Bauart <FieldExportBadges field="construction_type" /></label>
          <Select v-model="form.construction_type">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="massiv">Massiv</SelectItem>
              <SelectItem value="fertighaus">Fertighaus</SelectItem>
              <SelectItem value="holz">Holz</SelectItem>
              <SelectItem value="leichtbau">Leichtbau</SelectItem>
              <SelectItem value="sonstige">Sonstige</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Objektzustand <FieldExportBadges field="realty_condition" /></label>
          <Select v-model="form.realty_condition">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="erstbezug">Erstbezug</SelectItem>
              <SelectItem value="neuwertig">Neuwertig</SelectItem>
              <SelectItem value="gepflegt">Gepflegt</SelectItem>
              <SelectItem value="renovierungsbeduerftig">Renovierungsbedürftig</SelectItem>
              <SelectItem value="saniert">Saniert</SelectItem>
              <SelectItem value="teilsaniert">Teilsaniert</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Baujahr <FieldExportBadges field="construction_year" /></label>
          <Input v-model="form.construction_year" type="number" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Um- oder Zubauten <FieldExportBadges field="conversions_additions" /></label>
          <textarea
            v-model="form.conversions_additions"
            rows="2"
            placeholder="z. B. Zubau 1997 mit zusätzlichem Schlafzimmer, Umbau 2015 im OG."
            class="w-full px-3 py-2 text-[13px] bg-zinc-100/80 border-0 rounded-lg resize-none focus:outline-none focus:ring-2 focus:ring-orange-500/30"
          />
        </div>
        <div>
          <label :class="labelCls">Möblierung <FieldExportBadges field="furnishing" /></label>
          <Input v-model="form.furnishing" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Eigentumsform <FieldExportBadges field="ownership_type" /></label>
          <Select v-model="form.ownership_type">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="wohnungseigentum">Wohnungseigentum</SelectItem>
              <SelectItem value="miteigentum">Miteigentum</SelectItem>
              <SelectItem value="alleineigentum">Alleineigentum</SelectItem>
              <SelectItem value="baurecht">Baurecht</SelectItem>
              <SelectItem value="genossenschaft">Genossenschaft</SelectItem>
              <SelectItem value="sonstige">Sonstige</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Wohneinheiten <FieldExportBadges field="total_units" /></label>
          <Input v-model="form.total_units" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Beziehbar ab <FieldExportBadges field="available_text" /></label>
          <Input v-model="form.available_text" :class="inputCls" placeholder="nach Vereinbarung" />
        </div>
      </AccordionSection>

      <!-- Zuordnung & Status -->
      <AccordionSection title="Zuordnung & Status" color="#f59e0b" :default-open="false" :filled="sectionCounts.zuordnung.filled" :total="sectionCounts.zuordnung.total">
        <div>
          <label :class="labelCls">Objektbetreuer / Makler <FieldExportBadges field="broker_id" /></label>
          <Select v-model="form.broker_id">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="b in brokerList" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Fertigstellung <FieldExportBadges field="construction_end" /></label>
          <Input v-model="form.construction_end" type="date" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Bauträger <FieldExportBadges field="builder_company" /></label>
          <Input v-model="form.builder_company" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Hausverwaltung <FieldExportBadges field="property_manager_id" /></label>
          <PropertyManagerPicker
            v-if="form.id"
            :property-id="form.id"
            :manager-id="form.property_manager_id"
            :manager-name="form.property_manager"
            @assigned="(m) => onManagerAssigned(form, m)"
          />
          <Input v-else v-model="form.property_manager" :class="inputCls" placeholder="Objekt zuerst speichern, dann Hausverwaltung wählen" disabled />
        </div>
        <div>
          <label :class="labelCls">Verfügbar ab <FieldExportBadges field="available_from" /></label>
          <Input v-model="form.available_from" type="date" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Inseriert seit <FieldExportBadges field="inserat_since" /></label>
          <Input v-model="form.inserat_since" type="date" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Marketing / Hervorhebung -->
      <AccordionSection title="Marketing & Hervorhebung" color="#EE7600" :default-open="false">
        <!-- Featured-Toggle -->
        <div class="col-span-2 flex items-start gap-3 py-2">
          <input
            type="checkbox"
            id="is_featured_checkbox"
            :checked="!!form.is_featured"
            @change="form.is_featured = $event.target.checked ? 1 : 0"
            class="mt-0.5 w-4 h-4 accent-[#EE7600] cursor-pointer"
          />
          <label for="is_featured_checkbox" class="flex-1 cursor-pointer">
            <div class="text-[13px] font-medium text-foreground">Als „Featured" markieren</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">
              Featured-Objekte erscheinen auf der Startseite im Hero-/Featured-Bereich.
            </div>
          </label>
        </div>

        <!-- Sortierungs-Reihenfolge (nur sichtbar wenn Featured) -->
        <div v-if="form.is_featured" class="col-span-2">
          <label :class="labelCls">Sortierung auf Startseite (niedriger = weiter vorne)</label>
          <Input v-model.number="form.featured_order" type="number" step="1" min="0" inputmode="numeric"
                 placeholder="z.B. 1 = ganz oben, leer = unsortiert" :class="inputCls" />
        </div>

        <!-- Badge-Label -->
        <div class="col-span-2">
          <label :class="labelCls">Badge auf Card (Overlay-Label)</label>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="b in [
                { value: '', label: 'Kein Badge' },
                { value: 'NEU', label: 'NEU' },
                { value: 'EXKLUSIV', label: 'EXKLUSIV' },
                { value: 'REDUZIERT', label: 'REDUZIERT' },
                { value: 'TOP', label: 'TOP' },
                { value: 'DEMNÄCHST', label: 'DEMNÄCHST' },
              ]"
              :key="b.value"
              type="button"
              @click="form.badge = b.value"
              :class="[
                'px-3 py-1.5 rounded-full text-[12px] font-medium border transition-all',
                (form.badge || '') === b.value
                  ? 'bg-orange-500 text-white border-transparent shadow-md shadow-orange-500/40'
                  : 'bg-card border-border text-foreground shadow-sm hover:shadow'
              ]"
            >
              {{ b.label }}
            </button>
          </div>
          <p class="text-[10px] text-muted-foreground mt-1">
            Das Badge wird auf der Website-Card als Label überlagert. Freitext im Input möglich, oder einen der Presets wählen.
          </p>
          <Input v-model="form.badge" :class="inputCls" placeholder="z.B. VERKAUFT BALD" maxlength="30" />
        </div>
      </AccordionSection>

      <!-- Ausstattung & Merkmale — jetzt unter Flächen & Ausstattung -->
    </div>
  </div>
</template>
