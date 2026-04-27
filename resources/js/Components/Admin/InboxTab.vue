<script setup>
import { ref, inject, onMounted, computed, watch, nextTick, provide } from "vue";
import { catBadgeStyle, catLabel, catIsInbound } from "@/utils/categoryBadge.js";
import {
  Mail, Clock, Send, CheckCircle, X, ChevronDown, ChevronUp, CalendarDays,
  Paperclip, Loader2, Search, Sparkles, ArrowUp, ArrowDown,
  PenSquare, History, FileEdit, Trash2, Inbox, LayoutTemplate, Plus, Pencil,
  ChevronLeft, ChevronRight, Reply, Save, MailQuestion, Settings2
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
// HvComposeDialog wird jetzt global in Dashboard.vue gemountet — nicht mehr hier
import InboxComposeView from "./inbox/InboxComposeView.vue";
import InboxMatchView from "./inbox/InboxMatchView.vue";

// ============================================================
// INJECTIONS (merged from PrioritiesTab + CommsTab)
// ============================================================
const API = inject("API");
const toast = inject("toast");
// User's email signature block (plain string), provided by Dashboard.vue.
// Used by regenerateAiDraft to strip the AI's own sign-off and append
// the full company block so drafts always carry name + title + company
// + phone + website.
const injectedInboxSignature = inject("inboxSignature", "");
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
const matchMode = ref(false);
const matchItems = ref([]);

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

  // Newest first (matches reading-pane order in InboxChatView)
  return Array.from(seen.values()).sort((a, b) => {
    const da = new Date(a.date || a.activity_date || a.email_date || a.created_at || 0);
    const db = new Date(b.date || b.activity_date || b.email_date || b.created_at || 0);
    return db - da;
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

// Auto-reply
const autoReplyLogs = ref([]);
const showAutoFollowupSettings = ref(false);
const showNfStagePicker = ref(false);
const bulkSending = ref(false);
const showSaveAttachDialog = ref(false);
const saveAttachData = ref(null);
const saveAttachPropertyId = ref(null);
const saveAttachLabel = ref("");
const saveAttachSaving = ref(false);
const bulkSendTotal = ref(0);
const bulkSendDone = ref(0);
const bulkSendLabel = ref("");
const bulkSendCancelRequested = ref(false);
const nfStageSelection = ref({ nf1: true, nf2: true, nf3: true });
const autoFollowupStage1 = ref(false);
const autoFollowupStage2 = ref(false);
const autoFollowupStage3 = ref(false);
const autoFollowupSaving = ref(false);
const autoReplyLoading = ref(false);
const autoReplyBannerOpen = ref(false);
const showAutoReplySettings = ref(false);
const autoReplyEnabled = ref(false);
const autoReplyText = ref('');
const autoReplySaving = ref(false);
const autoReplyPropertyIds = ref([]);

// Broker filter
const BROKER_FILTER_STORAGE_KEY = "sr-inbox-broker-filter";
const maklerFilter = ref(localStorage.getItem(BROKER_FILTER_STORAGE_KEY) || '');
const brokerList = ref([]);

function resolveDefaultBrokerId(list) {
  const brokers = Array.isArray(list) ? list : [];
  if (!brokers.length) return '';
  const nico = brokers.find((b) => /nico/i.test(String(b.name || '')) && /berger/i.test(String(b.name || '')));
  if (nico?.id) return String(nico.id);
  const office = brokers.find((b) => /office/i.test(String(b.name || '')));
  if (office?.id) return String(office.id);
  return String(brokers[0].id || '');
}

function brokerDisplayName(b) {
  if (!b) return '';
  const name = String(b.name || b.full_name || b.label || '').trim();
  if (name) return name;
  const email = String(b.email || b.email_address || '').trim();
  if (email) return email;
  return `Makler #${b.id}`;
}

const effectiveBrokerFilter = computed(() => {
  if (!isAssistenz.value) return '';
  if (maklerFilter.value) return String(maklerFilter.value);
  return resolveDefaultBrokerId(brokerList.value);
});

const selectedBrokerLabel = computed(() => {
  const current = String(maklerFilter.value || '');
  const b = brokerList.value.find((x) => String(x.id) === current);
  return b ? brokerDisplayName(b) : '';
});

const inboxRefreshing = ref(false);

// ============================================================
// COMMS STATE (from CommsTab.vue)
// ============================================================

// Signature from settings
const sigData = ref(null);
provide("inboxSignatureData", sigData);

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
  const s1 = stage1Followups.value;
  const s2 = (followupData.value?.followups || []);
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

const filteredTrash = computed(() => {
  let list = trashData.value;
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i =>
      (i.from_name || i.stakeholder || '').toLowerCase().includes(q) ||
      (i.subject || '').toLowerCase().includes(q) ||
      (i.from_email || i.to_email || '').toLowerCase().includes(q)
    );
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
async function loadSignature(accountId = null) {
  try {
    const query = accountId
      ? "&action=signature_for_account&account_id=" + encodeURIComponent(accountId)
      : "&action=get_settings";
    const r = await fetch(API.value + query);
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
    const brokerParam = effectiveBrokerFilter.value ? "&broker_filter=" + encodeURIComponent(effectiveBrokerFilter.value) : "";
    const r = await fetch(API.value + "&action=conv_list&status=offen" + brokerParam);
    const d = await r.json();
    unansweredList.value = (d.conversations || []).map(c => ({
      ...c,
      from_name: c.from_name || c.stakeholder || '',
      from_email: c.from_email || c.contact_email || '',
    }));
    unansweredCount.value = d.total || 0;
    matchItems.value = (d.conversations || []).filter(c => c.match_count > 0 && !c.match_dismissed);
  } catch (e) { toast("Fehler: " + e.message); }
  unansweredLoading.value = false;
}

async function loadFollowups(filter) {
  followupFilter.value = filter;
  followupLoading.value = true;
  stage1Loading.value = true;
  try {
    const brokerParam = effectiveBrokerFilter.value ? "&broker_filter=" + encodeURIComponent(effectiveBrokerFilter.value) : "";
    const r = await fetch(API.value + "&action=conv_list&status=nachfassen&per_page=200" + brokerParam);
    const d = await r.json();
    const all = (d.conversations || []).map(c => {
      // Stage basiert auf followup_count (nicht status),
              // weil status zurueckgesetzt wird wenn Kunde antwortet
              // followup_count=0 → NF1, =1 → NF2, >=2 → NF3
      return {
        ...c,
        from_name: c.from_name || c.stakeholder || '',
        from_email: c.from_email || c.contact_email || '',
        _stage: Math.min(3, (c.followup_count || 0) + 1),
      };
    });
    // Stage 0 (beantwortet) goes to stage1Followups, rest to followupData
    stage1Followups.value = []; // All items now in followupData with correct stages
    stage1Count.value = stage1Followups.value.length;
    const followups = all;
    followupData.value = { followups };
    followupCount.value = d.total || all.length;
  } catch (e) { toast("Fehler: " + e.message); }
  followupLoading.value = false;
  stage1Loading.value = false;
}

async function loadStage1() {
  // Now handled by loadFollowups() via conv_list&status=nachfassen
  // Kept as no-op for call-site compatibility
}

async function loadMatchesTab() {
  try {
    const r = await fetch(API.value + '&action=conv_list&status=offen&has_matches=1')
    const d = await r.json()
    matchItems.value = (d.conversations || d.data || d).filter(c => c.match_count > 0 && !c.match_dismissed)
  } catch (e) {
    console.error('Failed to load matches', e)
  }
}

async function handleMatchDismiss() {
  if (!selectedItem.value) return
  await fetch(API.value + '&action=match_dismiss', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ conversation_id: selectedItem.value.id }),
  })
  selectedItem.value.match_count = 0
  selectedItem.value.match_dismissed = true
  matchMode.value = false
  matchItems.value = matchItems.value.filter(m => m.id !== selectedItem.value.id)
}

// Property change event from InboxChatView → update local state + refresh lists
function onPropertyChanged({ convId, newPropertyId }) {
  // Update selectedItem locally (für sofortiges visuelles Feedback)
  if (selectedItem.value) {
    selectedItem.value.property_id = newPropertyId
    const prop = (properties.value || []).find(p => Number(p.id) === Number(newPropertyId))
    selectedItem.value.ref_id = prop?.ref_id || null
    selectedItem.value.property_ref = prop?.ref_id || null
    selectedItem.value.property_ref_id = prop?.ref_id || null
  }
  // Refresh aktive Listen damit die Zuordnung in der Seitenleiste sichtbar wird
  try { loadUnanswered && loadUnanswered() } catch (e) {}
  try { loadFollowups && loadFollowups() } catch (e) {}
}

function handleMatchDraft(draftData) {
  matchMode.value = false
  if (selectedItem.value) {
    // Set draft in the AI draft panel
    expandedAiDraft.value = {
      body: draftData.draft_body || '',
      subject: draftData.draft_subject || '',
      to: draftData.draft_to || selectedItem.value.contact_email || '',
    }
    expandedAiLoading.value = false

    // Auto-select matched expose files
    if (draftData.file_ids && draftData.file_ids.length) {
      // Add matched files to the file list if not already there
      const existingIds = new Set(expandedFiles.value.map(f => f.id))
      if (draftData.file_map) {
        for (const fm of draftData.file_map) {
          if (!existingIds.has(fm.file_id)) {
            expandedFiles.value.push({
              id: fm.file_id,
              filename: fm.filename,
              label: fm.property_title + ' — ' + fm.filename,
              _matchProperty: fm.property_title,
            })
          } else {
            // Update label of existing file to show property name
            const existing = expandedFiles.value.find(f => f.id === fm.file_id)
            if (existing) existing._matchProperty = fm.property_title
          }
        }
      }
      // Select the matched files
      expandedSelectedFiles.value = [
        ...new Set([...expandedSelectedFiles.value, ...draftData.file_ids])
      ]
      selectedItem.value._matchFileIds = draftData.file_ids
      selectedItem.value._fileMap = draftData.file_map || []
    }

    selectedItem.value.match_count = 0
  }
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

async function loadAutoFollowupSettings() {
  try {
    const r = await fetch(API.value + "&action=get_auto_followup_settings");
    const d = await r.json();
    autoFollowupStage1.value = !!d.stage1_enabled;
    autoFollowupStage2.value = !!d.stage2_enabled;
    autoFollowupStage3.value = !!d.stage3_enabled;
  } catch (e) {}
}

async function saveAutoFollowupSettings() {
  autoFollowupSaving.value = true;
  try {
    await fetch(API.value + "&action=save_auto_followup_settings", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        stage1_enabled: autoFollowupStage1.value ? 1 : 0,
        stage2_enabled: autoFollowupStage2.value ? 1 : 0,
        stage3_enabled: autoFollowupStage3.value ? 1 : 0,
      }),
    });
    toast("Auto-Nachfassen gespeichert!");
  } catch (e) { toast("Fehler: " + e.message); }
  autoFollowupSaving.value = false;
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
        enabled: autoReplyEnabled.value ? 1 : 0,
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
function nachfassenAlle() {
  showNfStagePicker.value = !showNfStagePicker.value;
}

function nfStageCount(stage) {
  // Keine draft_body-Abhaengigkeit mehr — das Backend baut die Mail per
  // Template on-the-fly. Wir zaehlen alle faelligen Konversationen der
  // jeweiligen Stufe.
  return filteredFollowups.value.filter(f => f._stage === stage).length;
}

async function sendNachfassenSelected() {
  const sel = nfStageSelection.value;
  const stages = [];
  if (sel.nf1) stages.push(1);
  if (sel.nf2) stages.push(2);
  if (sel.nf3) stages.push(3);
  if (!stages.length) { toast("Bitte mindestens eine Stufe wählen"); return; }

  // Keine Draft-Filter mehr — das Backend baut die Nachfass-Mail fuer jede
  // faellige Konversation per Template on-the-fly (deterministisch, ohne KI).
  const items = filteredFollowups.value.filter(f => stages.includes(f._stage));
  if (!items.length) { toast("Keine fälligen Konversationen in den gewählten Stufen"); return; }

  showNfStagePicker.value = false;
  const allForStages = items;
  bulkSending.value = true;
  bulkSendTotal.value = allForStages.length;
  bulkSendDone.value = 0;
  bulkSendCancelRequested.value = false;
  bulkSendLabel.value = "Sende 0/" + allForStages.length + " Nachfass-Mails...";

  // Chunk-weise senden statt alles in einem Request — ermoeglicht dem User
  // den Abbruch zwischen den Chunks. Chunk-Groesse 5 ist ein guter Trade-off
  // zwischen Netzwerk-Overhead und Abbruch-Granularitaet.
  const chunkSize = 5;
  let totalSent = 0;
  const allErrors = [];
  try {
    for (let i = 0; i < allForStages.length; i += chunkSize) {
      if (bulkSendCancelRequested.value) {
        bulkSendLabel.value = "Abgebrochen — " + totalSent + " von " + allForStages.length + " gesendet";
        break;
      }
      const chunk = allForStages.slice(i, i + chunkSize);
      const convIds = chunk.map(f => f._conv_id || f.id).filter(Boolean);

      const r = await fetch(API.value + "&action=conv_followup_all", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          account_id: sendAccountId.value || 1,
          stages,
          conv_ids: convIds,
        }),
      });
      const d = await r.json();
      if (d.success) {
        totalSent += (d.sent || 0);
        if (d.errors?.length) allErrors.push(...d.errors);
      } else {
        allErrors.push(d.error || "Unbekannter Fehler");
      }
      bulkSendDone.value = totalSent;
      if (!bulkSendCancelRequested.value) {
        bulkSendLabel.value = "Sende " + totalSent + "/" + allForStages.length + " Nachfass-Mails...";
      }
    }
    if (!bulkSendCancelRequested.value) {
      bulkSendLabel.value = totalSent + " Nachfass-Mails gesendet!";
      if (allErrors.length) bulkSendLabel.value += " (" + allErrors.length + " Fehler)";
    }
  } catch (e) {
    bulkSendLabel.value = "Fehler: " + e.message;
  }

  setTimeout(() => { bulkSending.value = false; }, 3000);
  loadFollowups(followupFilter.value);
  loadUnanswered(unansweredFilter.value);
  refreshCounts();
}

async function loadBrokerList() {
  if (!isAssistenz.value || brokerList.value.length) return;
  try {
    const r = await fetch(API.value + '&action=list_brokers');
    const d = await r.json();
    brokerList.value = (d.brokers || []).filter((b) => {
      const t = String(b.user_type || '').toLowerCase();
      return ['admin', 'makler', 'assistenz', 'backoffice'].includes(t);
    });
    const savedBrokerId = String(maklerFilter.value || '');
    const hasSavedBroker = savedBrokerId && brokerList.value.some((b) => String(b.id) === savedBrokerId);
    const defaultBrokerId = resolveDefaultBrokerId(brokerList.value);
    if (!hasSavedBroker && defaultBrokerId) {
      maklerFilter.value = defaultBrokerId;
    }
  } catch {}
}

async function loadSendAccounts(brokerId, preferredAccountId = null) {
  sendAccounts.value = [];
  sendAccountId.value = null;
  try {
    const param = brokerId ? "&for_broker=" + brokerId : "";
    const r = await fetch(API.value + "&action=email_accounts" + param);
    const d = await r.json();
    sendAccounts.value = (d.accounts || []).filter(a => a.is_active !== false);
    const preferred = preferredAccountId == null ? null : Number(preferredAccountId);
    const hasPreferred = preferred != null && sendAccounts.value.some((a) => Number(a.id) === preferred);
    if (hasPreferred) {
      sendAccountId.value = preferred;
    } else if (sendAccounts.value.length) {
      sendAccountId.value = sendAccounts.value[0].id;
    }

    // Keep both compose contexts aligned to one sender account.
    if (sendAccountId.value) {
      selectedAccountId.value = sendAccountId.value;
      loadSignature(sendAccountId.value);
    }
  } catch {}
}

// ============================================================
// DETAIL PANEL FUNCTIONS (from PrioritiesTab.vue, sheetOpen → detailOpen)
// ============================================================
function openDetail(item, mode) {
  matchMode.value = false;
  selectedItem.value = item;
  sheetMode.value = mode;
  selectedMode.value = mode;
  composing.value = false;
  detailOpen.value = true;

  // Standard-Absender immer auf das Konto setzen, das die Mail empfangen hat.
  if (item?.account_id) {
    selectedAccountId.value = item.account_id;
    sendAccountId.value = item.account_id;
    loadSignature(item.account_id);
  }

  // Mark as read via conv_read
  if (!item.is_read && item.id && (mode === 'offen' || mode === 'nachfassen')) {
    fetch(API.value + "&action=conv_read&id=" + item.id, { method: "POST" }).catch(() => {});
    item.is_read = 1;
  } else if (!item.is_read && item.id) {
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

  // Use draft from conv_list response if available
  if ((mode === 'offen' || mode === 'nachfassen') && item.draft_body) {
    expandedAiDraft.value = {
      body: item.draft_body,
      subject: item.draft_subject || item.subject || '',
      to: item.draft_to || item.contact_email || '',
    };
    expandedAiLoading.value = false;
  } else if (mode === "posteingang") {
    // Allow replying to posteingang emails - show empty draft
    expandedAiDraft.value = {
      body: "",
      subject: "Re: " + (item.subject || ""),
      to: item.from_email || item.contact_email || "",
    };
    expandedAiLoading.value = false;
  } else if (mode === "gesendet") {
    expandedAiLoading.value = false;
  } else if (mode === "papierkorb") {
    // Trashed messages are read-only — no reply draft.
    expandedAiLoading.value = false;
  } else {
    // offen / nachfassen without a pre-cached draft. Still prepare an EMPTY
    // draft immediately so the compose pane is visible and editable from
    // the first frame — the AI fetch will overlay the draft_body when it
    // returns. Without this, the user had to wait for the AI call before
    // they could type a reply, which is a hard no-go for fast handling.
    expandedAiDraft.value = {
      body: "",
      subject: "Re: " + (item.subject || ""),
      to: item.contact_email || item.from_email || "",
    };
  }

  // Load send accounts
  loadSendAccounts(item.broker_id, item?.account_id || null);

  // Load property files
  expandedFilesLoading.value = true;
  if (item.property_id) {
    fetch(API.value + "&action=get_property_files&property_id=" + item.property_id)
      .then(r => r.json())
      .then(d => { expandedFiles.value = (d.files || []).map(f => f.source === "global_files" ? { ...f, _matchProperty: "Allgemeine Dokumente" } : f); })
      .catch(() => {})
      .finally(() => { expandedFilesLoading.value = false; });
  } else { 
    // No property - still load global files
    fetch(API.value + "&action=list_global_files")
      .then(r => r.json())
      .then(d => { expandedFiles.value = (d.files || []).map(f => ({ ...f, id: "global_" + f.id, _matchProperty: "Allgemeine Dokumente" })); })
      .catch(() => {})
      .finally(() => { expandedFilesLoading.value = false; });
  }

  // Load conversation detail — for offen/nachfassen use conv_detail, for posteingang/gesendet use email_context
  const isEmailHistory = mode === "posteingang" || mode === "gesendet";
  const isTrash = mode === "papierkorb";
  let contextPromise;
  // Helper: merge HV-Info aus conv_detail in selectedItem damit der
  // ForwardToManagerButton weiss ob eine HV zugewiesen ist.
  const mergeHvInfoIntoItem = (conv) => {
    if (!conv || !selectedItem.value) return;
    selectedItem.value.property_manager_id = conv.property_manager_id || null;
    selectedItem.value.property_manager_name = conv.property_manager_name || null;
    selectedItem.value.property_manager_email = conv.property_manager_email || null;
    // property_id falls vorher fehlte (z.B. aus Posteingang-Mails)
    if (conv.property_id && !selectedItem.value.property_id) {
      selectedItem.value.property_id = conv.property_id;
    }
  };

  if (isTrash) {
    // Papierkorb: conv_detail filtert is_deleted=0, deshalb direkt die
    // einzelne Mail via email_context laden und als 1-Eintrag-Thread anzeigen.
    contextPromise = fetch(API.value + "&action=email_context&email_id=" + item.id)
      .then(r => r.json())
      .then(d => {
        const mail = d.email || null;
        expandedDetail.value = {
          email: mail,
          thread: mail ? [mail] : [],
        };
      })
      .catch(e => { toast("Fehler: " + e.message); })
      .finally(() => { expandedLoading.value = false; });
  } else if (isEmailHistory) {
    // Load full conversation thread by finding matching conversation
    contextPromise = fetch(API.value + "&action=email_context&email_id=" + item.id)
      .then(r => r.json())
      .then(async d => {
        // Use conversation_id from backend (works with AND without property_id)
        const convId = d.conversation_id;
        if (convId && selectedItem.value) {
          selectedItem.value._conv_id = convId;
        }
        if (convId) {
          try {
            const detailR = await fetch(API.value + "&action=conv_detail&id=" + convId);
            const detailD = await detailR.json();
            if (detailD.messages?.length) {
              expandedDetail.value = { email: detailD.conversation || d.email, thread: detailD.messages || [] };
              mergeHvInfoIntoItem(detailD.conversation);
              return;
            }
          } catch (e) { /* fallback to single email */ }
        }
        // Fallback: try conv_list search by stakeholder + property_id
        if (d.email?.property_id && (d.email?.from_email || d.email?.to_email || d.email?.stakeholder)) {
          try {
            const stakeholder = d.email.stakeholder || d.email.from_name || "";
            const convListR = await fetch(API.value + "&action=conv_list&search=" + encodeURIComponent(stakeholder) + "&property_id=" + d.email.property_id + "&per_page=1");
            const convListD = await convListR.json();
            const conv = (convListD.conversations || [])[0];
            if (conv) {
              const detailR = await fetch(API.value + "&action=conv_detail&id=" + conv.id);
              const detailD = await detailR.json();
              if (detailD.messages?.length) {
                expandedDetail.value = { email: detailD.conversation || d.email, thread: detailD.messages || [] };
                mergeHvInfoIntoItem(detailD.conversation);
                return;
              }
            }
          } catch (e) { /* fallback to single email */ }
        }
        expandedDetail.value = { email: d.email || null, thread: d.thread || [] };
      })
      .catch(e => { toast("Fehler: " + e.message); })
      .finally(() => { expandedLoading.value = false; });
  } else {
    contextPromise = fetch(API.value + "&action=conv_detail&id=" + item.id)
      .then(r => r.json())
      .then(async (d) => {
        expandedDetail.value = { email: d.conversation || null, thread: d.messages || [] };
        mergeHvInfoIntoItem(d.conversation);
      })
      .catch(e => { toast("Fehler: " + e.message); })
      .finally(() => { expandedLoading.value = false; });
  }

  // Generate draft if not available from conv_list. Skip for threads that
  // should not receive an automated reply: info-cc (we were only CC'd, mail
  // wasn't addressed to us) and intern (colleague comms, no customer reply).
  const promises = [contextPromise];
  const noAutoDraftCats = ['info-cc', 'intern'];
  const convCat = (item.category || '').toLowerCase();
  if ((mode === 'offen' || mode === 'nachfassen') && !item.draft_body && !item.draft_dismissed && !noAutoDraftCats.includes(convCat)) {
    // Immer die Conversation-ID nutzen, nicht die Email-ID — bei
    // Posteingang/Gesendet-Items ist item.id die email_id, nicht die
    // conversation_id. openDetail() reichert _conv_id ueber die Fallback-
    // Logik an (conv_list Lookup by stakeholder+property).
    const convIdForAi = item._conv_id || item.id;
    const aiPromise = fetch(API.value + "&action=conv_regenerate_draft&id=" + convIdForAi, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
    })
      .then(r => r.json())
      .then(async (d) => {
        // Respect user input — if the user already started typing their own
        // reply while the AI was still running, do NOT clobber it. Only fill
        // in the AI body when the current draft body is still empty.
        const current = expandedAiDraft.value || {};
        const userHasTyped = typeof current.body === 'string' && current.body.trim().length > 0;
        if (d.draft_body && !userHasTyped) {
          const draftWithLink = await ensureDraftContainsPropertyLink(
            stripAiSignoffAndKnownSignatures(d.draft_body),
            item
          );
          expandedAiDraft.value = {
            body: draftWithLink,
            subject: d.draft_subject || current.subject || item.subject || '',
            to: d.draft_to || current.to || item.contact_email || '',
          };
        } else if (!d.draft_body && !userHasTyped) {
          // Keep the empty initial draft in place so the compose pane
          // stays visible and editable.
          expandedAiDraft.value = current.body !== undefined
            ? current
            : { body: '', subject: 'Re: ' + (item.subject || ''), to: item.contact_email || '' };
        }
        // else: user has typed something — keep their draft untouched.
      })
      .catch(() => {
        // Keep whatever the compose pane already holds — either an empty
        // scaffold from openDetail() or the user's own typing.
        if (!expandedAiDraft.value) {
          expandedAiDraft.value = { body: '', subject: 'Re: ' + (item.subject || ''), to: item.contact_email || '' };
        }
      })
      .finally(() => { expandedAiLoading.value = false; });
    promises.push(aiPromise);
  } else {
    // No AI request will fire — flip loading off so the compose pane
    // stops showing the "generating" state.
    expandedAiLoading.value = false;
  }

  Promise.all(promises);
}

async function reloadExpandedFiles(item) {
  if (!item) return;
  expandedFilesLoading.value = true;
  try {
    if (item.property_id) {
      const r = await fetch(API.value + "&action=get_property_files&property_id=" + item.property_id);
      const d = await r.json();
      expandedFiles.value = (d.files || []).map((f) => (f.source === "global_files" ? { ...f, _matchProperty: "Allgemeine Dokumente" } : f));
    } else {
      const r = await fetch(API.value + "&action=list_global_files");
      const d = await r.json();
      expandedFiles.value = (d.files || []).map((f) => ({ ...f, id: "global_" + f.id, _matchProperty: "Allgemeine Dokumente" }));
    }
  } catch {
    /* keep existing file list */
  } finally {
    expandedFilesLoading.value = false;
  }
}

/** Reload thread + attachments only (does not reset draft or re-run KI). */
async function reloadOpenDetailThread() {
  if (!detailOpen.value || !selectedItem.value || composing.value) return;
  const item = selectedItem.value;
  const mode = sheetMode.value;
  expandedLoading.value = true;
  try {
    const isEmailHistory = mode === "posteingang" || mode === "gesendet";
    if (isEmailHistory) {
      const r = await fetch(API.value + "&action=email_context&email_id=" + item.id);
      const d = await r.json();
      const convId = d.conversation_id;
      if (convId && selectedItem.value) {
        selectedItem.value._conv_id = convId;
      }
      let resolved = false;
      if (convId) {
        try {
          const detailR = await fetch(API.value + "&action=conv_detail&id=" + convId);
          const detailD = await detailR.json();
          if (detailD.messages?.length) {
            expandedDetail.value = { email: detailD.conversation || d.email, thread: detailD.messages || [] };
            if (selectedItem.value && detailD.conversation) {
              selectedItem.value.property_manager_id = detailD.conversation.property_manager_id || null;
              selectedItem.value.property_manager_name = detailD.conversation.property_manager_name || null;
              selectedItem.value.property_manager_email = detailD.conversation.property_manager_email || null;
              if (detailD.conversation.property_id && !selectedItem.value.property_id) selectedItem.value.property_id = detailD.conversation.property_id;
            }
            resolved = true;
          }
        } catch {
          /* fall through */
        }
      }
      if (!resolved && d.email?.property_id && (d.email?.from_email || d.email?.to_email || d.email?.stakeholder)) {
        try {
          const stakeholder = d.email.stakeholder || d.email.from_name || "";
          const convListR = await fetch(
            API.value + "&action=conv_list&search=" + encodeURIComponent(stakeholder) + "&property_id=" + d.email.property_id + "&per_page=1"
          );
          const convListD = await convListR.json();
          const conv = (convListD.conversations || [])[0];
          if (conv) {
            const detailR = await fetch(API.value + "&action=conv_detail&id=" + conv.id);
            const detailD = await detailR.json();
            if (detailD.messages?.length) {
              expandedDetail.value = { email: detailD.conversation || d.email, thread: detailD.messages || [] };
              if (selectedItem.value && detailD.conversation) {
                selectedItem.value.property_manager_id = detailD.conversation.property_manager_id || null;
                selectedItem.value.property_manager_name = detailD.conversation.property_manager_name || null;
                selectedItem.value.property_manager_email = detailD.conversation.property_manager_email || null;
                if (detailD.conversation.property_id && !selectedItem.value.property_id) selectedItem.value.property_id = detailD.conversation.property_id;
              }
              resolved = true;
            }
          }
        } catch {
          /* fall through */
        }
      }
      if (!resolved) {
        expandedDetail.value = { email: d.email || null, thread: d.thread || [] };
      }
    } else {
      const r = await fetch(API.value + "&action=conv_detail&id=" + item.id);
      const d = await r.json();
      expandedDetail.value = { email: d.conversation || null, thread: d.messages || [] };
      if (selectedItem.value && d.conversation) {
        selectedItem.value.property_manager_id = d.conversation.property_manager_id || null;
        selectedItem.value.property_manager_name = d.conversation.property_manager_name || null;
        selectedItem.value.property_manager_email = d.conversation.property_manager_email || null;
      }
    }
    await reloadExpandedFiles(item);
  } catch (e) {
    toast("Aktualisieren fehlgeschlagen: " + (e.message || "Unbekannt"));
  } finally {
    expandedLoading.value = false;
  }
}

async function refreshInbox() {
  if (inboxRefreshing.value) return;
  inboxRefreshing.value = true;
  try {
    refreshCounts();
    const tab = activeSubtab.value;
    if (tab === "offen") {
      await Promise.all([loadUnanswered(unansweredFilter.value), loadAutoReplyLogs()]);
    } else if (tab === "nachfassen") {
      await loadFollowups(followupFilter.value);
    } else if (tab === "posteingang" || tab === "gesendet") {
      await loadEmailHistory();
    } else if (tab === "entwuerfe") {
      await loadDrafts();
    } else if (tab === "templates") {
      await loadTemplates();
    } else if (tab === "papierkorb") {
      await loadTrash();
    } else if (tab === "matches") {
      await loadMatchesTab();
    } else if (tab === "compose") {
      await loadSignature();
    }
    if (detailOpen.value && selectedItem.value && !composing.value) {
      await reloadOpenDetailThread();
    }
  } finally {
    inboxRefreshing.value = false;
  }
}

function setAiDetailLevel(level) {
  aiDetailLevel.value = level;
  localStorage.setItem("sr-ai-detail-level", level);
}

function stripAiSignoffAndKnownSignatures(aiBody) {
  const storedSig = buildSignature().trim();
  const injectedSig = String(injectedInboxSignature || '').trim();
  let body = String(aiBody || '');

  // Remove any AI sign-off and everything below it.
  body = body.replace(
    /\n\s*(Mit\s+freundlichen\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)en|Beste\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Liebe\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Viele\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Mit\s+besten\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e|Freundliche\s+Gr(?:ü|\?|ue)(?:ß|\?|ss)e)[\s\S]*$/i,
    ''
  );

  // Remove previously appended signatures to avoid duplicates.
  const escapeRegExp = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  if (storedSig) body = body.replace(new RegExp(`\\n\\n?${escapeRegExp(storedSig)}\\s*$`, 'i'), '');
  if (injectedSig && injectedSig !== storedSig) {
    body = body.replace(new RegExp(`\\n\\n?${escapeRegExp(injectedSig)}\\s*$`, 'i'), '');
  }

  return body.trimEnd();
}

async function fetchPreferredPropertyLink(propertyId) {
  const pid = Number(propertyId);
  if (!Number.isFinite(pid) || pid <= 0) return '';
  try {
    const r = await fetch(`/admin/properties/${pid}/links/active`, {
      headers: { Accept: 'application/json' },
    });
    if (!r.ok) return '';
    const d = await r.json();
    const links = Array.isArray(d?.links) ? d.links : [];
    const first = links.find((l) => (l?.url || l?.public_url || l?.link_url || '').trim());
    return String(first?.url || first?.public_url || first?.link_url || '').trim();
  } catch {
    return '';
  }
}

async function ensureDraftContainsPropertyLink(body, item) {
  const cleanBody = String(body || '').trimEnd();
  const existingLinkMatch = cleanBody.match(/https?:\/\/[^\s)>"']+/i);
  if (existingLinkMatch) return cleanBody;
  const linkUrl = await fetchPreferredPropertyLink(item?.property_id);
  if (!linkUrl) return cleanBody;
  const sep = cleanBody ? '\n\n' : '';
  return `${cleanBody}${sep}Unterlagen: ${linkUrl}`;
}

// Always use the stored signature from settings and keep it single.
function withUserSignature(aiBody) {
  const storedSig = buildSignature().trim();
  const body = stripAiSignoffAndKnownSignatures(aiBody);
  if (!storedSig) return body;
  return body ? `${body}\n\n${storedSig}` : storedSig;
}

async function regenerateAiDraft() {
  const item = selectedItem.value;
  if (!item) return;
  // Keep existing draft visible during loading (don't null it)
  expandedAiLoading.value = true;
  // Conversation-ID bevorzugen (wird bei Posteingang/Gesendet-Items von
  // openDetail via conv_list-Lookup ins _conv_id geschrieben) — item.id ist
  // dort die Email-ID und fuehrt zu 'Conversation not found'.
  const convIdForAi = item._conv_id || item.id;
  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 60000);
    const r = await fetch(API.value + "&action=conv_regenerate_draft&id=" + convIdForAi, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
      signal: controller.signal,
    });
    clearTimeout(timeout);
    const d = await r.json();
    if (d.draft_body) {
      const draftWithLink = await ensureDraftContainsPropertyLink(
        stripAiSignoffAndKnownSignatures(d.draft_body),
        item
      );
      expandedAiDraft.value = {
        body: draftWithLink,
        subject: d.draft_subject || item.subject || '',
        to: d.draft_to || item.contact_email || '',
        cc: expandedAiDraft.value?.cc || '',
      };
    } else {
      toast("KI-Entwurf konnte nicht generiert werden: " + (d.error || "Unbekannter Fehler"));
      // Keep draft area visible with empty body so user can retry
      if (!expandedAiDraft.value) {
        expandedAiDraft.value = { body: '', subject: item.subject || '', to: item.contact_email || '' };
      }
    }
  } catch (e) {
    toast("KI-Fehler: " + e.message);
    if (!expandedAiDraft.value) {
      expandedAiDraft.value = { body: '', subject: item.subject || '', to: item.contact_email || '' };
    }
  }
  expandedAiLoading.value = false;
}

async function improveWithAi() {
  if (!expandedAiDraft.value?.body?.trim()) {
    toast("Bitte zuerst einen Text eingeben.");
    return;
  }
  expandedAiLoading.value = true;
  const item = selectedItem.value;
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

async function improveComposeWording() {
  if (!composeBody.value?.trim()) { toast("Bitte zuerst einen Text eingeben."); return; }
  aiLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=improve_text", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ text: composeBody.value }),
    });
    const d = await r.json();
    if (d.improved_text) {
      composeBody.value = d.improved_text;
      toast("Wording verbessert!");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) { toast("KI-Fehler: " + e.message); }
  aiLoading.value = false;
}

function toggleFileSelection(fileId) {
  const idx = expandedSelectedFiles.value.indexOf(fileId);
  if (idx >= 0) expandedSelectedFiles.value.splice(idx, 1);
  else expandedSelectedFiles.value.push(fileId);
}

async function batchMarkDone(ids) {
  try {
    const r = await fetch(API.value + "&action=conv_done_batch", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ids }),
    });
    const d = await r.json();
    if (d.success) {
      toast(d.done + " als erledigt markiert!");
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
      refreshCounts();
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
}

async function onSaveAttachment(data) {
  saveAttachData.value = data;
  saveAttachPropertyId.value = data.propertyId ? String(data.propertyId) : (selectedItem.value?.property_id ? String(selectedItem.value.property_id) : null);
  saveAttachLabel.value = data.filename?.replace(/\.[^.]+$/, "") || "";
  showSaveAttachDialog.value = true;
}

async function confirmSaveAttachment() {
  if (!saveAttachData.value || !saveAttachPropertyId.value) { toast("Bitte Objekt auswählen"); return; }
  saveAttachSaving.value = true;
  try {
    const r = await fetch(API.value + "&action=save_attachment_to_property", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        email_id: saveAttachData.value.emailId,
        file_index: saveAttachData.value.fileIndex,
        property_id: parseInt(saveAttachPropertyId.value),
        label: saveAttachLabel.value,
      }),
    });
    const d = await r.json();
    if (d.success) {
      toast("Anhang zum Objekt gespeichert!");
      showSaveAttachDialog.value = false;
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) { toast("Fehler: " + e.message); }
  saveAttachSaving.value = false;
}

async function batchTrash(ids) {
  if (!ids.length) return;
  try {
    const r = await fetch(API.value + "&action=trash_emails", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ids }),
    });
    const d = await r.json();
    if (d.success !== false) {
      toast(ids.length + " in Papierkorb verschoben");
      loadEmailHistory();
    }
  } catch (e) { toast("Fehler: " + e.message); }
}

async function markConvDone(convId) {
  if (!convId) return;

  // Optimistic UI: sofort aus der Liste entfernen. Fruehere Version wartete
  // auf die Server-Response bevor irgendwas passierte — bei Latenz sah der
  // User "nichts" und klickte unter Umstaenden erneut.
  const idStr = String(convId);
  const snapshotUn = unansweredList.value.slice();
  const snapshotS1 = stage1Followups.value.slice();
  const snapshotS2 = (followupData.value?.followups || []).slice();

  const matches = (i) => {
    if (!i) return false;
    const convMatch = i._conv_id != null && String(i._conv_id) === idStr;
    const idMatch = i.id != null && String(i.id) === idStr;
    return convMatch || idMatch;
  };

  unansweredList.value = unansweredList.value.filter(i => !matches(i));
  stage1Followups.value = stage1Followups.value.filter(i => !matches(i));
  if (followupData.value && Array.isArray(followupData.value.followups)) {
    followupData.value.followups = followupData.value.followups.filter(i => !matches(i));
  }

  try {
    const r = await fetch(API.value + "&action=conv_done&id=" + convId, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    const d = await r.json();
    if (d.success) {
      toast("Erledigt!");
      refreshCounts();
      // Hintergrund-Refresh — falls serverseitig Side-Effects passiert sind,
      // aber die Liste bleibt optisch unveraendert bis die Antwort da ist.
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
    } else {
      // Rollback: Server hat abgelehnt
      unansweredList.value = snapshotUn;
      stage1Followups.value = snapshotS1;
      if (followupData.value) followupData.value.followups = snapshotS2;
      toast("Fehler: " + (d.error || "Konnte nicht als erledigt markiert werden"));
    }
  } catch (e) {
    // Rollback bei Netzwerk-/Parse-Fehler
    unansweredList.value = snapshotUn;
    stage1Followups.value = snapshotS1;
    if (followupData.value) followupData.value.followups = snapshotS2;
    toast("Fehler: " + e.message);
  }
}

async function markHandled(stakeholder, propertyId) {
  const item = selectedItem.value;
  if (!item) return;
  const idForApi = item._conv_id || item.id;
  const idStr = String(idForApi);

  // Optimistic UI — sofort Detail schliessen + aus Listen entfernen
  const snapshotUn = unansweredList.value.slice();
  const snapshotS1 = stage1Followups.value.slice();
  const snapshotS2 = (followupData.value?.followups || []).slice();

  const matches = (i) => {
    if (!i) return false;
    const convMatch = i._conv_id != null && String(i._conv_id) === idStr;
    const idMatch = i.id != null && String(i.id) === idStr;
    return convMatch || idMatch;
  };
  unansweredList.value = unansweredList.value.filter(i => !matches(i));
  stage1Followups.value = stage1Followups.value.filter(i => !matches(i));
  if (followupData.value && Array.isArray(followupData.value.followups)) {
    followupData.value.followups = followupData.value.followups.filter(i => !matches(i));
  }
  detailOpen.value = false;
  selectedItem.value = null;

  try {
    const r = await fetch(API.value + "&action=conv_done&id=" + idForApi, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    const d = await r.json();
    if (d.success) {
      toast("Als erledigt markiert!");
      refreshCounts();
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
    } else {
      unansweredList.value = snapshotUn;
      stage1Followups.value = snapshotS1;
      if (followupData.value) followupData.value.followups = snapshotS2;
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    unansweredList.value = snapshotUn;
    stage1Followups.value = snapshotS1;
    if (followupData.value) followupData.value.followups = snapshotS2;
    toast("Fehler: " + e.message);
  }
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
    const action = isFollowup ? "conv_followup" : "conv_reply";
    const convId = item._conv_id || itemId;
    // Keep reply body signature-free here so EmailService can append the
    // configured HTML signature (photo/logo/banner) consistently.
    const normalizedBody = stripAiSignoffAndKnownSignatures(draft.body || '');
    const r = await fetch(API.value + "&action=" + action + "&id=" + convId, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        body: normalizedBody,
        subject: draft.subject || '',
        to: draft.to || item.from_email || item.contact_email || '',
        cc: draft.cc || '',
        account_id: sendAccountId.value || 1,
        file_ids: expandedSelectedFiles.value || [],
      }),
    });
    const result = await r.json();

    if (sendingEl) sendingEl.remove();

    if (result.success) {
      toast("Email an " + itemName + " gesendet!");
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
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

// Shared compose state for InboxComposePane. Keeps sendDraft() and
// friends working unchanged — the new pane just reaches in via inject.
provide('inboxCompose', {
    draft: expandedAiDraft,
    sendAccountId,
    selectedFiles: expandedSelectedFiles,
    sendAccounts,
    loading: expandedAiLoading,
    regenerate: regenerateAiDraft,
    improve: improveWithAi,
    send: sendDraft,
    toggleFile: toggleFileSelection,
});


// ============================================================
// API FUNCTIONS — COMMS (from CommsTab.vue)
// ============================================================
async function loadEmailAccountsSelect() {
  try {
    const r = await fetch(API.value + "&action=get_email_accounts_select");
    const d = await r.json();
    emailAccountsSelect.value = d.accounts || [];
    if (!selectedAccountId.value && emailAccountsSelect.value.length) selectedAccountId.value = emailAccountsSelect.value[0].id;
    if (selectedAccountId.value) loadSignature(selectedAccountId.value);
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
  // Body kann jetzt HTML sein (RichTextEditor) oder plain-text (Legacy/AI).
  // Wir normalisieren beide Faelle.
  const rawBody = composeBody.value || "";
  const isHtml = /<(p|br|div|ul|ol|li|strong|em|u|h[1-6]|blockquote|a)[\s>]/i.test(rawBody);
  const plainCheck = isHtml ? rawBody.replace(/<[^>]+>/g, '').trim() : rawBody.trim();

  if (!composeTo.value || !composeSubject.value || !plainCheck) { toast("Bitte alle Felder ausfuellen."); return; }
  if (!selectedAccountId.value) { toast("Bitte Absender-Konto waehlen."); return; }
  emailSending.value = true;
  try {
    const sigHtml = buildSignatureHtml();
    let htmlBody;
    let plainText;
    if (isHtml) {
      // RichTextEditor-Output: bereits HTML, keine \n→<br>-Konvertierung noetig.
      htmlBody = rawBody + sigHtml;
      // Plain-Variante: Tags strippen, </p> und <br> als Newlines mappen.
      plainText = rawBody
        .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/li>\s*<li[^>]*>/gi, '\n• ')
        .replace(/<li[^>]*>/gi, '• ')
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .trim();
    } else {
      // Legacy plain-text-Pfad (z.B. Templates ohne Editor-Roundtrip).
      const normalizedBody = withUserSignature(rawBody);
      htmlBody = normalizedBody.replace(/\n/g, "<br>") + sigHtml;
      plainText = normalizedBody;
    }

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
    fd.append("body_text", plainText);
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
    const accountFilter = ehAccountId.value || (isAssistenz.value ? effectiveBrokerFilter.value : "");
    if (accountFilter) url += "&account_id=" + accountFilter;
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
  composeBody.value = '';  // signature shown as visual preview, appended on send
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
  localStorage.setItem(BROKER_FILTER_STORAGE_KEY, String(maklerFilter.value || ''));
  loadUnanswered(unansweredFilter.value);
  loadFollowups(followupFilter.value);
  if (['posteingang', 'gesendet'].includes(activeSubtab.value)) {
    ehPage.value = 1;
    loadEmailHistory();
  }
});

watch(brokerList, (list) => {
  if (!Array.isArray(list) || !list.length) return;
  const current = String(maklerFilter.value || '');
  const valid = list.some((b) => String(b.id) === current);
  if (!valid) {
    const fallback = resolveDefaultBrokerId(list);
    if (fallback) maklerFilter.value = fallback;
  }
}, { deep: true });

watch(activeSubtab, (v) => {
  if (v === 'posteingang') { ehDirection.value = 'inbound'; ehShowUnmatched.value = false; ehPage.value = 1; loadEmailHistory(); }
  if (v === 'gesendet') { ehDirection.value = 'outbound'; ehPage.value = 1; loadEmailHistory(); }
  if (v === 'nachfassen') { loadFollowups(followupFilter.value); }
  if (v === 'papierkorb') loadTrash();
  if (v === 'offen') { loadUnanswered(unansweredFilter.value); }
  if (v === 'entwuerfe') loadDrafts();
  if (v === 'templates' && !templates.value?.length) loadTemplates();
  if (v === 'matches') loadMatchesTab();
});

watch(selectedAccountId, (id) => {
  if (id) loadSignature(id);
});

watch(sendAccountId, (id) => {
  if (!id) return;
  // Reply pane ("Von") uses sendAccountId; keep compose/signature in sync.
  selectedAccountId.value = id;
  loadSignature(id);
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


    

    <!-- Bulk sending progress -->
    <div v-if="bulkSending" class="absolute inset-x-0 top-0 z-50 flex items-center justify-center px-4 py-3 bg-gradient-to-r from-orange-500 to-orange-600 text-white shadow-lg">
      <div class="flex items-center gap-3 w-full max-w-md">
        <div class="flex-1">
          <div class="text-[12px] font-medium">{{ bulkSendLabel }}</div>
          <div class="mt-1.5 h-1.5 bg-white/30 rounded-full overflow-hidden">
            <div class="h-full bg-white rounded-full transition-all duration-500" :style="{ width: bulkSendTotal ? ((bulkSendDone / bulkSendTotal) * 100) + '%' : '100%' }" :class="bulkSendDone >= bulkSendTotal ? '' : 'animate-pulse'"></div>
          </div>
          <div class="text-[10px] text-white/80 mt-0.5">{{ bulkSendDone }} / {{ bulkSendTotal }}</div>
        </div>
        <button
          v-if="bulkSendDone < bulkSendTotal && !bulkSendCancelRequested"
          type="button"
          @click="bulkSendCancelRequested = true"
          class="shrink-0 h-8 px-3 rounded-md bg-white/20 hover:bg-white/30 text-white text-[11px] font-semibold transition-colors"
          title="Versand nach dem aktuellen Chunk stoppen"
        >
          Abbrechen
        </button>
        <span v-else-if="bulkSendCancelRequested && bulkSendDone < bulkSendTotal" class="shrink-0 text-[11px] text-white/80 italic">
          Stoppt nach Chunk…
        </span>
      </div>
    </div>

    <!-- Content (z-10 above background) -->
    <div class="relative z-10 flex flex-1 min-h-0 overflow-hidden md:divide-x md:divide-zinc-300">
      <!-- Left: Conversation List -->
      <div class="w-full md:w-[400px] flex-shrink-0 flex flex-col h-full overflow-hidden md:bg-zinc-50/40" :class="{ 'hidden md:flex': detailOpen || composing }">

        <!-- Panel Header with Pill Tabs -->
        <div class="px-4 pt-3 pb-2 flex-shrink-0">

          <!-- Primary Pills: Anfragen / Nachfassen / Alle -->
          <div class="mb-2 grid grid-cols-4 gap-1 p-[3px] bg-[#f4f4f5] rounded-lg">
            <button
              @click="activeSubtab = 'offen'"
              class="flex min-w-0 items-center justify-center gap-1 overflow-hidden px-1.5 py-[5px] text-[11px] leading-none rounded-md transition-all sm:gap-1.5 sm:px-2 sm:text-[12px]"
              :class="activeSubtab === 'offen'
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >Anfragen
              <span v-if="unansweredCount" class="text-[9px] font-bold px-1.5 py-0 rounded-md bg-red-50 text-red-600">{{ unansweredCount }}</span>
            </button>
            <button
              @click="activeSubtab = 'nachfassen'"
              class="flex min-w-0 items-center justify-center gap-1 overflow-hidden px-1.5 py-[5px] text-[11px] leading-none rounded-md transition-all sm:gap-1.5 sm:px-2 sm:text-[12px]"
              :class="activeSubtab === 'nachfassen'
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >
              Nachfassen
              <span v-if="followupCount" class="text-[9px] font-bold px-1.5 py-0 rounded-md bg-zinc-200 text-zinc-600">{{ followupCount }}</span>
            </button>
            <button
              @click="activeSubtab = 'posteingang'"
              class="flex min-w-0 items-center justify-center gap-1 overflow-hidden px-1.5 py-[5px] text-[11px] leading-none rounded-md transition-all sm:gap-1.5 sm:px-2 sm:text-[12px]"
              :class="['posteingang','gesendet','entwuerfe','templates','papierkorb'].includes(activeSubtab)
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >
              Alle
            </button>
            <button
              @click="activeSubtab = 'matches'; loadMatchesTab()"
              class="flex min-w-0 items-center justify-center gap-1 overflow-hidden px-1.5 py-[5px] text-[11px] leading-none rounded-md transition-all sm:gap-1.5 sm:px-2 sm:text-[12px]"
              :class="activeSubtab === 'matches'
                ? 'bg-white text-foreground font-semibold shadow-sm'
                : 'text-muted-foreground hover:text-foreground'"
            >
              Matches
              <span
                v-if="matchItems.length"
                class="text-[9px] font-bold px-1.5 py-0 rounded-md bg-violet-100 text-violet-700"
              >{{ matchItems.length }}</span>
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

        <div v-if="isAssistenz && brokerList.length" class="px-3 pb-1 flex-shrink-0">
          <Select
            :model-value="String(maklerFilter || '')"
            @update:model-value="maklerFilter = String($event || '')"
          >
            <SelectTrigger class="h-6 w-[130px] text-[11px] text-foreground font-semibold border-zinc-200">
              <SelectValue placeholder="Makler wählen" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="b in brokerList"
                :key="b.id"
                :value="String(b.id)"
                class="text-[11px]"
              >
                {{ brokerDisplayName(b) }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <!-- Anfragen -->
        <InboxConversationList
          v-if="activeSubtab === 'offen'"
          :items="filteredUnanswered"
          :loading="unansweredLoading"
          subtab="offen"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="objectFilter"
          :properties="availableProperties"
          :toolbar-refreshing="inboxRefreshing"
          empty-message="Keine offenen Konversationen"
          @select="(item) => openDetail(item, 'offen')"
          @update:search-query="searchQuery = $event"
          @update:object-filter="objectFilter = $event"
          @toolbar-refresh="refreshInbox"
          @compose="startCompose()" @delete="markConvDone($event.id)"
        >
          <template #toolbar-inline>
            <div class="flex min-w-0 max-w-[min(100%,16rem)] sm:max-w-[min(100%,20rem)] items-center gap-1.5">
              <button v-if="autoReplyLogs.length" type="button" class="truncate text-left text-[10px] text-emerald-600 hover:text-emerald-800 hover:underline transition-colors" @click="autoReplyBannerOpen = !autoReplyBannerOpen">✓ {{ autoReplyLogs.length }} Auto-Replies (24h)</button>
              <span v-else-if="autoReplyEnabled && autoReplyPropertyIds.length" class="line-clamp-2 text-[10px] text-emerald-600 leading-tight">Auto-Reply aktiv ({{ autoReplyPropertyIds.length }} {{ autoReplyPropertyIds.length === 1 ? 'Objekt' : 'Objekte' }})</span>
            </div>
          </template>
          <template #toolbar-icons-end>
            <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0" @click="showAutoReplySettings = !showAutoReplySettings; if (showAutoReplySettings && !autoReplyText) loadAutoReplySettings()" title="Auto-Reply Einstellungen">
              <Settings2 class="w-3.5 h-3.5 text-muted-foreground" />
            </Button>
          </template>
          <template #under-toolbar>
            <div v-if="autoReplyBannerOpen && autoReplyLogs.length" class="border-b border-zinc-100 bg-zinc-50/40 px-3 pb-2 pt-1">
              <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 overflow-hidden">
                <div class="px-3 py-2 border-b border-emerald-100 flex items-center justify-between">
                  <span class="text-[11px] font-semibold text-emerald-800">Auto-Replies der letzten 24h</span>
                  <button type="button" class="text-[10px] text-emerald-600 hover:text-emerald-800" @click="autoReplyBannerOpen = false">Schließen</button>
                </div>
                <div class="max-h-[200px] overflow-y-auto divide-y divide-emerald-100">
                  <div v-for="log in autoReplyLogs" :key="log.id" class="px-3 py-2 hover:bg-emerald-50 cursor-pointer" @click="() => { const conv = [...(unansweredList || []), ...(allFollowups || [])].find(c => c.stakeholder === log.stakeholder && c.property_id === log.property_id); if (conv) openDetail(conv, 'offen'); else { ehSearch = log.to_email || log.stakeholder; ehPage = 1; activeSubtab = 'gesendet'; loadEmailHistory(); } }">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-[12px] font-medium text-zinc-800 truncate">{{ log.stakeholder || log.to_email }}</span>
                      <span class="text-[10px] text-muted-foreground flex-shrink-0">{{ log.created_at?.substring(11, 16) }}</span>
                    </div>
                    <div class="text-[11px] text-muted-foreground truncate mt-0.5">{{ log.subject }}</div>
                  </div>
                </div>
              </div>
            </div>
            <div v-if="showAutoReplySettings" class="mx-3 mb-2 rounded-lg border border-zinc-100 bg-white/80 backdrop-blur-sm p-3 space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-[12px] font-semibold">Auto-Reply</div>
                <button
                  type="button"
                  @click="autoReplyEnabled = !autoReplyEnabled"
                  class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                  :class="autoReplyEnabled ? 'bg-emerald-500' : 'bg-zinc-300'"
                >
                  <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform" :class="autoReplyEnabled ? 'translate-x-4.5' : 'translate-x-0.5'" :style="{ transform: autoReplyEnabled ? 'translateX(18px)' : 'translateX(2px)' }" />
                </button>
              </div>
              <div v-if="!autoReplyEnabled" class="text-[10px] text-red-600 bg-red-50 rounded px-2 py-1.5">Auto-Reply ist deaktiviert. Keine automatischen Antworten werden gesendet.</div>
              <template v-if="autoReplyEnabled">
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
              </template>
              <div class="flex justify-end">
                <Button size="sm" class="h-7 text-[11px]" :disabled="autoReplySaving" @click="saveAutoReplySettings">Speichern</Button>
              </div>
            </div>
          </template>
        </InboxConversationList>

        <!-- Nachfassen Settings + Alle Button -->
        <div v-if="activeSubtab === 'nachfassen'" class="px-4 pb-2 space-y-2">
          <div class="flex items-center gap-2">
            <Button
              v-if="filteredFollowups.filter(f => f.draft_body).length > 0"
              variant="outline"
              size="sm"
              class="flex-1 h-8 text-[11px] gap-1.5 border-orange-200 text-orange-700 hover:bg-orange-50"
              @click="nachfassenAlle"
            >
              <Send class="w-3 h-3" />
              Alle nachfassen ({{ filteredFollowups.length }})
            </Button>
            <Button variant="ghost" size="sm" class="h-8 w-8 p-0 flex-shrink-0" @click="showAutoFollowupSettings = !showAutoFollowupSettings; if (showAutoFollowupSettings) loadAutoFollowupSettings()" title="Auto-Nachfassen Einstellungen">
              <Settings2 class="w-3.5 h-3.5 text-muted-foreground" />
            </Button>
          </div>
          <!-- NF Stage Picker Popup -->
          <div v-if="showNfStagePicker" class="rounded-lg border border-orange-200 bg-white p-3 space-y-2">
            <div class="text-[12px] font-semibold">Welche Stufen senden?</div>
            <label class="flex items-center justify-between gap-2 text-[11px] cursor-pointer hover:bg-zinc-50 px-2 py-1.5 rounded">
              <div class="flex items-center gap-2">
                <input type="checkbox" v-model="nfStageSelection.nf1" class="rounded border-zinc-300" />
                <span>NF1 — Erstmalig</span>
              </div>
              <span class="text-[10px] text-muted-foreground">{{ nfStageCount(1) }} Konversationen</span>
            </label>
            <label class="flex items-center justify-between gap-2 text-[11px] cursor-pointer hover:bg-zinc-50 px-2 py-1.5 rounded">
              <div class="flex items-center gap-2">
                <input type="checkbox" v-model="nfStageSelection.nf2" class="rounded border-zinc-300" />
                <span>NF2 — Nachfassen</span>
              </div>
              <span class="text-[10px] text-muted-foreground">{{ nfStageCount(2) }} Konversationen</span>
            </label>
            <label class="flex items-center justify-between gap-2 text-[11px] cursor-pointer hover:bg-zinc-50 px-2 py-1.5 rounded">
              <div class="flex items-center gap-2">
                <input type="checkbox" v-model="nfStageSelection.nf3" class="rounded border-zinc-300" />
                <span>NF3 — Dringend</span>
              </div>
              <span class="text-[10px] text-muted-foreground">{{ nfStageCount(3) }} Konversationen</span>
            </label>
            <Button size="sm" class="w-full h-8 text-[11px] gap-1.5" @click="sendNachfassenSelected">
              <Send class="w-3 h-3" />
              Ausgewählte senden
            </Button>
          </div>

          <div v-if="showAutoFollowupSettings" class="rounded-lg border border-zinc-100 bg-white p-3 space-y-2">
            <div class="text-[12px] font-semibold">Auto-Nachfassen</div>
            <div class="text-[10px] text-muted-foreground">Automatisch Nachfass-Mails senden (alle 2h geprüft)</div>
            <label class="flex items-center gap-2 text-[11px] cursor-pointer">
              <input type="checkbox" v-model="autoFollowupStage1" class="rounded border-zinc-300" />
              NF1: Nach 24h automatisch nachfassen
            </label>
            <label class="flex items-center gap-2 text-[11px] cursor-pointer">
              <input type="checkbox" v-model="autoFollowupStage2" class="rounded border-zinc-300" />
              NF2: Nach weiteren 3 Tagen erneut nachfassen
            </label>
            <label class="flex items-center gap-2 text-[11px] cursor-pointer">
              <input type="checkbox" v-model="autoFollowupStage3" class="rounded border-zinc-300" />
              NF3: Nach weiteren 3 Tagen letzte Nachfrage (danach erledigt)
            </label>
            <div class="flex items-center justify-between">
              <button v-if="autoReplyLogs.length" class="text-[10px] text-emerald-600 hover:underline" @click="autoReplyBannerOpen = !autoReplyBannerOpen">
                ✓ {{ autoReplyLogs.length }} Auto-Mails (24h) anzeigen
              </button>
              <Button size="sm" class="h-7 text-[11px]" :disabled="autoFollowupSaving" @click="saveAutoFollowupSettings">Speichern</Button>
            </div>
          </div>
          <!-- Auto-Reply Log (shared, shows for both tabs) -->
          <div v-if="showAutoFollowupSettings && autoReplyBannerOpen && autoReplyLogs.length" class="rounded-lg border border-emerald-200 bg-emerald-50/50 overflow-hidden">
            <div class="px-3 py-1.5 border-b border-emerald-100 flex items-center justify-between">
              <span class="text-[11px] font-semibold text-emerald-800">Auto-Mails der letzten 24h</span>
              <button class="text-[10px] text-emerald-600 hover:text-emerald-800" @click="autoReplyBannerOpen = false">Schließen</button>
            </div>
            <div class="max-h-[160px] overflow-y-auto divide-y divide-emerald-100">
              <div v-for="log in autoReplyLogs" :key="log.id" class="px-3 py-1.5">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[11px] font-medium text-zinc-800 truncate">{{ log.stakeholder || log.to_email }}</span>
                  <span class="text-[10px] text-muted-foreground flex-shrink-0">{{ log.created_at?.substring(11, 16) }}</span>
                </div>
                <div class="text-[10px] text-muted-foreground truncate">{{ log.subject }}</div>
              </div>
            </div>
          </div>
        </div>
        <div v-if="false && activeSubtab === 'nachfassen' && filteredFollowups.filter(f => f.draft_body).length > 0" class="px-4 pb-2">
          <Button
            variant="outline"
            size="sm"
            class="w-full h-8 text-[11px] gap-1.5 border-orange-200 text-orange-700 hover:bg-orange-50"
            @click="nachfassenAlle"
          >
            <Send class="w-3 h-3" />
            Alle nachfassen ({{ filteredFollowups.length }})
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
          :toolbar-refreshing="inboxRefreshing"
          empty-message="Keine Nachfass-Konversationen"
          @select="(item) => openDetail(item, 'nachfassen')"
          @update:search-query="searchQuery = $event"
          @update:object-filter="objectFilter = $event"
          @toolbar-refresh="refreshInbox"
          @compose="startCompose()" @delete="markConvDone($event.id)"
          @batch-done="batchMarkDone($event)"
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
          :toolbar-refreshing="inboxRefreshing"
          empty-message="Keine E-Mails im Posteingang"
          @select="(item) => openDetail(item, 'posteingang')"
          @update:search-query="searchQuery = $event; ehSearch = $event; ehPage = 1; loadEmailHistory()"
          @update:object-filter="objectFilter = $event"
          @toolbar-refresh="refreshInbox"
          @compose="startCompose()" @delete="trashEmail($event.id)"
          @batch-trash="batchTrash($event)"
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
          :toolbar-refreshing="inboxRefreshing"
          empty-message="Keine gesendeten E-Mails"
          @select="(item) => openDetail(item, 'gesendet')"
          @update:search-query="searchQuery = $event; ehSearch = $event; ehPage = 1; loadEmailHistory()"
          @update:object-filter="objectFilter = $event"
          @toolbar-refresh="refreshInbox"
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
          :items="filteredTrash"
          :loading="trashLoading"
          subtab="papierkorb"
          :selected-id="selectedItem?.id"
          :search-query="searchQuery"
          :object-filter="'all'"
          :properties="[]"
          :toolbar-refreshing="inboxRefreshing"
          empty-message="Papierkorb ist leer"
          @select="(item) => openDetail(item, 'papierkorb')"
          @update:search-query="searchQuery = $event"
          @toolbar-refresh="refreshInbox"
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
          :toolbar-refreshing="inboxRefreshing"
          empty-message="Keine Entwuerfe"
          @select="(item) => loadDraftIntoCompose(item)"
          @update:search-query="searchQuery = $event"
          @toolbar-refresh="refreshInbox"
          @compose="startCompose()" @delete="trashEmail($event.id)"
        />

        <!-- Matches -->
        <div v-else-if="activeSubtab === 'matches'" class="flex flex-col h-full overflow-hidden">
          <div v-if="!matchItems.length" class="flex items-center justify-center py-12">
            <span class="text-[12px] text-muted-foreground">Keine Matches</span>
          </div>
          <div v-else class="flex-1 overflow-y-auto min-h-0 divide-y divide-zinc-100">
            <div
              v-for="item in matchItems"
              :key="item.id"
              class="px-3 py-2.5 cursor-pointer hover:bg-accent/50 transition-colors"
              :class="selectedItem?.id === item.id ? 'bg-accent' : ''"
              @click="openDetail(item, 'offen')"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="text-[13px] font-medium text-foreground truncate">{{ item.from_name || item.stakeholder }}</span>
                <span class="inline-flex items-center justify-center px-1.5 min-w-[18px] h-4 rounded-full bg-gradient-to-r from-violet-500 to-cyan-500 text-white text-[10px] font-bold">
                  {{ item.match_count }}
                </span>
              </div>
              <div class="text-[11px] text-muted-foreground truncate mt-0.5">{{ item.subject }}</div>
            </div>
          </div>
        </div>

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
        class="flex-1 min-w-0 w-full md:w-auto bg-white"
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
        :signature-data="sigData"
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
        @improve-wording="improveComposeWording"
        @apply-template="applyTemplate($event)"
        @add-attachments="onComposeAddAttachments"
        @remove-attachment="onComposeRemoveAttachment"
      />
      <InboxChatView
        v-else-if="selectedItem"
        class="flex-1 min-w-0 w-full md:w-auto bg-white"
        :item="selectedItem"
        :messages="allDetailMessages"
        :loading="expandedLoading"
        :mode="selectedMode"
        @close="selectedItem = null; detailOpen = false"
        @save-attachment="onSaveAttachment($event)"
        @match-draft="handleMatchDraft"
        @match-dismiss="handleMatchDismiss"
        @property-changed="onPropertyChanged"
      >
      </InboxChatView>
      <div v-else class="hidden md:flex flex-1 items-center justify-center text-sm text-muted-foreground">
        Konversation auswählen
      </div>
    </div>

    <!-- Save Attachment Dialog -->
    <div v-if="showSaveAttachDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="showSaveAttachDialog = false">
      <div class="bg-white rounded-xl shadow-xl border border-zinc-200 w-[calc(100vw-2rem)] max-w-[380px] overflow-hidden">
        <div class="px-5 py-3 border-b border-zinc-100 flex items-center justify-between">
          <span class="text-[14px] font-semibold">Anhang speichern</span>
          <button class="text-muted-foreground hover:text-foreground" @click="showSaveAttachDialog = false"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
        </div>
        <div class="px-5 py-4 space-y-3">
          <div class="flex items-center gap-2 text-[12px] bg-zinc-50 rounded-lg px-3 py-2">
            <svg class="w-4 h-4 text-zinc-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
            <span class="truncate font-medium">{{ saveAttachData?.filename }}</span>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground font-medium mb-1 block">Objekt</label>
            <select v-model="saveAttachPropertyId" class="w-full h-9 rounded-md border border-zinc-200 bg-white px-3 text-[12px]">
              <option :value="null" disabled>Objekt auswählen...</option>
              <option v-for="p in (properties || [])" :key="p.id" :value="String(p.id)">{{ p.ref_id || p.address || ("Obj " + p.id) }}</option>
            </select>
          </div>
          <div>
            <label class="text-[11px] text-muted-foreground font-medium mb-1 block">Bezeichnung</label>
            <input v-model="saveAttachLabel" class="w-full h-9 rounded-md border border-zinc-200 bg-white px-3 text-[12px]" placeholder="z.B. Grundbuchauszug, Expose..." />
          </div>
        </div>
        <div class="px-5 py-3 border-t border-zinc-100 flex justify-end gap-2">
          <Button variant="outline" size="sm" class="h-8 text-[12px]" @click="showSaveAttachDialog = false">Abbrechen</Button>
          <Button size="sm" class="h-8 text-[12px] gap-1.5" :disabled="saveAttachSaving || !saveAttachPropertyId" @click="confirmSaveAttachment">
            <svg v-if="saveAttachSaving" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            Speichern
          </Button>
        </div>
      </div>
    </div>

    <!-- HvComposeDialog ist jetzt global in Dashboard.vue gemountet — nicht mehr hier duplizieren -->
  </div>
</template>
