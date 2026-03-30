<script setup>
import { ref, inject, onMounted, computed, watch, nextTick } from "vue";
import { ChevronLeft, ChevronRight, Plus, X, Clock, MapPin, FileText, Calendar as CalendarIcon, Home, Trash2, Save, Eye } from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");
const properties = inject("properties");
const userType = inject("userType", ref("admin"));

// Broker list for assistenz to assign events to a broker
const brokers = ref([]);
async function loadBrokers() {
    try {
        const r = await fetch(API.value + "&action=list_brokers");
        const d = await r.json();
        brokers.value = (d.brokers || []).filter(b => b.user_type !== 'assistenz');
    } catch(e) {}
}

// View state
const viewMode = ref("week"); // week | month
const currentDate = ref(new Date());
const events = ref([]);
const loading = ref(false);


// Event modal
const showModal = ref(false);
const modalMode = ref("create"); // create | edit
const modalEvent = ref({
    id: null, summary: "", description: "", location: "",
    start_date: "", start_time: "09:00", end_date: "", end_time: "10:00",
    all_day: false, color: null, property_id: null,
});
const saving = ref(false);

// Colors
const eventColors = [
    { value: null, label: "Standard", bg: "#ee7606", text: "#fff" },
    { value: "#3b82f6", label: "Blau", bg: "#3b82f6", text: "#fff" },
    { value: "#10b981", label: "Grün", bg: "#10b981", text: "#fff" },
    { value: "#8b5cf6", label: "Lila", bg: "#8b5cf6", text: "#fff" },
    { value: "#ef4444", label: "Rot", bg: "#ef4444", text: "#fff" },
    { value: "#f59e0b", label: "Gelb", bg: "#f59e0b", text: "#fff" },
    { value: "#14b8a6", label: "Teal", bg: "#14b8a6", text: "#fff" },
];

const hours = Array.from({ length: 15 }, (_, i) => i + 7); // 7:00 - 21:00

// Computed
const weekDays = computed(() => {
    const d = new Date(currentDate.value);
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day; // Monday start
    const monday = new Date(d);
    monday.setDate(d.getDate() + diff);
    return Array.from({ length: 7 }, (_, i) => {
        const date = new Date(monday);
        date.setDate(monday.getDate() + i);
        return date;
    });
});

const monthDays = computed(() => {
    const d = new Date(currentDate.value);
    const year = d.getFullYear();
    const month = d.getMonth();
    const first = new Date(year, month, 1);
    const last = new Date(year, month + 1, 0);
    const startDay = first.getDay() === 0 ? 6 : first.getDay() - 1; // Monday start
    const days = [];
    // Previous month padding
    for (let i = startDay - 1; i >= 0; i--) {
        const pd = new Date(year, month, -i);
        days.push({ date: pd, currentMonth: false });
    }
    // Current month
    for (let i = 1; i <= last.getDate(); i++) {
        days.push({ date: new Date(year, month, i), currentMonth: true });
    }
    // Next month padding
    const remaining = 42 - days.length;
    for (let i = 1; i <= remaining; i++) {
        days.push({ date: new Date(year, month + 1, i), currentMonth: false });
    }
    return days;
});

const headerTitle = computed(() => {
    const d = currentDate.value;
    if (viewMode.value === "week") {
        const start = weekDays.value[0];
        const end = weekDays.value[6];
        if (start.getMonth() === end.getMonth()) {
            return start.toLocaleDateString("de-AT", { day: "numeric" }) + " – " +
                end.toLocaleDateString("de-AT", { day: "numeric", month: "long", year: "numeric" });
        }
        return start.toLocaleDateString("de-AT", { day: "numeric", month: "short" }) + " – " +
            end.toLocaleDateString("de-AT", { day: "numeric", month: "short", year: "numeric" });
    }
    return d.toLocaleDateString("de-AT", { month: "long", year: "numeric" });
});

const viewRange = computed(() => {
    if (viewMode.value === "week") {
        return { start: fmt(weekDays.value[0]), end: fmt(weekDays.value[6]) };
    }
    const days = monthDays.value;
    return { start: fmt(days[0].date), end: fmt(days[days.length - 1].date) };
});

function fmt(d) { return d.toISOString().slice(0, 10); }
function fmtTime(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d)) {
        // Might be "YYYY-MM-DD HH:mm:ss" format
        const parts = iso.split(" ");
        return parts[1] ? parts[1].slice(0, 5) : "";
    }
    return d.toLocaleTimeString("de-AT", { hour: "2-digit", minute: "2-digit" });
}
function isToday(d) { return fmt(d) === fmt(new Date()); }
function isSameDay(a, b) { return fmt(a) === fmt(b); }

function eventsForDay(date) {
    const ds = fmt(date);
    return events.value.filter(e => {
        const es = (e.start || "").slice(0, 10);
        const ee = (e.end || "").slice(0, 10);
        return es <= ds && ee >= ds;
    });
}

function eventsForHour(date, hour) {
    const ds = fmt(date);
    return events.value.filter(e => {
        if (e.all_day) return false;
        const es = e.start || "";
        const eDate = es.slice(0, 10);
        if (eDate !== ds) return false;
        const eHour = parseInt(fmtTime(es).split(":")[0]);
        return eHour === hour;
    });
}

function eventStyle(event) {
    const color = event.color || (event.is_besichtigung ? "#14b8a6" : "#ee7606");
    return { background: color + "20", borderLeft: "3px solid " + color, color: color };
}

function eventBg(event) {
    return event.color || (event.is_besichtigung ? "#14b8a6" : "#ee7606");
}

// Navigation
function navigate(dir) {
    const d = new Date(currentDate.value);
    if (viewMode.value === "week") d.setDate(d.getDate() + dir * 7);
    else d.setMonth(d.getMonth() + dir);
    currentDate.value = d;
}
function goToday() { currentDate.value = new Date(); }

// Data loading
async function loadEvents() {
    loading.value = true;
    try {
        const r = await fetch(API.value + "&action=calendar_events&start=" + viewRange.value.start + "&end=" + viewRange.value.end);
        const d = await r.json();
        events.value = d.events || [];
    } catch (e) { toast("Fehler beim Laden: " + e.message); }
    loading.value = false;
}



// Event modal
function openCreateModal(date, hour) {
    const ds = fmt(date || new Date());
    const h = hour !== undefined ? String(hour).padStart(2, "0") + ":00" : "09:00";
    const hEnd = hour !== undefined ? String(hour + 1).padStart(2, "0") + ":00" : "10:00";
    modalMode.value = "create";
    modalEvent.value = {
        id: null, summary: "", description: "", location: "",
        start_date: ds, start_time: h, end_date: ds, end_time: hEnd,
        all_day: false, color: null, property_id: null, for_user_id: null,
    };
    showModal.value = true;
}

function openEditModal(event) {
    modalMode.value = "edit";
    const startDate = (event.start || "").slice(0, 10);
    const endDate = (event.end || "").slice(0, 10);
    const startTime = fmtTime(event.start) || "09:00";
    const endTime = fmtTime(event.end) || "10:00";
    modalEvent.value = {
        id: event.id,
        summary: event.summary || "",
        description: event.description || "",
        location: event.location || "",
        start_date: startDate,
        start_time: startTime,
        end_date: endDate,
        end_time: endTime,
        all_day: event.all_day || false,
        color: event.color || null,
        property_id: event.property_id || null,
    };
    showModal.value = true;
}

async function saveEvent() {
    if (!modalEvent.value.summary.trim()) { toast("Titel erforderlich"); return; }
    saving.value = true;
    const e = modalEvent.value;
        try {
        if (modalMode.value === "create") {
            const r = await fetch(API.value + "&action=calendar_create", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ summary: e.summary, description: e.description, location: e.location, start_date: e.start_date, start_time: e.start_time, end_date: e.end_date || e.start_date, end_time: e.end_time, all_day: e.all_day, color: e.color, property_id: e.property_id, for_user_id: e.for_user_id }),
            });
            const d = await r.json();
            if (d.success) { toast("Termin erstellt"); showModal.value = false; await loadEvents(); }
            else toast(d.error || "Fehler");
        } else {
            const r = await fetch(API.value + "&action=calendar_update", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id: e.id, summary: e.summary, description: e.description, location: e.location, start_date: e.start_date, start_time: e.start_time, end_date: e.end_date || e.start_date, end_time: e.end_time, all_day: e.all_day, color: e.color, property_id: e.property_id }),
            });
            const d = await r.json();
            if (d.success) { toast("Termin aktualisiert"); showModal.value = false; await loadEvents(); }
            else toast(d.error || "Fehler");
        }
    } catch (err) { toast("Fehler: " + err.message); }
    saving.value = false;
}

async function deleteEvent() {
    if (!modalEvent.value.id) return;
    if (!confirm("Termin löschen?")) return;
    saving.value = true;
    try {
        const r = await fetch(API.value + "&action=calendar_delete", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: modalEvent.value.id }),
        });
        const d = await r.json();
        if (d.success) { toast("Termin gelöscht"); showModal.value = false; await loadEvents(); }
    } catch (e) { toast("Fehler: " + e.message); }
    saving.value = false;
}

// Watch for view/date changes
watch([viewRange], () => loadEvents(), { deep: true });

onMounted(async () => {
    await loadEvents();
    if (userType.value === "assistenz") loadBrokers();
});

const dayNames = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
const dayNamesFull = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag", "Sonntag"];
</script>

<template>
    <div class="px-4 py-6 space-y-4">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-[var(--muted)]">
                    <CalendarIcon class="w-5 h-5 text-[var(--muted-foreground)]" />
                </div>
                <div>
                    <h2 class="text-lg font-bold">{{ headerTitle }}</h2>
                    <p class="text-xs text-[var(--muted-foreground)]">
                        {{ events.length }} Termine

                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <!-- View toggle -->
                <div class="flex rounded-lg border border-[var(--border)] overflow-hidden">
                    <button @click="viewMode = 'week'" class="px-3 py-1.5 text-xs font-medium transition-colors" :class="viewMode === 'week' ? 'bg-[var(--foreground)] text-[var(--background)]' : 'bg-[var(--card)] text-[var(--muted-foreground)] hover:bg-[var(--accent)]'">Woche</button>
                    <button @click="viewMode = 'month'" class="px-3 py-1.5 text-xs font-medium transition-colors" :class="viewMode === 'month' ? 'bg-[var(--foreground)] text-[var(--background)]' : 'bg-[var(--card)] text-[var(--muted-foreground)] hover:bg-[var(--accent)]'">Monat</button>
                </div>
                <!-- Navigation -->
                <div class="flex items-center gap-1">
                    <button @click="navigate(-1)" class="btn btn-ghost btn-icon btn-sm"><ChevronLeft class="w-4 h-4" /></button>
                    <button @click="goToday()" class="btn btn-outline btn-sm">Heute</button>
                    <button @click="navigate(1)" class="btn btn-ghost btn-icon btn-sm"><ChevronRight class="w-4 h-4" /></button>
                </div>
                <!-- Actions -->

                <button @click="openCreateModal()" class="btn btn-primary btn-sm"><Plus class="w-3.5 h-3.5" /><span>Termin</span></button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading && !events.length" class="card p-12 text-center"><span class="spinner"></span></div>

        <!-- Week View -->
        <div v-else-if="viewMode === 'week'" class="card overflow-hidden">
            <!-- Day headers -->
            <div class="grid grid-cols-[60px_repeat(7,1fr)] border-b border-[var(--border)] min-w-[600px]">
                <div class="p-2"></div>
                <div v-for="(day, i) in weekDays" :key="i" class="p-2 text-center border-l border-[var(--border)]" :class="{ 'bg-[var(--brand-light)]': isToday(day) }">
                    <div class="text-[10px] font-medium text-[var(--muted-foreground)] uppercase">{{ dayNames[i] }}</div>
                    <div class="text-sm font-semibold" :class="isToday(day) ? 'text-[var(--brand)]' : ''">{{ day.getDate() }}</div>
                </div>
            </div>

            <!-- All-day row -->
            <div class="grid grid-cols-[60px_repeat(7,1fr)] border-b border-[var(--border)] min-w-[600px]" v-if="events.some(e => e.all_day)">
                <div class="p-1 text-[9px] text-[var(--muted-foreground)] text-right pr-2 pt-2">Ganztag</div>
                <div v-for="(day, i) in weekDays" :key="'ad'+i" class="p-1 border-l border-[var(--border)] min-h-[28px]">
                    <div v-for="ev in eventsForDay(day).filter(e => e.all_day)" :key="ev.id" @click.stop="openEditModal(ev)"
                        class="text-[10px] font-medium px-1.5 py-0.5 rounded cursor-pointer truncate mb-0.5"
                        :style="{ background: eventBg(ev) + '20', color: eventBg(ev) }">
                        {{ ev.summary }}
                    </div>
                </div>
            </div>

            <!-- Time grid -->
            <div class="overflow-y-auto" style="max-height:calc(100vh - 280px)">
                <div v-for="hour in hours" :key="hour" class="grid grid-cols-[60px_repeat(7,1fr)] min-w-[600px]">
                    <div class="text-[10px] text-[var(--muted-foreground)] text-right pr-2 pt-1 border-b border-[var(--border)]" style="height:48px">
                        {{ String(hour).padStart(2, '0') }}:00
                    </div>
                    <div v-for="(day, i) in weekDays" :key="'h'+hour+'d'+i"
                        @click="openCreateModal(day, hour)"
                        class="border-l border-b border-[var(--border)] relative cursor-pointer hover:bg-[var(--accent)] transition-colors"
                        :class="{ 'bg-[var(--brand-light)]': isToday(day) }"
                        style="height:48px">
                        <div v-for="ev in eventsForHour(day, hour)" :key="ev.id" @click.stop="openEditModal(ev)"
                            class="absolute inset-x-0.5 top-0.5 rounded px-1.5 py-0.5 text-[10px] font-medium cursor-pointer z-10 overflow-hidden"
                            :style="eventStyle(ev)">
                            <div class="flex items-center gap-1">
                                <Home v-if="ev.is_besichtigung" class="w-2.5 h-2.5 flex-shrink-0" />
                                <span class="truncate">{{ ev.summary }}</span>
                            </div>
                            <div class="text-[9px] opacity-75">{{ fmtTime(ev.start) }} – {{ fmtTime(ev.end) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Month View -->
        <div v-else class="card overflow-hidden">
            <!-- Day headers -->
            <div class="grid grid-cols-7 min-w-[280px]">
                <div v-for="name in dayNames" :key="name" class="p-2 text-center text-[10px] font-semibold text-[var(--muted-foreground)] uppercase border-b border-[var(--border)]">{{ name }}</div>
            </div>
            <!-- Days grid -->
            <div class="grid grid-cols-7 min-w-[280px]">
                <div v-for="(d, i) in monthDays" :key="i"
                    @click="openCreateModal(d.date)"
                    class="border-b border-r border-[var(--border)] p-1 min-h-[100px] cursor-pointer hover:bg-[var(--accent)] transition-colors"
                    :class="{ 'bg-[var(--brand-light)]': isToday(d.date), 'opacity-40': !d.currentMonth }">
                    <div class="text-xs font-medium mb-1" :class="isToday(d.date) ? 'text-[var(--brand)] font-bold' : 'text-[var(--foreground)]'">
                        {{ d.date.getDate() }}
                    </div>
                    <div class="space-y-0.5">
                        <div v-for="ev in eventsForDay(d.date).slice(0, 3)" :key="ev.id" @click.stop="openEditModal(ev)"
                            class="text-[10px] font-medium px-1.5 py-0.5 rounded cursor-pointer truncate"
                            :style="{ background: eventBg(ev) + '20', color: eventBg(ev) }">
                            <span v-if="!ev.all_day" class="opacity-70">{{ fmtTime(ev.start) }} </span>{{ ev.summary }}
                        </div>
                        <div v-if="eventsForDay(d.date).length > 3" class="text-[9px] text-[var(--muted-foreground)] px-1">
                            +{{ eventsForDay(d.date).length - 3 }} weitere
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Besichtigungen summary -->
        <div v-if="events.some(e => e.is_besichtigung)" class="card">
            <div class="px-6 py-3 border-b border-[var(--border)]">
                <h3 class="text-sm font-semibold flex items-center gap-2">
                    <Home class="w-4 h-4" style="color:#14b8a6" />
                    Besichtigungen
                </h3>
            </div>
            <div class="divide-y divide-[var(--border)]">
                <div v-for="ev in events.filter(e => e.is_besichtigung)" :key="ev.id" @click="openEditModal(ev)"
                    class="px-6 py-3 flex items-center gap-3 hover:bg-[var(--accent)] cursor-pointer transition-colors">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(20,184,166,0.1)">
                        <Home class="w-4 h-4" style="color:#14b8a6" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate">{{ ev.summary }}</div>
                        <div class="text-xs text-[var(--muted-foreground)]">
                            {{ new Date(ev.start).toLocaleDateString("de-AT", { weekday: "short", day: "numeric", month: "short" }) }}
                            <span v-if="!ev.all_day"> · {{ fmtTime(ev.start) }} – {{ fmtTime(ev.end) }}</span>
                            <span v-if="ev.stakeholder"> · {{ ev.stakeholder }}</span>
                        </div>
                    </div>
                    <span v-if="ev.property_id" class="badge badge-accent text-[10px]">Objekt #{{ ev.property_id }}</span>
                </div>
            </div>
        </div>

        <!-- Event Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showModal = false">
                <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showModal = false"></div>
                <div class="relative w-full max-w-lg rounded-2xl shadow-2xl bg-[var(--card)] border border-[var(--border)] mx-4 overflow-hidden">
                    <!-- Modal header -->
                    <div class="px-6 py-4 flex items-center justify-between border-b border-[var(--border)]">
                        <h3 class="text-base font-semibold">{{ modalMode === 'create' ? 'Neuer Termin' : 'Termin bearbeiten' }}</h3>
                        <button @click="showModal = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                    </div>
                    <!-- Modal body -->
                    <div class="px-6 py-4 space-y-4">
                        <!-- Title -->
                        <div>
                            <label class="form-label text-xs">Titel</label>
                            <input v-model="modalEvent.summary" class="form-input" placeholder="z.B. Besichtigung Hauptstr. 5" autofocus />
                        </div>
                        <!-- All day toggle -->
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="modalEvent.all_day" class="rounded border-[var(--border)]" />
                            <span class="text-sm">Ganztägig</span>
                        </label>
                        <!-- Date/Time -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="form-label text-xs">Startdatum</label>
                                <input v-model="modalEvent.start_date" type="date" class="form-input" />
                            </div>
                            <div v-if="!modalEvent.all_day">
                                <label class="form-label text-xs">Startzeit</label>
                                <input v-model="modalEvent.start_time" type="time" class="form-input" />
                            </div>
                            <div>
                                <label class="form-label text-xs">Enddatum</label>
                                <input v-model="modalEvent.end_date" type="date" class="form-input" />
                            </div>
                            <div v-if="!modalEvent.all_day">
                                <label class="form-label text-xs">Endzeit</label>
                                <input v-model="modalEvent.end_time" type="time" class="form-input" />
                            </div>
                        </div>
                        <!-- Location -->
                        <div>
                            <label class="form-label text-xs flex items-center gap-1"><MapPin class="w-3 h-3" /> Ort</label>
                            <input v-model="modalEvent.location" class="form-input" placeholder="Adresse..." />
                        </div>
                        <!-- Assign to broker (assistenz only) -->
                        <div v-if="userType === 'assistenz' && brokers.length">
                            <label class="form-label text-xs">Termin für Makler</label>
                            <select v-model="modalEvent.for_user_id" class="form-input">
                                <option :value="null">Mein Termin</option>
                                <option v-for="b in brokers" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                        <!-- Description -->
                        <div>
                            <label class="form-label text-xs flex items-center gap-1"><FileText class="w-3 h-3" /> Beschreibung</label>
                            <textarea v-model="modalEvent.description" class="form-textarea" rows="2" placeholder="Notizen..."></textarea>
                        </div>
                        <!-- Color -->
                        <div>
                            <label class="form-label text-xs">Farbe</label>
                            <div class="flex gap-2">
                                <button v-for="c in eventColors" :key="c.value || 'default'" @click="modalEvent.color = c.value"
                                    class="w-7 h-7 rounded-full border-2 transition-transform"
                                    :style="{ background: c.bg, borderColor: modalEvent.color === c.value ? 'var(--foreground)' : 'transparent' }"
                                    :class="{ 'scale-110': modalEvent.color === c.value }"
                                    :title="c.label">
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- Modal footer -->
                    <div class="px-6 py-4 flex items-center justify-between border-t border-[var(--border)]">
                        <div>
                            <button v-if="modalMode === 'edit'" @click="deleteEvent()" class="btn btn-ghost btn-sm" style="color:var(--destructive)" :disabled="saving">
                                <Trash2 class="w-3.5 h-3.5" /><span>Löschen</span>
                            </button>
                        </div>
                        <div class="flex gap-2">
                            <button @click="showModal = false" class="btn btn-outline btn-sm">Abbrechen</button>
                            <button @click="saveEvent()" class="btn btn-primary btn-sm" :disabled="saving">
                                <span v-if="saving" class="spinner" style="width:12px;height:12px"></span>
                                <Save v-else class="w-3.5 h-3.5" />
                                <span>{{ modalMode === 'create' ? 'Erstellen' : 'Speichern' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
