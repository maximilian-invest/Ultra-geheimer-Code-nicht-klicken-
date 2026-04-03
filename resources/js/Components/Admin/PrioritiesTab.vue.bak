<script setup>
import { ref, inject, onMounted, computed, watch, nextTick, provide } from "vue";
import { catBadgeStyle, catLabel } from '@/utils/categoryBadge.js';
import {
  Mail, Clock, Send, CheckCircle, X, ChevronDown, CalendarDays,
  Paperclip, Loader2, Search, Sparkles, ArrowUp, ArrowDown
} from "lucide-vue-next";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Textarea } from "@/components/ui/textarea";
import { ScrollArea } from "@/components/ui/scroll-area";
// Sheet replaced with manual slide-over panel
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

// === INJECTIONS ===
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

// === STATE ===
const activeTab = ref('offen');

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

// Filters
const searchQuery = ref('');
const objectFilter = ref('all');
const categoryFilter = ref('all');

// Sheet detail panel
const sheetOpen = ref(false);
const selectedItem = ref(null);
const sheetMode = ref('offen'); // 'offen' | 'nachfassen'

// Detail state
const expandedDetail = ref(null);
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

// Send state
const aiSending = ref(false);
const followupSending = ref(false);
const aiDetailLevel = ref(localStorage.getItem("sr-ai-detail-level") || "standard");

// Send accounts
const sendAccounts = ref([]);
const sendAccountId = ref(null);

// Recipient email
const recipientEmailSaving = ref(false);
const recipientEmailSaved = ref(false);

// Auto-reply
const autoReplyLogs = ref([]);
const autoReplyLoading = ref(false);
const autoReplyBannerOpen = ref(false);

// Broker filter
const maklerFilter = ref('all');
const brokerList = ref([]);

// === COMPUTED ===
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

// === HELPERS ===
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

// === API FUNCTIONS ===
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

// === SHEET FUNCTIONS ===
function openDetail(item, mode) {
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

  // Load email context
  const contextPromise = fetch(API.value + "&action=email_context&email_id=" + item.id + "&type=activity")
    .then(r => r.json())
    .then(d => { expandedDetail.value = { email: d.email || null, thread: d.thread || [] }; })
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
  if (selectedItem.value) regenerateAiDraft();
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
      sheetOpen.value = false;
      selectedItem.value = null;
      loadUnanswered(unansweredFilter.value);
      loadFollowups(followupFilter.value);
      loadStage1();
      refreshCounts();
    } else { toast("Fehler: " + (d.error || "Unbekannt")); }
  } catch (e) { toast("Fehler: " + e.message); }
}

// === SEND FUNCTION ===
async function sendDraft() {
  const item = selectedItem.value;
  const draft = expandedAiDraft.value;
  if (!draft || !item) return;

  const itemName = item.from_name || item.from_email || item.stakeholder || "Kunde";
  const itemId = item.id;
  const isFollowup = sheetMode.value === 'nachfassen';

  // 1. Close sheet immediately, remove item from list
  sheetOpen.value = false;
  selectedItem.value = null;
  if (isFollowup) {
    stage1Followups.value = stage1Followups.value.filter(i => i.id !== itemId);
    if (followupData.value && followupData.value.followups) {
      followupData.value.followups = followupData.value.followups.filter(i => i.id !== itemId);
    }
  } else {
    unansweredList.value = unansweredList.value.filter(i => i.id !== itemId);
  }

  // 2. Show persistent sending toast
  const toastsContainer = document.querySelector(".fixed.bottom-4.right-4");
  let sendingEl = null;
  if (toastsContainer) {
    sendingEl = document.createElement("div");
    sendingEl.className = "toast-notification";
    sendingEl.style.cssText = "background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none";
    sendingEl.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span><span>Wird gesendet an ' + itemName + '...</span>';
    toastsContainer.appendChild(sendingEl);
  }

  // 3. Send in background
  try {
    // Load signature
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

    // Fetch file attachments
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

// === REASSIGN ===
// === LIFECYCLE ===
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

<template>
  <div class="flex h-full" style="min-height:0">

    <!-- LEFT: List Panel (hidden on mobile when detail open) -->
    <div class="flex flex-col h-full p-4 overflow-hidden" :class="[sheetOpen ? 'w-[380px] flex-shrink-0 border-r border-gray-200 hidden lg:flex' : 'flex-1']">

    <!-- Broker Filter (Assistenz only) -->
    <div v-if="isAssistenz && brokerList.length" class="flex items-center gap-2 mb-3">
      <span class="text-xs font-medium text-muted-foreground">Makler:</span>
      <Select v-model="maklerFilter">
        <SelectTrigger class="w-[200px] h-8 text-xs">
          <SelectValue placeholder="Alle Makler" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">Alle Makler</SelectItem>
          <SelectItem v-for="b in brokerList" :key="b.id" :value="String(b.id)">
            {{ b.name || b.email }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>

    <!-- Tabs: Offen / Nachfassen -->
    <Tabs v-model="activeTab" class="w-full flex-1 flex flex-col min-h-0">
      <TabsList class="inline-flex h-auto w-auto self-start">
        <TabsTrigger value="offen" class="gap-1.5 text-[12px] px-4">
          Offen
          <span v-if="unansweredCount" class="ml-1 text-[10px] font-bold px-1.5 py-0 rounded-full bg-red-50 text-red-600">{{ unansweredCount }}</span>
        </TabsTrigger>
        <TabsTrigger value="nachfassen" class="gap-1.5 text-[12px] px-4">
          Nachfassen
          <span v-if="(followupCount || 0) + (stage1Count || 0)" class="ml-1 text-[10px] font-bold px-1.5 py-0 rounded-full bg-zinc-100 text-zinc-500">{{ (followupCount || 0) + (stage1Count || 0) }}</span>
        </TabsTrigger>
      </TabsList>

      <!-- Auto-Reply Banner -->
      <div v-if="autoReplyLogs.length" class="mt-3">
        <Collapsible v-model:open="autoReplyBannerOpen">
          <CollapsibleTrigger class="flex items-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-medium cursor-pointer" style="background:rgba(16,185,129,0.08);color:#059669">
            <Send class="w-3.5 h-3.5" />
            <span>{{ autoReplyLogs.length }} Auto-Replies gesendet (24h)</span>
            <ChevronDown class="w-3.5 h-3.5 ml-auto transition-transform" :class="autoReplyBannerOpen ? 'rotate-180' : ''" />
          </CollapsibleTrigger>
          <CollapsibleContent>
            <div class="mt-1 rounded-lg border border-gray-200 p-3 space-y-2 max-h-48 overflow-y-auto" style="background:rgba(16,185,129,0.03)">
              <div v-for="log in autoReplyLogs" :key="log.id" class="flex items-center gap-2 text-xs text-muted-foreground">
                <Send class="w-3 h-3 text-emerald-500 flex-shrink-0" />
                <span class="font-medium text-foreground">{{ log.to_name || log.to_email }}</span>
                <span class="text-muted-foreground truncate">{{ log.subject }}</span>
                <span class="ml-auto text-[10px] whitespace-nowrap">{{ timeAgo(log.sent_at || log.created_at) }}</span>
              </div>
            </div>
          </CollapsibleContent>
        </Collapsible>
      </div>

      <!-- Toolbar: Search + Filters -->
      <div class="flex flex-wrap items-center gap-2 mt-3">
        <div class="relative flex-1 min-w-[140px]">
          <Search class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input v-model="searchQuery" placeholder="Suchen..." class="pl-8 h-8 text-sm" />
        </div>
        <Select v-model="objectFilter">
          <SelectTrigger class="w-auto h-8 text-xs">
            <SelectValue placeholder="Objekt" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Alle Objekte</SelectItem>
            <SelectItem v-for="p in availableProperties" :key="p.id" :value="p.id">
              {{ p.ref_id }}
            </SelectItem>
          </SelectContent>
        </Select>
        <Select v-if="!sheetOpen" v-model="categoryFilter">
          <SelectTrigger class="w-auto h-8 text-xs">
            <SelectValue placeholder="Kategorie" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Alle Kategorien</SelectItem>
            <SelectItem v-for="cat in availableCategories" :key="cat" :value="cat">
              {{ catLabel(cat) }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- TAB: Offen -->
      <TabsContent value="offen" class="mt-3 flex-1 min-h-0 flex flex-col data-[state=inactive]:hidden">
        <div v-if="unansweredLoading" class="flex items-center justify-center py-12">
          <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
        </div>
        <div v-else-if="!filteredUnanswered.length" class="py-12 text-center text-sm text-muted-foreground">
          Keine offenen Anfragen.
        </div>
        <div v-else class="flex-1 overflow-y-auto min-h-0">
          <div class="divide-y divide-gray-100">
            <div
              v-for="item in filteredUnanswered"
              :key="item.id"
              @click="openDetail(item, 'offen')"
              class="cursor-pointer transition-colors hover:bg-muted/50"
              :class="[selectedItem?.id === item.id && sheetMode === 'offen' ? 'bg-orange-50' : '', sheetOpen ? 'px-2 py-2' : 'px-3 py-3']"
            >
              <!-- Row 1: Avatar + Name + Badges + Time -->
              <div class="flex items-center gap-2">
                <Avatar class="h-7 w-7 flex-shrink-0">
                  <AvatarFallback class="text-[10px] font-medium bg-orange-100 text-orange-700">{{ getInitials(item.from_name) }}</AvatarFallback>
                </Avatar>
                <span class="font-semibold text-sm truncate">{{ item.from_name || item.from_email }}</span>
                <Badge v-if="item.category === 'bounce'" class="text-[10px] px-1.5 py-0 bg-red-100 text-red-700 border-red-200">Bounce</Badge>
                <Badge v-else-if="item.days_waiting >= 3" class="text-[10px] px-1.5 py-0 bg-red-100 text-red-700 border-red-200">Dringend</Badge>
                <span class="ml-auto text-[10px] text-muted-foreground whitespace-nowrap">{{ timeAgo(item.email_date || item.created_at) }}</span>
              </div>
              <!-- Row 2: Subject -->
              <div class="text-sm font-medium line-clamp-1 ml-9 mt-0.5">{{ item.subject || '(Kein Betreff)' }}</div>
              <!-- Row 3: Preview (hidden when panel open) -->
              <div v-if="!sheetOpen" class="text-xs text-muted-foreground line-clamp-1 ml-9 mt-0.5">{{ stripQuotedReply(item.ai_summary || item.body_text || item.body || '') }}</div>
              <!-- Row 4: Tags (hidden when panel open) -->
              <div v-if="!sheetOpen" class="flex flex-wrap gap-1 ml-9 mt-1.5">
                <Badge v-if="item.platform" variant="outline" class="text-[10px] px-1.5 py-0">{{ item.platform }}</Badge>
                <Badge v-if="item.ref_id" variant="outline" class="text-[10px] px-1.5 py-0">{{ item.ref_id }}</Badge>
                <Badge v-if="item.category" variant="outline" class="text-[10px] px-1.5 py-0" :style="catBadgeStyle(item.category)">{{ catLabel(item.category) }}</Badge>
              </div>
            </div>
          </div>
        </div>
      </TabsContent>

      <!-- TAB: Nachfassen -->
      <TabsContent value="nachfassen" class="mt-3 flex-1 min-h-0 flex flex-col data-[state=inactive]:hidden">
        <div v-if="followupLoading && stage1Loading" class="flex items-center justify-center py-12">
          <Loader2 class="w-5 h-5 animate-spin text-muted-foreground" />
        </div>
        <div v-else-if="!filteredFollowups.length" class="py-12 text-center text-sm text-muted-foreground">
          Keine Nachfass-Fälle.
        </div>
        <div v-else class="flex-1 overflow-y-auto min-h-0">
          <div class="divide-y divide-gray-100">
            <div
              v-for="item in filteredFollowups"
              :key="'f-' + item.id + '-' + item._stage"
              @click="openDetail(item, 'nachfassen')"
              class="cursor-pointer transition-colors hover:bg-muted/50"
              :class="[selectedItem?.id === item.id && sheetMode === 'nachfassen' ? 'bg-orange-50' : '', sheetOpen ? 'px-2 py-2' : 'px-3 py-3']"
            >
              <!-- Row 1: Avatar + Name + Badge + Time -->
              <div class="flex items-center gap-2">
                <Avatar class="h-7 w-7 flex-shrink-0">
                  <AvatarFallback class="text-[10px] font-medium bg-blue-100 text-blue-700">{{ getInitials(item.from_name || item.stakeholder) }}</AvatarFallback>
                </Avatar>
                <span class="font-semibold text-sm truncate">{{ item.from_name || item.stakeholder }}</span>
                <Badge v-if="item._stage === 1" class="text-[10px] px-1.5 py-0 bg-amber-100 text-amber-800 border-amber-200">24h</Badge>
                <Badge v-else-if="item.days_waiting >= 14" class="text-[10px] px-1.5 py-0 bg-red-100 text-red-700 border-red-200">{{ item.days_waiting }}d</Badge>
                <Badge v-else-if="item.days_waiting >= 7" class="text-[10px] px-1.5 py-0 bg-orange-100 text-orange-700 border-orange-200">{{ item.days_waiting }}d</Badge>
                <Badge v-else class="text-[10px] px-1.5 py-0 bg-blue-50 text-blue-600 border-blue-100">{{ item.days_waiting || '?' }}d</Badge>
                <span class="ml-auto text-[10px] text-muted-foreground whitespace-nowrap">{{ timeAgo(item.last_contact || item.email_date || item.created_at) }}</span>
              </div>
              <!-- Row 2: Subject/Activity -->
              <div class="text-sm font-medium line-clamp-1 ml-9 mt-0.5">{{ item.subject || item.activity || '(Kein Betreff)' }}</div>
              <!-- Row 3: Preview (hidden when panel open) -->
              <div v-if="!sheetOpen" class="text-xs text-muted-foreground line-clamp-1 ml-9 mt-0.5">{{ stripQuotedReply(item.ai_summary || item.body_text || item.body || item.last_message || '') }}</div>
              <!-- Row 4: Tags (hidden when panel open) -->
              <div v-if="!sheetOpen" class="flex flex-wrap gap-1 ml-9 mt-1.5">
                <Badge v-if="item.platform" variant="outline" class="text-[10px] px-1.5 py-0">{{ item.platform }}</Badge>
                <Badge v-if="item.ref_id" variant="outline" class="text-[10px] px-1.5 py-0">{{ item.ref_id }}</Badge>
                <Badge v-if="item.category" variant="outline" class="text-[10px] px-1.5 py-0" :style="catBadgeStyle(item.category)">{{ catLabel(item.category) }}</Badge>
              </div>
            </div>
          </div>
        </div>
      </TabsContent>
    </Tabs>
    </div><!-- end LEFT list panel -->

    <!-- RIGHT: Detail Panel (inline, side-by-side) -->
    <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in" enter-from-class="opacity-0 translate-x-4" leave-to-class="opacity-0 translate-x-4">
      <div v-if="sheetOpen && selectedItem" class="flex-1 min-w-0 bg-white dark:bg-zinc-950 flex flex-col h-full overflow-hidden">

          <!-- Compact Header: Back (mobile) + Subject + Close -->
          <div class="flex items-center gap-2 px-4 py-2.5 border-b border-gray-200 flex-shrink-0">
            <button @click="sheetOpen = false; selectedItem = null" class="lg:hidden w-7 h-7 rounded-md flex items-center justify-center hover:bg-muted transition-colors flex-shrink-0">
              <ArrowDown class="w-4 h-4 text-muted-foreground -rotate-90" />
            </button>
            <div class="font-medium text-[13px] truncate flex-1">{{ selectedItem.subject || selectedItem.activity || '(Kein Betreff)' }}</div>
            <button @click="sheetOpen = false; selectedItem = null" class="hidden lg:flex w-6 h-6 rounded-md border border-gray-200 items-center justify-center hover:bg-muted transition-colors flex-shrink-0">
              <X class="w-3 h-3 text-muted-foreground" />
            </button>
          </div>

          <!-- Scrollable middle: Nachricht + Verlauf + KI-Entwurf -->
          <div class="flex-1 min-h-0 overflow-y-auto flex flex-col">

              <!-- Bounce Warning -->
              <div v-if="selectedItem.category === 'bounce'" class="mx-5 mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <strong>Unzustellbar:</strong> Diese E-Mail konnte nicht zugestellt werden. Bitte E-Mail-Adresse pruefen.
              </div>

              <!-- Nachricht + Verlauf -->
              <div class="px-4 pt-2 pb-1">
                <div v-if="expandedLoading" class="flex items-center justify-center py-4">
                  <Loader2 class="w-4 h-4 animate-spin text-muted-foreground" />
                </div>
                <template v-else>
                  <!-- Current message -->
                  <div class="bg-slate-50 dark:bg-zinc-900 rounded-lg p-3 text-[12px] text-foreground whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto border border-gray-100 dark:border-zinc-800">{{ expandedDetail?.email?.body_text || selectedItem.body_text || selectedItem.ai_summary || 'Kein Inhalt verfuegbar.' }}</div>

                  <!-- Thread (inline, collapsed by default) -->
                  <div v-if="expandedDetail?.thread?.length" class="mt-2">
                    <button @click="showThreadAccordion = !showThreadAccordion" class="flex items-center gap-1.5 text-[11px] text-muted-foreground hover:text-foreground cursor-pointer">
                      <Clock class="w-3 h-3" />
                      <span>{{ expandedDetail.thread.length }} frühere {{ expandedDetail.thread.length === 1 ? 'Nachricht' : 'Nachrichten' }}</span>
                      <ChevronDown class="w-2.5 h-2.5 transition-transform" :class="showThreadAccordion ? 'rotate-180' : ''" />
                    </button>
                    <div v-if="showThreadAccordion" class="space-y-1.5 mt-2">
                      <div v-for="(msg, idx) in expandedDetail.thread" :key="idx" class="rounded border border-gray-200 p-2 text-[11px]">
                        <div class="flex items-center gap-1.5 mb-0.5">
                          <ArrowDown v-if="msg.direction === 'inbound' || msg.direction === 'in'" class="w-2.5 h-2.5 text-blue-500" />
                          <ArrowUp v-else class="w-2.5 h-2.5 text-green-500" />
                          <span class="font-medium">{{ msg.from_name || msg.from_email || (msg.direction === 'outbound' || msg.direction === 'out' ? 'SR-Homes' : selectedItem.from_name) }}</span>
                          <span class="ml-auto text-[10px] text-muted-foreground">{{ formatDetailDate(msg.date || msg.email_date) }}</span>
                        </div>
                        <div class="text-muted-foreground whitespace-pre-wrap line-clamp-3">{{ stripQuotedReply(msg.body || msg.body_text || msg.ai_summary || '') }}</div>
                      </div>
                    </div>
                  </div>
                </template>
              </div>

              <!-- KI-Entwurf (in scroll area, directly after Verlauf) -->
              <div class="px-4 pt-2 pb-0 border-t border-gray-100 flex-1 flex flex-col">
                <div class="flex items-center justify-between mb-2">
                  <div class="flex items-center gap-2">
                    <Sparkles class="w-3.5 h-3.5 text-orange-500" />
                    <span class="text-[12px] font-semibold">KI-Entwurf</span>
                  </div>
                  <button v-if="expandedAiDraft" @click="showEmailFields = !showEmailFields" class="flex items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground cursor-pointer">
                    <span>Von/An/Betr.</span>
                    <ChevronDown class="w-2.5 h-2.5 transition-transform" :class="showEmailFields ? 'rotate-180' : ''" />
                  </button>
                </div>

                <div v-if="expandedAiLoading" class="flex items-center justify-center py-4">
                  <Loader2 class="w-5 h-5 animate-spin text-orange-500" />
                  <span class="ml-2 text-sm text-muted-foreground">KI generiert Entwurf...</span>
                </div>

                <template v-else-if="expandedAiDraft">
                  <!-- Email fields (Von/An/Betr.) -->
                  <div v-if="showEmailFields" class="space-y-1.5 mb-2">
                    <div v-if="sendAccounts.length > 1" class="flex items-center gap-2">
                      <span class="text-xs text-muted-foreground w-8">Von:</span>
                      <Select v-model="sendAccountId">
                        <SelectTrigger class="flex-1 h-7 text-xs">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem v-for="acc in sendAccounts" :key="acc.id" :value="acc.id">{{ acc.email }}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div v-else class="flex items-center gap-2 text-xs">
                      <span class="text-muted-foreground w-8">Von:</span>
                      <span>{{ sendAccounts[0]?.email || 'Standard' }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-xs text-muted-foreground w-8">An:</span>
                      <Input v-model="expandedAiDraft.to" class="flex-1 h-7 text-xs" />
                      <Button
                        v-if="expandedAiDraft.to"
                        variant="ghost" size="icon-sm"
                        @click="saveRecipientEmail(selectedItem.from_name || selectedItem.stakeholder, selectedItem.property_id, expandedAiDraft.to)"
                        :disabled="recipientEmailSaving"
                        class="h-7 w-7"
                      >
                        <Loader2 v-if="recipientEmailSaving" class="w-3 h-3 animate-spin" />
                        <CheckCircle v-else-if="recipientEmailSaved" class="w-3 h-3 text-green-500" />
                        <CheckCircle v-else class="w-3 h-3" />
                      </Button>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-xs text-muted-foreground w-8">Betr.:</span>
                      <Input v-model="expandedAiDraft.subject" class="flex-1 h-7 text-xs" />
                    </div>
                  </div>

                  <!-- Draft Textarea -->
                  <div class="border border-gray-200 rounded-lg overflow-hidden flex-1 flex flex-col">
                    <Textarea v-model="expandedAiDraft.body" class="flex-1 min-h-[200px] text-[12px] leading-relaxed resize-none border-0 focus-visible:ring-0 focus-visible:ring-offset-0" />
                  </div>
                </template>

                <div v-else class="text-sm text-muted-foreground py-3 text-center">
                  KI-Vorschlag konnte nicht generiert werden.
                  <Button variant="link" size="sm" @click="regenerateAiDraft" class="ml-1">Erneut versuchen</Button>
                </div>
              </div>

              <!-- Calendar embed -->
              <div v-if="showCalendar && calendarEmbedUrl" class="mx-4 my-2 rounded-lg border border-gray-200 overflow-hidden">
                <iframe :src="calendarEmbedUrl" class="w-full h-[400px] border-0" />
              </div>

          </div>

          <!-- FIXED BOTTOM: Toolbar only (always visible) -->
          <div v-if="expandedAiDraft" class="border-t border-gray-200 bg-white dark:bg-zinc-950 flex-shrink-0 px-4 py-2">
            <div class="flex items-center gap-1.5 flex-wrap">
              <!-- Attachments -->
              <div class="relative">
                <Button variant="outline" size="sm" @click="showAttachPopup = !showAttachPopup" class="h-7 text-[11px] gap-1.5 px-2.5">
                  <Paperclip class="w-3 h-3" />
                  <span v-if="expandedSelectedFiles.length">{{ expandedSelectedFiles.length }} {{ expandedSelectedFiles.length === 1 ? 'Datei' : 'Dateien' }}</span>
                  <span v-else>Anhang</span>
                </Button>
                <div v-if="showAttachPopup" class="absolute bottom-full left-0 mb-1 w-64 bg-white dark:bg-zinc-950 border border-gray-200 rounded-lg shadow-lg p-2 z-50">
                  <div class="text-xs font-medium mb-1.5">Dateien anhaengen</div>
                  <div v-if="expandedFilesLoading" class="py-3 text-center">
                    <Loader2 class="w-4 h-4 animate-spin mx-auto text-muted-foreground" />
                  </div>
                  <div v-else-if="!expandedFiles.length" class="text-xs text-muted-foreground py-2 text-center">Keine Dateien verfuegbar.</div>
                  <div v-else class="space-y-0.5 max-h-40 overflow-y-auto">
                    <label v-for="f in expandedFiles" :key="f.id" class="flex items-center gap-2 text-xs p-1 rounded hover:bg-muted cursor-pointer">
                      <input type="checkbox" :checked="expandedSelectedFiles.includes(f.id)" @change="toggleFileSelection(f.id)" class="rounded" />
                      <span class="truncate">{{ f.filename || f.label }}</span>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Detail level -->
              <Select :model-value="aiDetailLevel" @update:model-value="setAiDetailLevel">
                <SelectTrigger class="w-[100px] h-7 text-[11px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="brief">Knapp</SelectItem>
                  <SelectItem value="standard">Standard</SelectItem>
                  <SelectItem value="ausfuehrlich">Ausfuehrlich</SelectItem>
                </SelectContent>
              </Select>

              <!-- Calendar button -->
              <Button v-if="calendarEmbedUrl" variant="outline" size="sm" @click="showCalendar = !showCalendar" class="h-7 w-7 p-0">
                <CalendarDays class="w-3 h-3" />
              </Button>

              <div class="flex-1" />

              <!-- Erledigt -->
              <Button variant="outline" size="sm" @click="markHandled(selectedItem.from_name || selectedItem.stakeholder, selectedItem.property_id)" class="h-7 text-[11px] gap-1.5 px-2.5">
                <CheckCircle class="w-3 h-3" />
                Erledigt
              </Button>

              <!-- Senden -->
              <Button
                size="sm"
                @click="sendDraft"
                :disabled="!expandedAiDraft.to || !expandedAiDraft.body"
                class="h-7 text-[11px] gap-1.5 px-4 text-white border-0 shadow-sm"
                style="background:linear-gradient(135deg,#f97316,#ea580c)"
              >
                <Send class="w-3 h-3" />
                Senden
              </Button>
            </div>
          </div>

      </div>
    </Transition>

  </div>
</template>
