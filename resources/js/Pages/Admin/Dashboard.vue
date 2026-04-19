<script setup>
import { ref, onMounted, computed, watch, provide } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import TodayTab from "@/Components/Admin/TodayTab.vue";
// import PrioritiesTab from "@/Components/Admin/PrioritiesTab.vue";
// import CommsTab from "@/Components/Admin/CommsTab.vue";
import InboxTab from "@/Components/Admin/InboxTab.vue";
import EmailAccountsTab from "@/Components/Admin/EmailAccountsTab.vue";
import PropertiesTab from "@/Components/Admin/PropertiesTab.vue";
import ReportsTab from "@/Components/Admin/ReportsTab.vue";
import AnalyticsTab from "@/Components/Admin/AnalyticsTab.vue";
import AdminTab from "@/Components/Admin/AdminTab.vue";
import SettingsTab from "@/Components/Admin/SettingsTab.vue";
import WebsiteTab from "@/Components/Admin/WebsiteTab.vue";
import BlogTab from "@/Components/Admin/BlogTab.vue";
import CalendarTab from "@/Components/Admin/CalendarTab.vue";
import AssistenzTasksTab from "@/Components/Admin/AssistenzTasksTab.vue";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
import {
    LayoutDashboard, AlertCircle, Zap, MessageSquare, Mail, Home,
    FileText, TrendingUp, Settings, Moon, Sun, LogOut,
    PanelLeft, Calendar, X, CheckCircle, Bell, Users, AlertTriangle, Inbox as InboxIcon, Clock as ClockIcon, Sparkles, Home as HomeIcon, Menu, Globe, ListTodo
} from "lucide-vue-next";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    properties: { type: Array, default: () => [] },
    kbCounts: { type: Object, default: () => ({}) },
    apiKey: { type: String, default: "" },
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || "Admin");
const userInitials = computed(() => (page.props.auth?.user?.name || "A").split(" ").map(w => w[0]).join("").toUpperCase().substring(0, 2));

const API = computed(() => "/api/admin_api.php?key=" + props.apiKey);
provide("API", API);
provide("properties", props.properties);
provide("kbCounts", props.kbCounts);

const tab = ref(localStorage.getItem("sr-admin-tab") || "today");
const sidebarCollapsed = ref(localStorage.getItem("sr-sidebar-collapsed") === "1");
const sidebarHovered = ref(false);
const sidebarEffective = computed(() => sidebarCollapsed.value && !sidebarHovered.value);
const mobileOpen = ref(false);
const darkMode = ref(localStorage.getItem("sr-dark") === "1");

const unansweredCount = ref(0);
const taskCount = ref(0);
const followupCount = ref(0);
const unmatchedCount = ref(0);
provide("unansweredCount", unansweredCount);
provide("followupCount", followupCount);
provide("unmatchedCount", unmatchedCount);

const toasts = ref([]);
function toast(msg) {
    const id = Date.now() + Math.random();
    toasts.value.push({ id, msg });
    setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id); }, 4000);
}
provide("toast", toast);

onMounted(() => {
    if (darkMode.value) document.documentElement.classList.add("dark");
    loadCounts();
    loadNotifications();
    document.addEventListener("click", closeBell);
});

function toggleDarkMode() {
    darkMode.value = !darkMode.value;
    localStorage.setItem("sr-dark", darkMode.value ? "1" : "0");
    document.documentElement.classList.toggle("dark", darkMode.value);
}

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem("sr-sidebar-collapsed", sidebarCollapsed.value ? "1" : "0");
}

watch(tab, (v) => localStorage.setItem("sr-admin-tab", v));
watch(mobileOpen, (open) => { if (!open) { document.body.style.pointerEvents = ""; document.body.style.overflow = ""; } });

function switchTab(t) {
    tab.value = t;
    mobileOpen.value = false;
    window.scrollTo({ top: 0, behavior: "smooth" });
}
provide("switchTab", switchTab);

const openContactSearch = ref("");
provide("openContactSearch", openContactSearch);

function openContact(name) {
    openContactSearch.value = name;
    tab.value = "admin";
    mobileOpen.value = false;
}
provide("openContact", openContact);

// Current user's avatar (profile_image + initials) — consumed by the
// inbox reading pane for outbound messages. Falls back to initials when
// no profile image is uploaded. Provided as a plain object (not a
// ref/computed) because user profile doesn't change mid-session —
// simpler downstream consumption, no .value unwrap needed.
const _avatarUser = page.props.auth?.user
const _avatarName = String(_avatarUser?.name || "")
const _avatarParts = _avatarName.trim().split(/\s+/).filter(Boolean)
const _avatarInitials = (_avatarParts.length >= 2
    ? _avatarParts[0][0] + _avatarParts[_avatarParts.length - 1][0]
    : _avatarName.slice(0, 2) || "??").toUpperCase()
const currentUserAvatar = {
    url: _avatarUser?.profile_image ? "/storage/" + _avatarUser.profile_image : null,
    initials: _avatarInitials,
};
provide("currentUserAvatar", currentUserAvatar);

// Build the user's email signature from the profile fields for reuse
// in inbox compose mode. The existing User model has signature_name,
// signature_title, signature_company, signature_phone, signature_website.
// InboxChatView reads this via inject('inboxSignature') when prefilling
// a plain "Antworten" draft.
const _sigParts = [];
const _sigName = String(_avatarUser?.signature_name || _avatarUser?.name || "").trim();
const _sigTitle = String(_avatarUser?.signature_title || "").trim();
const _sigCompany = String(_avatarUser?.signature_company || "").trim();
const _sigPhone = String(_avatarUser?.signature_phone || "").trim();
const _sigWebsite = String(_avatarUser?.signature_website || "").trim();
if (_sigName) _sigParts.push(_sigName);
if (_sigTitle) _sigParts.push(_sigTitle);
if (_sigCompany) _sigParts.push(_sigCompany);
if (_sigPhone) _sigParts.push("Tel: " + _sigPhone);
if (_sigWebsite) _sigParts.push(_sigWebsite);
const inboxSignature = _sigParts.length
    ? "Mit freundlichen Grüßen\n\n" + _sigParts.join("\n")
    : "";
provide("inboxSignature", inboxSignature);

const userType = computed(() => page.props.auth?.user?.user_type || 'makler');
const isAdmin = computed(() => userType.value === 'admin');
provide("userType", userType);
provide("isAdmin", isAdmin);
const userId = computed(() => page.props.auth?.user?.id || null);
provide("userId", userId);
const isAssistenz = computed(() => ['assistenz','backoffice'].includes(userType.value));
provide('isAssistenz', isAssistenz);

const apiBase = API;

async function loadCounts() {
    try {
        const r = await fetch(API.value + "&action=followups&filter=all");
        const d = await r.json();
        unansweredCount.value = d.total_open || 0;
        followupCount.value = d.total_followup || 0;
    } catch (e) { /* silent */ }
    try {
        const isAss = ['assistenz','backoffice'].includes(userType.value);
        const tUrl = API.value + '&action=getTasks' + (isAss ? '&scope=assistenz' : '');
        const tRes = await fetch(tUrl);
        const tData = await tRes.json();
        taskCount.value = (tData.tasks || []).filter(t => !t.is_done).length;
    } catch(e) {}
    try {
        const r = await fetch(API.value + "&action=unmatched_emails");
        const d = await r.json();
        unmatchedCount.value = d.total || 0;
    } catch (e) { /* silent */ }
}
provide("refreshCounts", loadCounts);

provide("userName", userName);
const userEmail = computed(() => page.props.auth?.user?.email || "");
const calendarEmbedUrl = computed(() => userEmail.value ? `https://calendar.google.com/calendar/embed?src=${encodeURIComponent(userEmail.value)}&ctz=Europe%2FVienna&mode=WEEK&showTitle=0&showNav=1&showDate=1&showPrint=0&showTabs=0&showCalendars=0&showTz=0&bgcolor=%23ffffff` : "");
provide("calendarEmbedUrl", calendarEmbedUrl);

// Notification bell
const bellOpen = ref(false);
const notifications = ref([]);
const notifLoading = ref(false);
const notifLoaded = ref(false);

const showViewingForm = ref(false);
const viewingFormData = ref({ activityId: null, stakeholder: '', refId: '', address: '', date: '', time: '10:00', duration: 60, notifId: '' });
const viewingSubmitting = ref(false);

async function loadNotifications() {
    if (notifLoading.value) return;
    notifLoading.value = true;
    notifLoaded.value = true;
    try {
        const items = [];

        // 1) x Aufgaben offen
        const isAss = ['assistenz', 'backoffice'].includes(userType.value);
        const tasksUrl = API.value + '&action=getTasks&done=1' + (isAss ? '&scope=assistenz' : '');
        const tasksRes = await fetch(tasksUrl);
        const tasksData = await tasksRes.json();
        const openTasks = (tasksData.tasks || []).filter(t => !t.is_done);
        if (openTasks.length > 0) {
            items.push({
                id: 'tasks_open_count',
                icon: 'clock',
                color: '#2563eb',
                text: openTasks.length + ' Aufgaben offen',
                tab: 'tasks',
            });
        }

        const now = new Date();
        const today = now.toISOString().slice(0, 10);
        const userIdValue = Number(userId.value || 0);

        // 2) Neue Aufgabe von (nur wenn zugewiesen)
        const assignedNewTasks = openTasks
            .filter(t => Number(t.assigned_to || 0) === userIdValue && Number(t.created_by || 0) !== userIdValue)
            .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
        assignedNewTasks.slice(0, 3).forEach((t) => {
            items.push({
                id: 'task_assigned_' + t.id,
                icon: 'inbox',
                color: '#7c3aed',
                text: 'Neue Aufgabe von ' + (t.created_by_name || 'Unbekannt') + ': ' + (t.title || 'Ohne Titel'),
                tab: 'tasks',
            });
        });

        // 3) Deadline heute für Aufgabe X
        const dueTodayTasks = openTasks
            .filter(t => t.due_date && String(t.due_date).slice(0, 10) === today)
            .sort((a, b) => String(a.due_date || '').localeCompare(String(b.due_date || '')));
        dueTodayTasks.slice(0, 5).forEach((t) => {
            items.push({
                id: 'task_due_today_' + t.id,
                icon: 'calendar',
                color: '#d97706',
                text: 'Deadline heute: ' + (t.title || 'Ohne Titel'),
                tab: 'tasks',
            });
        });

        // 4) Eingetragene Termine Reminder (24h, 15min)
        try {
            const calRes = await fetch(API.value + '&action=calendar_upcoming&limit=50');
            const calData = await calRes.json();
            const events = calData.events || [];
            for (const ev of events) {
                if (!ev.start) continue;
                const start = new Date(ev.start);
                const diffMs = start.getTime() - now.getTime();
                if (diffMs <= 0) continue;
                const diffMin = Math.floor(diffMs / 60000);
                if (diffMin <= 15) {
                    items.push({
                        id: 'cal_15m_' + ev.id,
                        icon: 'calendar',
                        color: '#ef4444',
                        text: 'Termin in 15 Min: ' + (ev.summary || 'Ohne Titel'),
                        tab: 'calendar',
                    });
                } else if (diffMin <= 24 * 60) {
                    items.push({
                        id: 'cal_24h_' + ev.id,
                        icon: 'calendar',
                        color: '#f59e0b',
                        text: 'Termin in 24h: ' + (ev.summary || 'Ohne Titel'),
                        tab: 'calendar',
                    });
                }
            }
        } catch {}

        const dismissed = JSON.parse(localStorage.getItem('sr-dismissed-notifs') || '{}');
        const nowTs = Date.now();
        let changed = false;
        for (const key in dismissed) { if (nowTs - dismissed[key] > 604800000) { delete dismissed[key]; changed = true; } }
        if (changed) localStorage.setItem('sr-dismissed-notifs', JSON.stringify(dismissed));
        const nonDismissableIds = ['tasks_open_count'];
        notifications.value = items.filter(n => nonDismissableIds.includes(n.id) || !dismissed[n.id]);
    } catch {}
    notifLoading.value = false;
}
const notifCount = computed(() => notifications.value.length);

function parseDatesFromEmail(text) {
    if (!text) return [];
    const dates = [];
    const p1 = /(\d{1,2})\.(\d{1,2})\.(\d{4})\s*(?:um|,)?\s*(\d{1,2})[:.:](\d{2})/gi;
    let m;
    while ((m = p1.exec(text)) !== null) {
        const y = parseInt(m[3]), mo = parseInt(m[2]), d = parseInt(m[1]), h = parseInt(m[4]), mi = parseInt(m[5]);
        if (y >= 2026 && mo >= 1 && mo <= 12 && d >= 1 && d <= 31) {
            const pad = n => String(n).padStart(2, "0");
            dates.push({ date: y + "-" + pad(mo) + "-" + pad(d), time: pad(h) + ":" + pad(mi), label: pad(d) + "." + pad(mo) + "." + y + " um " + pad(h) + ":" + pad(mi) });
        }
    }
    return dates;
}

async function notifClick(notif) {
    if (notif.activityId && notif.id.startsWith('viewing_')) {
        const m = notif.text.match(/TERMIN EINTRAGEN:\s*(.+?)\s*\((.+?)\)/);
        const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
        const dateStr2 = tomorrow.toISOString().split('T')[0];
        const parsed = parseDatesFromEmail(notif.emailBody || '');
        viewingFormData.value = { activityId: notif.activityId, stakeholder: m ? m[1].trim() : '', refId: m ? m[2].trim() : '', address: notif.address || '', date: parsed.length ? parsed[0].date : dateStr2, time: parsed.length ? parsed[0].time : '10:00', duration: 60, notifId: notif.id, parsedDates: parsed };
        showViewingForm.value = true;
        bellOpen.value = false;
        return;
    }
    bellOpen.value = false;
    switchTab(notif.tab);
}

async function submitViewingForm() {
    const f = viewingFormData.value;
    if (!f.date || !f.time) { toast('Bitte Datum und Uhrzeit angeben'); return; }
    viewingSubmitting.value = true;
    try {
        const startDt = f.date + 'T' + f.time + ':00';
        const endDate = new Date(startDt);
        endDate.setMinutes(endDate.getMinutes() + (f.duration || 60));
        const pad = (n) => String(n).padStart(2, '0');
        const endDt = endDate.getFullYear() + '-' + pad(endDate.getMonth()+1) + '-' + pad(endDate.getDate()) + 'T' + pad(endDate.getHours()) + ':' + pad(endDate.getMinutes()) + ':00';
        const summary = 'Besichtigung: ' + f.stakeholder + (f.refId ? ' – ' + f.refId : '');
        const description = 'Besichtigungstermin mit ' + f.stakeholder + (f.address ? '\nAdresse: ' + f.address : '');
        const cr = await fetch(API.value + '&action=calendar_create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ summary, start: startDt, end: endDt, description, location: f.address || '' }) });
        const cd = await cr.json();
        if (!cd.success && !cd.ok) { toast('Fehler beim Erstellen: ' + (cd.error || 'Unbekannt')); viewingSubmitting.value = false; return; }
        await fetch(API.value + '&action=dismiss_viewing_alert', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ activity_id: f.activityId }) });
        notifications.value = notifications.value.filter(n => n.id !== f.notifId);
        showViewingForm.value = false;
        toast('Termin eingetragen + Google Calendar aktualisiert');
    } catch (e) { toast('Fehler: ' + e.message); }
    viewingSubmitting.value = false;
}

function dismissNotification(n) {
    const nonDismissable = ['tasks_open_count'];
    if (nonDismissable.includes(n.id)) { notifications.value = notifications.value.filter(item => item.id !== n.id); return; }
    notifications.value = notifications.value.filter(item => item.id !== n.id);
    const dismissed = JSON.parse(localStorage.getItem('sr-dismissed-notifs') || '{}');
    dismissed[n.id] = Date.now();
    localStorage.setItem('sr-dismissed-notifs', JSON.stringify(dismissed));
}
function closeBell(e) {
    if (!e.target.closest('.bell-dropdown') && !e.target.closest('.bell-button')) bellOpen.value = false;
}

// Navigation groups
const navGroups = [
    { label: "Hauptnavigation", items: [
        { key: "today", label: "Dashboard", icon: LayoutDashboard },
        // { key: "priorities", label: "Aktionen", icon: Zap },
        { key: "inbox", label: "Inbox", icon: Mail },
        { key: "tasks", label: "Aufgaben", icon: ListTodo },
        // { key: "comms", label: "Kommunikation", icon: MessageSquare },
        { key: "properties", label: "Objekte", icon: Home },
    ]},
    { label: "Auswertungen", items: [
        { key: "reports", label: "Berichte", icon: FileText },
        { key: "analytics", label: "Marktanalyse", icon: TrendingUp },
    ]},
    { label: "", items: [
        { key: "calendar", label: "Kalender", icon: Calendar },
        { key: "admin", label: "Kontakte", icon: Users },
        { key: "website", label: "Website", icon: Globe, adminOnly: true },
        { key: "blog", label: "Blog", icon: FileText, adminOnly: true },
        { key: "settings", label: "Einstellungen", icon: Settings },
    ]},
];
const filteredGroups = computed(() =>
    navGroups.map(g => ({
        ...g,
        items: g.items.filter(i => {
            if (i.adminOnly && !isAdmin.value) return false;
            if (i.assistenzOnly && !isAssistenz.value) return false;
            return true;
        }),
    })).filter(g => g.items.length > 0)
);

const dateStr = computed(() => new Date().toLocaleDateString("de-AT", { weekday: "long", day: "2-digit", month: "long", year: "numeric" }));
const dateShort = computed(() => new Date().toLocaleDateString("de-AT", { day: "2-digit", month: "short", year: "numeric" }));

// Page title for slim header
const pageTitle = computed(() => {
    const allItems = navGroups.flatMap(g => g.items);
    const item = allItems.find(i => i.key === tab.value);
    return item ? item.label : '';
});
const pageBadge = computed(() => navBadge(tab.value));

function navBadge(key) {
    if (key === "today" && props.stats.new_24h > 0) return props.stats.new_24h;
    if (key === "inbox") { const total = (unansweredCount.value || 0) + (followupCount.value || 0); return total > 0 ? total : null; }
    // if (key === "comms" && unmatchedCount.value > 0) return unmatchedCount.value;
    if (key === "tasks" && taskCount.value > 0) return taskCount.value;
    if (key === "properties") return props.stats.properties || null;
    return null;
}
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-[var(--background)] text-[var(--foreground)]">

        <!-- Mobile Sheet Sidebar -->
        <Sheet v-model:open="mobileOpen">
            <SheetContent side="left" class="w-64 p-0 bg-white dark:bg-zinc-950">
                <nav class="flex flex-col h-full">
                    <div class="px-4 py-5 flex items-center gap-2">
                        <img v-if="!darkMode" src="/assets/logo-full-orange.svg" alt="SR-Homes" style="height:24px" />
                        <img v-else src="/assets/logo-full-white.svg" alt="SR-Homes" style="height:24px" />
                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded text-white bg-[#EE7600]">Cockpit</span>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 pb-3">
                        <template v-for="(group, gi) in filteredGroups" :key="gi">
                            <Separator v-if="gi > 0" class="my-1.5 mx-2" />
                            <p class="px-2.5 pt-3 pb-1 text-[9px] font-semibold uppercase tracking-widest text-muted-foreground/60">{{ group.label }}</p>
                            <div class="flex flex-col gap-0.5">
                                <button v-for="item in group.items" :key="item.key" @click="switchTab(item.key)"
                                    class="flex items-center gap-2 px-2.5 py-1.5 rounded-md text-[13px] transition-colors"
                                    :class="tab === item.key
                                        ? 'bg-[#fff7ed] text-[#ea580c] font-medium'
                                        : 'text-muted-foreground hover:bg-orange-50 dark:hover:bg-gray-800 hover:text-foreground'">
                                    <component :is="item.icon" class="w-4 h-4 shrink-0" />
                                    <span class="flex-1 text-left">{{ item.label }}</span>
                                    <span v-if="navBadge(item.key)" class="text-[10px] font-medium px-1.5 py-0.5 rounded-md"
                                        :class="tab === item.key ? 'bg-orange-100 text-orange-600' : 'bg-muted text-muted-foreground'">{{ navBadge(item.key) }}</span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <div class="border-t border-gray-200 p-3 space-y-2">
                        <div class="flex items-center gap-2.5 px-2">
                            <Avatar class="h-7 w-7 rounded-md">
                                <AvatarFallback class="rounded-md bg-[#fff7ed] text-[#ea580c] text-[10px] font-semibold">{{ userInitials }}</AvatarFallback>
                            </Avatar>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-medium truncate">{{ userName }}</div>
                            </div>
                            <Button variant="ghost" size="sm" class="h-7 w-7 p-0" @click="switchTab('settings')"><Settings class="w-3.5 h-3.5" /></Button>
                            <Button variant="ghost" size="sm" class="h-7 w-7 p-0" @click.prevent="useForm({}).post(route('logout'))"><LogOut class="w-3.5 h-3.5" /></Button>
                        </div>
                        <button @click="toggleDarkMode()" class="flex items-center gap-2 px-2 py-1 w-full text-left rounded-md hover:bg-orange-50 dark:hover:bg-gray-800 transition-colors">
                            <Moon v-if="!darkMode" class="w-3.5 h-3.5 text-muted-foreground" />
                            <Sun v-else class="w-3.5 h-3.5 text-muted-foreground" />
                            <span class="text-[10px] font-medium text-muted-foreground">{{ darkMode ? 'Light Mode' : 'Dark Mode' }}</span>
                        </button>
                    </div>
                </nav>
            </SheetContent>
        </Sheet>

        <!-- Desktop Sidebar -->
        <TooltipProvider :delay-duration="0">
        <aside class="hidden md:flex flex-col h-screen sticky top-0 border-r border-gray-200 bg-background transition-all duration-200"
            :class="sidebarCollapsed && sidebarHovered ? 'z-50 shadow-xl absolute left-0' : ''"
            :style="{ width: sidebarEffective ? '48px' : '200px' }"
            @mouseenter="sidebarHovered = true"
            @mouseleave="sidebarHovered = false">
            <div class="px-3 pt-4 pb-6 flex items-center" :class="sidebarEffective ? 'justify-center' : 'gap-2'">
                <img v-if="sidebarEffective && !darkMode" src="/assets/logo-icon-orange.svg" alt="SR" class="shrink-0 cursor-pointer" style="width:28px" @click="toggleSidebar()" />
                <img v-if="sidebarEffective && darkMode" src="/assets/logo-icon-white.svg" alt="SR" class="shrink-0 cursor-pointer" style="width:28px" @click="toggleSidebar()" />
                <img v-if="!sidebarEffective && !darkMode" src="/assets/logo-full-orange.svg" alt="SR-Homes" class="shrink-0" style="height:24px" />
                <img v-if="!sidebarEffective && darkMode" src="/assets/logo-full-white.svg" alt="SR-Homes" class="shrink-0" style="height:24px" />
                <span v-if="!sidebarEffective" class="text-[10px] font-semibold px-1.5 py-0.5 rounded text-white bg-[#EE7600]">Cockpit</span>
            </div>
            <nav class="flex-1 overflow-y-auto px-1.5 pb-2">
                <template v-for="(group, gi) in filteredGroups" :key="gi">
                    <Separator v-if="gi > 0" class="my-1.5 mx-2" />
                    <p v-if="!sidebarEffective" class="px-2.5 pt-3 pb-1 text-[9px] font-semibold uppercase tracking-widest text-muted-foreground/60">{{ group.label }}</p>
                    <div class="flex flex-col gap-0.5" :class="sidebarEffective ? 'items-center' : ''">
                        <template v-for="item in group.items" :key="item.key">
                            <Tooltip v-if="sidebarEffective">
                                <TooltipTrigger asChild>
                                    <button @click="switchTab(item.key)"
                                        class="relative flex items-center justify-center w-9 h-9 rounded-md transition-colors"
                                        :class="tab === item.key
                                            ? 'bg-[#fff7ed] text-[#ea580c]'
                                            : 'text-muted-foreground hover:bg-orange-50 dark:hover:bg-gray-800 hover:text-foreground'">
                                        <component :is="item.icon" class="w-4 h-4" />

                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="right" :side-offset="8">
                                    {{ item.label }}<span v-if="navBadge(item.key)"> ({{ navBadge(item.key) }})</span>
                                </TooltipContent>
                            </Tooltip>
                            <button v-else @click="switchTab(item.key)"
                                class="flex items-center gap-2 px-2.5 py-1.5 rounded-md text-[13px] transition-colors"
                                :class="tab === item.key
                                    ? 'bg-[#fff7ed] text-[#ea580c] font-medium'
                                    : 'text-muted-foreground hover:bg-orange-50 dark:hover:bg-gray-800 hover:text-foreground'">
                                <component :is="item.icon" class="w-4 h-4 shrink-0" />
                                <span class="flex-1 text-left">{{ item.label }}</span>
                                <span v-if="navBadge(item.key)" class="text-[10px] font-medium px-1.5 py-0.5 rounded-md"
                                    :class="tab === item.key ? 'bg-orange-100 text-orange-600' : 'bg-muted text-muted-foreground'">{{ navBadge(item.key) }}</span>
                            </button>
                        </template>
                    </div>
                </template>
            </nav>
            <div class="border-t border-gray-200 p-2 space-y-1">
                <div v-if="!sidebarEffective" class="flex items-center gap-2.5 px-2 py-1">
                    <Avatar class="h-7 w-7 rounded-md">
                        <AvatarFallback class="rounded-md bg-[#fff7ed] text-[#ea580c] text-[10px] font-semibold">{{ userInitials }}</AvatarFallback>
                    </Avatar>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium truncate">{{ userName }}</div>
                    </div>
                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0" @click="switchTab('settings')" title="Einstellungen"><Settings class="w-3.5 h-3.5" /></Button>
                    <Button variant="ghost" size="sm" class="h-7 w-7 p-0" @click.prevent="useForm({}).post(route('logout'))" title="Abmelden"><LogOut class="w-3.5 h-3.5" /></Button>
                </div>
                <div v-if="sidebarEffective" class="flex flex-col items-center gap-1">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Avatar class="h-7 w-7 rounded-md cursor-default">
                                <AvatarFallback class="rounded-md bg-[#fff7ed] text-[#ea580c] text-[10px] font-semibold">{{ userInitials }}</AvatarFallback>
                            </Avatar>
                        </TooltipTrigger>
                        <TooltipContent side="right">{{ userName }}</TooltipContent>
                    </Tooltip>
                    <Tooltip v-if="isAdmin">
                        <TooltipTrigger asChild>
                            <Button variant="ghost" size="sm" class="h-7 w-7 p-0" @click="switchTab('settings')"><Settings class="w-3.5 h-3.5" /></Button>
                        </TooltipTrigger>
                        <TooltipContent side="right">Einstellungen</TooltipContent>
                    </Tooltip>
                </div>
                <button v-if="!sidebarEffective" @click="toggleDarkMode()" class="flex items-center gap-2 px-2 py-1 w-full text-left rounded-md hover:bg-orange-50 dark:hover:bg-gray-800 transition-colors">
                    <Moon v-if="!darkMode" class="w-3.5 h-3.5 text-muted-foreground" />
                    <Sun v-else class="w-3.5 h-3.5 text-muted-foreground" />
                    <span class="text-[10px] font-medium text-muted-foreground">{{ darkMode ? 'Light Mode' : 'Dark Mode' }}</span>
                </button>
            </div>
        </aside>
        </TooltipProvider>

        <!-- Main -->
        <div class="flex-1 flex flex-col overflow-hidden" :class="'bg-white dark:bg-background'">
            <div class="px-3 py-1.5 md:px-6 md:py-2 flex items-center justify-between border-b border-[var(--border)]">
                <div class="flex items-center gap-2">
                    <button @click="mobileOpen = true" class="md:hidden flex items-center justify-center w-7 h-7 rounded-md -ml-1 text-muted-foreground hover:text-foreground hover:bg-orange-50 dark:hover:bg-gray-800 transition-colors" title="Menü"><PanelLeft class="w-4 h-4" /></button>
                    <button @click="toggleSidebar()" class="hidden md:flex items-center justify-center w-7 h-7 rounded-md -ml-1 text-muted-foreground hover:text-foreground hover:bg-orange-50 dark:hover:bg-gray-800 transition-colors" title="Sidebar"><PanelLeft class="w-4 h-4" /></button>
                    <h1 class="text-sm font-semibold">{{ pageTitle }}</h1>
                    <span v-if="pageBadge" class="text-[10px] font-medium px-1.5 py-0.5 rounded-md bg-orange-100 text-orange-600">{{ pageBadge }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Notification Bell -->
                    <div class="relative">
                        <button @click.stop="bellOpen = !bellOpen; if (bellOpen && !notifLoaded) loadNotifications()" class="bell-button btn btn-ghost btn-icon btn-sm relative">
                            <Bell class="w-4 h-4" />
                            <span v-if="notifCount > 0" class="absolute -top-0.5 -right-0.5 w-4.5 h-4.5 rounded-full text-[9px] font-bold flex items-center justify-center text-white" style="background:#ef4444;min-width:18px;height:18px;padding:0 4px">{{ notifCount > 9 ? '9+' : notifCount }}</span>
                        </button>
                        <!-- Dropdown -->
                        <Transition enter-active-class="transition ease-out duration-100" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition ease-in duration-75" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
                            <div v-if="bellOpen" class="bell-dropdown absolute right-0 top-full mt-2 w-[calc(100vw-1.5rem)] sm:w-80 max-w-sm rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xl z-50 overflow-hidden">
                                <div class="px-4 py-3 border-b border-[var(--border)] flex items-center justify-between">
                                    <span class="text-sm font-semibold">Hinweise</span>
                                    <button @click="loadNotifications()" class="btn btn-ghost btn-sm" style="height:24px;padding:0 6px">
                                        <Sparkles class="w-3 h-3" :class="{ 'animate-spin': notifLoading }" />
                                    </button>
                                </div>
                                <div v-if="notifLoading && !notifications.length" class="px-4 py-6 text-center"><span class="spinner"></span></div>
                                <div v-else-if="!notifications.length" class="px-4 py-6 text-center text-sm text-[var(--muted-foreground)]">Alles erledigt!</div>
                                <div v-else class="divide-y divide-[var(--border)] max-h-80 overflow-y-auto">
                                    <div v-for="n in notifications" :key="n.id" @click="notifClick(n)" class="px-4 py-3 flex items-center gap-3 hover:bg-[var(--accent)] cursor-pointer transition-colors">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :style="'background:' + n.color + '15'">
                                            <AlertTriangle v-if="n.icon === 'alert'" class="w-4 h-4" :style="'color:' + n.color" />
                                            <InboxIcon v-else-if="n.icon === 'inbox'" class="w-4 h-4" :style="'color:' + n.color" />
                                            <ClockIcon v-else-if="n.icon === 'clock'" class="w-4 h-4" :style="'color:' + n.color" />
                                            <Users v-else-if="n.icon === 'users'" class="w-4 h-4" :style="'color:' + n.color" />
                                            <HomeIcon v-else-if="n.icon === 'home'" class="w-4 h-4" :style="'color:' + n.color" />
                                            <svg v-else-if="n.icon === 'calendar'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="n.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                            <Bell v-else class="w-4 h-4" :style="'color:' + n.color" />
                                        </div>
                                        <span class="text-[13px] font-medium flex-1">{{ n.text }}</span>
                                        <button @click.stop="dismissNotification(n)" class="w-6 h-6 rounded-md flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/20 text-[var(--muted-foreground)] hover:text-red-500 transition-colors flex-shrink-0" title="Erledigt"><X class="w-3.5 h-3.5" /></button>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
            <div class="flex-1 overflow-x-hidden" :class="tab === 'inbox' ? 'overflow-hidden h-full' : 'overflow-y-auto'">
                <TodayTab v-if="tab === 'today'" :stats="stats" :dark-mode="darkMode" />
                <!-- <PrioritiesTab v-if="tab === 'priorities'" /> -->
                <InboxTab v-if="tab === 'inbox'" />
                <!-- <CommsTab v-if="tab === 'comms'" /> -->
                <EmailAccountsTab v-if="tab === 'email_accounts'" />
                <PropertiesTab v-if="tab === 'properties'" />
                <ReportsTab v-if="tab === 'reports'" />
                <AnalyticsTab v-if="tab === 'analytics'" :dark-mode="darkMode" />
                <AdminTab v-if="tab === 'admin'" />
                <CalendarTab v-if="tab === 'calendar'" />
                <AssistenzTasksTab v-if="tab === 'tasks'" />
                <SettingsTab v-if="tab === 'settings'" />
                <WebsiteTab v-if="tab === 'website'" />
                <BlogTab v-else-if="tab === 'blog'" />
            </div>
        </div>

        <!-- Viewing Creation Modal -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showViewingForm" class="fixed inset-0 z-[100] flex items-center justify-center" @click.self="showViewingForm = false">
                <div class="fixed inset-0 bg-black/40"></div>
                <div class="relative bg-[var(--card)] rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden border border-[var(--border)]">
                    <div class="px-6 py-4 border-b border-[var(--border)] flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold">Besichtigungstermin eintragen</h3>
                            <p class="text-xs text-[var(--muted-foreground)] mt-0.5">{{ viewingFormData.stakeholder }} &middot; {{ viewingFormData.refId }}</p>
                        </div>
                        <button @click="showViewingForm = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                    </div>
                    <div v-if="viewingFormData.parsedDates?.length" class="px-5 py-3 flex items-start gap-2 border-b border-[var(--border)]" style="background:rgba(59,130,246,0.04)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <div>
                            <div class="text-[11px] font-medium text-blue-600 mb-1">In der Nachricht genannte Termine:</div>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="(pd, pi) in viewingFormData.parsedDates" :key="pi" @click="viewingFormData.date = pd.date; viewingFormData.time = pd.time" class="text-[11px] px-2.5 py-1 rounded-lg border font-medium transition-all" :class="viewingFormData.date === pd.date && viewingFormData.time === pd.time ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'border-blue-200 text-blue-700 hover:border-blue-400 bg-white'">{{ pd.label }}</button>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <div>
                            <label class="text-xs font-medium text-[var(--muted-foreground)] mb-1 block">Datum</label>
                            <input v-model="viewingFormData.date" type="date" class="form-input w-full" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-medium text-[var(--muted-foreground)] mb-1 block">Uhrzeit</label>
                                <input v-model="viewingFormData.time" type="time" class="form-input w-full" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-[var(--muted-foreground)] mb-1 block">Dauer (Min)</label>
                                <select v-model="viewingFormData.duration" class="form-select w-full">
                                    <option :value="30">30 Min</option>
                                    <option :value="45">45 Min</option>
                                    <option :value="60">60 Min</option>
                                    <option :value="90">90 Min</option>
                                    <option :value="120">2 Stunden</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-[var(--muted-foreground)] mb-1 block">Adresse</label>
                            <input v-model="viewingFormData.address" type="text" class="form-input w-full" placeholder="Wird als Ort im Kalender eingetragen" />
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-[var(--border)] flex items-center justify-end gap-2">
                        <button @click="showViewingForm = false" class="btn btn-outline btn-sm">Abbrechen</button>
                        <button @click="submitViewingForm()" :disabled="viewingSubmitting" class="btn btn-primary btn-sm flex items-center gap-2">
                            <span v-if="viewingSubmitting" class="spinner" style="width:14px;height:14px;border-width:1.5px"></span>
                            <Calendar class="w-3.5 h-3.5" v-else />
                            Termin erstellen
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
        <!-- Toasts -->
        <div class="fixed bottom-4 right-4 space-y-2 z-50">
            <TransitionGroup name="toast">
                <div v-for="t in toasts" :key="t.id" class="toast-notification">
                    <CheckCircle class="w-4 h-4 flex-shrink-0" />
                    <span>{{ t.msg }}</span>
                    <button @click="toasts = toasts.filter(x => x.id !== t.id)"><X class="w-3 h-3" /></button>
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>

<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@700;800&display=swap");
:root {
    --background: #f4f5f7; --foreground: #0a0a18;
    --card: #ffffff; --card-foreground: #0a0a18;
    --primary: #0f172a; --primary-foreground: #f8fafc;
    --secondary: #f1f5f9; --secondary-foreground: #0f172a;
    --muted: #f1f5f9; --muted-foreground: #64748b;
    --accent: #f1f5f9; --accent-foreground: #0f172a;
    --destructive: 0 72% 51%; --destructive-foreground: 0 0% 98%;
    --border: #e9ecf1; --input: #e2e8f0; --ring: #94a3b8;
    --radius: 0.625rem;
    --brand: #f97316; --brand-light: #fff7ed;
}
html.dark {
    --background: #020817; --foreground: #f8fafc;
    --card: #020817; --card-foreground: #f8fafc;
    --primary: #f8fafc; --primary-foreground: #0f172a;
    --secondary: #1e293b; --secondary-foreground: #f8fafc;
    --muted: #1e293b; --muted-foreground: #94a3b8;
    --accent: #1e293b; --accent-foreground: #f8fafc;
    --destructive: 0 84% 60%; --destructive-foreground: 0 0% 98%;
    --border: rgba(255,255,255,0.1); --input: rgba(255,255,255,0.15); --ring: #475569;
    --brand-light: rgba(249,115,22,0.08);
}
body { font-family: "Inter", system-ui, sans-serif; font-size: 14px; -webkit-font-smoothing: antialiased; }
.font-display { font-family: "Manrope", "Inter", system-ui, sans-serif; }

.card { background: var(--card); border-radius: calc(var(--radius) + 4px); border: 1px solid var(--border); box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); }
html.dark .card { box-shadow: none; }
.stat-tile { background: var(--card); border-radius: calc(var(--radius) + 4px); padding: 14px 16px; border: 1px solid var(--border); box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); transition: all 0.15s; cursor: pointer; }
.stat-tile:hover { border-color: var(--ring); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
html.dark .stat-tile { box-shadow: none; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 500; font-size: 14px; transition: all 0.15s; white-space: nowrap; border-radius: calc(var(--radius) - 2px); cursor: pointer; height: 36px; padding: 0 16px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); border: none; }
.btn-primary { background: var(--primary); color: var(--primary-foreground); }
.btn-outline { border: 1px solid var(--border); color: var(--foreground); background: var(--background); }
.btn-outline:hover { background: var(--accent); }
.btn-ghost { color: var(--muted-foreground); box-shadow: none; background: transparent; }
.btn-ghost:hover { background: var(--accent); }
.btn-destructive { background: hsl(var(--destructive)); color: hsl(var(--destructive-foreground)); }
.btn-brand { background: var(--brand); color: white; }
.btn-secondary { background: var(--secondary); color: var(--secondary-foreground); }
.btn-sm { height: 32px; padding: 0 12px; font-size: 12px; }
.btn-icon { height: 36px; width: 36px; padding: 0; }
.btn-icon.btn-sm { height: 32px; width: 32px; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.form-input, .form-textarea, .form-select { width: 100%; padding: 8px 12px; font-size: 13px; background: transparent; color: var(--foreground); border: 1px solid var(--input); border-radius: calc(var(--radius) - 2px); height: 38px; }
html.dark .form-input, html.dark .form-textarea, html.dark .form-select { background: rgba(255,255,255,0.05); }
.form-textarea { height: auto; min-height: 80px; }
.form-input:focus, .form-textarea:focus, .form-select:focus { outline: none; border-color: var(--ring); box-shadow: 0 0 0 3px rgba(148,163,184,0.3); }
.form-label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; }

.badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 500; white-space: nowrap; border: 1px solid transparent; }
.badge-success { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
html.dark .badge-success { background: rgba(16,185,129,0.1); color: #34d399; }
.badge-warning { background: #fffbeb; color: #b45309; border-color: #fde68a; }
html.dark .badge-warning { background: rgba(245,158,11,0.1); color: #fbbf24; }
.badge-danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
html.dark .badge-danger { background: rgba(239,68,68,0.1); color: #f87171; }
.badge-info { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
html.dark .badge-info { background: rgba(59,130,246,0.1); color: #60a5fa; }
.badge-purple { background: #faf5ff; color: #7c3aed; border-color: #ddd6fe; }
html.dark .badge-purple { background: rgba(139,92,246,0.1); color: #a78bfa; }
.badge-muted { background: var(--muted); color: var(--muted-foreground); }
.badge-accent { background: var(--brand-light); color: var(--brand); }

.follow-card { background: var(--card); border: 1px solid var(--border); border-radius: calc(var(--radius) + 4px); padding: 16px; }
.follow-card:hover { border-color: var(--ring); }
.follow-card.urgency-critical { border-left: 3px solid #9333ea; }
.follow-card.urgency-urgent { border-left: 3px solid #ef4444; }
.follow-card.urgency-warning { border-left: 3px solid #f59e0b; }
.follow-card.urgency-info { border-left: 3px solid #3b82f6; }

.toast-notification { position: relative; padding: 12px 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 8px; max-width: 24rem; font-size: 14px; font-weight: 500; background: var(--foreground); color: var(--background); border-radius: var(--radius); animation: toastIn 0.2s ease-out; }
@keyframes toastIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.toast-enter-active { animation: toastIn 0.2s ease-out; }
.toast-leave-active { transition: all 0.15s ease-in; }
.toast-leave-to { opacity: 0; transform: translateY(8px); }

.spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.dtable { width: 100%; font-size: 14px; border-collapse: separate; border-spacing: 0; }
.dtable th { padding: 8px; text-align: left; font-weight: 500; height: 40px; color: var(--foreground); border-bottom: 1px solid var(--border); }
.dtable td { padding: 8px; color: var(--foreground); border-bottom: 1px solid var(--border); }
.dtable tbody tr:hover { background: rgba(0,0,0,0.02); }
html.dark .dtable tbody tr:hover { background: rgba(255,255,255,0.02); }

html.dark .apexcharts-text { fill: #64748b !important; }
html.dark .apexcharts-gridline { stroke: #1e293b !important; }
html.dark .apexcharts-tooltip { background: #1e293b !important; color: #f8fafc !important; }
html.dark .apexcharts-legend-text { color: #94a3b8 !important; }
</style>
