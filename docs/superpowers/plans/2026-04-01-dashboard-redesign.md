# Dashboard (TodayTab) Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace TodayTab.vue with shadcn-vue components (Card, Table, Badge, Select) and migrate charts from ApexCharts to @unovis/vue.

**Architecture:** Single file rewrite of `TodayTab.vue`. Script logic stays mostly the same — only chart-building functions change from ApexCharts config to unovis data format. Template is completely rewritten using shadcn Card/Table/Badge patterns. Modals stay functionally identical with minor shadcn Card wrapping.

**Tech Stack:** Vue 3, shadcn-vue (Card, Table, Badge, Select, Chart), @unovis/vue, @unovis/ts, Tailwind CSS, lucide-vue-next

---

### Task 1: Install shadcn components and unovis dependencies

**Files:**
- Modify: `package.json` (npm install)
- Create: `resources/js/components/ui/card/*`
- Create: `resources/js/components/ui/table/*`
- Create: `resources/js/components/ui/badge/*`
- Create: `resources/js/components/ui/select/*`

- [ ] **Step 1: Install shadcn-vue components**

```bash
cd /var/www/srhomes
npx shadcn-vue@latest add card table badge select -y
```

Expected: Components created in `resources/js/components/ui/`

- [ ] **Step 2: Install unovis chart libraries**

```bash
cd /var/www/srhomes
npm install @unovis/vue @unovis/ts
```

Expected: Packages added to `package.json`

- [ ] **Step 3: Verify installations**

```bash
ls resources/js/components/ui/card/
ls resources/js/components/ui/table/
ls resources/js/components/ui/badge/
ls resources/js/components/ui/select/
cat package.json | grep unovis
```

Expected: All component directories exist, unovis in dependencies

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/ui/card resources/js/components/ui/table resources/js/components/ui/badge resources/js/components/ui/select package.json package-lock.json
git commit -m "feat(dashboard): install shadcn card, table, badge, select + unovis charts"
```

---

### Task 2: Rewrite TodayTab.vue — Script section

**Files:**
- Modify: `resources/js/Components/Admin/TodayTab.vue`

The script section keeps all existing data loading, task management, modal logic, ranking, and event functions. Changes:
1. Replace `import VueApexCharts` with unovis imports
2. Replace `buildDashboardCharts()` to produce unovis-compatible data refs
3. Remove ApexCharts option/series refs, add unovis data refs
4. Add shadcn component imports

- [ ] **Step 1: Write the new script block**

Replace the entire `<script setup>` block. Full code:

```javascript
import { ref, inject, onMounted, computed } from "vue";
import {
    Sun as SunIcon, MailX, Clock, CalendarIcon, Home, Inbox,
    CheckSquare, CheckCircle, ChevronRight, BadgeCheck, Building,
    Plus, Sparkles, Trash2, X, Pencil, DollarSign, Wallet,
    TrendingUp, Timer
} from "lucide-vue-next";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import {
    Select as UiSelect,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";

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

// Charts — unovis data
const perfData = ref(null);
const chartsReady = ref(false);

// Trend chart data
const trendData = ref([]);
const trendX = (d) => d.x;
const trendY = [(d) => d.inquiries, (d) => d.outbound];
const trendColors = ["#f97316", "#3b82f6"];

// Platform chart data
const platformData = ref([]);
const platformValue = (d) => d.count;
const platformColors = ["#f97316", "#3b82f6", "#10b981", "#8b5cf6", "#14b8a6"];

// Funnel chart data
const funnelData = ref([]);
const funnelX = (d) => d.label;
const funnelY = (d) => d.value;
const funnelColors = ["#f97316", "#3b82f6", "#14b8a6", "#8b5cf6"];

// Response time
const responseHours = ref(0);
const responsePercent = computed(() => Math.max(0, Math.min(100, Math.round((1 - responseHours.value / 48) * 100))));
const responseColor = computed(() => responseHours.value > 48 ? "#ef4444" : responseHours.value > 24 ? "#f59e0b" : "#10b981");

// Kaufanbote chart (modal) — kept as simple data for bar rendering
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

// Ranking
const rankingData = ref([]);
const rankingPeriod = ref("30");
const rankingSort = ref("anfragen");
async function loadRanking() {
    try {
        const r = await fetch(API.value + "&action=broker_ranking&period=" + rankingPeriod.value);
        const d = await r.json();
        rankingData.value = d.ranking || [];
    } catch {}
}
const sortedRanking = computed(() => {
    const key = rankingSort.value;
    return [...rankingData.value].sort((a, b) => (Number(b[key]) || 0) - (Number(a[key]) || 0));
});

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
    loadRanking();
    await Promise.all([loadTasks(), loadKaufanboteStats(), loadPerformance(), loadUpcoming()]);
});
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Admin/TodayTab.vue
git commit -m "feat(dashboard): rewrite script with shadcn imports and unovis chart data"
```

---

### Task 3: Rewrite TodayTab.vue — Template (Action Card + KPI Cards)

**Files:**
- Modify: `resources/js/Components/Admin/TodayTab.vue` (template section)

- [ ] **Step 1: Write the template opening, Action Card, and KPI Cards**

Replace the entire `<template>` block. Start with:

```html
<template>
    <div class="px-4 py-6 space-y-6">

        <!-- Section 1: Action Card -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <CardTitle class="text-base">{{ greeting }}</CardTitle>
                        <CardDescription>Hier ist dein Tagesüberblick</CardDescription>
                    </div>
                    <div class="flex gap-1.5 flex-wrap">
                        <Badge v-if="unansweredCount > 0" variant="destructive" class="cursor-pointer" @click="switchTab('priorities')">
                            {{ unansweredCount }} Unbeantwortet
                        </Badge>
                        <Badge v-if="followupCount > 0" class="cursor-pointer bg-orange-100 text-orange-700 hover:bg-orange-200 border-0" @click="switchTab('priorities')">
                            {{ followupCount }} Nachfassen
                        </Badge>
                        <Badge v-if="unmatchedCount > 0" class="cursor-pointer bg-blue-100 text-blue-700 hover:bg-blue-200 border-0" @click="switchTab('comms')">
                            {{ unmatchedCount }} Posteingang
                        </Badge>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="pt-0">
                <div class="divide-y divide-border -mx-6 px-6">
                    <div v-if="unansweredCount > 0" @click="switchTab('priorities')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent -mx-6 px-6 transition-colors">
                        <span class="w-2 h-2 rounded-full bg-destructive shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ unansweredCount }} unbeantwortete Anfrage{{ unansweredCount > 1 ? 'n' : '' }} bearbeiten</span>
                        <Badge variant="destructive" class="text-[10px]">Dringend</Badge>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="followupCount > 0" @click="switchTab('priorities')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent -mx-6 px-6 transition-colors">
                        <span class="w-2 h-2 rounded-full bg-orange-500 shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ followupCount }} Kontakte zum Nachfassen</span>
                        <Badge class="text-[10px] bg-orange-100 text-orange-700 border-0">Fällig</Badge>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="stats.viewings_today > 0" @click="switchTab('properties')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent -mx-6 px-6 transition-colors">
                        <span class="w-2 h-2 rounded-full bg-teal-500 shrink-0"></span>
                        <span class="flex-1 text-sm font-medium">{{ stats.viewings_today }} Besichtigung{{ stats.viewings_today > 1 ? 'en' : '' }} heute</span>
                        <ChevronRight class="w-4 h-4 text-muted-foreground" />
                    </div>
                    <div v-if="openTaskCount > 0" @click="switchTab('priorities')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent -mx-6 px-6 transition-colors">
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
            <Card class="cursor-pointer hover:border-ring transition-colors" @click="showKaufanboteModal = true">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Kaufanbote</CardTitle>
                    <BadgeCheck class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ realKaufanbote.length || kaufanboteStats.total || '0' }}</div>
                    <p class="text-xs text-emerald-600 mt-1" v-if="kaufanboteStats.total > 0">+{{ kaufanboteStats.monthly?.[kaufanboteStats.monthly.length - 1]?.count || 0 }} diesen Monat</p>
                </CardContent>
            </Card>

            <Card class="cursor-pointer hover:border-ring transition-colors" @click="showSalesModal = true">
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

            <Card v-if="userType !== 'assistenz'" class="cursor-pointer hover:border-ring transition-colors" @click="showCommissionModal = true">
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

            <Card class="cursor-pointer hover:border-ring transition-colors" @click="switchTab('properties')">
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
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Admin/TodayTab.vue
git commit -m "feat(dashboard): template action card + KPI cards with shadcn"
```

---

### Task 4: Rewrite TodayTab.vue — Template (Charts with unovis)

**Files:**
- Modify: `resources/js/Components/Admin/TodayTab.vue` (continue template)

- [ ] **Step 1: Add charts section after KPI cards**

Note: Since @unovis/vue might not be fully compatible with the project's JS setup (no TypeScript), we use a pragmatic approach — keep the chart rendering in shadcn Cards but use simple SVG/CSS-based chart representations that match the mockup. The data is already computed. If unovis works, it can be swapped in later.

For the initial implementation, render charts as visual HTML within shadcn Cards, using the computed data:

```html
        <!-- Section 3: Charts -->
        <div v-if="chartsReady" class="grid grid-cols-1 lg:grid-cols-7 gap-4">
            <!-- Anfragen-Trend -->
            <Card class="lg:col-span-4">
                <CardHeader>
                    <CardTitle class="text-sm">Anfragen-Trend</CardTitle>
                    <CardDescription>Letzte 8 Wochen</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-end gap-1 h-[160px]" v-if="trendData.length">
                        <div v-for="(d, i) in trendData" :key="i" class="flex-1 flex flex-col items-center gap-1">
                            <div class="w-full flex gap-0.5 items-end" style="height:140px;">
                                <div class="flex-1 rounded-t-sm bg-[#f97316] transition-all" :style="{ height: (d.inquiries / Math.max(...trendData.map(t => t.inquiries), 1) * 100) + '%', minHeight: d.inquiries > 0 ? '4px' : '0' }"></div>
                                <div class="flex-1 rounded-t-sm bg-[#3b82f6] transition-all" :style="{ height: (d.outbound / Math.max(...trendData.map(t => t.outbound), 1) * 100) + '%', minHeight: d.outbound > 0 ? '4px' : '0' }"></div>
                            </div>
                            <span class="text-[9px] text-muted-foreground">{{ d.label }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 mt-3 text-xs text-muted-foreground">
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#f97316]"></span> Anfragen</span>
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#3b82f6]"></span> Ausgehend</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Plattform-Verteilung -->
            <Card class="lg:col-span-3">
                <CardHeader>
                    <CardTitle class="text-sm">Plattformen</CardTitle>
                    <CardDescription>Anfragen nach Quelle</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="platformData.length" class="space-y-2">
                        <div v-for="(p, i) in platformData" :key="i" class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: platformColors[i % platformColors.length] }"></span>
                            <span class="text-sm flex-1">{{ p.label }}</span>
                            <div class="w-24 h-2 rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full" :style="{ width: (p.count / Math.max(...platformData.map(x => x.count), 1) * 100) + '%', background: platformColors[i % platformColors.length] }"></div>
                            </div>
                            <span class="text-sm font-medium tabular-nums w-8 text-right">{{ p.count }}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div v-if="chartsReady" class="grid grid-cols-1 lg:grid-cols-7 gap-4">
            <!-- Verkaufstrichter -->
            <Card class="lg:col-span-4">
                <CardHeader>
                    <CardTitle class="text-sm">Verkaufstrichter</CardTitle>
                    <CardDescription>Conversion Pipeline</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="funnelData.length" class="space-y-3">
                        <div v-for="(f, i) in funnelData" :key="i" class="flex items-center gap-3">
                            <span class="text-xs text-muted-foreground w-20 text-right shrink-0">{{ f.label }}</span>
                            <div class="flex-1 h-5 rounded bg-muted overflow-hidden">
                                <div class="h-full rounded transition-all" :style="{ width: (f.value / Math.max(funnelData[0]?.value, 1) * 100) + '%', background: funnelColors[i] }"></div>
                            </div>
                            <span class="text-sm font-bold tabular-nums w-8 text-right">{{ f.value }}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Antwortzeit -->
            <Card class="lg:col-span-3">
                <CardHeader>
                    <CardTitle class="text-sm">Antwortzeit</CardTitle>
                    <CardDescription>Durchschnitt</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col items-center justify-center pt-2">
                    <div class="relative w-28 h-28">
                        <svg class="w-28 h-28 -rotate-90" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="50" fill="none" class="stroke-muted" stroke-width="10" />
                            <circle cx="60" cy="60" r="50" fill="none" :stroke="responseColor" stroke-width="10"
                                :stroke-dasharray="(responsePercent / 100 * 314) + ' 314'" stroke-linecap="round" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold" :style="{ color: responseColor }">{{ responseHours.toFixed(1) }}h</span>
                        </div>
                    </div>
                    <Badge class="mt-3 border-0" :class="responseHours <= 4 ? 'bg-emerald-100 text-emerald-700' : responseHours <= 24 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'">
                        {{ responseHours <= 4 ? 'Gut' : responseHours <= 24 ? 'Mittel' : 'Langsam' }} ({{ responseHours <= 4 ? '<4h' : responseHours <= 24 ? '4-24h' : '>24h' }})
                    </Badge>
                </CardContent>
            </Card>
        </div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Admin/TodayTab.vue
git commit -m "feat(dashboard): charts section with shadcn cards"
```

---

### Task 5: Rewrite TodayTab.vue — Template (Termine + Ranking Table)

**Files:**
- Modify: `resources/js/Components/Admin/TodayTab.vue` (continue template)

- [ ] **Step 1: Add Termine and Ranking sections**

```html
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
                <div class="divide-y divide-border -mx-6 px-6">
                    <div v-for="ev in upcomingEvents.slice(0, 5)" :key="ev.id" @click="switchTab('calendar')"
                        class="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-accent -mx-6 px-6 transition-colors">
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

        <!-- Section 5: Makler-Ranking -->
        <Card v-if="rankingData.length > 1">
            <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle class="text-sm">Makler-Ranking</CardTitle>
                <div class="flex items-center gap-2">
                    <select v-model="rankingPeriod" @change="loadRanking()" class="h-8 rounded-md border border-input bg-background px-2 text-xs">
                        <option value="7">7 Tage</option>
                        <option value="30">30 Tage</option>
                        <option value="90">90 Tage</option>
                        <option value="365">1 Jahr</option>
                    </select>
                    <select v-model="rankingSort" class="h-8 rounded-md border border-input bg-background px-2 text-xs">
                        <option value="anfragen">Anfragen</option>
                        <option value="kaufanbote">Kaufanbote</option>
                        <option value="besichtigungen">Besichtigungen</option>
                        <option value="verkaufsvolumen">Verkaufsvolumen</option>
                        <option value="gesendet">Gesendete Mails</option>
                    </select>
                </div>
            </CardHeader>
            <CardContent class="pt-0 -mx-6 px-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-12 pl-6">#</TableHead>
                            <TableHead>Makler</TableHead>
                            <TableHead class="text-right cursor-pointer" @click="rankingSort = 'anfragen'"
                                :class="rankingSort === 'anfragen' ? 'text-orange-600 font-semibold' : ''">Anfragen</TableHead>
                            <TableHead class="text-right cursor-pointer" @click="rankingSort = 'besichtigungen'"
                                :class="rankingSort === 'besichtigungen' ? 'text-orange-600 font-semibold' : ''">Besicht.</TableHead>
                            <TableHead class="text-right cursor-pointer" @click="rankingSort = 'kaufanbote'"
                                :class="rankingSort === 'kaufanbote' ? 'text-orange-600 font-semibold' : ''">Anbote</TableHead>
                            <TableHead class="text-right cursor-pointer hidden sm:table-cell" @click="rankingSort = 'verkaufsvolumen'"
                                :class="rankingSort === 'verkaufsvolumen' ? 'text-orange-600 font-semibold' : ''">Volumen</TableHead>
                            <TableHead class="text-right cursor-pointer hidden md:table-cell" @click="rankingSort = 'gesendet'"
                                :class="rankingSort === 'gesendet' ? 'text-orange-600 font-semibold' : ''">Gesendet</TableHead>
                            <TableHead class="text-right hidden md:table-cell">Antwortzeit</TableHead>
                            <TableHead class="text-right pr-6 hidden lg:table-cell">Objekte</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="(b, i) in sortedRanking" :key="b.id">
                            <TableCell class="pl-6">
                                <span class="w-6 h-6 rounded-full inline-flex items-center justify-center text-[10px] font-bold"
                                    :class="i === 0 ? 'bg-amber-400 text-amber-900' : i === 1 ? 'bg-gray-300 text-gray-700' : i === 2 ? 'bg-amber-600 text-white' : 'bg-muted text-muted-foreground'">
                                    {{ i + 1 }}
                                </span>
                            </TableCell>
                            <TableCell class="font-semibold">{{ b.name }}</TableCell>
                            <TableCell class="text-right tabular-nums" :class="rankingSort === 'anfragen' ? 'text-orange-600 font-bold' : ''">{{ b.anfragen || 0 }}</TableCell>
                            <TableCell class="text-right tabular-nums" :class="rankingSort === 'besichtigungen' ? 'text-orange-600 font-bold' : ''">{{ b.besichtigungen || 0 }}</TableCell>
                            <TableCell class="text-right tabular-nums" :class="rankingSort === 'kaufanbote' ? 'text-orange-600 font-bold' : ''">{{ b.kaufanbote || 0 }}</TableCell>
                            <TableCell class="text-right tabular-nums hidden sm:table-cell" :class="rankingSort === 'verkaufsvolumen' ? 'text-orange-600 font-bold' : ''">&euro; {{ Number(b.verkaufsvolumen || 0).toLocaleString('de-DE') }}</TableCell>
                            <TableCell class="text-right tabular-nums hidden md:table-cell" :class="rankingSort === 'gesendet' ? 'text-orange-600 font-bold' : ''">{{ b.gesendet || 0 }}</TableCell>
                            <TableCell class="text-right tabular-nums hidden md:table-cell">
                                <span v-if="b.avg_antwortzeit_h != null"
                                    :class="Number(b.avg_antwortzeit_h) <= 4 ? 'text-emerald-600' : Number(b.avg_antwortzeit_h) <= 24 ? 'text-amber-600' : 'text-red-600'">
                                    {{ b.avg_antwortzeit_h }}h
                                </span>
                                <span v-else class="text-muted-foreground">–</span>
                            </TableCell>
                            <TableCell class="text-right pr-6 tabular-nums hidden lg:table-cell">{{ b.objekte || 0 }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Admin/TodayTab.vue
git commit -m "feat(dashboard): termine + ranking with shadcn card/table"
```

---

### Task 6: Rewrite TodayTab.vue — Template (Modals + closing tags)

**Files:**
- Modify: `resources/js/Components/Admin/TodayTab.vue` (finish template)

- [ ] **Step 1: Add the modals and close the template**

The modals stay functionally identical. Keep the existing modal HTML from the current file (Kaufanbote, Verkaufsvolumen, Provisionen modals) as-is. They already work and don't need shadcn conversion in this phase. Close all template tags:

```html
    </div><!-- end main content div -->

    <!-- Kaufanbote Detail Modal -->
    <Teleport to="body">
        <!-- KEEP EXISTING MODAL CODE EXACTLY AS-IS -->
        <!-- ... kaufanbote modal ... -->
        <!-- ... sales modal ... -->
        <!-- ... commission modal ... -->
    </Teleport>
</template>
```

Note: Copy the three `<Teleport>` / modal blocks from the current TodayTab.vue lines 914-1092 verbatim. No changes needed.

- [ ] **Step 2: Remove the old `<style>` block if any exists in TodayTab.vue**

TodayTab.vue has no `<style>` block — all styles come from Dashboard.vue's global styles. Nothing to remove.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Admin/TodayTab.vue
git commit -m "feat(dashboard): complete template with modals"
```

---

### Task 7: Build, verify, clean up

**Files:**
- Modify: `package.json` (remove apexcharts)
- Modify: `resources/js/Components/Admin/TodayTab.vue` (final)

- [ ] **Step 1: Build and check for errors**

```bash
cd /var/www/srhomes
npx vite build 2>&1 | tail -20
```

Expected: Build succeeds. If errors, fix imports or template syntax.

- [ ] **Step 2: Clear caches**

```bash
php artisan cache:clear && php artisan view:clear
```

- [ ] **Step 3: Verify in browser**

Open the dashboard in browser. Check:
- Action Card shows greeting + badges + action items
- 4 KPI cards display with correct data
- Charts render (bar, progress bars, radial)
- Termine section shows upcoming events
- Ranking table is sortable
- Modals open correctly (Kaufanbote, Verkaufsvolumen, Provisionen)
- Dark mode works
- Mobile responsive (2-col KPIs, single-col charts)

- [ ] **Step 4: Remove ApexCharts dependency**

Only after verifying everything works:

```bash
cd /var/www/srhomes
npm uninstall apexcharts vue3-apexcharts
```

Check that no other file imports ApexCharts:

```bash
grep -r "apexcharts\|VueApexCharts" resources/js/ --include="*.vue" --include="*.js"
```

If other files still use it, keep the dependency. Otherwise proceed.

- [ ] **Step 5: Final build**

```bash
npx vite build 2>&1 | tail -5
```

- [ ] **Step 6: Commit and push**

```bash
git add -A
git commit -m "feat(dashboard): complete TodayTab redesign with shadcn components

- Replace custom CSS with shadcn Card, Table, Badge components
- Migrate charts from ApexCharts to native SVG/CSS in shadcn Cards
- 4 main KPI cards with secondary badges in action card
- Makler ranking with shadcn Table
- Responsive grid layout"
git push
```

---

## Verification Checklist

- [ ] Action Card: Greeting text, badges for secondary KPIs, clickable action items
- [ ] KPI Cards: 4 cards (3 for Assistenz), correct data, click opens modal/tab
- [ ] Charts: Trend bars, platform distribution, funnel, response time radial
- [ ] Termine: Shows this week's events, badge for Besichtigung
- [ ] Ranking: shadcn Table, sortable columns, medal badges, color-coded response time
- [ ] Modals: Kaufanbote, Verkaufsvolumen, Provisionen open and display data
- [ ] Dark Mode: All components render correctly
- [ ] Mobile: 2-col KPIs, single-col charts, scrollable table
