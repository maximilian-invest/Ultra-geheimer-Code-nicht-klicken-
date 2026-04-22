<script setup>
import { catBadgeStyle, catLabel } from '@/utils/categoryBadge.js';
import { ref, inject, onMounted, watch, computed } from "vue";
import { Search, Users, Pencil, Trash2, Plus, X, ChevronRight, Building, Building2, Clock, KeyRound, Mail, MapPin, Phone, Tag } from "lucide-vue-next";
import HausverwaltungenTab from "@/Components/Admin/HausverwaltungenTab.vue";
import { Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

const API = inject("API");
const toast = inject("toast");
const properties = inject("properties");

const adminSubTab = ref(localStorage.getItem("sr-admin-subtab") || "contacts");
watch(adminSubTab, (v) => localStorage.setItem("sr-admin-subtab", v));

// Contacts
const contactsList = ref([]);
const contactsCount = ref(0);
const contactsLoading = ref(false);
const contactSearch = ref("");
const editingContact = ref(null);

// Listen for openContact from Dashboard
const openContactSearch = inject("openContactSearch", ref(""));
watch(openContactSearch, (name) => {
    if (name) {
        adminSubTab.value = "contacts";
        contactSearch.value = name;
        loadContacts();
        openContactSearch.value = "";
    }
});

// Timeline
const timelineContact = ref(null);
const timelineData = ref([]);
const timelineLoading = ref(false);

// Owners (customers)
const ownersList = ref([]);
const ownersLoading = ref(false);
const ownersSearch = ref('');

// Team/Makler management
const teamList = ref([]);
const teamLoading = ref(false);
const teamSearch = ref('');
const showTeamForm = ref(false);
const teamForm = ref({ name: '', email: '', password: '', phone: '', email_address: '', imap_host: '', imap_port: 993, imap_username: '', imap_password: '', smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '', property_ids: [] });
const teamSaving = ref(false);
const teamError = ref('');
const editingBroker = ref(null);
const allPropertiesForTeam = ref([]);

// Client-side Suche fuer Team + Owners (Server-Filter waere Overkill fuer die kleinen Listen).
const filteredTeam = computed(() => {
    const q = teamSearch.value.trim().toLowerCase();
    if (!q) return teamList.value;
    return teamList.value.filter(b =>
        (b.name || '').toLowerCase().includes(q) ||
        (b.email || '').toLowerCase().includes(q) ||
        (b.email_accounts || '').toLowerCase().includes(q)
    );
});
const filteredOwners = computed(() => {
    const q = ownersSearch.value.trim().toLowerCase();
    if (!q) return ownersList.value;
    return ownersList.value.filter(o =>
        (o.name || '').toLowerCase().includes(q) ||
        (o.email || '').toLowerCase().includes(q) ||
        (o.phone || '').toLowerCase().includes(q) ||
        (o.city || '').toLowerCase().includes(q)
    );
});

const ROLES = [
    { value: 'kunde', label: 'Kunde' },
    { value: 'eigentuemer', label: 'Eigentümer' },
    { value: 'partner', label: 'Partner' },
    { value: 'makler', label: 'Makler' },
    { value: 'bautraeger', label: 'Bauträger' },
    { value: 'intern', label: 'Intern' },
];

onMounted(() => {
    if (adminSubTab.value === "contacts") loadContacts();
    if (adminSubTab.value === "owners") loadOwners();
    if (adminSubTab.value === "team") loadTeam();

});

function switchSub(sub) {
    adminSubTab.value = sub;
    if (sub === 'team') loadTeam();
    if (sub === "contacts") loadContacts();
    if (sub === "owners") loadOwners();
}

// === CONTACTS ===
async function loadContacts() {
    contactsLoading.value = true;
    try {
        const q = contactSearch.value ? "&search=" + encodeURIComponent(contactSearch.value) : "";
        const r = await fetch(API.value + "&action=contacts" + q);
        const d = await r.json();
        contactsList.value = d.contacts || [];
        contactsCount.value = d.count || 0;
    } catch (e) { /* silent */ }
    contactsLoading.value = false;
}

// Debounced Auto-Search: beim Tippen nach 300ms Server-Suche ausloesen.
let contactSearchDebounce = null;
function onContactSearchInput() {
    if (contactSearchDebounce) clearTimeout(contactSearchDebounce);
    contactSearchDebounce = setTimeout(() => loadContacts(), 300);
}

function startEditContact(c) {
    editingContact.value = JSON.parse(JSON.stringify(c));
    editingContact.value._newAlias = "";
    // Ensure property_ids is always an array of numbers
    if (!Array.isArray(editingContact.value.property_ids)) {
        editingContact.value.property_ids = [];
    } else {
        editingContact.value.property_ids = editingContact.value.property_ids.map(Number);
    }
    // Ensure role has a default
    if (!editingContact.value.role) {
        editingContact.value.role = 'kunde';
    }
}

function startNewContact() {
    editingContact.value = {
        id: null,
        full_name: '',
        email: '',
        phone: '',
        role: 'kunde',
        property_ids: [],
        aliases: [],
        _newAlias: '',
    };
}

function togglePropertyId(pid) {
    if (!editingContact.value) return;
    const ids = editingContact.value.property_ids || [];
    const idx = ids.indexOf(pid);
    if (idx === -1) ids.push(pid);
    else ids.splice(idx, 1);
    editingContact.value.property_ids = [...ids];
}

async function saveContact() {
    if (!editingContact.value) return;
    const isNew = !editingContact.value.id;
    const action = isNew ? "contact_create" : "contact_update";
    try {
        const r = await fetch(API.value + "&action=" + action, {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify(editingContact.value),
        });
        const d = await r.json().catch(() => ({}));
        if (d.error) { toast("Fehler: " + d.error); return; }
        editingContact.value = null;
        toast(isNew ? "Kontakt angelegt" : "Kontakt gespeichert");
        loadContacts();
        loadOwners();
    } catch (e) { toast("Fehler: " + e.message); }
}

async function addAlias() {
    if (!editingContact.value || !editingContact.value._newAlias) return;
    const alias = editingContact.value._newAlias.trim();
    if (!alias) return;
    try {
        const r = await fetch(API.value + "&action=contact_add_alias", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: editingContact.value.id, alias }),
        });
        const d = await r.json();
        if (d.aliases) editingContact.value.aliases = d.aliases;
        editingContact.value._newAlias = "";
        toast("Alias hinzugefügt");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function deleteContact(c) {
    if (!confirm('Kontakt "' + c.full_name + '" wirklich löschen?')) return;
    try {
        await fetch(API.value + "&action=contact_delete", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: c.id }),
        });
        toast("Kontakt gelöscht");
        loadContacts();
    } catch (e) { toast("Fehler: " + e.message); }
}

async function openTimeline(c) {
    timelineContact.value = c;
    timelineData.value = [];
    timelineLoading.value = true;
    try {
        const params = [];
        if (c.full_name) params.push("name=" + encodeURIComponent(c.full_name));
        if (c.email) params.push("email=" + encodeURIComponent(c.email));
        const r = await fetch(API.value + "&action=contact_timeline&" + params.join("&"));
        const d = await r.json();
        timelineData.value = d.timeline || [];
    } catch (e) { toast("Fehler: " + e.message); }
    timelineLoading.value = false;
}

function formatEventDate(s) {
    if (!s) return "";
    return new Date(s).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

function eventTypeLabel(e) {
    if (e.event_type === "email") return e.direction === "outbound" ? "E-Mail gesendet" : "E-Mail erhalten";
    const catMap = {
        "besichtigung": "Besichtigung", "kaufanbot": "Kaufanbot", "telefon": "Telefonat",
        "email-in": "E-Mail erhalten", "email-out": "E-Mail gesendet", "absage": "Absage", "partner": "Partner", "eigentuemer": "Eigentümer", "anfrage": "Erstanfrage",
        "notiz": "Notiz", "followup": "Follow-Up",
    };
    return catMap[e.category] || e.category || "Aktivität";
}

function eventBadgeClass(e) {
    // Legacy: returns a CSS class — kept for compatibility
    return "";
}
function eventBadgeStyle(e) {
    return catBadgeStyle(e.category || "sonstiges");
}

function roleBadgeStyle(role) {
    const map = {
        'eigentuemer': 'background:#7c3aed;color:#fff',
        'makler': 'background:#2563eb;color:#fff',
        'bautraeger': 'background:#059669;color:#fff',
        'partner': 'background:#d97706;color:#fff',
        'intern': 'background:#6b7280;color:#fff',
        'kunde': 'background:#e5e7eb;color:#374151',
    };
    return map[role] || map['kunde'];
}

function roleLabel(role) {
    const r = ROLES.find(x => x.value === role);
    return r ? r.label : 'Kunde';
}

// === LEAD-PROFIL ===
const expandedLeadProfile = ref(null);  // contact id
const leadProfiles = ref({});
const loadingLeadProfile = ref({});
const savingLeadProfile = ref({});

const DEFAULT_LEAD_DATA = {
    budget_min: null, budget_max: null, property_type: null,
    location_pref: null, rooms_min: null, size_min_m2: null,
    financing: null, timeline: null, priority: 'warm', notes_lead: null,
};

function toggleLeadProfile(c) {
    if (expandedLeadProfile.value === c.id) {
        expandedLeadProfile.value = null;
        return;
    }
    expandedLeadProfile.value = c.id;
    loadLeadProfile(c);
}

async function loadLeadProfile(c) {
    if (leadProfiles.value[c.id] !== undefined) return;
    loadingLeadProfile.value[c.id] = true;
    try {
        const r = await fetch(API.value + '&action=get_lead_data&contact_id=' + c.id);
        const d = await r.json();
        leadProfiles.value[c.id] = { ...DEFAULT_LEAD_DATA, ...(d.lead_data || {}) };
    } catch (e) {
        leadProfiles.value[c.id] = { ...DEFAULT_LEAD_DATA };
    }
    loadingLeadProfile.value[c.id] = false;
}

async function saveLeadProfile(c) {
    const profile = leadProfiles.value[c.id];
    if (!profile) return;
    savingLeadProfile.value[c.id] = true;
    try {
        const r = await fetch(API.value, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_lead_data', contact_id: c.id, ...profile }),
        });
        const d = await r.json();
        if (d.success) {
            leadProfiles.value[c.id] = { ...DEFAULT_LEAD_DATA, ...(d.lead_data || profile) };
            toast('Lead-Profil gespeichert ✓');
        }
    } catch (e) { toast('Fehler: ' + e.message); }
    savingLeadProfile.value[c.id] = false;
}

async function loadTeam() {
    teamLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=list_brokers");
        const d = await r.json();
        teamList.value = d.brokers || [];
    } catch (e) { toast("Fehler: " + e.message); }
    teamLoading.value = false;

    if (!allPropertiesForTeam.value.length) {
        try {
            const r2 = await fetch(API.value + "&action=list_properties");
            const d2 = await r2.json();
            allPropertiesForTeam.value = d2.properties || [];
        } catch(e) {}
    }
}

function resetTeamForm() {
    teamForm.value = { name: '', email: '', password: '', phone: '', user_type: 'makler', email_address: '', imap_host: '', imap_port: 993, imap_username: '', imap_password: '', smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '', property_ids: [] };
    teamError.value = '';
    editingBroker.value = null;
}

async function saveBroker() {
    teamSaving.value = true;
    teamError.value = '';
    try {
        const action = editingBroker.value ? 'update_broker' : 'create_broker';
        const body = editingBroker.value
            ? { broker_id: editingBroker.value.id, ...teamForm.value }
            : teamForm.value;
        const r = await fetch(API.value + "&action=" + action, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const d = await r.json();
        if (d.success) {
            toast(d.message || 'Gespeichert');
            showTeamForm.value = false;
            resetTeamForm();
            loadTeam();
        } else {
            teamError.value = d.error || 'Fehler';
        }
    } catch (e) { teamError.value = e.message; }
    teamSaving.value = false;
}

function editBroker(b) {
    editingBroker.value = b;
    // Load broker's properties
    const brokerPropIds = allPropertiesForTeam.value.filter(p => p.broker_id == b.id).map(p => p.id);
    teamForm.value = {
        name: b.name, email: b.email, password: '', phone: b.phone || '',
        user_type: b.user_type || 'makler',
        email_address: '', imap_host: '', imap_port: 993, imap_username: '', imap_password: '',
        smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '',
        property_ids: brokerPropIds,
    };
    showTeamForm.value = true;
}

function priorityBadgeStyle(priority) {
    if (priority === 'heiss') return 'background:rgba(239,68,68,0.12);color:#dc2626;border:1px solid rgba(239,68,68,0.3)';
    if (priority === 'kalt')  return 'background:rgba(59,130,246,0.12);color:#2563eb;border:1px solid rgba(59,130,246,0.3)';
    return 'background:rgba(245,158,11,0.12);color:#d97706;border:1px solid rgba(245,158,11,0.3)';
}

function priorityLabel(priority) {
    return priority === 'heiss' ? '🔴 Heiß' : priority === 'kalt' ? '🔵 Kalt' : '🟡 Warm';
}

// === OWNERS ===
const editingOwner = ref(null);
const editOwnerForm = ref({ name: '', email: '', phone: '', address: '', city: '', zip: '', notes: '' });
const editOwnerSaving = ref(false);
const showNewOwnerForm = ref(false);
const newOwnerForm = ref({ name: '', email: '', phone: '', address: '', city: '', zip: '', notes: '' });
const newOwnerSaving = ref(false);

async function loadOwners() {
    ownersLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=list_owners");
        const d = await r.json();
        ownersList.value = d.owners || [];
    } catch (e) { /* silent */ }
    ownersLoading.value = false;
}

function startEditOwner(owner) {
    editingOwner.value = owner.id;
    editOwnerForm.value = {
        name: owner.name || '', email: owner.email || '', phone: owner.phone || '',
        address: owner.address || '', city: owner.city || '', zip: owner.zip || '', notes: owner.notes || ''
    };
}

async function saveEditOwner() {
    editOwnerSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=update_customer", {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: editingOwner.value, ...editOwnerForm.value })
        });
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else { toast("Eigentümer gespeichert"); editingOwner.value = null; loadOwners(); }
    } catch (e) { toast("Fehler: " + e.message); }
    editOwnerSaving.value = false;
}

async function deleteOwner(owner) {
    if (!confirm("Eigentümer \"" + owner.name + "\" wirklich löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_customer", {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: owner.id })
        });
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else { toast("Eigentümer gelöscht"); loadOwners(); }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function createOwner() {
    if (!newOwnerForm.value.name) return;
    newOwnerSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=create_customer", {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(newOwnerForm.value)
        });
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else {
            toast("Eigentümer angelegt");
            showNewOwnerForm.value = false;
            newOwnerForm.value = { name: '', email: '', phone: '', address: '', city: '', zip: '', notes: '' };
            loadOwners();
        }
    } catch (e) { toast("Fehler: " + e.message); }
    newOwnerSaving.value = false;
}

// === PORTAL USER (Dialog) ===
const portalDialogOwner = ref(null);
const portalForm = ref({ password: '', email: '' });
const portalSaving = ref(false);
const portalDeleteConfirm = ref(false);

const portalDialogMode = computed(() => portalDialogOwner.value?.portal_user ? 'edit' : 'create');

function openPortalDialog(owner) {
    portalDialogOwner.value = owner;
    portalDeleteConfirm.value = false;
    portalForm.value = {
        password: '',
        email: owner.portal_user?.email || owner.email || '',
    };
}

function closePortalDialog() {
    portalDialogOwner.value = null;
    portalDeleteConfirm.value = false;
    portalForm.value = { password: '', email: '' };
}

async function createPortalUser() {
    const owner = portalDialogOwner.value;
    if (!owner || !portalForm.value.password) return;
    portalSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=create_portal_user", {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ customer_id: owner.id, password: portalForm.value.password })
        });
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else { toast(d.message || "Portalzugang erstellt"); closePortalDialog(); loadOwners(); }
    } catch (e) { toast("Fehler: " + e.message); }
    portalSaving.value = false;
}

async function savePortalUser() {
    const owner = portalDialogOwner.value;
    if (!owner?.portal_user) return;
    portalSaving.value = true;
    const payload = { user_id: owner.portal_user.id };
    if (portalForm.value.password) payload.password = portalForm.value.password;
    if (portalForm.value.email && portalForm.value.email !== owner.portal_user.email) payload.email = portalForm.value.email;
    try {
        const r = await fetch(API.value + "&action=update_portal_user", {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else { toast("Portalzugang aktualisiert"); closePortalDialog(); loadOwners(); }
    } catch (e) { toast("Fehler: " + e.message); }
    portalSaving.value = false;
}

async function deletePortalUser() {
    const owner = portalDialogOwner.value;
    if (!owner?.portal_user) return;
    portalSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=delete_portal_user", {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: owner.portal_user.id })
        });
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else { toast("Portalzugang gelöscht"); closePortalDialog(); loadOwners(); }
    } catch (e) { toast("Fehler: " + e.message); }
    portalSaving.value = false;
}
</script>

<template>
    <div class="px-4 py-6 w-full space-y-5 overflow-x-hidden" style="box-sizing:border-box">
        <!-- Sub-tabs: nur aktiver Tab markiert, keine durchgehende Unterstrich-Linie -->
        <div class="flex items-center gap-0 w-full overflow-x-hidden">
            <button
                v-for="t in [
                    { key:'contacts', icon: Users,     label:'Kontakte',        count: contactsCount },
                    { key:'owners',   icon: Building,  label:'Eigentümer',      count: ownersList.length },
                    { key:'team',     icon: Users,     label:'Team',            count: teamList.length },
                    { key:'managers', icon: Building2, label:'Hausverwaltungen', count: null },
                ]"
                :key="t.key"
                @click="switchSub(t.key)"
                :class="[
                    'flex-shrink-0 text-[13px] px-4 py-2.5 rounded-none border-b-2 transition-colors flex items-center gap-1.5',
                    adminSubTab === t.key
                        ? 'border-zinc-900 text-zinc-900 font-medium'
                        : 'border-transparent text-muted-foreground hover:text-zinc-900'
                ]"
            >
                <component :is="t.icon" class="w-3.5 h-3.5" />
                {{ t.label }}
                <span v-if="t.count" class="text-[10px] px-1.5 py-0.5 rounded-md bg-muted text-muted-foreground tabular-nums">{{ t.count }}</span>
            </button>
        </div>

        <!-- CONTACTS -->
        <div v-if="adminSubTab === 'contacts'" class="space-y-4 w-full max-w-full min-w-0 overflow-x-hidden px-2.5">
            <!-- Header: Such-Input + Add-Button (einheitlich mit allen Tabs) -->
            <div class="flex items-center gap-2">
                <div class="relative flex-1 min-w-0">
                    <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <Input v-model="contactSearch" @input="onContactSearchInput" @keyup.enter="loadContacts()" class="pl-9" placeholder="Kontakt suchen…" />
                </div>
                <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="startNewContact">
                    <Plus class="w-4 h-4 mr-1" /> Neuer Kontakt
                </Button>
            </div>

            <div v-if="contactsLoading" class="text-sm text-muted-foreground py-8 text-center">Lädt…</div>
            <div v-else-if="!contactsList.length" class="text-center py-12 text-sm text-muted-foreground">
                <Users class="w-10 h-10 mx-auto mb-2 text-muted-foreground/40" />
                <div>Keine Kontakte gefunden.</div>
            </div>
            <div v-else class="space-y-2">
                <div v-for="c in contactsList" :key="c.id"
                     class="rounded-xl bg-card shadow-sm hover:shadow-md transition-shadow overflow-hidden border border-border/20">
                    <div class="p-4 flex items-start justify-between gap-4">
                        <!-- Avatar (einheitlich: orange Tint) -->
                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-semibold text-sm shrink-0" style="background:rgba(238,118,6,0.1);color:#ee7606">
                            {{ (c.full_name || '?').charAt(0).toUpperCase() }}
                        </div>
                        <!-- Main info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-sm truncate">{{ c.full_name }}</span>
                                <Badge v-if="c.role && c.role !== 'kunde'" class="text-[10px] font-medium border-0" :style="roleBadgeStyle(c.role)">
                                    {{ roleLabel(c.role) }}
                                </Badge>
                                <Badge v-if="leadProfiles[c.id]?.priority" class="text-[10px] font-medium border-0" :style="priorityBadgeStyle(leadProfiles[c.id].priority)">
                                    {{ priorityLabel(leadProfiles[c.id].priority) }}
                                </Badge>
                                <Badge v-if="c.property_ids && c.property_ids.length" variant="outline" class="text-[10px] font-medium">
                                    {{ c.property_ids.length }} Objekt{{ c.property_ids.length !== 1 ? 'e' : '' }}
                                </Badge>
                            </div>
                            <div class="text-xs text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                <span v-if="c.email" class="inline-flex items-center gap-1"><Mail class="w-3 h-3" />{{ c.email }}</span>
                                <span v-if="c.phone" class="inline-flex items-center gap-1"><Phone class="w-3 h-3" />{{ c.phone }}</span>
                                <span v-if="c.aliases && c.aliases.length" class="inline-flex items-center gap-1 text-muted-foreground/70">+{{ c.aliases.length }} Alias</span>
                            </div>
                        </div>
                        <!-- Actions (einheitlich: nur ghost-Icon-Buttons) -->
                        <div class="flex items-center gap-1 shrink-0">
                            <Button variant="ghost" size="icon" class="h-8 w-8"
                                    :class="expandedLeadProfile === c.id ? 'text-orange-600 bg-orange-50' : ''"
                                    @click="toggleLeadProfile(c)" title="Lead-Profil">
                                <Tag class="w-3.5 h-3.5" />
                            </Button>
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="openTimeline(c)" title="Timeline">
                                <Clock class="w-3.5 h-3.5" />
                            </Button>
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="startEditContact(c)" title="Bearbeiten">
                                <Pencil class="w-3.5 h-3.5" />
                            </Button>
                            <Button variant="ghost" size="icon" class="h-8 w-8 text-red-600 hover:text-red-700 hover:bg-red-50"
                                    @click="deleteContact(c)" title="Löschen">
                                <Trash2 class="w-3.5 h-3.5" />
                            </Button>
                        </div>
                    </div>
                    <!-- Lead-Profil Panel (aufklappbar) -->
                    <div v-if="expandedLeadProfile === c.id" class="border-t border-border/60 bg-muted/30 px-4 py-4">
                        <div v-if="loadingLeadProfile[c.id]" class="flex items-center gap-2 py-2">
                            <span class="spinner" style="width:14px;height:14px"></span>
                            <span class="text-xs text-muted-foreground">Lade Lead-Profil…</span>
                        </div>
                        <div v-else-if="leadProfiles[c.id]">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-semibold" style="color:var(--muted-foreground)">🏷️ Lead-Profil</span>
                                <button @click="saveLeadProfile(c)" :disabled="savingLeadProfile[c.id]"
                                    class="btn btn-sm" style="background:#10b981;color:#fff;border:none;height:28px;font-size:11px;padding:0 12px">
                                    {{ savingLeadProfile[c.id] ? 'Speichert...' : '✓ Speichern' }}
                                </button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                                <!-- Budget -->
                                <div class="sm:col-span-2 flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Budget €</label>
                                    <input type="number" v-model="leadProfiles[c.id].budget_min" placeholder="Min" class="form-input flex-1 text-xs py-1" style="min-width:0">
                                    <span class="text-xs flex-shrink-0" style="color:var(--muted-foreground)">bis</span>
                                    <input type="number" v-model="leadProfiles[c.id].budget_max" placeholder="Max" class="form-input flex-1 text-xs py-1" style="min-width:0">
                                </div>
                                <!-- Objekttyp -->
                                <div class="flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Objekttyp</label>
                                    <select v-model="leadProfiles[c.id].property_type" class="form-input flex-1 text-xs py-1">
                                        <option value="">egal</option>
                                        <option value="Wohnung">Wohnung</option>
                                        <option value="Haus">Haus</option>
                                        <option value="Grundstueck">Grundstück</option>
                                        <option value="Gewerbe">Gewerbe</option>
                                    </select>
                                </div>
                                <!-- Lage -->
                                <div class="flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Lage/Region</label>
                                    <input type="text" v-model="leadProfiles[c.id].location_pref" placeholder="z.B. Salzburg-Stadt..." class="form-input flex-1 text-xs py-1" style="min-width:0">
                                </div>
                                <!-- Zimmer -->
                                <div class="flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Zimmer min.</label>
                                    <input type="number" v-model="leadProfiles[c.id].rooms_min" placeholder="z.B. 3" class="form-input flex-1 text-xs py-1" style="min-width:0">
                                </div>
                                <!-- Größe -->
                                <div class="flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Größe ab m²</label>
                                    <input type="number" v-model="leadProfiles[c.id].size_min_m2" placeholder="z.B. 80" class="form-input flex-1 text-xs py-1" style="min-width:0">
                                </div>
                                <!-- Finanzierung -->
                                <div class="flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Finanzierung</label>
                                    <select v-model="leadProfiles[c.id].financing" class="form-input flex-1 text-xs py-1">
                                        <option value="">–</option>
                                        <option value="Eigenkapital">Eigenkapital vorhanden</option>
                                        <option value="Kredit geplant">Kredit in Planung</option>
                                        <option value="Kredit genehmigt">Bereits genehmigt</option>
                                        <option value="Unklar">Unklar</option>
                                    </select>
                                </div>
                                <!-- Zeithorizont -->
                                <div class="flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Zeithorizont</label>
                                    <select v-model="leadProfiles[c.id].timeline" class="form-input flex-1 text-xs py-1">
                                        <option value="">–</option>
                                        <option value="sofort">Sofort / Dringend</option>
                                        <option value="1-3 Monate">1-3 Monate</option>
                                        <option value="dieses Jahr">Dieses Jahr</option>
                                        <option value="nächstes Jahr">Nächstes Jahr / Kein Druck</option>
                                    </select>
                                </div>
                                <!-- Priorität -->
                                <div class="sm:col-span-2 flex items-center gap-2">
                                    <label class="text-xs flex-shrink-0" style="color:var(--muted-foreground);width:80px">Priorität</label>
                                    <div class="flex gap-1.5">
                                        <button @click="leadProfiles[c.id].priority = 'heiss'"
                                            class="text-xs px-3 py-1 rounded-full border transition-colors"
                                            :style="leadProfiles[c.id].priority === 'heiss' ? 'background:#ef4444;color:#fff;border-color:#ef4444' : 'border-color:var(--border);color:var(--muted-foreground)'">
                                            🔴 Heiß
                                        </button>
                                        <button @click="leadProfiles[c.id].priority = 'warm'"
                                            class="text-xs px-3 py-1 rounded-full border transition-colors"
                                            :style="leadProfiles[c.id].priority === 'warm' ? 'background:#f59e0b;color:#fff;border-color:#f59e0b' : 'border-color:var(--border);color:var(--muted-foreground)'">
                                            🟡 Warm
                                        </button>
                                        <button @click="leadProfiles[c.id].priority = 'kalt'"
                                            class="text-xs px-3 py-1 rounded-full border transition-colors"
                                            :style="leadProfiles[c.id].priority === 'kalt' ? 'background:#3b82f6;color:#fff;border-color:#3b82f6' : 'border-color:var(--border);color:var(--muted-foreground)'">
                                            🔵 Kalt
                                        </button>
                                    </div>
                                </div>
                                <!-- Notizen -->
                                <div class="sm:col-span-2 flex flex-col gap-1">
                                    <label class="text-xs" style="color:var(--muted-foreground)">Notizen zum Lead</label>
                                    <textarea v-model="leadProfiles[c.id].notes_lead" rows="3" placeholder="Freitext zu diesem Lead..."
                                        class="form-input text-xs resize-y" style="min-height:60px"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Contact Modal -->
            <div v-if="editingContact" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                <div class="bg-[var(--card)] rounded-xl shadow-lg w-full max-w-md mx-4 flex flex-col" style="max-height:90vh">
                    <div class="px-6 py-4 flex items-center justify-between border-b border-[var(--border)] flex-shrink-0">
                        <h3 class="text-sm font-semibold">{{ editingContact.id ? 'Kontakt bearbeiten' : 'Neuer Kontakt' }}</h3>
                        <button @click="editingContact = null" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                    </div>
                    <div class="p-6 space-y-4 overflow-y-auto flex-1">
                        <div><label class="form-label">Name</label><input v-model="editingContact.full_name" class="form-input" /></div>
                        <div><label class="form-label">E-Mail</label><input v-model="editingContact.email" class="form-input" /></div>
                        <div><label class="form-label">Telefon</label><input v-model="editingContact.phone" class="form-input" /></div>

                        <!-- Rolle -->
                        <div>
                            <label class="form-label">Rolle</label>
                            <select v-model="editingContact.role" class="form-input">
                                <option v-for="r in ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
                            </select>
                        </div>

                        <!-- Eigentümer von -->
                        <div>
                            <label class="form-label">Eigentümer von</label>
                            <div class="rounded-lg border border-[var(--border)] overflow-hidden">
                                <div class="max-h-44 overflow-y-auto divide-y divide-[var(--border)]">
                                    <label v-for="prop in properties" :key="prop.id"
                                        class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-[var(--muted)] select-none transition-colors"
                                        :class="(editingContact.property_ids || []).includes(prop.id) ? 'bg-[var(--muted)]' : ''">
                                        <input type="checkbox"
                                            :checked="(editingContact.property_ids || []).includes(prop.id)"
                                            @change="togglePropertyId(prop.id)"
                                            class="rounded border-[var(--border)] flex-shrink-0" />
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-mono flex-shrink-0" style="background:rgba(238,118,6,0.1);color:#ee7606">{{ prop.ref_id }}</span>
                                        <span class="text-xs truncate text-[var(--foreground)]">{{ prop.address }}<span v-if="prop.city" class="text-[var(--muted-foreground)]">, {{ prop.city }}</span></span>
                                    </label>
                                    <div v-if="!properties || !properties.length" class="text-[var(--muted-foreground)] text-xs py-3 px-3 text-center">Keine Objekte verfügbar</div>
                                </div>
                                <div v-if="editingContact.property_ids && editingContact.property_ids.length" class="px-3 py-1.5 bg-[var(--muted)] border-t border-[var(--border)]">
                                    <span class="text-[10px] text-[var(--muted-foreground)]">{{ editingContact.property_ids.length }} Objekt(e) ausgewählt</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="form-label">Aliase</label>
                            <div v-if="editingContact.aliases && editingContact.aliases.length" class="flex flex-wrap gap-1 mb-2">
                                <span v-for="(alias, idx) in editingContact.aliases" :key="idx"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-[var(--muted)]">
                                    {{ alias }}
                                    <button @click="editingContact.aliases.splice(idx, 1)" class="text-[hsl(var(--destructive))]"><X class="w-3 h-3" /></button>
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <input v-model="editingContact._newAlias" @keyup.enter="addAlias()" class="form-input flex-1" placeholder="Neuer Alias..." />
                                <button @click="addAlias()" class="btn btn-outline btn-sm"><Plus class="w-3 h-3" /></button>
                            </div>
                        </div>

                        <div class="flex gap-3 pt-1">
                            <button @click="saveContact()" class="btn btn-primary">Speichern</button>
                            <button @click="editingContact = null" class="btn btn-ghost">Abbrechen</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TEAM -->
        <div v-if="adminSubTab === 'team'" class="space-y-4 w-full max-w-full min-w-0 overflow-x-hidden px-2.5">
            <!-- Header: Such-Input + Add-Button (einheitlich mit allen Tabs) -->
            <div class="flex items-center gap-2">
                <div class="relative flex-1 min-w-0">
                    <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <Input v-model="teamSearch" class="pl-9" placeholder="Team-Mitglied suchen…" />
                </div>
                <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="resetTeamForm(); showTeamForm = true">
                    <Plus class="w-4 h-4 mr-1" /> Makler hinzufügen
                </Button>
            </div>

            <!-- Broker list -->
            <div v-if="teamLoading" class="text-sm text-muted-foreground py-8 text-center">Lädt…</div>
            <div v-else-if="!filteredTeam.length" class="text-center py-12 text-sm text-muted-foreground">
                <Users class="w-10 h-10 mx-auto mb-2 text-muted-foreground/40" />
                <div v-if="teamSearch">Keine Treffer für „{{ teamSearch }}".</div>
                <template v-else>
                    <div>Noch kein Team-Mitglied angelegt.</div>
                    <div class="text-xs mt-1">Klick „Makler hinzufügen" um zu beginnen.</div>
                </template>
            </div>
            <div v-else class="space-y-2">
                <div v-for="b in filteredTeam" :key="b.id"
                     class="rounded-xl bg-card shadow-sm hover:shadow-md transition-shadow overflow-hidden border border-border/20">
                    <div class="p-4 flex items-start justify-between gap-4">
                        <!-- Avatar (einheitlich orange) -->
                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-semibold text-sm shrink-0" style="background:rgba(238,118,6,0.1);color:#ee7606">
                            {{ (b.name || '?').charAt(0).toUpperCase() }}
                        </div>
                        <!-- Main info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-sm truncate">{{ b.name }}</span>
                                <Badge class="text-[10px] font-medium border-0"
                                       :style="b.user_type === 'admin'
                                           ? 'background:rgba(139,92,246,0.12);color:#7c3aed'
                                           : b.user_type === 'assistenz'
                                               ? 'background:rgba(37,99,235,0.12);color:#2563eb'
                                               : 'background:rgba(16,185,129,0.12);color:#059669'">
                                    {{ b.user_type === 'admin' ? 'Admin' : b.user_type === 'assistenz' ? 'Assistenz' : 'Makler' }}
                                </Badge>
                                <Badge v-if="b.property_count" variant="outline" class="text-[10px] font-medium">
                                    {{ b.property_count }} Objekt{{ b.property_count !== 1 ? 'e' : '' }}
                                </Badge>
                            </div>
                            <div class="text-xs text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                <span class="inline-flex items-center gap-1"><Mail class="w-3 h-3" />{{ b.email }}</span>
                                <span v-if="b.email_accounts" class="inline-flex items-center gap-1 text-muted-foreground/70">📧 {{ b.email_accounts }}</span>
                            </div>
                        </div>
                        <!-- Actions (einheitlich: nur ghost-Icon-Buttons) -->
                        <div class="flex items-center gap-1 shrink-0">
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="editBroker(b)" title="Bearbeiten">
                                <Pencil class="w-3.5 h-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create/Edit form (modal) -->
            <div v-if="showTeamForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showTeamForm = false">
                <div class="bg-[var(--card)] rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-[var(--border)] flex items-center justify-between">
                        <h3 class="font-semibold">{{ editingBroker ? 'Makler bearbeiten' : 'Neuer Makler' }}</h3>
                        <button @click="showTeamForm = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                    </div>
                    <div class="p-6 space-y-4">
                        <!-- Basic info -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-[var(--muted-foreground)]">Name</label>
                                <input v-model="teamForm.name" class="form-input mt-1" placeholder="Max Mustermann" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-[var(--muted-foreground)]">Telefon</label>
                                <input v-model="teamForm.phone" class="form-input mt-1" placeholder="+43..." />
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-[var(--muted-foreground)]">Login E-Mail</label>
                            <input v-model="teamForm.email" type="email" class="form-input mt-1" :disabled="!!editingBroker" placeholder="makler@sr-homes.at" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-[var(--muted-foreground)]">{{ editingBroker ? 'Neues Passwort (leer = unverändert)' : 'Passwort' }}</label>
                                <input v-model="teamForm.password" type="text" class="form-input mt-1" :placeholder="editingBroker ? 'Leer lassen wenn unverändert' : 'Initiales Passwort'" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-[var(--muted-foreground)]">Rolle</label>
                                <select v-model="teamForm.user_type" class="form-input mt-1">
                                    <option value="makler">Makler</option>
                                    <option value="assistenz">Assistenz</option>
                                </select>
                            </div>
                        </div>

                        <!-- Email Account -->
                        <div v-if="!editingBroker" class="border-t border-[var(--border)] pt-4">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-xs font-semibold text-[var(--muted-foreground)]">📧 E-Mail-Konto (IMAP/SMTP)</span>
                                <span class="text-[10px] text-[var(--muted-foreground)]">Optional — kann später ergänzt werden</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">E-Mail-Adresse</label>
                                    <input v-model="teamForm.email_address" class="form-input mt-0.5 text-xs" placeholder="makler@firma.at" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">IMAP Host</label>
                                    <input v-model="teamForm.imap_host" class="form-input mt-0.5 text-xs" placeholder="imap.gmail.com" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">IMAP User</label>
                                    <input v-model="teamForm.imap_username" class="form-input mt-0.5 text-xs" placeholder="makler@firma.at" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">IMAP Passwort</label>
                                    <input v-model="teamForm.imap_password" type="password" class="form-input mt-0.5 text-xs" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">SMTP Host</label>
                                    <input v-model="teamForm.smtp_host" class="form-input mt-0.5 text-xs" placeholder="smtp.gmail.com" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">SMTP User</label>
                                    <input v-model="teamForm.smtp_username" class="form-input mt-0.5 text-xs" placeholder="= IMAP User" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-[var(--muted-foreground)]">SMTP Passwort</label>
                                    <input v-model="teamForm.smtp_password" type="password" class="form-input mt-0.5 text-xs" placeholder="= IMAP Passwort" />
                                </div>
                            </div>
                        </div>

                        <!-- Property assignment -->
                        <div class="border-t border-[var(--border)] pt-4">
                            <label class="text-xs font-semibold text-[var(--muted-foreground)] mb-2 block">Zugewiesene Objekte</label>
                            <div class="grid grid-cols-1 gap-1 max-h-40 overflow-y-auto p-2 rounded-lg" style="background:var(--muted)">
                                <label v-for="p in allPropertiesForTeam" :key="p.id"
                                    class="flex items-center gap-2 cursor-pointer py-1 px-2 rounded hover:bg-white/50 dark:hover:bg-white/5 text-xs">
                                    <input type="checkbox" :value="p.id" v-model="teamForm.property_ids" class="w-3.5 h-3.5" style="accent-color:#ee7606" />
                                    <span class="truncate">{{ p.ref_id }} — {{ p.address }}, {{ p.city }}</span>
                                    <span v-if="p.broker_id && p.broker_id != (editingBroker?.id || 0)" class="text-[10px] ml-auto text-amber-500 flex-shrink-0">vergeben</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="teamError" class="text-xs text-red-500">{{ teamError }}</div>

                        <div class="flex gap-2 pt-2">
                            <button @click="showTeamForm = false" class="flex-1 btn btn-outline">Abbrechen</button>
                            <button @click="saveBroker()" :disabled="teamSaving || !teamForm.name || !teamForm.email"
                                class="flex-1 btn" style="background:#ee7606;color:white;border:none">
                                {{ teamSaving ? 'Speichert...' : (editingBroker ? 'Speichern' : 'Makler erstellen') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

            <!-- Timeline Modal -->
            <div v-if="timelineContact" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                <div class="bg-[var(--card)] rounded-xl shadow-lg w-full max-w-2xl mx-4 flex flex-col" style="max-height:85vh">
                    <div class="px-6 py-4 flex items-center justify-between border-b border-[var(--border)] flex-shrink-0">
                        <div class="flex items-center gap-3">
                            <Clock class="w-4 h-4" style="color:#ee7606" />
                            <div>
                                <h3 class="text-sm font-semibold">Timeline: {{ timelineContact.full_name }}</h3>
                                <p class="text-xs text-[var(--muted-foreground)]">{{ timelineContact.email || '' }}</p>
                            </div>
                        </div>
                        <button @click="timelineContact = null" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                    </div>
                    <div class="overflow-y-auto flex-1 p-4">
                        <div v-if="timelineLoading" class="text-center py-8"><span class="spinner"></span></div>
                        <div v-else-if="!timelineData.length" class="text-center py-8 text-[var(--muted-foreground)] text-sm">Keine Interaktionen gefunden</div>
                        <div v-else class="relative pl-6">
                            <!-- vertical line -->
                            <div class="absolute left-2 top-0 bottom-0 w-0.5 bg-[var(--border)]"></div>
                            <div v-for="(e, idx) in timelineData" :key="idx" class="mb-4 relative">
                                <!-- dot -->
                                <div class="absolute -left-4 top-1.5 w-2 h-2 rounded-full border-2 border-[var(--border)]"
                                    :style="e.event_type === 'email' ? 'background:#3b82f6' : e.category === 'kaufanbot' ? 'background:#9333ea' : 'background:#ee7606'"></div>
                                <div class="card p-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                                <span class="badge text-[10px]" :style="eventBadgeStyle(e)">{{ eventTypeLabel(e) }}</span>
                                                <span v-if="e.ref_id" class="badge badge-muted text-[10px]">{{ e.ref_id }}</span>
                                                <span v-if="e.address" class="text-[10px] text-[var(--muted-foreground)] truncate">{{ e.address }}, {{ e.city }}</span>
                                            </div>
                                            <p class="text-sm font-medium">{{ e.title }}</p>
                                            <p v-if="e.detail" class="text-xs text-[var(--muted-foreground)] mt-0.5">{{ e.detail }}</p>
                                        </div>
                                        <div class="text-[10px] text-[var(--muted-foreground)] flex-shrink-0 text-right">{{ formatEventDate(e.event_date) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-3 border-t border-[var(--border)] flex-shrink-0">
                        <span class="text-xs text-[var(--muted-foreground)]">{{ timelineData.length }} Einträge</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- OWNERS -->
        <div v-if="adminSubTab === 'owners'" class="space-y-4 w-full max-w-full min-w-0 overflow-x-hidden px-2.5">
            <!-- Header: konsistent mit allen Tabs -->
            <div class="flex items-center gap-2">
                <div class="relative flex-1 min-w-0">
                    <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <Input v-model="ownersSearch" class="pl-9" placeholder="Eigentümer suchen…" />
                </div>
                <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="showNewOwnerForm = !showNewOwnerForm">
                    <Plus class="w-4 h-4 mr-1" /> Neuer Eigentümer
                </Button>
            </div>

            <!-- New Owner Form -->
            <div v-if="showNewOwnerForm" class="rounded-xl bg-card shadow-sm overflow-hidden border border-border/20">
                <div class="px-4 py-3 border-b border-border/60 bg-muted/30 flex items-center justify-between">
                    <div>
                        <div class="text-sm font-semibold">Neuen Eigentümer anlegen</div>
                        <div class="text-xs text-muted-foreground">Alle Felder außer Name sind optional.</div>
                    </div>
                    <Button variant="ghost" size="icon" class="h-8 w-8" @click="showNewOwnerForm = false">
                        <X class="w-4 h-4" />
                    </Button>
                </div>
                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Name <span class="text-red-500">*</span></label>
                        <Input v-model="newOwnerForm.name" placeholder="Vor- und Nachname" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">E-Mail</label>
                        <Input v-model="newOwnerForm.email" type="email" placeholder="email@beispiel.at" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Telefon</label>
                        <Input v-model="newOwnerForm.phone" placeholder="+43 ..." />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Adresse</label>
                        <Input v-model="newOwnerForm.address" placeholder="Straße Nr." />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">PLZ</label>
                        <Input v-model="newOwnerForm.zip" placeholder="5020" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Ort</label>
                        <Input v-model="newOwnerForm.city" placeholder="Salzburg" />
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="text-xs font-medium text-muted-foreground">Notizen</label>
                        <Textarea v-model="newOwnerForm.notes" rows="2" placeholder="Interne Notizen..." />
                    </div>
                </div>
                <div class="px-4 py-3 border-t border-border/60 bg-muted/30 flex justify-end gap-2">
                    <Button variant="outline" size="sm" @click="showNewOwnerForm = false">Abbrechen</Button>
                    <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" :disabled="newOwnerSaving || !newOwnerForm.name" @click="createOwner">
                        {{ newOwnerSaving ? 'Wird angelegt…' : 'Anlegen' }}
                    </Button>
                </div>
            </div>

            <div v-if="ownersLoading" class="text-sm text-muted-foreground py-8 text-center">Lädt…</div>
            <div v-else-if="!filteredOwners.length" class="text-center py-12 text-sm text-muted-foreground">
                <Building class="w-10 h-10 mx-auto mb-2 text-muted-foreground/40" />
                <div v-if="ownersSearch">Keine Treffer für „{{ ownersSearch }}".</div>
                <template v-else>
                    <div>Keine Eigentümer vorhanden.</div>
                    <div class="text-xs mt-1">Klick „Neuer Eigentümer" um zu beginnen.</div>
                </template>
            </div>

            <div v-else class="space-y-2">
                <div v-for="owner in filteredOwners" :key="owner.id"
                     class="rounded-xl bg-card shadow-sm hover:shadow-md transition-shadow overflow-hidden border border-border/20">
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-4">
                            <!-- Avatar -->
                            <div class="w-10 h-10 rounded-full flex items-center justify-center font-semibold text-sm shrink-0" style="background:rgba(238,118,6,0.1);color:#ee7606">
                                {{ (owner.name || '?').charAt(0).toUpperCase() }}
                            </div>
                            <!-- Main info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-sm truncate">{{ owner.name }}</span>
                                    <Badge variant="outline" class="text-[10px] font-medium">
                                        {{ owner.property_count }} Objekt{{ owner.property_count !== 1 ? 'e' : '' }}
                                    </Badge>
                                    <Badge v-if="owner.portal_user" class="text-[10px] font-medium border-0" style="background:rgba(16,185,129,0.12);color:#059669">
                                        <KeyRound class="w-3 h-3" /> Portalzugang
                                    </Badge>
                                </div>
                                <div class="text-xs text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                    <span v-if="owner.email" class="inline-flex items-center gap-1"><Mail class="w-3 h-3" />{{ owner.email }}</span>
                                    <span v-if="owner.phone" class="inline-flex items-center gap-1"><Phone class="w-3 h-3" />{{ owner.phone }}</span>
                                    <span v-if="owner.address" class="inline-flex items-center gap-1"><MapPin class="w-3 h-3" />{{ owner.address }}{{ owner.zip ? ', ' + owner.zip : '' }} {{ owner.city || '' }}</span>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div class="flex items-center gap-1 shrink-0">
                                <Button variant="ghost" size="icon" class="h-8 w-8"
                                        @click="openPortalDialog(owner)" title="Portalzugang"
                                        :disabled="!owner.portal_user && (!owner.email || owner.email.startsWith('placeholder'))">
                                    <KeyRound class="w-3.5 h-3.5" />
                                </Button>
                                <Button variant="ghost" size="icon" class="h-8 w-8" @click="startEditOwner(owner)" title="Bearbeiten">
                                    <Pencil class="w-3.5 h-3.5" />
                                </Button>
                                <Button variant="ghost" size="icon" class="h-8 w-8 text-red-600 hover:text-red-700 hover:bg-red-50"
                                        @click="deleteOwner(owner)" title="Löschen">
                                    <Trash2 class="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        </div>

                        <!-- Objekte-Liste (in-row, kompakt) -->
                        <div v-if="owner.properties && owner.properties.length" class="mt-3 pt-3 border-t border-border/60 space-y-1 min-w-0">
                            <div v-for="prop in owner.properties" :key="prop.id" class="flex items-center gap-2 text-xs min-w-0">
                                <Badge variant="outline" class="text-[10px] font-mono shrink-0">{{ prop.ref_id }}</Badge>
                                <span class="truncate text-muted-foreground min-w-0 flex-1">{{ prop.address }}, {{ prop.city }}</span>
                                <Badge v-if="prop.status === 'verkauft'" class="text-[10px] font-medium border-0 shrink-0" style="background:rgba(71,85,105,0.12);color:#475569">verkauft</Badge>
                            </div>
                        </div>

                        <!-- Inline Edit Form -->
                        <div v-if="editingOwner === owner.id" class="mt-4 pt-4 border-t border-border/60 bg-muted/30 -mx-4 -mb-4 px-4 py-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium">Name <span class="text-red-500">*</span></label>
                                    <Input v-model="editOwnerForm.name" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium">E-Mail</label>
                                    <Input v-model="editOwnerForm.email" type="email" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium">Telefon</label>
                                    <Input v-model="editOwnerForm.phone" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium">Adresse</label>
                                    <Input v-model="editOwnerForm.address" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium">PLZ</label>
                                    <Input v-model="editOwnerForm.zip" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium">Ort</label>
                                    <Input v-model="editOwnerForm.city" />
                                </div>
                                <div class="space-y-1.5 sm:col-span-2">
                                    <label class="text-sm font-medium">Notizen</label>
                                    <Textarea v-model="editOwnerForm.notes" rows="2" />
                                </div>
                                <div class="sm:col-span-2 flex justify-end gap-2">
                                    <Button variant="outline" size="sm" @click="editingOwner = null">Abbrechen</Button>
                                    <Button size="sm" :disabled="editOwnerSaving || !editOwnerForm.name" @click="saveEditOwner" style="background:#ee7606;color:white">
                                        {{ editOwnerSaving ? 'Speichern...' : 'Speichern' }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Portalzugang Dialog -->
        <Dialog :open="!!portalDialogOwner" @update:open="(v) => { if (!v) closePortalDialog() }">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <KeyRound class="w-4 h-4" style="color:#ee7606" />
                        Portalzugang {{ portalDialogMode === 'edit' ? 'verwalten' : 'erstellen' }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="portalDialogOwner">
                            <span class="font-medium text-foreground">{{ portalDialogOwner.name }}</span>
                            <span v-if="portalDialogMode === 'edit'"> hat aktuell Zugang zum Kundenportal.</span>
                            <span v-else> bekommt einen neuen Portalzugang.</span>
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <div v-if="portalDialogOwner" class="space-y-4 py-2">
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium">Login E-Mail</label>
                        <Input v-model="portalForm.email" type="email" :disabled="portalDialogMode === 'create'" placeholder="login@beispiel.at" />
                        <p v-if="portalDialogMode === 'create'" class="text-xs text-muted-foreground">Die E-Mail wird vom Eigentümer übernommen und kann nach dem Anlegen geändert werden.</p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-medium">
                            {{ portalDialogMode === 'edit' ? 'Neues Passwort' : 'Initiales Passwort' }}
                        </label>
                        <Input v-model="portalForm.password" type="text" :placeholder="portalDialogMode === 'edit' ? 'Leer lassen = unverändert' : 'Mindestens 8 Zeichen'" />
                        <p v-if="portalDialogMode === 'edit'" class="text-xs text-muted-foreground">Leer lassen, wenn das Passwort unverändert bleiben soll.</p>
                    </div>

                    <div v-if="portalDialogMode === 'edit' && portalDeleteConfirm" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700">
                        <p class="font-medium mb-2">Portalzugang wirklich löschen?</p>
                        <p>Der Eigentümer kann sich danach nicht mehr einloggen. Diese Aktion kann nicht rückgängig gemacht werden.</p>
                        <div class="flex gap-2 mt-3">
                            <Button variant="outline" size="sm" @click="portalDeleteConfirm = false">Abbrechen</Button>
                            <Button variant="destructive" size="sm" :disabled="portalSaving" @click="deletePortalUser">
                                {{ portalSaving ? 'Lösche...' : 'Ja, löschen' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <DialogFooter class="flex flex-col-reverse sm:flex-row sm:justify-between gap-2">
                    <div>
                        <Button v-if="portalDialogMode === 'edit' && !portalDeleteConfirm"
                            variant="ghost" size="sm" class="text-destructive hover:text-destructive hover:bg-red-50"
                            @click="portalDeleteConfirm = true">
                            <Trash2 class="w-3.5 h-3.5" /> Zugang löschen
                        </Button>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" @click="closePortalDialog">Abbrechen</Button>
                        <Button v-if="portalDialogMode === 'create'"
                            size="sm" :disabled="portalSaving || !portalForm.password"
                            @click="createPortalUser" style="background:#ee7606;color:white">
                            {{ portalSaving ? 'Erstellen...' : 'Zugang erstellen' }}
                        </Button>
                        <Button v-else
                            size="sm" :disabled="portalSaving"
                            @click="savePortalUser" style="background:#ee7606;color:white">
                            {{ portalSaving ? 'Speichern...' : 'Änderungen speichern' }}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- HAUSVERWALTUNGEN (Phase 1) -->
        <div v-if="adminSubTab === 'managers'" class="w-full max-w-full min-w-0 overflow-x-hidden px-2.5">
            <HausverwaltungenTab />
        </div>
</template>
