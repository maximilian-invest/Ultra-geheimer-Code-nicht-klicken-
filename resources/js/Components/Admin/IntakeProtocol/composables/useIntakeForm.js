import { reactive, ref, computed } from 'vue';

function initialForm() {
  return {
    // Step 1
    object_type: '',
    object_subtype: '',
    marketing_type: '',
    title: '',
    ref_id: '',
    // Step 2
    address: '', house_number: '', zip: '', city: '',
    staircase: '', door: '', address_floor: '',
    latitude: null, longitude: null,
    // Step 3
    owner: { name: '', email: '', phone: '', address: '', zip: '', city: '' },
    owner_customer_id: null,
    portal_access_granted: false,
    // Step 4
    living_area: null, free_area: null, total_area: null, realty_area: null,
    rooms_amount: null, bedrooms: null, bathrooms: null, toilets: null,
    floor_count: null, floor_number: null,
    construction_year: null,
    // Step 5
    realty_condition: '', construction_type: '', quality: '',
    ownership_type: '', furnishing: '', condition_note: '',
    property_history: [],
    // Step 6
    has_balcony: false, area_balcony: null, balcony_count: null,
    has_terrace: false, area_terrace: null, terrace_count: null,
    has_dachterrasse: false, area_dachterrasse: null, dachterrasse_count: null,
    has_loggia: false, area_loggia: null, loggia_count: null,
    has_garden: false, area_garden: null,
    has_basement: false, area_basement: null,
    has_elevator: false, has_fitted_kitchen: false, has_air_conditioning: false,
    has_pool: false, has_sauna: false, has_fireplace: false,
    has_alarm: false, has_barrier_free: false, has_guest_wc: false,
    has_storage_room: false,
    common_areas: [],
    // flooring + bathroom_equipment: Multi-Select → JSON-Array als String speichern
    flooring: '', bathroom_equipment: '', orientation: '',
    garage_spaces: null, parking_spaces: null,
    parking_type: '', parking_assignment: '',
    // Step 7
    energy_certificate: '', heating_demand_value: null, heating_demand_class: '',
    energy_efficiency_value: null, energy_valid_until: null,
    heating: '', has_photovoltaik: false, has_wohnraumlueftung: false,
    charging_station_status: '',
    // Step 8
    property_manager_id: null,
    encumbrances: '',
    approvals_status: '',
    approvals_notes: '',
    documents_available: {},
    // Step 9 — Kosten (Provisionen bewusst NICHT im Wizard, werden im Cockpit gesetzt)
    purchase_price: null, rental_price: null, rent_warm: null, rent_deposit: null,
    operating_costs: null, maintenance_reserves: null,
    heating_costs: null, warm_water_costs: null, cooling_costs: null,
    admin_costs: null, elevator_costs: null, parking_costs_monthly: null,
    other_costs: null, monthly_costs: null,
    available_from: null,
    // Step 10
    photos: [],
    // Step 11
    broker_notes: '',
    open_fields: [],
    signature_data_url: '',
    signed_by_name: '',
    mail_subject: '',
    mail_body: '',
  };
}

// Stabiler Key fuer die aktuelle Draft-UUID. Damit bleibt die draftKey ueber
// Reloads hinweg erhalten und useAutoSave findet seine localStorage-Eintraege.
const CURRENT_DRAFT_KEY_STORAGE = 'intake_protocol_current_draft_key';

function loadPersistedDraftKey() {
  try {
    const v = localStorage.getItem(CURRENT_DRAFT_KEY_STORAGE);
    if (v && typeof v === 'string' && v.startsWith('iap-')) return v;
  } catch {}
  return null;
}

function persistDraftKey(uuid) {
  try { localStorage.setItem(CURRENT_DRAFT_KEY_STORAGE, uuid); } catch {}
}

function clearPersistedDraftKey() {
  try { localStorage.removeItem(CURRENT_DRAFT_KEY_STORAGE); } catch {}
}

export function useIntakeForm(options = {}) {
  const form = reactive(initialForm());
  const currentStep = ref(1);

  // 3 Initialisierungs-Pfade:
  // a) options.draftKey (z.B. aus Draft-Liste) → exakt diesen Key verwenden
  // b) localStorage hat einen Key → Reload-Szenario, wiederverwenden
  // c) Neuer Key → frischer Start, sofort persistieren
  let initialKey;
  if (options.draftKey) {
    initialKey = options.draftKey;
  } else {
    initialKey = loadPersistedDraftKey() || generateUuid();
  }
  const draftKey = ref(initialKey);
  persistDraftKey(initialKey);

  const TOTAL_STEPS = 11;

  const progress = computed(() => Math.round((currentStep.value - 1) / (TOTAL_STEPS - 1) * 100));

  function markSkipped(fieldKey) {
    if (!form.open_fields.includes(fieldKey)) form.open_fields.push(fieldKey);
  }

  function unmarkSkipped(fieldKey) {
    form.open_fields = form.open_fields.filter(f => f !== fieldKey);
  }

  function isSkipped(fieldKey) {
    return form.open_fields.includes(fieldKey);
  }

  // Hard reset: Formular leeren + neuen Draft-Key starten + alten aufraeumen.
  function reset() {
    const oldKey = draftKey.value;
    try { localStorage.removeItem('intake_protocol_draft_' + oldKey); } catch {}
    Object.assign(form, initialForm());
    currentStep.value = 1;
    draftKey.value = generateUuid();
    persistDraftKey(draftKey.value);
  }

  // Wird vom Wizard nach erfolgreichem Submit aufgerufen: alle Spuren weg.
  function finishAndCleanup() {
    const k = draftKey.value;
    try { localStorage.removeItem('intake_protocol_draft_' + k); } catch {}
    clearPersistedDraftKey();
  }

  return {
    form, currentStep, draftKey,
    TOTAL_STEPS, progress,
    markSkipped, unmarkSkipped, isSkipped,
    reset, finishAndCleanup,
  };
}

function generateUuid() {
  return 'iap-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}
