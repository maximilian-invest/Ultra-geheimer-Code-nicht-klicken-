<script setup>
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

function onManagerAssigned(form, manager) {
  if (manager) {
    form.property_manager_id = manager.id;
    form.property_manager = manager.company_name;
  } else {
    form.property_manager_id = null;
    form.property_manager = '';
  }
}

defineProps({
  form: { type: Object, required: true },
  brokerList: { type: Array, default: () => [] },
  isNewbuild: { type: Boolean, default: false },
  isChild: { type: Boolean, default: false },
  features: { type: Array, default: () => [] },
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
      <AccordionSection title="Objekt" color="#ea580c" :default-open="true">
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
        <div>
          <label :class="labelCls">Projektname <FieldExportBadges field="project_name" /></label>
          <Input v-model="form.project_name" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Ref-ID <FieldExportBadges field="ref_id" /></label>
          <Input v-model="form.ref_id" :class="inputCls" />
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Allgemeinräume <FieldExportBadges field="common_areas" /></label>
          <Textarea v-model="form.common_areas" rows="3" placeholder="z.B. Fahrradraum, Waschküche, Kinderwagenabstellplatz, Gemeinschaftsgarten..." class="text-[13px] bg-zinc-100/80 border-0" />
        </div>
      </AccordionSection>

      <!-- Adresse -->
      <AccordionSection title="Adresse" color="#3b82f6" :default-open="true">
        <div class="col-span-2 text-[11px] text-muted-foreground bg-blue-50 border border-blue-200 rounded-lg px-3 py-1.5 mb-1">
          Auf Portalen und der Website wird nur die Stadt angezeigt, nicht die genaue Adresse.
        </div>
        <div class="col-span-2">
          <label :class="labelCls">Strasse <FieldExportBadges field="address" /></label>
          <Input v-model="form.address" :class="inputCls" />
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
      </AccordionSection>

    </div>

    <!-- Right column -->
    <div class="flex flex-col gap-4">
      <!-- Allgemeines -->
      <AccordionSection title="Allgemeines" color="#8b5cf6" :default-open="true">
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
        <div>
          <label :class="labelCls">Möblierung <FieldExportBadges field="furnishing" /></label>
          <Input v-model="form.furnishing" :class="inputCls" />
        </div>
        <div>
          <label :class="labelCls">Zimmer <FieldExportBadges field="rooms_amount" /></label>
          <Input v-model="form.rooms_amount" type="number" step="0.5" :class="inputCls" />
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
      <AccordionSection title="Zuordnung & Status" color="#f59e0b" :default-open="false">
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

      <!-- Ausstattung & Merkmale — jetzt unter Flächen & Ausstattung -->
    </div>
  </div>
</template>
