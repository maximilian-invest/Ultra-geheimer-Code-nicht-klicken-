<script setup>
import { catBadgeStyle, catLabel } from '@/utils/categoryBadge.js';
import { ref, inject, computed, reactive, watch } from "vue";
import { Pause, Play, BookOpen, Search, X, Plus, Sparkles, Upload, Settings, Trash2, Check, Pencil, ClipboardList, Save, FileText, MessageCircle, Users, ChevronDown, ChevronRight, ArrowLeft, Lock, Link2, LayoutList, LayoutGrid } from "lucide-vue-next";
import PropertyDetailPage from '@/Components/Admin/PropertyDetailPage.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

const API = inject("API");
const toast = inject("toast");
const properties = inject("properties");
const kbCounts = inject("kbCounts");
const userType = inject("userType", ref("admin"));

// Makler can only edit their own properties (readonly=false)
function canEditProperty(prop) {
    if (!prop) return true;
    if (userType.value === 'admin' || userType.value === 'assistenz') return true;
    return !prop.readonly;
}

const searchQuery = ref("");

// Portal icons from local data (property.portals from DashboardController)
const PORTAL_ICONS = {
    'immoji':       { key: 'IMJ', label: 'Immoji verbunden',    color: '#3b82f6' },
    'sr-homes':     { key: 'SR',  label: 'SR-Homes Website',    color: '#0d9488' },
    'willhaben':    { key: 'WH',  label: 'willhaben.at',        color: '#ea580c' },
    'immowelt':     { key: 'IW',  label: 'immowelt.at',         color: '#dc2626' },
    'immoscout24':  { key: 'IS',  label: 'ImmobilienScout24',   color: '#2563eb' },
    'immoSN':       { key: 'SN',  label: 'immoSN',              color: '#7c3aed' },
    'dibeo':        { key: 'DI',  label: 'DIBEO',               color: '#0891b2' },
    'kurier':       { key: 'KU',  label: 'Kurier',              color: '#b91c1c' },
    'allesKralle':  { key: 'AK',  label: 'allesKralle',         color: '#65a30d' },
};

function getPortalIcons(prop) {
    const icons = [];
    // Immoji connection badge first
    if (prop.openimmo_id) {
        icons.push(PORTAL_ICONS['immoji']);
    }
    // SR-Homes website from show_on_website flag
    if (prop.show_on_website && !(prop.portals || []).some(p => p.name === 'sr-homes' && p.enabled)) {
        icons.push(PORTAL_ICONS['sr-homes']);
    }
    const portals = prop.portals || [];
    // Show active portals in order
    const order = ['sr-homes','willhaben','immowelt','immoscout24','immoSN','dibeo','kurier','allesKralle'];
    for (const name of order) {
        const p = portals.find(x => x.name === name && x.enabled);
        if (p && PORTAL_ICONS[name]) icons.push(PORTAL_ICONS[name]);
    }
    return icons;
}
const statusFilter = ref('aktiv');
const typeFilter = ref('');
const propMenuOpen = ref(null);
const propMenuDir = ref('down');
function togglePropMenu(propId, event) {
    if (propMenuOpen.value === propId) { propMenuOpen.value = null; return; }
    const rect = event.currentTarget.getBoundingClientRect();
    propMenuDir.value = rect.top > window.innerHeight / 2 ? 'up' : 'down';
    propMenuOpen.value = propId;
}

// Property Create/Delete
const showCreateForm = ref(false);
const createForm = ref({ ref_id: '', address: '', city: '', zip: '', type: '', price: '', size_m2: '', rooms: '', customer_id: '' });
const createLoading = ref(false);
const deleteConfirm = ref(null);
const deleteLoading = ref(false);


// Property Detail View
const selectedProperty = ref(null);
const isNewProperty = ref(false);
function openDetail(prop) { selectedProperty.value = prop; isNewProperty.value = false; }
function closeDetail() { selectedProperty.value = null; isNewProperty.value = false; }
function openNewProperty() { selectedProperty.value = { on_hold: false, marketing_type: "kauf" }; isNewProperty.value = true; }

function handlePropertyCreated(createdProperty) {
  const allProps = (properties?.value ?? properties) || [];
  if (createdProperty?.id && Array.isArray(allProps)) {
    const exists = allProps.some((p) => Number(p.id) === Number(createdProperty.id));
    if (!exists) {
      allProps.unshift(createdProperty);
    }
    openDetail(createdProperty);
    return;
  }
  closeDetail();
}

function handleOwnerChanged(data) {
  const allProps = (properties?.value ?? properties) || [];
  const p = allProps.find(x => x.id === data.propertyId);
  if (p) {
    p.customer_id = data.customer_id;
    p.owner_name = data.owner_name;
    p.owner_email = data.owner_email;
    p.owner_phone = data.owner_phone;
  }
  if (selectedProperty.value) {
    selectedProperty.value.customer_id = data.customer_id;
    selectedProperty.value.owner_name = data.owner_name;
    selectedProperty.value.owner_email = data.owner_email;
    selectedProperty.value.owner_phone = data.owner_phone;
  }
}

function handlePropertySaved(p) {
  if (p && selectedProperty.value) {
    Object.assign(selectedProperty.value, p);
  }
}

async function createProperty() {
    createLoading.value = true;
    try {
        const r = await fetch(API.value + '&action=create_property', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.fromEntries(Object.entries(createForm.value).map(([k,v]) => [k, v === '' ? null : v])))
        });
        const d = await r.json();
        if (d.success) {
            toast('Objekt erfolgreich angelegt');
            showCreateForm.value = false;
            createForm.value = { ref_id: '', address: '', city: '', zip: '', type: '', price: '', size_m2: '', rooms: '', customer_id: '' };
            // Reload page to get updated properties
            window.location.reload();
        } else {
            toast('Fehler: ' + (d.error || d.message || 'Unbekannt'));
        }
    } catch (e) { toast('Fehler: ' + e.message); }
    createLoading.value = false;
}

async function deleteProperty(prop) {
    if (!deleteConfirm.value || deleteConfirm.value.id !== prop.id) {
        // First click: request confirmation
        deleteLoading.value = true;
        try {
            const r = await fetch(API.value + '&action=delete_property', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ property_id: prop.id })
            });
            const d = await r.json();
            if (d.confirm_required) {
                deleteConfirm.value = { id: prop.id, ...d };
            }
            if (d.success && d.set_inaktiv) {
                toast(d.message);
                deleteConfirm.value = null;
                window.location.reload();
                return;
            }
        } catch (e) { toast('Fehler: ' + e.message); }
        deleteLoading.value = false;
        return;
    }
    // Second click: confirmed delete
    deleteLoading.value = true;
    try {
        const r = await fetch(API.value + '&action=delete_property', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: prop.id, confirm: true })
        });
        const d = await r.json();
        if (d.success) {
            toast(d.message);
            deleteConfirm.value = null;
            window.location.reload();
        } else {
            toast('Fehler: ' + (d.error || 'Unbekannt'));
        }
    } catch (e) { toast('Fehler: ' + e.message); }
    deleteLoading.value = false;
}

function requestDeleteProperty(prop, event = null) {
    event?.stopPropagation?.();
    deleteProperty(prop);
}

const kbOpen = ref(false);
const kbPropertyId = ref(null);
const kbPropertyLabel = ref("");
const kbItems = ref([]);
const kbLoading = ref(false);
const kbCategoryCounts = ref({});
const kbSearch = ref("");
const kbFilterCategory = ref("");
const kbEditingId = ref(null);
const kbEditTitle = ref("");
const kbEditContent = ref("");
const kbShowFeedModal = ref(false);
const kbFeedMode = ref("text");
const kbFeedText = ref("");
const kbFeedLoading = ref(false);
const kbFeedPreview = ref(null);
const kbFileUploading = ref(false);

const showGlobalFiles = ref(false);
const globalFiles = ref([]);
const globalFilesLoaded = ref(false);
const globalFileUploading = ref(false);

async function loadGlobalFiles() {
    if (globalFilesLoaded.value) return;
    try {
        const r = await fetch(API.value + '&action=list_global_files');
        const d = await r.json();
        globalFiles.value = d.files || [];
        globalFilesLoaded.value = true;
    } catch (e) {}
}

async function uploadGlobalFile(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = '';
    globalFileUploading.value = true;
    try {
        const label = prompt('Bezeichnung (optional):', file.name.replace(/\.[^.]+$/, ''));
        const fd = new FormData();
        fd.append('file', file);
        if (label) fd.append('label', label);
        const r = await fetch(API.value + '&action=upload_global_file', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            globalFiles.value.unshift(d.file);
            toast('Datei hochgeladen');
        } else {
            toast('Fehler: ' + (d.error || 'Unbekannt'));
        }
    } catch (e) { toast('Fehler: ' + e.message); }
    globalFileUploading.value = false;
}

async function deleteGlobalFile(file) {
    if (!confirm('Datei "' + (file.label || file.original_name) + '" wirklich löschen?')) return;
    try {
        await fetch(API.value + '&action=delete_global_file', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: file.id }),
        });
        globalFiles.value = globalFiles.value.filter(f => f.id !== file.id);
        toast('Gelöscht');
    } catch (e) { toast('Fehler'); }
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function getCategoryLabel(cat) {
    const labels = { apartment: 'Wohnung', house: 'Haus', newbuild: 'Neubauprojekt', land: 'Grundst\u00fcck' };
    return labels[cat] || cat || '\u2013';
}

function formatPrice(price, isNewbuild) {
    if (!price) return '\u2013';
    const formatted = Number(price).toLocaleString('de-DE');
    return (isNewbuild ? 'ab \u20ac ' : '\u20ac ') + formatted;
}

function formatPriceMobile(price, isNewbuild) {
    if (!price) return '\u2013';
    const n = Number(price);
    let short;
    if (n >= 1000000) short = (n / 1000000).toFixed(1).replace('.0', '') + 'M';
    else if (n >= 1000) short = Math.round(n / 1000) + 'K';
    else short = n.toString();
    return (isNewbuild ? 'ab \u20ac ' : '\u20ac ') + short;
}


const customersList = ref([]);
const customersLoaded = ref(false);
const adminUsers = ref([]);

const selectedCustomerId = ref('');

async function loadCustomers() {
    if (customersLoaded.value) return;
    try {
        const r = await fetch(API.value + '&action=list_customers');
        const d = await r.json();
        customersList.value = d.customers || [];
        customersLoaded.value = true;
    } catch (e) {}
}

// Load customers early
loadCustomers();

// Load admin users for broker assignment
(async () => {
    try {
        const r = await fetch(API.value + '&action=list_admin_users');
        const d = await r.json();
        adminUsers.value = d.users || [];
    } catch {}
})();

async function saveBroker() {
    const pid = propSettingsId.value;
    const brokerId = propSettingsData.value.broker_id;
    if (!pid) return;
    try {
        await fetch(API.value + '&action=update_broker', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: pid, broker_id: brokerId }),
        });
        toast('Makler aktualisiert');
    } catch { toast('Fehler beim Speichern'); }
}

async function selectExistingOwner() {
    const id = Number(selectedCustomerId.value);
    if (!id) return;
    const c = customersList.value.find(x => x.id === id);
    if (c) {
        // Save to API immediately
        try {
            const r = await fetch(API.value + '&action=save_property_settings', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    property_id: propSettingsId.value,
                    customer_id: c.id,
                    owner_name: c.name,
                    owner_email: c.email || '',
                    owner_phone: c.phone || '',
                })
            });
            const d = await r.json();
            if (d.success) {
                propSettingsData.value = {
                    ...propSettingsData.value,
                    owner_name: c.name,
                    owner_email: c.email || '',
                    owner_phone: c.phone || '',
                    customer_id: c.id,
                };
                toast('Eigentümer zugewiesen: ' + c.name);
            } else {
                toast('Fehler: ' + (d.error || 'Unbekannt'));
            }
        } catch (e) { toast('Fehler: ' + e.message); }
        // Check if this customer has a portal user
        try {
            const r = await fetch(API.value + '&action=check_portal_access&property_id=' + propSettingsId.value + '&email=' + encodeURIComponent(c.email || ''));
            const d = await r.json();
            portalUser.value = d.portal_user || null;
        } catch (e) {}
    }
    selectedCustomerId.value = '';
}

// --- Eigentümer CRUD ---
async function createNewOwner() {
    const f = newOwnerForm.value;
    if (!f.name) { toast('Bitte Name eingeben'); return; }
    newOwnerSaving.value = true;
    try {
        const r = await fetch(API.value + '&action=create_customer', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: f.name, email: f.email, phone: f.phone }),
        });
        const d = await r.json();
        if (d.success && d.customer) {
            customersLoaded.value = false;
            await loadCustomers();
            // Auto-assign
            propSettingsData.value = {
                ...propSettingsData.value,
                owner_name: d.customer.name,
                owner_email: d.customer.email || '',
                owner_phone: d.customer.phone || '',
                customer_id: d.customer.id,
            };
            toast('Eigentümer angelegt & zugewiesen');
            showCreateOwnerForm.value = false;
            newOwnerForm.value = { name: '', email: '', phone: '' };
        } else { toast('Fehler: ' + (d.error || 'Unbekannt')); }
    } catch (e) { toast('Fehler: ' + e.message); }
    newOwnerSaving.value = false;
}

function openEditCustomer() {
    const cid = propSettingsData.value.customer_id;
    if (!cid) return;
    const c = customersList.value.find(x => x.id === cid);
    if (!c) return;
    editCustomerForm.value = {
        id: c.id,
        name: c.name || '',
        email: c.email || '',
        phone: c.phone || '',
        address: c.address || '',
        city: c.city || '',
        zip: c.zip || '',
        notes: c.notes || '',
    };
    showEditCustomer.value = true;
    deleteCustomerConfirm.value = false;
}

async function saveEditCustomer() {
    editCustomerSaving.value = true;
    try {
        const r = await fetch(API.value + '&action=update_customer', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(editCustomerForm.value),
        });
        const d = await r.json();
        if (d.success && d.customer) {
            // Refresh customers list
            customersLoaded.value = false;
            await loadCustomers();
            // Sync back to property fields
            propSettingsData.value = {
                ...propSettingsData.value,
                owner_name: d.customer.name,
                owner_email: d.customer.email || '',
                owner_phone: d.customer.phone || '',
            };
            toast('Eigentümer aktualisiert');
            showEditCustomer.value = false;
        } else { toast('Fehler: ' + (d.error || 'Unbekannt')); }
    } catch (e) { toast('Fehler: ' + e.message); }
    editCustomerSaving.value = false;
}

async function unlinkCustomer() {
    if (!confirm('Eigentümer-Verknüpfung wirklich lösen?')) return;
    try {
        const r = await fetch(API.value + '&action=save_property_settings', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: propSettingsId.value, customer_id: 0, owner_name: '', owner_email: '', owner_phone: '' })
        });
        const d = await r.json();
        if (d.success) {
            propSettingsData.value.customer_id = null;
            propSettingsData.value.owner_name = '';
            propSettingsData.value.owner_email = '';
            propSettingsData.value.owner_phone = '';
            toast('Eigentümer-Verknüpfung gelöst');
        } else { toast('Fehler: ' + (d.error || 'Unbekannt')); }
    } catch (e) { toast('Fehler: ' + e.message); }
}

async function deleteCustomer() {
    const cid = editCustomerForm.value.id;
    if (!cid) return;
    deleteCustomerLoading.value = true;
    try {
        const r = await fetch(API.value + '&action=delete_customer', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: cid }),
        });
        const d = await r.json();
        if (d.success) {
            customersLoaded.value = false;
            await loadCustomers();
            propSettingsData.value = {
                ...propSettingsData.value,
                customer_id: null,
                owner_name: '',
                owner_email: '',
                owner_phone: '',
            };
            toast('Eigentümer gelöscht');
            showEditCustomer.value = false;
            deleteCustomerConfirm.value = false;
        } else { toast('Fehler: ' + (d.error || 'Noch mit Immobilien verknüpft')); }
    } catch (e) { toast('Fehler: ' + e.message); }
    deleteCustomerLoading.value = false;
}


// Activities panel state
const actOpen = ref(false);
const actPropertyId = ref(null);
const actPropertyLabel = ref("");
const actItems = ref([]);

// Quick Activity Add (im Activities-Panel)
const pqaOpen = ref(false);
const pqaActivity = ref("");
const pqaDuration = ref("");
const pqaCategory = ref("sonstiges");
const pqaStakeholder = ref("");
const pqaDate = ref(new Date().toISOString().slice(0, 10));
const pqaTime = ref(new Date().toTimeString().slice(0, 5));
const pqaSaving = ref(false);

async function pqaSubmit() {
    if (!pqaActivity.value) { toast("Aktivität eingeben"); return; }
    pqaSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=add_activity", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                property_id: actPropertyId.value,
                activity: pqaActivity.value,
                duration: pqaDuration.value ? parseInt(pqaDuration.value) : null,
                category: pqaCategory.value,
                stakeholder: pqaStakeholder.value,
                activity_date: pqaDate.value,
                activity_time: pqaTime.value,
            }),
        });
        const d = await r.json();
        if (d.success) {
            toast("✓ " + (d.polished !== d.raw ? "KI-optimiert gespeichert" : "Gespeichert"));
            pqaActivity.value = "";
            pqaDuration.value = "";
            pqaStakeholder.value = "";
            pqaDate.value = new Date().toISOString().slice(0, 10);
            pqaTime.value = new Date().toTimeString().slice(0, 5);
            // Reload activities
            // Reload activities list
            try { const _r = await fetch(API.value + "&action=list_activities&property_id=" + actPropertyId.value); const _d = await _r.json(); actItems.value = _d.activities || []; } catch {}
        } else {
            toast("Fehler: " + (d.error || "Unbekannt"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
    pqaSaving.value = false;
}
const actLoading = ref(false);
const actEditingId = ref(null);
const actEditData = ref({});
const actSearch = ref("");
const actCategory = ref("");
const kbFileName = ref("");
const kbBulkPreview = ref([]);
const kbBulkLoading = ref(false);
const kbBulkSaving = ref(false);
const onHoldNote = ref("");
const showOnHoldForm = ref(null);
const propViewMode = ref('table');
// Status removed from property level
// healthScores removed — Health-Check entfernt

const kbCategoryLabels = {
    objektbeschreibung: "Objektbeschreibung", ausstattung: "Ausstattung", lage_umgebung: "Lage & Umgebung",
    preis_markt: "Preis & Markt", rechtliches: "Rechtliches", energetik: "Energetik",
    feedback_positiv: "Feedback positiv", feedback_negativ: "Feedback negativ", feedback_besichtigung: "Feedback Besichtigung",
    verhandlung: "Verhandlung", eigentuemer_info: "Eigentumer-Info", vermarktung: "Vermarktung",
    dokument_extrakt: "Aus Dokumenten", sonstiges: "Sonstiges",
};



const selectedBrokers = ref(new Set()); // empty = show own, broker_ids = show those

const brokerFilterOpen = ref(false);

// All unique brokers from properties
const availableBrokers = computed(() => {
    const all = (properties?.value ?? properties) || [];
    const map = {};
    for (const p of all) {
        if (p.broker_id && p.broker_name) {
            map[p.broker_id] = p.broker_name;
        }
    }
    return Object.entries(map).map(([id, name]) => ({ id: Number(id), name })).sort((a, b) => a.name.localeCompare(b.name));
});

// Assistenz: auto-select all brokers on init
if (['assistenz','backoffice'].includes(userType.value)) {
  watch(() => availableBrokers.value, (brokers) => {
    if (brokers.length && selectedBrokers.value.size === 0) {
      selectedBrokers.value = new Set(brokers.map(b => b.id));
    }
  }, { immediate: true });
}

// Current user's broker_id (from page props)
const myBrokerId = computed(() => {
    const page = inject("page", null);
    // Fallback: find the broker whose properties are not readonly
    const all = (properties?.value ?? properties) || [];
    const own = all.find(p => !p.is_other_broker);
    return own?.broker_id || null;
});

function toggleBroker(id) {
    const s = new Set(selectedBrokers.value);
    if (s.has(id)) s.delete(id); else s.add(id);
    selectedBrokers.value = s;
}

function selectAllBrokers() {
    selectedBrokers.value = new Set(availableBrokers.value.map(b => b.id));
}

function clearBrokerFilter() {
    selectedBrokers.value = new Set();
    brokerFilterOpen.value = false;
}

const statusCounts = computed(() => {
    let list = (properties?.value ?? properties) || [];
    if (typeFilter.value) list = list.filter(p => p.property_category === typeFilter.value);
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter(p => (p.address||'').toLowerCase().includes(q) || (p.ref_id||'').toLowerCase().includes(q) || (p.city||'').toLowerCase().includes(q) || (p.project_name||'').toLowerCase().includes(q) || (p.broker_name||'').toLowerCase().includes(q));
    }
    // Apply broker filter so counts match visible list
    if (selectedBrokers.value.size > 0) list = list.filter(p => selectedBrokers.value.has(p.broker_id));
    else list = list.filter(p => !p.is_other_broker);
    return {
        aktiv: list.filter(p => p.realty_status !== 'inaktiv' && p.realty_status !== 'verkauft').length,
        inaktiv: list.filter(p => p.realty_status === 'inaktiv').length,
        verkauft: list.filter(p => p.realty_status === 'verkauft').length,
    };
});

const allFilteredProperties = computed(() => {
    let list = ((properties?.value ?? properties) || []).filter(p => !p.parent_id);
    if (statusFilter.value === 'inaktiv') list = list.filter(p => p.realty_status === 'inaktiv');
    else if (statusFilter.value === 'verkauft') list = list.filter(p => p.realty_status === 'verkauft');
    else list = list.filter(p => p.realty_status !== 'inaktiv' && p.realty_status !== 'verkauft');
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter(p => (p.address||'').toLowerCase().includes(q) || (p.ref_id||'').toLowerCase().includes(q) || (p.city||'').toLowerCase().includes(q) || (p.project_name||'').toLowerCase().includes(q) || (p.broker_name||'').toLowerCase().includes(q));
    }
    if (typeFilter.value) list = list.filter(p => p.property_category === typeFilter.value);
    if (selectedBrokers.value.size > 0) list = list.filter(p => selectedBrokers.value.has(p.broker_id));
    list.sort((a, b) => (b.created_at ? new Date(b.created_at) : new Date(0)) - (a.created_at ? new Date(a.created_at) : new Date(0)));
    return list;
});

// Own properties (not readonly)
const myProperties = computed(() => allFilteredProperties.value.filter(p => !p.is_other_broker));
// Other brokers' properties (readonly), grouped by broker
const otherProperties = computed(() => allFilteredProperties.value.filter(p => p.is_other_broker));
const otherByBroker = computed(() => {
    const map = {};
    for (const p of otherProperties.value) {
        const name = p.broker_name || 'Nicht zugewiesen';
        if (!map[name]) map[name] = [];
        map[name].push(p);
    }
    return map;
});

// What gets shown in the main list
const filteredProperties = computed(() => {
    if (selectedBrokers.value.size === 0) return myProperties.value;
    return allFilteredProperties.value.filter(p => selectedBrokers.value.has(p.broker_id));
});

const groupedDisplay = computed(() => {
    const list = filteredProperties.value;
    const grouped = [];
    const groupsSeen = new Set();
    for (const p of list) {
      if (p.project_group_id && !groupsSeen.has(p.project_group_id)) {
        groupsSeen.add(p.project_group_id);
        const groupMembers = list.filter(x => x.project_group_id === p.project_group_id);
        grouped.push({ _isGroup: true, groupId: p.project_group_id, groupName: p.project_name || 'Projektgruppe', members: groupMembers });
      } else if (!p.project_group_id) {
        grouped.push(p);
      }
    }
    return grouped;
});

const kbFilteredItems = computed(() => {
    let items = kbItems.value;
    if (kbFilterCategory.value) items = items.filter((i) => i.category === kbFilterCategory.value);
    if (kbSearch.value.trim()) {
        const q = kbSearch.value.trim().toLowerCase();
        items = items.filter((i) => (i.title || "").toLowerCase().includes(q) || (i.content || "").toLowerCase().includes(q));
    }
    return items;
});

const kbGroupedCategories = computed(() => {
    const groups = {};
    const order = ["objektbeschreibung", "ausstattung", "lage_umgebung", "preis_markt", "rechtliches", "energetik", "feedback_positiv", "feedback_negativ", "feedback_besichtigung", "verhandlung", "eigentuemer_info", "vermarktung", "dokument_extrakt", "sonstiges"];
    for (const item of kbFilteredItems.value) {
        if (!groups[item.category]) groups[item.category] = { key: item.category, label: kbCategoryLabels[item.category] || item.category, items: [] };
        groups[item.category].items.push(item);
    }
    return order.filter((k) => groups[k]).map((k) => groups[k]);
});

async function toggleOnHold(prop) {
    if (prop.on_hold) {
        try {
            const r = await fetch(API.value + "&action=set_on_hold", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ property_id: prop.id, on_hold: 0 }),
            });
            const d = await r.json();
            if (d.success) { prop.on_hold = 0; toast("Vermarktung fortgesetzt"); }
        } catch (e) { toast("Fehler: " + e.message); }
    } else {
        showOnHoldForm.value = prop.id;
    }
}

async function confirmOnHold(prop) {
    try {
        const r = await fetch(API.value + "&action=set_on_hold", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: prop.id, on_hold: 1, note: onHoldNote.value }),
        });
        const d = await r.json();
        if (d.success) { prop.on_hold = 1; prop.on_hold_note = onHoldNote.value; toast("Vermarktung pausiert"); showOnHoldForm.value = null; onHoldNote.value = ""; }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function openKnowledge(propertyId, label) {
    kbPropertyId.value = propertyId;
    kbPropertyLabel.value = label;
    kbOpen.value = true;
    kbItems.value = [];
    kbLoading.value = true;
    kbEditingId.value = null;
    kbSearch.value = "";
    kbFilterCategory.value = "";
    try {
        const r = await fetch(API.value + "&action=list_knowledge&property_id=" + propertyId);
        const d = await r.json();
        kbItems.value = d.knowledge || [];
        kbCategoryCounts.value = d.category_counts || {};
    } catch (e) { toast("Fehler: " + e.message); }
    kbLoading.value = false;
}

async function kbToggleVerify(item) {
    const newVal = item.is_verified == 1 ? 0 : 1;
    try {
        await fetch(API.value + "&action=update_knowledge", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: item.id, is_verified: newVal, confidence: newVal ? "high" : item.confidence }),
        });
        item.is_verified = newVal;
        if (newVal) item.confidence = "high";
        toast(newVal ? "Verifiziert" : "Verifizierung aufgehoben");
    } catch (e) { toast("Fehler: " + e.message); }
}

function kbStartEdit(item) { kbEditingId.value = item.id; kbEditTitle.value = item.title; kbEditContent.value = item.content; }

async function kbSaveEdit(item) {
    try {
        await fetch(API.value + "&action=update_knowledge", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: item.id, title: kbEditTitle.value, content: kbEditContent.value }),
        });
        item.title = kbEditTitle.value;
        item.content = kbEditContent.value;
        kbEditingId.value = null;
        toast("Gespeichert");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function kbDeactivate(item) {
    try {
        await fetch(API.value + "&action=delete_knowledge", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: item.id }) });
        kbItems.value = kbItems.value.filter((i) => i.id !== item.id);
        toast("Ausgeblendet");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function kbHandleFile(event) {
    const file = event.target.files[0];
    if (!file) return;
    kbFileName.value = file.name;
    kbFileUploading.value = true;
    kbBulkPreview.value = [];
    kbFeedText.value = "";
    
    try {
        // Upload file → server extracts text → sends to Haiku → returns categorized entries
        const fd = new FormData();
        fd.append('file', file);
        fd.append('property_id', kbPropertyId.value);
        
        toast("KI analysiert " + file.name + "...");
        const r = await fetch(API.value + "&action=ai_extract_from_file", { method: "POST", body: fd });
        const d = await r.json();
        
        if (d.entries && d.entries.length) {
            kbBulkPreview.value = d.entries.map(e => ({ ...e, selected: true }));
            toast(d.entries.length + " Wissenseinträge aus " + file.name + " erkannt");
        } else if (d.error) {
            toast("Fehler: " + d.error);
        } else {
            toast("KI konnte keine Einträge erkennen");
        }
    } catch (e) {
        toast("Fehler: " + e.message);
    }
    kbFileUploading.value = false;
    event.target.value = "";
}

async function kbBulkCategorize() {
    if (!kbFeedText.value.trim()) return;
    kbBulkLoading.value = true;
    kbBulkPreview.value = [];
    try {
        const r = await fetch(API.value + "&action=ai_bulk_categorize", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: kbPropertyId.value, text: kbFeedText.value.trim() }),
        });
        const d = await r.json();
        if (d.entries && d.entries.length) {
            kbBulkPreview.value = d.entries.map(e => ({ ...e, selected: true }));
            toast(d.entries.length + " Einträge erkannt");
        } else {
            toast(d.error || "Keine Einträge erkannt");
        }
    } catch (e) { toast("Fehler: " + e.message); }
    kbBulkLoading.value = false;
}

async function kbSaveBulk() {
    const selected = kbBulkPreview.value.filter(e => e.selected);
    if (!selected.length) { toast("Keine Einträge ausgewählt"); return; }
    kbBulkSaving.value = true;
    let saved = 0;
    for (const entry of selected) {
        try {
            const r = await fetch(API.value + "&action=add_knowledge", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    property_id: kbPropertyId.value,
                    category: entry.category,
                    title: entry.title,
                    content: entry.content,
                    source_type: "document",
                    confidence: "medium",
                    created_by: "admin",
                }),
            });
            const d = await r.json();
            if (d.success) saved++;
        } catch (e) { /* continue */ }
    }
    toast(saved + " Einträge gespeichert!");
    kbBulkSaving.value = false;
    kbShowFeedModal.value = false;
    kbFeedText.value = "";
    kbBulkPreview.value = [];
    kbFileName.value = "";
    kbFeedMode.value = "text";
    await openKnowledge(kbPropertyId.value, kbPropertyLabel.value);
}

async function kbCategorize() {
    if (!kbFeedText.value.trim()) return;
    kbFeedLoading.value = true;
    
    // For longer texts, use bulk categorize (multiple entries)
    if (kbFeedText.value.trim().length > 300) {
        try {
            const r = await fetch(API.value + "&action=ai_bulk_categorize", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ property_id: kbPropertyId.value, text: kbFeedText.value.trim() }),
            });
            const d = await r.json();
            if (d.entries && d.entries.length) {
                kbBulkPreview.value = d.entries.map(e => ({ ...e, selected: true }));
                toast(d.entries.length + " Einträge erkannt");
            } else {
                toast(d.error || "Konnte nicht kategorisiert werden");
            }
        } catch (e) { toast("Fehler: " + e.message); }
        kbFeedLoading.value = false;
        return;
    }
    
    // Short text: single entry
    try {
        const r = await fetch(API.value + "&action=ai_categorize_knowledge", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: kbPropertyId.value, text: kbFeedText.value.trim() }),
        });
        const d = await r.json();
        if (d.category) kbFeedPreview.value = d;
        else toast(d.error || "Konnte nicht kategorisiert werden");
    } catch (e) { toast("Fehler: " + e.message); }
    kbFeedLoading.value = false;
}

async function kbSaveFeedPreview() {
    if (!kbFeedPreview.value) return;
    try {
        const r = await fetch(API.value + "&action=add_knowledge", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: kbPropertyId.value, category: kbFeedPreview.value.category, title: kbFeedPreview.value.title, content: kbFeedPreview.value.content, source_type: "manual", confidence: "high", created_by: "admin" }),
        });
        const d = await r.json();
        if (d.success) { toast("Wissen gespeichert!"); kbShowFeedModal.value = false; kbFeedText.value = ""; kbFeedPreview.value = null; await openKnowledge(kbPropertyId.value, kbPropertyLabel.value); }
    } catch (e) { toast("Fehler: " + e.message); }
}

// loadHealth removed — Health-Check entfernt

async function openActivities(propertyId, label) {
    actPropertyId.value = propertyId;
    actPropertyLabel.value = label;
    actOpen.value = true;
    actItems.value = [];
    actLoading.value = true;
    actEditingId.value = null;
    actSearch.value = "";
    actCategory.value = "";
    try {
        const r = await fetch(API.value + "&action=list_activities&property_id=" + propertyId);
        const d = await r.json();
        actItems.value = d.activities || [];
    } catch (e) { toast("Fehler: " + e.message); }
    actLoading.value = false;
}

function actStartEdit(item) {
    actEditingId.value = item.id;
    actEditData.value = {
        stakeholder: item.stakeholder || '',
        activity: item.activity || '',
        result: item.result || '',
        category: item.category || 'sonstiges',
        activity_date: item.activity_date || '',
    };
}

async function actSaveEdit(item) {
    try {
        const r = await fetch(API.value + "&action=update_activity", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: item.id, ...actEditData.value }),
        });
        const d = await r.json();
        if (d.success) {
            Object.assign(item, actEditData.value);
            actEditingId.value = null;
            toast("Gespeichert");
        } else toast(d.error || "Fehler");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function actDelete(item) {
    if (!confirm("Aktivität löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_activity", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: item.id }),
        });
        const d = await r.json();
        if (d.success) {
            actItems.value = actItems.value.filter(a => a.id !== item.id);
            toast("Gelöscht");
        }
    } catch (e) { toast("Fehler: " + e.message); }
}

const filteredActivities = computed(() => {
    let items = actItems.value;
    if (actSearch.value) {
        const s = actSearch.value.toLowerCase();
        items = items.filter(a => (a.stakeholder||'').toLowerCase().includes(s) || (a.activity||'').toLowerCase().includes(s) || (a.result||'').toLowerCase().includes(s));
    }
    if (actCategory.value) {
        items = items.filter(a => a.category === actCategory.value);
    }
    return items;
});

const actCategories = ['anfrage','email-in','email-out','expose','besichtigung','kaufanbot','absage','update','sonstiges','bounce'];

// Portal Documents panel state
const docsOpen = ref(false);
const docsPropertyId = ref(null);
const docsPropertyLabel = ref("");
const docsList = ref([]);
const docsLoading = ref(false);
const docsUploading = ref(false);
const docsUploadFile = ref(null);
const docsUploadDesc = ref("");
const docsUploadInput = ref(null);

// Portal Messages panel state
const msgsOpen = ref(false);
const msgsPropertyId = ref(null);
const msgsPropertyLabel = ref("");
const msgsList = ref([]);
const msgsLoading = ref(false);
const msgsNewText = ref("");
const msgsSending = ref(false);
const msgsUnread = ref({});
async function openDocs(propId, propLabel) {
    docsPropertyId.value = propId;
    docsPropertyLabel.value = propLabel;
    docsOpen.value = true;
    docsLoading.value = true;
    docsList.value = [];
    try {
        const r = await fetch(API.value + "&action=list_portal_documents&property_id=" + propId);
        const d = await r.json();
        docsList.value = d.documents || [];
    } catch (e) { toast("Fehler: " + e.message); }
    finally { docsLoading.value = false; }
}

async function docsHandleFile(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    docsUploadFile.value = file;
}

async function docsUpload() {
    if (!docsUploadFile.value || docsUploading.value) return;
    docsUploading.value = true;
    try {
        const form = new FormData();
        form.append("file", docsUploadFile.value);
        form.append("property_id", docsPropertyId.value);
        form.append("description", docsUploadDesc.value || docsUploadFile.value.name);
        const r = await fetch(API.value + "&action=upload_portal_document", {
            method: "POST", body: form
        });
        const d = await r.json();
        if (d.success) {
            toast("Dokument hochgeladen");
            docsUploadFile.value = null;
            docsUploadDesc.value = "";
            if (docsUploadInput.value) docsUploadInput.value.value = "";
            // Reload
            const r2 = await fetch(API.value + "&action=list_portal_documents&property_id=" + docsPropertyId.value);
            const d2 = await r2.json();
            docsList.value = d2.documents || [];
        } else {
            toast("Fehler: " + (d.error || "Upload fehlgeschlagen"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
    finally { docsUploading.value = false; }
}

async function docsDelete(docId) {
    if (!confirm("Dokument loeschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_portal_document", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: docId })
        });
        const d = await r.json();
        if (d.success) {
            docsList.value = docsList.value.filter(doc => doc.id !== docId);
            toast("Geloescht");
        }
    } catch (e) { toast("Fehler: " + e.message); }
}

function docsFormatSize(bytes) {
    if (!bytes) return "0 B";
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + " MB";
    if (bytes >= 1024) return Math.round(bytes / 1024) + " KB";
    return bytes + " B";
}

async function openMsgs(propId, propLabel) {
    msgsPropertyId.value = propId;
    msgsPropertyLabel.value = propLabel;
    msgsOpen.value = true;
    msgsLoading.value = true;
    msgsList.value = [];
    try {
        const r = await fetch(API.value + "&action=list_portal_messages&property_id=" + propId);
        const d = await r.json();
        msgsList.value = d.messages || [];
    } catch (e) { toast("Fehler: " + e.message); }
    finally { msgsLoading.value = false; }
}

async function msgsSend() {
    const text = msgsNewText.value.trim();
    if (!text || msgsSending.value) return;
    msgsSending.value = true;
    try {
        const r = await fetch(API.value + "&action=send_portal_message", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: msgsPropertyId.value, message: text })
        });
        const d = await r.json();
        if (d.success && d.message) {
            msgsList.value.push(d.message);
            msgsNewText.value = "";
            toast("Nachricht gesendet");
        }
    } catch (e) { toast("Fehler: " + e.message); }
    finally { msgsSending.value = false; }
}

async function msgsDelete(msgId) {
    try {
        const r = await fetch(API.value + "&action=delete_portal_message", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: msgId })
        });
        const d = await r.json();
        if (d.success) {
            msgsList.value = msgsList.value.filter(m => m.id !== msgId);
        }
    } catch (e) { toast("Fehler: " + e.message); }
}

function msgsFormatTime(dt) {
    if (!dt) return "";
    return new Date(dt).toLocaleString("de-AT", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
}

// Property files (general multi-file)
const propFilesOpen = ref(false);
const propFilesId = ref(null);
const propFilesLabel = ref("");
const propFilesList = ref([]);
const propFilesLoading = ref(false);
const propFilesUploading = ref(false);
const propFilesNewLabel = ref("Dokument");

// Property settings
const propSettingsOpen = ref(false);
const propSettingsId = ref(null);
const propSettingsData = ref({});
const propSettingsSchema = ref([]);
const propSettingsUnits = ref([]);
const propSettingsUnitSummary = ref(null);
const propSettingsCategories = ref([]);
const propSettingsLoading = ref(false);
const propSettingsSaving = ref(false);
const propSettingsTab = ref('prop_kaufanbote');
// Property-level Kaufanbote
const propKaufanbote = ref([]);
const propKaufanboteLoading = ref(false);
const propKaufanbotUploading = ref(false);
const propKaufanbotForm = ref({ buyer_name: "", buyer_email: "", buyer_phone: "", amount: "", kaufanbot_date: "", notes: "" });
watch(propSettingsTab, (tab) => { if (tab === "prop_kaufanbote") loadPropertyKaufanbote(); });

const portalUser = ref(null);
const showPortalForm = ref(false);
const portalForm = ref({ name: '', email: '', password: '' });
const portalCreating = ref(false);
const portalError = ref('');
const portalSuccess = ref('');

// Eigentümer management
const showCreateOwnerForm = ref(false);
const newOwnerForm = ref({ name: '', email: '', phone: '' });
const newOwnerSaving = ref(false);
const showEditCustomer = ref(false);
const editCustomerForm = ref({ id: null, name: '', email: '', phone: '', address: '', city: '', zip: '', notes: '' });
const editCustomerSaving = ref(false);
const deleteCustomerConfirm = ref(false);
const deleteCustomerLoading = ref(false);

const propSettingsEditUnit = ref(null);
const propSettingsParking = ref([]);
const bulkParkingForm = ref(null);
const singleParkingForm = ref(null);
const distinctUnitCount = computed(() => new Set([...propSettingsUnits.value, ...propSettingsParking.value].map(u => u.unit_number)).size);
const splitLoading = ref(false);
const propSettingsNewUnit = ref(null);
// Sales Volume
const salesData = ref(null);
const salesPeriod = ref('year');
const salesExpanded = ref(false);
const salesExpandedProp = ref(null);
const salesLoading = ref(false);
const periodLabels = { week: 'Woche', month: 'Monat', year: 'Jahr', all: 'Gesamt' };
async function loadSalesVolume(period) {
    salesPeriod.value = period || salesPeriod.value;
    salesLoading.value = true;
    try {
        const r = await fetch(API.value + '&action=get_sales_volume&period=' + salesPeriod.value);
        salesData.value = await r.json();
    } catch(e) { console.error(e); }
    salesLoading.value = false;
}

const openParkingCategories = reactive({});const parkingByCategory = computed(() => {    const groups = {};    for (const p of propSettingsParking.value) {        const cat = p.unit_type || "Sonstige";        if (!groups[cat]) groups[cat] = [];        groups[cat].push(p);    }    return groups;});const kaufanbotUnits = computed(() => {
    return propSettingsUnits.value.filter(u => u.kaufanbot_pdf);
});
function toggleParkingCategory(cat) {    openParkingCategories[cat] = !openParkingCategories[cat];}
const uploadingPdfUnitId = ref(null);
const uploadingImageUnitId = ref(null);

const propSettingsTabs = computed(() => {
    const cat = propSettingsData.value.property_category;
    const tabs = [];
    // Only Einheiten/Kaufanbote/Stellplaetze for newbuild, plus shared tabs
    if (cat === 'newbuild') {
        tabs.push({ key: 'einheiten', label: 'Einheiten (' + distinctUnitCount.value + ')' });
        tabs.push({ key: 'kaufanbote', label: 'Kaufanbote (' + kaufanbotUnits.value.length + ')' });
        // stellplaetze tab removed
    }
    tabs.push({ key: 'prop_kaufanbote', label: 'Kaufanbote' });
    return tabs;
});

// Field groups per tab per category
const groupedUnitsByFloor = computed(() => {
    const groups = {};
    for (const u of propSettingsUnits.value) {
        const f = u.floor != null ? u.floor : 0;
        if (!groups[f]) groups[f] = [];
        groups[f].push(u);
    }
    // Integrate parking into floor -1 (Tiefgarage / Stellplätze)
    if (propSettingsParking.value.length) {
        if (!groups[-1]) groups[-1] = [];
        for (const p of propSettingsParking.value) {
            groups[-1].push(p);
        }
    }
    const sorted = {};
    for (const k of Object.keys(groups).sort((a,b) => a-b)) sorted[k] = groups[k];
    return sorted;
});

const FLOOR_NAMES = { '-1': 'Tiefgarage / Stellplätze', 0: 'Erdgeschoss', 1: '1. Obergeschoss', 2: '2. Obergeschoss', 3: 'Dachgeschoss' };

const FIELD_GROUPS = {
    basis: ['ref_id', 'project_name', 'address', 'city', 'zip', 'property_category', 'purchase_price', 'platforms', 'inserat_since', 'realty_description', 'highlights'],
    details_house: ['living_area', 'free_area', 'rooms_amount', 'floor_count', 'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value'],
    details_apartment: ['living_area', 'rooms_amount', 'floor_number', 'floor_count', 'construction_year', 'year_renovated', 'heating', 'energy_certificate', 'heating_demand_value'],
    ausstattung_house: ['garage_spaces', 'parking_spaces', 'has_basement', 'has_garden', 'has_balcony', 'has_terrace', 'condition_note', 'furnishing', 'orientation', 'noise_level'],
    ausstattung_apartment: ['garage_spaces', 'parking_spaces', 'has_elevator', 'has_balcony', 'has_terrace', 'has_loggia', 'has_basement', 'condition_note', 'furnishing', 'orientation', 'noise_level'],
    kosten: ['operating_costs', 'maintenance_reserves', 'commission_percent', 'commission_note'],
    projekt: ['builder_company', 'construction_start', 'construction_end', 'move_in_date', 'total_units', 'energy_certificate', 'heating_demand_value', 'has_elevator', 'garage_spaces', 'parking_spaces'],
    grundstueck: ['free_area', 'total_area', 'plot_dedication', 'plot_buildable', 'plot_developed', 'orientation', 'noise_level'],
    eigentuemer: ['commission_percent', 'commission_note', 'commission_total', 'commission_makler'],
};

function getFieldsForTab(tabKey) {
    const cat = propSettingsData.value.property_category || '';
    // Map new types to existing field groups for tabs like details/ausstattung/kosten
    let lookupCat = cat;
    if (['bungalow','villa','reihenhaus','doppelhaus','newbuild_single'].includes(cat)) lookupCat = 'house';
    else if (['penthouse','dachgeschoss','garconniere'].includes(cat)) lookupCat = 'apartment';
    else if (['gewerbe','anlage'].includes(cat)) lookupCat = 'apartment';
    const key = tabKey + '_' + lookupCat;
    return FIELD_GROUPS[key] || FIELD_GROUPS[tabKey] || [];
}

function getFieldMeta(fieldKey) {
    return propSettingsSchema.value.find(s => s.key === fieldKey) || { key: fieldKey, label: fieldKey, type: 'text' };
}

async function openPropSettings(propId) {
    propSettingsId.value = propId;
    propSettingsOpen.value = true;
    propSettingsLoading.value = true;
    // Only reset tab on fresh open, not on reload
    if (!propSettingsOpen.value) propSettingsTab.value = propSettingsTabs.value[0]?.key || 'prop_kaufanbote';
    propSettingsEditUnit.value = null;
    try {
        const r = await fetch(API.value + "&action=get_property_settings&property_id=" + propId);
        const d = await r.json();
        propSettingsData.value = d.property || {};
        propSettingsSchema.value = d.schema || [];
        propSettingsUnits.value = d.units || [];
        propSettingsParking.value = d.parking || [];
        propSettingsUnitSummary.value = d.unit_summary || null;
        propSettingsCategories.value = d.categories || [];
        portalUser.value = d.portal_user || null;
        showPortalForm.value = false;
        portalForm.value = { name: propSettingsData.value.owner_name || '', email: propSettingsData.value.owner_email || '', password: '' };
        portalError.value = '';
        portalSuccess.value = '';
    } catch (e) { toast("Fehler: " + e.message); }
    propSettingsLoading.value = false;
}

async function createPortalAccess() {
    const ownerName = propSettingsData.value.owner_name;
    const ownerEmail = propSettingsData.value.owner_email;
    if (!ownerName || !ownerEmail || !portalForm.value.password) {
        portalError.value = 'Eigentümer-Daten und Passwort erforderlich';
        return;
    }
    portalCreating.value = true;
    portalError.value = '';
    try {
        const r = await fetch(API.value + '&action=create_portal_access', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                property_id: propSettingsId.value,
                name: ownerName,
                email: ownerEmail,
                password: portalForm.value.password,
            }),
        });
        const d = await r.json();
        if (d.success) {
            portalUser.value = d.user;
            showPortalForm.value = false;
            portalSuccess.value = 'Zugang erstellt!';
            toast('✓ Portalzugang erstellt');
        } else {
            portalError.value = d.error || 'Fehler beim Erstellen';
        }
    } catch (e) {
        portalError.value = e.message;
    }
    portalCreating.value = false;
}

async function savePropSettings() {
    propSettingsSaving.value = true;
    try {
        const payload = { property_id: propSettingsId.value };
        // Send all fields that have values
        for (const s of propSettingsSchema.value) {
            if (propSettingsData.value[s.key] !== undefined) {
                payload[s.key] = propSettingsData.value[s.key];
            }
        }
        const r = await fetch(API.value + "&action=save_property_settings", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.success) {
            toast("✓ Einstellungen gespeichert!");
            // Reload to get updated schema (category might have changed)
            openPropSettings(propSettingsId.value);
        } else toast("Fehler: " + (d.error || "Unbekannt"));
    } catch (e) { toast("Fehler: " + e.message); }
    propSettingsSaving.value = false;
}

async function saveUnit(unitData) {
    try {
        // Include assigned_parking as JSON
        const payload = { ...unitData, property_id: propSettingsId.value, unit_id: unitData.id || 0 };
        if (unitData.assigned_parking_ids) {
            payload.assigned_parking = JSON.stringify(unitData.assigned_parking_ids);
        }
        const r = await fetch(API.value + "&action=save_property_unit", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.success) {
            toast("✓ Einheit gespeichert");
            propSettingsEditUnit.value = null;
            openPropSettings(propSettingsId.value);
        } else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
}

async function saveNewUnit() {
    if (!propSettingsNewUnit.value) return;
    const data = { ...propSettingsNewUnit.value };
    propSettingsNewUnit.value = null;
    try {
        const payload = { ...data, property_id: propSettingsId.value };
        if (data.assigned_parking_ids) {
            payload.assigned_parking = JSON.stringify(data.assigned_parking_ids);
        }
        const r = await fetch(API.value + "&action=save_property_unit", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.success) {
            toast("✓ Einheit gespeichert");
            openPropSettings(propSettingsId.value);
        } else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
}

async function deleteUnit(unitId) {
    if (!confirm("Einheit wirklich löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_property_unit", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ unit_id: unitId }),
        });
        const d = await r.json();
        if (d.success) { toast("Einheit gelöscht"); openPropSettings(propSettingsId.value); }
    } catch (e) { toast("Fehler: " + e.message); }
}

function editUnit(u) {
    if (propSettingsEditUnit.value && propSettingsEditUnit.value.id === u.id) {
        propSettingsEditUnit.value = null;
        return;
    }
    const data = { ...u };
    // Parse images JSON
    if (typeof data.images === "string") try { data.images = JSON.parse(data.images); } catch { data.images = []; }
    if (!data.images) data.images = [];
    // Parse assigned_parking JSON into array of IDs
    if (data.assigned_parking) {
        try { data.assigned_parking_ids = JSON.parse(data.assigned_parking) || []; } catch { data.assigned_parking_ids = []; }
    } else {
        data.assigned_parking_ids = [];
    }
    propSettingsEditUnit.value = data;
    // Scroll edit form into view
    setTimeout(() => {
        const el = document.querySelector('[data-edit-form]');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 100);
}

function toggleParkingAssignment(parkingId) {
    if (!propSettingsEditUnit.value.assigned_parking_ids) {
        propSettingsEditUnit.value.assigned_parking_ids = [];
        // Initialize from existing assigned_parking JSON
        if (propSettingsEditUnit.value.assigned_parking) {
            try { propSettingsEditUnit.value.assigned_parking_ids = JSON.parse(propSettingsEditUnit.value.assigned_parking) || []; } catch {}
        }
    }
    const idx = propSettingsEditUnit.value.assigned_parking_ids.indexOf(parkingId);
    if (idx >= 0) propSettingsEditUnit.value.assigned_parking_ids.splice(idx, 1);
    else propSettingsEditUnit.value.assigned_parking_ids.push(parkingId);
}

function calcTotalPrice() {
    const unit = propSettingsEditUnit.value;
    let total = parseFloat(unit.price) || 0;
    const ids = unit.assigned_parking_ids || [];
    for (const sp of propSettingsParking.value) {
        if (ids.includes(sp.id)) total += parseFloat(sp.purchase_price) || 0;
    }
    return total;
}

async function bulkCreateParking() {
    const f = bulkParkingForm.value;
    if (!f) return;
    try {
        const r = await fetch(API.value + "&action=bulk_create_parking", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: propSettingsId.value, prefix: f.prefix, type: f.type, from: f.from, to: f.to, price: f.price }),
        });
        const d = await r.json();
        if (d.success) { toast(d.created + " Stellplätze angelegt"); bulkParkingForm.value = null; openPropSettings(propSettingsId.value); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
}

async function deleteSingleParking(parkingId, e) {
    e.stopPropagation();
    if (!confirm("Stellplatz wirklich löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_property_unit", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ unit_id: parkingId }),
        });
        const d = await r.json();
        if (d.success) { toast("Stellplatz gelöscht"); openPropSettings(propSettingsId.value); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e2) { toast("Fehler: " + e2.message); }
}

async function createSingleParking() {
    const f = singleParkingForm.value;
    if (!f || !f.unit_number) return;
    try {
        const r = await fetch(API.value + "&action=create_single_parking", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: propSettingsId.value, unit_number: f.unit_number, unit_type: f.unit_type, price: f.price }),
        });
        const d = await r.json();
        if (d.success) { toast("Stellplatz angelegt"); singleParkingForm.value = null; openPropSettings(propSettingsId.value); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e2) { toast("Fehler: " + e2.message); }
}

async function splitUnit(unitId) {
    if (!confirm("Einheit aufteilen? Es wird ein zweiter Eintrag mit gleichem TOP erstellt.")) return;
    splitLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=split_unit", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ unit_id: unitId }),
        });
        const d = await r.json();
        if (d.success) { toast("Einheit aufgeteilt"); propSettingsEditUnit.value = null; openPropSettings(propSettingsId.value); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e2) { toast("Fehler: " + e2.message); }
    splitLoading.value = false;
}

async function removeKaufanbotPdf(unitId) {    if (!confirm("Kaufanbot-PDF wirklich entfernen?")) return;    try {        const r = await fetch(API.value + "&action=remove_kaufanbot_pdf", {            method: "POST", headers: { "Content-Type": "application/json" },            body: JSON.stringify({ unit_id: unitId })        });        const d = await r.json();        if (d.success) { toast("Kaufanbot-PDF entfernt"); openPropSettings(propSettingsId.value); }        else toast("Fehler: " + (d.error || ""));    } catch (e) { toast("Fehler: " + e.message); }}
async function uploadUnitImage(unitId, event) {
    const file = event.target.files?.[0];
    if (!file) return;
    uploadingImageUnitId.value = unitId;
    const fd = new FormData();
    fd.append("unit_id", unitId);
    fd.append("file", file);
    try {
        const r = await fetch(API.value + "&action=upload_unit_image", { method: "POST", body: fd });
        const d = await r.json();
        if (d.success) {
            toast("Bild hochgeladen");
            // Update the images in the edit form
            if (propSettingsEditUnit.value && propSettingsEditUnit.value.id === unitId) {
                propSettingsEditUnit.value.images = d.images;
            }
            // Also update in the units list
            const idx = propSettingsUnits.value.findIndex(u => u.id === unitId);
            if (idx >= 0) propSettingsUnits.value[idx].images = d.images;
        } else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
    uploadingImageUnitId.value = null;
    event.target.value = "";
}

async function deleteUnitImage(unitId, imageUrl) {
    if (!confirm("Bild wirklich entfernen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_unit_image", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ unit_id: unitId, image_url: imageUrl })
        });
        const d = await r.json();
        if (d.success) {
            toast("Bild entfernt");
            if (propSettingsEditUnit.value && propSettingsEditUnit.value.id === unitId) {
                propSettingsEditUnit.value.images = d.images;
            }
            const idx = propSettingsUnits.value.findIndex(u => u.id === unitId);
            if (idx >= 0) propSettingsUnits.value[idx].images = d.images;
        } else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
}

async function uploadKaufanbotPdf(unitId, event) {
    const file = event.target.files?.[0];
    if (!file) return;
    uploadingPdfUnitId.value = unitId;
    const fd = new FormData();
    fd.append("unit_id", unitId);
    fd.append("file", file);
    try {
        const r = await fetch(API.value + "&action=upload_kaufanbot_pdf", { method: "POST", body: fd });
        const d = await r.json();
        if (d.success) { toast("Kaufanbot-PDF hochgeladen"); openPropSettings(propSettingsId.value); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
    uploadingPdfUnitId.value = null;
    event.target.value = "";
}


// ── Property-level Kaufanbote methods ──────────────────
async function loadPropertyKaufanbote() {
    if (!propSettingsId.value) return;
    propKaufanboteLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=list_property_kaufanbote&property_id=" + propSettingsId.value);
        const d = await r.json();
        propKaufanbote.value = d.kaufanbote || [];
    } catch (e) { toast("Fehler: " + e.message); }
    propKaufanboteLoading.value = false;
}

async function uploadPropertyKaufanbot(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!propKaufanbotForm.value.buyer_name.trim()) { toast("Käufername ist erforderlich"); return; }
    propKaufanbotUploading.value = true;
    const fd = new FormData();
    fd.append("property_id", propSettingsId.value);
    fd.append("buyer_name", propKaufanbotForm.value.buyer_name);
    if (propKaufanbotForm.value.buyer_email) fd.append("buyer_email", propKaufanbotForm.value.buyer_email);
    if (propKaufanbotForm.value.buyer_phone) fd.append("buyer_phone", propKaufanbotForm.value.buyer_phone);
    if (propKaufanbotForm.value.amount) fd.append("amount", propKaufanbotForm.value.amount);
    if (propKaufanbotForm.value.kaufanbot_date) fd.append("kaufanbot_date", propKaufanbotForm.value.kaufanbot_date);
    if (propKaufanbotForm.value.notes) fd.append("notes", propKaufanbotForm.value.notes);
    fd.append("pdf", file);
    try {
        const r = await fetch(API.value + "&action=upload_property_kaufanbot", { method: "POST", body: fd });
        const d = await r.json();
        if (d.success) {
            toast("Kaufanbot hochgeladen");
            propKaufanbotForm.value = { buyer_name: "", buyer_email: "", buyer_phone: "", amount: "", kaufanbot_date: "", notes: "" };
            loadPropertyKaufanbote();
        } else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
    propKaufanbotUploading.value = false;
    event.target.value = "";
}

async function deletePropertyKaufanbot(id) {
    if (!confirm("Kaufanbot wirklich löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_property_kaufanbot", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id })
        });
        const d = await r.json();
        if (d.success) { toast("Kaufanbot gelöscht"); loadPropertyKaufanbote(); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
}

async function updatePropertyKaufanbotStatus(id, status) {
    try {
        const r = await fetch(API.value + "&action=update_property_kaufanbot_status", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id, status })
        });
        const d = await r.json();
        if (d.success) { toast("Status aktualisiert"); loadPropertyKaufanbote(); }
        else toast("Fehler: " + (d.error || ""));
    } catch (e) { toast("Fehler: " + e.message); }
}
async function openPropFiles(propId, propLabel) {
    propFilesId.value = propId;
    propFilesLabel.value = propLabel;
    propFilesOpen.value = true;
    propFilesLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=get_property_files&property_id=" + propId);
        const d = await r.json();
        propFilesList.value = d.files || [];
    } catch (e) { toast("Fehler: " + e.message); }
    finally { propFilesLoading.value = false; }
}

async function uploadPropFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    propFilesUploading.value = true;
    try {
        const form = new FormData();
        form.append("file", file);
        form.append("property_id", propFilesId.value);
        form.append("label", propFilesNewLabel.value || "Dokument");
        const r = await fetch(API.value + "&action=upload_property_file", { method: "POST", body: form });
        const d = await r.json();
        if (d.success) {
            toast(d.file.label + " hochgeladen");
            propFilesList.value.push(d.file);
            propFilesNewLabel.value = "Dokument";
        } else toast("Fehler: " + (d.error || "Upload fehlgeschlagen"));
    } catch (e) { toast("Fehler: " + e.message); }
    finally { propFilesUploading.value = false; event.target.value = ""; }
}

async function deletePropFile(fileId) {
    if (!confirm("Datei wirklich löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_property_file", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ file_id: fileId })
        });
        const d = await r.json();
        if (d.success) {
            propFilesList.value = propFilesList.value.filter(f => f.id !== fileId);
            toast("Gelöscht");
        }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function toggleWebsiteDownload(f) {
    try {
        const r = await fetch(API.value + "&action=toggle_website_download", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ file_id: f.id })
        });
        const d = await r.json();
        if (d.success) {
            f.is_website_download = d.is_website_download;
            toast(d.is_website_download ? "Download auf Website aktiviert" : "Download von Website entfernt");
        }
    } catch (e) { toast("Fehler: " + e.message); }
}


</script>

<template>
    <PropertyDetailPage
      v-if="selectedProperty"
      :property="selectedProperty"
      :is-new="isNewProperty"
      @back="closeDetail"
      @toggle-on-hold="toggleOnHold(selectedProperty)"
      @delete-property="deleteProperty(selectedProperty)"
      @property-created="handlePropertyCreated"
      @owner-changed="handleOwnerChanged"
      @saved="handlePropertySaved"
    />
    <div v-else class="px-3 sm:px-5 py-4 sm:py-5 space-y-0">
    <!-- Toolbar Row 1: Actions (title is in Dashboard header bar) -->
    <div class="flex items-center justify-end gap-2 pb-3">
        <Button variant="outline" size="sm" class="h-8 text-xs hidden sm:inline-flex" @click="showGlobalFiles = true; loadGlobalFiles()">
          <FileText class="w-3.5 h-3.5 mr-1.5" />
          Allg. Dokumente
        </Button>
        <Button size="sm" class="h-8 text-xs" style="background:hsl(240 5.9% 10%);color:white" @click="openNewProperty()">
          <Plus class="w-3.5 h-3.5 mr-1" />
          <span class="hidden sm:inline">Neues Objekt</span>
          <span class="sm:hidden">Neu</span>
        </Button>
    </div>

    <!-- Toolbar Row 2: Filters (Desktop) -->
    <div class="hidden sm:flex items-center gap-2 py-2" style="border-bottom:1px solid hsl(240 5.9% 90%)">
      <!-- Search -->
      <div class="relative">
        <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style="color:hsl(240 3.8% 46.1%)" />
        <Input v-model="searchQuery" placeholder="Suchen..." class="h-8 w-[200px] pl-8 text-[13px]" />
      </div>

      <!-- Status Tabs -->
      <div class="inline-flex h-auto p-0.5 gap-0.5 rounded-md" style="background:hsl(240 4.8% 95.9%)">
        <button v-for="s in [{v:'aktiv',l:'Aktiv'},{v:'inaktiv',l:'Inaktiv'},{v:'verkauft',l:'Verkauft'}]" :key="s.v"
          :class="statusFilter === s.v ? 'bg-white shadow-sm font-semibold' : ''" class="rounded px-3 py-1 text-xs transition-all" @click="statusFilter = s.v">
          {{ s.l }} <span class="ml-1 text-[10px]" style="color:hsl(240 3.8% 46.1%)">{{ statusCounts[s.v] }}</span>
        </button>
      </div>

      <Separator orientation="vertical" class="h-5" />

      <!-- Type Tabs -->
      <div class="inline-flex h-auto p-0.5 gap-0.5 rounded-md" style="background:hsl(240 4.8% 95.9%)">
        <button v-for="t in [{v:'',l:'Alle'},{v:'apartment',l:'Wohnung'},{v:'house',l:'Haus'},{v:'newbuild',l:'Neubau'},{v:'land',l:'Grundstück'}]" :key="t.v"
          :class="typeFilter === t.v ? 'bg-white shadow-sm font-semibold' : ''" class="rounded px-3 py-1 text-xs transition-all" @click="typeFilter = t.v">
          {{ t.l }}
        </button>
      </div>

      <div class="flex-1"></div>

      <!-- Broker filter -->
      <div v-if="availableBrokers.length > 1" class="relative">
        <button @click="brokerFilterOpen = !brokerFilterOpen"
          class="inline-flex items-center gap-1.5 h-8 px-3 text-xs rounded-md transition-all"
          :style="selectedBrokers.size ? 'background:hsl(240 5.9% 10%);color:white' : 'border:1px solid hsl(240 5.9% 90%);color:hsl(240 3.8% 46.1%)'">
          <Users class="w-3.5 h-3.5" />
          {{ selectedBrokers.size ? selectedBrokers.size + ' Makler' : 'Makler' }}
          <ChevronDown class="w-3 h-3" />
        </button>
        <div v-if="brokerFilterOpen" class="fixed inset-0 z-40" @click="brokerFilterOpen = false"></div>
        <div v-if="brokerFilterOpen" class="absolute right-0 top-full mt-1 z-50 bg-white border rounded-lg shadow-lg min-w-[220px] py-1" style="border-color:hsl(240 5.9% 90%)">
          <div class="px-3 py-2 flex items-center justify-between" style="border-bottom:1px solid hsl(240 5.9% 90%)">
            <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:hsl(240 3.8% 46.1%)">Makler filtern</span>
            <div class="flex gap-1">
              <button @click="selectAllBrokers()" class="text-[10px] px-1.5 py-0.5 rounded hover:bg-gray-100" style="color:hsl(240 5.9% 10%)">Alle</button>
              <button @click="clearBrokerFilter()" class="text-[10px] px-1.5 py-0.5 rounded hover:bg-gray-100" style="color:hsl(240 3.8% 46.1%)">Reset</button>
            </div>
          </div>
          <label v-for="b in availableBrokers" :key="b.id"
            class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-gray-50 transition-colors text-[12px]"
            @click="toggleBroker(b.id)">
            <span class="w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 transition-all"
              :style="selectedBrokers.has(b.id) ? 'background:hsl(240 5.9% 10%);border-color:hsl(240 5.9% 10%)' : 'border-color:hsl(240 5.9% 90%)'">
              <Check v-if="selectedBrokers.has(b.id)" class="w-2.5 h-2.5 text-white" />
            </span>
            <span class="flex-1 truncate">{{ b.name }}</span>
            <span class="text-[10px] tabular-nums" style="color:hsl(240 3.8% 46.1%)">{{ allFilteredProperties.filter(p => p.broker_id === b.id).length }}</span>
          </label>
        </div>
      </div>

      <!-- View Toggle -->
      <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid hsl(240 5.9% 90%)">
        <button @click="propViewMode = 'table'" class="w-8 h-[30px] flex items-center justify-center transition-all" :style="propViewMode === 'table' ? 'background:hsl(240 4.8% 95.9%)' : ''">
          <LayoutList class="w-3.5 h-3.5" />
        </button>
        <button @click="propViewMode = 'cards'" class="w-8 h-[30px] flex items-center justify-center transition-all" style="border-left:1px solid hsl(240 5.9% 90%)" :style="propViewMode === 'cards' ? 'background:hsl(240 4.8% 95.9%)' : ''">
          <LayoutGrid class="w-3.5 h-3.5" />
        </button>
      </div>
    </div>

    <!-- Mobile Toolbar -->
    <div class="sm:hidden space-y-2 py-2" style="border-bottom:1px solid hsl(240 5.9% 90%)">
      <div class="relative">
        <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style="color:hsl(240 3.8% 46.1%)" />
        <Input v-model="searchQuery" placeholder="Suchen..." class="h-8 pl-8 text-[13px]" />
      </div>
      <div class="flex gap-1.5 overflow-x-auto" style="-webkit-overflow-scrolling:touch;scrollbar-width:none">
        <div class="inline-flex h-auto p-0.5 gap-0.5 rounded-md flex-shrink-0" style="background:hsl(240 4.8% 95.9%)">
          <button v-for="s in [{v:'aktiv',l:'Aktiv'},{v:'inaktiv',l:'Inaktiv'},{v:'verkauft',l:'Verkauft'}]" :key="s.v"
            :class="statusFilter === s.v ? 'bg-white shadow-sm' : ''" class="rounded px-2 py-1 text-[11px] transition-all" @click="statusFilter = s.v">
            {{ s.l }} <span class="ml-0.5 text-[9px]" style="color:hsl(240 3.8% 46.1%)">{{ statusCounts[s.v] }}</span>
          </button>
        </div>
      </div>
      <div class="flex gap-1.5 overflow-x-auto" style="-webkit-overflow-scrolling:touch;scrollbar-width:none">
        <div class="inline-flex h-auto p-0.5 gap-0.5 rounded-md flex-shrink-0" style="background:hsl(240 4.8% 95.9%)">
          <button v-for="t in [{v:'',l:'Alle'},{v:'apartment',l:'Wohnung'},{v:'house',l:'Haus'},{v:'newbuild',l:'Neubau'},{v:'land',l:'Grundstück'}]" :key="t.v"
            :class="typeFilter === t.v ? 'bg-white shadow-sm' : ''" class="rounded px-2 py-1 text-[11px] transition-all" @click="typeFilter = t.v">
            {{ t.l }}
          </button>
        </div>
      </div>
    </div>

    <!-- TABLE VIEW (Default, Desktop) -->
    <div v-if="propViewMode === 'table'" class="hidden sm:block mt-3 rounded-lg overflow-hidden" style="border:1px solid hsl(240 5.9% 90%)">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-[44px]"></TableHead>
            <TableHead class="text-[11px] uppercase tracking-wider font-medium" style="color:hsl(240 3.8% 46.1%)">Objekt</TableHead>
            <TableHead class="text-[11px] uppercase tracking-wider font-medium" style="color:hsl(240 3.8% 46.1%)">Ort</TableHead>
            <TableHead class="text-[11px] uppercase tracking-wider font-medium" style="color:hsl(240 3.8% 46.1%)">Typ</TableHead>
            <TableHead class="text-[11px] uppercase tracking-wider font-medium text-right" style="color:hsl(240 3.8% 46.1%)">Kaufpreis</TableHead>
            <TableHead class="text-[11px] uppercase tracking-wider font-medium" style="color:hsl(240 3.8% 46.1%)">Fläche</TableHead>
            <TableHead class="text-[11px] uppercase tracking-wider font-medium" style="color:hsl(240 3.8% 46.1%)">Portale</TableHead>
            <TableHead class="w-[20px]"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <template v-for="prop in filteredProperties" :key="prop.id">
            <TableRow class="cursor-pointer hover:bg-[hsl(240_4.8%_95.9%)] transition-colors" :style="prop.realty_status === 'inaktiv' ? 'opacity:0.45' : ''" @click="openDetail(prop)">
              <TableCell class="py-1.5 px-2 pl-4">
                <div class="w-12 h-9 rounded overflow-hidden flex-shrink-0 flex items-center justify-center" style="background:hsl(240 4.8% 95.9%)">
                  <img v-if="prop.thumbnail_url" :src="prop.thumbnail_url" class="w-full h-full object-cover" loading="lazy" />
                  <span v-else style="font-size:14px;color:hsl(240 3.8% 46.1%)">⌂</span>
                </div>
              </TableCell>
              <TableCell>
                <span class="font-semibold text-[13px]">{{ prop.project_name || prop.address }}</span>
                <span v-if="prop.property_category === 'newbuild' && prop.unit_count" class="text-[10px] ml-1" style="color:hsl(240 3.8% 46.1%)">· {{ prop.unit_count }} Einh.</span>
              </TableCell>
              <TableCell class="text-[13px]" style="color:hsl(240 3.8% 46.1%)">{{ prop.city }}</TableCell>
              <TableCell class="text-[13px]" style="color:hsl(240 3.8% 46.1%)">{{ getCategoryLabel(prop.property_category) }}</TableCell>
              <TableCell class="text-right text-[13px] font-semibold tabular-nums">{{ formatPrice(prop.purchase_price || prop.price, prop.property_category === 'newbuild') }}</TableCell>
              <TableCell class="text-[13px] tabular-nums" style="color:hsl(240 3.8% 46.1%)">{{ prop.size_m2 ? prop.size_m2 + ' m²' : '–' }}</TableCell>
              <TableCell>
                <div class="flex gap-0.5 items-center">
                  <template v-for="(icon, i) in getPortalIcons(prop).slice(0, 3)" :key="i">
                    <div class="w-5 h-5 rounded flex items-center justify-center text-[8px] font-bold text-white" :style="'background:' + icon.color" :title="icon.label">{{ icon.key }}</div>
                  </template>
                  <span v-if="getPortalIcons(prop).length > 3" class="text-[10px] ml-0.5" style="color:hsl(240 3.8% 46.1%)">+{{ getPortalIcons(prop).length - 3 }}</span>
                </div>
              </TableCell>
              <TableCell class="text-right">
                <button
                  @click="requestDeleteProperty(prop, $event)"
                  class="inline-flex items-center justify-center w-8 h-8 rounded-md text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors"
                  title="Objekt löschen"
                >
                  <Trash2 class="w-4 h-4" />
                </button>
              </TableCell>
            </TableRow>
          </template>
        </TableBody>
      </Table>
      <div class="px-4 py-2 text-[11px]" style="color:hsl(240 3.8% 46.1%);border-top:1px solid hsl(240 5.9% 90%)">{{ allFilteredProperties.length }} Objekte</div>
    </div>

    <!-- CARD VIEW (Desktop) -->
    <div v-else-if="propViewMode === 'cards'" class="hidden sm:block mt-3">
      <div class="grid grid-cols-3 gap-3">
        <div v-for="prop in filteredProperties" :key="prop.id"
          class="rounded-lg overflow-hidden cursor-pointer transition-shadow hover:shadow-md"
          style="border:1px solid hsl(240 5.9% 90%)"
          :style="prop.realty_status === 'inaktiv' ? 'opacity:0.45' : ''"
          @click="openDetail(prop)">
          <div class="w-full h-[120px] relative flex items-center justify-center overflow-hidden" style="background:hsl(240 4.8% 95.9%)">
            <img v-if="prop.thumbnail_url" :src="prop.thumbnail_url" class="w-full h-full object-cover" loading="lazy" />
            <span v-else style="font-size:22px;color:hsl(240 3.8% 46.1%)">⌂</span>
            <span class="absolute top-2 left-2 text-[10px] font-medium px-1.5 py-0.5 rounded" style="background:rgba(255,255,255,0.92);backdrop-filter:blur(4px)">{{ getCategoryLabel(prop.property_category) }}</span>
            <button
              @click="requestDeleteProperty(prop, $event)"
              class="absolute top-2 right-2 inline-flex items-center justify-center w-8 h-8 rounded-md bg-white/90 text-red-600 hover:bg-red-50 hover:text-red-700 shadow-sm transition-colors"
              title="Objekt löschen"
            >
              <Trash2 class="w-4 h-4" />
            </button>
            <div class="absolute bottom-2 right-2 flex gap-0.5">
              <template v-for="(icon, i) in getPortalIcons(prop).slice(0, 3)" :key="i">
                <div class="w-[18px] h-[18px] rounded flex items-center justify-center text-[7px] font-bold text-white" :style="'background:' + icon.color">{{ icon.key }}</div>
              </template>
            </div>
          </div>
          <div class="p-3">
            <div class="text-[13px] font-semibold">{{ prop.project_name || prop.address }}</div>
            <div class="text-[11px] mt-0.5" style="color:hsl(240 3.8% 46.1%)">
              {{ prop.city }}
              <template v-if="prop.property_category === 'newbuild' && prop.unit_count"> · {{ prop.unit_count }} Einheiten</template>
              <template v-else-if="prop.size_m2"> · {{ prop.size_m2 }} m²</template>
            </div>
            <div class="mt-2.5 text-sm font-bold tabular-nums">{{ formatPrice(prop.purchase_price || prop.price, prop.property_category === 'newbuild') }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- MOBILE LIST -->
    <div class="sm:hidden mt-3 rounded-lg overflow-hidden" style="border:1px solid hsl(240 5.9% 90%)">
      <template v-for="prop in filteredProperties" :key="prop.id">
        <div class="flex gap-3 items-center px-4 py-3 cursor-pointer" style="border-bottom:1px solid hsl(240 5.9% 90%)"
          :style="prop.realty_status === 'inaktiv' ? 'opacity:0.45' : ''"
          @click="openDetail(prop)">
          <div class="w-14 h-[42px] rounded-md overflow-hidden flex-shrink-0 flex items-center justify-center" style="background:hsl(240 4.8% 95.9%)">
            <img v-if="prop.thumbnail_url" :src="prop.thumbnail_url" class="w-full h-full object-cover" loading="lazy" />
            <span v-else style="font-size:14px;color:hsl(240 3.8% 46.1%)">⌂</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-[13px] font-semibold truncate">{{ prop.project_name || prop.address }}</div>
            <div class="text-[11px] truncate" style="color:hsl(240 3.8% 46.1%)">{{ prop.city }} · {{ getCategoryLabel(prop.property_category) }}<template v-if="prop.size_m2"> · {{ prop.size_m2 }} m²</template></div>
            <div class="flex gap-0.5 mt-1">
              <template v-for="(icon, i) in getPortalIcons(prop).slice(0, 3)" :key="i">
                <div class="w-4 h-4 rounded flex items-center justify-center text-[6px] font-bold text-white" :style="'background:' + icon.color">{{ icon.key }}</div>
              </template>
            </div>
          </div>
          <div class="text-right flex-shrink-0">
            <div class="text-[13px] font-bold tabular-nums">{{ formatPriceMobile(prop.purchase_price || prop.price, prop.property_category === 'newbuild') }}</div>
            <button
              @click="requestDeleteProperty(prop, $event)"
              class="mt-1 inline-flex items-center justify-center w-7 h-7 rounded-md text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors"
              title="Objekt löschen"
            >
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </template>
    </div>
    
        <!-- Knowledge Base Sidebar -->
        <div v-if="kbOpen" class="fixed inset-0 z-[300] flex items-start justify-center pt-8 overflow-y-auto" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
            <div @click="kbOpen = false" class="absolute inset-0"></div>
            <div class="relative w-full max-w-2xl bg-white shadow-2xl flex flex-col mx-2 sm:mx-4 mb-8 rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.6);max-height:90vh">
                <div class="px-3 sm:px-5 py-3 sm:py-4 flex items-center justify-between flex-shrink-0 border-b border-[#e4e4e7]">
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button @click="kbOpen = false" class="w-8 h-8 rounded-xl flex items-center justify-center bg-zinc-100 hover:bg-zinc-200 transition-all duration-200 active:scale-[0.97]"><ArrowLeft class="w-4 h-4 text-zinc-600" /></button>
                        <div>
                            <h3 class="text-sm font-semibold">Wissensbasis</h3>
                            <p class="text-xs text-[#71717a]">{{ kbPropertyLabel }}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button @click="kbShowFeedModal = true" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]"><Plus class="w-3 h-3" /> Hinzufugen</button>
                        <button @click="kbOpen = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                    </div>
                </div>

                <!-- Search/filter -->
                <div class="px-5 py-3 flex gap-2 border-b border-[#e4e4e7]">
                    <input v-model="kbSearch" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all flex-1" placeholder="Suchen..." />
                    <select v-model="kbFilterCategory" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" style="width:auto">
                        <option value="">Alle Kategorien</option>
                        <option v-for="(label, key) in kbCategoryLabels" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="flex-1 overflow-y-auto p-5 space-y-4">
                    <div v-if="kbLoading" class="text-center py-8"><span class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></span></div>
                    <div v-else-if="!kbGroupedCategories.length" class="text-center py-8 text-[#71717a] text-sm">Keine Wissenseinträge</div>
                    <div v-for="group in kbGroupedCategories" :key="group.key">
                        <h4 class="text-xs font-semibold uppercase tracking-wider mb-2 text-[#71717a]">{{ group.label }} ({{ group.items.length }})</h4>
                        <div class="space-y-2">
                            <div v-for="item in group.items" :key="item.id" class="p-3 rounded-lg border border-[#e4e4e7] bg-[white]">
                                <template v-if="kbEditingId === item.id">
                                    <input v-model="kbEditTitle" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all mb-2 text-xs" />
                                    <textarea v-model="kbEditContent" class="form-textarea text-xs" rows="3"></textarea>
                                    <div class="flex gap-2 mt-2">
                                        <button @click="kbSaveEdit(item)" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]">Speichern</button>
                                        <button @click="kbEditingId = null" class="inline-flex items-center gap-2 px-3 py-2 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200">Abbrechen</button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-1.5 mb-1">
                                                <span v-if="item.is_verified" class="text-[10px] px-1 rounded" style="background:rgba(16,185,129,0.12);color:#059669">Verifiziert</span>
                                                <span class="text-[10px] px-1 rounded bg-[#f4f4f5] text-[#71717a]">{{ item.confidence }}</span>
                                            </div>
                                            <p class="text-xs font-semibold">{{ item.title }}</p>
                                            <p class="text-[11px] text-[#71717a] mt-0.5">{{ item.content }}</p>
                                        </div>
                                        <div class="flex gap-1 flex-shrink-0">
                                            <button @click="kbToggleVerify(item)" class="btn btn-ghost btn-icon btn-sm"><Check class="w-3 h-3" /></button>
                                            <button @click="kbStartEdit(item)" class="btn btn-ghost btn-icon btn-sm"><Pencil class="w-3 h-3" /></button>
                                            <button @click="kbDeactivate(item)" class="btn btn-ghost btn-icon btn-sm" style="color:#ef4444"><Trash2 class="w-3 h-3" /></button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activities Panel -->
        <div v-if="actOpen" class="fixed inset-0 z-[300] flex items-start justify-center bg-black/50 pt-4 sm:pt-8 overflow-y-auto">
            <div class="bg-[white] rounded-xl shadow-lg w-full max-w-4xl mx-2 sm:mx-4 mb-8">
                <div class="px-3 sm:px-6 py-3 sm:py-4 flex items-center justify-between border-b border-[#e4e4e7] sticky top-0 bg-[white] rounded-t-xl z-10">
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button @click="actOpen = false" class="w-8 h-8 rounded-xl flex items-center justify-center bg-zinc-100 hover:bg-zinc-200 transition-all duration-200 active:scale-[0.97]"><ArrowLeft class="w-4 h-4 text-zinc-600" /></button>
                        <div>
                            <h3 class="text-sm font-semibold">Aktivitaeten — {{ actPropertyLabel }}</h3>
                            <p class="text-[10px] text-[#71717a]">{{ filteredActivities.length }} Eintraege</p>
                        </div>
                    </div>
                    <button @click="actOpen = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                </div>
                
                <!-- Filters -->
                <div class="px-6 py-3 border-b border-[#e4e4e7] flex gap-2 flex-wrap">
                    <div class="relative flex-1 min-w-[180px]">
                        <Search class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-[#71717a]" />
                        <input v-model="actSearch" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all pl-9 text-xs" style="height:32px" placeholder="Suchen..." />
                    </div>
                    <select v-model="actCategory" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="width:auto;height:32px">
                        <option value="">Alle Kategorien</option>
                        <option v-for="c in actCategories" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
                
                <!-- Quick Add -->
                <div class="px-6 py-3 border-b border-[#e4e4e7]">
                    <div class="flex items-center gap-2 cursor-pointer" @click="pqaOpen = !pqaOpen">
                        <Plus class="w-3.5 h-3.5 text-green-600" />
                        <span class="text-xs font-medium text-green-700">Aktivität eintragen</span>
                        <span class="text-[10px] text-[#71717a]">(KI formuliert)</span>
                    </div>
                    <div v-if="pqaOpen" class="mt-3 space-y-2">
                        <textarea v-model="pqaActivity" rows="2" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs w-full" placeholder="Stichworte reinschreiben... z.B. tel maier, interesse an whg, will freitag besichtigen"></textarea>
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model="pqaStakeholder" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:30px" placeholder="Stakeholder" />
                            <select v-model="pqaCategory" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:30px">
                                <option value="besichtigung">Besichtigung</option>
                                <option value="email-out">Email gesendet</option>
                                <option value="email-in">Email erhalten</option>
                                <option value="expose">Exposé</option>
                                <option value="kaufanbot">Kaufanbot</option>
                                <option value="absage">Absage</option>
                                <option value="eigentuemer">Eigentümer</option>
                                <option value="update">Update</option>
                                <option value="sonstiges" selected>Sonstiges</option>
                            </select>
                            <input v-model="pqaDuration" type="number" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:30px" placeholder="Dauer (Min)" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="pqaDate" type="date" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:30px" />
                            <input v-model="pqaTime" type="time" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:30px" />
                        </div>
                        <div class="flex justify-end">
                            <button @click="pqaSubmit()" :disabled="pqaSaving || !pqaActivity" class="btn btn-sm flex items-center gap-1.5" :class="pqaSaving ? 'opacity-50' : ''">
                                <Sparkles v-if="!pqaSaving" class="w-3 h-3" />
                                <span v-if="pqaSaving" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:12px;height:12px"></span>
                                {{ pqaSaving ? 'KI formuliert...' : 'Eintragen' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="actLoading" class="p-8 text-center"><span class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></span></div>
                <div v-else class="divide-y divide-[#e4e4e7] max-h-[60vh] overflow-y-auto">
                    <div v-for="item in filteredActivities" :key="item.id" class="px-6 py-3 hover:bg-[#f4f4f5] transition-colors">
                        <!-- View mode -->
                        <div v-if="actEditingId !== item.id" class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-0.5">
                                    <span class="text-xs font-medium">{{ item.stakeholder }}</span>
                                    <span class="badge text-[9px]" :style="catBadgeStyle(item.category)">{{ catLabel(item.category) }}</span>
                                    <span class="text-[10px] text-[#71717a]">{{ item.activity_date }}</span>
                                </div>
                                <p class="text-xs text-[#18181b]">{{ item.activity }}</p>
                                <p v-if="item.result" class="text-[11px] text-[#71717a] mt-0.5">{{ item.result }}</p>
                            </div>
                            <div class="flex gap-1 flex-shrink-0">
                                <button @click="actStartEdit(item)" class="btn btn-ghost btn-icon btn-sm" style="height:24px;width:24px"><Pencil class="w-3 h-3" /></button>
                                <button @click="actDelete(item)" class="btn btn-ghost btn-icon btn-sm" style="height:24px;width:24px;color:#ef4444"><Trash2 class="w-3 h-3" /></button>
                            </div>
                        </div>
                        <!-- Edit mode -->
                        <div v-else class="space-y-2">
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <input v-model="actEditData.stakeholder" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:28px" placeholder="Stakeholder" />
                                <select v-model="actEditData.category" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:28px">
                                    <option v-for="c in actCategories" :key="c" :value="c">{{ c }}</option>
                                </select>
                                <input v-model="actEditData.activity_date" type="date" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:28px" />
                            </div>
                            <input v-model="actEditData.activity" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-xs" style="height:28px" placeholder="Aktivität" />
                            <textarea v-model="actEditData.result" class="form-textarea text-xs" rows="2" placeholder="Ergebnis / KI-Zusammenfassung"></textarea>
                            <div class="flex gap-2">
                                <button @click="actSaveEdit(item)" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]" style="height:26px"><Save class="w-3 h-3" /> Speichern</button>
                                <button @click="actEditingId = null" class="inline-flex items-center gap-2 px-3 py-2 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200" style="height:26px">Abbrechen</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="!filteredActivities.length" class="p-8 text-center text-xs text-[#71717a]">Keine Aktivitäten gefunden</div>
                </div>
            </div>
        </div>

        <!-- Add Knowledge Modal -->
        <div v-if="kbShowFeedModal" class="fixed inset-0 z-[300] flex items-center justify-center bg-black/50 overflow-y-auto py-8">
            <div class="bg-[white] rounded-xl shadow-lg w-full mx-4" :class="kbBulkPreview.length ? 'max-w-2xl' : 'max-w-md'">
                <div class="px-5 py-4 flex items-center justify-between border-b border-[#e4e4e7]">
                    <h3 class="text-sm font-semibold">Wissen hinzufügen</h3>
                    <button @click="kbShowFeedModal = false; kbBulkPreview = []; kbFeedText = ''; kbFileName = ''; kbFeedPreview = null" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                </div>
                <div class="p-5">

                    <!-- STEP 1: Input (text or file) — only show if no bulk preview yet -->
                    <template v-if="!kbBulkPreview.length">
                        <!-- Tab toggle -->
                        <div class="flex gap-1 mb-3">
                            <button @click="kbFeedMode = 'text'" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-zinc-700 bg-white border border-zinc-200 rounded-xl hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" :class="kbFeedMode === 'text' ? 'btn-primary' : 'btn-ghost'">Text eingeben</button>
                            <button @click="kbFeedMode = 'file'" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-zinc-700 bg-white border border-zinc-200 rounded-xl hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" :class="kbFeedMode === 'file' ? 'btn-primary' : 'btn-ghost'"><Upload class="w-3 h-3" /> Datei auslesen</button>
                        </div>
                        
                        <!-- File upload area -->
                        <div v-if="kbFeedMode === 'file'" class="mb-3">
                            <label class="flex flex-col items-center justify-center w-full h-24 rounded-lg border-2 border-dashed border-[#e4e4e7] cursor-pointer hover:border-[#a1a1aa] transition-colors">
                                <div v-if="kbFileUploading" class="flex items-center gap-2 text-xs text-[#71717a]">
                                    <span class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:14px;height:14px"></span>
                                    <span>{{ kbFileName }} — KI analysiert...</span>
                                </div>
                                <div v-else class="text-center text-xs text-[#71717a]">
                                    <Upload class="w-5 h-5 mx-auto mb-1 opacity-50" />
                                    <p>PDF, TXT, DOC, DOCX, CSV, JSON, XML</p>
                                    <p class="text-[10px] opacity-60 mt-0.5">KI liest aus & kategorisiert automatisch</p>
                                </div>
                                <input type="file" @change="kbHandleFile" accept=".pdf,.txt,.csv,.json,.xml,.html,.md,.doc,.docx,.xls,.xlsx,.log" class="hidden" />
                            </label>
                        </div>
                        
                        <!-- Text input (text mode) -->
                        <template v-if="kbFeedMode === 'text'">
                            <textarea v-model="kbFeedText" rows="4" class="form-textarea text-xs mb-3" placeholder="z.B. 'Die Küche wurde 2023 komplett erneuert, Fußbodenheizung im gesamten EG'..."></textarea>
                            
                            <!-- Single entry preview -->
                            <div v-if="kbFeedPreview" class="p-3 rounded-lg bg-[#f4f4f5] mb-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wider mb-1 text-[#71717a]">KI-Vorschlag:</p>
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded" style="background:rgba(59,130,246,0.12);color:#2563eb">{{ kbFeedPreview.category }}</span>
                                <p class="text-xs font-semibold mt-1">{{ kbFeedPreview.title }}</p>
                                <p class="text-[11px] text-[#71717a]">{{ kbFeedPreview.content }}</p>
                            </div>
                            
                            <div class="flex gap-2">
                                <button v-if="!kbFeedPreview" @click="kbCategorize()" :disabled="!kbFeedText.trim() || kbFeedLoading" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]">
                                    <span v-if="kbFeedLoading" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:12px;height:12px"></span>
                                    <Sparkles v-else class="w-3 h-3" />
                                    <span>KI analysieren</span>
                                </button>
                                <template v-if="kbFeedPreview">
                                    <button @click="kbSaveFeedPreview()" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]">Speichern</button>
                                    <button @click="kbFeedPreview = null" class="inline-flex items-center gap-2 px-3 py-2 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200">Anpassen</button>
                                </template>
                            </div>
                        </template>
                    </template>

                    <!-- STEP 2: Bulk preview — KI hat Einträge erkannt -->
                    <template v-if="kbBulkPreview.length">
                        <div v-if="kbFileName" class="text-[10px] text-[#71717a] mb-2">
                            Ausgelesen aus: <span class="font-medium text-[#18181b]">{{ kbFileName }}</span>
                        </div>
                        
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold">{{ kbBulkPreview.length }} Wissenseinträge erkannt</span>
                            <div class="flex gap-2">
                                <button @click="kbBulkPreview.forEach(e => e.selected = true)" class="text-[10px] text-[#18181b] hover:underline cursor-pointer">Alle</button>
                                <button @click="kbBulkPreview.forEach(e => e.selected = false)" class="text-[10px] text-[#71717a] hover:underline cursor-pointer">Keine</button>
                            </div>
                        </div>
                        
                        <div class="space-y-2 max-h-[50vh] overflow-y-auto mb-4 pr-1">
                            <div v-for="(entry, idx) in kbBulkPreview" :key="idx" 
                                class="p-3 rounded-lg border text-xs cursor-pointer transition-all"
                                :class="entry.selected ? 'border-[#18181b] bg-[var(--brand-light)]' : 'border-[#e4e4e7] bg-[#f4f4f5] opacity-50'"
                                @click="entry.selected = !entry.selected">
                                <div class="flex items-start gap-2.5">
                                    <input type="checkbox" :checked="entry.selected" class="mt-0.5 flex-shrink-0 accent-[#18181b]" @click.stop="entry.selected = !entry.selected" />
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5 mb-1">
                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded flex-shrink-0" style="background:rgba(59,130,246,0.12);color:#2563eb">{{ entry.category }}</span>
                                            <span class="font-semibold">{{ entry.title }}</span>
                                        </div>
                                        <p class="text-[11px] text-[#71717a] leading-relaxed">{{ entry.content }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex gap-2">
                            <button @click="kbSaveBulk()" :disabled="kbBulkSaving || !kbBulkPreview.some(e => e.selected)" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]">
                                <span v-if="kbBulkSaving" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:12px;height:12px"></span>
                                <Check v-else class="w-3 h-3" />
                                <span>{{ kbBulkPreview.filter(e => e.selected).length }} Einträge speichern</span>
                            </button>
                            <button @click="kbBulkPreview = []; kbFeedText = ''; kbFileName = ''" class="inline-flex items-center gap-2 px-3 py-2 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200">Zurück</button>
                        </div>
                    </template>

                </div>
            </div>
        </div>
    </div>
    <!-- Global Documents Dialog -->
    <Dialog :open="showGlobalFiles" @update:open="v => showGlobalFiles = v">
      <DialogContent class="max-w-lg">
        <DialogHeader>
          <DialogTitle>Allgemeine Dokumente</DialogTitle>
        </DialogHeader>
        <p class="text-xs" style="color:hsl(240 3.8% 46.1%)">Dokumente die bei jedem Objekt als Anhang verwendbar sind.</p>
        <div class="mt-3">
          <label class="flex items-center justify-center gap-2 py-6 rounded-lg cursor-pointer text-xs border-2 border-dashed transition-colors hover:bg-gray-50" style="border-color:hsl(240 5.9% 90%);color:hsl(240 3.8% 46.1%)">
            <Upload class="w-4 h-4" />
            {{ globalFileUploading ? 'Wird hochgeladen...' : 'Datei hochladen' }}
            <input type="file" class="hidden" @change="uploadGlobalFile" :disabled="globalFileUploading" />
          </label>
        </div>
        <div v-if="globalFiles.length" class="mt-3 space-y-1">
          <div v-for="f in globalFiles" :key="f.id" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50">
            <FileText class="w-4 h-4 flex-shrink-0" style="color:hsl(240 3.8% 46.1%)" />
            <div class="flex-1 min-w-0">
              <a :href="'/storage/' + f.path" target="_blank" class="text-xs font-medium hover:underline truncate block">{{ f.label || f.original_name }}</a>
              <div class="text-[10px]" style="color:hsl(240 3.8% 46.1%)">{{ formatFileSize(f.file_size) }}</div>
            </div>
            <button @click="deleteGlobalFile(f)" class="text-xs p-1 rounded hover:bg-red-50" style="color:hsl(0 72% 51%)">
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
        <div v-else class="mt-3 py-6 text-center text-xs" style="color:hsl(240 3.8% 46.1%)">Noch keine Dokumente hochgeladen.</div>
      </DialogContent>
    </Dialog>


        <!-- Property Files Sidebar -->
        <div v-if="propFilesOpen" class="fixed inset-0 z-[300] flex items-start justify-center pt-4 sm:pt-8 overflow-y-auto" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
            <div @click="propFilesOpen = false" class="absolute inset-0"></div>
            <div class="relative w-full max-w-lg bg-white shadow-2xl flex flex-col mx-2 sm:mx-4 mb-8 rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.6);max-height:90vh">
                <div class="px-3 sm:px-5 py-3 sm:py-4 flex items-center justify-between flex-shrink-0 border-b border-[#e4e4e7]">
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button @click="propFilesOpen = false" class="w-8 h-8 rounded-xl flex items-center justify-center bg-zinc-100 hover:bg-zinc-200 transition-all duration-200 active:scale-[0.97]"><ArrowLeft class="w-4 h-4 text-zinc-600" /></button>
                        <div>
                            <h3 class="text-sm font-semibold">Dateien & Dokumente</h3>
                            <p class="text-xs text-[#71717a]">{{ propFilesLabel }}</p>
                        </div>
                    </div>
                    <button @click="propFilesOpen = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                </div>
                <div v-if="propFilesLoading" class="flex-1 flex items-center justify-center"><span class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></span></div>
                <div v-else class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    <p class="text-xs text-[#71717a]">Diese Dateien stehen beim E-Mail-Versand als Anhänge zur Auswahl.</p>

                    <!-- Existing files list -->
                    <div v-for="f in propFilesList" :key="f.id" class="flex items-center gap-2 p-3 rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]">
                        <FileText class="w-5 h-5 text-[#18181b] flex-shrink-0" />
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-semibold">{{ f.label }}</div>
                            <div class="text-[10px] text-[#71717a] truncate">{{ f.filename }} <span v-if="f.file_size">· {{ formatFileSize(f.file_size) }}</span></div>
                            <a :href="f.url" target="_blank" class="text-[10px] text-[#18181b] hover:underline">Anzeigen</a>
                        </div>
                        <button v-if="f.source === 'property_files'" @click="toggleWebsiteDownload(f)"
                            :title="f.is_website_download ? 'Download auf Website aktiv' : 'Auf Website zum Download freigeben'"
                            class="p-1.5 rounded-lg transition-all duration-200 flex-shrink-0"
                            :class="f.is_website_download ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'text-zinc-300 hover:text-zinc-500 hover:bg-zinc-100'">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                        </button>
                        <button @click="deletePropFile(f.id)" class="btn btn-ghost btn-icon btn-sm text-red-500 hover:text-red-700"><Trash2 class="w-3.5 h-3.5" /></button>
                    </div>

                    <div v-if="!propFilesList.length" class="text-xs text-[#71717a] text-center py-4">Noch keine Dateien hochgeladen</div>

                    <!-- Upload new file -->
                    <div class="border-t border-[#e4e4e7] pt-4">
                        <h4 class="text-xs font-semibold mb-2">Neue Datei hochladen</h4>
                        <div class="flex gap-2 mb-2">
                            <input v-model="propFilesNewLabel" list="file-label-suggestions" class="input text-xs flex-1" style="height:32px" placeholder="Bezeichnung eingeben..." />
                            <datalist id="file-label-suggestions">
                                <option value="Exposé" />
                                <option value="BaB" />
                                <option value="Nebenkostenübersicht" />
                                <option value="Grundriss" />
                                <option value="Kaufvertragsentwurf" />
                                <option value="Energieausweis" />
                                <option value="Fotos" />
                                <option value="Dokument" />
                            </datalist>
                        </div>
                        <label class="flex items-center gap-2 w-full h-14 rounded-lg border-2 border-dashed border-[#e4e4e7] cursor-pointer hover:border-[#a1a1aa] transition-colors px-3">
                            <Upload class="w-5 h-5 text-[#71717a]" />
                            <span class="text-xs text-[#71717a]">
                                <span v-if="propFilesUploading" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:12px;height:12px"></span>
                                <template v-else>PDF, DOC, XLS oder Bild hochladen</template>
                            </span>
                            <input type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" @change="uploadPropFile($event)" class="hidden" />
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Property Settings Sidebar -->
        <div v-if="propSettingsOpen" class="fixed inset-0 z-[350] flex items-start justify-center pt-4 sm:pt-8 overflow-y-auto" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
            <div @click="propSettingsOpen = false" class="absolute inset-0"></div>
            <div class="relative w-full max-w-2xl bg-white shadow-2xl flex flex-col mx-2 sm:mx-4 mb-8 rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.6);max-height:90vh">
                <!-- Header -->
                <div class="px-3 sm:px-5 py-2.5 sm:py-3 flex items-center justify-between flex-shrink-0 border-b border-[#e4e4e7]" style="background:linear-gradient(135deg,rgba(238,118,6,0.08),rgba(139,92,246,0.03))">
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button @click="propSettingsOpen = false" class="w-8 h-8 rounded-xl flex items-center justify-center bg-white/80 hover:bg-white transition-all duration-200 active:scale-[0.97]"><ArrowLeft class="w-4 h-4 text-zinc-600" /></button>
                        <div>
                            <h3 class="text-sm font-bold">Einheiten & Extras</h3>
                            <p class="text-[11px] text-[#71717a]">{{ propSettingsData.ref_id }} — {{ propSettingsData.address }}</p>
                        </div>
                    </div>
                    <button @click="propSettingsOpen = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#f4f4f5]"><X class="w-4 h-4" /></button>
                </div>

                <!-- Tabs -->
                <div class="flex gap-0.5 px-3 py-2 overflow-x-auto border-b border-[#e4e4e7]" style="background:#f4f4f5;-ms-overflow-style:none;scrollbar-width:none;min-height:40px">
                    <button v-for="tab in propSettingsTabs" :key="tab.key" @click="propSettingsTab = tab.key"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-medium whitespace-nowrap transition-colors flex-shrink-0"
                        :style="propSettingsTab === tab.key ? 'background:white;color:#18181b;box-shadow:0 1px 3px rgba(0,0,0,0.1)' : 'color:#71717a'">
                        {{ tab.label }}
                    </button>
                </div>

                <div v-if="propSettingsLoading" class="flex-1 flex items-center justify-center"><span class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></span></div>
                <div v-else class="flex-1 overflow-y-auto">

                    <!-- Removed: Dynamic field tabs, Eigentuemer, Portalzugang — now in PropertyDetailView -->

                    <!-- EINHEITEN TAB (Newbuild) -->
                    <div v-if="propSettingsTab === 'einheiten'" class="px-4 py-4 space-y-3">
                        <!-- Summary cards -->
                        <div v-if="propSettingsUnitSummary" class="grid grid-cols-4 gap-2">
                            <div class="text-center p-2 rounded-lg" style="background:#f4f4f5">
                                <div class="text-lg font-bold">{{ propSettingsUnitSummary.total }}</div>
                                <div class="text-[10px] text-[#71717a]">Gesamt</div>
                            </div>
                            <div class="text-center p-2 rounded-lg" style="background:rgba(16,185,129,0.08)">
                                <div class="text-lg font-bold" style="color:#10b981">{{ propSettingsUnitSummary.frei }}</div>
                                <div class="text-[10px] text-[#71717a]">Frei</div>
                            </div>
                            <div class="text-center p-2 rounded-lg" style="background:rgba(245,158,11,0.08)">
                                <div class="text-lg font-bold" style="color:#f59e0b">{{ propSettingsUnitSummary.reserviert }}</div>
                                <div class="text-[10px] text-[#71717a]">Reserviert</div>
                            </div>
                            <div class="text-center p-2 rounded-lg" style="background:rgba(239,68,68,0.08)">
                                <div class="text-lg font-bold" style="color:#ef4444">{{ propSettingsUnitSummary.verkauft }}</div>
                                <div class="text-[10px] text-[#71717a]">Verkauft</div>
                            </div>
                        </div>

                        <!-- Verkaufsfortschritt -->
                        <div v-if="propSettingsUnitSummary && propSettingsUnitSummary.total > 0" class="p-3 rounded-xl" style="background:#f4f4f5">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[11px] font-semibold">Verkaufsfortschritt</span>
                                <span class="text-[11px] font-bold" style="color:#ee7606">{{ propSettingsUnits.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) > 0 ? Math.round((propSettingsUnits.filter(u => u.status === 'verkauft').reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) / propSettingsUnits.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0)) * 100) : Math.round((propSettingsUnitSummary.verkauft / propSettingsUnitSummary.total) * 100) }}% verkauft</span>
                            </div>
                            <div class="w-full h-2.5 rounded-full overflow-hidden" style="background:#e4e4e7">
                                <div class="h-full rounded-full transition-all" :style="'width:' + (propSettingsUnits.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) > 0 ? (propSettingsUnits.filter(u => u.status === 'verkauft').reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) / propSettingsUnits.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) * 100) : (propSettingsUnitSummary.verkauft / propSettingsUnitSummary.total * 100)) + '%;background:linear-gradient(90deg,#10b981,#ee7606)'"></div>
                            </div>
                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-[10px] text-[#71717a]">{{ propSettingsUnitSummary.verkauft }} von {{ propSettingsUnitSummary.total }} Einheiten</span>
                                <span class="text-[10px] text-[#71717a]" v-if="propSettingsUnits.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) > 0">{{ propSettingsUnits.filter(u => u.status === 'verkauft').reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0).toFixed(0) }} / {{ propSettingsUnits.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0).toFixed(0) }} m²</span>
                                
                            </div>
                        </div>


                        <!-- Units list by floor -->
                        <div v-for="(units, floor) in groupedUnitsByFloor" :key="floor" class="space-y-1">
                            <div class="text-[11px] font-semibold text-[#71717a] px-1 pt-2">{{ FLOOR_NAMES[floor] || ('Stockwerk ' + floor) }}</div>
                            <div v-for="u in units" :key="u.id">
                                <div @click="editUnit(u)"
                                    class="flex items-center gap-2 px-3 py-2.5 rounded-xl hover:bg-[#f4f4f5] cursor-pointer transition-colors"
                                    :style="'border:1px solid #e4e4e7' + (propSettingsEditUnit && propSettingsEditUnit.id === u.id ? ';background:#f4f4f5' : '')">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium truncate">{{ u.unit_number }}</div>
                                        <div class="text-[10px] text-[#71717a]">{{ u.unit_type }} · {{ u.area_m2 }} m² · € {{ Number(u.total_price || u.price || 0).toLocaleString('de-DE') }}</div>
                                    </div>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                                        :style="u.status === 'frei' ? 'background:rgba(16,185,129,0.1);color:#10b981' : u.status === 'reserviert' ? 'background:rgba(245,158,11,0.1);color:#f59e0b' : 'background:rgba(239,68,68,0.1);color:#ef4444'">
                                        {{ u.status }}
                                    </span>
                                    <ChevronRight class="w-3.5 h-3.5 text-[#71717a]" :style="propSettingsEditUnit && propSettingsEditUnit.id === u.id ? 'transform:rotate(90deg)' : ''" />
                                </div>

                                <!-- Inline Edit Form -->
                                <div v-if="propSettingsEditUnit && propSettingsEditUnit.id === u.id" data-edit-form class="ml-3 mt-1 mb-2 p-3 rounded-xl space-y-3" style="border:1px solid #e4e4e7;background:white">
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">TOP / Nr.</label>
                                            <input v-model="propSettingsEditUnit.unit_number" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Typ</label>
                                            <input v-model="propSettingsEditUnit.unit_type" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Fläche m²</label>
                                            <input v-model="propSettingsEditUnit.area_m2" type="number" step="0.01" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Stockwerk</label>
                                            <input v-model="propSettingsEditUnit.floor" type="number" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Zimmer</label>
                                            <input v-model="propSettingsEditUnit.rooms_amount" type="number" step="0.5" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Preis €</label>
                                            <input v-model="propSettingsEditUnit.price" type="number" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Status</label>
                                            <select v-model="propSettingsEditUnit.status" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]">
                                                <option value="frei">Frei</option>
                                                <option value="reserviert">Reserviert</option>
                                                <option value="verkauft">Verkauft</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-[10px] text-[#71717a]">Käufer</label>
                                            <input v-model="propSettingsEditUnit.buyer_name" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" placeholder="Name des Käufers" />
                                        </div>

                                    </div>

                                    <!-- Parking assignment by category -->
                                    <div v-if="propSettingsParking.length" class="space-y-1">
                                        <label class="text-[10px] text-[#71717a] font-semibold">Stellplätze zuordnen</label>
                                        <div v-for="(items, category) in parkingByCategory" :key="'assign-'+category" class="space-y-0.5">
                                            <button @click="toggleParkingCategory('edit_'+category)"
                                                class="w-full flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-left text-[10px] font-semibold hover:bg-[#f4f4f5] transition-colors">
                                                <ChevronRight class="w-3 h-3 transition-transform" :style="openParkingCategories['edit_'+category] ? 'transform:rotate(90deg)' : ''" />
                                                <span>{{ category || 'Sonstige' }}</span>
                                                <span class="text-[9px] px-1.5 py-0.5 rounded-full" style="background:#f4f4f5">{{ items.length }}</span>
                                            </button>
                                            <div v-if="openParkingCategories['edit_'+category]" class="ml-4 flex flex-wrap gap-1">
                                                <button v-for="p in items" :key="p.id" @click="toggleParkingAssignment(p.id)"
                                                    class="text-xs px-3 py-2 rounded-xl border transition-all font-medium"
                                                    :style="(propSettingsEditUnit.assigned_parking_ids || []).includes(p.id) ? 'background:#18181b;color:white;border-color:#ee7606' : 'border-color:#e4e4e7'">
                                                    {{ p.unit_number }} (€{{ Number(p.price||0).toLocaleString('de-DE') }})
                                                </button>
                                            </div>
                                        </div>
                                        <div v-if="(propSettingsEditUnit.assigned_parking_ids || []).length" class="text-[10px] text-[#71717a] mt-1">
                                            Gesamtpreis: <strong style="color:#ee7606">€ {{ calcTotalPrice().toLocaleString('de-DE') }}</strong>
                                        </div>
                                    </div>

                                    <!-- Unit Images -->
                                    <div class="space-y-1">
                                        <label class="text-[10px] text-[#71717a] font-semibold">Bilder</label>
                                        <div v-if="propSettingsEditUnit.images && propSettingsEditUnit.images.length" class="flex flex-wrap gap-2">
                                            <div v-for="(img, imgIdx) in propSettingsEditUnit.images" :key="imgIdx" class="relative group">
                                                <img :src="img" class="w-20 h-20 object-cover rounded-lg border border-[#e4e4e7]" />
                                                <button @click="deleteUnitImage(u.id, img)"
                                                    class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-sm"
                                                    title="Bild entfernen">&times;</button>
                                            </div>
                                        </div>
                                        <label class="flex items-center gap-2 px-3 py-2 rounded-xl border-2 border-dashed border-[#e4e4e7] hover:border-[#ee7606] hover:bg-[#f4f4f5] cursor-pointer transition-all" :class="uploadingImageUnitId === u.id ? 'opacity-50 pointer-events-none' : ''">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[#71717a]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                            <span class="text-xs font-medium text-[#71717a]">{{ uploadingImageUnitId === u.id ? 'Wird hochgeladen...' : 'Bild hochladen' }}</span>
                                            <input type="file" accept="image/*" @change="uploadUnitImage(u.id, $event)" class="hidden" :disabled="uploadingImageUnitId === u.id" />
                                        </label>
                                    </div>

                                    <!-- Kaufanbot PDF -->
                                    <div class="space-y-1">
                                        <label class="text-[10px] text-[#71717a] font-semibold">Kaufanbot PDF</label>
                                        <div v-if="u.kaufanbot_pdf" class="flex items-center gap-2">
                                            <a :href="'/storage/' + u.kaufanbot_pdf" target="_blank" class="text-[10px] underline" style="color:#ee7606">{{ u.kaufanbot_pdf }}</a>
                                            <button @click="removeKaufanbotPdf(u.id)" class="text-[10px] px-2 py-0.5 rounded text-red-500 hover:bg-red-50 transition-colors" title="Kaufanbot entfernen">Entfernen</button>
                                        </div>
                                        <label class="flex items-center gap-2 px-3 py-2 rounded-xl border-2 border-dashed border-[#e4e4e7] hover:border-[#ee7606] hover:bg-[#f4f4f5] cursor-pointer transition-all" :class="uploadingPdfUnitId === u.id ? 'opacity-50 pointer-events-none' : ''">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[#71717a]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                                            <span class="text-xs font-medium text-[#71717a]">{{ uploadingPdfUnitId === u.id ? 'Wird hochgeladen...' : 'PDF hochladen' }}</span>
                                            <input type="file" accept=".pdf" @change="uploadKaufanbotPdf(u.id, $event)" class="hidden" :disabled="uploadingPdfUnitId === u.id" />
                                        </label>
                                    </div>

                                    <!-- Action buttons -->
                                    <div class="flex items-center gap-2 pt-1">
                                        <button @click="saveUnit(propSettingsEditUnit)" class="px-3 py-1.5 text-[11px] font-medium rounded-lg text-white" style="background:#ee7606">Speichern</button>
                                        <button @click="splitUnit(u.id)" class="px-3 py-1.5 text-[11px] font-medium rounded-lg border border-[#e4e4e7] hover:bg-[#f4f4f5]" :disabled="splitLoading">Aufteilen</button>
                                        <button @click="deleteUnit(u.id)" class="px-3 py-1.5 text-[11px] font-medium rounded-lg text-red-600 border border-red-200 hover:bg-red-50">Löschen</button>
                                        <button @click="propSettingsEditUnit = null" class="px-3 py-1.5 text-[11px] font-medium rounded-lg border border-[#e4e4e7] hover:bg-[#f4f4f5] ml-auto">Abbrechen</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- New Unit Form -->
                        <div v-if="propSettingsNewUnit" class="p-3 rounded-xl space-y-3" style="border:2px dashed #e4e4e7;background:white">
                            <div class="text-[11px] font-semibold">Neue Einheit</div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-[#71717a]">TOP / Nr.</label>
                                    <input v-model="propSettingsNewUnit.unit_number" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Typ</label>
                                    <input v-model="propSettingsNewUnit.unit_type" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Fläche m²</label>
                                    <input v-model="propSettingsNewUnit.area_m2" type="number" step="0.01" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Stockwerk</label>
                                    <input v-model="propSettingsNewUnit.floor" type="number" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Zimmer</label>
                                    <input v-model="propSettingsNewUnit.rooms_amount" type="number" step="0.5" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Preis €</label>
                                    <input v-model="propSettingsNewUnit.price" type="number" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Status</label>
                                    <select v-model="propSettingsNewUnit.status" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]">
                                        <option value="frei">Frei</option>
                                        <option value="reserviert">Reserviert</option>
                                        <option value="verkauft">Verkauft</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] text-[#71717a]">Käufer</label>
                                    <input v-model="propSettingsNewUnit.buyer_name" class="w-full px-2 py-1.5 text-xs rounded-lg border border-[#e4e4e7] bg-[#f4f4f5]" placeholder="Name des Käufers" />
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="saveNewUnit()" class="px-3 py-1.5 text-[11px] font-medium rounded-lg text-white" style="background:#ee7606">Speichern</button>
                                <button @click="propSettingsNewUnit = null" class="px-3 py-1.5 text-[11px] font-medium rounded-lg border border-[#e4e4e7] hover:bg-[#f4f4f5]">Abbrechen</button>
                            </div>
                        </div>

                        <!-- Add unit button -->
                        <button v-if="!propSettingsNewUnit" @click="propSettingsNewUnit = { unit_number: '', unit_type: 'Wohnung', area_m2: '', floor: 0, rooms: '', price: '', status: 'frei', buyer_name: '' }"
                            class="w-full py-2.5 text-[11px] font-medium rounded-xl border-2 border-dashed border-[#e4e4e7] hover:bg-[#f4f4f5] transition-colors">
                            + Einheit hinzufügen
                        </button>
                    </div>


                    <!-- KAUFANBOTE TAB -->
                    <div v-if="propSettingsTab === 'kaufanbote'" class="px-4 py-4 space-y-3">
                        <div v-if="!kaufanbotUnits.length" class="text-center py-8 text-sm text-[#71717a]">
                            Noch keine Kaufanbote hochgeladen. Kaufanbote können im Einheiten-Tab pro Einheit hochgeladen werden.
                        </div>
                        <div v-else class="space-y-2">
                            <div v-for="ku in kaufanbotUnits" :key="ku.id" class="p-3 rounded-xl" style="border:1px solid #e4e4e7">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">{{ ku.unit_number }}</span>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                                            :style="ku.status === 'verkauft' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : ku.status === 'reserviert' ? 'background:rgba(245,158,11,0.08);color:#f59e0b' : 'background:rgba(16,185,129,0.08);color:#10b981'">{{ ku.status }}</span>
                                    </div>
                                    <span class="text-sm font-bold" style="color:#ee7606">&euro; {{ Number(ku.total_price || ku.price || 0).toLocaleString('de-DE') }}</span>
                                </div>
                                <div class="flex items-center justify-between text-[10px] text-[#71717a]">
                                    <span>{{ ku.buyer_name || 'Kein Käufer eingetragen' }} · {{ ku.area_m2 }} m²</span>
                                </div>
                                <a :href="'/storage/' + ku.kaufanbot_pdf" target="_blank" class="inline-flex items-center gap-1.5 mt-2 text-[11px] font-medium px-3 py-1.5 rounded-lg transition-colors" style="color:#ee7606;background:rgba(238,118,6,0.06)">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    Kaufanbot PDF anzeigen
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- STELLPLÄTZE TAB -->
                    <!-- Stellplaetze tab removed - integrated into Einheiten -->


                    <!-- PROPERTY KAUFANBOTE TAB -->
                    <div v-if="propSettingsTab === 'prop_kaufanbote'" class="px-5 py-4 space-y-4">
                        <!-- Upload Form -->
                        <div class="p-4 rounded-xl space-y-3" style="border:1px solid #e4e4e7;background:white">
                            <div class="text-sm font-semibold mb-2">Neues Kaufanbot hochladen</div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[11px] font-medium text-[#71717a] mb-1 block">Käufer Name *</label>
                                    <input v-model="propKaufanbotForm.buyer_name" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all w-full" placeholder="Vor- und Nachname" />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium text-[#71717a] mb-1 block">E-Mail</label>
                                    <input v-model="propKaufanbotForm.buyer_email" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all w-full" placeholder="email@beispiel.at" />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium text-[#71717a] mb-1 block">Telefon</label>
                                    <input v-model="propKaufanbotForm.buyer_phone" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all w-full" placeholder="+43..." />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium text-[#71717a] mb-1 block">Betrag</label>
                                    <input v-model="propKaufanbotForm.amount" type="number" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all w-full" placeholder="z.B. 350000" />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium text-[#71717a] mb-1 block">Datum</label>
                                    <input v-model="propKaufanbotForm.kaufanbot_date" type="date" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all w-full" />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium text-[#71717a] mb-1 block">Notizen</label>
                                    <input v-model="propKaufanbotForm.notes" class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all w-full" placeholder="Optional" />
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mt-2">
                                <label class="btn btn-brand cursor-pointer text-xs px-4 py-2 inline-flex items-center gap-2" :class="{ 'opacity-50 pointer-events-none': propKaufanbotUploading || !propKaufanbotForm.buyer_name.trim() }">
                                    <Upload class="w-3.5 h-3.5" />
                                    {{ propKaufanbotUploading ? 'Wird hochgeladen...' : 'PDF hochladen' }}
                                    <input type="file" accept=".pdf" class="hidden" @change="uploadPropertyKaufanbot" :disabled="propKaufanbotUploading || !propKaufanbotForm.buyer_name.trim()" />
                                </label>
                                <span class="text-[10px] text-[#71717a]">PDF-Datei erforderlich</span>
                            </div>
                        </div>

                        <!-- Loading -->
                        <div v-if="propKaufanboteLoading" class="text-center py-6 text-sm text-[#71717a]">Kaufanbote werden geladen...</div>

                        <!-- Empty state -->
                        <div v-else-if="!propKaufanbote.length" class="text-center py-8 text-sm text-[#71717a]">
                            Noch keine Kaufanbote für dieses Objekt vorhanden.
                        </div>

                        <!-- List -->
                        <div v-else class="space-y-2">
                            <div v-for="ka in propKaufanbote" :key="ka.id" class="p-3 rounded-xl" style="border:1px solid #e4e4e7">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">{{ ka.buyer_name }}</span>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                                            :style="ka.status === 'akzeptiert' ? 'background:rgba(16,185,129,0.08);color:#10b981' : ka.status === 'abgelehnt' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : ka.status === 'zurueckgezogen' ? 'background:rgba(156,163,175,0.08);color:#9ca3af' : 'background:rgba(245,158,11,0.08);color:#f59e0b'">
                                            {{ ka.status === 'eingegangen' ? 'Eingegangen' : ka.status === 'akzeptiert' ? 'Akzeptiert' : ka.status === 'abgelehnt' ? 'Abgelehnt' : 'Zurückgezogen' }}
                                        </span>
                                    </div>
                                    <span v-if="ka.amount" class="text-sm font-bold" style="color:#ee7606">&euro; {{ Number(ka.amount).toLocaleString('de-DE') }}</span>
                                </div>
                                <div class="flex items-center gap-3 text-[10px] text-[#71717a] mb-2">
                                    <span v-if="ka.buyer_email">{{ ka.buyer_email }}</span>
                                    <span v-if="ka.buyer_phone">{{ ka.buyer_phone }}</span>
                                    <span v-if="ka.kaufanbot_date">{{ ka.kaufanbot_date }}</span>
                                    <span v-if="ka.notes" class="italic">{{ ka.notes }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a :href="'/storage/kaufanbote/' + propSettingsId + '/' + ka.pdf_filename" target="_blank"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-medium px-3 py-1.5 rounded-lg transition-colors"
                                        style="color:#ee7606;background:rgba(238,118,6,0.06)">
                                        <FileText class="w-3.5 h-3.5" />
                                        PDF anzeigen
                                    </a>
                                    <select :value="ka.status" @change="updatePropertyKaufanbotStatus(ka.id, $event.target.value)"
                                        class="w-full px-3 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all text-[11px] py-1 px-2" style="width:auto">
                                        <option value="eingegangen">Eingegangen</option>
                                        <option value="akzeptiert">Akzeptiert</option>
                                        <option value="abgelehnt">Abgelehnt</option>
                                        <option value="zurueckgezogen">Zurückgezogen</option>
                                    </select>
                                    <button @click="deletePropertyKaufanbot(ka.id)" class="text-[11px] px-2 py-1 rounded-lg transition-colors" style="color:#ef4444;background:rgba(239,68,68,0.06)">
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer -->
                <div v-if="propSettingsTab !== 'prop_kaufanbote'" class="px-5 py-3 border-t border-[#e4e4e7] flex justify-end gap-2" style="background:#f4f4f5">
                    <button @click="propSettingsOpen = false" class="btn btn-outline btn-sm" style="height:40px">Abbrechen</button>
                    <button @click="savePropSettings()" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97]" style="height:40px" :disabled="propSettingsSaving">
                        <span v-if="propSettingsSaving" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:12px;height:12px"></span>
                        <template v-else>Speichern</template>
                    </button>
                </div>
            </div>
        </div>

        <!-- Portal Messages Sidebar -->
        <div v-if="msgsOpen" class="fixed inset-0 z-[300] flex items-start justify-center pt-4 sm:pt-8 overflow-y-auto" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
            <div @click="msgsOpen = false" class="absolute inset-0"></div>
            <div class="relative w-full max-w-lg bg-white shadow-2xl flex flex-col mx-2 sm:mx-4 mb-8 rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.6);max-height:90vh">
                <div class="px-3 sm:px-5 py-3 sm:py-4 flex items-center justify-between flex-shrink-0 border-b border-[#e4e4e7]">
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button @click="msgsOpen = false" class="w-8 h-8 rounded-xl flex items-center justify-center bg-zinc-100 hover:bg-zinc-200 transition-all duration-200 active:scale-[0.97]"><ArrowLeft class="w-4 h-4 text-zinc-600" /></button>
                        <div>
                            <h3 class="text-sm font-semibold">Portal-Nachrichten</h3>
                            <p class="text-xs text-[#71717a]">{{ msgsPropertyLabel }}</p>
                        </div>
                    </div>
                    <button @click="msgsOpen = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-3">
                    <div v-if="msgsLoading" class="text-center py-8"><span class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></span></div>
                    <div v-else-if="!msgsList.length" class="text-center py-8 text-[#71717a] text-sm">Noch keine Nachrichten</div>
                    <div v-for="msg in msgsList" :key="msg.id" class="flex" :class="msg.author_role === 'admin' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[85%]">
                            <div class="px-3 py-2 rounded-xl text-xs leading-relaxed"
                                :style="{ background: msg.author_role === 'admin' ? 'rgba(238,118,6,0.1)' : '#f4f4f5', color: '#18181b' }">
                                {{ msg.message }}
                            </div>
                            <div class="flex items-center gap-1 mt-0.5" :class="msg.author_role === 'admin' ? 'justify-end' : 'justify-start'">
                                <span class="text-[9px] text-[#71717a]">{{ msg.author_name || (msg.author_role === 'admin' ? 'SR-Homes' : 'Eigentuemer') }} · {{ msgsFormatTime(msg.created_at) }}</span>
                                <button @click="msgsDelete(msg.id)" class="opacity-0 hover:opacity-100 transition-opacity btn btn-ghost btn-icon" style="width:16px;height:16px;padding:0"><Trash2 class="w-2.5 h-2.5" style="color:#ef4444" /></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-3 border-t border-[#e4e4e7]">
                    <div class="flex gap-2">
                        <textarea v-model="msgsNewText" rows="2" class="form-textarea flex-1 text-xs resize-none" placeholder="Antwort an Eigentuemer... (Enter zum Senden)" @keydown.enter.exact.prevent="msgsSend"></textarea>
                        <button @click="msgsSend" :disabled="!msgsNewText.trim() || msgsSending" class="btn btn-brand btn-sm self-end flex-shrink-0">
                            <span v-if="msgsSending" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin" style="width:12px;height:12px"></span>
                            <span v-else>Senden</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    <!-- Delete Confirmation -->
    <div v-if="deleteConfirm" class="fixed inset-0 z-[300] flex items-center justify-center bg-black/50" @click.self="deleteConfirm = null">
        <div class="bg-[white] rounded-xl shadow-xl p-6 max-w-md w-full mx-4 border border-[#e4e4e7]">
            <h3 class="text-base font-semibold text-red-600 mb-3">Objekt löschen?</h3>
            <p class="text-sm text-[#71717a] mb-2">Alle Aktivitäten, Dateien und verknüpften Daten zu diesem Objekt werden unwiderruflich gelöscht.</p>
            <p class="text-sm text-[#71717a] mb-4">{{ deleteConfirm.message }}</p>
            <div class="flex items-center gap-2">
                <button @click="deleteProperty({ id: deleteConfirm.id })" :disabled="deleteLoading" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium text-zinc-700 bg-white border border-zinc-200 rounded-xl hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="background: #ef4444; color: white;">
                    <span v-if="deleteLoading" class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></span>
                    <template v-else>Endgültig löschen</template>
                </button>
                <button @click="deleteConfirm = null" class="inline-flex items-center gap-2 px-3 py-2 text-xs text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200">Abbrechen</button>
            </div>
        </div>
    </div>


</template>