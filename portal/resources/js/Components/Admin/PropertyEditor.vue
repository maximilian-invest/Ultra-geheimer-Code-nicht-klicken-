<script setup>
import { ref, inject, computed, reactive, watch, onMounted, nextTick } from "vue";
import {
  Home, Search, Plus, Upload, ChevronRight, ChevronLeft,
  Trash2, Save, Check, X, Image, Globe, MapPin, Ruler,
  Thermometer, FileText, Sparkles, GripVertical, Star,
  Eye, EyeOff, Pencil, Building2, Layers, MoreVertical,
  ArrowUpRight, BadgeCheck, Clock, AlertCircle, Settings,
  ParkingSquare, LayoutGrid, LandPlot, Warehouse, Building
} from "lucide-vue-next";

const API   = inject("API");
const toast = inject("toast");

// Props & Emits
const props = defineProps({
  propertyId: { type: [Number, null], default: null },
  visible: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "saved"]);

// State
const property = ref(null);
const images = ref([]);
const portals = ref([]);
const immojiConnected = ref(false);
const immojiEmail = ref('');
const immojiPassword = ref('');
const immojiConnecting = ref(false);
const immojiPushing = ref(false);
const immojiPortals = ref(null);
const immojiPortalLoading = ref(false);
const immojiPortalSaving = ref({});
const immojiCapacity = ref(null);
const wizardStep = ref(0);
const loading = ref(false);
const saving = ref(false);
const imageUploading = ref(false);
const dragOver = ref(false);
const parseLoading = ref(false);
const newFileInput = ref(null);

// Analyze file for NEW properties (no property_id needed)
async function analyzeNewFile(event) {
  const files = event.target.files;
  if (!files || !files.length) return;
  parseLoading.value = true;
  try {
    // If property is saved, upload files first then parse
    if (property.value?.id) {
      for (const file of files) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('property_id', property.value.id);
        fd.append('label', file.name.replace(/\.[^.]+$/, ''));
        await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      }
      // Now parse all files
      const r2 = await fetch(API.value + '&action=parse_expose&property_id=' + property.value.id, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ property_id: property.value.id }),
      });
      const txt = await r2.text();
      if (!txt.startsWith('<')) {
        const d = JSON.parse(txt);
        if (d.extracted?.fields || d.fields) {
          const fields = d.extracted?.fields || d.fields;
          for (const [k, v] of Object.entries(fields)) {
            if (v !== null && v !== undefined && v !== '' && property.value) {
              property.value[k] = v;
            }
          }
        }
        if (d.extracted?.units || d.units) {
          units.value = d.extracted?.units || d.units;
        }
        // Merge parking into units with is_parking flag
        const parkingData = d.extracted?.parking || d.parking || [];
        if (parkingData.length) {
          units.value = [...units.value, ...parkingData.map(p => ({ ...p, is_parking: 1 }))];
        }
        toast(files.length + ' Datei(en) analysiert!');
      mapEnergyAliases(property.value);
      }
    } else {
      // No property saved yet: analyze each file directly via analyze_file
      for (const file of files) {
        const fd = new FormData();
        fd.append('file', file);
        const r = await fetch(API.value + '&action=analyze_file', { method: 'POST', body: fd });
        const txt = await r.text();
        if (!txt.startsWith('<')) {
          const d = JSON.parse(txt);
          if (d.fields) {
            for (const [k, v] of Object.entries(d.fields)) {
              if (v !== null && v !== undefined && v !== '' && property.value) {
                property.value[k] = v;
              }
            }
          }
          if (d.units && d.units.length) {
            units.value = [...units.value, ...d.units];
          }
          if (d.parking && d.parking.length) {
            units.value = [...units.value, ...d.parking.map(p => ({ ...p, is_parking: 1 }))];
          }
          toast(file.name + ' analysiert!');
        mapEnergyAliases(property.value);
        }
      }
    }
  } catch (e) { toast('Fehler: ' + e.message); }
  parseLoading.value = false;
  if (newFileInput.value) newFileInput.value.value = '';
}

// Map AI energy field aliases to correct DB field names
function mapEnergyAliases(prop) {
  if (!prop) return;
  const aliases = {
    'energy_hwb': 'heating_demand_value',
    'energy_fgee': 'energy_efficiency_value',
    'energy_class': 'heating_demand_class',
  };
  for (const [from, to] of Object.entries(aliases)) {
    if (prop[from] && !prop[to]) prop[to] = prop[from];
  }
}
const fileSelectOpen = ref(false);
const availableFiles = ref([]);
const selectedFileIds = ref([]);
const units = ref([]);
const unitsLoading = ref(false);
const unitSaving = ref({});
const showTypeSelect = ref(false);

// Type categories for step 0
const typeCategories = [
  { key: "wohnung", label: "Wohnung", icon: Building2, desc: "Eigentumswohnung, Gartenwohnung, Penthouse, etc.", types: ["Eigentumswohnung", "Gartenwohnung", "Dachgeschosswohnung", "Penthouse", "Maisonette"], color: "bg-blue-50 border-blue-200 hover:border-blue-400 text-blue-700" },
  { key: "haus", label: "Haus", icon: Home, desc: "Einfamilienhaus, Reihenhaus, Doppelhaushaelfte", types: ["Haus", "Einfamilienhaus", "Reihenhaus", "Doppelhaushaelfte"], color: "bg-emerald-50 border-emerald-200 hover:border-emerald-400 text-emerald-700" },
  { key: "grundstueck", label: "Grundstueck", icon: LandPlot, desc: "Baugrund, Freizeitgrund, Landwirtschaft", types: ["Grundstueck"], color: "bg-amber-50 border-amber-200 hover:border-amber-400 text-amber-700" },
  { key: "neubauprojekt", label: "Neubauprojekt", icon: Building, desc: "Projekt mit Einheiten & Stellplaetzen", types: ["Neubauprojekt", "Neubau"], color: "bg-violet-50 border-violet-200 hover:border-violet-400 text-violet-700" },
  { key: "sonstige", label: "Gewerbe / Sonstiges", icon: Warehouse, desc: "Buero, Anlage, Sonstiges", types: ["Gewerbe", "Buero", "Anlage", "Sonstiges"], color: "bg-zinc-50 border-zinc-200 hover:border-zinc-400 text-zinc-700" },
];

const isNeubauprojekt = computed(() => {
  if (!property.value) return false;
  const t = (property.value.object_type || property.value.type || "").toLowerCase();
  return t === "neubauprojekt" || t === "neubau";
});

// Dynamic wizard steps
const isChildProperty = computed(() => !!property.value?.parent_id);

const steps = computed(() => {
  // Child properties: only basic data, descriptions, images, portals
  if (isChildProperty.value) {
    return [
      { key: "basis",    label: "Basisdaten",      icon: Home },
      { key: "flaechen", label: "Flaechen & Raeume", icon: Ruler },
      { key: "energie",  label: "Energie",          icon: Thermometer },
      { key: "texte",    label: "Beschreibungen",   icon: FileText },
      { key: "bilder",   label: "Bilder",           icon: Image },
      { key: "portale",  label: "Portale",          icon: Globe },
    ];
  }
  const base = [
    { key: "basis",      label: "Basisdaten",      icon: Home },
    { key: "flaechen",   label: "Flaechen & Raeume", icon: Ruler },
    { key: "ausstattung",label: "Ausstattung",     icon: Settings },
  ];
  base.push(
    { key: "energie",    label: "Energie",          icon: Thermometer },
    { key: "texte",      label: "Beschreibungen",   icon: FileText },
    { key: "bilder",     label: "Bilder",           icon: Image },
    { key: "portale",    label: "Portale",          icon: Globe },
  );
  return base;
});

// Find step index by key
function stepIndex(key) {
  return steps.value.findIndex(s => s.key === key);
}

// Options
const propertyTypes = [
  "Eigentumswohnung", "Haus", "Einfamilienhaus", "Grundstueck",
  "Neubauprojekt", "Gartenwohnung", "Dachgeschosswohnung", "Penthouse",
  "Maisonette", "Reihenhaus", "Doppelhaushaelfte", "Gewerbe",
  "Buero", "Anlage", "Sonstiges", "Neubau"
];

const transactionTypes = [
  { value: "kauf", label: "Kauf" },
  { value: "miete", label: "Miete" },
  { value: "pacht", label: "Pacht" },
];

const conditionOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "erstbezug", label: "Erstbezug" },
  { value: "neuwertig", label: "Neuwertig" },
  { value: "gepflegt", label: "Gepflegt" },
  { value: "renovierungsbeduerftig", label: "Renovierungsbeduerftig" },
  { value: "saniert", label: "Saniert" },
  { value: "teilsaniert", label: "Teilsaniert" },
  { value: "abbruchreif", label: "Abbruchreif" },
];

const qualityOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "einfach", label: "Einfach" },
  { value: "normal", label: "Normal" },
  { value: "gehoben", label: "Gehoben" },
  { value: "luxurioes", label: "Luxurioes" },
];

const energyTypeOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "Verbrauch", label: "Verbrauchsausweis" },
  { value: "Bedarf", label: "Bedarfsausweis" },
];

const kitchenOptions = [
  { value: "", label: "-- Bitte waehlen --" },
  { value: "ohne", label: "Ohne Kueche" },
  { value: "offen", label: "Offene Kueche" },
  { value: "einbau", label: "Einbaukueche" },
  { value: "pantry", label: "Pantrykueche" },
];

const imageCategories = [
  { value: "titelbild", label: "Titelbild" },
  { value: "innenansicht", label: "Innenansicht" },
  { value: "aussenansicht", label: "Aussenansicht" },
  { value: "grundriss", label: "Grundriss" },
  { value: "badezimmer", label: "Badezimmer" },
  { value: "kueche", label: "Kueche" },
  { value: "schlafzimmer", label: "Schlafzimmer" },
  { value: "wohnzimmer", label: "Wohnzimmer" },
  { value: "balkon_terrasse", label: "Balkon/Terrasse" },
  { value: "garten", label: "Garten" },
  { value: "garage_stellplatz", label: "Garage/Stellplatz" },
  { value: "keller", label: "Keller" },
  { value: "umgebung", label: "Umgebung" },
  { value: "sonstiges", label: "Sonstiges" },
];

// Watcher for visible prop
watch(() => props.visible, async (newVal) => {
  if (newVal && props.propertyId) {
    showTypeSelect.value = false;
    await loadProperty(props.propertyId);
    checkImmojiStatus();
  } else if (newVal && !props.propertyId) {
    newProperty();
    showTypeSelect.value = true;
  }
});

// Methods
function newProperty() {
  property.value = {
    id: null, ref_id: "", openimmo_id: "", title: "", project_name: "",
    address: "", latitude: null, longitude: null, city: "", zip: "",
    type: "Eigentumswohnung", property_category: "", sub_type: "", transaction_type: "kauf",
    status: "auftrag", price: null, rent_cold: null, rent_warm: null, rent_deposit: null,
    price_per_m2: null, operating_costs: null, reserve_fund: null,
    size_m2: null, area_living: null, area_land: null, area_usable: null,
    area_balcony: null, area_terrace: null, area_garden: null, area_basement: null,
    area_loggia: null, area_garage: null, area_office: null,
    rooms: null, bedrooms: null, bathrooms: null, toilets: null,
    floor_count: null, floor_number: null,
    energy_certificate: "", energy_hwb: null, energy_type: "", energy_class: "",
    energy_fgee: null, energy_primary_source: "", energy_valid_until: null,
    year_built: null, year_renovated: null, heating: "",
    condition_note: "", object_condition: "", quality: "",
    flooring: "", bathroom_equipment: "", kitchen_type: "",
    furnishing: "", orientation: "", noise_level: "",
    has_basement: false, has_garden: false, has_elevator: false,
    has_balcony: false, has_terrace: false, has_loggia: false,
    has_fitted_kitchen: false, has_air_conditioning: false,
    has_pool: false, has_sauna: false, has_fireplace: false,
    has_alarm: false, has_barrier_free: false, has_guest_wc: false,
    has_storage_room: false, has_washing_connection: false, has_cellar: false,
    garage_spaces: null, parking_spaces: null, parking_type: "", parking_price: null,
    description: "", description_location: "", description_equipment: "", description_other: "",
    highlights: "",
    owner_name: "", owner_phone: "", owner_email: "",
    contact_person: "", contact_phone: "", contact_email: "",
    commission_percent: null, commission_note: "", commission_total: null,
    commission_makler: null, buyer_commission_percent: null,
    buyer_commission_text: "", commission_incl_vat: true,
    builder_company: "", property_manager: "",
    construction_start: null, construction_end: null,
    move_in_date: null, available_from: null, available_text: "",
    total_units: null, plot_dedication: "", plot_buildable: false, plot_developed: false,
    platforms: "", inserat_since: null, is_published: false,
    expose_path: "", nebenkosten_path: "",
  };
  images.value = [];
  portals.value = [];
  units.value = [];
  wizardStep.value = 0;
}

function selectType(cat) {
  property.value.type = cat.types[0];
  if (cat.key === "neubauprojekt") {
    property.value.property_category = "newbuild";
  } else if (cat.key === "haus") {
    property.value.property_category = "house";
  } else if (cat.key === "wohnung") {
    property.value.property_category = "apartment";
  } else if (cat.key === "grundstueck") {
    property.value.property_category = "land";
  }
  showTypeSelect.value = false;
  wizardStep.value = 0;
}

async function loadProperty(propId) {
  loading.value = true;
  try {
    const [propRes, imgRes, portalRes] = await Promise.all([
      fetch(API.value + "&action=get_property&property_id=" + propId).then(r => r.json()),
      fetch(API.value + "&action=list_property_images&property_id=" + propId).then(r => r.json()),
      fetch(API.value + "&action=list_property_portals&property_id=" + propId).then(r => r.json()),
    ]);
    property.value = propRes.property || propRes;
    images.value = imgRes.images || [];
    portals.value = portalRes.portals || [];
    wizardStep.value = 0;
  } catch (e) {
    toast("Fehler beim Laden: " + e.message);
  }
  loading.value = false;
}

async function saveProperty() {
  saving.value = true;
  const wasNew = !property.value.id;
  try {
    const r = await fetch(API.value + "&action=save_full_property", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(property.value),
    });
    const d = await r.json();
    if (d.success) {
      toast("Gespeichert");
      if (!property.value.id && d.property?.id) {
        property.value.id = d.property.id;
      }
      // After creating a new property, auto-save any extracted units
      if (wasNew && property.value.id && units.value.length > 0) {
        try {
          const unitPayload = { property_id: property.value.id, units: units.value.map(u => ({
            unit_number: u.unit_number, unit_type: u.unit_type, floor: u.floor,
            area_m2: u.area_m2, rooms_amount: u.rooms ?? u.rooms_amount,
            purchase_price: u.price ?? u.purchase_price,
            status: u.status || 'frei', balcony_terrace_m2: u.balcony_terrace_m2,
            garden_m2: u.garden_m2, is_parking: u.is_parking || 0,
          }))};
          const ur = await fetch(API.value + "&action=bulk_import_units", {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify(unitPayload),
          });
          const ud = await ur.json();
          if (ud.success) {
            toast((ud.created || 0) + " Einheiten + " + (ud.updated || 0) + " aktualisiert");
          }
        } catch (ue) { console.error("Auto-save units failed:", ue); }
      }
      emit("saved");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  saving.value = false;
}

// Auto-save property before going to units step (need property_id)
async function goToStep(idx) {
  const targetKey = steps.value[idx]?.key;
  if ((targetKey === "einheiten" || targetKey === "stellplaetze") && !property.value?.id) {
    toast("Speichere Objekt zuerst...");
    await saveProperty();
    if (!property.value?.id) return; // save failed
  }
  if ((targetKey === "einheiten" || targetKey === "stellplaetze") && property.value?.id) {
    await loadUnits(property.value.id);
  }
  wizardStep.value = idx;
}

async function goNext() {
  if (wizardStep.value < steps.value.length - 1) {
    await goToStep(wizardStep.value + 1);
  }
}

function goPrev() {
  if (wizardStep.value > 0) {
    wizardStep.value--;
  }
}

// ─── Units ───
async function loadUnits(propId) {
  unitsLoading.value = true;
  try {
    const r = await fetch(API.value + '&action=get_units&property_id=' + propId);
    const d = await r.json();
    if (d.units) units.value = d.units;
  } catch (e) { console.error('loadUnits error:', e); }
  unitsLoading.value = false;
}

const realUnits = computed(() => units.value.filter(u => !u.is_parking));
const parkingUnits = computed(() => units.value.filter(u => u.is_parking));

// Unit generator state
const unitGen = reactive({ prefix: "TOP", from: 1, count: 5, floors: 3 });
const parkingGen = reactive({ prefix: "Stellplatz", from: 1, to: 10, price: null, type: "Tiefgarage" });

function generateUnitRows() {
  const newUnits = [];
  const unitsPerFloor = Math.ceil(unitGen.count / unitGen.floors);
  let num = unitGen.from;
  for (let f = 0; f < unitGen.floors && newUnits.length < unitGen.count; f++) {
    for (let u = 0; u < unitsPerFloor && newUnits.length < unitGen.count; u++) {
      newUnits.push({
        _isNew: true,
        unit_number: unitGen.prefix + " " + num,
        unit_type: "Wohnung",
        floor: f,
        area_m2: null,
        rooms: null,
        price: null,
        balcony_terrace_m2: null,
        garden_m2: null,
        status: "frei",
        notes: "",
      });
      num++;
    }
  }
  units.value.push(...newUnits);
}

function addUnitRow() {
  const maxNum = realUnits.value.reduce((max, u) => {
    const m = (u.unit_number || "").match(/(\d+)/);
    return m ? Math.max(max, parseInt(m[1])) : max;
  }, 0);
  units.value.push({
    _isNew: true,
    unit_number: "TOP " + (maxNum + 1),
    unit_type: "Wohnung",
    floor: 0,
    area_m2: null,
    rooms: null,
    price: null,
    balcony_terrace_m2: null,
    garden_m2: null,
    status: "frei",
    notes: "",
    is_parking: 0,
  });
}

async function saveUnit(unit) {
  if (!property.value?.id) return;
  const key = unit.id || unit.unit_number;
  unitSaving.value[key] = true;
  try {
    const payload = {
      property_id: property.value.id,
      id: unit.id || null,
      unit_number: unit.unit_number,
      unit_type: unit.unit_type,
      floor: unit.floor,
      area_m2: unit.area_m2,
      rooms_amount: unit.rooms || unit.rooms_amount,
      purchase_price: unit.price || unit.purchase_price,
      status: unit.status || 'frei',
      balcony_terrace_m2: unit.balcony_terrace_m2,
      garden_m2: unit.garden_m2,
      parking: unit.parking,
      notes: unit.notes,
      assigned_parking: unit.assigned_parking,
      is_parking: unit.is_parking || 0,
    };
    const r = await fetch(API.value + "&action=save_property_unit", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
      if (d.unit?.id) unit.id = d.unit.id;
      unit._isNew = false;
      unit._dirty = false;
      toast("Einheit gespeichert");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  unitSaving.value[key] = false;
}

async function saveAllUnits() {
  const unsaved = units.value.filter(u => !u.is_parking && (u._isNew || u._dirty));
  for (const u of unsaved) {
    await saveUnit(u);
  }
  toast("Alle Einheiten gespeichert");
}

async function deleteUnit(unit, idx) {
  if (unit.id) {
  }
  units.value.splice(units.value.indexOf(unit), 1);
  toast("Entfernt");
}

async function bulkCreateParking() {
  if (!property.value?.id) return;
}

function addParkingRow() {
  const maxNum = parkingUnits.value.reduce((max, u) => {
    const m = (u.unit_number || "").match(/(\d+)/);
    return m ? Math.max(max, parseInt(m[1])) : max;
  }, 0);
  units.value.push({
    _isNew: true,
    unit_number: "Stellplatz " + (maxNum + 1),
    unit_type: "Tiefgarage",
    floor: -1,
    price: null,
    status: "frei",
    is_parking: 1,
  });
}

const floorLabel = (f) => {
  if (f === -1) return "UG";
  if (f === 0) return "EG";
  return f + ". OG";
};

const statusColor = (s) => {
  if (s === "verkauft") return "bg-red-100 text-red-700";
  if (s === "reserviert") return "bg-amber-100 text-amber-700";
  return "bg-emerald-100 text-emerald-700";
};

// Images
async function handleImageUpload(e) {
  const files = e.target?.files || e.dataTransfer?.files;
  if (!files || !files.length || !property.value?.id) return;
  dragOver.value = false;
  imageUploading.value = true;
  try {
    for (const file of files) {
      const fd = new FormData();
      fd.append("images[]", file);
      fd.append("property_id", property.value.id);
      await fetch(API.value + "&action=upload_property_image", { method: "POST", body: fd });
    }
    // Reload images
    const res = await fetch(API.value + "&action=list_property_images&property_id=" + property.value.id);
    const data = await res.json();
    if (data.images) images.value = data.images;
    toast("Bilder hochgeladen");
  } catch (err) {
    toast("Upload fehlgeschlagen: " + err.message);
  }
  imageUploading.value = false;
  if (e.target) e.target.value = "";
}

async function setTitleImage(img) {
  await fetch(API.value + "&action=update_property_image", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: img.id, is_title_image: 1 }),
  });
  images.value.forEach(i => i.is_title_image = i.id === img.id ? 1 : 0);
  toast("Titelbild gesetzt");
}

async function deleteImage(img) {
  await fetch(API.value + "&action=delete_property_image", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: img.id }),
  });
  images.value = images.value.filter(i => i.id !== img.id);
  toast("Bild geloescht");
}

async function updateImageCategory(img, cat) {
  await fetch(API.value + "&action=update_property_image", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id: img.id, category: cat }),
  });
  img.category = cat;
}

// Expose - file selection + parse
async function openFileSelect() {
  if (!property.value?.id) { toast("Bitte zuerst speichern"); return; }
  try {
    const r = await fetch(API.value + "&action=get_property_files&property_id=" + property.value.id);
    const d = await r.json();
    availableFiles.value = d.files || [];
    selectedFileIds.value = (d.files || []).map(f => f.id);
  } catch (e) { toast("Fehler beim Laden der Dateien: " + e.message); }
  fileSelectOpen.value = true;
}

async function uploadAndAddFiles(event) {
  const files = event.target.files;
  if (!files || !files.length || !property.value?.id) return;
  for (const file of files) {
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('property_id', property.value.id);
      fd.append('label', file.name.replace(/\.[^.]+$/, ''));
      const r = await fetch(API.value + '&action=upload_property_file', { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success && d.file) {
        availableFiles.value.push(d.file);
        selectedFileIds.value.push(d.file.id);
      }
    } catch(e) { console.error(e); }
  }
  event.target.value = '';
  toast(files.length + ' Datei(en) hochgeladen');
}

async function runParseWithFiles() {
  fileSelectOpen.value = false;
  parseLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=parse_expose&property_id=" + property.value.id, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ property_id: property.value.id, file_ids: selectedFileIds.value }),
    });
    const _txt = await r.text();
    if (_txt.startsWith("<!") || _txt.startsWith("<html")) { toast("Session abgelaufen, bitte Seite neu laden"); parseLoading.value = false; return; }
    const d = JSON.parse(_txt);
    if (d.error) { toast(d.error); }
    else {
      const result = d.extracted || d;
      if (result.fields) {
        for (const [k, v] of Object.entries(result.fields)) {
          if (v !== null && v !== undefined && v !== '' && property.value) {
            property.value[k] = v;
          }
        }
      }
      toast("Dateien analysiert und Felder aktualisiert!");
    }
  } catch (e) { toast("Fehler: " + e.message); }
  parseLoading.value = false;
}

// Portals
const PORTAL_TO_IMMOJI = {
  'willhaben': 'willhabenExportEnabled',
  'immowelt': 'immoweltExportEnabled',
  'immoscout24': 'immoscoutExportEnabled',
};

async function savePortal(portalName, enabled) {
  // Special handling for Immoji: push data when enabling
  if (portalName === 'immoji' && enabled) {
    await pushToImmoji();
    return;
  }
  if (!property.value?.id) return;
  const r = await fetch(API.value + "&action=save_property_portal", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      property_id: property.value.id,
      portal_name: portalName,
      sync_enabled: enabled ? 1 : 0,
      status: enabled ? "active" : "draft",
    }),
  });
  const d = await r.json();
  if (d.success) {
    const res = await fetch(API.value + "&action=list_property_portals&property_id=" + property.value.id).then(r => r.json());
    portals.value = res.portals || [];
    toast("Portal-Status aktualisiert");

    // Also sync to Immoji if this portal has an Immoji mapping and object is on Immoji
    const immojiField = PORTAL_TO_IMMOJI[portalName];
    if (immojiField && immojiConnected.value && property.value?.openimmo_id) {
      try {
        await fetch(API.value + "&action=immoji_set_portals", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ property_id: property.value.id, portals: { [immojiField]: enabled } })
        });
        if (immojiPortals.value) immojiPortals.value[immojiField] = enabled;
        toast("Immoji " + portalName + (enabled ? " aktiviert" : " deaktiviert"));
      } catch (e) { /* silent */ }
    }
  }
}

function getPortalStatus(name) {
  const p = portals.value.find(x => x.portal_name === name);
  return p ? p.realty_status : "draft";
}

function isPortalEnabled(name) {
  const p = portals.value.find(x => x.portal_name === name);
  return p ? !!p.sync_enabled : false;
}

async function checkImmojiStatus() {
  try {
    const r = await fetch(API.value + '&action=immoji_status');
    const d = await r.json();
    immojiConnected.value = d.connected || false;
    if (immojiConnected.value) { loadImmojiPortals(); loadImmojiCapacity(); }
  } catch(e) { immojiConnected.value = false; }
}

async function connectImmoji() {
  if (!immojiEmail.value.trim() || !immojiPassword.value.trim()) return;
  immojiConnecting.value = true;
  try {
    const r = await fetch(API.value + '&action=immoji_connect', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ email: immojiEmail.value.trim(), password: immojiPassword.value.trim() })
    });
    const d = await r.json();
    if (d.success) {
      immojiConnected.value = true;
      immojiEmail.value = '';
      immojiPassword.value = '';
      toast(d.message || "Erfolg");
    } else {
      toast(d.message || "Fehler");
    }
  } catch(e) {
    toast("Verbindungsfehler");
  }
  immojiConnecting.value = false;
}

async function disconnectImmoji() {
  try {
    await fetch(API.value + '&action=immoji_disconnect', { method: 'POST' });
    immojiConnected.value = false;
    toast("Immoji getrennt");
  } catch(e) {}
}

async function pushToImmoji() {
  if (!property.value?.id) return;
  immojiPushing.value = true;
  try {
    const r = await fetch(API.value + '&action=immoji_push', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ property_id: property.value?.id })
    });
    const d = await r.json();
    if (d.success) {
      toast(d.message || "Erfolg");
      const pr = await fetch(API.value + '&action=list_property_portals&property_id=' + property.value?.id);
      const pd = await pr.json();
      portals.value = pd.portals || [];
      await loadImmojiPortals();
    } else {
      toast(d.message || "Fehler");
    }
  } catch(e) {
    toast("Upload fehlgeschlagen");
  }
  immojiPushing.value = false;
}

const IMMOJI_PORTALS = [
  { key: "willhabenExportEnabled", label: "willhaben.at", color: "bg-orange-500", lastKey: "willhabenLastExport", capKey: "willhaben" },
  { key: "immoweltExportEnabled", label: "immowelt.at", color: "bg-red-500", lastKey: "immoweltLastExport", capKey: "immowelt" },
  { key: "immoscoutExportEnabled", label: "ImmobilienScout24", color: "bg-blue-500", lastKey: "immoscoutLastExport", capKey: "immoscout" },
  { key: "dibeoExportEnabled", label: "Dibeo", color: "bg-emerald-500", lastKey: "dibeoLastExport", capKey: "dibeo" },
  { key: "kurierExportEnabled", label: "Kurier", color: "bg-purple-500", lastKey: "kurierLastExport", capKey: "kurier" },
  { key: "immoSNExportEnabled", label: "Immo SN", color: "bg-sky-500", lastKey: "immoSNLastExport", capKey: "immoSN" },
  { key: "allesKralleExportEnabled", label: "Alles Kralle", color: "bg-amber-500", capKey: "allesKralle" },
  { key: "homepageExportEnabled", label: "Immoji Homepage", color: "bg-violet-500" },
];

async function loadImmojiPortals() {
  if (!property.value?.id || !immojiConnected.value) return;
  immojiPortalLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=immoji_portal_status&property_id=" + property.value.id);
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

async function toggleImmojiPortal(fieldKey, currentValue) {
  if (!property.value?.id) return;
  immojiPortalSaving.value = { ...immojiPortalSaving.value, [fieldKey]: true };
  try {
    const r = await fetch(API.value + "&action=immoji_set_portals", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        property_id: property.value.id,
        portals: { [fieldKey]: !currentValue }
      })
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

</script>

<template>
  <!-- Full-screen overlay panel -->
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-[300] flex items-center justify-center p-4" @click.self="emit('close')">
      <!-- Backdrop -->
      <div class="fixed inset-0 bg-black/40 backdrop-blur-sm"></div>

      <!-- Panel -->
      <div class="relative bg-white rounded-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col">

        <!-- Header -->
        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-zinc-200/80 bg-white">
          <div class="flex items-center gap-3">
            <div>
              <h2 class="text-xl font-semibold text-zinc-900">
                {{ showTypeSelect ? 'Neues Objekt anlegen' : (property?.id ? property?.address || 'Objekt bearbeiten' : 'Neues Objekt') }}
              </h2>
              <p class="text-xs text-zinc-400 mt-0.5">
                {{ showTypeSelect ? 'Waehle den Objekttyp' : (property?.ref_id || 'Keine Ref-ID') }}
              </p>
            </div>
          </div>
          <button @click="emit('close')" class="p-2 rounded-xl text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 transition-all">
            <X :size="20" />
          </button>
        </div>

        <!-- Content area with scroll -->
        <div class="flex-1 overflow-y-auto">
          <div class="p-6">
            <div v-if="loading" class="flex items-center justify-center py-20">
              <div class="text-center">
                <div class="w-8 h-8 border-2 border-zinc-300 border-t-zinc-900 rounded-full animate-spin mx-auto mb-2"></div>
                <p class="text-sm text-zinc-500">Lade Objekt...</p>
              </div>
            </div>

            <!-- ═══ TYPE SELECT ═══ -->
            <div v-else-if="showTypeSelect" class="py-8">
              <h3 class="text-lg font-semibold text-zinc-900 text-center mb-2">Was moechtest du anlegen?</h3>
              <p class="text-sm text-zinc-500 text-center mb-8">Waehle den Typ — die passenden Felder werden automatisch angezeigt.</p>
              <div class="grid grid-cols-2 md:grid-cols-3 gap-4 max-w-3xl mx-auto">
                <button v-for="cat in typeCategories" :key="cat.key"
                  @click="selectType(cat)"
                  :class="[
                    'flex flex-col items-center gap-3 p-6 rounded-2xl border-2 transition-all duration-300 cursor-pointer active:scale-[0.97]',
                    cat.color
                  ]">
                  <div class="w-14 h-14 rounded-2xl bg-white/80 flex items-center justify-center shadow-sm">
                    <component :is="cat.icon" :size="28" />
                  </div>
                  <div class="text-center">
                    <p class="text-sm font-semibold">{{ cat.label }}</p>
                    <p class="text-xs opacity-70 mt-1">{{ cat.desc }}</p>
                  </div>
                </button>
              </div>
            </div>

            <!-- ═══ WIZARD ═══ -->
            <div v-else-if="property" class="space-y-6">
              <!-- Wizard Steps Nav -->
              <div class="flex items-center gap-1 p-1 bg-zinc-100/80 rounded-2xl overflow-x-auto" style="scrollbar-width: none; -ms-overflow-style: none;">
                <button v-for="(step, idx) in steps" :key="step.key"
                  @click="goToStep(idx)"
                  :class="[
                    'flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap transition-all duration-300 flex-shrink-0',
                    wizardStep === idx
                      ? 'bg-white text-zinc-900 shadow-sm'
                      : 'text-zinc-500 hover:text-zinc-700 hover:bg-white/50'
                  ]">
                  <component :is="step.icon" :size="15" />
                  {{ step.label }}
                </button>
              </div>

              <!-- ── Step: Basisdaten ── -->
              <div v-show="steps[wizardStep]?.key === 'basis'" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="space-y-5">
                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Objekt</h3>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Ref-ID</label>
                      <input v-model="property.ref_id" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Inserat-Titel</label>
                      <input v-model="property.title" type="text" placeholder="z.B. Traumhafte 3-Zi Wohnung mit Bergblick" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Objekttyp</label>
                        <select v-model="property.object_type" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                          <option v-for="t in propertyTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Transaktionsart</label>
                        <select v-model="property.marketing_type" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                          <option v-for="t in transactionTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                      </div>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Kategorie</label>
                      <select v-model="property.property_category" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                        <option value="">-- Keine --</option>
                        <option value="house">Haus</option>
                        <option value="apartment">Wohnung</option>
                        <option value="newbuild">Neubauprojekt</option>
                        <option value="land">Grundstueck</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Projektname</label>
                      <input v-model="property.project_name" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Plattformen</label>
                        <input v-model="property.platforms" type="text" placeholder="z.B. willhaben, immoscout24" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Inseriert seit</label>
                        <input v-model="property.inserat_since" type="date" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Adresse</h3>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Strasse & Hausnummer</label>
                      <input v-model="property.address" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">PLZ</label>
                        <input v-model="property.zip" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div class="col-span-2">
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Stadt</label>
                        <input v-model="property.city" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Breitengrad</label>
                        <input v-model="property.latitude" type="number" step="0.0000001" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Laengengrad</label>
                        <input v-model="property.longitude" type="number" step="0.0000001" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Objekt-Details</h3>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Baujahr</label>
                        <input v-model="property.construction_year" type="number" placeholder="z.B. 2024" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Sanierungsjahr</label>
                        <input v-model="property.year_renovated" type="number" placeholder="z.B. 2020" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Beziehbar ab</label>
                        <input v-model="property.available_from" type="date" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Verfuegbarkeit (Text)</label>
                        <input v-model="property.available_text" type="text" placeholder="sofort, nach Vereinbarung" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>
                </div>

                <div class="space-y-5">
                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Preis</h3>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">{{ isNeubauprojekt ? 'Gesamtvolumen (berechnet)' : 'Kaufpreis / Miete' }}</label>
                        <div class="relative">
                          <input v-model="property.purchase_price" type="number" step="0.01" :disabled="isNeubauprojekt" :class="isNeubauprojekt ? 'bg-zinc-100 cursor-not-allowed' : 'bg-zinc-50'" class="w-full pl-3 pr-10 py-2.5 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-400">EUR</span>
                        </div>
                        <p v-if="isNeubauprojekt" class="text-[10px] text-zinc-400 mt-1">Wird aus Einheiten berechnet</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Preis/m2</label>
                        <div class="relative">
                          <input v-model="property.price_per_m2" type="number" step="0.01" class="w-full pl-3 pr-10 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-400">EUR</span>
                        </div>
                      </div>
                    </div>

                    <div v-if="property.marketing_type === 'miete'" class="grid grid-cols-3 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Kaltmiete</label>
                        <input v-model="property.rental_price" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Warmmiete</label>
                        <input v-model="property.rent_warm" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Kaution</label>
                        <input v-model="property.rent_deposit" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Betriebskosten</label>
                        <input v-model="property.operating_costs" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Ruecklage</label>
                        <input v-model="property.maintenance_reserves" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-5">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Provision</h3>
                    <div class="space-y-3">
                      <p class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Intern (Eigentuemer)</p>
                      <div class="grid grid-cols-2 gap-4">
                        <div>
                          <label class="block text-xs font-medium text-zinc-500 mb-1.5">Provision Gesamt %</label>
                          <input v-model="property.commission_percent" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-zinc-500 mb-1.5">Provision Gesamt EUR</label>
                          <input v-model="property.commission_total" type="number" step="0.01" placeholder="0.00" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                        </div>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Provisionsnotiz (intern)</label>
                        <input v-model="property.commission_note" type="text" placeholder="Interne Notiz zur Provision" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                      </div>
                    </div>
                    <div class="border-t border-zinc-100 pt-4 space-y-3">
                      <p class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Oeffentlich (Plattformen)</p>
                      <div class="grid grid-cols-2 gap-4">
                        <div>
                          <label class="block text-xs font-medium text-zinc-500 mb-1.5">Makler-Provision %</label>
                          <input v-model="property.buyer_commission_percent" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-zinc-500 mb-1.5">Makler-Provision EUR</label>
                          <input v-model="property.commission_makler" type="number" step="0.01" placeholder="0.00" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                        </div>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Provisionstext (fuer Inserate)</label>
                        <input v-model="property.buyer_commission_text" type="text" placeholder="z.B. 3% zzgl. 20% USt." class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200" />
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Eigentuemer & Kontakt</h3>
                    <div class="grid grid-cols-3 gap-3">
                      <input v-model="property.owner_name" type="text" placeholder="Name" class="px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      <input v-model="property.owner_phone" type="text" placeholder="Telefon" class="px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      <input v-model="property.owner_email" type="text" placeholder="E-Mail" class="px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <p class="text-xs text-zinc-400">Ansprechpartner Inserat (falls abweichend)</p>
                    <div class="grid grid-cols-3 gap-3">
                      <input v-model="property.contact_person" type="text" placeholder="Name" class="px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      <input v-model="property.contact_phone" type="text" placeholder="Telefon" class="px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      <input v-model="property.contact_email" type="text" placeholder="E-Mail" class="px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                  </div>

                </div>
              </div>

              <!-- ── Step: Flaechen & Raeume ── -->
              <div v-show="steps[wizardStep]?.key === 'flaechen'" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                  <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Flaechen (m2)</h3>
                  <div class="grid grid-cols-2 gap-4">
                    <div v-for="f in [
                      { key: 'total_area', label: 'Gesamtflaeche' },
                      { key: 'living_area', label: 'Wohnflaeche' },
                      { key: 'realty_area', label: 'Nutzflaeche' },
                      { key: 'free_area', label: 'Grundstueck' },
                      { key: 'area_balcony', label: 'Balkon' },
                      { key: 'area_terrace', label: 'Terrasse' },
                      { key: 'area_garden', label: 'Garten' },
                      { key: 'area_loggia', label: 'Loggia' },
                      { key: 'area_basement', label: 'Keller' },
                      { key: 'area_garage', label: 'Garage' },
                      { key: 'office_space', label: 'Buero' },
                    ]" :key="f.key">
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">{{ f.label }}</label>
                      <input v-model="property[f.key]" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                  </div>
                </div>

                <div class="space-y-5">
                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Raeume</h3>
                    <div class="grid grid-cols-2 gap-4">
                      <div v-for="f in [
                        { key: 'rooms_amount', label: 'Zimmer', step: '0.5' },
                        { key: 'bedrooms', label: 'Schlafzimmer', step: '1' },
                        { key: 'bathrooms', label: 'Badezimmer', step: '1' },
                        { key: 'toilets', label: 'Separate WCs', step: '1' },
                      ]" :key="f.key">
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">{{ f.label }}</label>
                        <input v-model="property[f.key]" type="number" :step="f.step" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Stockwerk & Parking</h3>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Stockwerk</label>
                        <input v-model="property.floor_number" type="number" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Stockwerke gesamt</label>
                        <input v-model="property.floor_count" type="number" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Garagen</label>
                        <input v-model="property.garage_spaces" type="number" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Stellplaetze</label>
                        <input v-model="property.parking_spaces" type="number" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Parkplatz-Typ</label>
                        <input v-model="property.parking_type" type="text" placeholder="z.B. Tiefgarage" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Stellplatz-Preis</label>
                        <input v-model="property.parking_price" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ── Step: Ausstattung ── -->
              <div v-show="steps[wizardStep]?.key === 'ausstattung'" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="space-y-5">
                  <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Zustand & Qualitaet</h3>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Zustand</label>
                        <select v-model="property.realty_condition" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                          <option v-for="o in conditionOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Qualitaet</label>
                        <select v-model="property.quality" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                          <option v-for="o in qualityOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Baujahr</label>
                        <input v-model="property.construction_year" type="number" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Saniert</label>
                        <input v-model="property.year_renovated" type="number" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Kueche</label>
                        <select v-model="property.kitchen_type" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                          <option v-for="o in kitchenOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Heizung</label>
                        <input v-model="property.heating" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Bodenbelag</label>
                        <input v-model="property.flooring" type="text" placeholder="Parkett, Fliesen..." class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Badausstattung</label>
                        <input v-model="property.bathroom_equipment" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Ausrichtung</label>
                        <input v-model="property.orientation" type="text" placeholder="Suedwest..." class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-zinc-500 mb-1.5">Moeblierung</label>
                        <input v-model="property.furnishing" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                      </div>
                    </div>
                  </div>
                </div>

                <div class="bg-white border border-zinc-200/80 rounded-2xl p-6">
                  <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider mb-5">Ausstattungs-Merkmale</h3>
                  <div class="grid grid-cols-2 gap-3">
                    <label v-for="f in [
                      { key: 'has_balcony', label: 'Balkon' },
                      { key: 'has_terrace', label: 'Terrasse' },
                      { key: 'has_loggia', label: 'Loggia' },
                      { key: 'has_garden', label: 'Garten' },
                      { key: 'has_basement', label: 'Keller' },
                      { key: 'has_cellar', label: 'Kellerabteil' },
                      { key: 'has_elevator', label: 'Aufzug' },
                      { key: 'has_fitted_kitchen', label: 'Einbaukueche' },
                      { key: 'has_air_conditioning', label: 'Klimaanlage' },
                      { key: 'has_pool', label: 'Pool' },
                      { key: 'has_sauna', label: 'Sauna' },
                      { key: 'has_fireplace', label: 'Kamin' },
                      { key: 'has_alarm', label: 'Alarmanlage' },
                      { key: 'has_barrier_free', label: 'Barrierefrei' },
                      { key: 'has_guest_wc', label: 'Gaeste-WC' },
                      { key: 'has_storage_room', label: 'Abstellraum' },
                      { key: 'has_washing_connection', label: 'Waschmaschinenanschluss' },
                    ]" :key="f.key"
                      class="flex items-center gap-3 p-3 rounded-xl border border-zinc-100 hover:border-zinc-200 transition-all duration-200 cursor-pointer select-none"
                      :class="property[f.key] ? 'bg-zinc-900 border-zinc-900' : 'bg-white'">
                      <input type="checkbox" v-model="property[f.key]" class="sr-only" />
                      <div :class="[
                        'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all duration-200 shrink-0',
                        property[f.key] ? 'bg-white border-white' : 'border-zinc-300'
                      ]">
                        <Check v-if="property[f.key]" :size="12" class="text-zinc-900" />
                      </div>
                      <span :class="['text-sm font-medium transition-colors', property[f.key] ? 'text-white' : 'text-zinc-700']">{{ f.label }}</span>
                    </label>
                  </div>
                </div>
              </div>

              <!-- ══ Step: Einheiten (nur Neubauprojekt) ══ -->
              <div v-show="steps[wizardStep]?.key === 'einheiten'" class="space-y-5">
                <!-- Generator -->
                <div class="bg-violet-50 border border-violet-200 rounded-2xl p-5">
                  <h3 class="text-sm font-semibold text-violet-900 mb-3">Einheiten generieren</h3>
                  <div class="flex flex-wrap items-end gap-3">
                    <div>
                      <label class="block text-xs font-medium text-violet-700 mb-1">Prefix</label>
                      <input v-model="unitGen.prefix" type="text" class="w-24 px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-violet-700 mb-1">Start-Nr.</label>
                      <input v-model.number="unitGen.from" type="number" min="1" class="w-20 px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-violet-700 mb-1">Anzahl</label>
                      <input v-model.number="unitGen.count" type="number" min="1" class="w-20 px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-violet-700 mb-1">Stockwerke</label>
                      <input v-model.number="unitGen.floors" type="number" min="1" class="w-20 px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm" />
                    </div>
                    <button @click="generateUnitRows" class="px-4 py-2 bg-violet-600 text-white text-sm font-medium rounded-xl hover:bg-violet-500 transition-all active:scale-[0.97]">
                      <Plus :size="14" class="inline mr-1" /> Generieren
                    </button>
                  </div>
                </div>

                <!-- Units table -->
                <div class="bg-white border border-zinc-200/80 rounded-2xl overflow-hidden">
                  <div class="px-5 py-3 border-b border-zinc-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-zinc-900">Einheiten ({{ realUnits.length }})</h3>
                    <div class="flex items-center gap-2">
                      <button @click="addUnitRow" class="text-xs px-3 py-1.5 bg-zinc-100 text-zinc-700 rounded-lg hover:bg-zinc-200 transition-all">
                        <Plus :size="12" class="inline mr-1" /> Zeile
                      </button>
                      <button @click="saveAllUnits" class="text-xs px-3 py-1.5 bg-zinc-900 text-white rounded-lg hover:bg-zinc-800 transition-all">
                        <Save :size="12" class="inline mr-1" /> Alle speichern
                      </button>
                    </div>
                  </div>

                  <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="bg-zinc-50 text-xs text-zinc-500 uppercase">
                          <th class="px-3 py-2 text-left font-medium">Nr.</th>
                          <th class="px-3 py-2 text-left font-medium">Typ</th>
                          <th class="px-3 py-2 text-left font-medium">Stock</th>
                          <th class="px-3 py-2 text-right font-medium">m²</th>
                          <th class="px-3 py-2 text-right font-medium">Zimmer</th>
                          <th class="px-3 py-2 text-right font-medium">Preis €</th>
                          <th class="px-3 py-2 text-right font-medium">Balk/Terr.</th>
                          <th class="px-3 py-2 text-right font-medium">Garten</th>
                          <th class="px-3 py-2 text-center font-medium">Status</th>
                          <th class="px-3 py-2 text-center font-medium w-20"></th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(unit, idx) in realUnits" :key="unit.id || idx"
                          class="border-t border-zinc-100 hover:bg-zinc-50/50">
                          <td class="px-2 py-1.5">
                            <input v-model="unit.unit_number" type="text" class="w-20 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs" />
                          </td>
                          <td class="px-2 py-1.5">
                            <select v-model="unit.unit_type" class="w-24 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs">
                              <option>Wohnung</option>
                              <option>Reihenhaus</option>
                              <option>Doppelhaus</option>
                              <option>Penthouse</option>
                              <option>Maisonette</option>
                              <option>Geschaeft</option>
                              <option>Buero</option>
                            </select>
                          </td>
                          <td class="px-2 py-1.5">
                            <select v-model.number="unit.floor" class="w-20 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs">
                              <option :value="-1">UG</option>
                              <option :value="0">EG</option>
                              <option v-for="f in 10" :key="f" :value="f">{{ f }}. OG</option>
                            </select>
                          </td>
                          <td class="px-2 py-1.5">
                            <input v-model.number="unit.area_m2" type="number" step="0.1" class="w-16 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs text-right" />
                          </td>
                          <td class="px-2 py-1.5">
                            <input v-model.number="unit.rooms_amount" type="number" step="0.5" class="w-14 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs text-right" />
                          </td>
                          <td class="px-2 py-1.5">
                            <input v-model.number="unit.price" type="number" step="100" class="w-24 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs text-right" />
                          </td>
                          <td class="px-2 py-1.5">
                            <input v-model.number="unit.balcony_terrace_m2" type="number" step="0.1" class="w-16 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs text-right" />
                          </td>
                          <td class="px-2 py-1.5">
                            <input v-model.number="unit.garden_m2" type="number" step="0.1" class="w-16 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs text-right" />
                          </td>
                          <td class="px-2 py-1.5 text-center">
                            <select v-model="unit.status" :class="['px-2 py-1.5 rounded-lg text-xs font-medium border-0', statusColor(unit.status)]">
                              <option value="frei">Frei</option>
                              <option value="reserviert">Reserviert</option>
                              <option value="verkauft">Verkauft</option>
                            </select>
                          </td>
                          <td class="px-2 py-1.5 text-center">
                            <div class="flex items-center gap-1">
                              <button @click="saveUnit(unit)" class="p-1 text-emerald-600 hover:bg-emerald-50 rounded-lg" title="Speichern">
                                <Check :size="14" />
                              </button>
                              <button @click="deleteUnit(unit, idx)" class="p-1 text-red-500 hover:bg-red-50 rounded-lg" title="Loeschen">
                                <Trash2 :size="14" />
                              </button>
                            </div>
                          </td>
                        </tr>
                        <tr v-if="!realUnits.length">
                          <td colspan="10" class="px-5 py-8 text-center text-sm text-zinc-400">
                            Noch keine Einheiten. Nutze den Generator oben oder fuege einzelne Zeilen hinzu.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <!-- ══ Step: Stellplaetze (nur Neubauprojekt) ══ -->
              <div v-show="steps[wizardStep]?.key === 'stellplaetze'" class="space-y-5">
                <!-- Bulk Generator -->
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5">
                  <h3 class="text-sm font-semibold text-amber-900 mb-3">Stellplaetze generieren</h3>
                  <div class="flex flex-wrap items-end gap-3">
                    <div>
                      <label class="block text-xs font-medium text-amber-700 mb-1">Prefix</label>
                      <input v-model="parkingGen.prefix" type="text" class="w-28 px-3 py-2 bg-white border border-amber-200 rounded-xl text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-amber-700 mb-1">Typ</label>
                      <select v-model="parkingGen.type" class="w-28 px-3 py-2 bg-white border border-amber-200 rounded-xl text-sm">
                        <option>Tiefgarage</option>
                        <option>Freiplatz</option>
                        <option>Carport</option>
                        <option>Garage</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-amber-700 mb-1">Von Nr.</label>
                      <input v-model.number="parkingGen.from" type="number" min="1" class="w-20 px-3 py-2 bg-white border border-amber-200 rounded-xl text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-amber-700 mb-1">Bis Nr.</label>
                      <input v-model.number="parkingGen.to" type="number" min="1" class="w-20 px-3 py-2 bg-white border border-amber-200 rounded-xl text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-amber-700 mb-1">Preis/Stk</label>
                      <input v-model.number="parkingGen.price" type="number" step="100" class="w-24 px-3 py-2 bg-white border border-amber-200 rounded-xl text-sm" />
                    </div>
                    <button @click="bulkCreateParking" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-xl hover:bg-amber-500 transition-all active:scale-[0.97]">
                      <Plus :size="14" class="inline mr-1" /> Generieren
                    </button>
                  </div>
                </div>

                <!-- Parking table -->
                <div class="bg-white border border-zinc-200/80 rounded-2xl overflow-hidden">
                  <div class="px-5 py-3 border-b border-zinc-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-zinc-900">Stellplaetze ({{ parkingUnits.length }})</h3>
                    <button @click="addParkingRow" class="text-xs px-3 py-1.5 bg-zinc-100 text-zinc-700 rounded-lg hover:bg-zinc-200 transition-all">
                      <Plus :size="12" class="inline mr-1" /> Einzeln hinzufuegen
                    </button>
                  </div>

                  <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="bg-zinc-50 text-xs text-zinc-500 uppercase">
                          <th class="px-4 py-2 text-left font-medium">Nr.</th>
                          <th class="px-4 py-2 text-left font-medium">Typ</th>
                          <th class="px-4 py-2 text-right font-medium">Preis €</th>
                          <th class="px-4 py-2 text-center font-medium">Status</th>
                          <th class="px-4 py-2 text-center font-medium w-20"></th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(unit, idx) in parkingUnits" :key="unit.id || idx"
                          class="border-t border-zinc-100 hover:bg-zinc-50/50">
                          <td class="px-3 py-1.5">
                            <input v-model="unit.unit_number" type="text" class="w-32 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs" />
                          </td>
                          <td class="px-3 py-1.5">
                            <select v-model="unit.unit_type" class="w-28 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs">
                              <option>Tiefgarage</option>
                              <option>Freiplatz</option>
                              <option>Carport</option>
                              <option>Garage</option>
                              <option>Stellplatz</option>
                            </select>
                          </td>
                          <td class="px-3 py-1.5">
                            <input v-model.number="unit.price" type="number" step="100" class="w-24 px-2 py-1.5 bg-zinc-50 border border-zinc-200 rounded-lg text-xs text-right" />
                          </td>
                          <td class="px-3 py-1.5 text-center">
                            <select v-model="unit.status" :class="['px-2 py-1.5 rounded-lg text-xs font-medium border-0', statusColor(unit.status)]">
                              <option value="frei">Frei</option>
                              <option value="reserviert">Reserviert</option>
                              <option value="verkauft">Verkauft</option>
                            </select>
                          </td>
                          <td class="px-3 py-1.5 text-center">
                            <div class="flex items-center gap-1">
                              <button @click="saveUnit(unit)" class="p-1 text-emerald-600 hover:bg-emerald-50 rounded-lg">
                                <Check :size="14" />
                              </button>
                              <button @click="deleteUnit(unit, idx)" class="p-1 text-red-500 hover:bg-red-50 rounded-lg">
                                <Trash2 :size="14" />
                              </button>
                            </div>
                          </td>
                        </tr>
                        <tr v-if="!parkingUnits.length">
                          <td colspan="5" class="px-5 py-8 text-center text-sm text-zinc-400">
                            Noch keine Stellplaetze. Nutze den Generator oben oder fuege einzelne hinzu.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <!-- ── Step: Energie ── -->
              <div v-show="steps[wizardStep]?.key === 'energie'" class="max-w-2xl space-y-6">
                <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                  <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Energieausweis</h3>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Ausweistyp</label>
                      <select v-model="property.energy_type" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                        <option v-for="o in energyTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Energieklasse</label>
                      <select v-model="property.heating_demand_class" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all">
                        <option value="">--</option>
                        <option v-for="c in ['A++','A+','A','B','C','D','E','F','G','H']" :key="c" :value="c">{{ c }}</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">HWB (kWh/m2a)</label>
                      <input v-model="property.heating_demand_value" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">fGEE</label>
                      <input v-model="property.energy_efficiency_value" type="number" step="0.01" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Energietraeger</label>
                      <input v-model="property.energy_primary_source" type="text" placeholder="z.B. Fernwaerme" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Gueltig bis</label>
                      <input v-model="property.energy_valid_until" type="date" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-zinc-500 mb-1.5">Energieausweis (Freitext)</label>
                    <input v-model="property.energy_certificate" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                  </div>
                </div>

                <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4">
                  <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">Verfuegbarkeit & Bau</h3>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Verfuegbar ab</label>
                      <input v-model="property.available_from" type="date" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Verfuegbar (Text)</label>
                      <input v-model="property.available_text" type="text" placeholder="sofort, nach Vereinbarung" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Baubeginn</label>
                      <input v-model="property.construction_start" type="date" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Fertigstellung</label>
                      <input v-model="property.construction_end" type="date" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Bautraeger</label>
                      <input v-model="property.builder_company" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-zinc-500 mb-1.5">Hausverwaltung</label>
                      <input v-model="property.property_manager" type="text" class="w-full px-3 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" />
                    </div>
                  </div>
                </div>
              </div>

              <!-- ── Step: Beschreibungen ── -->
              <div v-show="steps[wizardStep]?.key === 'texte'" class="space-y-5 max-w-3xl">
                <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-4" v-for="t in [
                  { key: 'realty_description', label: 'Objektbeschreibung', placeholder: 'Allgemeine Beschreibung des Objekts...' },
                  { key: 'location_description', label: 'Lagebeschreibung', placeholder: 'Beschreibung der Lage und Umgebung...' },
                  { key: 'equipment_description', label: 'Ausstattungsbeschreibung', placeholder: 'Detaillierte Ausstattung...' },
                  { key: 'other_description', label: 'Sonstige Angaben', placeholder: 'Weitere relevante Informationen...' },
                  { key: 'highlights', label: 'Highlights', placeholder: 'Besondere Highlights (zeilenweise)...' },
                ]" :key="t.key">
                  <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider">{{ t.label }}</h3>
                  <textarea v-model="property[t.key]" rows="5" :placeholder="t.placeholder"
                    class="w-full px-4 py-3 bg-zinc-50 border border-zinc-200 rounded-xl text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all duration-200 resize-y"></textarea>
                </div>
              </div>

              <!-- ── Step: Bilder ── -->
              <div v-show="steps[wizardStep]?.key === 'bilder'" class="space-y-5">
                <div v-if="!property.id" class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
                  <AlertCircle :size="24" class="mx-auto text-amber-500 mb-2" />
                  <p class="text-sm text-amber-700">Bitte speichere das Objekt zuerst, bevor du Bilder hochlaedst.</p>
                </div>

                <div v-else>
                  <div @drop.prevent="handleImageUpload" @dragover.prevent="dragOver = true" @dragleave="dragOver = false"
                    :class="[
                      'border-2 border-dashed rounded-2xl p-10 text-center transition-all duration-300 cursor-pointer',
                      dragOver ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200 hover:border-zinc-300'
                    ]"
                    @click="$refs.imageInput.click()">
                    <input ref="imageInput" type="file" multiple accept="image/*" class="hidden" @change="handleImageUpload" />
                    <Upload :size="32" class="mx-auto text-zinc-400 mb-3" />
                    <p class="text-sm text-zinc-600 font-medium">Bilder hierher ziehen oder klicken</p>
                    <p class="text-xs text-zinc-400 mt-1">JPG, PNG, WebP — Mehrere gleichzeitig moeglich</p>
                  </div>

                  <div v-if="imageUploading" class="flex items-center gap-2 text-sm text-zinc-500 mt-4">
                    <div class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></div>
                    Lade hoch...
                  </div>

                  <div v-if="images.length" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mt-6">
                    <div v-for="img in images" :key="img.id"
                      class="group relative rounded-xl overflow-hidden border border-zinc-200 hover:border-zinc-300 transition-all duration-300">
                      <div class="aspect-[4/3] bg-zinc-100">
                        <img :src="img.url" :alt="img.original_name" class="w-full h-full object-cover" loading="lazy" />
                      </div>
                      <div v-if="img.is_title_image" class="absolute top-2 left-2">
                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-zinc-900 text-white text-xs font-medium rounded-lg">
                          <Star :size="10" /> Titelbild
                        </span>
                      </div>
                      <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-end p-3">
                        <div class="flex items-center gap-1.5 w-full">
                          <button v-if="!img.is_title_image" @click="setTitleImage(img)"
                            class="flex-1 px-2 py-1.5 bg-white text-zinc-900 text-xs font-medium rounded-lg hover:bg-zinc-100 transition-colors">
                            <Star :size="12" class="inline mr-1" /> Titelbild
                          </button>
                          <select :value="img.category" @change="updateImageCategory(img, $event.target.value)"
                            class="flex-1 px-2 py-1.5 bg-white text-zinc-900 text-xs rounded-lg">
                            <option v-for="c in imageCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
                          </select>
                          <button @click="deleteImage(img)"
                            class="p-1.5 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors">
                            <Trash2 :size="12" />
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>

                  <p v-else class="text-sm text-zinc-400 mt-4">Noch keine Bilder vorhanden.</p>
                </div>
              </div>

              <!-- ── Step: Portale ── -->
              <div v-show="steps[wizardStep]?.key === 'portale'" class="max-w-2xl space-y-5">
                <div class="bg-white border border-zinc-200/80 rounded-2xl p-6 space-y-2">
                  <h3 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider mb-4">Plattform-Veroeffentlichung</h3>

                  <!-- Master: only website -->
                  <div v-if="property?.children_count > 0 && !property?.parent_id" class="p-4 bg-indigo-50 border border-indigo-200 rounded-xl text-sm text-indigo-700 mb-3">
                    <span class="font-medium">Master-Objekt:</span> Plattform-Export (willhaben, IS24, etc.) laeuft ueber die Unterobjekte.
                  </div>
                  <!-- Child: no website -->
                  <div v-if="property?.parent_id" class="p-4 bg-indigo-50 border border-indigo-200 rounded-xl text-sm text-indigo-700 mb-3">
                    <span class="font-medium">Unterobjekt:</span> Website-Anzeige wird ueber das Master-Projekt gesteuert.
                  </div>

                  <div v-if="!property.id" class="p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-700">
                    Bitte zuerst speichern.
                  </div>

                  <div v-else class="space-y-3">
                    <!-- SR-Homes Website Toggle (only master/standalone) -->
                    <div v-if="!property?.parent_id" class="flex items-center justify-between p-4 rounded-xl border border-zinc-200 hover:border-zinc-300 transition-all">
                      <div class="flex items-center gap-3">
                        <div class="w-3 h-3 rounded-full bg-zinc-900"></div>
                        <div>
                          <p class="text-sm font-medium text-zinc-900">SR-Homes Website</p>
                          <p class="text-xs text-zinc-400">Auf sr-homes.at veroeffentlichen</p>
                        </div>
                      </div>
                      <button @click="savePortal('sr-homes', !isPortalEnabled('sr-homes'))"
                        :class="['relative w-11 h-6 rounded-full transition-all duration-300 cursor-pointer', isPortalEnabled('sr-homes') ? 'bg-zinc-900' : 'bg-zinc-200']">
                        <span :class="['absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-all duration-300', isPortalEnabled('sr-homes') ? 'left-[22px]' : 'left-0.5']"></span>
                      </button>
                    </div>

                    <!-- Immoji Section (only children/standalone, not master) -->
                    <div v-if="!(property?.children_count > 0 && !property?.parent_id)" class="mt-4 border-t border-zinc-100 pt-4">
                      <div class="flex items-center gap-3 mb-3">
                        <div class="w-2.5 h-2.5 rounded-full bg-violet-500"></div>
                        <span class="text-sm font-semibold text-zinc-900">Immoji</span>
                        <span v-if="immojiConnected" class="ml-auto inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Verbunden
                        </span>
                        <span v-else class="ml-auto inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-zinc-100 text-zinc-500">Nicht verbunden</span>
                      </div>

                      <!-- Login -->
                      <div v-if="!immojiConnected" class="space-y-2">
                        <input v-model="immojiEmail" type="email" placeholder="Immoji E-Mail" class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-400" />
                        <input v-model="immojiPassword" type="password" placeholder="Immoji Passwort" class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-400" />
                        <button @click="connectImmoji" :disabled="immojiConnecting || !immojiEmail.trim() || !immojiPassword.trim()" class="w-full px-4 py-2 text-sm font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 disabled:opacity-50 transition-colors">
                          {{ immojiConnecting ? 'Verbinde...' : 'Verbinden' }}
                        </button>
                      </div>

                      <!-- Connected -->
                      <div v-else class="space-y-3">
                        <div class="flex items-center gap-3">
                          <button @click="disconnectImmoji" class="px-3 py-1.5 text-xs font-medium text-zinc-600 bg-zinc-100 rounded-lg hover:bg-zinc-200 transition-colors">Trennen</button>
                          <button v-if="property?.id" @click="pushToImmoji" :disabled="immojiPushing" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 disabled:opacity-50 transition-colors">
                            {{ immojiPushing ? 'Sync...' : (property?.openimmo_id ? 'Erneut syncen' : 'Zu Immoji hochladen') }}
                          </button>
                        </div>

                        <!-- Single unified portal list -->
                        <div v-if="immojiPortals !== null" class="space-y-1.5">
                          <div v-for="p in IMMOJI_PORTALS" :key="p.key"
                            class="flex items-center justify-between py-2 px-3 rounded-lg border border-zinc-100 hover:border-zinc-200 transition-all">
                            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                              <div :class="['w-2 h-2 rounded-full flex-shrink-0', p.color]"></div>
                              <span class="text-sm text-zinc-800">{{ p.label }}</span>
                              <span v-if="p.capKey && immojiCapacity && immojiCapacity[p.capKey] && immojiCapacity[p.capKey].limit" class="text-[10px] px-1.5 py-0.5 rounded-md bg-zinc-100 text-zinc-500">
                                Limit: {{ immojiCapacity[p.capKey].limit }}
                              </span>
                              <span v-if="p.lastKey && immojiPortals[p.lastKey]" class="text-[10px] text-zinc-400 ml-auto mr-2 hidden sm:inline">
                                {{ formatLastExport(immojiPortals[p.lastKey]) }}
                              </span>
                            </div>
                            <button @click="toggleImmojiPortal(p.key, immojiPortals[p.key])"
                              :disabled="immojiPortalSaving[p.key]"
                              :class="['relative rounded-full transition-all duration-300 cursor-pointer flex-shrink-0', immojiPortals[p.key] ? 'bg-zinc-900' : 'bg-zinc-200']"
                              style="min-width: 40px; width: 40px; height: 22px;">
                              <span :class="['absolute top-[2px] bg-white rounded-full shadow transition-all duration-300', immojiPortals[p.key] ? 'left-[20px]' : 'left-[2px]']"
                                style="width: 18px; height: 18px;"></span>
                            </button>
                          </div>
                        </div>

                        <div v-else-if="immojiPortalLoading" class="flex items-center gap-2 text-xs text-zinc-400 py-2">
                          <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                          Lade...
                        </div>

                        <div v-else-if="!property?.openimmo_id" class="text-xs text-zinc-400 py-2">
                          Objekt zuerst hochladen um Portal-Export zu steuern.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer with navigation -->
        <div v-if="!showTypeSelect" class="flex items-center justify-between gap-4 px-6 py-4 border-t border-zinc-200/80 bg-zinc-50">
          <button @click="goPrev" :disabled="wizardStep === 0"
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-zinc-600 bg-white border border-zinc-200 rounded-xl hover:bg-zinc-50 transition-all duration-300 active:scale-[0.97] disabled:opacity-30 disabled:cursor-not-allowed">
            <ChevronLeft :size="16" /> Zurueck
          </button>

          <div class="flex items-center gap-1.5">
            <span v-for="(s, i) in steps" :key="i"
              :class="['w-2 h-2 rounded-full transition-all duration-300', i === wizardStep ? 'bg-zinc-900 w-6' : 'bg-zinc-200']"></span>
          </div>

          <div class="flex items-center gap-2">
            <label v-if="!isChildProperty" for="newFileInputId" :class="['inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium text-violet-700 bg-violet-50 border border-violet-200 rounded-xl hover:bg-violet-100 transition-all duration-300 active:scale-[0.97] cursor-pointer', parseLoading ? 'opacity-50 pointer-events-none' : '']">
              <Upload :size="15" />
              {{ parseLoading ? 'Analysiere...' : 'Dateien hochladen & auslesen' }}
            </label>
            <button v-if="property?.id && !isChildProperty" @click="openFileSelect" :disabled="parseLoading"
              class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium text-zinc-600 bg-zinc-50 border border-zinc-200 rounded-xl hover:bg-zinc-100 transition-all duration-300 active:scale-[0.97] disabled:opacity-50">
              <Sparkles :size="15" :class="parseLoading ? 'animate-spin' : ''" />
              Vorhandene auslesen
            </button>
            <input id="newFileInputId" ref="newFileInput" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xlsx,.xls" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)" @change="analyzeNewFile" />

            <button @click="saveProperty" :disabled="saving"
              class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-emerald-600 rounded-xl hover:bg-emerald-500 transition-all duration-300 active:scale-[0.97] disabled:opacity-50">
              <Save :size="16" /> {{ saving ? 'Speichert...' : 'Speichern' }}
            </button>
            <button v-if="wizardStep < steps.length - 1" @click="goNext"
              class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-300 active:scale-[0.97]">
              Weiter <ChevronRight :size="16" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>

    <!-- File Select Modal -->
    <div v-if="fileSelectOpen" class="fixed inset-0 z-[310] flex items-center justify-center bg-black/50" @click.self="fileSelectOpen = false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6" @click.stop>
        <div class="text-sm font-semibold text-zinc-900 mb-1">Dateien zum Auslesen auswaehlen</div>
        <div class="text-xs text-zinc-500 mb-4">Dateien hochladen oder vorhandene auswaehlen.</div>
        <label class="flex items-center justify-center gap-2 px-4 py-3 mb-3 rounded-xl border-2 border-dashed cursor-pointer transition-all hover:border-violet-400 hover:bg-violet-50/30 border-zinc-300">
          <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="hidden" @change="uploadAndAddFiles" />
          <Upload :size="16" class="text-zinc-400" />
          <span class="text-xs font-medium text-zinc-500">Dateien hochladen (mehrere moeglich)</span>
        </label>
        <div class="space-y-2 max-h-64 overflow-y-auto mb-4">
          <label v-for="f in availableFiles" :key="f.id"
            class="flex items-center gap-3 p-2.5 rounded-xl border cursor-pointer transition-all hover:bg-zinc-50"
            :class="selectedFileIds.includes(f.id) ? 'border-violet-300 bg-violet-50/50' : 'border-zinc-200'">
            <input type="checkbox" :value="f.id" v-model="selectedFileIds"
              class="w-4 h-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500" />
            <div class="flex-1 min-w-0">
              <div class="text-xs font-medium text-zinc-800 truncate">{{ f.label || f.filename }}</div>
              <div class="text-[10px] text-zinc-400">{{ f.filename }} &middot; {{ f.file_size ? (f.file_size / 1024 / 1024).toFixed(1) + ' MB' : '' }}</div>
            </div>
          </label>
          <div v-if="!availableFiles.length" class="text-sm text-zinc-400 text-center py-6">Noch keine Dateien. Lade oben Expose, Preisliste etc. hoch.</div>
        </div>
        <div class="flex items-center gap-2">
          <button @click="runParseWithFiles()" :disabled="!selectedFileIds.length"
            class="flex-1 px-3 py-2.5 text-xs font-medium rounded-xl transition-all"
            :class="selectedFileIds.length ? 'bg-violet-600 text-white hover:bg-violet-500' : 'bg-zinc-100 text-zinc-400 cursor-not-allowed'">
            <Sparkles :size="13" class="inline mr-1" />
            {{ selectedFileIds.length }} Datei(en) auslesen
          </button>
          <button @click="fileSelectOpen = false" class="px-3 py-2.5 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all">Abbrechen</button>
        </div>
      </div>
    </div>

</template>
