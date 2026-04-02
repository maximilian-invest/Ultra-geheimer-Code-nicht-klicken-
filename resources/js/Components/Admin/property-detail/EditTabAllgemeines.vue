<script setup>
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import AccordionSection from "./AccordionSection.vue";

defineProps({
  form: { type: Object, required: true },
  brokerList: { type: Array, default: () => [] },
  isNewbuild: { type: Boolean, default: false },
  isChild: { type: Boolean, default: false },
  features: { type: Array, default: () => [] },
});

const inputCls = "h-9 text-[13px] border border-input rounded-lg bg-background";
const selectCls = "h-9 text-[13px] border border-input rounded-lg bg-background";
const labelCls = "text-[11px] text-muted-foreground font-medium mb-1.5 block";
</script>

<template>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-4">
    <!-- Left column -->
    <div class="flex flex-col gap-4">
      <!-- Objekt -->
      <AccordionSection title="Objekt" color="#ea580c" :default-open="true">
        <div>
          <label :class="labelCls">Status</label>
          <Select v-model="form.status">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="auftrag">Auftrag</SelectItem>
              <SelectItem value="aktiv">Aktiv</SelectItem>
              <SelectItem value="verkauft">Verkauft</SelectItem>
              <SelectItem value="reserviert">Reserviert</SelectItem>
              <SelectItem value="inaktiv">Inaktiv</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Objektbetreuer</label>
          <Select v-model="form.broker_id">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="b in brokerList" :key="b.id" :value="b.id">{{ b.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Vermarktungsart</label>
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
          <label :class="labelCls">Titel</label>
          <Input v-model="form.title" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Untertitel</label>
          <Input v-model="form.subtitle" :class="inputCls" placeholder="z.B. Wohnung in Mondsee" />
        </div>
        <div>
          <label :class="labelCls">Projektname</label>
          <Input v-model="form.project_name" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Ref-ID</label>
          <Input v-model="form.ref_id" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Adresse -->
      <AccordionSection title="Adresse" color="#3b82f6" :default-open="true">
        <div class="col-span-2">
          <label :class="labelCls">Strasse</label>
          <Input v-model="form.address" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Hausnummer</label>
          <Input v-model="form.house_number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">PLZ</label>
          <Input v-model="form.zip" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stadt</label>
          <Input v-model="form.city" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Stiege</label>
          <Input v-model="form.staircase" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Tür</label>
          <Input v-model="form.door" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Etage</label>
          <Input v-model="form.address_floor" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Breitengrad</label>
          <Input v-model="form.latitude" type="number" step="0.0000001" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Längengrad</label>
          <Input v-model="form.longitude" type="number" step="0.0000001" :class="inputCls" />
        </div>
      </AccordionSection>

      <!-- Energieausweis -->
      <AccordionSection title="Energieausweis" color="#22c55e" :default-open="false">
        <div>
          <label :class="labelCls">Ausweistyp</label>
          <Select v-model="form.energy_certificate">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="Verbrauch">Verbrauch</SelectItem>
              <SelectItem value="Bedarf">Bedarf</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Energieklasse</label>
          <Select v-model="form.heating_demand_class">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="A++">A++</SelectItem>
              <SelectItem value="A+">A+</SelectItem>
              <SelectItem value="A">A</SelectItem>
              <SelectItem value="B">B</SelectItem>
              <SelectItem value="C">C</SelectItem>
              <SelectItem value="D">D</SelectItem>
              <SelectItem value="E">E</SelectItem>
              <SelectItem value="F">F</SelectItem>
              <SelectItem value="G">G</SelectItem>
              <SelectItem value="H">H</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">HWB kWh/m²a</label>
          <Input v-model="form.heating_demand_value" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">fGEE</label>
          <Input v-model="form.energy_efficiency_value" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Energieträger</label>
          <Input v-model="form.energy_primary_source" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Gültig bis</label>
          <Input v-model="form.energy_valid_until" type="date" :class="inputCls" />
        </div>
      </AccordionSection>
    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Allgemeines -->
      <AccordionSection title="Allgemeines" color="#8b5cf6" :default-open="true">
        <div>
          <label :class="labelCls">Objektart</label>
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
          <label :class="labelCls">Unterobjektart</label>
          <Select v-model="form.sub_type">
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
          <label :class="labelCls">Bauart</label>
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
          <label :class="labelCls">Objektzustand</label>
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
          <label :class="labelCls">Baujahr</label>
          <Input v-model="form.construction_year" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Möblierung</label>
          <Input v-model="form.furnishing" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Zimmer</label>
          <Input v-model="form.rooms_amount" type="number" step="0.5" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Eigentumsform</label>
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
          <label :class="labelCls">Wohneinheiten</label>
          <Input v-model="form.total_units" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Beziehbar ab</label>
          <Input v-model="form.available_text" :class="inputCls" placeholder="nach Vereinbarung" />
        </div>
      </AccordionSection>

      <!-- Zuordnung & Status -->
      <AccordionSection title="Zuordnung & Status" color="#f59e0b" :default-open="false">
        <div>
          <label :class="labelCls">Makler</label>
          <Select v-model="form.broker_id">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="b in brokerList" :key="b.id" :value="b.id">{{ b.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Fertigstellung</label>
          <Input v-model="form.construction_end" type="date" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Bauträger</label>
          <Input v-model="form.builder_company" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Hausverwaltung</label>
          <Input v-model="form.property_manager" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Verfügbar ab</label>
          <Input v-model="form.available_from" type="date" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Inseriert seit</label>
          <Input v-model="form.inserat_since" type="date" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Plattformen</label>
          <Input v-model="form.platforms" :class="inputCls" placeholder="willhaben, immoscout24" />
        </div>
      </AccordionSection>

      <!-- Ausstattung & Merkmale -->
      <AccordionSection v-if="!isChild" title="Ausstattung & Merkmale" color="#06b6d4" :default-open="false">
        <div>
          <label :class="labelCls">Qualität</label>
          <Select v-model="form.quality">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="einfach">Einfach</SelectItem>
              <SelectItem value="normal">Normal</SelectItem>
              <SelectItem value="gehoben">Gehoben</SelectItem>
              <SelectItem value="luxurioes">Luxuriös</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Sanierungsjahr</label>
          <Input v-model="form.year_renovated" type="number" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Küche</label>
          <Select v-model="form.kitchen_type">
            <SelectTrigger :class="selectCls"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="einbaukueche">Einbauküche</SelectItem>
              <SelectItem value="pantry">Pantry</SelectItem>
              <SelectItem value="offen">Offen</SelectItem>
              <SelectItem value="keine">Keine</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <label :class="labelCls">Heizung</label>
          <Input v-model="form.heating" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Bodenbelag</label>
          <Input v-model="form.flooring" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Badausstattung</label>
          <Input v-model="form.bathroom_equipment" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Ausrichtung</label>
          <Input v-model="form.orientation" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Merkmale</label>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="feat in features"
              :key="feat.key"
              type="button"
              @click="form[feat.key] = !form[feat.key]"
              class="px-2.5 py-1 rounded-md text-[12px] font-medium transition-colors"
              :class="form[feat.key] ? 'bg-zinc-900 text-white' : 'border border-border text-foreground hover:bg-zinc-50'"
            >
              {{ feat.label }}
            </button>
          </div>
        </div>
      </AccordionSection>
    </div>
  </div>
</template>
