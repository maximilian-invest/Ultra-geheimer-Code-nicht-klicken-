<script setup>
import { ref, inject, onMounted, computed } from "vue";
import {
    Sun as SunIcon, MailX, Clock, CalendarIcon, Home, Inbox,
    CheckSquare, CheckCircle, ChevronRight, BadgeCheck, Building,
    Plus, Sparkles, Trash2, X, Pencil, DollarSign, Wallet,
    TrendingUp, Timer
} from "lucide-vue-next";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import TagesbriefingCard from "@/Components/Admin/TagesbriefingCard.vue";
import TagesbriefingSheet from "@/Components/Admin/TagesbriefingSheet.vue";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    darkMode: { type: Boolean, default: false },
});

const API = inject("API");
const userType = inject("userType", ref("admin"));
const isAdmin = inject("isAdmin", ref(true));
const userName = inject("userName", ref("Admin"));
const toast = inject("toast");
const switchTab = inject("switchTab");
const unansweredCount = inject("unansweredCount");
const followupCount = inject("followupCount");
const unmatchedCount = inject("unmatchedCount");
const properties = inject("properties");

// ===== Tagesbriefing =====
const briefingData = ref(null);
const briefingLoading = ref(true);
const briefingOpen = ref(false);
const briefingDate = ref(new Date().toISOString().slice(0, 10));

async function loadBriefing() {
    briefingLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=briefing_get&date=" + briefingDate.value);
        const d = await r.json();
        if (d.success) briefingData.value = d.briefing;
    } catch (e) {
        console.error("Briefing load failed:", e);
    } finally {
        briefingLoading.value = false;
    }
}

async function regenerateBriefing() {
    briefingLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=briefing_regenerate", { method: "POST" });
        const d = await r.json();
        if (d.success) {
            briefingData.value = d.briefing;
            toast("Briefing aktualisiert");
        } else if (d.rate_limited) {
            toast("Zu schnell — bitte kurz warten");
        } else {
            toast("Fehler: " + (d.error || "Unbekannt"));
        }
    } catch (e) {
        toast("Fehler beim Regenerieren");
    } finally {
        briefingLoading.value = false;
    }
}

function openBriefingConversation(convId) {
    briefingOpen.value = false;
    setTimeout(() => {
        switchTab("inbox");
        window.dispatchEvent(new CustomEvent("open-conversation", { detail: { convId } }));
    }, 100);
}

function openBriefingViewing(propertyId) {
    briefingOpen.value = false;
    setTimeout(() => switchTab("properties"), 100);
}

function openBriefingTask(taskId) {
    briefingOpen.value = false;
    setTimeout(() => switchTab("tasks"), 100);
}

// Tasks (kept for future re-enable)
const tasks = ref([]);
const newTaskText = ref("");
const newTaskProperty = ref("");
const newTaskPriority = ref("medium");
const aiTodosLoading = ref(false);
const showAllTasks = ref(false);
const newTaskDueDate = ref("");
const editingTask = ref(null);
const editTaskTitle = ref("");
const editTaskDueDate = ref("");
const editTaskPriority = ref("medium");

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
                property_id: qaProperty.value, activity: qaActivity.value,
                duration: qaDuration.value ? parseInt(qaDuration.value) : null,
                category: qaCategory.value, stakeholder: qaStakeholder.value,
                activity_date: qaDate.value, activity_time: qaTime.value,
            }),
        });
        const d = await r.json();
        if (d.success) {
            qaSuccess.value = true;
            toast("Aktivität eingetragen" + (d.polished !== d.raw ? " (KI-optimiert)" : ""));
            qaActivity.value = ""; qaDuration.value = ""; qaStakeholder.value = "";
            setTimeout(() => { qaSuccess.value = false; }, 2000);
        } else { toast("Fehler: " + (d.error || "Unbekannt")); }
    } catch (e) { toast("Fehler: " + e.message); }
    qaSaving.value = false;
}

// Kaufanbote & Sales
const kaufanboteStats = ref({ total: 0, monthly: [], details: [] });
const salesVolumeData = ref(null);
const commissionData = ref(null);
const commissionBrokerFilter = ref('all');
const salesBrokerFilter = ref('all');
const realKaufanbote = ref([]);
const showKaufanboteModal = ref(false);
const showSalesModal = ref(false);
const showCommissionModal = ref(false);
const salesPeriod = ref("year");

const filteredSalesProperties = computed(() => {
    if (!salesVolumeData.value?.properties) return [];
    if (salesBrokerFilter.value === 'all') return salesVolumeData.value.properties;
    return salesVolumeData.value.properties.filter(p => p.broker_id == salesBrokerFilter.value);
});
const filteredSalesTotal = computed(() => filteredSalesProperties.value.reduce((s, p) => s + (p.volume || 0), 0));
const salesExpanded = ref(false);
const commissionExpanded = ref(false);
const filteredCommissionDetails = computed(() => {
    if (!commissionData.value?.details) return [];
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

async function loadSalesAndCommissions(period) {
    if (period) salesPeriod.value = period;
    try {
        const r = await fetch(API.value + '&action=get_sales_volume&period=' + salesPeriod.value);
        salesVolumeData.value = await r.json();
        try { commissionData.value = await (await fetch(API.value + '&action=get_commission_summary')).json(); } catch {}
        try { const kd = await (await fetch(API.value + '&action=get_kaufanbot_pdfs')).json(); realKaufanbote.value = kd.kaufanbote || []; } catch {}
    } catch {}
}

// Charts — data refs
const perfData = ref(null);
const chartsReady = ref(false);

// Chart data
const trendData = ref([]);
const platformData = ref([]);
const funnelData = ref([]);
const hoveredTrend = ref(null);
const hoveredPlatform = ref(null);
const hoveredFunnel = ref(null);
const trendMax = computed(() => Math.max(...trendData.value.map(t => Math.max(t.inquiries, t.outbound)), 1));
const platformMax = computed(() => Math.max(...platformData.value.map(p => p.count), 1));
const platformTotal = computed(() => platformData.value.reduce((s, p) => s + p.count, 0));
// Donut arc path calculator
function donutArc(index) {
    const total = platformData.value.reduce((s, p) => s + p.count, 0);
    if (total === 0) return '';
    const r = 46, ir = 30;
    let startAngle = 0;
    for (let j = 0; j < index; j++) startAngle += (platformData.value[j].count / total) * Math.PI * 2;
    const angle = (platformData.value[index].count / total) * Math.PI * 2;
    const endAngle = startAngle + angle - 0.02;
    const x1 = Math.cos(startAngle) * r, y1 = Math.sin(startAngle) * r;
    const x2 = Math.cos(endAngle) * r, y2 = Math.sin(endAngle) * r;
    const ix1 = Math.cos(endAngle) * ir, iy1 = Math.sin(endAngle) * ir;
    const ix2 = Math.cos(startAngle) * ir, iy2 = Math.sin(startAngle) * ir;
    const large = angle > Math.PI ? 1 : 0;
    return `M ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} L ${ix1} ${iy1} A ${ir} ${ir} 0 ${large} 0 ${ix2} ${iy2} Z`;
}

// Response time
const responseHours = ref(0);
const responsePercent = computed(() => Math.max(0, Math.min(100, Math.round((1 - responseHours.value / 48) * 100))));
const responseColor = computed(() => responseHours.value > 48 ? "#ef4444" : responseHours.value > 24 ? "#f59e0b" : "#10b981");

// Kaufanbote chart (modal)
const kaufanboteMonthly = ref([]);

function buildChartData() {
    const d = perfData.value;
    if (!d) return;

    // Trend
    if (d.weekly_trend) {
        trendData.value = d.weekly_trend.map((w, i) => ({
            x: i,
            label: new Date(w.week_start).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit" }),
            inquiries: parseInt(w.inquiries) || 0,
            outbound: parseInt(w.outbound) || 0,
        }));
    }

    // Platforms
    if (d.platforms?.length) {
        platformData.value = d.platforms.map(p => ({ label: p.platform, count: parseInt(p.count) }));
    }

    // Funnel
    if (d.funnel) {
        const f = d.funnel;
        funnelData.value = [
            { label: "Leads", value: f.total_leads || 0 },
            { label: "Bes.-Anfr.", value: f.viewing_requests || 0 },
            { label: "Besichtigt", value: f.viewings_done || 0 },
            { label: "Kaufanbote", value: f.offers || 0 },
        ];
    }

    // Response time
    responseHours.value = d.avg_response_hours || 0;

    chartsReady.value = true;
}

// Ranking removed

// Upcoming events
const upcomingEvents = ref([]);
const upcomingLoading = ref(false);
async function loadUpcoming() {
    upcomingLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=calendar_upcoming");
        upcomingEvents.value = (await r.json()).events || [];
    } catch {}
    upcomingLoading.value = false;
}

function fmtEventTime(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d)) { const parts = iso.split(" "); return parts[1] ? parts[1].slice(0, 5) : ""; }
    return d.toLocaleTimeString("de-AT", { hour: "2-digit", minute: "2-digit" });
}
function fmtEventDay(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d)) return iso.slice(0, 10);
    const today = new Date(); const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1);
    if (d.toDateString() === today.toDateString()) return "Heute";
    if (d.toDateString() === tomorrow.toDateString()) return "Morgen";
    return d.toLocaleDateString("de-AT", { weekday: "short", day: "numeric", month: "short" });
}

// Greeting
const greeting = computed(() => {
    const h = new Date().getHours();
    const dayOfYear = Math.floor((Date.now() - new Date(new Date().getFullYear(), 0, 0)) / 86400000);
    const slot = Math.floor(h / 3);
    const seed = dayOfYear * 8 + slot;
    const fn = (userName?.value || "Admin").split(" ")[0];
    const morningGreetings = [`Guten Morgen, ${fn}!`, "Guten Morgen! Heute wird produktiv.", `Guten Morgen, ${fn}! Neue Chancen warten.`, `Los geht's, ${fn}!`];
    const dayGreetings = [`Hallo ${fn}! Weiter so.`, `Hey ${fn}! Bleib dran.`, "Weiter geht's!", `Hallo ${fn}! Fokus halten.`];
    const eveningGreetings = [`Guten Abend, ${fn}!`, `Feierabend in Sicht, ${fn}.`, "Guten Abend! Letzter Check.", `Entspann dich, ${fn}.`];
    let pool = h < 12 ? morningGreetings : h < 18 ? dayGreetings : eveningGreetings;
    return pool[seed % pool.length];
});

const openTaskCount = computed(() => tasks.value.filter(t => !t.done).length);

// Task functions (kept for future re-enable)
async function loadTasks() {
    try { const r = await fetch(API.value + "&action=getTasks"); tasks.value = (await r.json()).tasks || []; } catch {}
}
async function addTask() {
    if (!newTaskText.value.trim()) return;
    try {
        const r = await fetch(API.value + "&action=addTask", { method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ text: newTaskText.value.trim(), property_id: newTaskProperty.value || null, priority: newTaskPriority.value, due_date: newTaskDueDate.value || null }) });
        const d = await r.json();
        if (d.success && d.task) { tasks.value.unshift(d.task); newTaskText.value = ""; newTaskDueDate.value = ""; toast("Aufgabe hinzugefügt"); }
    } catch (e) { toast("Fehler: " + e.message); }
}
async function completeTask(task) {
    task._completing = true;
    try {
        const d = await (await fetch(API.value + "&action=doneTask", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ task_id: task.id }) })).json();
        if (d.success) { setTimeout(() => { task.done = 1; toast(d.activity_created ? "Erledigt + Aktivität geloggt" : "Erledigt!"); }, 300); }
    } catch (e) { task._completing = false; toast("Fehler: " + e.message); }
}
async function deleteTask(task) {
    if (!confirm('Aufgabe "' + (task.title || task.text) + '" löschen?')) return;
    try {
        const d = await (await fetch(API.value + "&action=delete_task", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: task.id }) })).json();
        if (d.success) { tasks.value = tasks.value.filter(t => t.id !== task.id); toast("Aufgabe gelöscht"); }
    } catch (e) { toast("Fehler: " + e.message); }
}
async function delegateTask(task) {
    try {
        const d = await (await fetch(API.value + "&action=delegate_task", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ task_id: task.id }) })).json();
        if (d.success) { toast("An Assistenz delegiert"); loadTasks(); } else { toast("Fehler: " + (d.error || "Unbekannt")); }
    } catch (e) { toast("Fehler: " + e.message); }
}
async function generateAiTodos() {
    aiTodosLoading.value = true;
    try {
        const d = await (await fetch(API.value + "&action=generateTodos", { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" })).json();
        if (!d.error) { await loadTasks(); toast(d.generated ? d.generated + " Aufgaben generiert" : "Keine neuen Aufgaben"); }
        else { toast("Fehler: " + d.error); }
    } catch (e) { toast("Fehler: " + e.message); }
    aiTodosLoading.value = false;
}

async function loadKaufanboteStats() {
    try {
        const from = new Date(new Date().setMonth(new Date().getMonth() - 11)).toISOString().slice(0, 7) + "-01";
        const to = new Date().toISOString().slice(0, 10);
        kaufanboteStats.value = await (await fetch(API.value + "&action=kaufanbote_stats&from=" + encodeURIComponent(from) + "&to=" + encodeURIComponent(to))).json();
        const months = kaufanboteStats.value.monthly || [];
        const names = ["Jan","Feb","Mar","Apr","Mai","Jun","Jul","Aug","Sep","Okt","Nov","Dez"];
        kaufanboteMonthly.value = months.map(m => {
            const [y, mo] = m.month.split("-");
            return { label: names[parseInt(mo)-1] + " " + y.slice(2), count: m.count };
        });
    } catch {}
}

async function loadPerformance() {
    try { perfData.value = await (await fetch(API.value + "&action=performance")).json(); buildChartData(); } catch {}
}

onMounted(async () => {
    if (userType.value !== "assistenz") loadSalesAndCommissions();
    await Promise.all([loadTasks(), loadKaufanboteStats(), loadPerformance(), loadUpcoming(), loadBriefing()]);
});
</script>

<template>
    <div class="sr-soft-dashboard px-4 py-6 space-y-6">

        <!-- Tagesbriefing (NEU: oberhalb der Action-Card) -->
        <TagesbriefingCard
            :briefing="briefingData"
            :loading="briefingLoading"
            :date="briefingDate"
            @open="briefingOpen = true"
        />

        <TagesbriefingSheet
            v-model:open="briefingOpen"
            :briefing="briefingData"
            :loading="briefingLoading"
            :date="briefingDate"
            @regenerate="regenerateBriefing"
            @open-conversation="openBriefingConversation"
            @open-viewing="openBriefingViewing"
            @open-task="openBriefingTask"
        />

        <!-- Section 1: Action Card -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <CardTitle class="text-base">{{ greeting }}</CardTitle>
                        <CardDescription>Hier ist dein Tagesüberblick</CardDescription>
                    </div>
                    <div class="flex gap-1.5 flex-wrap">
                        <Badge v-if="unansweredCount > 0" variant="destructive" class="cursor-pointer" @click="switchTab('inbox')">
                            {{ unansweredCount }} Unbeantwortet
                        </Badge>
                        <Badge v-if="followupCount > 0" class="cursor-pointer bg-orange-100 text-orange-700 hover:bg-orange-200 border-0" @click="switchTab('inbox')">
                            {{ followupCount }} Nachfassen
                        </Badge>
                        <Badge v-if="unmatchedCount > 0" class="cursor-pointer bg-blue-100 text-blue-700 hover:bg-blue-200 border-0" @click="switchTab('inbox')">
                            {{ unmatchedCount }} Posteingang
                        </Badge>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="pt-0">
                <div class="divide-y divide-gray-200 -mx-6 px-6">
                    <div v-if="unansweredCount > 0" @click="switchTab('inbox')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent/80 -mx-6 px-6 transition-all duration-150">
                        <span class="w-2 h-2 rounded-full bg-destructive shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ unansweredCount }} unbeantwortete Anfrage{{ unansweredCount > 1 ? 'n' : '' }} bearbeiten</span>
                        <Badge variant="destructive" class="text-[10px]">Dringend</Badge>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="followupCount > 0" @click="switchTab('inbox')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent/80 -mx-6 px-6 transition-all duration-150">
                        <span class="w-2 h-2 rounded-full bg-orange-500 shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ followupCount }} Kontakte zum Nachfassen</span>
                        <Badge class="text-[10px] bg-orange-100 text-orange-700 border-0">Fällig</Badge>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="stats.viewings_today > 0" @click="switchTab('properties')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent/80 -mx-6 px-6 transition-all duration-150">
                        <span class="w-2 h-2 rounded-full bg-teal-500 shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ stats.viewings_today }} Besichtigung{{ stats.viewings_today > 1 ? 'en' : '' }} heute</span>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="openTaskCount > 0" @click="switchTab('inbox')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent/80 -mx-6 px-6 transition-all duration-150">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ openTaskCount }} offene Aufgabe{{ openTaskCount > 1 ? 'n' : '' }}</span>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="unansweredCount === 0 && followupCount === 0 && unmatchedCount === 0 && openTaskCount === 0"
                        class="flex items-center gap-3 py-2.5">
                        <CheckCircle class="w-4 h-4 text-emerald-500" />
                        <span class="text-sm font-medium text-emerald-600">Alles erledigt! Keine offenen Aufgaben.</span>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Section 2: KPI Cards -->
        <div class="grid gap-4" :class="userType !== 'assistenz' ? 'grid-cols-2 md:grid-cols-4' : 'grid-cols-2 md:grid-cols-3'">
            <Card class="cursor-pointer hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" @click="showKaufanboteModal = true">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Kaufanbote</CardTitle>
                    <BadgeCheck class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ kaufanboteStats.total || '0' }}</div>
                    <p class="text-xs text-emerald-600 mt-1" v-if="kaufanboteStats.total > 0">+{{ kaufanboteStats.monthly?.[kaufanboteStats.monthly.length - 1]?.count || 0 }} diesen Monat</p>
                </CardContent>
            </Card>

            <Card class="cursor-pointer hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" @click="showSalesModal = true">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Verkaufsvolumen</CardTitle>
                    <DollarSign class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold" v-if="salesVolumeData">&euro; {{ Number(salesVolumeData.total_volume || 0).toLocaleString('de-DE') }}</div>
                    <div class="text-2xl font-bold text-muted-foreground" v-else>&ndash;</div>
                    <p class="text-xs text-muted-foreground mt-1">Gesamtjahr</p>
                </CardContent>
            </Card>

            <Card v-if="userType !== 'assistenz'" class="cursor-pointer hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" @click="showCommissionModal = true">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Provisionen</CardTitle>
                    <Wallet class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-emerald-600" v-if="commissionData">&euro; {{ Number(commissionData.total_makler || 0).toLocaleString('de-DE') }}</div>
                    <div class="text-2xl font-bold text-muted-foreground" v-else>&ndash;</div>
                    <p class="text-xs text-muted-foreground mt-1">Netto Makler</p>
                </CardContent>
            </Card>

            <Card class="cursor-pointer hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" @click="switchTab('properties')">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Objekte</CardTitle>
                    <Building class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ stats.properties || '0' }}</div>
                    <p class="text-xs text-muted-foreground mt-1">{{ stats.active_properties || 0 }} aktiv beworben</p>
                </CardContent>
            </Card>
        </div>

        <!-- Section 3: Charts -->
        <div v-if="chartsReady" class="grid grid-cols-1 lg:grid-cols-7 gap-4">
            <!-- Anfragen-Trend -->
            <Card class="lg:col-span-4">
                <CardHeader>
                    <CardTitle class="text-sm">Anfragen-Trend</CardTitle>
                    <CardDescription>Letzte 8 Wochen</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="trendData.length" class="relative">
                        <!-- Trend bars: CSS-basiert, responsive, keine Verzerrung -->
                        <div class="trend-chart flex items-end gap-2 h-44 pb-6 relative">
                            <!-- horizontale Grid-Lines -->
                            <div class="absolute inset-x-0 top-0 bottom-6 flex flex-col justify-between pointer-events-none">
                                <div v-for="n in 4" :key="'grid'+n" class="h-px bg-border/60"></div>
                                <div class="h-px bg-border"></div>
                            </div>
                            <!-- Bars pro Woche -->
                            <div v-for="(d, i) in trendData" :key="'t'+i"
                                class="flex-1 flex flex-col items-center h-full relative group cursor-pointer"
                                @mouseenter="hoveredTrend = i" @mouseleave="hoveredTrend = null">
                                <div class="flex-1 w-full flex items-end justify-center gap-1 relative z-10">
                                    <div class="flex-1 max-w-[14px] rounded-t-md transition-all duration-200"
                                        :style="{
                                            height: (d.inquiries / trendMax * 100) + '%',
                                            minHeight: d.inquiries > 0 ? '3px' : '0',
                                            background: 'hsl(var(--chart-1))',
                                            opacity: hoveredTrend === null || hoveredTrend === i ? 1 : 0.5,
                                        }"></div>
                                    <div class="flex-1 max-w-[14px] rounded-t-md transition-all duration-200"
                                        :style="{
                                            height: (d.outbound / trendMax * 100) + '%',
                                            minHeight: d.outbound > 0 ? '3px' : '0',
                                            background: 'hsl(var(--chart-2))',
                                            opacity: hoveredTrend === null || hoveredTrend === i ? 1 : 0.5,
                                        }"></div>
                                </div>
                                <div class="absolute bottom-0 text-[10px] text-muted-foreground">{{ d.label }}</div>
                            </div>
                        </div>
                        <!-- Hover tooltip -->
                        <div v-if="hoveredTrend !== null" class="absolute top-0 right-0 bg-popover border border-border rounded-lg shadow-md px-3 py-2 text-xs pointer-events-none z-10">
                            <div class="font-medium mb-1">{{ trendData[hoveredTrend]?.label }}</div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full" style="background:hsl(var(--chart-1))"></span>
                                Anfragen: <span class="font-semibold ml-auto">{{ trendData[hoveredTrend]?.inquiries }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full" style="background:hsl(var(--chart-2))"></span>
                                Ausgehend: <span class="font-semibold ml-auto">{{ trendData[hoveredTrend]?.outbound }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 mt-3 text-xs text-muted-foreground">
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm" style="background:hsl(var(--chart-1))"></span> Anfragen</span>
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm" style="background:hsl(var(--chart-2))"></span> Ausgehend</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Plattform-Verteilung (Donut) -->
            <Card class="lg:col-span-3">
                <CardHeader>
                    <CardTitle class="text-sm">Plattformen</CardTitle>
                    <CardDescription>Anfragen nach Quelle</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="platformData.length" class="flex flex-col items-center relative">
                        <svg viewBox="-60 -60 120 120" class="w-44 h-44">
                            <template v-for="(p, i) in platformData" :key="'d'+i">
                                <path :d="donutArc(i)" :fill="'hsl(var(--chart-' + (i % 5 + 1) + '))'"
                                    @mouseenter="hoveredPlatform = i" @mouseleave="hoveredPlatform = null"
                                    :opacity="hoveredPlatform === null || hoveredPlatform === i ? 1 : 0.4"
                                    :transform="hoveredPlatform === i ? 'scale(1.05)' : 'scale(1)'"
                                    class="cursor-pointer transition-all duration-200" style="transform-origin:center" />
                            </template>
                            <circle cx="0" cy="0" r="28" class="fill-background" />
                            <text x="0" y="2" text-anchor="middle" dominant-baseline="middle" class="fill-foreground font-bold" style="font-size:14px">{{ platformTotal }}</text>
                            <text x="0" y="14" text-anchor="middle" class="fill-muted-foreground" style="font-size:7px">Gesamt</text>
                        </svg>
                        <!-- Hover tooltip -->
                        <div v-if="hoveredPlatform !== null" class="absolute top-0 right-0 bg-popover border border-border rounded-lg shadow-md px-3 py-2 text-xs pointer-events-none z-10">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full" :style="'background:hsl(var(--chart-' + (hoveredPlatform % 5 + 1) + '))'"></span>
                                <span class="font-medium">{{ platformData[hoveredPlatform]?.label }}</span>
                            </div>
                            <div class="font-semibold mt-0.5">{{ platformData[hoveredPlatform]?.count }} ({{ Math.round(platformData[hoveredPlatform]?.count / platformTotal * 100) }}%)</div>
                        </div>
                        <div class="flex flex-wrap gap-x-3 gap-y-1 mt-3 justify-center">
                            <span v-for="(p, i) in platformData" :key="'pl'+i"
                                @mouseenter="hoveredPlatform = i" @mouseleave="hoveredPlatform = null"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer hover:text-foreground transition-colors">
                                <span class="w-2.5 h-2.5 rounded-full" :style="'background:hsl(var(--chart-' + (i % 5 + 1) + '))'"></span>
                                {{ p.label }}
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div v-if="chartsReady" class="grid grid-cols-1 lg:grid-cols-7 gap-4">
            <!-- Verkaufstrichter (nimmt jetzt volle Breite nach Entfernen der Antwortzeit) -->
            <Card class="lg:col-span-7">
                <CardHeader>
                    <CardTitle class="text-sm">Verkaufstrichter</CardTitle>
                    <CardDescription>Conversion Pipeline</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="funnelData.length" class="space-y-2.5 relative">
                        <div v-for="(f, i) in funnelData" :key="'f'+i"
                            @mouseenter="hoveredFunnel = i" @mouseleave="hoveredFunnel = null"
                            class="flex items-center gap-3 cursor-pointer group">
                            <span class="text-xs text-muted-foreground w-20 text-right shrink-0">{{ f.label }}</span>
                            <div class="flex-1 h-7 rounded-md bg-muted overflow-hidden">
                                <div class="h-full rounded-md transition-all duration-300"
                                    :style="{ width: (f.value / Math.max(funnelData[0]?.value, 1) * 100) + '%', background: 'hsl(var(--chart-' + (i % 4 + 1) + '))' }"
                                    :class="hoveredFunnel === i ? 'opacity-100' : 'opacity-85'">
                                </div>
                            </div>
                            <span class="text-sm font-bold tabular-nums w-10 text-right">{{ f.value }}</span>
                        </div>
                        <!-- Hover tooltip -->
                        <div v-if="hoveredFunnel !== null && funnelData[0]?.value > 0" class="absolute -top-2 right-0 bg-popover border border-border rounded-lg shadow-md px-3 py-2 text-xs pointer-events-none z-10">
                            <div class="font-medium">{{ funnelData[hoveredFunnel]?.label }}</div>
                            <div class="text-muted-foreground">{{ Math.round(funnelData[hoveredFunnel]?.value / funnelData[0].value * 100) }}% Conversion</div>
                        </div>
                    </div>
                </CardContent>
            </Card>

        </div>

        <!-- Section 4: Termine -->
        <Card v-if="upcomingEvents.length">
            <CardHeader class="flex flex-row items-center justify-between">
                <div>
                    <CardTitle class="text-sm">Termine diese Woche</CardTitle>
                </div>
                <button @click="switchTab('calendar')" class="text-xs text-muted-foreground hover:text-foreground transition-colors">
                    Alle anzeigen &rarr;
                </button>
            </CardHeader>
            <CardContent class="pt-0">
                <div class="divide-y divide-gray-200 -mx-6 px-6">
                    <div v-for="ev in upcomingEvents.slice(0, 5)" :key="ev.id" @click="switchTab('calendar')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent/80 -mx-6 px-6 transition-all duration-150">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                            :class="ev.is_besichtigung ? 'bg-teal-50 dark:bg-teal-950' : 'bg-orange-50 dark:bg-orange-950'">
                            <Home v-if="ev.is_besichtigung" class="w-4 h-4 text-teal-600" />
                            <CalendarIcon v-else class="w-4 h-4 text-orange-600" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium truncate">{{ ev.summary }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ fmtEventDay(ev.start) }}
                                <span v-if="!ev.all_day"> &middot; {{ fmtEventTime(ev.start) }} – {{ fmtEventTime(ev.end) }}</span>
                                <span v-if="ev.location"> &middot; {{ ev.location }}</span>
                            </div>
                        </div>
                        <Badge v-if="ev.is_besichtigung" variant="outline" class="text-[10px] text-teal-600 border-teal-200">Besichtigung</Badge>
                    </div>
                </div>
            </CardContent>
        </Card>


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

                <!-- Monthly Chart (replaced ApexCharts with CSS bars) -->
                <div v-if="kaufanboteMonthly.length" class="px-6 pt-4 pb-2">
                    <h3 class="text-sm font-semibold mb-2">Monatliche Entwicklung</h3>
                    <div class="flex items-end gap-1 h-[180px]">
                        <div v-for="(m, i) in kaufanboteMonthly" :key="i" class="flex-1 flex flex-col items-center gap-1">
                            <div class="w-full flex items-end justify-center" style="height:150px;">
                                <div class="w-3/4 rounded-t-sm bg-emerald-500 transition-all"
                                    :style="{ height: (m.count / Math.max(...kaufanboteMonthly.map(x => x.count), 1) * 100) + '%', minHeight: m.count > 0 ? '4px' : '0' }"></div>
                            </div>
                            <span class="text-[8px] text-[var(--muted-foreground)]">{{ m.label }}</span>
                        </div>
                    </div>
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
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted">{{ prop.ref_id }}</span>
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
                                    <td class="py-2 pr-4"><span class="text-[10px] px-1.5 py-0.5 rounded bg-muted">{{ d.properties?.[0]?.ref_id }}</span></td>
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
                        <span class="text-[10px] text-[var(--muted-foreground)]">{{ prop.city }} &middot; {{ prop.all_sold }}/{{ prop.total }} verkauft &middot; {{ Math.round((prop.sold_area / (prop.living_area || 1)) * 100) }}% m&sup2;</span>
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

<style scoped>
/*
 * Weicheres Dashboard: ersetzt die harten shadcn-Card-Borders
 * durch sanfte Schlagschatten, groessere Rundungen, mehr Raum.
 * :deep()-Selektoren durchbrechen die Vue-scoped-CSS-Grenze und
 * treffen die von <Card> generierten Elemente.
 */
.sr-soft-dashboard :deep(.rounded-xl) {
    border-color: transparent !important;
    border-radius: 16px;
    box-shadow:
        0 1px 2px rgba(15, 23, 42, 0.03),
        0 4px 16px -6px rgba(15, 23, 42, 0.06);
    transition: box-shadow 180ms ease, transform 180ms ease;
}

/* Etwas staerkerer Shadow beim Hover fuer Aktions-Cards */
.sr-soft-dashboard :deep(.rounded-xl.cursor-pointer:hover) {
    box-shadow:
        0 2px 4px rgba(15, 23, 42, 0.04),
        0 10px 28px -8px rgba(15, 23, 42, 0.10);
    transform: translateY(-1px);
}

/* KPI-Cards: noch etwas mehr Luft + klarere Hierarchie */
.sr-soft-dashboard :deep([class*="rounded-xl"]) > [class*="flex-row"][class*="items-center"] {
    padding-top: 1rem;
}

/* Divide-y wirkt zart statt hart */
.sr-soft-dashboard :deep(.divide-gray-200 > * + *) {
    border-top-color: rgba(0, 0, 0, 0.06);
}

/* Trend-Chart smoothed */
.trend-chart > *:last-child {
    min-width: 0;
}
</style>
