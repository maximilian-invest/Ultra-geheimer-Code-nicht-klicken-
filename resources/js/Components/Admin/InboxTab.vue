<script setup>
import { ref, inject, onMounted, computed, watch, nextTick, provide } from "vue";
import { catBadgeStyle, catLabel, catIsInbound } from "@/utils/categoryBadge.js";
import {
  Mail, Clock, Send, CheckCircle, X, ChevronDown, ChevronUp, CalendarDays,
  Paperclip, Loader2, Search, Sparkles, ArrowUp, ArrowDown,
  PenSquare, History, FileEdit, Trash2, Inbox, LayoutTemplate, Plus, Pencil,
  ChevronLeft, ChevronRight, Reply, Save, MailQuestion, Settings2, ImageIcon
} from "lucide-vue-next";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Textarea } from "@/components/ui/textarea";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import InboxConversationList from "./inbox/InboxConversationList.vue";
import InboxChatView from "./inbox/InboxChatView.vue";
import InboxAiDraft from "./inbox/InboxAiDraft.vue";
import InboxComposeView from "./inbox/InboxComposeView.vue";
import InboxBottomBar from "./inbox/InboxBottomBar.vue";

// ============================================================
// INJECTIONS (merged from PrioritiesTab + CommsTab)
// ============================================================
const API = inject("API");
const toast = inject("toast");
const switchTab = inject("switchTab");
const unansweredCount = inject("unansweredCount");
const followupCount = inject("followupCount");
const unmatchedCount = inject("unmatchedCount");
const refreshCounts = inject("refreshCounts", () => {});
const properties = inject("properties");
const calendarEmbedUrl = inject("calendarEmbedUrl", "");
const userType = inject("userType", ref("makler"));
const isAssistenz = computed(() => ['assistenz', 'backoffice'].includes(userType.value));

// ============================================================
// SUBTAB STATE (from Task 1 shell)
// ============================================================
const activeSubtab = ref(localStorage.getItem("sr-admin-inboxview") || "offen");
watch(activeSubtab, (v) => localStorage.setItem("sr-admin-inboxview", v));

// Background image
const bgGradient = ref(localStorage.getItem('sr-inbox-bg') || '');
const bgOpacity = ref(parseFloat(localStorage.getItem('sr-inbox-bg-opacity') || '0.15'));
const showBgPicker = ref(false);

const defaultBgs = [
  { label: 'Keine', value: '' },
  { label: 'Orange', value: 'linear-gradient(135deg, #fed7aa 0%, #fdba74 50%, #fb923c 100%)' },
  { label: 'Blau', value: 'linear-gradient(135deg, #dbeafe 0%, #93c5fd 50%, #60a5fa 100%)' },
  { label: 'Grün', value: 'linear-gradient(135deg, #d1fae5 0%, #6ee7b7 50%, #34d399 100%)' },
  { label: 'Lila', value: 'linear-gradient(135deg, #ede9fe 0%, #c4b5fd 50%, #a78bfa 100%)' },
  { label: 'Sunset', value: 'linear-gradient(135deg, #fecaca 0%, #fdba74 50%, #f97316 100%)' },
];

function setBg(value) {
  bgGradient.value = value;
  localStorage.setItem('sr-inbox-bg', value);
  showBgPicker.value = false;
}

// ============================================================
// PROVIDE TO CHILDREN (from Task 1 shell)
// ============================================================
provide("inboxAPI", API);
provide("inboxToast", toast);
provide("inboxProperties", properties);
provide("inboxCalendarUrl", calendarEmbedUrl);
provide("inboxBgGradient", bgGradient);
provide("inboxBgOpacity", bgOpacity);

// ============================================================
// SELECTED CONVERSATION (from Task 1 shell)
// ============================================================
const selectedItem = ref(null);
const selectedMode = ref("offen");
const composing = ref(false);

// ============================================================
// PRIORITIES STATE (from PrioritiesTab.vue)
// ============================================================

// Unanswered
const unansweredList = ref([]);
const unansweredLoading = ref(false);
const unansweredFilter = ref("all");

// Followup
const followupData = ref(null);
const followupLoading = ref(false);
const followupFilter = ref("all");
const stage1Followups = ref([]);
const stage1Count = ref(0);
const stage1Loading = ref(false);

// Filters (priorities)
const searchQuery = ref('');
const objectFilter = ref('all');
const categoryFilter = ref('all');

// Detail panel (renamed sheetOpen → detailOpen)
const detailOpen = ref(false);
const sheetMode = ref('offen'); // 'offen' | 'nachfassen'

// Detail state
const expandedDetail = ref(null);

// Combine thread + current email into one messages array for chat view (deduped by ID)
const allDetailMessages = computed(() => {
  if (!expandedDetail.value) return [];
  const thread = expandedDetail.value.thread || expandedDetail.value.messages || [];
  const current = expandedDetail.value.email;

  // Build map by ID to deduplicate
  const seen = new Map();
  for (const msg of thread) {
    const key = msg.id || msg.activity_id || JSON.stringify({ date: msg.date || msg.email_date, body: (msg.body_text || msg.body || '').substring(0, 50) });
    if (!seen.has(key)) seen.set(key, msg);
  }

  // Add current email only if not already in thread
  if (current) {
    const key = current.id || current.activity_id || 'current';
    if (!seen.has(key)) seen.set(key, current);
  }

  // Sort by date ascending (oldest first — chat view)
  return Array.from(seen.values()).sort((a, b) => {
    const da = new Date(a.date || a.activity_date || a.email_date || a.created_at || 0);
    const db = new Date(b.date || b.activity_date || b.email_date || b.created_at || 0);
    return da - db;
  });
});
const expandedLoading = ref(false);
const expandedAiDraft = ref(null);
const expandedAiLoading = ref(false);
const expandedFiles = ref([]);
const expandedFilesLoading = ref(false);
const expandedSelectedFiles = ref([]);
const expandedBodyFull = ref(true);
const showThreadAccordion = ref(false);
const showEmailFields = ref(false);
const showCalendar = ref(false);
const showAttachPopup = ref(false);

// Send state (priorities)
const aiSending = ref(false);
const followupSending = ref(false);
const aiDetailLevel = ref(localStorage.getItem("sr-ai-detail-level") || "standard");

// Send accounts (PrioritiesTab naming wins)
const sendAccounts = ref([]);
const sendAccountId = ref(null);

// Recipient email
const recipientEmailSaving = ref(false);
const recipientEmailSaved = ref(false);

// Auto-reply
const autoReplyLogs = ref([]);
const autoReplyLoading = ref(false);
const autoReplyBannerOpen = ref(false);
const showAutoReplySettings = ref(false);
const autoReplyEnabled = ref(false);
const autoReplyText = ref('');
const autoReplySaving = ref(false);
const autoReplyPropertyIds = ref([]);

// Broker filter
const maklerFilter = ref('all');
const brokerList = ref([]);

// ============================================================
// COMMS STATE (from CommsTab.vue)
// ============================================================

// Signature from settings
const sigData = ref(null);

// Compose state
const composeTo = ref("");
const composeCc = ref("");
const composeBcc = ref("");
const showCcBcc = ref(false);
const composeSubject = ref("");
const composeBody = ref("");
const composeTone = ref("professional");
const composePropertyId = ref(null);
const composeStakeholder = ref("");
const composeAttachments = ref([]);
const emailSourceId = ref("");
const currentDraftId = ref(null);
const aiOriginalBody = ref("");

// Email accounts for compose (CommsTab)
const emailAccountsSelect = ref([]);
const selectedAccountId = ref(null); // used in compose context (separate from sendAccountId for priorities)
const aiLoading = ref(false);
const emailSending = ref(false);

// Contact search autocomplete
const contactSearchResults = ref([]);
const contactSearchLoading = ref(false);
const showContactSearch = ref(false);
let contactSearchTimeout = null;
const replyContext = ref(null);
const replyContextLoading = ref(false);

// Email history state
const ehData = ref([]);
const ehLoading = ref(false);
const ehTotal = ref(0);
const ehPage = ref(1);
const ehPerPage = ref(100);
const ehTotalPages = ref(0);
const ehSearch = ref("");
const ehPropertyId = ref("0");
const ehCategory = ref("");
const ehDirection = ref("");
const ehAccountId = ref("");
const emailAccounts = ref([]);
const ehShowUnmatched = ref(false);
const ehExpanded = ref(null);
const ehSelected = ref([]);
const ehSelectAll = ref(false);
const ehThreadLoading = ref(null);
const ehThreadMessages = ref({});
const ehThreadExpanded = ref(null);
let ehRequestId = 0;

// Attachment handling (email history)
const attachAssignOpen = ref(null);
const attachAssigning = ref(null);

// Inbox (nicht zugeordnet)
const inboxEmails = ref([]);
const inboxProps = ref([]);
const inboxLoading = ref(false);

// Drafts
const draftsData = ref([]);
const draftsLoading = ref(false);
const draftsCount = ref(0);

// Trash
const trashData = ref([]);
const trashLoading = ref(false);
const trashCount = ref(0);
const trashSelected = ref([]);

// Email Templates
const templates = ref([]);
const templatesLoading = ref(false);
const templateEdit = ref(null);
const templateSaving = ref(false);

// Property files for compose attachments
const propertyFiles = ref([]);
const propertyFilesLoading = ref(false);

// Trash confirm
const trashConfirmId = ref(null);

// ============================================================
// COMPUTED (from PrioritiesTab.vue)
// ============================================================
const filteredUnanswered = computed(() => {
  let list = unansweredList.value;
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i =>
      (i.from_name || '').toLowerCase().includes(q) ||
      (i.subject || '').toLowerCase().includes(q) ||
      (i.from_email || '').toLowerCase().includes(q)
    );
  }
  if (objectFilter.value !== 'all') {
    list = list.filter(i => String(i.property_id) === objectFilter.value);
  }
  if (categoryFilter.value !== 'all') {
    list = list.filter(i => i.category === categoryFilter.value);
  }
  return list;
});

const allFollowups = computed(() => {
  const s1 = stage1Followups.value.map(f => ({ ...f, _stage: 1 }));
  const s2 = (followupData.value?.followups || []).map(f => ({ ...f, _stage: 2 }));
  return [...s1, ...s2];
});

const filteredFollowups = computed(() => {
  let list = allFollowups.value;
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i =>
      (i.from_name || i.stakeholder || '').toLowerCase().includes(q) ||
      (i.subject || i.activity || '').toLowerCase().includes(q) ||
      (i.from_email || '').toLowerCase().includes(q)
    );
  }
  if (objectFilter.value !== 'all') {
    list = list.filter(i => String(i.property_id) === objectFilter.value);
  }
  if (categoryFilter.value !== 'all') {
    list = list.filter(i => i.category === categoryFilter.value);
  }
  return list;
});

const availableProperties = computed(() => {
  const map = {};
  const all = [...unansweredList.value, ...allFollowups.value];
  all.forEach(i => {
    if (i.property_id && i.ref_id) map[i.property_id] = i.ref_id;
  });
  return Object.entries(map).map(([id, ref_id]) => ({ id, ref_id }));
});

const availableCategories = computed(() => {
  const cats = new Set();
  const all = [...unansweredList.value, ...allFollowups.value];
  all.forEach(i => { if (i.category) cats.add(i.category); });
  return [...cats].sort();
});

const nachfassenSections = computed(() => {
  const list = filteredFollowups.value;
  const kaufanbot = [];
  const s3 = [];
  const s2 = [];
  const s1 = [];
  const other = [];
  for (const item of list) {
    const cat = (item.category || "").toLowerCase();
    if (cat === "kaufanbot" || cat === "anbot") { kaufanbot.push(item); continue; }
    const stage = item._stage || item.stage;
    if (stage === 3) s3.push(item);
    else if (stage === 2) s2.push(item);
    else if (stage === 1) s1.push(item);
    else other.push(item);
  }
  return [
    { label: "Kaufanbot", items: kaufanbot },
    { label: "NF3 - Dringend", items: s3 },
    { label: "NF2 - Nachfassen", items: s2 },
    { label: "NF1 - Erstmalig", items: s1 },
    { label: "Sonstige", items: other },
  ];
});

// ============================================================
// HELPERS (from PrioritiesTab.vue)
// ============================================================
function getInitials(name) {
  if (!name) return '??';
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  return name.substring(0, 2).toUpperCase();
}

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const now = new Date();
  const d = new Date(dateStr.replace(' ', 'T'));
  const diffMs = now - d;
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'gerade';
  if (mins < 60) return 'vor ' + mins + ' Min.';
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return 'vor ' + hrs + ' Std.';
  const days = Math.floor(hrs / 24);
  if (days < 30) return 'vor ' + days + ' Tag' + (days > 1 ? 'en' : '');
  const months = Math.floor(days / 30);
  return 'vor ' + months + ' Mon.';
}

function formatDetailDate(s) {
  if (!s) return '';
  if (s.includes(' ') || s.includes('T')) {
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('de-AT', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
      ', ' + d.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' });
  }
  return s.split('-').reverse().join('.');
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

// ============================================================
// HELPERS (from CommsTab.vue)
// ============================================================
const formatDate = (s) => {
  if (!s) return "";
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    const [y, m, d] = s.split("-");
    return d + "." + m + "." + y;
  }
  const dt = new Date(s);
  const h = dt.getHours(), mi = dt.getMinutes();
  const dateStr = dt.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" });
  if (h === 0 && mi === 0) return dateStr;
  return dateStr + ", " + String(h).padStart(2,"0") + ":" + String(mi).padStart(2,"0");
};

const formatDateShort = (s) => {
  if (!s) return "";
  const d = new Date(s);
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  const h = d.getHours(), mi = d.getMinutes();
  const timeStr = (h === 0 && mi === 0) ? "" : " " + String(h).padStart(2,"0") + ":" + String(mi).padStart(2,"0");
  if (isToday) return "Heute" + timeStr;
  return d.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit" }) + timeStr;
};

function parseAttachmentNames(names) {
  if (!names) return [];
  try {
    const parsed = JSON.parse(names);
    if (Array.isArray(parsed)) return parsed;
  } catch {}
  return names.split(',').map(n => n.trim()).filter(Boolean);
}

function extractRealEmail(fromEmail, bodyText) {
  if (fromEmail && !/noreply|no-reply|mailer|notification|typeform|followups|info@willhaben|info@immowelt/i.test(fromEmail)) {
    return fromEmail;
  }
  if (bodyText) {
    const tlds = "at|de|com|net|org|info|io|eu|ch|uk|biz|me|cc|tv|top|to|li|hr|si|ro|bg|rs|cz|hu|sk|pl|it|fr|es|nl|be|se|no|fi|dk|pt|ru|us|ca|au|nz|jp|cn|in|br|mx|za|online|app|dev|gmbh|wien|mobi|xyz|live|email";
    const emailRe = new RegExp("[\\w.+\\-]+@[\\w.\\-]+\\.(?:" + tlds + ")(?=[^a-z]|$)", "i");
    const flat = bodyText.replace(/\r?\n/g, ' ');
    let m = flat.match(new RegExp("E-?Mail[=:\\s]+(" + emailRe.source + ")", "i"));
    if (m) return m[1].toLowerCase();
    m = flat.match(new RegExp("(?:email|e-mail)(" + emailRe.source + ")", "i"));
    if (m) return m[1].toLowerCase();
    const allEmails = flat.match(new RegExp(emailRe.source, "gi")) || [];
    for (const e of allEmails) {
      if (!/willhaben|immowelt|noreply|typeform|followups|scout24|sr-homes/i.test(e) && e.toLowerCase() !== (fromEmail||'').toLowerCase()) {
        return e.toLowerCase();
      }
    }
  }
  return fromEmail || '';
}

// ============================================================
// SIGNATURE FUNCTIONS (from CommsTab.vue)
// ============================================================
async function loadSignature() {
  try {
    const r = await fetch(API.value + "&action=get_settings");
    const d = await r.json();
    if (d.signature_name) sigData.value = d;
  } catch {}
}

function buildSignature() {
  const s = sigData.value;
  if (!s) return "\n\n--\nSR-Homes Immobilien GmbH\nwww.sr-homes.at";
  return "\n\n--\n" + (s.signature_name || '') + "\n" + (s.signature_title || '') + "\n" + (s.signature_company || '') + "\nTel: " + (s.signature_phone || '') + "\n" + (s.signature_website || '');
}

function buildSignatureHtml() {
  const s = sigData.value;
  if (!s) return '<br><br><span style="color:#999">--</span><br>SR-Homes Immobilien GmbH<br>www.sr-homes.at';
  const hasPhoto = !!s.signature_photo_url;
  const cs = hasPhoto ? 2 : 1;
  let html = '<br><br><table cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333">';
  if (s.signature_logo_url) {
    html += '<tr><td colspan="' + cs + '" style="padding-bottom:8px"><img src="' + s.signature_logo_url + '" alt="Logo" style="max-height:60px;max-width:200px"></td></tr>';
  }
  html += '<tr>';
  if (hasPhoto) {
    html += '<td style="border-top:2px solid #ee7606;padding-top:8px;padding-right:12px;vertical-align:top"><img src="' + s.signature_photo_url + '" alt="" style="width:70px;height:90px;object-fit:cover;border-radius:4px"></td>';
  }
  html += '<td style="border-top:2px solid #ee7606;padding-top:8px">';
  html += '<strong style="font-size:14px;color:#222">' + (s.signature_name || '') + '</strong>';
  if (s.signature_title) html += '<br><span style="color:#666">' + s.signature_title + '</span>';
  html += '<br><span style="color:#666">' + (s.signature_company || '') + '</span>';
  html += '<br>Tel: <a href="tel:' + (s.signature_phone || '').replace(/\s/g,'') + '" style="color:#ee7606;text-decoration:none">' + (s.signature_phone || '') + '</a>';
  html += '<br><a href="https://' + (s.signature_website || '') + '" style="color:#ee7606;text-decoration:none">' + (s.signature_website || '') + '</a>';
  html += '</td></tr>';
  if (s.signature_banner_url) {
    html += '<tr><td colspan="' + cs + '" style="padding-top:8px"><img src="' + s.signature_banner_url + '" alt="" style="max-width:400px;width:100%;border-radius:4px"></td></tr>';
  }
  html += '</table>';
  return html;
}

// ============================================================
// CONTACT SEARCH (from CommsTab.vue)
// ============================================================
function searchContacts(query) {
  if (contactSearchTimeout) clearTimeout(contactSearchTimeout);
  if (!query || query.length < 2) { contactSearchResults.value = []; showContactSearch.value = false; return; }
  contactSearchTimeout = setTimeout(async () => {
    contactSearchLoading.value = true;
    showContactSearch.value = true;
    try {
      const r = await fetch(API.value + "&action=contact_search&q=" + encodeURIComponent(query));
      const d = await r.json();
      contactSearchResults.value = d.contacts || [];
    } catch { contactSearchResults.value = []; }
    contactSearchLoading.value = false;
  }, 250);
}

function selectContact(contact) {
  composeTo.value = contact.email || "";
  showContactSearch.value = false;
  contactSearchResults.value = [];
}

function onComposeToInput(e) {
  searchContacts(e.target.value);
}

function onComposeToBlur() {
  setTimeout(() => { showContactSearch.value = false; }, 200);
}

// ============================================================
// API FUNCTIONS — PRIORITIES (from PrioritiesTab.vue)
// ============================================================
async function loadUnanswered(filter) {
  unansweredFilter.value = filter;
  unansweredLoading.value = true;
  try {
    const brokerParam = (maklerFilter.value && maklerFilter.value !== 'all') ? "&broker_filter=" + maklerFilter.value : "";
    const r = await fetch(API.value + "&action=followups&mode=unanswered&filter=" + filter + brokerParam);
    const d = await r.json();
    unansweredList.value = d.followups || [];
    unansweredCount.value = (d.total_open || 0) + (d.total_unmatched || 0);
  } catch (e) { toast("Fehler: " + e.message); }
  unansweredLoading.value = false;
  prefetchDrafts(unansweredList.value);
}

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
  const items = [...(followupData.value?.followups || [])];
  prefetchFollowupDrafts(items);
}

async function loadStage1() {
  stage1Loading.value = true;
  try {
    const brokerParam = (maklerFilter.value && maklerFilter.value !== 'all') ? "&broker_filter=" + maklerFilter.value : "";
    const r = await fetch(API.value + "&action=followups_stage1" + brokerParam);
    const d = await r.json();
    stage1Followups.value = d.followups || [];
    stage1Count.value = d.total_stage1 || stage1Followups.value.length;
  } catch (e) { toast("Stage-1 Fehler: " + e.message); }
  stage1Loading.value = false;
}

async function loadAutoReplyLogs() {
  autoReplyLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=auto_reply_recent");
    const res = await r.json();
    autoReplyLogs.value = res.logs || [];
  } catch (e) { console.error(e); }
  autoReplyLoading.value = false;
}

async function loadAutoReplySettings() {
  try {
    const r = await fetch(API.value + "&action=get_settings");
    const d = await r.json();
    autoReplyEnabled.value = !!d.auto_reply_enabled;
    autoReplyText.value = d.auto_reply_text || '';
    autoReplyPropertyIds.value = d.auto_reply_property_ids ? d.auto_reply_property_ids.split(',').map(Number).filter(Boolean) : [];
  } catch (e) { console.error(e); }
}

async function toggleAutoReply() {
  try {
    const r = await fetch(API.value + "&action=toggle_auto_reply", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        enabled: !autoReplyEnabled.value,
        auto_reply_text: autoReplyText.value || null,
      }),
    });
    const d = await r.json();
    if (d.success) {
      autoReplyEnabled.value = d.auto_reply_enabled;
      toast(autoReplyEnabled.value ? "Auto-Reply aktiviert!" : "Auto-Reply deaktiviert!");
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
}

async function saveAutoReplySettings() {
  autoReplySaving.value = true;
  try {
    const r = await fetch(API.value + "&action=toggle_auto_reply", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        enabled: autoReplyPropertyIds.value.length > 0 ? 1 : 0,
        auto_reply_text: autoReplyText.value || null,
        auto_reply_property_ids: autoReplyPropertyIds.value.join(','),
      }),
    });
    const d = await r.json();
    if (d.success) toast("Auto-Reply Einstellungen gespeichert!");
    else toast("Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
  autoReplySaving.value = false;
}

async function nachfassenAlle() {
  const items = filteredFollowups.value.filter(f => f._prefetchedDraft);
  if (!items.length) return;
  if (!confirm("Alle " + items.length + " Nachfass-Entw\u00fcrfe jetzt senden?")) return;

  let sentCount = 0;
  let errorCount = 0;
  for (const item of items) {
    try {
      const draft = item._prefetchedDraft;
      let sigText = "\n\n--\nSR-Homes Immobilien GmbH\nwww.sr-homes.at";
      let sigHtml = '<br><br><span style="color:#999">--</span><br>SR-Homes Immobilien GmbH<br>www.sr-homes.at';
      try {
        const sr = await fetch(API.value + "&action=get_settings");
        const sd = await sr.json();
        if (sd.signature_name) {
          sigText = "\n\n--\n" + (sd.signature_name || "") + "\n" + (sd.signature_company || "") + "\nTel: " + (sd.signature_phone || "") + "\n" + (sd.signature_website || "");
          sigHtml = '<br><br><span style="color:#999">--</span><br><strong>' + (sd.signature_name || "") + '</strong><br>' + (sd.signature_company || "") + '<br>Tel: ' + (sd.signature_phone || "") + '<br>' + (sd.signature_website || "");
        }
      } catch {}

      const htmlBody = draft.body.replace(/\n/g, "<br>") + sigHtml;
      const fd = new FormData();
      fd.append("account_id", sendAccountId.value ? String(sendAccountId.value) : "1");
      fd.append("to_email", draft.to || item.from_email || "");
      fd.append("to_name", item.from_name || item.stakeholder || "");
      fd.append("subject", draft.subject || "");
      fd.append("body_html", htmlBody);
      fd.append("body_text", draft.body + sigText);
      fd.append("property_id", item.property_id || "");
      fd.append("in_reply_to", String(item.id) || "");
      fd.append("is_followup", "1");

      const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
      const result = await r.json();
      if (result.success) sentCount++;
      else errorCount++;
    } catch { errorCount++; }
  }

  if (sentCount > 0) toast(sentCount + " Nachfass-Emails gesendet!");
  if (errorCount > 0) toast(errorCount + " Fehler beim Senden");
  loadFollowups(followupFilter.value);
  loadStage1();
  loadUnanswered(unansweredFilter.value);
  refreshCounts();
}

async function loadBrokerList() {
  if (!isAssistenz.value || brokerList.value.length) return;
  try {
    const r = await fetch(API.value + '&action=list_brokers');
    const d = await r.json();
    brokerList.value = (d.brokers || []).filter(b => ['admin', 'makler'].includes(b.user_type));
  } catch {}
}

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
          leadPhase: d.draft.lead_phase || null,
          mailType: d.draft.mail_type || null,
          leadStatus: d.draft.lead_status || null,
          mailGoal: d.draft.mail_goal || null,
        };
      }
    })
    .catch(() => {})
  );
  await Promise.all(promises);
}

// ============================================================
// DETAIL PANEL FUNCTIONS (from PrioritiesTab.vue, sheetOpen → detailOpen)
// ============================================================
function openDetail(item, mode) {
  selectedItem.value = item;
  sheetMode.value = mode;
  selectedMode.value = mode;
  composing.value = false;
  detailOpen.value = true;

  // Mark as read if unread
  if (!item.is_read && item.id) {
    fetch(API.value + "&action=mark_read", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: item.id }),
    }).catch(() => {});
    item.is_read = 1;
  }
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

  // Use pre-generated draft if available
  if (mode === 'offen' && item.draft && item.draft.body) {
    expandedAiDraft.value = {
      body: item.draft.body,
      subject: item.draft.subject || ("Re: " + (item.subject || "")),
      to: item.draft.to || item.from_email || item.contact_email || "",
      prospect_email: item.draft.to || "",
    };
    expandedAiLoading.value = false;
  } else if (mode === 'nachfassen' && item._prefetchedDraft) {
    expandedAiDraft.value = item._prefetchedDraft;
    expandedAiLoading.value = false;
  } else if (mode === "posteingang" || mode === "gesendet") {
    expandedAiLoading.value = false;
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

  // Load email context — for offen/nachfassen use activity type, for posteingang/gesendet use portal_emails directly
  const isEmailHistory = mode === "posteingang" || mode === "gesendet";
  const contextUrl = isEmailHistory
    ? API.value + "&action=email_context&email_id=" + item.id
    : API.value + "&action=email_context&email_id=" + item.id + "&type=activity";
  const contextPromise = fetch(contextUrl)
    .then(r => r.json())
    .then(d => {
      if (isEmailHistory && d.email && !d.thread?.length) {
        // For email history: if no thread found, show the email itself as the only message
        const emailMsg = d.email;
        expandedDetail.value = { email: emailMsg, thread: d.thread || [] };
      } else {
        expandedDetail.value = { email: d.email || null, thread: d.thread || [] };
      }
    })
    .catch(e => { toast("Fehler: " + e.message); })
    .finally(() => { expandedLoading.value = false; });

  // Generate draft if not pre-fetched
  const promises = [contextPromise];
  if (mode === 'offen' && (!item.draft || !item.draft.body)) {
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
  } else if (mode === 'nachfassen' && !item._prefetchedDraft) {
    const draftPromise = fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(item.from_name || item.stakeholder) + "&property_id=" + item.property_id)
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

  Promise.all(promises);
}

function setAiDetailLevel(level) {
  aiDetailLevel.value = level;
  localStorage.setItem("sr-ai-detail-level", level);
}

async function regenerateAiDraft() {
  expandedAiDraft.value = null;
  expandedAiLoading.value = true;
  const item = selectedItem.value;
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
      const r = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(item.from_name || item.stakeholder) + "&property_id=" + item.property_id);
      const d = await r.json();
      if (d.draft) {
        expandedAiDraft.value = {
          body: d.draft.email_body || "",
          subject: d.draft.email_subject || ("Re: " + (item.subject || item.activity || "")),
          to: d.email || item.from_email || item.contact_email || "",
          phone: d.phone || item.contact_phone || "",
        };
      }
    }
  } catch (e) { toast("KI-Fehler: " + e.message); }
  expandedAiLoading.value = false;
}

async function improveWithAi() {
  if (!expandedAiDraft.value?.body?.trim()) {
    toast("Bitte zuerst einen Text eingeben.");
    return;
  }
  expandedAiLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=improve_text", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ text: expandedAiDraft.value.body }),
    });
    const d = await r.json();
    if (d.improved_text) {
      expandedAiDraft.value = { ...expandedAiDraft.value, body: d.improved_text };
      toast("Wording verbessert!");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) { toast("KI-Fehler: " + e.message); }
  expandedAiLoading.value = false;
}

function toggleFileSelection(fileId) {
  const idx = expandedSelectedFiles.value.indexOf(fileId);
  if (idx >= 0) expandedSelectedFiles.value.splice(idx, 1);
  else expandedSelectedFiles.value.push(fileId);
}

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
      toast("E-Mail-Adresse gespeichert: " + newEmail);
      setTimeout(() => { recipientEmailSaved.value = false; }, 2500);
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
  recipientEmailSaving.value = false;
}

async function markHandled(stakeholder, propertyId) {
  try {
    const r = await fetch(API.value + "&action=mark_handled", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ stakeholder, property_id: propertyId, note: "Bereits beantwortet (extern/Kalender/Telefon)" }),
    });
    const d = await r.json();
    if (d.success) {
      toast("Als erledigt markiert!");
      detailOpen.value = false;
      selectedItem.value = null;
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
      loadStage1();
      refreshCounts();
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
}

// ============================================================
// SEND DRAFT (from PrioritiesTab.vue, sheetOpen → detailOpen)
// ============================================================
async function sendDraft() {
  const item = selectedItem.value;
  const draft = expandedAiDraft.value;
  if (!draft || !item) return;

  const itemName = item.from_name || item.from_email || item.stakeholder || "Kunde";
  const itemId = item.id;
  const isFollowup = sheetMode.value === 'nachfassen';

  detailOpen.value = false;
  selectedItem.value = null;
  if (isFollowup) {
    stage1Followups.value = stage1Followups.value.filter(i => i.id !== itemId);
    if (followupData.value && followupData.value.followups) {
      followupData.value.followups = followupData.value.followups.filter(i => i.id !== itemId);
    }
  } else {
    unansweredList.value = unansweredList.value.filter(i => i.id !== itemId);
  }

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
    let sigText = "\n\n--\nSR-Homes Immobilien GmbH\nwww.sr-homes.at";
    let sigHtml = '<br><br><span style="color:#999">--</span><br>SR-Homes Immobilien GmbH<br>www.sr-homes.at';
    try {
      const sr = await fetch(API.value + "&action=get_settings");
      const sd = await sr.json();
      if (sd.signature_name) {
        sigText = "\n\n--\n" + (sd.signature_name || "") + "\n" + (sd.signature_title || "") + "\n" + (sd.signature_company || "") + "\nTel: " + (sd.signature_phone || "") + "\n" + (sd.signature_website || "");
        let sh = '<br><br><table cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333">';
        const hasPhoto = !!sd.signature_photo_url;
        const cs = hasPhoto ? 2 : 1;
        if (sd.signature_logo_url) sh += '<tr><td colspan="' + cs + '" style="padding-bottom:8px"><img src="' + sd.signature_logo_url + '" alt="Logo" style="max-height:60px;max-width:200px"></td></tr>';
        sh += '<tr>';
        if (hasPhoto) sh += '<td style="border-top:2px solid #D4622B;padding-top:8px;padding-right:12px;vertical-align:top"><img src="' + sd.signature_photo_url + '" alt="" style="width:70px;height:90px;object-fit:cover;border-radius:4px"></td>';
        sh += '<td style="border-top:2px solid #D4622B;padding-top:8px">';
        sh += '<strong style="font-size:14px;color:#222">' + (sd.signature_name || "") + '</strong>';
        if (sd.signature_title) sh += '<br><span style="color:#666">' + sd.signature_title + '</span>';
        sh += '<br><span style="color:#666">' + (sd.signature_company || "") + '</span>';
        sh += '<br>Tel: <a href="tel:' + (sd.signature_phone || "").replace(/\s/g, "") + '" style="color:#D4622B;text-decoration:none">' + (sd.signature_phone || "") + '</a>';
        sh += '<br><a href="https://' + (sd.signature_website || "") + '" style="color:#D4622B;text-decoration:none">' + (sd.signature_website || "") + '</a>';
        sh += '</td></tr>';
        if (sd.signature_banner_url) sh += '<tr><td colspan="' + cs + '" style="padding-top:8px"><img src="' + sd.signature_banner_url + '" alt="" style="max-width:400px;width:100%;border-radius:4px"></td></tr>';
        sh += '</table>';
        sigHtml = sh;
      }
    } catch {}

    let htmlBody = draft.body.replace(/\n/g, "<br>") + sigHtml;

    const attachments = [];
    if (expandedSelectedFiles.value.length && expandedFiles.value.length) {
      for (const fileId of expandedSelectedFiles.value) {
        const ef = expandedFiles.value.find(f => f.id === fileId);
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
    if (isFollowup) fd.append("is_followup", "1");
    for (const file of attachments) fd.append("attachments[]", file);

    const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
    const result = await r.json();

    if (sendingEl) sendingEl.remove();

    if (result.success) {
      toast("Email an " + itemName + " gesendet!" + (attachments.length ? " (" + attachments.length + " Anhang" + (attachments.length > 1 ? "e" : "") + ")" : ""));
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
      loadStage1();
      refreshCounts();
    } else {
      toast("Fehler beim Senden an " + itemName + ": " + (result.error || "Unbekannt"));
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
    }
  } catch (e) {
    if (sendingEl) sendingEl.remove();
    toast("Sende-Fehler: " + e.message);
    loadUnanswered(unansweredFilter.value);
    loadFollowups(followupFilter.value);
  }
}

// ============================================================
// API FUNCTIONS — COMMS (from CommsTab.vue)
// ============================================================
async function loadEmailAccountsSelect() {
  try {
    const r = await fetch(API.value + "&action=get_email_accounts_select");
    const d = await r.json();
    emailAccountsSelect.value = d.accounts || [];
    if (!selectedAccountId.value && emailAccountsSelect.value.length) selectedAccountId.value = emailAccountsSelect.value[0].id;
  } catch (e) { /* silent */ }
}

async function loadEmailAccounts() {
  try {
    const r = await fetch(API.value + "&action=list_brokers");
    const d = await r.json();
    const accs = [];
    for (const b of (d.brokers || [])) {
      if (b.email_accounts) {
        for (const ea of b.email_accounts.split(",")) {
          accs.push({ broker_id: b.id, label: b.name, email: ea.trim() });
        }
      }
    }
    emailAccounts.value = accs;
  } catch(e) {}
}

function switchView(v) {
  activeSubtab.value = v;
  if (v === "posteingang") { ehDirection.value = "inbound"; ehShowUnmatched.value = false; ehPage.value = 1; loadEmailHistory(); }
  if (v === "gesendet") { ehDirection.value = "outbound"; ehPage.value = 1; loadEmailHistory(); }
  if (v === "entwuerfe") loadDrafts();
  if (v === "templates") loadTemplates();
}

// === COMPOSE ===
async function generateAiReply() {
  if (!emailSourceId.value) return;
  aiLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=ai_reply", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email_id: emailSourceId.value, tone: composeTone.value }),
    });
    const d = await r.json();
    if (d.reply_text) { composeBody.value = d.reply_text; aiOriginalBody.value = d.reply_text; if (d.prospect_email) composeTo.value = d.prospect_email; else if (!composeTo.value && d.to) composeTo.value = d.to; if (d.subject && !composeSubject.value) composeSubject.value = d.subject; toast("KI-Vorschlag generiert!"); }
    else toast("KI-Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
  aiLoading.value = false;
}

function addAttachments(event) {
  const files = Array.from(event.target.files);
  for (const f of files) {
    if (f.size > 50 * 1024 * 1024) { toast("Datei zu gross (max 50 MB): " + f.name); continue; }
    composeAttachments.value.push(f);
  }
  event.target.value = "";
}

async function sendEmail() {
  if (!composeTo.value || !composeSubject.value || !composeBody.value) { toast("Bitte alle Felder ausfuellen."); return; }
  if (!selectedAccountId.value) { toast("Bitte Absender-Konto waehlen."); return; }
  emailSending.value = true;
  try {
    const sigHtml = buildSignatureHtml();
    const sig = buildSignature();
    let htmlBody = composeBody.value.replace(/\n/g, "<br>") + sigHtml;

    if (emailSourceId.value && replyContext.value && replyContext.value.originalBody) {
      const origDate = replyContext.value.originalDate || "";
      const origFrom = replyContext.value.originalFrom || replyContext.value.stakeholder || "";
      const origBody = (replyContext.value.originalBody || "").replace(/\n/g, "<br>");
      htmlBody += '<br><br><div style="border-left: 2px solid #ccc; padding-left: 10px; margin-left: 5px; color: #666;">'
        + '<p style="margin: 0 0 8px 0; font-size: 12px;">Am ' + origDate + ' schrieb ' + origFrom.replace(/</g, '&lt;').replace(/>/g, '&gt;') + ':</p>'
        + '<div style="font-size: 13px;">' + origBody + '</div>'
        + '</div>';
    }
    const fd = new FormData();
    fd.append("account_id", selectedAccountId.value);
    fd.append("to_email", composeTo.value);
    fd.append("to_name", composeStakeholder.value || "");
    if (composeCc.value) fd.append("cc", composeCc.value);
    if (composeBcc.value) fd.append("bcc", composeBcc.value);
    fd.append("subject", composeSubject.value);
    fd.append("body_html", htmlBody);
    fd.append("body_text", composeBody.value + sig);
    fd.append("property_id", composePropertyId.value || "");
    fd.append("in_reply_to", emailSourceId.value || "");
    for (const file of composeAttachments.value) fd.append("attachments[]", file);
    const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
    const result = await r.json();
    if (result.success) {
      if (aiOriginalBody.value && composeBody.value !== aiOriginalBody.value) {
        try {
          await fetch(API.value + "&action=save_ai_feedback", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              original_text: aiOriginalBody.value,
              edited_text: composeBody.value,
              context_type: "email_reply",
              stakeholder: composeStakeholder.value,
              property_id: composePropertyId.value,
              tone: composeTone.value,
            }),
          });
        } catch {}
      }
      aiOriginalBody.value = "";
      toast("Email erfolgreich gesendet!" + (composeAttachments.value.length ? " (" + composeAttachments.value.length + " Anhaenge)" : ""));
      composeTo.value = ""; composeCc.value = ""; composeBcc.value = ""; showCcBcc.value = false;
      composeSubject.value = ""; composeBody.value = "";
      emailSourceId.value = ""; composePropertyId.value = null; composeStakeholder.value = "";
      composeAttachments.value = [];
      if (currentDraftId.value) {
        fetch(API.value + "&action=delete_draft", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: currentDraftId.value }) });
        currentDraftId.value = null;
      }
    } else toast("Fehler: " + (result.error || "Unbekannt"));
  } catch (e) { toast("Sende-Fehler: " + e.message); }
  emailSending.value = false;
}

async function saveDraft() {
  if (!composeBody.value && !composeTo.value && !composeSubject.value) { toast("Entwurf ist leer."); return; }
  if (!selectedAccountId.value) { toast("Bitte Absender-Konto waehlen."); return; }
  try {
    const r = await fetch(API.value + "&action=save_draft", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: currentDraftId.value || 0, to_email: composeTo.value, subject: composeSubject.value, body: composeBody.value, property_id: composePropertyId.value, stakeholder: composeStakeholder.value, account_id: selectedAccountId.value, tone: composeTone.value, source_email_id: emailSourceId.value || null }),
    });
    const d = await r.json();
    if (d.ok) { const isNew = !currentDraftId.value; currentDraftId.value = d.id; if (isNew) draftsCount.value++; toast("Entwurf gespeichert."); }
    else toast("Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
}

// === EMAIL HISTORY ===
async function loadEmailHistory() {
  const thisRequest = ++ehRequestId;
  ehLoading.value = true;
  try {
    let url = API.value + "&action=email_history&per_page=" + ehPerPage.value + "&page=" + ehPage.value;
    if (ehPropertyId.value !== "0") url += "&property_id=" + ehPropertyId.value;
    if (ehSearch.value.trim()) url += "&search=" + encodeURIComponent(ehSearch.value.trim());
    if (ehCategory.value) url += "&category=" + ehCategory.value;
    if (ehDirection.value) url += "&direction=" + ehDirection.value;
    if (ehAccountId.value) url += "&account_id=" + ehAccountId.value;
    if (ehShowUnmatched.value) url += "&unmatched=1";
    if (ehShowUnmatched.value && !inboxProps.value.length) loadInbox();
    const r = await fetch(url);
    const d = await r.json();
    if (thisRequest !== ehRequestId) return;
    const newItems = (d.emails || []).map(e => ({ ...e, _assignTo: e._assignTo || "", ref_id: e.property_ref_id || e.matched_ref_id || "" }));
    if (ehPage.value > 1) { ehData.value = [...ehData.value, ...newItems]; } else { ehData.value = newItems; }
    ehTotal.value = d.total || 0;
    ehTotalPages.value = d.total_pages || 0;
  } catch (e) {
    if (thisRequest !== ehRequestId) return;
    toast("Fehler: " + e.message);
  }
  if (thisRequest === ehRequestId) ehLoading.value = false;
}

function ehPageChange(p) { ehPage.value = p; loadEmailHistory(); }

async function toggleEmailThread(em) {
  if (ehExpanded.value === em.id) { ehExpanded.value = null; return; }
  ehExpanded.value = em.id;
  if (ehThreadMessages.value[em.id]) return;
  ehThreadLoading.value = em.id;
  try {
    const r = await fetch(API.value + "&action=conversations&stakeholder=" + encodeURIComponent(em.stakeholder || em.from_name || '') + "&property_id=" + (em.property_id || 0));
    const d = await r.json();
    ehThreadMessages.value[em.id] = d.messages || [];
  } catch (e) { ehThreadMessages.value[em.id] = []; }
  ehThreadLoading.value = null;
}

// === ATTACHMENT HANDLING ===
async function downloadAttachment(emailId, fileIndex, filename) {
  try {
    const url = API.value + "&action=download_attachment&email_id=" + emailId + "&file_index=" + fileIndex + "&dl_mode=download";
    const r = await fetch(url);
    if (!r.ok) { const e = await r.json(); toast("Fehler: " + (e.error || "Download fehlgeschlagen")); return; }
    const blob = await r.blob();
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = filename || "attachment";
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 100);
  } catch (e) { toast("Download-Fehler: " + e.message); }
}

async function saveAttachmentToProperty(emailId, fileIndex, propertyId, label) {
  const key = emailId + "-" + fileIndex;
  attachAssigning.value = key;
  try {
    const r = await fetch(API.value + "&action=save_attachment_to_property", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email_id: emailId, file_index: fileIndex, property_id: propertyId, label: label || "" })
    });
    const d = await r.json();
    if (d.success) {
      toast("Anhang zum Objekt gespeichert!");
      attachAssignOpen.value = null;
    } else {
      toast("Fehler: " + (d.error || "Speichern fehlgeschlagen"));
    }
  } catch (e) { toast("Fehler: " + e.message); }
  attachAssigning.value = null;
}

// === PROPERTY FILES FOR COMPOSE ===
async function loadPropertyFiles(propertyId) {
  if (!propertyId) {
    propertyFilesLoading.value = true;
    try {
      const rg = await fetch(API.value + "&action=list_global_files");
      const dg = await rg.json();
      propertyFiles.value = (dg.files || []).map(gf => ({ id: "global-" + gf.id, label: "[Allgemein] " + (gf.label || gf.original_name), filename: gf.original_name, url: "/storage/" + gf.path, checked: false }));
    } catch (e) { propertyFiles.value = []; }
    propertyFilesLoading.value = false;
    return;
  }
  propertyFilesLoading.value = true;
  try {
    const items = [];
    const r1 = await fetch(API.value + "&action=get_property_files&property_id=" + propertyId);
    const d1 = await r1.json();
    for (const f of (d1.files || [])) {
      items.push({ id: "pf-" + f.id, label: f.label, filename: f.filename, url: f.url, checked: false });
    }
    const r2 = await fetch(API.value + "&action=list_portal_documents&property_id=" + propertyId);
    const d2 = await r2.json();
    for (const doc of (d2.documents || [])) {
      items.push({ id: "doc-" + doc.id, label: doc.description || doc.original_name, filename: doc.original_name, url: doc.file_url, checked: false });
    }
    try {
      const rg = await fetch(API.value + "&action=list_global_files");
      const dg = await rg.json();
      for (const gf of (dg.files || [])) {
        items.push({ id: "global-" + gf.id, label: "[Allgemein] " + (gf.label || gf.original_name), filename: gf.original_name, url: "/storage/" + gf.path, checked: false });
      }
    } catch (eg) {}
    propertyFiles.value = items;
  } catch (e) { propertyFiles.value = []; }
  propertyFilesLoading.value = false;
}

async function onPropertyFileToggle(pf) {
  pf.checked = !pf.checked;
  if (pf.checked) {
    if (!composeAttachments.value.some(a => a.name === pf.filename)) {
      try {
        const resp = await fetch(pf.url);
        const blob = await resp.blob();
        const file = new File([blob], pf.filename, { type: blob.type });
        composeAttachments.value.push(file);
      } catch (e) { toast("Fehler beim Laden: " + pf.filename); pf.checked = false; }
    }
  } else {
    composeAttachments.value = composeAttachments.value.filter(a => a.name !== pf.filename);
  }
}

watch(composePropertyId, (newId) => loadPropertyFiles(newId), { immediate: true });

// === REPLY TO EMAIL ===
async function replyToEmail(em) {
  activeSubtab.value = "compose";
  const toEmail = em.direction === 'outbound'
    ? (em.to_email || "")
    : (em.prospect_email || extractRealEmail(em.from_email, em.body_text));
  composeTo.value = toEmail;
  composeSubject.value = (em.subject || "").startsWith("Re:") ? em.subject : "Re: " + (em.subject || "");
  composeBody.value = "";
  composePropertyId.value = em.property_id || null;
  composeStakeholder.value = em.stakeholder || em.from_name || "";
  emailSourceId.value = String(em.id);
  currentDraftId.value = null;

  replyContext.value = {
    stakeholder: em.stakeholder || em.from_name || em.from_email || "",
    ref_id: em.property_ref_id || "",
    address: em.property_address || "",
    originalSubject: em.subject || "",
    originalDate: em.email_date || "",
    originalFrom: (em.from_name || "") + (em.from_email ? " <" + em.from_email + ">" : ""),
    originalBody: em.body_text || em.ai_summary || "",
    messages: null,
  };

  if (em.property_id && em.stakeholder) {
    replyContextLoading.value = true;
    try {
      const r = await fetch(API.value + "&action=email_context&email_id=" + em.id);
      const d = await r.json();
      if (d.thread && d.thread.length) {
        replyContext.value.messages = d.thread;
        replyContext.value.ref_id = d.ref_id || replyContext.value.ref_id;
        replyContext.value.address = d.address || replyContext.value.address;
      }
      if (d.prospect_email && (!composeTo.value || /noreply|no-reply|notification|typeform|followups/i.test(composeTo.value))) {
        composeTo.value = d.prospect_email;
      }
    } catch (e) { /* silent */ }
    replyContextLoading.value = false;
  }

  if (em.property_id) {
    loadPropertyFiles(em.property_id);
  }
}

// === TRASH / SELECT / BULK ===
async function trashEmail(id) {
  if (!id) return;
  if (trashConfirmId.value !== id) {
    trashConfirmId.value = id;
    toast("Nochmal klicken zum Löschen");
    setTimeout(() => { if (trashConfirmId.value === id) trashConfirmId.value = null; }, 3000);
    return;
  }
  trashConfirmId.value = null;
  try {
    const r = await fetch(API.value + "&action=trash_emails", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ids: [id] }) });
    const d = await r.json();
    if (d.ok) {
      ehData.value = ehData.value.filter((e) => e.id !== id);
      unansweredList.value = unansweredList.value.filter((e) => e.id !== id);
      if (followupData.value && followupData.value.followups) followupData.value.followups = followupData.value.followups.filter((e) => e.id !== id);
      stage1Followups.value = stage1Followups.value.filter((e) => e.id !== id);
      ehTotal.value--;
      trashCount.value++;
      if (selectedItem.value && selectedItem.value.id === id) { selectedItem.value = null; }
      toast("In Papierkorb verschoben");
    }
  } catch (e) { toast("Fehler: " + e.message); }
}

function toggleEmailSelect(id) {
  const idx = ehSelected.value.indexOf(id);
  if (idx === -1) ehSelected.value.push(id);
  else ehSelected.value.splice(idx, 1);
  ehSelectAll.value = ehSelected.value.length === ehData.value.length;
}

function toggleSelectAll() {
  if (ehSelectAll.value) {
    ehSelected.value = [];
    ehSelectAll.value = false;
  } else {
    ehSelected.value = ehData.value.map(e => e.id);
    ehSelectAll.value = true;
  }
}

async function bulkTrash() {
  if (!ehSelected.value.length) return;
  try {
    const r = await fetch(API.value + "&action=trash_emails", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ids: ehSelected.value }) });
    const d = await r.json();
    if (d.ok) {
      const count = ehSelected.value.length;
      ehData.value = ehData.value.filter(e => !ehSelected.value.includes(e.id));
      ehTotal.value -= count;
      trashCount.value += count;
      ehSelected.value = [];
      ehSelectAll.value = false;
      toast(count + " E-Mail(s) in Papierkorb");
    }
  } catch (e) { toast("Fehler: " + e.message); }
}

// === INBOX (nicht zugeordnet) ===
async function loadInbox() {
  inboxLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=unmatched_emails");
    const d = await r.json();
    inboxEmails.value = (d.emails || []).map((e) => ({ ...e, _assignTo: e.suggested_property_id ? String(e.suggested_property_id) : "", _contacts: [], _assignContact: "" }));
    inboxProps.value = d.properties || [];
    unmatchedCount.value = d.total || 0;
  } catch (e) { toast("Fehler: " + e.message); }
  inboxLoading.value = false;
}

async function loadContactsForProperty(propertyId) {
  if (!propertyId) return [];
  try {
    const r = await fetch(API.value + "&action=property_contacts&property_id=" + propertyId);
    const d = await r.json();
    return d.contacts || [];
  } catch { return []; }
}

async function onPropertyChange(email) {
  email._assignContact = '';
  if (email._assignTo) {
    email._contacts = await loadContactsForProperty(parseInt(email._assignTo));
  } else {
    email._contacts = [];
  }
}

async function assignEmailFromHistory(em) {
  if (!em._assignTo) { toast("Bitte ein Objekt auswaehlen."); return; }
  try {
    const r = await fetch(API.value + "&action=assign_email", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email_id: em.id, property_id: parseInt(em._assignTo), merge_stakeholder: "" }),
    });
    const d = await r.json();
    if (d.success) { ehData.value = ehData.value.filter(e => e.id !== em.id); ehTotal.value--; unmatchedCount.value = Math.max(0, unmatchedCount.value - 1); toast("Zugeordnet!"); }
    else toast("Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
}

async function dismissEmailFromHistory(em) {
  try {
    const r = await fetch(API.value + "&action=dismiss_email", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email_id: em.id }),
    });
    ehData.value = ehData.value.filter(e => e.id !== em.id); ehTotal.value--;
    unmatchedCount.value = Math.max(0, unmatchedCount.value - 1);
    toast("Ausgeblendet");
  } catch (e) { toast("Fehler: " + e.message); }
}

async function assignEmail(email) {
  if (!email._assignTo) { toast("Bitte ein Objekt auswaehlen."); return; }
  try {
    const r = await fetch(API.value + "&action=assign_email", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email_id: email.id, property_id: parseInt(email._assignTo), merge_stakeholder: email._assignContact || "" }),
    });
    const d = await r.json();
    if (d.success) {
      toast("Zugeordnet: " + d.message);
      inboxEmails.value = inboxEmails.value.filter((e) => e.id !== email.id);
      unmatchedCount.value = Math.max(0, unmatchedCount.value - 1);
      refreshCounts();
    } else toast("Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
}

function dismissEmail(email) {
  inboxEmails.value = inboxEmails.value.filter((e) => e.id !== email.id);
  unmatchedCount.value = Math.max(0, unmatchedCount.value - 1);
}

// === DRAFTS ===
async function loadDrafts() {
  draftsLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=list_drafts");
    const d = await r.json();
    draftsData.value = d.drafts || [];
    draftsCount.value = d.count || 0;
  } catch (e) { toast("Fehler: " + e.message); }
  draftsLoading.value = false;
}

function _oldLoadDraftIntoComposer(dr) {
  activeSubtab.value = "compose";
  currentDraftId.value = dr.id;
  composeTo.value = dr.to_email || "";
  composeSubject.value = dr.subject || "";
  composeBody.value = dr.body || "";
  composePropertyId.value = dr.property_id || null;
  composeStakeholder.value = dr.stakeholder || "";
  selectedAccountId.value = dr.account_id || null;
  composeTone.value = dr.tone || "professional";
  emailSourceId.value = dr.source_email_id ? String(dr.source_email_id) : "";
  composeAttachments.value = [];

  if (dr.source_email_id) {
    replyContext.value = { stakeholder: dr.stakeholder || '', ref_id: '', address: '', originalSubject: '', originalDate: '', originalFrom: '', originalBody: '', messages: null };
    replyContextLoading.value = true;
    fetch(API.value + "&action=email_context&email_id=" + dr.source_email_id).then(r => r.json()).then(d => {
      replyContext.value.ref_id = (d.email && d.email.ref_id) || '';
      replyContext.value.address = (d.email ? (d.email.address || '') + (d.email.city ? ', ' + d.email.city : '') : '');
      if (d.email) {
        replyContext.value.originalSubject = d.email.subject || '';
        replyContext.value.originalDate = d.email.email_date || '';
        replyContext.value.originalFrom = (d.email.from_name || '') + (d.email.from_email ? ' <' + d.email.from_email + '>' : '');
        replyContext.value.originalBody = d.email.body_text || d.email.ai_summary || '';
      }
      if (d.thread && d.thread.length) replyContext.value.messages = d.thread;
      replyContextLoading.value = false;
    }).catch(() => { replyContextLoading.value = false; });
  } else {
    replyContext.value = null;
  }

  if (dr.property_id) loadPropertyFiles(dr.property_id);
}

async function deleteDraft(id) {
  if (!confirm("Entwurf loeschen?")) return;
  try {
    const r = await fetch(API.value + "&action=delete_draft", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id }) });
    const d = await r.json();
    if (d.ok) { draftsData.value = draftsData.value.filter((dr) => dr.id !== id); draftsCount.value--; toast("Entwurf geloescht"); }
  } catch (e) { toast("Fehler: " + e.message); }
}

// === TRASH ===
async function loadTrash() {
  trashLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=email_history&trash=1&per_page=100&page=1");
    const d = await r.json();
    trashData.value = d.emails || [];
    trashCount.value = d.total || 0;
  } catch (e) { toast("Fehler: " + e.message); }
  trashLoading.value = false;
}

async function restoreEmails(ids) {
  try {
    const r = await fetch(API.value + "&action=restore_emails", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ids }) });
    const d = await r.json();
    if (d.ok) { trashData.value = trashData.value.filter((e) => !ids.includes(e.id)); trashCount.value -= ids.length; trashSelected.value = []; toast(d.restored + " E-Mail(s) wiederhergestellt"); }
  } catch (e) { toast("Fehler: " + e.message); }
}

// === EMAIL TEMPLATES ===
async function loadTemplates() {
  templatesLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=list_templates");
    const d = await r.json();
    templates.value = d.templates || [];
  } catch (e) { toast("Fehler: " + e.message); }
  templatesLoading.value = false;
}

function applyTemplate(tpl) {
  if (!tpl) return;
  const addr = composePropertyId.value
    ? (properties.value.find(p => p.id == composePropertyId.value) || {}).address || ''
    : '';
  composeSubject.value = (tpl.subject || '').replace(/\{OBJEKT\}/g, addr || '{OBJEKT}');
  composeBody.value = (tpl.body || '').replace(/\{OBJEKT\}/g, addr || '{OBJEKT}');
}

function startNewTemplate() {
  templateEdit.value = { id: 0, name: '', subject: '', body: '', category: 'allgemein' };
}

function editTemplate(tpl) {
  templateEdit.value = { ...tpl };
}

function cancelTemplateEdit() {
  templateEdit.value = null;
}

async function saveTemplate() {
  if (!templateEdit.value || !templateEdit.value.name.trim()) { toast("Bitte einen Namen eingeben."); return; }
  templateSaving.value = true;
  try {
    const r = await fetch(API.value + "&action=save_template", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify(templateEdit.value),
    });
    const d = await r.json();
    if (d.ok) {
      toast("Template gespeichert.");
      templateEdit.value = null;
      await loadTemplates();
    } else toast("Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
  templateSaving.value = false;
}

async function deleteTemplate(id) {
  if (!confirm("Template wirklich loeschen?")) return;
  try {
    const r = await fetch(API.value + "&action=delete_template", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    });
    const d = await r.json();
    if (d.ok) { templates.value = templates.value.filter(t => t.id !== id); toast("Template geloescht."); }
    else toast("Fehler: " + (d.error || "Unbekannt"));
  } catch (e) { toast("Fehler: " + e.message); }
}

// ============================================================
// COMPOSE FUNCTIONS
// ============================================================
function startCompose() {
  composing.value = true;
  composeTo.value = '';
  composeCc.value = '';
  composeBcc.value = '';
  showCcBcc.value = false;
  composeSubject.value = '';
  composeBody.value = buildSignature ? buildSignature() : '';
  composeAttachments.value = [];
  composePropertyId.value = null;
  composeStakeholder.value = '';
  emailSourceId.value = '';
  currentDraftId.value = null;
  replyContext.value = null;
  selectedItem.value = null;
}

function closeCompose() {
  composing.value = false;
}

function onComposeSend() {
  sendEmail().then(() => {
    composing.value = false;
  });
}

function onComposeSaveDraft() {
  saveDraft();
}

function onComposeAddAttachments(e) {
  addAttachments(e);
}

function onComposeRemoveAttachment(idx) {
  composeAttachments.value.splice(idx, 1);
}

function loadDraftIntoCompose(dr) {
  composing.value = true;
  currentDraftId.value = dr.id;
  composeTo.value = dr.to_email || "";
  composeSubject.value = dr.subject || "";
  composeBody.value = dr.body || "";
  composePropertyId.value = dr.property_id || null;
  composeStakeholder.value = dr.stakeholder || "";
  selectedAccountId.value = dr.account_id || null;
  composeTone.value = dr.tone || "professional";
  emailSourceId.value = dr.source_email_id ? String(dr.source_email_id) : "";
  composeAttachments.value = [];
  selectedItem.value = null;

  if (dr.source_email_id) {
    replyContext.value = { stakeholder: dr.stakeholder || '', ref_id: '', address: '', originalSubject: '', originalDate: '', originalFrom: '', originalBody: '', messages: null };
    replyContextLoading.value = true;
    fetch(API.value + "&action=email_context&email_id=" + dr.source_email_id).then(r => r.json()).then(d => {
      replyContext.value.ref_id = (d.email && d.email.ref_id) || '';
      replyContext.value.address = (d.email ? (d.email.address || '') + (d.email.city ? ', ' + d.email.city : '') : '');
      if (d.email) {
        replyContext.value.originalSubject = d.email.subject || '';
        replyContext.value.originalDate = d.email.email_date || '';
        replyContext.value.originalFrom = (d.email.from_name || '') + (d.email.from_email ? ' <' + d.email.from_email + '>' : '');
        replyContext.value.originalBody = d.email.body_text || d.email.ai_summary || '';
      }
      if (d.thread && d.thread.length) replyContext.value.messages = d.thread;
      replyContextLoading.value = false;
    }).catch(() => { replyContextLoading.value = false; });
  } else {
    replyContext.value = null;
  }

  if (dr.property_id) loadPropertyFiles(dr.property_id);
}

// Filtered/computed for subtabs
const filteredEhData = computed(() => {
  let list = ehData.value;
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i =>
      (i.from_name || '').toLowerCase().includes(q) ||
      (i.subject || '').toLowerCase().includes(q) ||
      (i.from_email || '').toLowerCase().includes(q) ||
      (i.stakeholder || '').toLowerCase().includes(q)
    );
  }
  if (objectFilter.value !== 'all') {
    list = list.filter(i => String(i.property_id) === objectFilter.value);
  }
  return list;
});

const filteredDrafts = computed(() => {
  let list = draftsData.value;
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i =>
      (i.to_email || '').toLowerCase().includes(q) ||
      (i.subject || '').toLowerCase().includes(q) ||
      (i.stakeholder || '').toLowerCase().includes(q)
    );
  }
  return list;
});

const filteredTemplates = computed(() => {
  let list = templates.value;
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i =>
      (i.name || '').toLowerCase().includes(q) ||
      (i.subject || '').toLowerCase().includes(q) ||
      (i.category || '').toLowerCase().includes(q)
    );
  }
  return list;
});

// ============================================================
// WATCHERS (from PrioritiesTab.vue)
// ============================================================
watch(maklerFilter, () => {
  loadUnanswered(unansweredFilter.value);
  loadFollowups(followupFilter.value);
  loadStage1();
});

watch(activeSubtab, (v) => {
  if (v === 'posteingang') { ehDirection.value = 'inbound'; ehShowUnmatched.value = false; ehPage.value = 1; loadEmailHistory(); }
  if (v === 'gesendet') { ehDirection.value = 'outbound'; ehPage.value = 1; loadEmailHistory(); }
  if (v === 'nachfassen') { loadFollowups(followupFilter.value); loadStage1(); }
  if (v === 'papierkorb') loadTrash();
  if (v === 'offen') { loadUnanswered(unansweredFilter.value); }
  if (v === 'entwuerfe') loadDrafts();
  if (v === 'templates' && !templates.value?.length) loadTemplates();
});

// ============================================================
// LIFECYCLE — merged onMounted from both tabs
// ============================================================
onMounted(() => {
  // Migrate old tab state
  const oldTab = localStorage.getItem("sr-admin-tab");
  if (oldTab === "priorities" || oldTab === "comms") {
    localStorage.setItem("sr-admin-tab", "inbox");
  }

  // Priorities data
  loadUnanswered("all");
  loadFollowups("all");
  loadStage1();
  loadAutoReplyLogs();
  loadAutoReplySettings();
  loadBrokerList();

  // Comms data
  loadSignature();
  loadEmailAccountsSelect();

  // Check for compose prefill from Priorities tab
  const prefill = sessionStorage.getItem('sr-compose-prefill');
  if (prefill) {
    sessionStorage.removeItem('sr-compose-prefill');
    try {
      const data = JSON.parse(prefill);
      composeTo.value = data.to || '';
      composeSubject.value = data.subject || '';
      composeBody.value = data.body || '';
      composeStakeholder.value = data.stakeholder || '';
      composePropertyId.value = data.propertyId || null;
      emailSourceId.value = data.sourceId || '';
      currentDraftId.value = null;
      activeSubtab.value = 'compose';
      if (data.sourceId) {
        replyContext.value = { stakeholder: data.stakeholder || '', ref_id: '', address: '', originalSubject: '', originalDate: '', originalFrom: '', originalBody: '', messages: null };
        replyContextLoading.value = true;
        const ctxType = data.sourceType === "activity" ? "&type=activity" : "";
        fetch(API.value + "&action=email_context&email_id=" + data.sourceId + ctxType).then(r => r.json()).then(d => {
          replyContext.value.ref_id = (d.email && d.email.ref_id) || '';
          replyContext.value.address = (d.email ? (d.email.address || '') + (d.email.city ? ', ' + d.email.city : '') : '');
          if (d.email) {
            replyContext.value.originalSubject = d.email.subject || '';
            replyContext.value.originalDate = d.email.email_date || '';
            replyContext.value.originalFrom = (d.email.from_name || '') + (d.email.from_email ? ' <' + d.email.from_email + '>' : '');
            replyContext.value.originalBody = d.email.body_text || d.email.ai_summary || '';
          }
          if (d.thread && d.thread.length) replyContext.value.messages = d.thread;
          replyContextLoading.value = false;
          if (d.prospect_email && (!composeTo.value || /noreply|no-reply|notification|typeform|followups/i.test(composeTo.value))) {
            composeTo.value = d.prospect_email;
          }
          if (data.propertyId) loadPropertyFiles(data.propertyId);
        }).catch(() => { replyContextLoading.value = false; });
      }
      if (data.propertyId) {
        loadPropertyFiles(data.propertyId).then(() => {
          if (data.preselectedFiles && data.preselectedFiles.length) {
            for (const pf of propertyFiles.value) {
              const numId = parseInt(String(pf.id).replace("pf-", ""));
              if (data.preselectedFiles.includes(numId)) {
                onPropertyFileToggle(pf);
              }
            }
          }
        });
      }
      return;
    } catch (e) { /* ignore parse errors */ }
  }

  // Load data for active subtab
  loadTemplates();
  if (activeSubtab.value === "posteingang") { ehDirection.value = "inbound"; loadEmailHistory(); }
  if (activeSubtab.value === "gesendet") { ehDirection.value = "outbound"; loadEmailHistory(); }
  if (activeSubtab.value === "entwuerfe") loadDrafts();
  if (userType.value === "assistenz") loadEmailAccounts();
});
</script>

<template>
  <div class="flex flex-col h-full relative" style="min-height:0">

    <!-- Background gradient -->
    <div v-if="bgGradient" class="absolute inset-0 z-0" :style="{ background: bgGradient, opacity: bgOpacity }"></div>

    <!-- BG picker button -->
    <button @click="showBgPicker = !showBgPicker" class="absolute top-2 right-2 z-20 w-6 h-6 rounded-full bg-white/80 hover:bg-white shadow-sm flex items-center justify-center" title="Hintergrund wählen">
      <ImageIcon class="w-3 h-3 text-muted-foreground" />
    </button>
    <div v-if="showBgPicker" class="absolute top-10 right-2 z-30 bg-white rounded-lg shadow-lg border border-zinc-100 p-2 w-52">
      <div class="space-y-0.5">
        <button v-for="bg in defaultBgs" :key="bg.label" @click="setBg(bg.value)"
          class="w-full text-left px-2 py-1.5 text-[11px] rounded flex items-center gap-2 hover:bg-zinc-50"
          :class="bgGradient === bg.value ? 'bg-orange-50 text-orange-700' : ''"
        >
          <div v-if="bg.value" class="w-4 h-4 rounded-full flex-shrink-0 border border-zinc-200" :style="{ background: bg.value }"></div>
          <div v-else class="w-4 h-4 rounded-full flex-shrink-0 border border-zinc-200 bg-white"></div>
          {{ bg.label }}
        </button>
      </div>
      <div v-if="bgGradient" class="pt-2 mt-2 border-t border-zinc-100">
        <div class="flex items-center justify-between mb-1">
          <span class="text-[10px] text-muted-foreground">Intensität</span>
          <span class="text-[10px] text-muted-foreground">{{ Math.round(bgOpacity * 100) }}%</span>
        </div>
        <input type="range" min="0.05" max="0.5" step="0.05" v-model.number="bgOpacity"
          @input="localStorage.setItem('sr-inbox-bg-opacity', String(bgOpacity))"
          class="w-full h-1 accent-orange-500 cursor-pointer" />
      </div>
    </div>

    <!-- Content (z-10 above background) -->
    <div class="relative z-10 flex flex-1 min-h-0 overflow-hidden">
      <!-- Left: Conversation List -->
      <div class="w-[400px] flex-shrink-0 border-r border-zinc-100 flex flex-col h-full overflow-hidden" :style="bgGradient ? { background: 'rgba(255,255,255,0.92)' } : {}">

        <!-- Panel Header with Pill Tabs -->
        <div class="px-4 pt-3 pb-2 flex-shrink-0">

          <!-- Primary Pills: Offen / Nachfassen / Alle -->
          <div class="flex gap-0.5 p-[3px] bg-[#f4f4f5] rounded-lg mb-2">
            <button
              @click="activeSubtab = 'offen'"
              class="flex-1 flex items-center justify-center gap-1.5 px-3 py-[5px] text-[12px] rounded-md transition-all"
              :class="activeSubtab === 'offen'
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >
              Offen
              <span v-if="unansweredCount" class="text-[9px] font-bold px-1.5 py-0 rounded-md bg-red-50 text-red-600">{{ unansweredCount }}</span>
            </button>
            <button
              @click="activeSubtab = 'nachfassen'"
              class="flex-1 flex items-center justify-center gap-1.5 px-3 py-[5px] text-[12px] rounded-md transition-all"
              :class="activeSubtab === 'nachfassen'
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >
              Nachfassen
              <span v-if="followupCount" class="text-[9px] font-bold px-1.5 py-0 rounded-md bg-zinc-200 text-zinc-600">{{ followupCount }}</span>
            </button>
            <button
              @click="activeSubtab = 'posteingang'"
              class="flex-1 flex items-center justify-center gap-1.5 px-3 py-[5px] text-[12px] rounded-md transition-all"
              :class="['posteingang','gesendet','entwuerfe','templates','papierkorb'].includes(activeSubtab)
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >
              Alle
            </button>
          </div>

          <!-- Secondary tabs (only visible when "Alle" is active) -->
          <div v-if="['posteingang','gesendet','entwuerfe','templates','papierkorb'].includes(activeSubtab)" class="flex gap-1 mb-1">
            <button v-for="st in [
              { key: 'posteingang', label: 'Posteingang' },
              { key: 'gesendet', label: 'Gesendet' },
              { key: 'entwuerfe', label: 'Entw\u00fcrfe' },
              { key: 'templates', label: 'Templates' },
              { key: 'papierkorb', label: 'Papierkorb' },
            ]" :key="st.key"
              @click="activeSubtab = st.key"
              class="px-2.5 py-1 text-[11px] rounded-md transition-colors"
              :class="activeSubtab === st.key
                ? 'bg-zinc-200 text-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-zinc-100'"
            >
              {{ st.label }}
            </button>
          </div>
        </div>

        <!-- Auto-Reply Info + Settings -->
        <div v-if="activeSubtab === 'offen'" class="px-4 pb-1 flex items-center gap-2">
          <span v-if="autoReplyLogs.length" class="text-[10px] text-emerald-600">✓ {{ autoReplyLogs.length }} Auto-Replies (24h)</span>
          <span v-else-if="autoReplyPropertyIds.length" class="text-[10px] text-muted-foreground">Auto-Reply aktiv für {{ autoReplyPropertyIds.length }} {{ autoReplyPropertyIds.length === 1 ? 'Objekt' : 'Objekte' }}</span>
          <div class="flex-1"></div>
          <Button variant="ghost" size="sm" class="h-6 w-6 p-0" @click="showAutoReplySettings = !showAutoReplySettings; if (showAutoReplySettings && !autoReplyText) loadAutoReplySettings()" title="Auto-Reply Einstellungen">
            <Settings2 class="w-3 h-3 text-muted-foreground" />
          </Button>
        </div>
        <div v-if="showAutoReplySettings && activeSubtab === 'offen'" class="mx-4 mb-2 rounded-lg border border-zinc-100 bg-white/80 backdrop-blur-sm p-3 space-y-3">
          <div class="text-[12px] font-semibold">Auto-Reply Einstellungen</div>
          <div class="text-[10px] text-muted-foreground">Wähle Objekte für automatische Antworten:</div>
          <div class="space-y-1 max-h-40 overflow-y-auto">
            <label v-for="p in (properties || [])" :key="p.id" class="flex items-center gap-2 text-[11px] cursor-pointer hover:bg-zinc-50 px-2 py-1.5 rounded">
              <input type="checkbox" :value="p.id" v-model="autoReplyPropertyIds" class="rounded border-zinc-300" />
              <span class="flex-1">{{ p.ref_id }} — {{ p.address }}</span>
            </label>
          </div>
          <div>
            <div class="text-[11px] font-medium mb-1">Antwort-Text (leer = KI generiert automatisch)</div>
            <Textarea v-model="autoReplyText" class="text-[11px] min-h-[60px]" placeholder="Vielen Dank für Ihre Anfrage..." />
          </div>
          <div v-if="!autoReplyText" class="text-[10px] text-amber-600 bg-amber-50 rounded px-2 py-1">
            Kein Text hinterlegt — es wird automatisch ein KI-Entwurf als Antwort generiert.
          </div>
          <div class="flex justify-end">
            <Button size="sm" class="h-7 text-[11px]" :disabled="autoReplySaving" @click="saveAutoReplySettings">Speichern</Button>
          </div>
        </div>
        <div v-if="isAssistenz && brokerList.length" class="px-3 pb-1 flex-shrink-0">
          <select v-model="maklerFilter" class="h-6 rounded-md border border-zinc-100 bg-background px-2 text-[11px]">
            <option value="all">Alle Makler</option>
            <option v-for="b in brokerList" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>

        <!-- Offen -->
        <InboxConversationList
          v-if="activeSubtab === 'offen'"
          :items="filteredUnanswered"
          :loading="unansweredLoading"
          subtab="offen"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="objectFilter"
          :properties="availableProperties"
          empty-message="Keine offenen Konversationen"
          @select="(item) => openDetail(item, 'offen')"
          @update:search-query="searchQuery = $event"
          @update:object-filter="objectFilter = $event"
          @compose="startCompose()" @delete="trashEmail($event.id)"
        />

        <!-- Nachfassen Alle Button -->
        <div v-if="activeSubtab === 'nachfassen' && filteredFollowups.filter(f => f._prefetchedDraft).length > 0" class="px-4 pb-2">
          <Button
            variant="outline"
            size="sm"
            class="w-full h-8 text-[11px] gap-1.5 border-orange-200 text-orange-700 hover:bg-orange-50"
            @click="nachfassenAlle"
          >
            <Send class="w-3 h-3" />
            Alle nachfassen ({{ filteredFollowups.filter(f => f._prefetchedDraft).length }})
          </Button>
        </div>

        <!-- Nachfassen -->
        <InboxConversationList
          v-if="activeSubtab === 'nachfassen'"
          :items="filteredFollowups"
          :loading="followupLoading || stage1Loading"
          subtab="nachfassen"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="objectFilter"
          :properties="availableProperties"
          :grouped-sections="nachfassenSections"
          empty-message="Keine Nachfass-Konversationen"
          @select="(item) => openDetail(item, 'nachfassen')"
          @update:search-query="searchQuery = $event"
          @update:object-filter="objectFilter = $event"
          @compose="startCompose()" @delete="trashEmail($event.id)"
        />

        <!-- Posteingang -->
        <InboxConversationList
          v-else-if="activeSubtab === 'posteingang'"
          :items="filteredEhData"
          :loading="ehLoading"
          subtab="posteingang"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="objectFilter"
          :properties="properties || []"
          empty-message="Keine E-Mails im Posteingang"
          @select="(item) => openDetail(item, 'posteingang')"
          @update:search-query="searchQuery = $event"
          @update:object-filter="objectFilter = $event"
          @compose="startCompose()" @delete="trashEmail($event.id)"
        />

        <div v-if="activeSubtab === 'posteingang'  && ehTotal > ehData.length" class="p-3 border-t border-zinc-100 flex-shrink-0">
          <button @click="ehPage++; loadEmailHistory()" class="w-full h-8 text-[11px] text-muted-foreground hover:text-foreground transition-colors">
            Mehr laden ({{ ehData.length }}/{{ ehTotal }})
          </button>
        </div>
        <!-- Gesendet -->
        <InboxConversationList
          v-else-if="activeSubtab === 'gesendet'"
          :items="filteredEhData"
          :loading="ehLoading"
          subtab="gesendet"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="objectFilter"
          :properties="properties || []"
          empty-message="Keine gesendeten E-Mails"
          @select="(item) => openDetail(item, 'gesendet')"
          @update:search-query="searchQuery = $event"
          @update:object-filter="objectFilter = $event"
          @compose="startCompose()" @delete="trashEmail($event.id)"
        />

        <div v-if="activeSubtab === 'gesendet'  && ehTotal > ehData.length" class="p-3 border-t border-zinc-100 flex-shrink-0">
          <button @click="ehPage++; loadEmailHistory()" class="w-full h-8 text-[11px] text-muted-foreground hover:text-foreground transition-colors">
            Mehr laden ({{ ehData.length }}/{{ ehTotal }})
          </button>
        </div>
        <!-- Papierkorb -->
        <InboxConversationList
          v-else-if="activeSubtab === 'papierkorb'"
          :items="trashData"
          :loading="trashLoading"
          subtab="papierkorb"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="'all'"
          :properties="[]"
          empty-message="Papierkorb ist leer"
          @select="(item) => openDetail(item, 'papierkorb')"
          @update:search-query="searchQuery = $event"
          @compose="startCompose()"
        />

        <!-- Entwuerfe -->
        <InboxConversationList
          v-else-if="activeSubtab === 'entwuerfe'"
          :items="filteredDrafts"
          :loading="draftsLoading"
          subtab="entwuerfe"
          :selected-id="null"
          :search-query="searchQuery"
          :object-filter="'all'"
          :properties="[]"
          empty-message="Keine Entwuerfe"
          @select="(item) => loadDraftIntoCompose(item)"
          @update:search-query="searchQuery = $event"
          @compose="startCompose()" @delete="trashEmail($event.id)"
        />

        <!-- Templates -->
        <div v-else-if="activeSubtab === 'templates'" class="flex flex-col h-full overflow-hidden">
          <!-- Toolbar -->
          <div class="flex items-center gap-1.5 px-3 py-2 border-b border-zinc-100 flex-shrink-0">
            <div class="relative flex-1 min-w-0">
              <Search class="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
              <Input
                v-model="searchQuery"
                placeholder="Template suchen..."
                class="pl-7 h-8 text-[12px]"
              />
            </div>
            <Button variant="ghost" size="icon" class="h-8 w-8 flex-shrink-0" @click="startNewTemplate()">
              <Plus class="h-4 w-4" />
            </Button>
          </div>

          <!-- Template List -->
          <div class="flex-1 overflow-y-auto min-h-0">
            <div v-if="templatesLoading" class="flex items-center justify-center py-12">
              <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
            <div v-else-if="!filteredTemplates.length" class="flex items-center justify-center py-12">
              <span class="text-[12px] text-muted-foreground">Keine Templates</span>
            </div>
            <div v-else class="divide-y divide-zinc-100">
              <div
                v-for="tpl in filteredTemplates"
                :key="tpl.id"
                class="px-3 py-2.5 cursor-pointer hover:bg-accent/50 transition-colors"
                @click="editTemplate(tpl)"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[13px] font-medium text-foreground truncate">{{ tpl.name }}</span>
                  <Badge v-if="tpl.category" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal">
                    {{ tpl.category }}
                  </Badge>
                </div>
                <div class="text-[11px] text-muted-foreground truncate mt-0.5">{{ tpl.subject || 'Kein Betreff' }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

<!-- Right: Compose View or Chat View -->
      <InboxComposeView
        v-if="composing"
        :compose-to="composeTo"
        :compose-subject="composeSubject"
        :compose-body="composeBody"
        :compose-tone="composeTone"
        :selected-account-id="selectedAccountId"
        :email-accounts="emailAccountsSelect"
        :compose-attachments="composeAttachments"
        :sending="emailSending"
        :ai-loading="aiLoading"
        :contact-search-results="contactSearchResults"
        :contact-search-loading="contactSearchLoading"
        :show-contact-search="showContactSearch"
        :properties="properties || []"
        :compose-property-id="composePropertyId"
        :templates="templates"
        :compose-cc="composeCc"
        :compose-bcc="composeBcc"
        :show-cc-bcc="showCcBcc"
        :reply-context="replyContext"
        @update:compose-to="composeTo = $event"
        @update:compose-subject="composeSubject = $event"
        @update:compose-body="composeBody = $event"
        @update:compose-tone="composeTone = $event"
        @update:selected-account-id="selectedAccountId = $event"
        @update:compose-property-id="composePropertyId = $event"
        @update:compose-cc="composeCc = $event"
        @update:compose-bcc="composeBcc = $event"
        @update:show-cc-bcc="showCcBcc = $event"
        @send="onComposeSend"
        @save-draft="onComposeSaveDraft"
        @close="closeCompose"
        @search-contacts="searchContacts($event)"
        @select-contact="selectContact($event)"
        @blur-contact-search="onComposeToBlur"
        @generate-ai-reply="generateAiReply"
        @apply-template="applyTemplate($event)"
        @add-attachments="onComposeAddAttachments"
        @remove-attachment="onComposeRemoveAttachment"
      />
      <InboxChatView
        v-else-if="selectedItem"
        :item="selectedItem"
        :messages="allDetailMessages"
        :loading="expandedLoading"
        :mode="selectedMode"
        @close="selectedItem = null; detailOpen = false"
      >
        <template #ai-draft>
          <InboxAiDraft
            v-if="expandedAiDraft || expandedAiLoading"
            :draft="expandedAiDraft"
            :loading="expandedAiLoading"
            :mode="selectedMode"
            :send-accounts="sendAccounts"
            :send-account-id="sendAccountId"
            :show-email-fields="showEmailFields"
            :stage="selectedItem?._stage || 1"
            @update:draft="expandedAiDraft = $event"
            @update:send-account-id="sendAccountId = $event"
            @update:show-email-fields="showEmailFields = $event"
            @regenerate="regenerateAiDraft"
            @improve="improveWithAi"
            @update:tone="setAiDetailLevel($event)"
          />
        </template>
        <template #bottom-bar>
          <InboxBottomBar
            :mode="selectedMode"
            :sending="emailSending"
            :can-send="!!(expandedAiDraft?.to && expandedAiDraft?.body)"
            :attachment-count="expandedSelectedFiles?.length || 0"
            :show-calendar="showCalendar"
            @send="sendDraft"
            @delete="trashEmail(selectedItem?.id)"
            @mark-handled="markHandled(selectedItem?.from_name || selectedItem?.stakeholder, selectedItem?.property_id)"
            @toggle-attach="showAttachPopup = !showAttachPopup"
            @toggle-calendar="showCalendar = !showCalendar"
          />
        </template>
      </InboxChatView>
      <div v-else class="flex-1 flex items-center justify-center text-sm text-muted-foreground" :style="bgGradient ? { background: 'rgba(255,255,255,0.92)' } : {}">
        Konversation auswählen
      </div>
    </div>
  </div>
</template>
