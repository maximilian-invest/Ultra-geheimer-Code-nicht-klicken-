<script setup>
import { ref, inject, onMounted, nextTick, computed } from "vue";
import { Sun as SunIcon, MailX, Clock, CalendarIcon, Home, Inbox, CheckSquare, CheckCircle, ChevronRight, BadgeCheck, Building, Plus, Sparkles, Trash2, X, Pencil, Save, PenLine, Timer } from "lucide-vue-next";
import VueApexCharts from "vue3-apexcharts";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    darkMode: { type: Boolean, default: false },
});

const API = inject("API");
const userName = inject("userName", ref("Admin"));
const toast = inject("toast");
const switchTab = inject("switchTab");
const unansweredCount = inject("unansweredCount");
const followupCount = inject("followupCount");
const unmatchedCount = inject("unmatchedCount");
const properties = inject("properties");

// Tasks
const tasks = ref([]);
const newTaskText = ref("");
const newTaskProperty = ref("");
const newTaskPriority = ref("medium");
const aiTodosLoading = ref(false);
const showAllTasks = ref(false);

// Quick Activity Add
const qaOpen = ref(false);
const qaProperty = ref("");
const qaActivity = ref("");
const qaDuration = ref("");
const qaCategory = ref("sonstiges");
const qaStakeholder = ref("");
const qaDate = ref(new Date().toISOString().slice(0, 10));
const qaTime = ref(new Date().toTimeString().slice(0, 5));
const qaSaving = ref(false);
const qaSuccess = ref(false);

async function qaSubmit() {
    if (!qaProperty.value || !qaActivity.value) { toast("Objekt und Aktivität sind Pflicht"); return; }
    qaSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=add_activity", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                property_id: qaProperty.value,
                activity: qaActivity.value,
                duration: qaDuration.value ? parseInt(qaDuration.value) : null,
                category: qaCategory.value,
                stakeholder: qaStakeholder.value,
                activity_date: qaDate.value,
                activity_time: qaTime.value,
            }),
        });
        const d = await r.json();
        if (d.success) {
            qaSuccess.value = true;
            toast("✓ Aktivität eingetragen" + (d.polished !== d.raw ? " (KI-optimiert)" : ""));
            qaActivity.value = "";
            qaDuration.value = "";
            qaStakeholder.value = "";
            setTimeout(() => { qaSuccess.value = false; }, 2000);
        } else {
            toast("Fehler: " + (d.error || "Unbekannt"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
    qaSaving.value = false;
}

async function loadSalesAndCommissions(period) {
    if (period) salesPeriod.value = period;
    try {
        const r = await fetch(API.value + '&action=get_sales_volume&period=' + salesPeriod.value);
        const data = await r.json();
        salesVolumeData.value = data;
        // Calculate commission data from units
        const r2 = await fetch(API.value + '&action=get_commission_summary');
        try {
            const cd = await r2.json();
            commissionData.value = cd;
        } catch {}
        // Load real kaufanbot PDFs
        const r3 = await fetch(API.value + '&action=get_kaufanbot_pdfs');
        try {
            const kd = await r3.json();
            realKaufanbote.value = kd.kaufanbote || [];
        } catch {}
    } catch {}
}

const newTaskDueDate = ref("");

// Edit task state
const editingTask = ref(null);
const editTaskTitle = ref("");
const editTaskDueDate = ref("");
const editTaskPriority = ref("medium");

// Kaufanbote
const kaufanboteStats = ref({ total: 0, monthly: [], details: [] });
const salesVolumeData = ref(null);
const commissionData = ref(null);
const commissionBrokerFilter = ref('all');
const salesBrokerFilter = ref('all');
const filteredSalesProperties = computed(() => {
    if (!salesVolumeData.value || !salesVolumeData.value.properties) return [];
    if (salesBrokerFilter.value === 'all') return salesVolumeData.value.properties;
    return salesVolumeData.value.properties.filter(p => p.broker_id == salesBrokerFilter.value);
});
const filteredSalesTotal = computed(() => {
    return filteredSalesProperties.value.reduce((s, p) => s + (p.volume || 0), 0);
});
const salesExpanded = ref(false);
const commissionExpanded = ref(false);
const filteredCommissionDetails = computed(() => {
    if (!commissionData.value || !commissionData.value.details) return [];
    if (commissionBrokerFilter.value === 'all') return commissionData.value.details;
    return commissionData.value.details.filter(d => d.broker_id == commissionBrokerFilter.value);
});
const filteredCommissionTotals = computed(() => {
    const details = filteredCommissionDetails.value;
    return {
        makler: details.reduce((s, d) => s + (d.makler_amount || 0), 0),
        gesamt: details.reduce((s, d) => s + (d.gesamt_amount || 0), 0),
    };
});
const showKaufanboteModal = ref(false);
const realKaufanbote = ref([]);
const showSalesModal = ref(false);
const showCommissionModal = ref(false);
const salesPeriod = ref("year");

// Dashboard charts
const perfData = ref(null);
const trendOptions = ref({});
const trendSeries = ref([]);
const platformOptions = ref({});
const platformSeries = ref([]);
const funnelOptions = ref({});
const funnelSeries = ref([]);
const responseOptions = ref({});
const responseSeries = ref([]);
const kaufanboteChartOptions = ref({});
const kaufanboteSeries = ref([]);
const chartsReady = ref(false);

// Proactive Alerts
const alerts = ref([]);
const alertsLoading = ref(false);

// Cross-property matches
const crossMatches = ref([]);
const matchesLoading = ref(false);

// Upcoming calendar events (next 7 days)
const upcomingEvents = ref([]);
const upcomingLoading = ref(false);

async function loadUpcoming() {
    upcomingLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=calendar_upcoming");
        const d = await r.json();
        upcomingEvents.value = d.events || [];
    } catch (e) { /* silent */ }
    upcomingLoading.value = false;
}

function fmtEventTime(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d)) {
        const parts = iso.split(" ");
        return parts[1] ? parts[1].slice(0, 5) : "";
    }
    return d.toLocaleTimeString("de-AT", { hour: "2-digit", minute: "2-digit" });
}

function fmtEventDay(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d)) return iso.slice(0, 10);
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);
    if (d.toDateString() === today.toDateString()) return "Heute";
    if (d.toDateString() === tomorrow.toDateString()) return "Morgen";
    return d.toLocaleDateString("de-AT", { weekday: "short", day: "numeric", month: "short" });
}

function getDismissedAlerts() {
    try {
        const raw = localStorage.getItem('dismissed_alerts');
        if (!raw) return {};
        const data = JSON.parse(raw);
        const now = Date.now();
        // Clean expired (>7 days)
        for (const key in data) {
            if (now - data[key] > 604800000) delete data[key];
        }
        localStorage.setItem('dismissed_alerts', JSON.stringify(data));
        return data;
    } catch { return {}; }
}

function dismissAlert(alertId) {
    const data = getDismissedAlerts();
    data[alertId] = Date.now();
    localStorage.setItem('dismissed_alerts', JSON.stringify(data));
    alerts.value = alerts.value.filter(a => a.id !== alertId);
}

const visibleAlerts = computed(() => {
    const dismissed = getDismissedAlerts();
    return alerts.value.filter(a => !dismissed[a.id]);
});

const greeting = computed(() => {
    const h = new Date().getHours();
    const d = new Date().getDay();
    const dayOfYear = Math.floor((Date.now() - new Date(new Date().getFullYear(), 0, 0)) / 86400000);
    const slot = Math.floor(h / 3); // changes every 3 hours
    const seed = dayOfYear * 8 + slot;
    const fn = (userName?.value || "Admin").split(" ")[0];

    const morningGreetings = [
        `Guten Morgen, ${fn}! Starte stark in den Tag.`,
        "Guten Morgen! Heute wird ein produktiver Tag.",
        `Guten Morgen, ${fn}! Neue Chancen warten.`,
        `Frisch ans Werk, ${fn}!`,
        "Guten Morgen! Zeit, Deals zu machen.",
        "Guten Morgen! Der Immobilienmarkt wartet auf dich.",
        `Los geht's, ${fn}! Was steht heute an?`,
    ];
    const dayGreetings = [
        `Hallo ${fn}! Weiter so, du bist gut unterwegs.`,
        `${fn}, die Halbzeit ist erreicht. Stark!`,
        `Hey ${fn}! Bleib dran, es läuft gut.`,
        "Hallo! Noch einige Stunden — mach das Beste draus.",
        `${fn}, jeder Anruf kann der nächste Deal sein.`,
        "Weiter geht's! Der Nachmittag gehört dir.",
        `Hallo ${fn}! Fokus halten, Erfolg ernten.`,
    ];
    const eveningGreetings = [
        `Guten Abend, ${fn}! Toller Einsatz heute.`,
        `Feierabend in Sicht! Gut gemacht, ${fn}.`,
        "Guten Abend! Zeit für den letzten Check.",
        `${fn}, ein erfolgreicher Tag geht zu Ende.`,
        `Entspann dich, ${fn}. Morgen geht's weiter!`,
    ];

    let pool;
    if (h < 12) pool = morningGreetings;
    else if (h < 18) pool = dayGreetings;
    else pool = eveningGreetings;

    return pool[seed % pool.length];
});

const openTaskCount = computed(() => tasks.value.filter((t) => !t.done).length);
const visibleTasks = computed(() => {
    const open = tasks.value.filter((t) => !t.done);
    return showAllTasks.value ? open : open.slice(0, 5);
});

onMounted(async () => {
    loadSalesAndCommissions();
    await Promise.all([loadTasks(), loadKaufanboteStats(), loadPerformance(), loadUpcoming()]);
});

async function loadTasks() {
    try {
        const r = await fetch(API.value + "&action=getTasks");
        const d = await r.json();
        tasks.value = d.tasks || [];
        if (!tasks.value.filter((t) => !t.done).length) generateAiTodos();
    } catch (e) { /* silent */ }
}

async function addTask() {
    if (!newTaskText.value.trim()) return;
    try {
        const r = await fetch(API.value + "&action=addTask", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ text: newTaskText.value.trim(), property_id: newTaskProperty.value || null, priority: newTaskPriority.value, due_date: newTaskDueDate.value || null }),
        });
        const d = await r.json();
        if (d.success && d.task) { tasks.value.unshift(d.task); newTaskText.value = ""; newTaskDueDate.value = ""; toast("Aufgabe hinzugefügt"); }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function completeTask(task) {
    task._completing = true;
    try {
        const r = await fetch(API.value + "&action=doneTask", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ task_id: task.id }),
        });
        const d = await r.json();
        if (d.success) { setTimeout(() => { task.done = 1; toast(d.activity_created ? "Erledigt + Aktivitat geloggt" : "Erledigt!"); }, 300); }
    } catch (e) { task._completing = false; toast("Fehler: " + e.message); }
}

async function updateTask() {
    if (!editingTask.value) return;
    try {
        const r = await fetch(API.value + "&action=update_task", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: editingTask.value.id, title: editTaskTitle.value.trim(), due_date: editTaskDueDate.value || null, priority: editTaskPriority.value }),
        });
        const d = await r.json();
        if (d.success && d.task) {
            const idx = tasks.value.findIndex(t => t.id === editingTask.value.id);
            if (idx !== -1) tasks.value[idx] = d.task;
            editingTask.value = null;
            toast("Aufgabe aktualisiert");
        }
    } catch (e) { toast("Fehler: " + e.message); }
}

function startEditTask(task) {
    editingTask.value = task;
    editTaskTitle.value = task.title || task.text || "";
    editTaskDueDate.value = task.due_date ? task.due_date.slice(0, 10) : "";
    editTaskPriority.value = task.priority || "medium";
}

async function deleteTask(task) {
    if (!confirm('Aufgabe "' + (task.title || task.text) + '" löschen?')) return;
    try {
        const r = await fetch(API.value + "&action=delete_task", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: task.id }),
        });
        const d = await r.json();
        if (d.success) { tasks.value = tasks.value.filter(t => t.id !== task.id); toast("Aufgabe gelöscht"); }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function generateAiTodos() {
    aiTodosLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=generateTodos", { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" });
        const d = await r.json();
        if (!d.error) {
            await loadTasks();
            const msg = d.generated ? d.generated + " Aufgaben generiert" + (d.replaced ? " (" + d.replaced + " alte ersetzt)" : "") : "Keine neuen Aufgaben";
            toast(msg);
        } else { toast("Fehler: " + d.error); }
    } catch (e) { toast("Fehler: " + e.message); }
    aiTodosLoading.value = false;
}

async function loadKaufanboteStats() {
    try {
        const from = new Date(new Date().setMonth(new Date().getMonth() - 11)).toISOString().slice(0, 7) + "-01";
        const to = new Date().toISOString().slice(0, 10);
        const r = await fetch(API.value + "&action=kaufanbote_stats&from=" + encodeURIComponent(from) + "&to=" + encodeURIComponent(to));
        kaufanboteStats.value = await r.json();
        buildKaufanboteChart();
    } catch (e) { /* silent */ }
}

function buildKaufanboteChart() {
    const months = kaufanboteStats.value.monthly || [];
    const names = ["Jan", "Feb", "Mar", "Apr", "Mai", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dez"];
    const labels = months.map((m) => { const [y, mo] = m.month.split("-"); return names[parseInt(mo) - 1] + " " + y.slice(2); });
    const values = months.map((m) => m.count);
    const isDark = props.darkMode;
    const textColor = isDark ? "#94a3b8" : "#64748b";
    kaufanboteChartOptions.value = {
        chart: { type: "bar", height: 250, toolbar: { show: false }, background: "transparent", fontFamily: "Inter, sans-serif" },
        xaxis: { categories: labels, labels: { style: { colors: textColor, fontSize: "11px" } } },
        yaxis: { labels: { style: { colors: textColor, fontSize: "11px" } }, forceNiceScale: true, min: 0 },
        colors: ["#10b981"],
        plotOptions: { bar: { borderRadius: 6, columnWidth: "50%" } },
        dataLabels: { enabled: true, style: { fontSize: "12px", fontWeight: 700, colors: [isDark ? "#f8fafc" : "#0f172a"] }, offsetY: -4 },
        grid: { borderColor: isDark ? "#1e293b" : "#e2e8f0", strokeDashArray: 4 },
        tooltip: { theme: isDark ? "dark" : "light" },
    };
    kaufanboteSeries.value = [{ name: "Kaufanbote", data: values }];
}

async function loadPerformance() {
    try {
        const r = await fetch(API.value + "&action=performance");
        perfData.value = await r.json();
        buildDashboardCharts();
    } catch (e) { /* silent */ }
}

async function loadAlerts() {
    alertsLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=proactive_alerts");
        const d = await r.json();
        alerts.value = d.alerts || [];
    } catch (e) { /* silent */ }
    alertsLoading.value = false;
}

async function loadCrossMatches() {
    matchesLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=cross_property_matches");
        const d = await r.json();
        crossMatches.value = d.matches || [];
    } catch (e) { /* silent */ }
    matchesLoading.value = false;
}

function alertActionClick(action) {
    if (action.tab) {
        switchTab(action.tab);
    } else if (action.property_id) {
        switchTab('properties');
    }
}

function buildDashboardCharts() {
    const d = perfData.value;
    if (!d) return;
    const isDark = props.darkMode;
    const accent = "#ee7606";
    const blue = "#3b82f6";
    const gridColor = isDark ? "#27272a" : "#f1f5f9";
    const textColor = isDark ? "#a1a1aa" : "#64748b";
    const bgCard = isDark ? "#09090b" : "#ffffff";

    // Trend
    if (d.weekly_trend) {
        const weeks = d.weekly_trend.map((w) => { const dt = new Date(w.week_start); return dt.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit" }); });
        trendSeries.value = [{ name: "Anfragen", data: d.weekly_trend.map((w) => parseInt(w.inquiries) || 0) }, { name: "Ausgehend", data: d.weekly_trend.map((w) => parseInt(w.outbound) || 0) }];
        trendOptions.value = {
            chart: { type: "area", height: 220, fontFamily: "Inter", toolbar: { show: false }, background: "transparent" },
            xaxis: { categories: weeks, labels: { style: { colors: textColor, fontSize: "10px" } }, axisBorder: { show: false }, axisTicks: { show: false } },
            colors: [accent, blue],
            fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 95, 100] } },
            stroke: { curve: "smooth", width: 2.5 },
            grid: { borderColor: gridColor, strokeDashArray: 4 },
            dataLabels: { enabled: false },
            legend: { position: "top", horizontalAlign: "right", fontSize: "11px", labels: { colors: textColor } },
            yaxis: { labels: { style: { fontSize: "10px", colors: [textColor] } } },
            tooltip: { theme: isDark ? "dark" : "light" },
        };
    }

    // Platform donut
    if (d.platforms && d.platforms.length) {
        platformSeries.value = d.platforms.map((p) => parseInt(p.count));
        platformOptions.value = {
            chart: { type: "donut", height: 220, fontFamily: "Inter", background: "transparent" },
            labels: d.platforms.map((p) => p.platform),
            colors: [accent, blue, "#10b981", "#8b5cf6", "#14b8a6"],
            plotOptions: { pie: { donut: { size: "72%", labels: { show: true, total: { show: true, label: "Gesamt", fontSize: "12px", fontWeight: 700, color: textColor }, value: { color: isDark ? "#fafafa" : "#09090b", fontSize: "20px", fontWeight: 800 } } } } },
            legend: { position: "bottom", fontSize: "11px", labels: { colors: textColor } },
            dataLabels: { enabled: false },
            stroke: { width: 2, colors: [bgCard] },
            tooltip: { theme: isDark ? "dark" : "light" },
        };
    }

    // Funnel
    if (d.funnel) {
        const f = d.funnel;
        funnelSeries.value = [{ name: "Anzahl", data: [f.total_leads || 0, f.viewing_requests || 0, f.viewings_done || 0, f.offers || 0] }];
        funnelOptions.value = {
            chart: { type: "bar", height: 200, fontFamily: "Inter", toolbar: { show: false }, background: "transparent" },
            xaxis: { categories: ["Leads", "Bes.-Anfr.", "Besichtigt", "Kaufanbote"], labels: { style: { colors: textColor, fontSize: "10px" } } },
            plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: "55%", distributed: true } },
            colors: [accent, blue, "#14b8a6", "#8b5cf6"],
            legend: { show: false },
            dataLabels: { enabled: true, style: { fontSize: "12px", fontWeight: 700, colors: ["#fff"] }, dropShadow: { enabled: false } },
            grid: { borderColor: gridColor, strokeDashArray: 3 },
            yaxis: { labels: { style: { fontSize: "10px", fontWeight: 500, colors: [textColor] } } },
            tooltip: { theme: isDark ? "dark" : "light" },
        };
    }

    // Response time
    const hrs = d.avg_response_hours || 0;
    const pct = Math.min(100, Math.round((1 - hrs / 48) * 100));
    const respColor = hrs > 48 ? "#ef4444" : hrs > 24 ? "#f59e0b" : "#10b981";
    responseSeries.value = [Math.max(0, pct)];
    responseOptions.value = {
        chart: { type: "radialBar", height: 200, fontFamily: "Inter", background: "transparent" },
        plotOptions: { radialBar: { hollow: { size: "65%", background: "transparent" }, track: { background: gridColor, strokeWidth: "100%" }, dataLabels: { name: { show: true, fontSize: "11px", color: textColor, offsetY: -10 }, value: { show: true, fontSize: "22px", fontWeight: 800, color: respColor, formatter: () => hrs.toFixed(1) + "h", offsetY: 4 } } } },
        labels: ["Antwortzeit"], colors: [respColor],
        stroke: { lineCap: "round" },
    };

    chartsReady.value = true;
}
</script>

<template>
    <div class="px-4 py-6 space-y-6">
        <!-- Greeting + Action Items -->
        <div class="card">
            <div class="px-6 py-4 flex items-center gap-3 border-b border-[var(--border)]">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-[var(--muted)]">
                    <SunIcon class="w-5 h-5 text-[var(--muted-foreground)]" />
                </div>
                <div class="flex-1">
                    <h2 class="text-base font-semibold">{{ greeting }}</h2>
                    <p class="text-xs text-[var(--muted-foreground)]">Hier ist dein Tagesüberblick.</p>
                </div>
            </div>
            <div>
                <!-- Unanswered -->
                <div v-if="unansweredCount > 0" @click="switchTab('priorities')" class="flex items-center gap-3 px-6 py-3 cursor-pointer transition-colors hover:bg-[var(--accent)] border-b border-[var(--border)]">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(239,68,68,0.1)"><MailX class="w-4 h-4" style="color:#ef4444" /></div>
                    <span class="text-[13px] font-medium flex-1">{{ unansweredCount }} unbeantwortete Anfrage{{ unansweredCount > 1 ? 'n' : '' }} bearbeiten</span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" style="background:rgba(239,68,68,0.1);color:#ef4444">Dringend</span>
                    <ChevronRight class="w-4 h-4 text-[var(--muted-foreground)]" />
                </div>
                <!-- Followups -->
                <div v-if="followupCount > 0" @click="switchTab('priorities')" class="flex items-center gap-3 px-6 py-3 cursor-pointer transition-colors hover:bg-[var(--accent)] border-b border-[var(--border)]">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(238,118,6,0.1)"><Clock class="w-4 h-4" style="color:#ee7606" /></div>
                    <span class="text-[13px] font-medium flex-1">{{ followupCount }} Kontakte zum Nachfassen</span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" style="background:rgba(238,118,6,0.1);color:#ee7606">Fallig</span>
                    <ChevronRight class="w-4 h-4 text-[var(--muted-foreground)]" />
                </div>
                <!-- Viewings -->
                <div v-if="stats.viewings_today > 0" @click="switchTab('properties')" class="flex items-center gap-3 px-6 py-3 cursor-pointer transition-colors hover:bg-[var(--accent)] border-b border-[var(--border)]">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(20,184,166,0.1)"><Home class="w-4 h-4" style="color:#14b8a6" /></div>
                    <span class="text-[13px] font-medium flex-1">{{ stats.viewings_today }} Besichtigung{{ stats.viewings_today > 1 ? 'en' : '' }} heute</span>
                    <ChevronRight class="w-4 h-4 text-[var(--muted-foreground)]" />
                </div>
                <!-- Inbox -->
                <div v-if="unmatchedCount > 0" @click="switchTab('comms')" class="flex items-center gap-3 px-6 py-3 cursor-pointer transition-colors hover:bg-[var(--accent)] border-b border-[var(--border)]">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(59,130,246,0.1)"><Inbox class="w-4 h-4" style="color:#3b82f6" /></div>
                    <span class="text-[13px] font-medium flex-1">{{ unmatchedCount }} E-Mail{{ unmatchedCount > 1 ? 's' : '' }} im Posteingang zuordnen</span>
                    <ChevronRight class="w-4 h-4 text-[var(--muted-foreground)]" />
                </div>
                <!-- Open tasks -->
                <div v-if="openTaskCount > 0" @click="switchTab('priorities')" class="flex items-center gap-3 px-6 py-3 cursor-pointer transition-colors hover:bg-[var(--accent)] border-b border-[var(--border)]">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(16,185,129,0.1)"><CheckSquare class="w-4 h-4" style="color:#10b981" /></div>
                    <span class="text-[13px] font-medium flex-1">{{ openTaskCount }} offene Aufgabe{{ openTaskCount > 1 ? 'n' : '' }}</span>
                    <ChevronRight class="w-4 h-4 text-[var(--muted-foreground)]" />
                </div>
                <!-- All done -->
                <div v-if="unansweredCount === 0 && followupCount === 0 && unmatchedCount === 0 && openTaskCount === 0" class="flex items-center gap-3 px-6 py-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(16,185,129,0.1)"><CheckCircle class="w-4 h-4" style="color:#10b981" /></div>
                    <span class="text-[13px] font-medium" style="color:#10b981">Alles erledigt! Keine offenen Aufgaben.</span>
                </div>
            </div>
        </div>

        <!-- Stat Tiles -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div @click="switchTab('priorities')" class="stat-tile">
                <div class="flex items-center justify-between"><p class="text-sm font-medium text-[var(--muted-foreground)]">Unbeantwortet</p><MailX class="w-4 h-4 text-[var(--muted-foreground)]" /></div>
                <p class="text-2xl font-bold font-display">{{ unansweredCount || '0' }}</p>
            </div>
            <div @click="switchTab('priorities')" class="stat-tile">
                <div class="flex items-center justify-between"><p class="text-sm font-medium text-[var(--muted-foreground)]">Nachfassen</p><Clock class="w-4 h-4 text-[var(--muted-foreground)]" /></div>
                <p class="text-2xl font-bold font-display">{{ followupCount || '0' }}</p>
            </div>
            <div @click="showKaufanboteModal = true" class="stat-tile">
                <div class="flex items-center justify-between"><p class="text-sm font-medium text-[var(--muted-foreground)]">Kaufanbote</p><BadgeCheck class="w-4 h-4 text-[var(--muted-foreground)]" /></div>
                <p class="text-2xl font-bold font-display">{{ realKaufanbote.length || kaufanboteStats.total || '0' }}</p>
            </div>

                <!-- Verkaufsvolumen Tile -->
                <div class="stat-tile" @click="showSalesModal = true" style="cursor:pointer">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl" style="background:rgba(238,118,6,0.1)">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" style="color:#ee7606" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[10px] font-medium text-[var(--muted-foreground)]">Verkaufsvolumen (Jahr)</p>
                            <p class="text-xl font-bold font-display whitespace-nowrap" v-if="salesVolumeData" style="color:#ee7606">&euro; {{ Number(salesVolumeData.total_volume || 0).toLocaleString('de-DE') }}</p>
                            <p class="text-xl font-bold font-display" v-else style="color:var(--muted-foreground)">&ndash;</p>
                        </div>

                    </div>
                </div>

                <!-- Provisionen Tile -->
                <div class="stat-tile" @click="showCommissionModal = true" style="cursor:pointer">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl" style="background:rgba(16,185,129,0.1)">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" style="color:#10b981" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[10px] font-medium text-[var(--muted-foreground)]">Provisionen (Makler)</p>
                            <p class="text-xl font-bold font-display whitespace-nowrap" v-if="commissionData" style="color:#10b981">&euro; {{ Number(commissionData.total_makler || 0).toLocaleString('de-DE') }}</p>
                            <p class="text-xl font-bold font-display" v-else style="color:var(--muted-foreground)">&ndash;</p>
                        </div>

                    </div>
                </div>

                

            <div @click="switchTab('comms')" class="stat-tile">
                <div class="flex items-center justify-between"><p class="text-sm font-medium text-[var(--muted-foreground)]">Posteingang</p><Inbox class="w-4 h-4 text-[var(--muted-foreground)]" /></div>
                <p class="text-2xl font-bold font-display">{{ unmatchedCount || '0' }}</p>
            </div>
            <div @click="switchTab('properties')" class="stat-tile">
                <div class="flex items-center justify-between"><p class="text-sm font-medium text-[var(--muted-foreground)]">Objekte</p><Building class="w-4 h-4 text-[var(--muted-foreground)]" /></div>
                <p class="text-2xl font-bold font-display">{{ stats.properties || '0' }}</p>
            </div>
        </div>

        <!-- Upcoming Events -->
        <div v-if="upcomingEvents.length" class="card">
            <div class="px-6 py-3 flex items-center justify-between border-b border-[var(--border)]">
                <h3 class="text-sm font-semibold flex items-center gap-2">
                    <CalendarIcon class="w-4 h-4 text-[var(--muted-foreground)]" />
                    Termine diese Woche
                </h3>
                <button @click="switchTab('calendar')" class="btn btn-ghost btn-sm text-xs">Alle anzeigen</button>
            </div>
            <div class="divide-y divide-[var(--border)]">
                <div v-for="ev in upcomingEvents.slice(0, 5)" :key="ev.id" @click="switchTab('calendar')"
                    class="px-6 py-2.5 flex items-center gap-3 hover:bg-[var(--accent)] cursor-pointer transition-colors">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                        :style="'background:' + (ev.is_besichtigung ? 'rgba(20,184,166,0.1)' : 'rgba(238,118,6,0.1)')">
                        <Home v-if="ev.is_besichtigung" class="w-4 h-4" style="color:#14b8a6" />
                        <CalendarIcon v-else class="w-4 h-4" style="color:#ee7606" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate">{{ ev.summary }}</div>
                        <div class="text-xs text-[var(--muted-foreground)]">
                            {{ fmtEventDay(ev.start) }}
                            <span v-if="!ev.all_day"> · {{ fmtEventTime(ev.start) }} – {{ fmtEventTime(ev.end) }}</span>
                            <span v-if="ev.location"> · {{ ev.location }}</span>
                        </div>
                    </div>
                    <span v-if="ev.is_besichtigung" class="badge badge-success text-[10px]">Besichtigung</span>
                </div>
            </div>
        </div>

                <!-- Dashboard Charts -->
        <div v-if="chartsReady" class="grid grid-cols-1 gap-4 lg:grid-cols-7">
            <div class="lg:col-span-4 card">
                <div class="px-6 py-3"><h3 class="text-sm font-semibold">Anfragen-Trend (8 Wochen)</h3></div>
                <div class="px-4 pb-2"><VueApexCharts type="area" :options="trendOptions" :series="trendSeries" height="220" /></div>
            </div>
            <div class="lg:col-span-3 card">
                <div class="px-6 py-3"><h3 class="text-sm font-semibold">Plattform-Verteilung</h3></div>
                <div class="px-4 pb-2"><VueApexCharts type="donut" :options="platformOptions" :series="platformSeries" height="220" /></div>
            </div>
        </div>
        <div v-if="chartsReady" class="grid grid-cols-1 gap-4 lg:grid-cols-7">
            <div class="lg:col-span-4 card">
                <div class="px-6 py-3"><h3 class="text-sm font-semibold">Verkaufstrichter</h3></div>
                <div class="px-4 pb-2"><VueApexCharts type="bar" :options="funnelOptions" :series="funnelSeries" height="200" /></div>
            </div>
            <div class="lg:col-span-3 card">
                <div class="px-6 py-3"><h3 class="text-sm font-semibold">Antwortzeit</h3></div>
                <div class="px-4 pb-2"><VueApexCharts type="radialBar" :options="responseOptions" :series="responseSeries" height="200" /></div>
            </div>
        </div>

        <!-- Tasks -->
        <div class="card">
            <div class="px-6 py-3 flex items-center justify-between border-b border-[var(--border)]">
                <h3 class="text-sm font-semibold">Aufgaben</h3>
                <div class="flex items-center gap-2">
                    <button @click="generateAiTodos()" :disabled="aiTodosLoading" class="btn btn-outline btn-sm">
                        <span v-if="aiTodosLoading" class="spinner" style="width:12px;height:12px"></span>
                        <Sparkles v-else class="w-3.5 h-3.5" />
                        <span>KI Todos</span>
                    </button>
                </div>
            </div>
            <div class="px-6 py-3">
                <div class="flex flex-wrap gap-2 mb-3">
                    <input v-model="newTaskText" @keyup.enter="addTask()" class="form-input flex-1" placeholder="Neue Aufgabe..." style="min-width:160px" />
                    <select v-model="newTaskProperty" class="form-select" style="width:auto;min-width:120px">
                        <option value="">Kein Objekt</option>
                        <option v-for="p in properties" :key="p.id" :value="p.id">{{ p.address }}</option>
                    </select>
                    <input v-model="newTaskDueDate" type="date" class="form-input" style="width:auto" title="Fälligkeitsdatum" />
                    <select v-model="newTaskPriority" class="form-select" style="width:auto">
                        <option value="low">Niedrig</option>
                        <option value="medium">Mittel</option>
                        <option value="high">Hoch</option>
                        <option value="critical">Kritisch</option>
                    </select>
                    <button @click="addTask()" class="btn btn-primary btn-sm"><Plus class="w-3.5 h-3.5" /></button>
                </div>
                <div class="space-y-1">
                    <div v-for="task in visibleTasks" :key="task.id">
                        <!-- View mode -->
                        <div v-if="editingTask?.id !== task.id" class="flex items-center gap-2 py-2 px-2 rounded-lg hover:bg-[var(--accent)] transition-colors group">
                            <button @click="completeTask(task)" class="w-5 h-5 rounded border border-[var(--border)] flex items-center justify-center flex-shrink-0 hover:border-green-500 transition-colors" :class="{ 'opacity-50': task._completing }">
                                <CheckCircle v-if="task.done" class="w-3.5 h-3.5 text-green-500" />
                            </button>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm" :class="{ 'line-through text-[var(--muted-foreground)]': task.done }">{{ task.title || task.text }}</span>
                                <div v-if="task.due_date" class="text-[10px] text-[var(--muted-foreground)] mt-0.5">
                                    <CalendarIcon class="w-3 h-3 inline mr-0.5" />
                                    {{ new Date(task.due_date).toLocaleDateString('de-AT') }}
                                </div>
                            </div>
                            <span v-if="task.address" class="text-[10px] px-1.5 py-0.5 rounded bg-[var(--muted)] text-[var(--muted-foreground)] hidden sm:block">{{ task.address }}</span>
                            <span v-if="task.priority && task.priority !== 'medium'" class="text-[10px] px-1.5 py-0.5 rounded" :class="{
                                'bg-red-100 text-red-700': task.priority === 'critical',
                                'bg-orange-100 text-orange-700': task.priority === 'high',
                                'bg-blue-100 text-blue-700': task.priority === 'low',
                            }">{{ task.priority }}</span>
                            <div class="flex gap-1 opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                                <button @click="startEditTask(task)" class="btn btn-ghost btn-icon btn-sm"><Pencil class="w-3.5 h-3.5" /></button>
                                <button @click="deleteTask(task)" class="btn btn-ghost btn-icon btn-sm" style="color:var(--destructive)"><Trash2 class="w-3.5 h-3.5" /></button>
                            </div>
                        </div>
                        <!-- Edit mode inline -->
                        <!-- Kaufanbote Detail Modal -->
        <div v-if="showKaufanboteModal" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showKaufanboteModal = false">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showKaufanboteModal = false"></div>
            <div class="relative bg-[var(--card)] rounded-2xl shadow-2xl w-full max-w-xl mx-4 max-h-[85vh] overflow-hidden border border-[var(--border)]">
                <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border)">
                    <div>
                        <h2 class="text-lg font-bold">Kaufanbote</h2>
                        <p class="text-xs text-[var(--muted-foreground)]">{{ realKaufanbote.length }} Kaufanbot{{ realKaufanbote.length !== 1 ? 'e' : '' }} hochgeladen</p>
                    </div>
                    <button @click="showKaufanboteModal = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                </div>
                <div class="overflow-y-auto" style="max-height:calc(85vh - 80px)">
                    <div v-if="!realKaufanbote.length" class="px-6 py-12 text-center text-sm text-[var(--muted-foreground)]">Noch keine Kaufanbote hochgeladen.</div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <div v-for="ka in realKaufanbote" :key="ka.unit_id" class="px-6 py-4">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-semibold">{{ ka.buyer_name || 'Unbekannt' }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :style="ka.status === 'verkauft' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : ka.status === 'reserviert' ? 'background:rgba(245,158,11,0.08);color:#f59e0b' : 'background:rgba(16,185,129,0.08);color:#10b981'">{{ ka.status }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-[var(--muted-foreground)]">
                                <span>{{ ka.property_address }} &middot; {{ ka.unit_number }}</span>
                                <span class="font-bold" style="color:#ee7606">&euro; {{ Number(ka.total_price || 0).toLocaleString('de-DE') }}</span>
                            </div>
                            <a :href="'/storage/' + ka.kaufanbot_pdf" target="_blank" class="inline-flex items-center gap-1 mt-2 text-[11px] font-medium px-2.5 py-1 rounded-lg" style="color:#ee7606;background:rgba(238,118,6,0.06)">PDF anzeigen</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
                    </div>
                </div>
                <button v-if="openTaskCount > 5" @click="showAllTasks = !showAllTasks" class="text-xs text-[var(--muted-foreground)] mt-2 hover:text-[var(--foreground)]">
                    {{ showAllTasks ? 'Weniger anzeigen' : `Alle ${openTaskCount} anzeigen` }}
                </button>
            </div>
        </div>

        <!-- Kaufanbote Detail Modal -->
        <Teleport to="body">
            <div v-if="showKaufanboteModal" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showKaufanboteModal = false">
                <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showKaufanboteModal = false"></div>
                <div class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl bg-[var(--card)] border border-[var(--border)] mx-4">
                    <!-- Header -->
                    <div class="sticky top-0 z-10 px-6 py-4 flex items-center justify-between border-b border-[var(--border)] bg-[var(--card)] rounded-t-2xl">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(16,185,129,0.1)">
                                <BadgeCheck class="w-5 h-5" style="color:#10b981" />
                            </div>
                            <div>
                                <h2 class="text-lg font-bold">Kaufanbote Übersicht</h2>
                                <p class="text-xs text-[var(--muted-foreground)]">{{ kaufanboteStats.total }} Kaufanbot{{ kaufanboteStats.total !== 1 ? 'e' : '' }} gesamt</p>
                            </div>
                        </div>
                        <button @click="showKaufanboteModal = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)] transition-colors">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <!-- Monthly Chart -->
                    <div v-if="kaufanboteSeries.length" class="px-6 pt-4 pb-2">
                        <h3 class="text-sm font-semibold mb-2">Monatliche Entwicklung</h3>
                        <VueApexCharts type="bar" :options="kaufanboteChartOptions" :series="kaufanboteSeries" height="220" />
                    </div>

                    <!-- Persons List -->
                    <div v-if="kaufanboteStats.persons && kaufanboteStats.persons.length" class="px-6 py-4">
                        <h3 class="text-sm font-semibold mb-3">Personen mit Kaufanboten</h3>
                        <div class="space-y-3">
                            <div v-for="person in kaufanboteStats.persons" :key="person.surname_key" class="rounded-xl border border-[var(--border)] p-4 hover:bg-[var(--accent)] transition-colors">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-sm font-semibold">{{ person.display_name }}</span>
                                            <span v-if="person.has_absage" class="text-[9px] font-bold px-1.5 py-0.5 rounded-full" style="background:rgba(239,68,68,0.1);color:#ef4444">Absage</span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--muted-foreground)]">
                                            <span v-if="person.email">{{ person.email }}</span>
                                            <span v-if="person.phone">{{ person.phone }}</span>
                                        </div>
                                        <!-- Properties -->
                                        <div v-for="prop in person.properties" :key="prop.property_id + prop.date" class="mt-2 flex items-center gap-2 text-xs">
                                            <span class="badge badge-muted text-[10px]">{{ prop.ref_id }}</span>
                                            <span class="text-[var(--muted-foreground)]">{{ prop.address }}, {{ prop.city }}</span>
                                            <span class="text-[var(--muted-foreground)]">{{ new Date(prop.date).toLocaleDateString('de-AT') }}</span>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="text-[10px] text-[var(--muted-foreground)]">{{ new Date(person.last_date).toLocaleDateString('de-AT') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Details List (fallback if no persons) -->
                    <div v-else-if="kaufanboteStats.details && kaufanboteStats.details.length" class="px-6 py-4">
                        <h3 class="text-sm font-semibold mb-3">Kaufanbot Details</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[10px] text-[var(--muted-foreground)] uppercase tracking-wider">
                                        <th class="pb-2 pr-4">Name</th>
                                        <th class="pb-2 pr-4">Objekt</th>
                                        <th class="pb-2 pr-4">Datum</th>
                                        <th class="pb-2 pr-4">E-Mail</th>
                                        <th class="pb-2">Telefon</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[var(--border)]">
                                    <tr v-for="d in kaufanboteStats.details" :key="d.surname_key" class="hover:bg-[var(--accent)] transition-colors">
                                        <td class="py-2 pr-4 font-medium">{{ d.display_name }}</td>
                                        <td class="py-2 pr-4"><span class="badge badge-muted text-[10px]">{{ d.properties?.[0]?.ref_id }}</span></td>
                                        <td class="py-2 pr-4 text-[var(--muted-foreground)]">{{ new Date(d.last_date).toLocaleDateString('de-AT') }}</td>
                                        <td class="py-2 pr-4 text-[var(--muted-foreground)]">{{ d.email || '-' }}</td>
                                        <td class="py-2 text-[var(--muted-foreground)]">{{ d.phone || '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-else class="px-6 py-12 text-center text-[var(--muted-foreground)] text-sm">
                        Keine Kaufanbote vorhanden.
                    </div>
                </div>
            </div>
        </Teleport>
    </div>

    <!-- Verkaufsvolumen Modal -->
    <div v-if="showSalesModal" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showSalesModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showSalesModal = false"></div>
        <div class="relative bg-[var(--card)] rounded-2xl shadow-2xl w-full max-w-xl mx-4 max-h-[85vh] overflow-hidden border border-[var(--border)]">
            <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border)">
                <div>
                    <h2 class="text-lg font-bold">Verkaufsvolumen</h2>
                    <p class="text-xs text-[var(--muted-foreground)]" v-if="salesVolumeData">{{ salesVolumeData.total_sold }} von {{ salesVolumeData.total_units }} Einheiten verkauft</p>
                </div>
                <button @click="showSalesModal = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
            </div>
            <div class="px-6 py-3 flex items-center gap-2" style="border-bottom:1px solid var(--border)">
                <button v-for="p in [{k:'week',l:'Woche'},{k:'month',l:'Monat'},{k:'year',l:'Jahr'},{k:'all',l:'Alle'}]" :key="p.k"
                    @click="loadSalesAndCommissions(p.k)"
                    class="px-3 py-1.5 text-[11px] font-medium rounded-lg transition-colors"
                    :style="(salesPeriod === p.k) ? 'background:#ee7606;color:white' : 'background:var(--muted);color:var(--foreground)'">{{ p.l }}</button>
            </div>
            <div class="overflow-y-auto px-6 py-4 space-y-3" style="max-height:calc(85vh - 130px)">

                <div class="text-center py-2">
                    <div class="text-3xl font-bold" style="color:#ee7606">&euro; {{ Number(salesVolumeData?.total_volume || 0).toLocaleString('de-DE') }}</div>
                </div>
                <div v-for="prop in filteredSalesProperties" :key="prop.property_id" class="rounded-xl p-3" style="background:var(--muted)">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold truncate flex-1">{{ prop.address }}</span>
                        <span class="text-sm font-bold ml-2 whitespace-nowrap" style="color:#ee7606">&euro; {{ Number(prop.volume || 0).toLocaleString('de-DE') }}</span>
                    </div>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-[10px] text-[var(--muted-foreground)]">{{ prop.city }} &middot; {{ prop.all_sold }}/{{ prop.total }} verkauft &middot; {{ Math.round((prop.sold_area / (prop.total_area || 1)) * 100) }}% m&sup2;</span>
                        <span class="text-[10px] font-semibold" style="color:#10b981" v-if="prop.commission_makler_amount">&euro; {{ Number(prop.commission_makler_amount).toLocaleString('de-DE') }} Prov.</span>
                    </div>
                    <div v-if="prop.sold_entries && prop.sold_entries.length" class="mt-2 space-y-1">
                        <div v-for="e in prop.sold_entries" :key="e.unit_number" class="flex items-center justify-between text-[10px] text-[var(--muted-foreground)] pl-2" style="border-left:2px solid var(--border)">
                            <span>{{ e.unit_number }} <span v-if="e.buyer_name" class="font-medium">&middot; {{ e.buyer_name }}</span></span>
                            <span class="font-medium">&euro; {{ Number(e.total_price || 0).toLocaleString('de-DE') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Provisionen Modal -->
    <div v-if="showCommissionModal" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showCommissionModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showCommissionModal = false"></div>
        <div class="relative bg-[var(--card)] rounded-2xl shadow-2xl w-full max-w-xl mx-4 max-h-[85vh] overflow-hidden border border-[var(--border)]">
            <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border)">
                <div>
                    <h2 class="text-lg font-bold">Provisionen</h2>
                    <p class="text-xs text-[var(--muted-foreground)]" v-if="commissionData">{{ commissionData.properties_with_commission || 0 }} Objekte mit Provision</p>
                </div>
                <button @click="showCommissionModal = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
            </div>
            <div class="overflow-y-auto px-6 py-4 space-y-3" style="max-height:calc(85vh - 80px)">


                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="text-center p-3 rounded-xl" style="background:var(--muted)">
                        <div class="text-xl font-bold" style="color:#10b981">&euro; {{ Number(commissionBrokerFilter === 'all' ? (commissionData?.total_makler || 0) : filteredCommissionTotals.makler).toLocaleString('de-DE') }}</div>
                        <div class="text-[10px] text-[var(--muted-foreground)]">Makler-Anteil</div>
                    </div>
                    <div class="text-center p-3 rounded-xl" style="background:var(--muted)">
                        <div class="text-xl font-bold">&euro; {{ Number(commissionBrokerFilter === 'all' ? (commissionData?.total_gesamt || 0) : filteredCommissionTotals.gesamt).toLocaleString('de-DE') }}</div>
                        <div class="text-[10px] text-[var(--muted-foreground)]">Gesamt-Provision</div>
                    </div>
                </div>

                <div class="text-[11px] font-semibold text-[var(--muted-foreground)] uppercase tracking-wider mb-2">Pro Objekt</div>
                <div v-for="d in filteredCommissionDetails" :key="d.property_id" class="rounded-xl p-3" style="background:var(--muted)">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold truncate flex-1">{{ d.address }}</span>
                        <span class="text-sm font-bold ml-2 whitespace-nowrap" style="color:#10b981">&euro; {{ Number(d.makler_amount || 0).toLocaleString('de-DE') }}</span>
                    </div>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-[10px] text-[var(--muted-foreground)]">Volumen: &euro; {{ Number(d.volume || 0).toLocaleString('de-DE') }}</span>
                        <span class="text-[10px] text-[var(--muted-foreground)]">{{ d.commission_total }}% gesamt &middot; {{ d.commission_makler }}% Makler</span>
                    </div>
                </div>
                <div v-if="!filteredCommissionDetails.length" class="text-center py-8 text-sm text-[var(--muted-foreground)]">
                    Keine Provisionen hinterlegt. Provisionen k&ouml;nnen im Objekt unter &bdquo;Eigent&uuml;mer&rdquo; eingestellt werden.
                </div>
            </div>
        </div>
    </div>

</template>
