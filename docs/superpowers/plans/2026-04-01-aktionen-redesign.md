# Aktionen-Seite Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite `PrioritiesTab.vue` from 7 overloaded tabs (~3800 lines) into a clean 2-tab layout (Offen + Nachfassen) with shadcn-vue components and a Sheet detail panel.

**Architecture:** Single-file rewrite of `PrioritiesTab.vue`. All existing API calls and backend endpoints stay unchanged — this is a pure frontend rewrite. The new component uses shadcn Tabs for navigation, a mail-list pattern for items, and a Sheet for detail/reply. Auto-Reply logs move from a tab to a collapsible banner.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), shadcn-vue (New York style), Tailwind CSS, Lucide icons

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `resources/js/Components/Admin/PrioritiesTab.vue` | **Rewrite** | All Aktionen functionality: tabs, list, sheet, auto-reply banner |
| `resources/js/components/ui/tabs/` | **Install** | shadcn Tabs component (new) |
| `resources/js/components/ui/collapsible/` | **Install** | shadcn Collapsible component (new) |
| `resources/js/components/ui/textarea/` | **Install** | shadcn Textarea component (new) |
| `resources/js/components/ui/scroll-area/` | **Install** | shadcn ScrollArea component (new) |

---

### Task 1: Install Missing shadcn Components

**Files:**
- Create: `resources/js/components/ui/tabs/` (via npx)
- Create: `resources/js/components/ui/collapsible/` (via npx)
- Create: `resources/js/components/ui/textarea/` (via npx)
- Create: `resources/js/components/ui/scroll-area/` (via npx)

- [ ] **Step 1: Install shadcn-vue components**

```bash
cd /var/www/srhomes
npx shadcn-vue@latest add tabs collapsible textarea scroll-area --yes
```

Expected: Components installed to `resources/js/components/ui/tabs/`, `collapsible/`, `textarea/`, `scroll-area/`

- [ ] **Step 2: Verify installation**

```bash
ls resources/js/components/ui/tabs/ resources/js/components/ui/collapsible/ resources/js/components/ui/textarea/ resources/js/components/ui/scroll-area/
```

Expected: Each directory contains `index.ts` or Vue files

- [ ] **Step 3: Test build compiles**

```bash
npm run build 2>&1 | tail -5
```

Expected: Build succeeds (no errors from new components)

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/ui/tabs/ resources/js/components/ui/collapsible/ resources/js/components/ui/textarea/ resources/js/components/ui/scroll-area/
git commit -m "feat: install shadcn tabs, collapsible, textarea, scroll-area components"
```

---

### Task 2: Rewrite PrioritiesTab — Script Section (State + API Functions)

**Files:**
- Modify: `resources/js/Components/Admin/PrioritiesTab.vue` (complete rewrite)

This task replaces the entire `<script setup>` block. The new script keeps all essential API functions but removes dead code for removed tabs (insights, matching, kanban, angebote, onhold).

- [ ] **Step 1: Back up current file**

```bash
cp /var/www/srhomes/resources/js/Components/Admin/PrioritiesTab.vue /var/www/srhomes/resources/js/Components/Admin/PrioritiesTab.vue.bak
```

- [ ] **Step 2: Write the new script section**

Replace the entire content of `PrioritiesTab.vue` with the code below. This step writes the full `<script setup>` block. The template will be added in Task 3.

```vue
<script setup>
import { ref, inject, onMounted, computed, watch } from "vue";
import { catBadgeStyle, catLabel } from '@/utils/categoryBadge.js';
import {
    Mail, Clock, Send, CheckCircle, X, ChevronDown, ChevronUp,
    CalendarDays, Paperclip, Loader2, Search, Sparkles, ArrowUp, ArrowDown
} from "lucide-vue-next";

// shadcn-vue components
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Input } from "@/components/ui/input";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from "@/components/ui/collapsible";
import { Textarea } from "@/components/ui/textarea";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Separator } from "@/components/ui/separator";

// Injections from Dashboard.vue
const API = inject("API");
const toast = inject("toast");
const switchTab = inject("switchTab");
const unansweredCount = inject("unansweredCount");
const followupCount = inject("followupCount");
const refreshCounts = inject("refreshCounts", () => {});
const properties = inject("properties");
const calendarEmbedUrl = inject("calendarEmbedUrl", "");
const userType = inject("userType", ref("makler"));
const isAssistenz = computed(() => ['assistenz', 'backoffice'].includes(userType.value));

// ─── Tab State ───
const activeTab = ref('offen');

// ─── Unanswered (Offen) State ───
const unansweredList = ref([]);
const unansweredLoading = ref(false);
const unansweredFilter = ref("all");

// ─── Followup (Nachfassen) State ───
const followupData = ref(null);
const followupLoading = ref(false);
const followupFilter = ref("all");
const stage1Followups = ref([]);
const stage1Count = ref(0);
const stage1Loading = ref(false);

// ─── Search & Filter ───
const searchQuery = ref('');
const objectFilter = ref('all');
const categoryFilter = ref('all');

// ─── Sheet State ───
const sheetOpen = ref(false);
const selectedItem = ref(null);
const sheetMode = ref('offen'); // 'offen' | 'nachfassen'
const expandedDetail = ref(null);
const expandedLoading = ref(false);
const expandedAiDraft = ref(null);
const expandedAiLoading = ref(false);
const expandedFiles = ref([]);
const expandedFilesLoading = ref(false);
const expandedSelectedFiles = ref([]);
const expandedBodyFull = ref(true); // default open
const showThreadAccordion = ref(false);
const showEmailFields = ref(false);
const showCalendar = ref(false);
const showAttachPopup = ref(false);
const aiSending = ref(false);
const followupSending = ref(false);

// ─── AI Detail Level ───
const aiDetailLevel = ref(localStorage.getItem("sr-ai-detail-level") || "standard");

// ─── Send Account ───
const sendAccounts = ref([]);
const sendAccountId = ref(null);

// ─── Recipient Email Edit ───
const recipientEmailSaving = ref(false);
const recipientEmailSaved = ref(false);

// ─── Auto-Reply Banner ───
const autoReplyLogs = ref([]);
const autoReplyLoading = ref(false);
const autoReplyBannerOpen = ref(false);

// ─── Broker Filter (Assistenz) ───
const maklerFilter = ref('all');
const brokerList = ref([]);

// ─── Computed: Filtered Lists ───
const filteredUnanswered = computed(() => {
    let items = unansweredList.value;
    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        items = items.filter(i =>
            (i.from_name || '').toLowerCase().includes(q) ||
            (i.stakeholder || '').toLowerCase().includes(q) ||
            (i.subject || '').toLowerCase().includes(q)
        );
    }
    if (objectFilter.value !== 'all') {
        items = items.filter(i => String(i.property_id) === objectFilter.value);
    }
    if (categoryFilter.value !== 'all') {
        items = items.filter(i => i.category === categoryFilter.value);
    }
    return items;
});

const allFollowups = computed(() => {
    const regular = followupData.value?.followups || [];
    const s1 = stage1Followups.value || [];
    return [...s1, ...regular];
});

const filteredFollowups = computed(() => {
    let items = allFollowups.value;
    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        items = items.filter(i =>
            (i.from_name || '').toLowerCase().includes(q) ||
            (i.stakeholder || '').toLowerCase().includes(q) ||
            (i.subject || i.activity || '').toLowerCase().includes(q)
        );
    }
    if (objectFilter.value !== 'all') {
        items = items.filter(i => String(i.property_id) === objectFilter.value);
    }
    if (categoryFilter.value !== 'all') {
        items = items.filter(i => i.category === categoryFilter.value);
    }
    return items;
});

// Unique properties for object filter dropdown
const availableProperties = computed(() => {
    const all = [...unansweredList.value, ...(followupData.value?.followups || []), ...stage1Followups.value];
    const map = {};
    all.forEach(i => { if (i.property_id && i.property_title) map[i.property_id] = i.property_title; });
    return Object.entries(map).map(([id, title]) => ({ id, title }));
});

// Unique categories for category filter dropdown
const availableCategories = computed(() => {
    const all = [...unansweredList.value, ...(followupData.value?.followups || []), ...stage1Followups.value];
    const cats = new Set();
    all.forEach(i => { if (i.category) cats.add(i.category); });
    return [...cats];
});

// ─── Helper Functions ───
function getInitials(name) {
    if (!name) return '??';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return parts[0].substring(0, 2).toUpperCase();
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    const now = new Date();
    const diffMs = now - d;
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return 'gerade';
    if (diffMin < 60) return 'vor ' + diffMin + ' Min.';
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return 'vor ' + diffH + ' Std.';
    const diffD = Math.floor(diffH / 24);
    if (diffD === 1) return 'vor 1 Tag';
    if (diffD < 30) return 'vor ' + diffD + ' Tagen';
    return 'vor ' + Math.floor(diffD / 30) + ' Mon.';
}

function formatDetailDate(s) {
    if (!s) return "";
    if (s.includes(" ") || s.includes("T")) {
        const d = new Date(s.replace(" ", "T"));
        return d.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" }) + ", " + d.toLocaleTimeString("de-AT", { hour: "2-digit", minute: "2-digit" });
    }
    return s.split("-").reverse().join(".");
}

function stripQuotedReply(text) {
    if (!text) return '';
    const patterns = [
        /\n\s*Am \d{1,2}\.\d{1,2}\.\d{2,4}\s+um\s+\d{1,2}:\d{2}\s+schrieb/i,
        /\n\s*On .+ wrote:/i,
        /\n\s*Von:\s*.+\s*Gesendet:/i,
        /\n\s*From:\s*.+\s*Sent:/i,
        /\n-{3,}\s*Original/i,
        /\n-{3,}\s*Weitergeleitete/i,
        /\n_{3,}/,
        /\n\s*>\s*>/,
        /\n\s*Gesendet:\s/i,
    ];
    let cutIndex = text.length;
    for (const pat of patterns) {
        const m = text.match(pat);
        if (m && m.index < cutIndex) cutIndex = m.index;
    }
    let result = text.substring(0, cutIndex).trim();
    const sigPatterns = [
        /\n\s*--\s*\n/,
        /\n\s*Mit freundlichen Grüßen\s*\n.*$/is,
        /\n\s*Beste Grüße\s*\n.*$/is,
    ];
    for (const pat of sigPatterns) {
        const m = result.match(pat);
        if (m && m.index > 20) result = result.substring(0, m.index).trim();
    }
    return result || text.substring(0, 500);
}

// ─── API: Load Unanswered ───
async function loadUnanswered(filter) {
    unansweredFilter.value = filter;
    unansweredLoading.value = true;
    try {
        const brokerParam = (maklerFilter.value && maklerFilter.value !== 'all') ? "&broker_filter=" + maklerFilter.value : "";
        const url = API.value + "&action=followups&mode=unanswered&filter=" + filter + brokerParam;
        const r = await fetch(url);
        const d = await r.json();
        unansweredList.value = d.followups || [];
        unansweredCount.value = (d.total_open || 0) + (d.total_unmatched || 0);
    } catch (e) { toast("Fehler: " + e.message); }
    unansweredLoading.value = false;
    prefetchDrafts(unansweredList.value);
}

// ─── API: Load Followups ───
async function loadFollowups(filter) {
    followupFilter.value = filter;
    followupLoading.value = true;
    try {
        const brokerParam = (maklerFilter.value && maklerFilter.value !== 'all') ? "&broker_filter=" + maklerFilter.value : "";
        const r = await fetch(API.value + "&action=followups&mode=followup&filter=" + filter + brokerParam);
        followupData.value = await r.json();
        followupCount.value = followupData.value.total_followup || 0;
    } catch (e) { toast("Fehler: " + e.message); }
    followupLoading.value = false;
    const allFu = followupData.value?.followups || [];
    prefetchFollowupDrafts(allFu);
}

// ─── API: Load Stage1 Followups ───
async function loadStage1() {
    stage1Loading.value = true;
    try {
        const brokerParam = (maklerFilter.value && maklerFilter.value !== 'all') ? "&broker_filter=" + maklerFilter.value : "";
        const r = await fetch(API.value + "&action=followups_stage1" + brokerParam);
        const d = await r.json();
        stage1Followups.value = d.followups || [];
        stage1Count.value = d.total_stage1 || stage1Followups.value.length;
    } catch (e) { /* silent */ }
    stage1Loading.value = false;
}

// ─── API: Load Auto-Reply Logs ───
async function loadAutoReplyLogs() {
    autoReplyLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=auto_reply_recent");
        const res = await r.json();
        autoReplyLogs.value = res.logs || [];
    } catch (e) { console.error(e); }
    autoReplyLoading.value = false;
}

// ─── API: Load Broker List (Assistenz) ───
async function loadBrokerList() {
    if (!isAssistenz.value || brokerList.value.length) return;
    try {
        const r = await fetch(API.value + '&action=list_brokers');
        const d = await r.json();
        brokerList.value = (d.brokers || []).filter(b => ['admin', 'makler'].includes(b.user_type));
    } catch {}
}

// ─── API: Load Send Accounts ───
async function loadSendAccounts(brokerId) {
    sendAccounts.value = [];
    sendAccountId.value = null;
    try {
        const param = brokerId ? "&for_broker=" + brokerId : "";
        const r = await fetch(API.value + "&action=email_accounts" + param);
        const d = await r.json();
        sendAccounts.value = (d.accounts || []).filter(a => a.is_active !== false);
        if (sendAccounts.value.length) sendAccountId.value = sendAccounts.value[0].id;
    } catch {}
}

// ─── Prefetch AI Drafts ───
async function prefetchDrafts(items) {
    const needDraft = items.filter(i => !i.draft || !i.draft.body);
    if (!needDraft.length) return;
    const batch = needDraft.slice(0, 6);
    const promises = batch.map(item =>
        fetch(API.value + "&action=ai_reply", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email_id: item.id, tone: "professional", type: "activity", detail_level: "standard" })
        })
        .then(r => r.json())
        .then(d => {
            if (d.reply_text) {
                item.draft = { body: d.reply_text, subject: d.subject, to: d.to };
            }
        })
        .catch(() => {})
    );
    await Promise.all(promises);
}

async function prefetchFollowupDrafts(items) {
    if (!items || !items.length) return;
    const batch = items.filter(f => !f._prefetchedDraft).slice(0, 15);
    const promises = batch.map(f =>
        fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(f.from_name) + "&property_id=" + f.property_id)
        .then(r => r.json())
        .then(d => {
            if (d.draft) {
                f._prefetchedDraft = {
                    body: d.draft.email_body || "",
                    subject: d.draft.email_subject || ("Re: " + (f.subject || f.activity || "")),
                    to: d.email || f.from_email || f.contact_email || "",
                    phone: d.phone || f.contact_phone || "",
                    callScript: d.draft.call_script || null,
                    preferredAction: d.draft.preferred_action || "email",
                };
            }
        })
        .catch(() => {})
    );
    await Promise.all(promises);
}

// ─── Open Sheet Detail ───
async function openDetail(item, mode) {
    selectedItem.value = item;
    sheetMode.value = mode;
    sheetOpen.value = true;
    expandedDetail.value = null;
    expandedAiDraft.value = null;
    expandedFiles.value = [];
    expandedSelectedFiles.value = [];
    expandedBodyFull.value = true;
    showThreadAccordion.value = false;
    showEmailFields.value = false;
    showCalendar.value = false;
    showAttachPopup.value = false;
    expandedLoading.value = true;
    expandedAiLoading.value = true;

    // Show pre-generated draft instantly if available
    if (mode === 'offen') {
        if (item.draft && item.draft.body) {
            expandedAiDraft.value = {
                body: item.draft.body,
                subject: item.draft.subject || ("Re: " + (item.subject || "")),
                to: item.draft.to || item.from_email || item.contact_email || "",
                prospect_email: item.draft.to || "",
            };
            expandedAiLoading.value = false;
        }
    } else {
        // Followup mode
        if (item._prefetchedDraft) {
            expandedAiDraft.value = item._prefetchedDraft;
            expandedAiLoading.value = false;
        } else if (item.draft && item.draft.body) {
            expandedAiDraft.value = {
                body: item.draft.body || "",
                subject: item.draft.subject || ("Re: " + (item.subject || item.activity || "")),
                to: item.draft.to || item.from_email || item.contact_email || "",
                phone: item.contact_phone || "",
            };
            expandedAiLoading.value = false;
        }
    }

    // Load send accounts
    loadSendAccounts(item.broker_id);

    // Load property files
    expandedFilesLoading.value = true;
    if (item.property_id) {
        fetch(API.value + "&action=get_property_files&property_id=" + item.property_id)
            .then(r => r.json())
            .then(d => { expandedFiles.value = d.files || []; })
            .catch(() => {})
            .finally(() => { expandedFilesLoading.value = false; });
    } else { expandedFilesLoading.value = false; }

    // Load email context (thread)
    const contextPromise = fetch(API.value + "&action=email_context&email_id=" + item.id + "&type=activity")
        .then(r => r.json())
        .then(d => { expandedDetail.value = { email: d.email || null, thread: d.thread || [] }; })
        .catch(e => { toast("Fehler: " + e.message); })
        .finally(() => { expandedLoading.value = false; });

    // Generate AI draft if not pre-generated
    const promises = [contextPromise];
    if (!expandedAiDraft.value) {
        if (mode === 'offen') {
            const aiPromise = fetch(API.value + "&action=ai_reply", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ email_id: item.id, tone: "professional", type: "activity", detail_level: aiDetailLevel.value }),
            })
            .then(r => r.json())
            .then(d => {
                if (d.reply_text) {
                    expandedAiDraft.value = {
                        body: d.reply_text,
                        subject: d.subject || ("Re: " + (item.subject || "")),
                        to: d.prospect_email || d.to || item.from_email || "",
                        prospect_email: d.prospect_email || "",
                    };
                    item.draft = { body: d.reply_text, subject: d.subject, to: d.to };
                }
            })
            .catch(() => {})
            .finally(() => { expandedAiLoading.value = false; });
            promises.push(aiPromise);
        } else {
            const draftPromise = fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(item.from_name) + "&property_id=" + item.property_id)
            .then(r => r.json())
            .then(d => {
                if (d.draft) {
                    expandedAiDraft.value = {
                        body: d.draft.email_body || "",
                        subject: d.draft.email_subject || ("Re: " + (item.subject || item.activity || "")),
                        to: d.email || item.from_email || item.contact_email || "",
                        phone: d.phone || item.contact_phone || "",
                    };
                }
            })
            .catch(() => {})
            .finally(() => { expandedAiLoading.value = false; });
            promises.push(draftPromise);
        }
    }
    await Promise.all(promises);
}

// ─── AI Detail Level Change ───
function setAiDetailLevel(level) {
    aiDetailLevel.value = level;
    localStorage.setItem("sr-ai-detail-level", level);
    regenerateAiDraft();
}

async function regenerateAiDraft() {
    const item = selectedItem.value;
    if (!item) return;
    expandedAiDraft.value = null;
    expandedAiLoading.value = true;
    try {
        if (sheetMode.value === 'offen') {
            const r = await fetch(API.value + "&action=ai_reply", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ email_id: item.id, tone: "professional", type: "activity", detail_level: aiDetailLevel.value }),
            });
            const d = await r.json();
            if (d.reply_text) {
                expandedAiDraft.value = {
                    body: d.reply_text,
                    subject: d.subject || ("Re: " + (item.subject || "")),
                    to: d.prospect_email || d.to || item.from_email || "",
                    prospect_email: d.prospect_email || "",
                };
            }
        } else {
            const r = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(item.from_name) + "&property_id=" + item.property_id);
            const d = await r.json();
            if (d.draft) {
                expandedAiDraft.value = {
                    body: d.draft.email_body || "",
                    subject: d.draft.email_subject || ("Re: " + (item.subject || item.activity || "")),
                    to: d.email || item.from_email || item.contact_email || "",
                };
            }
        }
    } catch (e) { toast("KI-Fehler: " + e.message); }
    expandedAiLoading.value = false;
}

// ─── File Selection ───
function toggleFileSelection(fileId) {
    const idx = expandedSelectedFiles.value.indexOf(fileId);
    if (idx >= 0) expandedSelectedFiles.value.splice(idx, 1);
    else expandedSelectedFiles.value.push(fileId);
}

// ─── Save Recipient Email ───
async function saveRecipientEmail(stakeholder, propertyId, newEmail) {
    if (!newEmail || !stakeholder) return;
    recipientEmailSaving.value = true;
    recipientEmailSaved.value = false;
    try {
        const fd = new FormData();
        fd.append("stakeholder", stakeholder);
        fd.append("property_id", propertyId || "");
        fd.append("new_email", newEmail);
        const r = await fetch(API.value + "&action=update_recipient_email", { method: "POST", body: fd });
        const d = await r.json();
        if (d.success) {
            recipientEmailSaved.value = true;
            toast("✓ E-Mail-Adresse gespeichert: " + newEmail);
            setTimeout(() => { recipientEmailSaved.value = false; }, 2500);
        } else { toast("Fehler: " + (d.error || "Unbekannt")); }
    } catch (e) { toast("Fehler: " + e.message); }
    recipientEmailSaving.value = false;
}

// ─── Mark Handled ───
async function markHandled(stakeholder, propertyId) {
    try {
        const r = await fetch(API.value + "&action=mark_handled", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ stakeholder, property_id: propertyId, note: "Als erledigt markiert" }),
        });
        const d = await r.json();
        if (d.success) {
            toast("✓ Als erledigt markiert!");
            sheetOpen.value = false;
            selectedItem.value = null;
            loadUnanswered(unansweredFilter.value);
            loadFollowups(followupFilter.value);
            loadStage1();
            refreshCounts();
        } else { toast("Fehler: " + (d.error || "Unbekannt")); }
    } catch (e) { toast("Fehler: " + e.message); }
}

// ─── Send Email (Offen tab) ───
async function sendDraft() {
    const item = selectedItem.value;
    const draft = expandedAiDraft.value;
    if (!item || !draft) return;

    const itemName = item.from_name || item.from_email || "Kunde";
    const itemId = item.id;

    // Close sheet immediately
    sheetOpen.value = false;
    selectedItem.value = null;
    expandedAiDraft.value = null;

    // Remove from list for instant feedback
    unansweredList.value = unansweredList.value.filter(i => i.id !== itemId);

    // Store selected files before clearing
    const filesToSend = [...expandedSelectedFiles.value];
    const filesAvailable = [...expandedFiles.value];

    // Show sending toast
    const toastsContainer = document.querySelector(".fixed.bottom-4.right-4");
    let sendingEl = null;
    if (toastsContainer) {
        sendingEl = document.createElement("div");
        sendingEl.className = "toast-notification";
        sendingEl.style.cssText = "background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none";
        sendingEl.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span><span>Wird gesendet an ' + itemName + '...</span>';
        toastsContainer.appendChild(sendingEl);
    }

    try {
        // Load signature
        let sigText = "\n\n--\nSR-Homes Immobilien GmbH";
        let sigHtml = "";
        try {
            const sr = await fetch(API.value + "&action=get_settings");
            const sd = await sr.json();
            if (sd.signature_name) {
                sigText = "\n\n--\n" + (sd.signature_name||"") + "\n" + (sd.signature_title||"") + "\n" + (sd.signature_company||"") + "\nTel: " + (sd.signature_phone||"") + "\n" + (sd.signature_website||"");
                let sh = '<br><br><table cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333">';
                const hasPhoto = !!sd.signature_photo_url;
                const cs = hasPhoto ? 2 : 1;
                if (sd.signature_logo_url) sh += '<tr><td colspan="' + cs + '" style="padding-bottom:8px"><img src="' + sd.signature_logo_url + '" alt="Logo" style="max-height:60px;max-width:200px"></td></tr>';
                sh += '<tr>';
                if (hasPhoto) sh += '<td style="border-top:2px solid #D4622B;padding-top:8px;padding-right:12px;vertical-align:top"><img src="' + sd.signature_photo_url + '" alt="" style="width:70px;height:90px;object-fit:cover;border-radius:4px"></td>';
                sh += '<td style="border-top:2px solid #D4622B;padding-top:8px">';
                sh += '<strong style="font-size:14px;color:#222">' + (sd.signature_name||"") + '</strong>';
                if (sd.signature_title) sh += '<br><span style="color:#666">' + sd.signature_title + '</span>';
                sh += '<br><span style="color:#666">' + (sd.signature_company||"") + '</span>';
                sh += '<br>Tel: <a href="tel:' + (sd.signature_phone||"").replace(/\s/g,"") + '" style="color:#D4622B;text-decoration:none">' + (sd.signature_phone||"") + '</a>';
                sh += '<br><a href="https://' + (sd.signature_website||"") + '" style="color:#D4622B;text-decoration:none">' + (sd.signature_website||"") + '</a>';
                sh += '</td></tr>';
                if (sd.signature_banner_url) sh += '<tr><td colspan="' + cs + '" style="padding-top:8px"><img src="' + sd.signature_banner_url + '" alt="" style="max-width:400px;width:100%;border-radius:4px"></td></tr>';
                sh += '</table>';
                sigHtml = sh;
            }
        } catch {}

        let htmlBody = draft.body.replace(/\n/g, "<br>") + sigHtml;

        // Fetch file attachments
        const attachments = [];
        if (filesToSend.length && filesAvailable.length) {
            for (const fileId of filesToSend) {
                const ef = filesAvailable.find(f => f.id === fileId);
                if (ef && ef.url) {
                    try {
                        const resp = await fetch(ef.url);
                        const blob = await resp.blob();
                        attachments.push(new File([blob], ef.filename || ef.label, { type: blob.type }));
                    } catch {}
                }
            }
        }

        const fd = new FormData();
        const accountId = sendAccountId.value ? String(sendAccountId.value) : "1";
        fd.append("account_id", accountId);
        fd.append("to_email", draft.to || item.from_email || "");
        fd.append("to_name", item.from_name || item.stakeholder || "");
        fd.append("subject", draft.subject || "");
        fd.append("body_html", htmlBody);
        fd.append("body_text", draft.body + sigText);
        fd.append("property_id", item.property_id || "");
        fd.append("in_reply_to", String(item.id) || "");
        if (sheetMode.value === 'nachfassen') fd.append("is_followup", "1");
        for (const file of attachments) fd.append("attachments[]", file);

        const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
        const result = await r.json();

        if (sendingEl) sendingEl.remove();

        if (result.success) {
            toast("✓ Email an " + itemName + " gesendet!" + (attachments.length ? " (" + attachments.length + " Anhänge)" : ""));
            loadFollowups("all");
            loadUnanswered("all");
            loadStage1();
            refreshCounts();
        } else {
            toast("✗ Fehler beim Senden: " + (result.error || "Unbekannt"));
            loadUnanswered("all");
            loadFollowups("all");
        }
    } catch (e) {
        if (sendingEl) sendingEl.remove();
        toast("✗ Sende-Fehler: " + e.message);
        loadUnanswered("all");
        loadFollowups("all");
    }
}

// ─── Reassign Item ───
async function reassignItem(item, propertyId) {
    try {
        await fetch(API.value + "&action=reassign_email", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email_id: item.source_email_id || item.id, property_id: propertyId }),
        });
        item.property_id = propertyId;
        const p = properties.value?.find(pp => pp.id === propertyId);
        if (p) item.ref_id = p.ref_id;
        toast("Objekt zugewiesen");
    } catch (e) { toast("Fehler: " + e.message); }
}

// ─── Lifecycle ───
watch(maklerFilter, () => {
    loadUnanswered(unansweredFilter.value);
    loadFollowups(followupFilter.value);
    loadStage1();
});

onMounted(() => {
    loadUnanswered("all");
    loadFollowups("all");
    loadStage1();
    loadAutoReplyLogs();
    loadBrokerList();
});
</script>
```

- [ ] **Step 3: Verify file saved correctly**

```bash
head -5 /var/www/srhomes/resources/js/Components/Admin/PrioritiesTab.vue
```

Expected: Shows `<script setup>` with the new imports

- [ ] **Step 4: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/PrioritiesTab.vue
git commit -m "feat(aktionen): rewrite script section — clean state + API functions"
```

---

### Task 3: Rewrite PrioritiesTab — Template Section

**Files:**
- Modify: `resources/js/Components/Admin/PrioritiesTab.vue` (append template)

This task adds the `<template>` block after the `</script>` tag. It implements the full UI: Tabs, auto-reply banner, toolbar, item list, and Sheet detail panel.

- [ ] **Step 1: Append the template section**

Add this template after the closing `</script>` tag in `PrioritiesTab.vue`:

```vue
<template>
    <div class="space-y-4">

        <!-- Broker Filter (Assistenz only) -->
        <div v-if="isAssistenz && brokerList.length" class="flex items-center gap-2">
            <Select v-model="maklerFilter">
                <SelectTrigger class="w-48">
                    <SelectValue placeholder="Alle Makler" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Alle Makler</SelectItem>
                    <SelectItem v-for="b in brokerList" :key="b.id" :value="String(b.id)">
                        {{ b.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Tabs: Offen / Nachfassen -->
        <Tabs v-model="activeTab" class="w-full">
            <TabsList>
                <TabsTrigger value="offen">
                    Offen
                    <Badge v-if="unansweredCount" variant="destructive" class="ml-1.5 h-5 px-1.5 text-[10px]">
                        {{ unansweredCount }}
                    </Badge>
                </TabsTrigger>
                <TabsTrigger value="nachfassen">
                    Nachfassen
                    <Badge v-if="followupCount + stage1Count" variant="secondary" class="ml-1.5 h-5 px-1.5 text-[10px]">
                        {{ followupCount + stage1Count }}
                    </Badge>
                </TabsTrigger>
            </TabsList>

            <!-- Auto-Reply Banner -->
            <Collapsible v-if="autoReplyLogs.length" v-model:open="autoReplyBannerOpen" class="mt-3">
                <CollapsibleTrigger class="flex items-center justify-between w-full bg-green-50 border border-green-200 rounded-lg px-3 py-2 text-sm text-green-800 hover:bg-green-100 transition-colors cursor-pointer">
                    <div class="flex items-center gap-2">
                        <Send class="w-3.5 h-3.5 text-green-600" />
                        <span class="font-medium">{{ autoReplyLogs.length }} automatische Antwort{{ autoReplyLogs.length !== 1 ? 'en' : '' }} heute gesendet</span>
                    </div>
                    <ChevronDown class="w-4 h-4 text-green-600 transition-transform" :class="autoReplyBannerOpen && 'rotate-180'" />
                </CollapsibleTrigger>
                <CollapsibleContent class="mt-2 space-y-1">
                    <div v-for="(log, i) in autoReplyLogs" :key="i" class="flex items-center justify-between bg-white border border-green-100 rounded-md px-3 py-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-foreground">{{ log.to_name || log.to_email }}</span>
                            <span class="text-muted-foreground">{{ log.subject }}</span>
                        </div>
                        <span class="text-muted-foreground">{{ log.sent_at ? formatDetailDate(log.sent_at) : '' }}</span>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <!-- Toolbar: Search + Filters -->
            <div class="flex items-center gap-2 mt-3">
                <div class="relative flex-1">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input v-model="searchQuery" placeholder="Suche nach Name, Betreff..." class="pl-9" />
                </div>
                <Select v-model="objectFilter">
                    <SelectTrigger class="w-40">
                        <SelectValue placeholder="Objekt" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Alle Objekte</SelectItem>
                        <SelectItem v-for="p in availableProperties" :key="p.id" :value="p.id">
                            {{ p.title }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="categoryFilter">
                    <SelectTrigger class="w-40">
                        <SelectValue placeholder="Kategorie" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Alle Kategorien</SelectItem>
                        <SelectItem v-for="c in availableCategories" :key="c" :value="c">
                            {{ catLabel(c) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- TAB: Offen -->
            <TabsContent value="offen" class="mt-0">
                <ScrollArea class="h-[calc(100vh-280px)]">
                    <!-- Loading -->
                    <div v-if="unansweredLoading" class="flex items-center justify-center py-12">
                        <Loader2 class="w-6 h-6 animate-spin text-muted-foreground" />
                    </div>
                    <!-- Empty -->
                    <div v-else-if="!filteredUnanswered.length" class="flex flex-col items-center justify-center py-12 text-muted-foreground">
                        <CheckCircle class="w-10 h-10 mb-2 text-green-500" />
                        <p class="text-sm font-medium">Alles beantwortet!</p>
                        <p class="text-xs">Keine offenen Anfragen.</p>
                    </div>
                    <!-- Items -->
                    <div v-else class="space-y-1 pr-3">
                        <div
                            v-for="item in filteredUnanswered"
                            :key="item.id"
                            @click="openDetail(item, 'offen')"
                            class="rounded-lg border p-3 cursor-pointer transition-colors"
                            :class="selectedItem?.id === item.id && sheetOpen
                                ? 'bg-orange-50 border-orange-200'
                                : 'border-transparent hover:bg-muted/50'"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-2">
                                    <Avatar class="h-7 w-7">
                                        <AvatarFallback class="text-[10px] font-bold" :class="item.category === 'bounce' ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-600'">
                                            {{ getInitials(item.from_name || item.stakeholder) }}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span class="font-semibold text-sm">{{ item.from_name || item.stakeholder || 'Unbekannt' }}</span>
                                    <Badge v-if="item.category === 'bounce'" variant="destructive" class="text-[10px] h-5">Bounce</Badge>
                                    <Badge v-else-if="item.priority === 'high'" class="text-[10px] h-5 bg-red-50 text-red-600 border-0">Dringend</Badge>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ item.received_at ? timeAgo(item.received_at) : '' }}</span>
                            </div>
                            <div class="text-sm font-medium text-foreground line-clamp-1 ml-9">{{ item.subject || 'Kein Betreff' }}</div>
                            <div class="text-xs text-muted-foreground line-clamp-1 ml-9 mt-0.5">{{ item.ai_summary || item.body || '' }}</div>
                            <div class="flex gap-1 mt-1.5 ml-9">
                                <Badge v-if="item.platform" variant="outline" class="text-[10px] h-5 font-normal">{{ item.platform }}</Badge>
                                <Badge v-if="item.property_title" variant="outline" class="text-[10px] h-5 font-normal">{{ item.property_title }}</Badge>
                                <Badge v-if="item.category && item.category !== 'bounce'" variant="outline" class="text-[10px] h-5 font-normal" :style="catBadgeStyle(item.category)">{{ catLabel(item.category) }}</Badge>
                            </div>
                        </div>
                    </div>
                </ScrollArea>
            </TabsContent>

            <!-- TAB: Nachfassen -->
            <TabsContent value="nachfassen" class="mt-0">
                <ScrollArea class="h-[calc(100vh-280px)]">
                    <div v-if="followupLoading && stage1Loading" class="flex items-center justify-center py-12">
                        <Loader2 class="w-6 h-6 animate-spin text-muted-foreground" />
                    </div>
                    <div v-else-if="!filteredFollowups.length" class="flex flex-col items-center justify-center py-12 text-muted-foreground">
                        <CheckCircle class="w-10 h-10 mb-2 text-green-500" />
                        <p class="text-sm font-medium">Alle nachgefasst!</p>
                        <p class="text-xs">Keine Follow-ups offen.</p>
                    </div>
                    <div v-else class="space-y-1 pr-3">
                        <div
                            v-for="item in filteredFollowups"
                            :key="item.id"
                            @click="openDetail(item, 'nachfassen')"
                            class="rounded-lg border p-3 cursor-pointer transition-colors"
                            :class="selectedItem?.id === item.id && sheetOpen
                                ? 'bg-orange-50 border-orange-200'
                                : 'border-transparent hover:bg-muted/50'"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-2">
                                    <Avatar class="h-7 w-7">
                                        <AvatarFallback class="text-[10px] font-bold bg-slate-100 text-slate-600">
                                            {{ getInitials(item.from_name || item.stakeholder) }}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span class="font-semibold text-sm">{{ item.from_name || item.stakeholder || 'Unbekannt' }}</span>
                                    <Badge v-if="item.days_waiting >= 14" class="text-[10px] h-5 bg-red-50 text-red-600 border-0">{{ item.days_waiting }}d</Badge>
                                    <Badge v-else-if="item.days_waiting >= 7" class="text-[10px] h-5 bg-orange-50 text-orange-600 border-0">{{ item.days_waiting }}d</Badge>
                                    <Badge v-else-if="item.days_waiting >= 3" class="text-[10px] h-5 bg-blue-50 text-blue-600 border-0">{{ item.days_waiting }}d</Badge>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ item.days_waiting }}d</span>
                            </div>
                            <div class="text-sm font-medium text-foreground line-clamp-1 ml-9">{{ item.subject || item.activity || 'Nachfassen' }}</div>
                            <div class="text-xs text-muted-foreground line-clamp-1 ml-9 mt-0.5">{{ item.ai_summary || '' }}</div>
                            <div class="flex gap-1 mt-1.5 ml-9">
                                <Badge v-if="item.platform" variant="outline" class="text-[10px] h-5 font-normal">{{ item.platform }}</Badge>
                                <Badge v-if="item.property_title || item.ref_id" variant="outline" class="text-[10px] h-5 font-normal">{{ item.property_title || item.ref_id }}</Badge>
                                <Badge v-if="item.category" variant="outline" class="text-[10px] h-5 font-normal" :style="catBadgeStyle(item.category)">{{ catLabel(item.category) }}</Badge>
                            </div>
                        </div>
                    </div>
                </ScrollArea>
            </TabsContent>
        </Tabs>

        <!-- Sheet Detail Panel -->
        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full sm:max-w-[600px] p-0 flex flex-col">
                <template v-if="selectedItem">
                    <!-- Sheet Header -->
                    <SheetHeader class="p-4 pb-3 border-b space-y-0">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <Avatar class="h-8 w-8">
                                    <AvatarFallback class="text-xs font-bold bg-slate-100 text-slate-600">
                                        {{ getInitials(selectedItem.from_name || selectedItem.stakeholder) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div>
                                    <SheetTitle class="text-sm">{{ selectedItem.from_name || selectedItem.stakeholder || 'Unbekannt' }}</SheetTitle>
                                    <p class="text-xs text-muted-foreground">{{ selectedItem.from_email || selectedItem.contact_email || '' }}</p>
                                </div>
                            </div>
                            <span class="text-xs text-muted-foreground mt-1">
                                {{ selectedItem.received_at ? timeAgo(selectedItem.received_at) : (selectedItem.days_waiting ? selectedItem.days_waiting + 'd' : '') }}
                            </span>
                        </div>
                        <div class="flex gap-1 mt-2 ml-11">
                            <Badge v-if="selectedItem.platform" variant="outline" class="text-[10px] h-5 font-normal">{{ selectedItem.platform }}</Badge>
                            <Badge v-if="selectedItem.property_title || selectedItem.ref_id" variant="outline" class="text-[10px] h-5 font-normal">{{ selectedItem.property_title || selectedItem.ref_id }}</Badge>
                            <Badge v-if="selectedItem.category === 'bounce'" variant="destructive" class="text-[10px] h-5">Bounce</Badge>
                            <Badge v-else-if="selectedItem.priority === 'high'" class="text-[10px] h-5 bg-red-50 text-red-600 border-0">Dringend</Badge>
                            <Badge v-else-if="selectedItem.category" variant="outline" class="text-[10px] h-5 font-normal" :style="catBadgeStyle(selectedItem.category)">{{ catLabel(selectedItem.category) }}</Badge>
                        </div>
                    </SheetHeader>

                    <!-- Sheet Body (scrollable) -->
                    <ScrollArea class="flex-1">
                        <div class="p-4 space-y-4">

                            <!-- Loading state -->
                            <div v-if="expandedLoading" class="flex items-center justify-center py-8">
                                <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
                            </div>

                            <template v-else>
                                <!-- Bounce Warning -->
                                <div v-if="selectedItem.category === 'bounce'" class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">
                                    <strong>E-Mail unzustellbar</strong> — {{ selectedItem.ai_summary || 'Bitte überprüfen Sie die E-Mail-Adresse.' }}
                                </div>

                                <!-- Eingehende Nachricht (Collapsible) -->
                                <Collapsible v-model:open="expandedBodyFull">
                                    <CollapsibleTrigger class="flex items-center justify-between w-full py-2 text-sm font-medium hover:text-foreground transition-colors">
                                        <div class="flex items-center gap-2">
                                            <Mail class="w-4 h-4 text-muted-foreground" />
                                            <span>Eingehende Nachricht</span>
                                        </div>
                                        <ChevronDown class="w-4 h-4 text-muted-foreground transition-transform" :class="expandedBodyFull && 'rotate-180'" />
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div class="mt-1">
                                            <p class="text-sm font-medium mb-2">{{ selectedItem.subject || expandedDetail?.email?.subject || 'Kein Betreff' }}</p>
                                            <div class="bg-muted rounded-lg p-3 text-sm leading-relaxed max-h-[300px] overflow-y-auto whitespace-pre-wrap break-words">
                                                {{ stripQuotedReply(expandedDetail?.email?.body || selectedItem.body || selectedItem.ai_summary || '') }}
                                            </div>
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>

                                <Separator />

                                <!-- Verlauf (Collapsible, default closed) -->
                                <Collapsible v-model:open="showThreadAccordion">
                                    <CollapsibleTrigger class="flex items-center justify-between w-full py-2 text-sm font-medium hover:text-foreground transition-colors">
                                        <div class="flex items-center gap-2">
                                            <Clock class="w-4 h-4 text-muted-foreground" />
                                            <span>Verlauf</span>
                                            <Badge v-if="expandedDetail?.thread?.length" variant="secondary" class="text-[10px] h-5 px-1.5">
                                                {{ expandedDetail.thread.length }}
                                            </Badge>
                                        </div>
                                        <ChevronDown class="w-4 h-4 text-muted-foreground transition-transform" :class="showThreadAccordion && 'rotate-180'" />
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div v-if="expandedDetail?.thread?.length" class="mt-2 space-y-2">
                                            <div v-for="msg in expandedDetail.thread" :key="msg.id || msg.datetime" class="flex items-start gap-2 text-xs">
                                                <div class="mt-0.5">
                                                    <ArrowUp v-if="msg.direction === 'outbound' || msg.type === 'sent'" class="w-3.5 h-3.5 text-blue-500" />
                                                    <ArrowDown v-else class="w-3.5 h-3.5 text-slate-400" />
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium">{{ msg.direction === 'outbound' || msg.type === 'sent' ? 'SR-Homes' : (msg.from_name || msg.stakeholder || 'Eingehend') }}</span>
                                                        <span class="text-muted-foreground">{{ formatDetailDate(msg.datetime || msg.created_at || msg.date || msg.activity_date) }}</span>
                                                    </div>
                                                    <p v-if="msg.subject" class="text-muted-foreground mt-0.5">{{ msg.subject }}</p>
                                                    <p v-if="msg.body || msg.ai_summary" class="text-muted-foreground mt-0.5 line-clamp-2">{{ msg.body || msg.ai_summary }}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <p v-else class="text-xs text-muted-foreground mt-2">Kein Verlauf vorhanden.</p>
                                    </CollapsibleContent>
                                </Collapsible>

                                <Separator />

                                <!-- KI-Entwurf -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center gap-2 text-sm font-medium">
                                            <Sparkles class="w-4 h-4 text-orange-500" />
                                            <span>KI-Entwurf</span>
                                        </div>
                                        <Button variant="ghost" size="sm" class="h-7 text-xs text-muted-foreground" @click="showEmailFields = !showEmailFields">
                                            Von/An/Betr.
                                            <ChevronDown class="w-3 h-3 ml-1 transition-transform" :class="showEmailFields && 'rotate-180'" />
                                        </Button>
                                    </div>

                                    <!-- Email Fields (collapsible) -->
                                    <div v-if="showEmailFields" class="space-y-2 mb-3">
                                        <!-- Von -->
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="text-muted-foreground w-8">Von:</span>
                                            <select v-if="sendAccounts.length > 1" v-model="sendAccountId" class="flex-1 text-xs border rounded px-2 py-1 bg-background">
                                                <option v-for="acc in sendAccounts" :key="acc.id" :value="acc.id">{{ acc.email || acc.name }}</option>
                                            </select>
                                            <span v-else class="flex-1 text-xs">{{ sendAccounts[0]?.email || '—' }}</span>
                                        </div>
                                        <!-- An -->
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="text-muted-foreground w-8">An:</span>
                                            <Input
                                                v-if="expandedAiDraft"
                                                v-model="expandedAiDraft.to"
                                                class="flex-1 h-7 text-xs"
                                            />
                                            <Button
                                                v-if="expandedAiDraft"
                                                variant="ghost"
                                                size="sm"
                                                class="h-7 text-xs"
                                                :disabled="recipientEmailSaving"
                                                @click="saveRecipientEmail(selectedItem.stakeholder || selectedItem.from_name, selectedItem.property_id, expandedAiDraft.to)"
                                            >
                                                <CheckCircle v-if="recipientEmailSaved" class="w-3.5 h-3.5 text-green-500" />
                                                <template v-else>Speichern</template>
                                            </Button>
                                        </div>
                                        <!-- Betr. -->
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="text-muted-foreground w-8">Betr.:</span>
                                            <Input
                                                v-if="expandedAiDraft"
                                                v-model="expandedAiDraft.subject"
                                                class="flex-1 h-7 text-xs"
                                            />
                                        </div>
                                    </div>

                                    <!-- Draft Loading -->
                                    <div v-if="expandedAiLoading" class="flex items-center gap-2 py-6 justify-center text-sm text-muted-foreground">
                                        <Loader2 class="w-4 h-4 animate-spin" />
                                        <span>KI-Entwurf wird generiert...</span>
                                    </div>

                                    <!-- Draft Textarea -->
                                    <Textarea
                                        v-else-if="expandedAiDraft"
                                        v-model="expandedAiDraft.body"
                                        class="min-h-[160px] resize-y text-sm"
                                    />

                                    <!-- Draft Toolbar -->
                                    <div v-if="expandedAiDraft" class="flex items-center gap-2 mt-3 flex-wrap">
                                        <!-- Attachments -->
                                        <div class="relative">
                                            <Button variant="outline" size="sm" class="h-8 text-xs gap-1" @click="showAttachPopup = !showAttachPopup">
                                                <Paperclip class="w-3.5 h-3.5" />
                                                {{ expandedSelectedFiles.length ? expandedSelectedFiles.length + ' Dateien' : 'Anhänge' }}
                                            </Button>
                                            <!-- Attachment Popup -->
                                            <div v-if="showAttachPopup && expandedFiles.length" class="absolute bottom-full left-0 mb-1 bg-popover border rounded-lg shadow-lg p-2 z-50 w-64 max-h-48 overflow-y-auto">
                                                <div v-for="f in expandedFiles" :key="f.id" class="flex items-center gap-2 py-1 px-1 hover:bg-muted rounded cursor-pointer" @click="toggleFileSelection(f.id)">
                                                    <input type="checkbox" :checked="expandedSelectedFiles.includes(f.id)" class="rounded border-gray-300" />
                                                    <span class="text-xs truncate">{{ f.label || f.filename }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Detail Level -->
                                        <Select v-model="aiDetailLevel" @update:modelValue="setAiDetailLevel">
                                            <SelectTrigger class="w-28 h-8 text-xs">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="brief">Knapp</SelectItem>
                                                <SelectItem value="standard">Standard</SelectItem>
                                                <SelectItem value="detailed">Ausführlich</SelectItem>
                                            </SelectContent>
                                        </Select>

                                        <!-- Calendar -->
                                        <Button v-if="calendarEmbedUrl" variant="outline" size="sm" class="h-8" :class="showCalendar && 'bg-blue-50 border-blue-200'" @click="showCalendar = !showCalendar">
                                            <CalendarDays class="w-3.5 h-3.5" />
                                        </Button>

                                        <div class="flex-1" />

                                        <!-- Mark Handled -->
                                        <Button variant="outline" size="sm" class="h-8 text-xs gap-1" @click="markHandled(selectedItem.stakeholder || selectedItem.from_name, selectedItem.property_id)">
                                            <CheckCircle class="w-3.5 h-3.5" />
                                            Erledigt
                                        </Button>

                                        <!-- Send -->
                                        <Button size="sm" class="h-8 text-xs gap-1 bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white shadow-sm" @click="sendDraft()">
                                            <Send class="w-3.5 h-3.5" />
                                            Senden
                                        </Button>
                                    </div>

                                    <!-- Calendar Embed -->
                                    <div v-if="showCalendar && calendarEmbedUrl" class="mt-3 border rounded-lg overflow-hidden">
                                        <iframe :src="calendarEmbedUrl" class="w-full h-96 border-0" />
                                    </div>
                                </div>
                            </template>
                        </div>
                    </ScrollArea>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>
```

- [ ] **Step 2: Verify the file has both script and template**

```bash
grep -c '<script setup>' /var/www/srhomes/resources/js/Components/Admin/PrioritiesTab.vue
grep -c '<template>' /var/www/srhomes/resources/js/Components/Admin/PrioritiesTab.vue
```

Expected: Both return `1`

- [ ] **Step 3: Build and check for errors**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -20
```

Expected: Build succeeds. If there are import errors, check that shadcn components export the expected names.

- [ ] **Step 4: Commit**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/PrioritiesTab.vue
git commit -m "feat(aktionen): rewrite template — shadcn tabs, mail list, sheet detail"
```

---

### Task 4: Fix Build Issues and Component Imports

**Files:**
- Modify: `resources/js/Components/Admin/PrioritiesTab.vue` (import fixes)

This task handles likely build issues from Task 3. shadcn-vue component exports may vary (e.g. some components export from `index.ts`, others have different names).

- [ ] **Step 1: Check component export names**

```bash
cd /var/www/srhomes
# Check Tabs exports
head -5 resources/js/components/ui/tabs/index.ts 2>/dev/null || head -5 resources/js/components/ui/tabs/index.js 2>/dev/null || ls resources/js/components/ui/tabs/
# Check Collapsible exports
head -5 resources/js/components/ui/collapsible/index.ts 2>/dev/null || ls resources/js/components/ui/collapsible/
# Check Textarea exports
head -5 resources/js/components/ui/textarea/index.ts 2>/dev/null || ls resources/js/components/ui/textarea/
# Check ScrollArea exports
head -5 resources/js/components/ui/scroll-area/index.ts 2>/dev/null || ls resources/js/components/ui/scroll-area/
```

- [ ] **Step 2: Fix any import mismatches**

If a component doesn't export what we expected (e.g., `Textarea` might be default export), update the imports in the `<script setup>` to match. Common fixes:

- `Textarea` might need: `import Textarea from "@/components/ui/textarea/Textarea.vue"`
- `Tabs` might export: `{ Tabs, TabsContent, TabsList, TabsTrigger }`
- `Collapsible` might export: `{ Collapsible, CollapsibleContent, CollapsibleTrigger }`

- [ ] **Step 3: Rebuild and verify**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -10
```

Expected: Build succeeds with no errors

- [ ] **Step 4: Commit if changes were needed**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/PrioritiesTab.vue
git commit -m "fix(aktionen): fix component import paths"
```

---

### Task 5: Visual Verification and Polish

**Files:**
- Modify: `resources/js/Components/Admin/PrioritiesTab.vue` (tweaks)

- [ ] **Step 1: Open in browser and check Offen tab**

Navigate to the admin dashboard and click "Aktionen". Verify:
- Tabs render correctly (Offen selected, badge shows count)
- Auto-reply banner appears if there are logs
- Search input and filter dropdowns render
- List items show: avatar, name, badge, subject, preview, tags
- Items are clickable

- [ ] **Step 2: Check Sheet opens correctly**

Click on a list item. Verify:
- Sheet slides in from the right
- Header shows avatar, name, email, tags
- "Eingehende Nachricht" is expanded showing the original email
- "Verlauf" is collapsed with message count
- KI-Entwurf shows loading spinner, then generated draft
- Toolbar shows: Anhänge, detail level select, calendar, erledigt, senden

- [ ] **Step 3: Check Nachfassen tab**

Click "Nachfassen" tab. Verify:
- List shows follow-up items with days_waiting badges
- Click opens Sheet with draft

- [ ] **Step 4: Fix any visual issues**

Common fixes:
- If Sheet is too narrow, adjust `sm:max-w-[600px]` to `sm:max-w-[650px]`
- If tabs look wrong, check TabsList/TabsTrigger class overrides
- If line-clamp doesn't work, ensure Tailwind's `@tailwindcss/line-clamp` plugin or native `line-clamp-1` class is available

- [ ] **Step 5: Commit any polish changes**

```bash
cd /var/www/srhomes
git add resources/js/Components/Admin/PrioritiesTab.vue
git commit -m "fix(aktionen): visual polish and layout tweaks"
```

---

### Task 6: Functional Testing

**Files:** None (testing only)

- [ ] **Step 1: Test search filter**

Type a name in the search box. Verify the list filters in real-time.

- [ ] **Step 2: Test object/category filters**

Select a specific property from the "Objekt" dropdown. Verify only items for that property show. Reset to "Alle Objekte".

- [ ] **Step 3: Test sending an email**

1. Click an item in "Offen" tab
2. Wait for KI-Draft to load
3. Optionally edit the draft
4. Click "Senden"
5. Verify: Sheet closes, item removed from list, toast shows "Email an X gesendet!"

- [ ] **Step 4: Test "Erledigt" button**

1. Click an item
2. Click "Erledigt"
3. Verify: Sheet closes, item removed, toast shows confirmation

- [ ] **Step 5: Test auto-reply banner**

1. If auto-reply logs exist, verify the green banner shows
2. Click to expand — verify logs are listed
3. Click again to collapse

- [ ] **Step 6: Test responsive / mobile**

Resize browser to mobile width. Verify:
- Sheet fills full screen
- Toolbar buttons wrap properly
- Tabs are still usable

- [ ] **Step 7: Final build check**

```bash
cd /var/www/srhomes && npm run build 2>&1 | tail -5
```

Expected: Clean build, no warnings

- [ ] **Step 8: Final commit**

```bash
cd /var/www/srhomes
git add -A
git commit -m "feat(aktionen): complete redesign — 2 tabs, shadcn mail pattern, sheet detail"
```

---

## Verification Checklist (from spec)

1. ✅ `npm run build` erfolgreich
2. ✅ Offen-Tab zeigt unbeantwortete Anfragen mit korrekter Anzahl
3. ✅ Nachfassen-Tab zeigt Follow-up Items
4. ✅ Suche filtert nach Name/Betreff
5. ✅ Objekt/Kategorie Filter funktionieren
6. ✅ Klick auf Item öffnet Sheet mit korrekten Details
7. ✅ KI-Draft wird generiert und ist editierbar
8. ✅ Senden funktioniert
9. ✅ Erledigt markiert und entfernt Item
10. ✅ Auto-Reply Banner zeigt Log
11. ✅ Keine Console-Errors
12. ✅ Responsive auf Mobile
