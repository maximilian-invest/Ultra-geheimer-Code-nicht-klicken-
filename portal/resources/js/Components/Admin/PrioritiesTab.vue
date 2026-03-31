<script setup>
import { catBadgeStyle, catLabel, catFilterStyle } from '@/utils/categoryBadge.js';
import { ref, inject, onMounted, computed } from "vue";
import { MailX, Clock, Sparkles, Send, Pause, Play, CheckCircle, BellOff, KanbanSquare, AlertCircle, AlertTriangle, Info, ArrowRight, X, Phone, Mail, ChevronLeft, ChevronRight, Loader2, Home, Check, ChevronDown, CalendarDays, Paperclip } from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");
const switchTab = inject("switchTab");
const unansweredCount = inject("unansweredCount");
const followupCount = inject("followupCount");
const refreshCounts = inject("refreshCounts", () => {});
const properties = inject("properties");
const userName = inject("userName", "Admin");
const calendarEmbedUrl = inject("calendarEmbedUrl", "");
const userType = inject("userType", ref("makler"));
const isAssistenz = computed(() => userType.value === "backoffice");


const unansweredList = ref([]);
const unmatchedList = ref([]);
const onHoldUnansweredList = ref([]);
const onHoldUnansweredExpanded = ref(false);
const unansweredLoading = ref(false);
const unansweredFilter = ref("all");
const unansweredInnerTab = ref('assigned');
const unansweredCategoryFilter = ref('all');
const expandedUnanswered = ref(null); // id of expanded item
const expandedDetail = ref(null); // { email, thread }
const expandedLoading = ref(false);
const expandedAiDraft = ref(null); // { reply_text, subject, to, prospect_email }
const expandedAiLoading = ref(false);
const aiDetailLevel = ref(localStorage.getItem("sr-ai-detail-level") || "standard");
function setAiDetailLevel(level) {
    aiDetailLevel.value = level;
    localStorage.setItem("sr-ai-detail-level", level);
    // Regenerate AI draft with new detail level
    if (expandedUnanswered.value) {
        const item = unansweredList.value.find(i => i.id === expandedUnanswered.value);
        if (item) regenerateAiDraft(item);
    }
}
async function regenerateAiDraft(item) {
    expandedAiDraft.value = null;
    expandedAiLoading.value = true;
    try {
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
    } catch (e) { toast("KI-Fehler: " + e.message); }
    expandedAiLoading.value = false;
}
const expandedFiles = ref([]); // property files for attachment selection
const expandedFilesLoading = ref(false);
const expandedSelectedFiles = ref([]);
const expandedBodyFull = ref(false); // selected file IDs
const expandedThreadMsg = ref(null); // id of expanded thread message
const showCalendar = ref(false);
const showAttachPopup = ref(false);
const showThreadAccordion = ref(false);
const showEmailFields = ref(false);
const showFollowupEmailFields = ref(false);
const showFollowupThread = ref(false);

const expandedItem = ref(null);
const expandedFollowupItem = ref(null);  // Vollständiges Item für saveRecipientEmail
const editingAssignment = ref(null); // {item, type:'prop'|'cat'}

async function reassignItem(item, propertyId) {
    try {
        await fetch(API.value + "&action=reassign_email", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email_id: item.source_email_id || item.id, property_id: propertyId }),
        });
        item.property_id = propertyId;
        const p = properties.value?.find(pp => pp.id === propertyId);
        if (p) item.ref_id = p.ref_id;
        editingAssignment.value = null;
        toast("Objekt zugewiesen");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function changeCategoryItem(item, category) {
    try {
        await fetch(API.value + "&action=change_email_category", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email_id: item.source_email_id || item.id, category }),
        });
        item.category = category;
        editingAssignment.value = null;
        toast("Kategorie geändert");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function sendUnansweredReply(item) {
    if (!expandedAiDraft.value?.body) { toast("Kein Entwurf vorhanden"); return; }
    await useAiDraft(item);
}

async function toggleUnansweredDetail(item) {
    expandedItem.value = item;
    if (expandedUnanswered.value === item.id) {
        expandedUnanswered.value = null;
        expandedDetail.value = null;
        expandedAiDraft.value = null;
        expandedThreadMsg.value = null;
        return;
    }
    expandedUnanswered.value = item.id;
    expandedDetail.value = null;
    expandedAiDraft.value = null;
    expandedThreadMsg.value = null;
    showCalendar.value = false;
    showAttachPopup.value = false;
    expandedBodyFull.value = false;
    expandedLoading.value = true;
    expandedAiLoading.value = true;

    // If pre-generated draft is already embedded → show instantly, no API call needed
    if (item.draft && item.draft.body) {
        expandedAiDraft.value = {
            body: item.draft.body,
            subject: item.draft.subject || ("Re: " + (item.subject || "")),
            to: item.draft.to || item.from_email || item.contact_email || "",
            prospect_email: item.draft.to || "",
        };
        expandedAiLoading.value = false;
    }

    // Load property files
    expandedFiles.value = [];
    expandedSelectedFiles.value = [];
    showThreadAccordion.value = false;
    showEmailFields.value = false;
    expandedFilesLoading.value = true;
    if (item.property_id) {
        fetch(API.value + "&action=get_property_files&property_id=" + item.property_id)
            .then(r => r.json())
            .then(d => { expandedFiles.value = d.files || []; })
            .catch(() => {})
            .finally(() => { expandedFilesLoading.value = false; });
    } else { expandedFilesLoading.value = false; }

    // Load context (email thread) — always needed
    const contextPromise = fetch(API.value + "&action=email_context&email_id=" + item.id + "&type=activity")
        .then(r => r.json())
        .then(d => {
            expandedDetail.value = { email: d.email || null, thread: d.thread || [] };
        })
        .catch(e => { toast("Fehler: " + e.message); })
        .finally(() => { expandedLoading.value = false; });

    // Only call ai_reply if no pre-generated draft available
    const promises = [contextPromise];
    if (!item.draft || !item.draft.body) {
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
                    // Cache it on item for future toggles
                    item.draft = { body: d.reply_text, subject: d.subject, to: d.to };
                }
            })
            .catch(() => {})
            .finally(() => { expandedAiLoading.value = false; });
        promises.push(aiPromise);
    }

    await Promise.all(promises);
}

const aiSending = ref(false);
const followupSending = ref(false);

async function useAiDraft(item) {
    const draft = expandedAiDraft.value;
    if (!draft) return;

    // 1. Immediately close expanded view and remove from list
    const itemName = item.from_name || item.from_email || "Kunde";
    const itemId = item.id;
    expandedUnanswered.value = null;
    expandedDetail.value = null;
    expandedAiDraft.value = null;
    expandedSelectedFiles.value = [];
    // Remove from list immediately for instant feedback
    unansweredList.value = unansweredList.value.filter(i => i.id !== itemId);

    // 2. Show persistent "sending" toast
    const sendToastId = Date.now() + Math.random();
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
        let sigText = "\n\n--\nMaximilian Hölzl\nKonzessionierter Immobilientreuhänder\nSR-Homes Immobilien GmbH\nTel: +43 660 9199939\nwww.sr-homes.at";
        let sigHtml = '<br><br><span style="color:#999">--</span><br>SR-Homes Immobilien GmbH<br>www.sr-homes.at';
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
        const ctx = expandedDetail.value;
        if (ctx && ctx.body) {
            const origDate = ctx.date || "";
            const origFrom = (ctx.from_name || ctx.stakeholder || "").replace(/</g, "&lt;").replace(/>/g, "&gt;");
            const origBody = (ctx.body || "").replace(/\n/g, "<br>");
            htmlBody += '<br><br><div style="border-left: 2px solid #ccc; padding-left: 10px; margin-left: 5px; color: #666;">' +
                '<p style="margin: 0 0 8px 0; font-size: 12px;">Am ' + origDate + ' schrieb ' + origFrom + ':</p>' +
                '<div style="font-size: 13px;">' + origBody + '</div></div>';
        }

        // Fetch file attachments
        const attachments = [];
        const selectedFiles = [...(item._selectedFiles || [])];
        const files = [...(item._expandedFiles || [])];
        if (selectedFiles.length && files.length) {
            for (const fileId of selectedFiles) {
                const ef = files.find(f => f.id === fileId);
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
        let accountId = "1";
        try {
            const acr = await fetch(API.value + "&action=email_accounts");
            const acd = await acr.json();
            if (acd.accounts && acd.accounts.length) accountId = String(acd.accounts[0].id);
        } catch {}
        fd.append("account_id", accountId);
        fd.append("to_email", draft.to || item.from_email || "");
        fd.append("to_name", item.from_name || item.stakeholder || "");
        fd.append("subject", draft.subject || "");
        fd.append("body_html", htmlBody);
        fd.append("body_text", draft.body + sigText);
        fd.append("property_id", item.property_id || "");
        fd.append("in_reply_to", String(item.id) || "");
        for (const file of attachments) fd.append("attachments[]", file);

        const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
        const result = await r.json();

        // Remove sending toast
        if (sendingEl) sendingEl.remove();

        if (result.success) {
            toast("✓ Email an " + itemName + " gesendet!" + (attachments.length ? " (" + attachments.length + " Anhänge)" : ""));
            loadFollowups("all");
            refreshCounts();
        } else {
            toast("✗ Fehler beim Senden an " + itemName + ": " + (result.error || "Unbekannt"));
            // Re-add to list on failure
            loadFollowups("all");
        }
    } catch (e) {
        if (sendingEl) sendingEl.remove();
        toast("✗ Sende-Fehler: " + e.message);
        loadFollowups("all");
    }
}

function toggleFileSelection(fileId) {
    const idx = expandedSelectedFiles.value.indexOf(fileId);
    if (idx >= 0) expandedSelectedFiles.value.splice(idx, 1);
    else expandedSelectedFiles.value.push(fileId);
}

function formatDetailDate(s) {
    if (!s) return "";
    // If string has time part (contains space or T), show time; otherwise just date
    if (s.includes(" ") || s.includes("T")) {
        const d = new Date(s.replace(" ", "T"));
        return d.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" }) + ", " + d.toLocaleTimeString("de-AT", { hour: "2-digit", minute: "2-digit" });
    }
    return s.split("-").reverse().join(".");
}

function stripQuotedReply(text) {
    if (!text) return '';
    // Split patterns that indicate start of quoted reply
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
    // Also strip trailing signature blocks
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
const followupData = ref(null);
const followupLoading = ref(false);
const followupFilter = ref("all");
const onHoldList = ref([]);

// Followup expand state (like unanswered)
const expandedFollowup = ref(null);
const followupDetail = ref(null);
const followupThreadMsg = ref(null);  // id of expanded thread message in followup
const followupDetailLoading = ref(false);
const followupAiDraft = ref(null);
const followupAiLoading = ref(false);
const followupAiSending = ref(false);

// ===== STUFE 1: 24h-Nachfassen =====
const stage1Followups = ref([]);
const stage1Count = ref(0);
const stage1Loading = ref(false);
const expandedStage1 = ref(null);
const stage1AiDraft = ref(null);
const stage1AiLoading = ref(false);
const stage1Sending = ref(false);
const stage1Detail = ref(null);
const stage1DetailLoading = ref(false);
const stage1ThreadMsg = ref(null);
const showStage1EmailFields = ref(false);
const showStage1Thread = ref(false);

// Auto-Nachfassen Settings
const showAutoFollowupSettings = ref(false);
const autoFollowupSettings = ref({ stage1_enabled: false, stage2_enabled: false, account_id: 0, accounts: [] });
const autoFollowupSaving = ref(false);
const autoFollowupLoading = ref(false);

async function loadAutoFollowupSettings() {
    autoFollowupLoading.value = true;
    try {
        const r = await fetch(API.value + '&action=get_auto_followup_settings');
        const d = await r.json();
        autoFollowupSettings.value = { ...autoFollowupSettings.value, ...d };
    } catch (e) { toast('Fehler beim Laden der Auto-Nachfassen Einstellungen'); }
    autoFollowupLoading.value = false;
}

async function saveAutoFollowupSettings() {
    autoFollowupSaving.value = true;
    try {
        const r = await fetch(API.value + '&action=save_auto_followup_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                stage1_enabled: autoFollowupSettings.value.stage1_enabled,
                stage2_enabled: autoFollowupSettings.value.stage2_enabled,
                account_id: autoFollowupSettings.value.account_id,
            }),
        });
        const d = await r.json();
        if (d.success) toast('Auto-Nachfassen Einstellungen gespeichert');
        else toast('Fehler beim Speichern');
    } catch (e) { toast('Fehler: ' + e.message); }
    autoFollowupSaving.value = false;
}

function toggleAutoFollowupSettings() {
    showAutoFollowupSettings.value = !showAutoFollowupSettings.value;
    if (showAutoFollowupSettings.value && autoFollowupSettings.value.accounts.length === 0) {
        loadAutoFollowupSettings();
    }
}
const recipientEmailSaving = ref(false);
const recipientEmailSaved = ref(false);   // kurzes grünes Häkchen nach Save

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
    } catch(e) { toast("Fehler: " + e.message); }
    recipientEmailSaving.value = false;
}
const followupDetailLevel = ref(localStorage.getItem('sr-followup-detail-level') || 'standard');
const followupBodyFull = ref(false);

// Sub-tab navigation
const activeSubTab = ref('unanswered');

// Snooze state
const snoozeOpenId = ref(null);
const snoozeOptions = [
    { label: '1 Tag', days: 1 },
    { label: '3 Tage', days: 3 },
    { label: '7 Tage', days: 7 },
    { label: '14 Tage', days: 14 },
];

// KI-Hinweise
const alerts = ref([]);
const alertsLoading = ref(false);

// === NACHFASS-WIZARD ===
const wizardOpen = ref(false);
const bulkMode = ref(false);
const bulkDrafts = ref({});
const bulkDraftLoading = ref({});
const wizardItems = ref([]);
const wizardIndex = ref(0);
const wizardDraftLoading = ref(false);
const wizardDraft = ref(null); // { preferred_action, call_script, email_subject, email_body }
const wizardPhone = ref(null);
const wizardEmail = ref(null);
const wizardEditBody = ref("");
const wizardEditSubject = ref("");
const wizardSending = ref(false);
const wizardCompleted = ref([]);
const wizardOriginalBody = ref("");
const wizardThread = ref([]); // recent conversation messages

const wizardCurrent = computed(() => wizardItems.value[wizardIndex.value] || null);
const wizardProgress = computed(() => wizardItems.value.length ? Math.round(((wizardIndex.value) / wizardItems.value.length) * 100) : 0);

let wizardAbort = null;

async function startBulkNachfassen() {
    // Simple toggle: expand all followups into quick-action mode
    bulkMode.value = !bulkMode.value;
}

async function loadWizardDraft(idx) {
    const item = wizardItems.value[idx];
    if (!item) return;
    if (wizardAbort) { try { wizardAbort.abort(); } catch {} }

    // Instantly show contact info from already-loaded data
    wizardDraftLoading.value = false;
    wizardDraft.value = null;
    wizardPhone.value = item.contact_phone || null;
    wizardEmail.value = item.contact_email || null;
    wizardThread.value = item.recent_messages || [];

    // Pre-fill a simple template (no AI call — user clicks button for AI)
    const firstName = (item.from_name || "").split(" ")[0] || "Interessent/in";
    const propAddr = (item.address || "") + (item.city ? ", " + item.city : "");
    wizardEditSubject.value = "Re: " + (item.ref_id || item.subject || "Anfrage");
    wizardEditBody.value = "Guten Tag " + (item.from_name?.includes(" ") ? (item.from_name.split(" ")[1].startsWith("G") || item.from_name.split(" ")[1].startsWith("g") ? "Frau " : "Herr ") + item.from_name.split(" ").slice(-1)[0] : firstName) + ",\n\nvielen Dank für Ihr Interesse an unserem Objekt " + (item.ref_id || "") + " in " + propAddr + ".\n\n\n\nBeste Grüße";
    wizardOriginalBody.value = "";

    // Try to get email from portal_emails if not available
    if (!wizardEmail.value && item.from_name) {
        try {
            const r = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(item.from_name) + "&property_id=" + item.property_id);
            const d = await r.json();
            if (d.email) wizardEmail.value = d.email;
            if (d.phone && !wizardPhone.value) wizardPhone.value = d.phone;
            if (d.thread && d.thread.length) wizardThread.value = d.thread;
        } catch {}
    }
}

async function wizardGenerateAiDraft() {
    const item = wizardCurrent.value;
    if (!item) return;
    wizardDraftLoading.value = true;
    wizardAbort = new AbortController();
    const signal = wizardAbort.signal;
    try {
        const r = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(item.from_name) + "&property_id=" + item.property_id, { signal });
        if (signal.aborted) return;
        const d = await r.json();
        wizardDraft.value = d.draft || null;
        if (d.email && !wizardEmail.value) wizardEmail.value = d.email;
        if (d.phone && !wizardPhone.value) wizardPhone.value = d.phone;
        if (d.thread && d.thread.length) wizardThread.value = d.thread;
        if (wizardDraft.value) {
            wizardEditBody.value = wizardDraft.value.email_body || wizardEditBody.value;
            wizardEditSubject.value = wizardDraft.value.email_subject || wizardEditSubject.value;
            wizardOriginalBody.value = wizardDraft.value.email_body || "";
        }
    } catch (e) {
        if (e.name !== 'AbortError') toast("KI-Fehler: " + e.message);
    }
    if (!signal.aborted) wizardDraftLoading.value = false;
}

async function wizardSendEmail() {
    const item = wizardCurrent.value;
    if (!item || !wizardEmail.value || !wizardEditBody.value) { toast("E-Mail oder Text fehlt"); return; }
    wizardSending.value = true;

    // Save AI feedback if text was modified
    if (wizardOriginalBody.value && wizardEditBody.value !== wizardOriginalBody.value) {
        try {
            await fetch(API.value + "&action=save_ai_feedback", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    original_text: wizardOriginalBody.value,
                    edited_text: wizardEditBody.value,
                    context_type: "followup_wizard",
                    stakeholder: item.from_name,
                    property_id: item.property_id,
                }),
            });
        } catch {}
    }

    // Compose prefill and switch to comms
    const composeData = {
        to: wizardEmail.value,
        subject: wizardEditSubject.value,
        stakeholder: item.from_name,
        propertyId: item.property_id,
        sourceId: String(item.id),
        body: wizardEditBody.value,
    };
    sessionStorage.setItem("sr-compose-prefill", JSON.stringify(composeData));

    wizardCompleted.value.push(wizardIndex.value);
    wizardSending.value = false;

    // Move to next or close
    if (wizardIndex.value < wizardItems.value.length - 1) {
        wizardIndex.value++;
        await loadWizardDraft(wizardIndex.value);
    } else {
        wizardOpen.value = false;
        switchTab("comms");
    }
}

async function wizardDirectSend() {
    const item = wizardCurrent.value;
    if (!item || !wizardEmail.value || !wizardEditBody.value) { toast("E-Mail oder Text fehlt"); return; }
    wizardSending.value = true;

    // Save AI feedback
    if (wizardOriginalBody.value && wizardEditBody.value !== wizardOriginalBody.value) {
        try {
            await fetch(API.value + "&action=save_ai_feedback", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    original_text: wizardOriginalBody.value,
                    edited_text: wizardEditBody.value,
                    context_type: "followup_wizard",
                    stakeholder: item.from_name,
                    property_id: item.property_id,
                }),
            });
        } catch {}
    }

    // Send email directly
    try {
        let sig = "\n\n--\nSR-Homes Immobilien GmbH";
        try { const _sr = await fetch(API.value + "&action=get_settings"); const _sd = await _sr.json(); if (_sd.signature_name) sig = "\n\n--\n" + (_sd.signature_name||"")+"\n"+(_sd.signature_title||"")+"\n"+(_sd.signature_company||"")+"\nTel: "+(_sd.signature_phone||"")+"\n"+(_sd.signature_website||""); } catch {}
        const htmlBody = (wizardEditBody.value + sig).replace(/\n/g, "<br>");
        const fd = new FormData();
        let wAccId = "1";
        try { const wr = await fetch(API.value + "&action=email_accounts"); const wd = await wr.json(); if (wd.accounts && wd.accounts.length) wAccId = String(wd.accounts[0].id); } catch {}
        fd.append("account_id", wAccId);
        fd.append("to_email", wizardEmail.value);
        fd.append("to_name", item.from_name || "");
        fd.append("subject", wizardEditSubject.value);
        fd.append("body_html", htmlBody);
        fd.append("body_text", wizardEditBody.value + sig);
        fd.append("property_id", item.property_id || "");
        fd.append("in_reply_to", String(item.id) || "");
        fd.append("is_followup", "1");
        const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
        const result = await r.json();
        if (result.success) {
            toast("Email an " + item.from_name + " gesendet!");
            wizardCompleted.value.push(wizardIndex.value);
        } else {
            toast("Fehler: " + (result.error || "Unbekannt"));
        }
    } catch (e) { toast("Sende-Fehler: " + e.message); }

    wizardSending.value = false;
    if (wizardIndex.value < wizardItems.value.length - 1) {
        wizardIndex.value++;
        await loadWizardDraft(wizardIndex.value);
    } else {
        wizardOpen.value = false;
        loadFollowups(followupFilter.value);
    }
}

async function wizardMarkCalled() {
    const item = wizardCurrent.value;
    if (!item) return;
    wizardSending.value = true;
    try {
        await fetch(API.value + "&action=mark_called", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ stakeholder: item.from_name, property_id: item.property_id, note: "Telefonisch nachgefasst" }),
        });
        toast("Anruf bei " + item.from_name + " vermerkt!");
        wizardCompleted.value.push(wizardIndex.value);
    } catch (e) { toast("Fehler: " + e.message); }
    wizardSending.value = false;
    if (wizardIndex.value < wizardItems.value.length - 1) {
        wizardIndex.value++;
        await loadWizardDraft(wizardIndex.value);
    } else {
        wizardOpen.value = false;
        loadFollowups(followupFilter.value);
    }
}

function wizardSkip() {
    if (wizardIndex.value < wizardItems.value.length - 1) {
        wizardIndex.value++;
        loadWizardDraft(wizardIndex.value);
    } else {
        wizardOpen.value = false;
        loadFollowups(followupFilter.value);
    }
}

function wizardBack() {
    if (wizardIndex.value > 0) {
        wizardIndex.value--;
        loadWizardDraft(wizardIndex.value);
    }
}

function wizardClose() {
    if (wizardAbort) { try { wizardAbort.abort(); } catch {} }
    wizardOpen.value = false;
    if (wizardCompleted.value.length) {
        loadFollowups(followupFilter.value);
        loadUnanswered(unansweredFilter.value);
    }
}

const subTabs = computed(() => [
    { key: 'unanswered', label: 'Unbeantwortete', count: unansweredCount.value },
    { key: 'followups', label: 'Nachfassen', count: (followupCount.value || 0) + (stage1Count.value || 0) },
    { key: 'matching', label: 'KI-Empfehlungen', count: crossMatches.value.length },
    { key: 'insights', label: 'KI-Hinweise', count: alerts.value.length },
    
    { key: 'onhold', label: 'Pausiert', count: onHoldList.value.length },
]);

onMounted(() => {
    loadUnanswered("all");
    loadFollowups("all");
    loadStage1();
    loadCrossMatches();
    loadAlerts();
    loadKanban();
    loadAutoReplyLogs();
    loadAutoReplySettings();
});

function switchSubTab(tab) {
    activeSubTab.value = tab;
    if (tab === 'insights' && !alerts.value.length) {
        loadAlerts();
    }
    if (tab === 'kanban' && !kanbanItems.value.length) {
        loadKanban();
    }
    if (tab === 'matching') {
        loadCrossMatches();
    }
}

async function loadUnanswered(filter) {
    unansweredFilter.value = filter;
    unansweredLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=followups&mode=unanswered&filter=" + filter);
        const d = await r.json();
        unansweredList.value = d.followups || [];
        unmatchedList.value = d.unmatched || [];
        onHoldUnansweredList.value = d.on_hold_unanswered || [];
        unansweredCount.value = (d.total_open || 0) + (d.total_unmatched || 0);
    } catch (e) { toast("Fehler: " + e.message); }
    unansweredLoading.value = false;
    
    // Pre-generate AI drafts in background for items without one
    prefetchDrafts(unansweredList.value);
}

async function loadFollowups(filter) {
    followupFilter.value = filter;
    followupLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=followups&mode=followup&filter=" + filter);
        followupData.value = await r.json();
        followupCount.value = followupData.value.total_followup || 0;
        onHoldList.value = followupData.value.on_hold || [];
    } catch (e) { toast("Fehler: " + e.message); }
    followupLoading.value = false;

    // Pre-fetch AI drafts for all followup items
    const allFollowups = [
        ...(followupData.value?.followups || []),
        ...(followupData.value?.stage1_followups || []),
    ];
    prefetchFollowupDrafts(allFollowups);
}

// Pre-fetch followup drafts in background
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

// Pre-fetch AI drafts for all items that don't have one yet (runs in background)
async function prefetchDrafts(items) {
    const needDraft = items.filter(i => !i.draft || !i.draft.body);
    if (!needDraft.length) return;
    
    // Generate drafts in parallel (max 3 concurrent)
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

async function loadRecommendation(f) {
    f._recLoading = true;
    try {
        const r = await fetch(API.value + "&action=followup_recommendation&stakeholder=" + encodeURIComponent(f.from_name) + "&property_id=" + f.property_id);
        const d = await r.json();
        f._recommendation = d.recommendation || "Keine Empfehlung verfugbar.";
    } catch (e) { toast("KI-Fehler: " + e.message); }
    f._recLoading = false;
}

async function markHandled(stakeholder, propertyId) {
    try {
        const r = await fetch(API.value + "&action=mark_handled", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ stakeholder, property_id: propertyId, note: "Bereits beantwortet (extern/Kalender/Telefon)" }),
        });
        const d = await r.json();
        if (d.success) { toast("✓ Als erledigt markiert!"); expandedFollowup.value = null; loadFollowups(followupFilter.value); loadUnanswered(unansweredFilter.value); loadStage1(); refreshCounts(); }
        else { toast("Fehler: " + (d.error || "Unbekannt")); }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function nachfassen(f) {
    if (!f._recommendation) await loadRecommendation(f);
    const composeData = {
        to: f.from_email || f.contact_email || '',
        subject: 'Re: ' + (f.subject || f.activity || f.ref_id || ''),
        stakeholder: f.from_name || f.stakeholder || '',
        propertyId: f.property_id || null,
        sourceId: f.id ? String(f.id) : '',
        body: '',
    };
    sessionStorage.setItem('sr-compose-prefill', JSON.stringify(composeData));
    switchTab("comms");
}



function setFollowupDetailLevel(level) {
    followupDetailLevel.value = level;
    localStorage.setItem("sr-followup-detail-level", level);
    if (expandedFollowup.value) {
        const items = followupData.value?.followups || [];
        const item = items.find(i => i.id === expandedFollowup.value);
        if (item) regenerateFollowupDraft(item);
    }
}

async function toggleFollowupDetail(f) {
    showFollowupThread.value = false;
    showFollowupEmailFields.value = false;
    expandedFollowupItem.value = f;
    if (expandedFollowup.value === f.id) {
        expandedFollowup.value = null;
        followupDetail.value = null;
        followupAiDraft.value = null;
        return;
    }
    expandedFollowup.value = f.id;
    followupDetail.value = null;
    followupAiDraft.value = null;
    followupBodyFull.value = false;
    followupDetailLoading.value = true;
    followupAiLoading.value = true;

    // Load thread context
    const ctxPromise = fetch(API.value + "&action=email_context&email_id=" + f.id + "&type=activity")
        .then(r => r.json())
        .then(d => { followupDetail.value = { email: d.email || null, thread: d.thread || [] }; })
        .catch(() => {})
        .finally(() => { followupDetailLoading.value = false; });

    // Use embedded draft from server, prefetched draft, or fetch on-demand
    if (f._prefetchedDraft) {
        followupAiDraft.value = f._prefetchedDraft;
        followupAiLoading.value = false;
        await ctxPromise;
    } else if (f.draft && f.draft.body) {
        // Server-embedded draft from index() — instant, no API call
        followupAiDraft.value = {
            body: f.draft.body || "",
            subject: f.draft.subject || ("Re: " + (f.subject || f.activity || "")),
            to: f.draft.to || f.from_email || f.contact_email || "",
            phone: f.contact_phone || "",
            callScript: f.draft.call_script || null,
            preferredAction: f.draft.preferred_action || "email",
            leadPhase: f.draft.lead_phase || null,
            mailType: f.draft.mail_type || null,
            leadStatus: f.draft.lead_status || null,
            mailGoal: f.draft.mail_goal || null,
        };
        followupAiLoading.value = false;
        await ctxPromise;
    } else {
        const draftPromise = fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(f.from_name) + "&property_id=" + f.property_id)
            .then(r => r.json())
            .then(d => {
                if (d.draft) {
                    followupAiDraft.value = {
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
            .finally(() => { followupAiLoading.value = false; });

        await Promise.all([ctxPromise, draftPromise]);
    }
}

async function regenerateFollowupDraft(f) {
    followupAiDraft.value = null;
    followupAiLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(f.from_name) + "&property_id=" + f.property_id);
        const d = await r.json();
        if (d.draft) {
            followupAiDraft.value = {
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
    } catch (e) { toast("KI-Fehler: " + e.message); }
    followupAiLoading.value = false;
}

async function useFollowupDraft(f) {
    const draft = followupAiDraft.value;
    if (!draft) return;
    const composeData = {
        to: draft.to || f.from_email || f.contact_email || "",
        subject: draft.subject || "",
        stakeholder: f.from_name || "",
        propertyId: f.property_id || null,
        sourceId: f.id ? String(f.id) : "",
        sourceType: "activity",
        body: draft.body || "",
    };
    sessionStorage.setItem("sr-compose-prefill", JSON.stringify(composeData));
    switchTab("comms");
}

async function loadStage1() {
    stage1Loading.value = true;
    try {
        const r = await fetch(API.value + "&action=followups_stage1");
        const d = await r.json();
        stage1Followups.value = d.followups || [];
        stage1Count.value = d.total_stage1 || stage1Followups.value.length;
    } catch(e) { toast("Stage-1 Fehler: " + e.message); }
    stage1Loading.value = false;
}

async function toggleStage1Detail(f) {
    if (expandedStage1.value === f.id) {
        expandedStage1.value = null;
        stage1AiDraft.value = null;
        stage1Detail.value = null;
        stage1ThreadMsg.value = null;
        return;
    }
    expandedStage1.value = f.id;
    stage1AiDraft.value = null;
    stage1Detail.value = null;
    stage1ThreadMsg.value = null;
    showStage1EmailFields.value = false;
    showStage1Thread.value = false;
    stage1AiLoading.value = true;
    stage1DetailLoading.value = true;

    // Load email context (thread)
    const ctxPromise = fetch(API.value + "&action=email_context&email_id=" + f.id + "&type=activity")
        .then(r => r.json())
        .then(d => { stage1Detail.value = { email: d.email || null, thread: d.thread || [] }; })
        .catch(() => {})
        .finally(() => { stage1DetailLoading.value = false; });

    // Load AI draft
    const draftPromise = fetch(API.value + "&action=followup_draft_staged&followup_stage=1&stakeholder=" + encodeURIComponent(f.from_name) + "&property_id=" + f.property_id)
        .then(r => r.json())
        .then(d => {
            if (d.draft) {
                stage1AiDraft.value = {
                    body: d.draft.email_body || "",
                    subject: d.draft.email_subject || ("Nachfrage: " + (f.subject || "")),
                    to: d.email || f.from_email || f.contact_email || "",
                    phone: d.phone || f.contact_phone || "",
                };
            }
        })
        .catch(e => { toast("KI-Fehler: " + e.message); })
        .finally(() => { stage1AiLoading.value = false; });

    await Promise.all([ctxPromise, draftPromise]);
}

async function sendStage1Draft(f) {
    const draft = stage1AiDraft.value;
    if (!draft || stage1Sending.value) return;
    stage1Sending.value = true;
    expandedStage1.value = null;
    stage1AiDraft.value = null;
    const recipientName = f.from_name || "Kontakt";
    const toastsContainer = document.querySelector(".fixed.bottom-4.right-4");
    let sendingEl = null;
    if (toastsContainer) {
        sendingEl = document.createElement("div");
        sendingEl.className = "toast-notification";
        sendingEl.style.cssText = "background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border:none";
        sendingEl.innerHTML = '<span>⚡ Sende 24h Nachfass-Mail an ' + recipientName + '...</span>';
        toastsContainer.appendChild(sendingEl);
    }
    try {
        let sigText = "";
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
                sh += '</td></tr></table>';
                sigHtml = sh;
            }
        } catch {}
        const htmlBody = draft.body.replace(/\n/g, "<br>") + sigHtml;
        let accountId = "1";
        try {
            const acr = await fetch(API.value + "&action=email_accounts");
            const acd = await acr.json();
            if (acd.accounts && acd.accounts.length) accountId = String(acd.accounts[0].id);
        } catch {}
        const fd = new FormData();
        fd.append("account_id", accountId);
        fd.append("to_email", draft.to || f.from_email || f.contact_email || "");
        fd.append("to_name", recipientName);
        fd.append("subject", draft.subject || "");
        fd.append("body_html", htmlBody);
        fd.append("body_text", draft.body + sigText);
        fd.append("property_id", f.property_id || "");
        fd.append("in_reply_to", String(f.id) || "");
        fd.append("is_followup", "1");
        fd.append("followup_stage", "1");
        const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
        const result = await r.json();
        if (sendingEl) sendingEl.remove();
        if (result.success) {
            toast("✓ 24h Nachfass-Mail an " + recipientName + " gesendet!");
            loadStage1();
            loadFollowups(followupFilter.value);
            refreshCounts();
        } else {
            toast("✗ Fehler: " + (result.error || "Unbekannt"));
            loadStage1();
        }
    } catch(e) {
        if (sendingEl) sendingEl.remove();
        toast("✗ Sende-Fehler: " + e.message);
        loadStage1();
    } finally {
        stage1Sending.value = false;
    }
}

async function sendFollowupDraft(f) {
    const draft = followupAiDraft.value;
    if (!draft || followupSending.value) return;
    followupSending.value = true;

    const recipientName = f.from_name || f.stakeholder || "Kunde";
    const fId = f.id;

    // Optimistically close + remove from list
    expandedFollowup.value = null;
    followupDetail.value = null;
    followupAiDraft.value = null;

    // Show sending toast
    const toastsContainer = document.querySelector(".fixed.bottom-4.right-4");
    let sendingEl = null;
    if (toastsContainer) {
        sendingEl = document.createElement("div");
        sendingEl.className = "toast-notification";
        sendingEl.style.cssText = "background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none";
        sendingEl.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span><span>Wird gesendet an ' + recipientName + '...</span>';
        toastsContainer.appendChild(sendingEl);
    }

    try {
        // Load signature
        let sigText = "\n\n--\nMaximilian Hölzl\nKonzessionierter Immobilientreuhänder\nSR-Homes Immobilien GmbH\nTel: +43 660 9199939\nwww.sr-homes.at";
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

        const htmlBody = draft.body.replace(/\n/g, "<br>") + sigHtml;

        let accountId = "1";
        try {
            const acr = await fetch(API.value + "&action=email_accounts");
            const acd = await acr.json();
            if (acd.accounts && acd.accounts.length) accountId = String(acd.accounts[0].id);
        } catch {}

        const fd = new FormData();
        fd.append("account_id", accountId);
        fd.append("to_email", draft.to || f.from_email || f.contact_email || "");
        fd.append("to_name", recipientName);
        fd.append("subject", draft.subject || "");
        fd.append("body_html", htmlBody);
        fd.append("body_text", draft.body + sigText);
        fd.append("property_id", f.property_id || "");
        fd.append("in_reply_to", String(fId) || "");
        fd.append("is_followup", "1");

        const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
        const result = await r.json();

        if (sendingEl) sendingEl.remove();

        if (result.success) {
            toast("✓ Nachfass-Mail an " + recipientName + " gesendet!");
            loadFollowups(followupFilter.value);
            refreshCounts();
        } else {
            toast("✗ Fehler: " + (result.error || "Unbekannt"));
            loadFollowups(followupFilter.value);
        }
    } catch (e) {
        if (sendingEl) sendingEl.remove();
        toast("✗ Sende-Fehler: " + e.message);
        loadFollowups(followupFilter.value);
    } finally {
        followupSending.value = false;
    }
}

async function setOnHold(propertyId, note) {
    try {
        const r = await fetch(API.value + "&action=set_on_hold", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: propertyId, on_hold: 1, note: note || "" }),
        });
        const d = await r.json();
        if (d.success) { toast("Vermarktung pausiert"); loadFollowups(followupFilter.value); loadUnanswered(unansweredFilter.value); }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function removeOnHold(propertyId) {
    try {
        const r = await fetch(API.value + "&action=set_on_hold", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ property_id: propertyId, on_hold: 0 }),
        });
        const d = await r.json();
        if (d.success) { toast("Vermarktung fortgesetzt"); loadFollowups(followupFilter.value); loadUnanswered(unansweredFilter.value); }
    } catch (e) { toast("Fehler: " + e.message); }
}

async function snoozeFollowup(f, days) {
    snoozeOpenId.value = null;
    try {
        const r = await fetch(API.value + "&action=snooze_followup", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: f.id, days }),
        });
        const d = await r.json();
        if (d.success) {
            toast(`Snoozed für ${days} Tag${days > 1 ? 'e' : ''}`);
            loadFollowups(followupFilter.value);
        } else { toast("Fehler beim Snoozen"); }
    } catch (e) { toast("Fehler: " + e.message); }
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



function dismissAlert(alertId) {
    try {
        const raw = localStorage.getItem('dismissed_alerts');
        const data = raw ? JSON.parse(raw) : {};
        data[alertId] = Date.now();
        localStorage.setItem('dismissed_alerts', JSON.stringify(data));
    } catch {}
    alerts.value = alerts.value.filter(a => a.id !== alertId);
}

function alertActionClick(action) {
    if (action.tab) {
        switchTab(action.tab);
    } else if (action.property_id) {
        switchTab('properties');
    }
}

function formatDate(s) {
    if (!s) return "";
    if (s.includes(" ") || s.includes("T")) {
        const d = new Date(s.replace(" ", "T"));
        return d.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" }) + ", " + d.toLocaleTimeString("de-AT", { hour: "2-digit", minute: "2-digit" });
    }
    return s.split("-").reverse().join(".");
}

// Makler filter (Assistenz only)
const maklerFilter = ref('all');
const availableMakler = computed(() => {
    const names = new Set();
    [...unansweredList.value, ...(followupData.value?.followups || []), ...stage1Followups.value].forEach(i => {
        if (i.broker_name) names.add(i.broker_name);
    });
    return [...names].sort();
});

// Computed followup groups
const filteredUnansweredList = computed(() => {
    let list = unansweredCategoryFilter.value === 'all' ? unansweredList.value : unansweredList.value.filter(i => i.category === unansweredCategoryFilter.value);
    if (maklerFilter.value !== 'all') list = list.filter(i => i.broker_name === maklerFilter.value);
    return list;
});

const unansweredCategories = computed(() => {
    const cats = {};
    unansweredList.value.forEach(i => {
        const c = i.category || 'sonstiges';
        cats[c] = (cats[c] || 0) + 1;
    });
    return cats;
});

const filteredFollowups = computed(() => {
    const list = followupData.value?.followups || [];
    return maklerFilter.value === 'all' ? list : list.filter(f => f.broker_name === maklerFilter.value);
});
const filteredStage1Followups = computed(() => {
    return maklerFilter.value === 'all' ? stage1Followups.value : stage1Followups.value.filter(f => f.broker_name === maklerFilter.value);
});
const kaufanbotFollowups = computed(() => filteredFollowups.value.filter((f) => f.category === "kaufanbot"));
const urgentFollowups = computed(() => filteredFollowups.value.filter((f) => f.category !== "kaufanbot" && f.days_waiting >= 14));
const warningFollowups = computed(() => filteredFollowups.value.filter((f) => f.category !== "kaufanbot" && f.days_waiting >= 7 && f.days_waiting < 14));
const infoFollowups = computed(() => filteredFollowups.value.filter((f) => f.category !== "kaufanbot" && f.days_waiting >= 3 && f.days_waiting < 7));

// Collapsed state per group (persisted)
const collapsedGroups = ref(JSON.parse(localStorage.getItem('sr-collapsed-followup-groups') || '{}'));
function toggleGroup(key) {
    collapsedGroups.value[key] = !collapsedGroups.value[key];
    localStorage.setItem('sr-collapsed-followup-groups', JSON.stringify(collapsedGroups.value));
}

// "Alle senden" per group
const sendAllRunning = ref(null); // group key currently sending
const sendAllProgress = ref({ sent: 0, total: 0, current: '' });
const sendAllConfirm = ref(null); // group key awaiting confirmation
const sendAllAborted = ref(false); // abort flag

function requestSendAll(groupKey, items) {
    if (sendAllRunning.value || !items.length) return;
    sendAllConfirm.value = { key: groupKey, items, count: items.length };
}

async function sendAllInGroup(groupKey, items) {
    sendAllConfirm.value = null;
    if (sendAllRunning.value || !items.length) return;
    sendAllRunning.value = groupKey;
    sendAllAborted.value = false;
    sendAllProgress.value = { sent: 0, total: items.length, current: '' };

    // Pre-fetch settings + account once
    let sigText = "", sigHtml = "", accountId = "1";
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
            sh += '</td></tr></table>';
            sigHtml = sh;
        }
    } catch {}
    try {
        const acr = await fetch(API.value + "&action=email_accounts");
        const acd = await acr.json();
        if (acd.accounts && acd.accounts.length) accountId = String(acd.accounts[0].id);
    } catch {}

    let sent = 0;
    for (const f of items) {
        if (sendAllAborted.value) break;
        const name = f.from_name || f.stakeholder || "Kontakt";
        sendAllProgress.value = { sent, total: items.length, current: name };
        try {
            // Generate AI draft via correct GET API
            const isStage1 = groupKey === 'stage1';
            const draftUrl = isStage1
                ? API.value + "&action=followup_draft_staged&followup_stage=1&stakeholder=" + encodeURIComponent(name) + "&property_id=" + f.property_id
                : API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(name) + "&property_id=" + f.property_id;
            const draftR = await fetch(draftUrl);
            const draftD = await draftR.json();
            const draft = draftD.draft || {};
            const body = draft.email_body || draft.body || '';
            const subject = draft.email_subject || draft.subject || ('Nachfrage – ' + (f.ref_id || ''));
            const toEmail = draftD.email || f.contact_email || f.from_email || '';
            if (!body || !toEmail) { sent++; continue; }

            // Send
            const htmlBody = body.replace(/\n/g, "<br>") + sigHtml;
            const fd = new FormData();
            fd.append("account_id", accountId);
            fd.append("to_email", toEmail);
            fd.append("to_name", name);
            fd.append("subject", subject);
            fd.append("body_html", htmlBody);
            fd.append("body_text", body + sigText);
            fd.append("property_id", f.property_id || "");
            fd.append("in_reply_to", String(f.id) || "");
            fd.append("is_followup", "1");
            if (isStage1) fd.append("followup_stage", "1");
            const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
            const result = await r.json();
            if (result.success) {
                sent++;
                // Live counter update — decrement both the injected ref AND local stage count
                if (groupKey === 'stage1') {
                    if (typeof stage1Count.value === 'number') stage1Count.value = Math.max(0, stage1Count.value - 1);
                    // Remove from local list
                    stage1Followups.value = stage1Followups.value.filter(x => x.id !== f.id);
                } else {
                    if (followupCount && typeof followupCount.value === 'number') followupCount.value = Math.max(0, followupCount.value - 1);
                    // Remove from local list
                    if (followupData.value && followupData.value.followups) {
                        followupData.value.followups = followupData.value.followups.filter(x => x.id !== f.id);
                    }
                }
                toast("✓ " + name + " (" + sent + "/" + items.length + ")");
            } else {
                toast("✗ " + name + ": " + (result.error || "Fehler"));
            }
        } catch (e) {
            toast("✗ " + name + ": " + e.message);
        }
        sendAllProgress.value = { sent, total: items.length, current: '' };
        // Small delay between sends
        await new Promise(r => setTimeout(r, 2000));
    }
    sendAllProgress.value = { sent, total: items.length, current: '' };
    toast(sendAllAborted.value
        ? `⏹ Abgebrochen – ${sent}/${items.length} gesendet`
        : `✓ ${sent}/${items.length} Nachfass-Mails gesendet`);
    sendAllRunning.value = null;
    sendAllAborted.value = false;
    // Short delay to let the server process all activities before reloading
    await new Promise(r => setTimeout(r, 1000));
    await Promise.all([loadStage1(), loadFollowups(followupFilter.value)]);
    refreshCounts();
}

// === KAUFANBOT KANBAN ===
const kanbanLoading = ref(false);
const realKaufanbotePrio = ref([]);
const showKaufanbotePopup = ref(false);
const showAutoReplyPopup = ref(false);
const autoReplyLogs = ref([]);
const autoReplyLoading = ref(false);
const showAutoReplySettings = ref(false);
const autoReplyEnabled = ref(false);
const autoReplyText = ref('');
const autoReplyPropertyIds = ref([]);
const autoReplyToggling = ref(false);
const autoReplyAllProperties = ref([]);
const kanbanItems = ref([]);

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
        autoReplyPropertyIds.value = d.auto_reply_property_ids
            ? d.auto_reply_property_ids.split(',').map(Number).filter(Boolean) : [];
    } catch (e) {}
    // Load properties list
    if (!autoReplyAllProperties.value.length) {
        try {
            const r2 = await fetch(API.value + "&action=list_properties");
            const d2 = await r2.json();
            autoReplyAllProperties.value = d2.properties || [];
        } catch (e) {}
    }
}

async function toggleAutoReply() {
    autoReplyToggling.value = true;
    try {
        const r = await fetch(API.value + "&action=toggle_auto_reply", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                enabled: !autoReplyEnabled.value,
                auto_reply_text: autoReplyText.value || null,
                auto_reply_property_ids: autoReplyPropertyIds.value.join(',') || null,
            }),
        });
        const d = await r.json();
        if (d.success) {
            autoReplyEnabled.value = d.auto_reply_enabled;
            toast(autoReplyEnabled.value ? "Auto-Reply aktiviert!" : "Auto-Reply deaktiviert!");
        }
    } catch (e) { toast("Fehler: " + e.message); }
    autoReplyToggling.value = false;
}

async function saveAutoReplySettings() {
    try {
        const r = await fetch(API.value + "&action=toggle_auto_reply", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                enabled: autoReplyEnabled.value,
                auto_reply_text: autoReplyText.value || null,
                auto_reply_property_ids: autoReplyPropertyIds.value.join(',') || null,
            }),
        });
        const d = await r.json();
        if (d.success) toast("Auto-Reply Einstellungen gespeichert!");
    } catch (e) { toast("Fehler: " + e.message); }
}

const KANBAN_COLUMNS = [
    { key: "eingegangen",           label: "Eingegangen",         color: "#3b82f6" },
    { key: "eigentuemer_informiert",label: "Eigentümer informiert",color: "#f59e0b" },
    { key: "in_verhandlung",        label: "In Verhandlung",      color: "#D4622B" },
    { key: "finanzierung_pruefen",  label: "Finanzierung prüfen", color: "#06b6d4" },
    { key: "akzeptiert",            label: "Akzeptiert",          color: "#10b981" },
    { key: "abgelehnt",             label: "Abgelehnt",           color: "#ef4444" },
];

function kanbanColumn(key) {
    if (key === "eingegangen") {
        return kanbanItems.value.filter((i) => !i.kaufanbot_status || i.kaufanbot_status === "eingegangen");
    }
    return kanbanItems.value.filter((i) => i.kaufanbot_status === key);
}

async function loadKanban() {
    kanbanLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=list_kaufanbote");
        const d = await r.json();
        kanbanItems.value = d.kaufanbote || [];
    } catch (e) { toast("Fehler: " + e.message); }
    // Also load real uploaded PDFs
    try {
        const r2 = await fetch(API.value + "&action=get_kaufanbot_pdfs");
        const d2 = await r2.json();
        realKaufanbotePrio.value = d2.kaufanbote || [];
    } catch {}
    kanbanLoading.value = false;
}

const kaufanboteByProperty = computed(() => {
    const groups = {};
    for (const ka of realKaufanbotePrio.value) {
        const key = ka.property_address || 'Unbekannt';
        if (!groups[key]) groups[key] = { address: key, property_id: ka.property_id, items: [] };
        groups[key].items.push(ka);
    }
    return Object.values(groups);
});

async function deleteKanban(item) {
    if (!confirm("Kaufanbot von " + item.stakeholder + " wirklich entfernen?")) return;
    try {
        await fetch(API.value + "&action=delete_kaufanbot", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ activity_id: item.id })
        });
        kanbanItems.value = kanbanItems.value.filter(i => i.id !== item.id);
        toast("Kaufanbot entfernt");
    } catch (e) { toast("Fehler: " + e.message); }
}

async function moveKanban(item, status) {
    try {
        await fetch(API.value + "&action=update_kaufanbot_status", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: item.id, status }),
        });
        item.kaufanbot_status = status;
        toast("Status aktualisiert");
    } catch (e) { toast("Fehler: " + e.message); }
}

// === CROSS-PROPERTY MATCHING (KI-Empfehlungen) ===
const crossMatches = ref([]);
const matchesLoading = ref(false);
const matchAccepting = ref(null);
const exposePreview = ref(null); // { contactName, suggestion, thread, loading, email, subject, body, sending }
const expandedContact = ref(null);

const groupedByContact = computed(() => {
    const groups = {};
    for (const m of crossMatches.value) {
        const key = m.contact_name;
        if (!groups[key]) {
            groups[key] = { name: m.contact_name, original: m.original_property, last_contact: m.last_contact, suggestions: [] };
        }
        groups[key].suggestions.push({ property: m.suggested_property, reasons: m.match_reasons || [], original_property: m.original_property });
    }
    return Object.values(groups).sort((a, b) => (b.last_contact || '').localeCompare(a.last_contact || ''));
});

async function loadCrossMatches() {
    matchesLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=cross_property_matches");
        const d = await r.json();
        crossMatches.value = d.matches || [];
    } catch (e) { /* silent */ }
    matchesLoading.value = false;
}

async function sendExposeEmail(contactName, suggestion) {
    const propAddr = suggestion.property.address + (suggestion.property.city ? ', ' + suggestion.property.city : '');
    const reasons = suggestion.reasons.join(', ');
    const origAddr = suggestion.original_property?.address || '';
    
    exposePreview.value = {
        contactName,
        suggestion,
        thread: [],
        loading: true,
        sending: false,
        email: '',
        subject: 'Neuer Objektvorschlag: ' + propAddr,
        body: "Guten Tag,\n\nSie haben sich kürzlich für unser Objekt " + origAddr + " interessiert.\n\nDa Sie ähnliche Kriterien suchen (" + reasons + "), möchten wir Ihnen gerne ein weiteres Objekt vorstellen:\n\n" + propAddr + " (" + suggestion.property.ref_id + ")" + (suggestion.property.purchase_price ? " — € " + Number(suggestion.property.purchase_price).toLocaleString('de-AT') : '') + "\n\nIm Anhang finden Sie das Exposé. Bei Interesse vereinbaren wir gerne einen Besichtigungstermin.\n\nBeste Grüße",
    };
    // Load conversation history + email
    try {
        const origPropId = suggestion.original_property?.id || '';
        const r = await fetch(API.value + "&action=email_history&search=" + encodeURIComponent(contactName) + "&property_id=" + origPropId + "&per_page=5");
        const d = await r.json();
        exposePreview.value.thread = (d.emails || []).map(e => ({
            date: e.email_date,
            direction: e.direction === 'outbound' ? 'out' : 'in',
            subject: e.subject || '',
            body: e.body_text || e.ai_summary || '',
            from_email: e.from_email || '',
        }));
        // Get email from inbound messages
        const isUsable = (em) => em && !/(noreply|no-reply|mailer|notification|system|info@willhaben|info@immowelt)/i.test(em) && !em.includes('sr-homes');
        const inbound = (d.emails || []).find(e => e.direction !== 'outbound' && isUsable(e.from_email));
        if (inbound) exposePreview.value.email = inbound.from_email;
    } catch {}
    // Fallback: try contacts table
    if (!exposePreview.value.email) {
        try {
            const r2 = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(contactName) + "&property_id=" + (suggestion.original_property?.id || ''));
            const d2 = await r2.json();
            if (d2.email) exposePreview.value.email = d2.email;
        } catch {}
    }
    exposePreview.value.loading = false;
}

async function exposeSend() {
    if (!exposePreview.value || !exposePreview.value.email || !exposePreview.value.body) { toast("E-Mail oder Text fehlt"); return; }
    exposePreview.value.sending = true;
    try {
        let sig = "\n\n--\nSR-Homes Immobilien GmbH";
        try { const _sr = await fetch(API.value + "&action=get_settings"); const _sd = await _sr.json(); if (_sd.signature_name) sig = "\n\n--\n" + (_sd.signature_name||"")+"\n"+(_sd.signature_title||"")+"\n"+(_sd.signature_company||"")+"\nTel: "+(_sd.signature_phone||"")+"\n"+(_sd.signature_website||""); } catch {}
        const htmlBody = (exposePreview.value.body + sig).replace(/\n/g, "<br>");
        const fd = new FormData();
        let eAccId = "1";
        try { const er = await fetch(API.value + "&action=email_accounts"); const ed = await er.json(); if (ed.accounts && ed.accounts.length) eAccId = String(ed.accounts[0].id); } catch {}
        fd.append("account_id", eAccId);
        fd.append("to_email", exposePreview.value.email);
        fd.append("to_name", exposePreview.value.contactName);
        fd.append("subject", exposePreview.value.subject);
        fd.append("body_html", htmlBody);
        fd.append("body_text", exposePreview.value.body + sig);
        fd.append("property_id", exposePreview.value.suggestion.property.id || "");
        const r = await fetch(API.value + "&action=send_email", { method: "POST", body: fd });
        const result = await r.json();
        if (result.success) {
            toast("Exposé an " + exposePreview.value.contactName + " gesendet!");
            // Remove from matches
            const name = exposePreview.value.contactName;
            const propId = exposePreview.value.suggestion.property.id;
            crossMatches.value = crossMatches.value.filter(m => !(m.contact_name === name && m.suggested_property.id === propId));
            exposePreview.value = null;
        } else { toast("Fehler: " + (result.error || "Unbekannt")); }
    } catch (e) { toast("Sende-Fehler: " + e.message); }
    if (exposePreview.value) exposePreview.value.sending = false;
}

async function loadBulkDraft(f) {
    const key = f.from_name + '|' + f.property_id;
    if (bulkDrafts.value[key]) return; // already loaded
    bulkDraftLoading.value = { ...bulkDraftLoading.value, [key]: true };
    try {
        const r = await fetch(API.value + "&action=followup_draft&stakeholder=" + encodeURIComponent(f.from_name) + "&property_id=" + f.property_id);
        const d = await r.json();
        bulkDrafts.value = { ...bulkDrafts.value, [key]: {
            email: d.email || f.from_email || '',
            phone: d.phone || f.contact_phone || '',
            body: d.draft?.email_body || '',
            subject: d.draft?.email_subject || ('Re: ' + (f.subject || f.ref_id || 'Anfrage')),
        }};
    } catch (e) {
        bulkDrafts.value = { ...bulkDrafts.value, [key]: { email: f.from_email || '', phone: '', body: '', subject: '' }};
    }
    bulkDraftLoading.value = { ...bulkDraftLoading.value, [key]: false };
}

async function quickSend(f) {
    const key = f.from_name + '|' + f.property_id;
    const draft = bulkDrafts.value[key];
    if (!draft || !draft.email) { toast("Keine E-Mail-Adresse"); return; }
    const composeData = {
        to: draft.email,
        subject: draft.subject,
        stakeholder: f.from_name,
        propertyId: f.property_id,
        sourceId: String(f.id),
        body: draft.body,
    };
    sessionStorage.setItem('sr-compose-prefill', JSON.stringify(composeData));
    switchTab('comms');
}

function formatKanbanDate(s) {
    if (!s) return "";
    return new Date(s).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" });
}
</script>


<template>
    <div class="px-3 sm:px-4 py-4 sm:py-6 space-y-4">
        <!-- Summary strip - horizontal scroll on mobile -->
        <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1 snap-x" style="scrollbar-width:none;-webkit-overflow-scrolling:touch">
            <div v-for="tab in [
                { key: 'unanswered', icon: 'MailX', count: unansweredCount || 0, label: 'Offen', color: '#ef4444', bg: 'rgba(239,68,68,0.06)' },
                { key: 'followups', icon: 'Clock', count: (followupCount || 0) + (stage1Count || 0), label: 'Nachfassen', color: '#D4622B', bg: 'rgba(212,98,43,0.06)' },
                { key: 'insights', icon: 'Sparkles', count: alerts.length, label: 'Hinweise', color: '#3b82f6', bg: 'rgba(59,130,246,0.06)' },
                { key: 'matching', icon: 'Home', count: groupedByContact.length, label: 'Matches', color: '#D4622B', bg: 'rgba(212,98,43,0.06)' },
                { key: 'auto', icon: 'Send', count: autoReplyLogs.length, label: 'Auto', color: '#10b981', bg: 'rgba(16,185,129,0.06)' },
                { key: 'angebote', icon: 'KanbanSquare', count: realKaufanbotePrio.length || kanbanItems.length, label: 'Angebote', color: '#D4622B', bg: 'rgba(212,98,43,0.06)' },
                { key: 'onhold', icon: 'Pause', count: onHoldList.length, label: 'Pause', color: '#71717a', bg: 'rgba(113,113,122,0.06)' },
            ]" :key="tab.key"
                @click="tab.key === 'auto' ? (loadAutoReplyLogs(), showAutoReplyPopup = true) : tab.key === 'angebote' ? (realKaufanbotePrio.length ? null : loadKanban(), showKaufanbotePopup = true) : switchSubTab(tab.key)"
                class="snap-start flex items-center gap-2.5 px-4 py-3 rounded-2xl cursor-pointer transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] flex-shrink-0 min-w-0 active:scale-[0.97]"
                :style="(activeSubTab === tab.key && !['auto','angebote'].includes(tab.key))
                    ? 'background:' + tab.bg + ';border:1.5px solid ' + tab.color + '33;box-shadow:0 2px 8px ' + tab.color + '15'
                    : 'background:rgba(244,244,245,0.8);border:1.5px solid transparent'">
                <component :is="{MailX,Clock,Sparkles,Home,Send,KanbanSquare,Pause}[tab.icon]" class="w-5 h-5 flex-shrink-0" :style="'color:' + tab.color" />
                <div>
                    <div class="text-lg font-bold leading-none tabular-nums">{{ tab.count }}</div>
                    <div class="text-[11px] text-zinc-500 mt-0.5">{{ tab.label }}</div>
                </div>
            </div>
        </div>

        <!-- Auto-Nachfassen Settings Panel (only in Nachfassen tab) -->
        <div v-if="activeSubTab === 'followups'" class="relative">
            <div class="flex items-center justify-end mb-1">
                <button @click="toggleAutoFollowupSettings()"
                    class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    :style="showAutoFollowupSettings ? 'background:var(--brand);color:#fff' : 'background:rgba(244,244,245,0.8);color:#71717a'"
                    title="Auto-Nachfassen Einstellungen">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Auto-Nachfassen
                    <span v-if="autoFollowupSettings.stage1_enabled || autoFollowupSettings.stage2_enabled"
                        class="inline-block w-2 h-2 rounded-full bg-emerald-400" title="Auto-Modus aktiv"></span>
                </button>
            </div>

            <transition name="slide-down">
            <div v-if="showAutoFollowupSettings"
                class="bg-white rounded-2xl overflow-hidden mb-3 border border-zinc-200/80 overflow-hidden">
                <div class="px-4 py-3 flex items-center justify-between border-b border-zinc-200/80"
                    style="background:rgba(244,244,245,0.8)">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--brand)">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span class="text-sm font-semibold">Auto-Nachfassen Einstellungen</span>
                        <span v-if="autoFollowupSettings.stage1_enabled || autoFollowupSettings.stage2_enabled"
                            class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-full font-medium"
                            style="background:rgba(16,185,129,0.15);color:#10b981">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                            Auto aktiv
                        </span>
                    </div>
                    <button @click="showAutoFollowupSettings = false" class="text-zinc-500 hover:text-zinc-900">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div v-if="autoFollowupLoading" class="px-4 py-6 text-center text-sm text-zinc-500">
                    Lade Einstellungen...
                </div>

                <div v-else class="px-4 py-4 space-y-4">
                    <!-- Email-Konto Auswahl -->
                    <div>
                        <label class="text-xs font-medium text-zinc-500 block mb-1.5">Email-Konto fuer automatischen Versand</label>
                        <select v-model="autoFollowupSettings.account_id"
                            class="w-full text-sm rounded-lg px-3 py-2"
                            style="background:rgba(244,244,245,0.8);color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                            <option :value="0" disabled>-- Konto waehlen --</option>
                            <option v-for="acc in autoFollowupSettings.accounts" :key="acc.id" :value="acc.id">
                                {{ acc.from_name ? acc.from_name + ' <' + acc.email_address + '>' : acc.email_address }}
                            </option>
                        </select>
                    </div>

                    <!-- Stage 1 Toggle -->
                    <div class="flex items-center justify-between py-3 px-3 rounded-lg" style="background:rgba(244,244,245,0.8)">
                        <div>
                            <div class="text-sm font-medium">Stage 1 – 24h nach Expose automatisch senden</div>
                            <div class="text-xs text-zinc-500 mt-0.5">Sendet eine kurze Nachfrage, ob das Expose angekommen ist</div>
                        </div>
                        <button @click="autoFollowupSettings.stage1_enabled = !autoFollowupSettings.stage1_enabled"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0 ml-3"
                            :style="autoFollowupSettings.stage1_enabled ? 'background:#10b981' : 'background:var(--border)'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                :style="autoFollowupSettings.stage1_enabled ? 'transform:translateX(20px)' : 'transform:translateX(3px)'"></span>
                        </button>
                    </div>

                    <!-- Stage 2 Toggle -->
                    <div class="flex items-center justify-between py-3 px-3 rounded-lg" style="background:rgba(244,244,245,0.8)">
                        <div>
                            <div class="text-sm font-medium">Stage 2 – 3 Tage Nachfassen automatisch senden</div>
                            <div class="text-xs text-zinc-500 mt-0.5">Bietet nach 3+ Tagen Funkstille konkret einen Besichtigungstermin an</div>
                        </div>
                        <button @click="autoFollowupSettings.stage2_enabled = !autoFollowupSettings.stage2_enabled"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0 ml-3"
                            :style="autoFollowupSettings.stage2_enabled ? 'background:#10b981' : 'background:var(--border)'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                :style="autoFollowupSettings.stage2_enabled ? 'transform:translateX(20px)' : 'transform:translateX(3px)'"></span>
                        </button>
                    </div>

                    <!-- Hinweistext -->
                    <p class="text-xs text-zinc-500 flex items-start gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Bei aktivierten Stufen werden KI-Nachfass-Mails automatisch alle 2 Stunden generiert und versendet – ohne manuelle Aktion.
                    </p>

                    <!-- Speichern -->
                    <div class="flex justify-end pt-1">
                        <button @click="saveAutoFollowupSettings()"
                            :disabled="autoFollowupSaving || autoFollowupSettings.account_id === 0"
                            class="px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97] btn-sm flex items-center gap-1.5"
                            :class="{ 'opacity-50 cursor-not-allowed': autoFollowupSaving || autoFollowupSettings.account_id === 0 }">
                            <svg v-if="autoFollowupSaving" class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ autoFollowupSaving ? 'Speichere...' : 'Einstellungen speichern' }}
                        </button>
                    </div>
                </div>
            </div>
            </transition>
        </div>



        <!-- ============ UNBEANTWORTETE ============ -->
        <div v-if="activeSubTab === 'unanswered'">
            <!-- Makler Filter (Assistenz only) -->
            <div v-if="isAssistenz" class="flex flex-wrap items-center gap-2 mb-3 p-3 rounded-2xl" style="background:rgba(238,118,6,0.04);border:1px solid rgba(238,118,6,0.12)">
                <span class="text-[10px] font-semibold uppercase tracking-wider flex-shrink-0" style="color:#D4622B">Makler:</span>
                <button @click="maklerFilter = 'all'"
                    class="px-3 py-1.5 text-xs font-semibold rounded-full transition-all duration-150 active:scale-[0.97]"
                    :style="maklerFilter === 'all' ? 'background:#D4622B;color:#fff;box-shadow:0 2px 8px rgba(212,98,43,0.3)' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    Alle
                </button>
                <button v-for="name in availableMakler" :key="name" @click="maklerFilter = name"
                    class="px-3 py-1.5 text-xs font-semibold rounded-full transition-all duration-150 active:scale-[0.97]"
                    :style="maklerFilter === name ? 'background:#18181b;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.2)' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    {{ name }}
                </button>
            </div>
            <!-- Inner tabs: Zugeordnete / Nicht zugeordnete -->
            <div class="flex gap-1 mb-3">
                <button @click="unansweredInnerTab = 'assigned'"
                    class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97]" :style="unansweredInnerTab === 'assigned' ? 'background:#18181b;color:white' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    Zugeordnete <span class="ml-1 text-[10px] opacity-70">{{ unansweredList.length }}</span>
                </button>
                <button @click="unansweredInnerTab = 'unmatched'"
                    class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97]" :style="unansweredInnerTab === 'unmatched' ? 'background:#18181b;color:white' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    Nicht zugeordnete <span class="ml-1 text-[10px] opacity-70">{{ unmatchedList.length }}</span>
                </button>
                <button v-if="onHoldUnansweredList.length" @click="unansweredInnerTab = 'onhold'"
                    class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97]" :style="unansweredInnerTab === 'onhold' ? 'background:#18181b;color:white' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    ⏸ Pausiert <span class="ml-1 text-[10px] opacity-70">{{ onHoldUnansweredList.length }}</span>
                </button>
            </div>

            <!-- ZUGEORDNETE -->
            <div v-if="unansweredInnerTab === 'assigned'" class="bg-white rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-zinc-200/80">
                    <div class="flex items-center gap-2">
                        <MailX class="w-4 h-4 text-red-500" />
                        <span class="text-sm font-semibold">Zugeordnete Anfragen</span>
                    </div>
<!-- Time filter removed for cleaner UI -->
                </div>
                <!-- Category filter dropdown -->
                <div class="px-4 py-2 flex items-center gap-2 border-b border-zinc-200/80" style="background:rgba(244,244,245,0.8)">
                    <select v-model="unansweredCategoryFilter"
                        class="text-xs font-medium rounded-xl px-3 py-1.5 cursor-pointer bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all"
                        style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                        <option value="all">Alle ({{ unansweredList.length }})</option>
                        <option v-if="unansweredCategories['anfrage']" value="anfrage">Erstanfrage ({{ unansweredCategories['anfrage'] }})</option>
                        <option v-if="unansweredCategories['email-in']" value="email-in">Eingehend ({{ unansweredCategories['email-in'] }})</option>
                        <option v-if="unansweredCategories['besichtigung']" value="besichtigung">Besichtigung ({{ unansweredCategories['besichtigung'] }})</option>
                        <option v-if="unansweredCategories['kaufanbot']" value="kaufanbot">Kaufanbot ({{ unansweredCategories['kaufanbot'] }})</option>
                        <option v-if="unansweredCategories['absage']" value="absage">Absage ({{ unansweredCategories['absage'] }})</option>
                        <option v-if="unansweredCategories['eigentuemer']" value="eigentuemer">Eigentümer ({{ unansweredCategories['eigentuemer'] }})</option>
                        <option v-if="unansweredCategories['partner']" value="partner">Partner ({{ unansweredCategories['partner'] }})</option>
                        <option v-if="unansweredCategories['sonstiges']" value="sonstiges">Sonstiges ({{ unansweredCategories['sonstiges'] }})</option>
                    </select>
                    <button v-if="unansweredCategories['bounce']" @click="unansweredCategoryFilter = 'bounce'" class="text-[10px] px-2 py-0.5 rounded-full border transition-colors" :style="catFilterStyle('bounce', unansweredCategoryFilter === 'bounce')">Unzustellbar <span class="opacity-60">{{ unansweredCategories['bounce'] }}</span></button>
                </div>
                <div v-if="unansweredLoading" class="px-6 py-8 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                <div v-else-if="!filteredUnansweredList.length" class="px-6 py-8 text-center text-zinc-500 text-sm">Keine Anfragen in dieser Kategorie</div>
                <div v-else class="divide-y divide-[var(--border)]">
                    <template v-for="item in filteredUnansweredList" :key="item.id">
                        <div @click="toggleUnansweredDetail(item)"
                            class="px-4 py-3 hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors group cursor-pointer"
                            :class="[expandedUnanswered === item.id ? 'bg-[var(--accent)]' : '', item.category === 'bounce' ? 'border-l-2 border-fuchsia-400' : '']">
                            <!-- Top row: name + urgency dot + days -->
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" :class="item.days_waiting >= 14 ? 'bg-red-500' : item.days_waiting >= 7 ? 'bg-amber-500' : 'bg-blue-500'"></span>
                                <span class="text-sm font-semibold truncate flex-1 min-w-0">{{ item.from_name || item.stakeholder }}</span>
                                <span class="text-xs font-bold tabular-nums flex-shrink-0 px-1.5 py-0.5 rounded-lg" :class="item.days_waiting >= 14 ? 'bg-red-50 text-red-600' : item.days_waiting >= 7 ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600'">{{ item.days_waiting }}d</span>
                                <ChevronDown class="w-4 h-4 text-zinc-400 flex-shrink-0 transition-transform" :class="expandedUnanswered === item.id ? 'rotate-180' : ''" />
                            </div>
                            <!-- Bottom row: object ref + category + broker (assistenz) + actions -->
                            <div class="flex items-center gap-1.5 mt-1.5 flex-wrap" @click.stop>
                                <span @click="editingAssignment = {item, type:'prop'}" class="badge badge-muted text-[10px] cursor-pointer hover:ring-2 hover:ring-[var(--brand)]/30"><Home class="w-2.5 h-2.5 mr-0.5" />{{ item.ref_id || 'Zuweisen' }}</span>
                                <span @click="editingAssignment = {item, type:'cat'}" class="badge text-[10px] cursor-pointer hover:ring-2 hover:ring-[var(--brand)]/30" :style="catBadgeStyle(item.category)">{{ catLabel(item.category) }}</span>
                                <span v-if="isAssistenz && item.broker_name" class="text-[10px] font-medium px-2 py-0.5 rounded-full flex-shrink-0" style="background:rgba(238,118,6,0.08);color:#D4622B">{{ item.broker_name }}</span>
                                <div class="flex gap-1 ml-auto">
                                    <button @click.stop="toggleUnansweredDetail(item)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97]" style="height:26px;background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none"><Sparkles class="w-3 h-3" /><span class="hidden sm:inline">KI-Entwurf</span></button>
                                    <button @click.stop="markHandled(item.stakeholder || item.from_name, item.property_id)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-white text-zinc-600 border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="height:26px"><CheckCircle class="w-3 h-3" /><span class="hidden sm:inline">Erledigt</span></button>
                                </div>
                            </div>
                        </div>
                        <!-- Expanded detail — 2-column layout matching Nachfassen design -->
                        <div v-if="expandedUnanswered === item.id" class="exp-detail"
                             :style="item.category === 'bounce' ? 'border-left:3px solid #dc2626' : 'border-left:3px solid var(--brand, #D4622B)'">

                            <!-- Bounce Warning -->
                            <div v-if="item.category === 'bounce'" class="mx-4 mt-3 px-4 py-3 rounded-xl flex items-start gap-3" style="background:#fef2f2;border:1px solid #fca5a5">
                                <span class="text-lg flex-shrink-0">⚠️</span>
                                <div>
                                    <p class="text-sm font-semibold" style="color:#dc2626">E-Mail unzustellbar</p>
                                    <p class="text-xs mt-0.5 leading-relaxed" style="color:#86198f">{{ item.ai_summary || 'Die E-Mail konnte nicht zugestellt werden. Bitte E-Mail-Adresse prüfen.' }}</p>
                                </div>
                            </div>

                            <div v-if="expandedLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                            <div v-else-if="expandedDetail" class="grid gap-4 p-4 two-col-grid">
                                <!-- LEFT: KI Editor -->
                                <div class="space-y-2 min-w-0">
                                    <div v-if="expandedAiLoading" class="flex items-center justify-center gap-2 py-8">
                                        <span class="spinner" style="width:16px;height:16px"></span>
                                        <span class="text-sm text-zinc-500">KI-Entwurf wird generiert...</span>
                                    </div>
                                    <template v-else-if="expandedAiDraft">
                                        <!-- To + Subject (compact by default, expandable) -->
                                        <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);background:white">
                                            <div v-if="!showEmailFields" @click.stop="showEmailFields = true"
                                                class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none hover:bg-[var(--accent)] transition-colors">
                                                <Mail class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                <span class="text-sm truncate flex-1 min-w-0"><span class="text-zinc-500">An:</span> <span class="font-medium">{{ expandedAiDraft.to || 'Keine Adresse' }}</span></span>
                                                <ChevronDown class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                            </div>
                                            <template v-if="showEmailFields">
                                                <div class="flex items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border)">
                                                    <span class="text-xs text-zinc-500 flex-shrink-0 w-8">An:</span>
                                                    <input v-model="expandedAiDraft.to"
                                                        class="flex-1 text-sm bg-transparent outline-none min-w-0"
                                                        style="color:#18181b"
                                                        placeholder="E-Mail-Adresse..." />
                                                    <button @click.stop="saveRecipientEmail(expandedItem?.from_name, expandedItem?.property_id, expandedAiDraft.to)"
                                                        class="flex-shrink-0 px-2 py-1 rounded-lg text-[10px] font-medium transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                                        :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534' : 'color:#71717a'"
                                                        title="Adresse speichern">
                                                        <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                                        <Check v-else-if="recipientEmailSaved" class="w-3.5 h-3.5" style="color:#166534" />
                                                        <span v-else>Speichern</span>
                                                    </button>
                                                </div>
                                                <div class="flex items-center gap-2 px-3 py-2">
                                                    <span class="text-xs text-zinc-500 flex-shrink-0 w-8">Betr:</span>
                                                    <input v-model="expandedAiDraft.subject"
                                                        class="flex-1 text-sm font-medium bg-transparent outline-none min-w-0"
                                                        style="color:#18181b" />
                                                </div>
                                            </template>
                                        </div>

                                        <!-- Textarea -->
                                        <textarea v-model="expandedAiDraft.body"
                                            class="w-full rounded-xl p-4 text-sm leading-relaxed resize-y"
                                            style="border:1px solid rgba(228,228,231,0.8);background:white;color:#18181b;min-height:220px;font-family:inherit;outline:none"
                                            @focus="$event.target.style.borderColor='var(--brand, #D4622B)'"
                                            @blur="$event.target.style.borderColor='var(--border)'"
                                        ></textarea>

                                        <!-- ── TOOLBAR ── -->
                                        <div class="flex items-center gap-2 pt-1">
                                            <!-- Attachments -->
                                            <div v-if="expandedFiles.length || expandedFilesLoading" class="relative">
                                                <button @click.stop="showAttachPopup = !showAttachPopup"
                                                    class="h-10 sm:h-9 px-3 rounded-xl flex items-center gap-1.5 transition-colors text-xs font-medium"
                                                    :style="expandedSelectedFiles.length ? 'background:rgba(238,118,6,0.1);color:#D4622B;border:1px solid rgba(238,118,6,0.25)' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                                                    <Paperclip class="w-3.5 h-3.5" />
                                                    <span v-if="expandedSelectedFiles.length">{{ expandedSelectedFiles.length }}</span>
                                                    <span v-else class="hidden sm:inline">Anhänge</span>
                                                </button>
                                                <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0 translate-y-1" enter-to-class="opacity-100 translate-y-0"
                                                    leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
                                                    <div v-if="showAttachPopup" class="absolute bottom-full left-0 mb-2 z-50 bg-white border border-zinc-200/80 rounded-2xl shadow-xl p-3 min-w-[240px] space-y-1">
                                                        <div class="text-[11px] font-semibold text-zinc-500 mb-2">Anhänge auswählen</div>
                                                        <label v-for="ef in expandedFiles" :key="ef.id" class="flex items-center gap-2.5 cursor-pointer py-2 px-2.5 rounded-lg hover:bg-[var(--accent)] transition-colors">
                                                            <input type="checkbox" :checked="expandedSelectedFiles.includes(ef.id)" @change="toggleFileSelection(ef.id)" class="form-checkbox rounded" style="width:16px;height:16px" />
                                                            <span class="text-xs" :class="expandedSelectedFiles.includes(ef.id) ? 'font-semibold text-zinc-900' : 'text-zinc-500'">{{ ef.label }}</span>
                                                        </label>
                                                        <span v-if="expandedFilesLoading" class="spinner" style="width:12px;height:12px"></span>
                                                    </div>
                                                </Transition>
                                            </div>

                                            <!-- Detail Level -->
                                            <select v-model="aiDetailLevel" @change.stop="setAiDetailLevel(aiDetailLevel)"
                                                class="h-10 sm:h-9 text-xs font-medium rounded-xl px-2.5 cursor-pointer"
                                                style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                                                <option value="brief">Knapp</option>
                                                <option value="standard">Standard</option>
                                                <option value="detailed">Ausführlich</option>
                                            </select>

                                            <!-- Calendar -->
                                            <button @click.stop="showCalendar = !showCalendar"
                                                class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                :style="showCalendar ? 'background:#0ea5e9;color:#fff;border:none' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                                                <CalendarDays class="w-4 h-4" />
                                            </button>

                                            <div class="flex-1"></div>

                                            <!-- Mark handled -->
                                            <button @click="markHandled(item.stakeholder || item.from_name, item.property_id)"
                                                class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)"
                                                title="Erledigt">
                                                <CheckCircle class="w-4 h-4" />
                                            </button>

                                            <!-- Send -->
                                            <button @click="item._selectedFiles = [...expandedSelectedFiles]; item._expandedFiles = [...expandedFiles]; useAiDraft(item)" :disabled="aiSending"
                                                class="h-10 sm:h-9 px-5 rounded-xl flex items-center justify-center gap-2 transition-all font-semibold text-sm"
                                                style="background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none;box-shadow:0 2px 8px rgba(212,98,43,0.3)">
                                                <span v-if="aiSending" class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span>
                                                <Send v-else class="w-4 h-4" />
                                                <span>Senden</span>
                                            </button>
                                        </div>
                                    </template>
                                    <div v-else class="text-sm text-zinc-500 py-4 text-center">KI-Vorschlag konnte nicht generiert werden.</div>
                                </div>

                                <!-- RIGHT: Thread / Context -->
                                <div class="space-y-2 min-w-0">
                                    <!-- Original Email (collapsed by default, toggle with expandedBodyFull) -->
                                    <div v-if="expandedDetail.email" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                        <div @click.stop="expandedBodyFull = !expandedBodyFull" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" :style="'background:' + (item.category === 'bounce' ? '#dc2626' : '#ef4444')">
                                                {{ (expandedDetail.email.from_name || expandedDetail.email.stakeholder || '?').charAt(0).toUpperCase() }}
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-semibold truncate">{{ expandedDetail.email.from_name || expandedDetail.email.stakeholder }}</div>
                                                <div class="text-xs text-zinc-500 truncate">{{ expandedDetail.email.subject }}</div>
                                            </div>
                                            <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ formatDetailDate(expandedDetail.email.email_date) }}</span>
                                            <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="expandedBodyFull ? 'rotate-180' : ''" />
                                        </div>
                                        <div v-if="expandedBodyFull" class="px-4 py-3 text-sm leading-relaxed whitespace-pre-line" style="border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word;background:white">{{ stripQuotedReply(expandedDetail.email.body_text || expandedDetail.email.ai_summary || 'Kein Inhalt') }}</div>
                                    </div>

                                    <!-- Thread / Verlauf (collapsed by default) -->
                                    <div v-if="expandedDetail.thread.length > 0" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                        <div @click.stop="showThreadAccordion = !showThreadAccordion" class="px-4 py-2.5 flex items-center gap-2 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                            <Clock class="w-3.5 h-3.5 text-zinc-500" />
                                            <span class="text-xs font-semibold text-zinc-500">Verlauf</span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background:rgba(244,244,245,0.8);color:#71717a">{{ expandedDetail.thread.length }}</span>
                                            <div class="flex-1"></div>
                                            <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="showThreadAccordion ? 'rotate-180' : ''" />
                                        </div>
                                        <div v-if="showThreadAccordion" style="border-top:1px solid var(--border)">
                                            <div v-for="(msg, mi) in expandedDetail.thread" :key="mi"
                                                class="cursor-pointer transition-colors hover:bg-[var(--accent)]"
                                                :style="mi > 0 ? 'border-top:1px solid var(--border)' : ''"
                                                @click.stop="expandedThreadMsg = (expandedThreadMsg === msg.id ? null : msg.id)">
                                                <div class="px-4 py-2 flex items-center gap-2">
                                                    <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0" :style="msg.direction === 'outbound' ? 'background:#3b82f6' : 'background:#6b7280'">
                                                        {{ msg.direction === 'outbound' ? '↑' : '↓' }}
                                                    </div>
                                                    <span class="text-xs font-medium truncate flex-1">{{ msg.direction === 'outbound' ? 'SR-Homes' : msg.from_name }}</span>
                                                    <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ msg.created_at ? msg.created_at.substring(5,10).split('-').reverse().join('.') + ' ' + msg.created_at.substring(11,16) : '' }}</span>
                                                </div>
                                                <div v-if="expandedThreadMsg === msg.id" class="px-4 py-2.5 text-xs leading-relaxed whitespace-pre-line" style="background:white;border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word">
                                                    <div class="text-[11px] font-semibold text-zinc-500 mb-1">{{ msg.email_subject || msg.subject }}</div>
                                                    <template v-if="msg.full_body && msg.full_body.trim()">{{ stripQuotedReply(msg.full_body) }}</template>
                                                    <template v-else-if="msg.ai_summary">{{ msg.ai_summary }}</template>
                                                    <template v-else><em class="text-zinc-500">Kein Inhalt</em></template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </template>
                </div>
            </div>

            <!-- PAUSIERTE OBJEKTE (on-hold unanswered) -->
            <div v-if="unansweredInnerTab === 'onhold'" class="bg-white rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                <div class="px-4 py-3 flex items-center justify-between border-b border-zinc-200/80">
                    <div class="flex items-center gap-2">
                        <span class="text-base">⏸</span>
                        <span class="text-sm font-semibold">Pausierte Objekte – Unbeantwortete Anfragen</span>
                    </div>
                    <span class="badge badge-muted text-[10px]">{{ onHoldUnansweredList.length }} offen</span>
                </div>
                <div v-if="!onHoldUnansweredList.length" class="px-6 py-8 text-center text-zinc-500 text-sm">Keine offenen Anfragen bei pausierten Objekten</div>
                <template v-else>
                    <div v-for="item in onHoldUnansweredList" :key="item.id"
                        class="px-4 py-3 border-b border-zinc-200/80 last:border-b-0 hover:bg-[var(--accent)] transition-colors cursor-pointer opacity-75"
                        @click="toggleUnansweredDetail(item)">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium truncate">{{ item.from_name }}</span>
                                    <span v-if="item.ref_id" class="badge badge-muted text-[10px] flex-shrink-0">⏸ {{ item.ref_id }}</span>
                                    <span v-if="item.on_hold_note" class="text-[10px] text-zinc-500 italic truncate">{{ item.on_hold_note }}</span>
                                </div>
                                <div class="text-xs text-zinc-500 mt-0.5 truncate">{{ item.subject }}</div>
                            </div>
                            <span class="text-[10px] text-zinc-500 flex-shrink-0">{{ item.days_waiting }}d</span>
                        </div>
                        <!-- Expanded detail (reuse same toggleUnansweredDetail logic) -->
                        <div v-if="expandedUnanswered === item.id" class="mt-3 pt-3 border-t border-zinc-200/80">
                            <div v-if="expandedAiLoading" class="text-xs text-zinc-500">KI-Entwurf wird geladen…</div>
                            <div v-else-if="expandedAiDraft" class="space-y-2">
                                <div class="text-xs text-zinc-500">
                                    <span class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-zinc-500">An:</span>
                                        <input v-model="expandedAiDraft.to"
                                            class="font-medium px-1.5 py-0 rounded text-xs"
                                            style="border:1px solid rgba(228,228,231,0.8);background:var(--background);color:#18181b;height:22px;min-width:180px;max-width:260px"
                                            placeholder="E-Mail-Adresse..." />
                                        <button @click.stop="saveRecipientEmail(expandedItem?.from_name, expandedItem?.property_id, expandedAiDraft.to)"
                                            class="flex items-center gap-1 px-2 py-0 rounded text-[10px] font-medium transition-colors"
                                            :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0' : 'background:rgba(244,244,245,0.8);color:#71717a;border:1px solid rgba(228,228,231,0.8)'"
                                            title="E-Mail-Adresse speichern">
                                            <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                            <Check v-else-if="recipientEmailSaved" class="w-3 h-3" />
                                            <span v-else class="text-[10px]">Speichern</span>
                                        </button>
                                        <span class="text-zinc-500">· Betreff:</span>
                                        <span class="font-medium text-zinc-900">{{ expandedAiDraft.subject }}</span>
                                    </span>
                                </div>
                                <textarea v-model="expandedAiDraft.body" class="text-xs leading-relaxed rounded-lg p-3 bg-white dark:bg-white w-full resize-y" style="border: 1px solid var(--border); min-height: 200px; font-family: inherit;"></textarea>
                                <button @click.stop="sendUnansweredReply(item)" class="px-4 py-2 text-xs font-medium text-white bg-zinc-900 rounded-xl hover:bg-zinc-800 transition-all duration-200 active:scale-[0.97] btn-sm">Senden</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- NICHT ZUGEORDNETE -->
            <div v-if="unansweredInnerTab === 'unmatched'" class="bg-white rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                <div class="px-4 py-3 flex items-center justify-between border-b border-zinc-200/80">
                    <div class="flex items-center gap-2">
                        <MailX class="w-4 h-4 text-amber-500" />
                        <span class="text-sm font-semibold">Nicht zugeordnete E-Mails</span>
                    </div>
                </div>
                <div v-if="unansweredLoading" class="px-6 py-8 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                <div v-else-if="!unmatchedList.length" class="px-6 py-8 text-center text-zinc-500 text-sm">Keine nicht zugeordneten E-Mails</div>
                <div v-else class="divide-y divide-[var(--border)]">
                    <div v-for="item in unmatchedList" :key="item.id"
                        class="px-3 py-2.5 flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 hover:bg-[var(--accent)] transition-colors cursor-pointer">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            <span class="w-2 h-2 rounded-full flex-shrink-0 bg-amber-400"></span>
                            <span class="text-sm font-medium truncate flex-1 min-w-0">{{ item.from_name || item.stakeholder || item.from_email }}</span>
                            <span class="badge text-[10px] flex-shrink-0" :style="catBadgeStyle(item.category || 'email-in')">{{ catLabel(item.category || 'email-in') }}</span>
                            <span class="text-[10px] text-zinc-500 flex-shrink-0">{{ item.days_waiting }}d</span>
                        </div>
                        <span class="text-xs text-zinc-500 truncate hidden sm:block">{{ item.subject }}</span>
                    </div>
                </div>
            </div>

            <!-- Auto-Reply Einstellungen (klappbar) -->
            <div class="mt-4">
                <button @click="showAutoReplySettings = !showAutoReplySettings; if(showAutoReplySettings && !autoReplyAllProperties.length) loadAutoReplySettings()"
                    class="flex items-center gap-2 text-xs font-medium px-3 py-2 rounded-lg w-full transition-colors"
                    style="background:rgba(244,244,245,0.8);color:#71717a">
                    <svg :class="showAutoReplySettings ? 'rotate-90' : ''" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    ⚙️ Auto-Reply Einstellungen
                    <span v-if="autoReplyLogs.length" class="text-[10px] px-2 py-0.5 rounded-full cursor-pointer" style="background:rgba(16,185,129,0.1);color:#10b981"
                        @click.stop="loadAutoReplyLogs(); showAutoReplyPopup = true">{{ autoReplyLogs.length }} gesendet (24h)</span>
                    <span v-if="autoReplyEnabled" class="ml-auto text-[10px] px-2 py-0.5 rounded-full" style="background:#10b981;color:white">Aktiv</span>
                    <span v-else class="ml-auto text-[10px] px-2 py-0.5 rounded-full" style="background:var(--border);color:#71717a">Inaktiv</span>
                </button>
                <div v-if="showAutoReplySettings" class="bg-white rounded-2xl overflow-hidden mt-2 p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold">Erstanfragen automatisch beantworten</div>
                            <p class="text-xs text-zinc-500 mt-0.5">KI-generierte Antwort mit Exposé & BaB als Anhang</p>
                        </div>
                        <button @click="toggleAutoReply()" :disabled="autoReplyToggling"
                            class="w-12 h-7 rounded-full transition-colors relative flex-shrink-0"
                            :style="autoReplyEnabled ? 'background:#10b981' : 'background:rgba(244,244,245,0.8);border:1px solid rgba(228,228,231,0.8)'">
                            <span class="absolute top-0.5 w-6 h-6 rounded-full bg-white shadow transition-transform"
                                :style="autoReplyEnabled ? 'transform:translateX(22px)' : 'transform:translateX(2px)'"></span>
                        </button>
                    </div>
                    <div :class="autoReplyEnabled ? 'opacity-100' : 'opacity-40'">
                        <label class="text-xs font-semibold text-zinc-500">Für welche Immobilien?</label>
                        <div class="grid grid-cols-1 gap-1 max-h-40 overflow-y-auto p-2 rounded-lg mt-1" style="background:rgba(244,244,245,0.8)">
                            <label v-for="p in autoReplyAllProperties" :key="p.id"
                                class="flex items-center gap-2 cursor-pointer py-1 px-2 rounded hover:bg-white/50 dark:hover:bg-white/5 text-xs">
                                <input type="checkbox" :value="p.id" v-model="autoReplyPropertyIds" :disabled="!autoReplyEnabled" class="w-3.5 h-3.5" style="accent-color:#D4622B" />
                                <span class="truncate">{{ p.ref_id }} — {{ p.address }}</span>
                                <span v-if="p.expose_count" class="text-[10px] ml-auto" style="color:#10b981">📎</span>
                            </label>
                        </div>
                    </div>
                    <div :class="autoReplyEnabled ? 'opacity-100' : 'opacity-40'">
                        <label class="text-xs font-semibold text-zinc-500">Antworttext (leer = KI-generiert)</label>
                        <textarea v-model="autoReplyText" rows="3" class="form-input resize-y text-xs mt-1"
                            :disabled="!autoReplyEnabled" placeholder="Platzhalter: {vorname}, {name}, {immobilie}"></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button @click="saveAutoReplySettings()" class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97]" style="background:#D4622B;color:white;border:none">Speichern</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ NACHFASSEN ============ -->
        <div v-if="activeSubTab === 'followups'">

            <!-- Makler Filter (Assistenz only) -->
            <div v-if="isAssistenz" class="flex flex-wrap items-center gap-2 mb-3 p-3 rounded-2xl" style="background:rgba(238,118,6,0.04);border:1px solid rgba(238,118,6,0.12)">
                <span class="text-[10px] font-semibold uppercase tracking-wider flex-shrink-0" style="color:#D4622B">Makler:</span>
                <button @click="maklerFilter = 'all'"
                    class="px-3 py-1.5 text-xs font-semibold rounded-full transition-all duration-150 active:scale-[0.97]"
                    :style="maklerFilter === 'all' ? 'background:#D4622B;color:#fff;box-shadow:0 2px 8px rgba(212,98,43,0.3)' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    Alle
                </button>
                <button v-for="name in availableMakler" :key="name" @click="maklerFilter = name"
                    class="px-3 py-1.5 text-xs font-semibold rounded-full transition-all duration-150 active:scale-[0.97]"
                    :style="maklerFilter === name ? 'background:#18181b;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.2)' : 'background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)'">
                    {{ name }}
                </button>
            </div>

            <!-- === STUFE 1: 24h Nachfassen === -->
            <div v-if="filteredStage1Followups.length || stage1Loading" class="mb-4">
                <div class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                    <div @click="toggleGroup('stage1')" class="px-4 py-2 flex items-center gap-2 cursor-pointer hover:brightness-95 transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" style="background:rgba(245,158,11,0.07);border-bottom:1px solid var(--border)">
                        <ChevronDown class="w-4 h-4 flex-shrink-0 transition-transform" :class="collapsedGroups.stage1 ? '-rotate-90' : ''" style="color:#d97706" />
                        <span class="text-xs font-semibold" style="color:#d97706">⚡ 24h – Erste Erinnerung nach Exposé</span>
                        <span v-if="stage1Count" class="text-[10px] font-bold px-1.5 py-0.5 rounded ml-1" style="background:rgba(245,158,11,0.15);color:#d97706">{{ stage1Count }}</span>
                        <span class="flex-1"></span>
                        <button v-if="filteredStage1Followups.length && !collapsedGroups.stage1" @click.stop="requestSendAll('stage1', filteredStage1Followups)" :disabled="sendAllRunning"
                            class="text-[10px] px-2.5 py-1 rounded-lg font-semibold transition-colors" style="background:#d97706;color:white">
                            <template v-if="sendAllRunning === 'stage1'">{{ sendAllProgress.sent }}/{{ sendAllProgress.total }}...</template>
                            <template v-else>Alle senden ({{ filteredStage1Followups.length }})</template>
                        </button>
                    </div>
                    <div v-if="collapsedGroups.stage1" class="px-4 py-2 text-[10px] text-zinc-500">{{ filteredStage1Followups.length }} Einträge eingeklappt</div>
                    <template v-else>
                    <div v-if="stage1Loading" class="px-4 py-4 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <template v-for="f in filteredStage1Followups" :key="f.id">
                            <div @click="toggleStage1Detail(f)" class="px-4 py-3 hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors group cursor-pointer">
                                <!-- Top row -->
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-semibold truncate flex-1 min-w-0">{{ f.from_name }}</span>
                                    <span class="text-xs font-bold tabular-nums px-1.5 py-0.5 rounded-lg flex-shrink-0" style="background:rgba(217,119,6,0.1);color:#d97706">{{ f.days_waiting }}d</span>
                                    <ChevronDown class="w-4 h-4 text-zinc-400 flex-shrink-0 transition-transform" :class="expandedStage1 === f.id ? 'rotate-180' : ''" />
                                </div>
                                <!-- Bottom row -->
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap" @click.stop>
                                    <span class="badge badge-muted text-[10px]">{{ f.ref_id }}</span>
                                    <span v-if="f.contact_phone" class="text-[10px] flex items-center gap-0.5" style="color:#71717a"><Phone class="w-2.5 h-2.5" />{{ f.contact_phone }}</span>
                                    <span v-if="isAssistenz && f.broker_name" class="text-[10px] font-medium px-2 py-0.5 rounded-full" style="background:rgba(238,118,6,0.08);color:#D4622B">{{ f.broker_name }}</span>
                                    <div class="flex gap-1 ml-auto">
                                        <button @click.stop="toggleStage1Detail(f)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97]" style="height:26px;background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border:none"><Sparkles class="w-3 h-3" /><span class="hidden sm:inline">KI-Entwurf</span></button>
                                        <button @click.stop="markHandled(f.from_name, f.property_id)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-white text-zinc-600 border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="height:26px"><CheckCircle class="w-3 h-3" /><span class="hidden sm:inline">Erledigt</span></button>
                                    </div>
                                </div>
                            </div>
                            <!-- Stage 1 Expanded Detail — 2-column layout -->
                            <div v-if="expandedStage1 === f.id" class="exp-detail" style="border-left:3px solid #d97706">
                                <div v-if="stage1AiLoading && stage1DetailLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span><span class="text-sm text-zinc-500 ml-2">KI-Entwurf wird erstellt...</span></div>
                                <div v-else class="grid gap-4 p-4 two-col-grid">
                                    <!-- LEFT: KI Editor -->
                                    <div class="space-y-2 min-w-0">
                                        <!-- Call script -->
                                        <div v-if="stage1AiDraft?.callScript" class="rounded-xl overflow-hidden" style="border:1px solid rgba(16,185,129,0.25)">
                                            <div class="px-4 py-2.5 flex items-center gap-2" style="background:rgba(16,185,129,0.06)">
                                                <Phone class="w-3.5 h-3.5" style="color:#10b981" />
                                                <span class="text-xs font-semibold" style="color:#10b981">Anrufen empfohlen</span>
                                                <a v-if="stage1AiDraft?.phone" :href="'tel:' + stage1AiDraft.phone" class="text-xs font-mono ml-1 underline" style="color:#10b981">{{ stage1AiDraft.phone }}</a>
                                            </div>
                                            <div class="px-4 py-2.5 text-xs whitespace-pre-line" style="border-top:1px solid rgba(16,185,129,0.15);background:white">{{ stage1AiDraft.callScript }}</div>
                                        </div>
                                        <!-- Lead badges -->
                                        <div v-if="stage1AiDraft?.leadStatus || stage1AiDraft?.leadPhase" class="flex items-center gap-2 flex-wrap">
                                            <span v-if="stage1AiDraft?.leadPhase" class="text-[10px] font-bold px-2 py-1 rounded-lg" style="background:#d97706;color:#fff">Phase {{ stage1AiDraft.leadPhase }}</span>
                                            <span v-if="stage1AiDraft?.mailType" class="text-[10px] font-medium px-2 py-1 rounded-lg" style="background:rgba(217,119,6,0.12);color:#b45309">Typ {{ stage1AiDraft.mailType }}</span>
                                            <span v-if="stage1AiDraft?.leadStatus" class="text-xs text-zinc-500">{{ stage1AiDraft.leadStatus }}</span>
                                        </div>
                                        <div v-if="stage1AiLoading" class="flex items-center justify-center gap-2 py-8">
                                            <span class="spinner" style="width:16px;height:16px"></span>
                                            <span class="text-sm text-zinc-500">KI-Entwurf wird generiert...</span>
                                        </div>
                                        <template v-else-if="stage1AiDraft">
                                            <!-- To + Subject (compact, expandable) -->
                                            <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);background:white">
                                                <div v-if="!showStage1EmailFields" @click.stop="showStage1EmailFields = true"
                                                    class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none hover:bg-[var(--accent)] transition-colors">
                                                    <Mail class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                    <span class="text-sm truncate flex-1 min-w-0"><span class="text-zinc-500">An:</span> <span class="font-medium">{{ stage1AiDraft.to || 'Keine Adresse' }}</span></span>
                                                    <ChevronDown class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                </div>
                                                <template v-if="showStage1EmailFields">
                                                    <div class="flex items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border)">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">An:</span>
                                                        <input v-model="stage1AiDraft.to" class="flex-1 text-sm bg-transparent outline-none min-w-0" style="color:#18181b" placeholder="E-Mail-Adresse..." />
                                                        <button @click.stop="saveRecipientEmail(f.from_name, f.property_id, stage1AiDraft.to)"
                                                            class="flex-shrink-0 px-2 py-1 rounded-lg text-[10px] font-medium transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                                            :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534' : 'color:#71717a'">
                                                            <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                                            <Check v-else-if="recipientEmailSaved" class="w-3.5 h-3.5" style="color:#166534" />
                                                            <span v-else>Speichern</span>
                                                        </button>
                                                    </div>
                                                    <div class="flex items-center gap-2 px-3 py-2">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">Betr:</span>
                                                        <input v-model="stage1AiDraft.subject" class="flex-1 text-sm font-medium bg-transparent outline-none min-w-0" style="color:#18181b" />
                                                    </div>
                                                </template>
                                            </div>
                                            <!-- Textarea -->
                                            <textarea v-model="stage1AiDraft.body"
                                                class="w-full rounded-xl p-4 text-sm leading-relaxed resize-y"
                                                style="border:1px solid rgba(228,228,231,0.8);background:white;color:#18181b;min-height:220px;font-family:inherit;outline:none"
                                                @focus="$event.target.style.borderColor='#d97706'"
                                                @blur="$event.target.style.borderColor='var(--border)'"
                                            ></textarea>
                                            <!-- Toolbar -->
                                            <div class="flex items-center gap-2 pt-1">
                                                <select v-model="aiDetailLevel" @change.stop="setAiDetailLevel(aiDetailLevel)"
                                                    class="h-10 sm:h-9 text-xs font-medium rounded-xl px-2.5 cursor-pointer"
                                                    style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                                                    <option value="brief">Knapp</option>
                                                    <option value="standard">Standard</option>
                                                    <option value="detailed">Ausführlich</option>
                                                </select>
                                                <button @click="toggleStage1Detail(f); $nextTick(() => toggleStage1Detail(f))" class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors" style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Neu generieren">
                                                    <Sparkles class="w-4 h-4" />
                                                </button>
                                                <div class="flex-1"></div>
                                                <button @click="markHandled(f.from_name, f.property_id)"
                                                    class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                    style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Erledigt">
                                                    <CheckCircle class="w-4 h-4" />
                                                </button>
                                                <button @click="sendStage1Draft(f)" :disabled="stage1Sending"
                                                    class="h-10 sm:h-9 px-5 rounded-xl flex items-center justify-center gap-2 transition-all font-semibold text-sm"
                                                    style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border:none;box-shadow:0 2px 8px rgba(217,119,6,0.3)">
                                                    <span v-if="stage1Sending" class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span>
                                                    <Send v-else class="w-4 h-4" />
                                                    <span>Senden</span>
                                                </button>
                                            </div>
                                        </template>
                                        <div v-else-if="!stage1AiLoading" class="text-sm text-zinc-500 py-4 text-center">KI-Entwurf konnte nicht geladen werden.</div>
                                    </div>
                                    <!-- RIGHT: Thread / Context -->
                                    <div class="space-y-2 min-w-0">
                                        <div v-if="stage1DetailLoading" class="flex items-center justify-center py-8"><span class="spinner" style="width:16px;height:16px"></span></div>
                                        <template v-else-if="stage1Detail">
                                            <!-- Original Email -->
                                            <div v-if="stage1Detail.email" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="stage1Detail._bodyOpen = !stage1Detail._bodyOpen" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background:#d97706">
                                                        {{ (stage1Detail.email.from_name || stage1Detail.email.stakeholder || '?').charAt(0).toUpperCase() }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold truncate">{{ stage1Detail.email.from_name || stage1Detail.email.stakeholder }}</div>
                                                        <div class="text-xs text-zinc-500 truncate">{{ stage1Detail.email.subject }}</div>
                                                    </div>
                                                    <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ formatDetailDate(stage1Detail.email.email_date) }}</span>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="stage1Detail._bodyOpen ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="stage1Detail._bodyOpen" class="px-4 py-3 text-sm leading-relaxed whitespace-pre-line" style="border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word;background:white">{{ stripQuotedReply(stage1Detail.email.body_text || stage1Detail.email.ai_summary || 'Kein Inhalt') }}</div>
                                            </div>
                                            <!-- Thread / Verlauf -->
                                            <div v-if="stage1Detail.thread.length > 0" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="showStage1Thread = !showStage1Thread" class="px-4 py-2.5 flex items-center gap-2 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <Clock class="w-3.5 h-3.5 text-zinc-500" />
                                                    <span class="text-xs font-semibold text-zinc-500">Verlauf</span>
                                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background:rgba(244,244,245,0.8);color:#71717a">{{ stage1Detail.thread.length }}</span>
                                                    <div class="flex-1"></div>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="showStage1Thread ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="showStage1Thread" style="border-top:1px solid var(--border)">
                                                    <div v-for="(msg, mi) in stage1Detail.thread" :key="mi"
                                                        class="cursor-pointer transition-colors hover:bg-[var(--accent)]"
                                                        :style="mi > 0 ? 'border-top:1px solid var(--border)' : ''"
                                                        @click.stop="stage1ThreadMsg = (stage1ThreadMsg === (msg.id || mi) ? null : (msg.id || mi))">
                                                        <div class="px-4 py-2 flex items-center gap-2">
                                                            <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0" :style="msg.direction === 'outbound' ? 'background:#3b82f6' : 'background:#6b7280'">
                                                                {{ msg.direction === 'outbound' ? '↑' : '↓' }}
                                                            </div>
                                                            <span class="text-xs font-medium truncate flex-1">{{ msg.direction === 'outbound' ? 'SR-Homes' : (msg.from_name || f.from_name) }}</span>
                                                            <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ msg.created_at ? msg.created_at.substring(5,10).split('-').reverse().join('.') + ' ' + msg.created_at.substring(11,16) : '' }}</span>
                                                        </div>
                                                        <div v-if="stage1ThreadMsg === (msg.id || mi)" class="px-4 py-2.5 text-xs leading-relaxed whitespace-pre-line" style="background:white;border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word">
                                                            <div class="text-[11px] font-semibold text-zinc-500 mb-1">{{ msg.email_subject || msg.subject }}</div>
                                                            <template v-if="msg.full_body && msg.full_body.trim()">{{ stripQuotedReply(msg.full_body) }}</template>
                                                            <template v-else-if="msg.ai_summary">{{ msg.ai_summary }}</template>
                                                            <template v-else><em class="text-zinc-500">Kein Inhalt</em></template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    </template>
                </div>
            </div>

            <!-- === STUFE 2: Klassische Nachfassen (3+ Tage) === -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                <div class="flex items-center gap-2">
                    <Clock class="w-4 h-4" style="color:#D4622B" />
                    <span class="text-sm font-semibold">Nachfass-Fälle</span>
                </div>
                <div class="flex gap-1">
                    <button v-for="f in [{v:'all',l:'Alle'},{v:'week',l:'7 Tage'},{v:'kaufanbot',l:'Kaufanbote'}]" :key="f.v"
                        @click="loadFollowups(f.v)"
                        class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97]" :class="followupFilter === f.v ? 'btn-primary' : 'btn-outline'">{{ f.l }}</button>
                </div>
            </div>
            <div v-if="followupLoading" class="px-6 py-8 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
            <div v-else class="space-y-3">
                <!-- Kaufanbot group -->
                <div v-if="kaufanbotFollowups.length" class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                    <div @click="toggleGroup('kaufanbot')" class="px-4 py-1.5 flex items-center gap-2 cursor-pointer hover:brightness-95 transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" style="background:rgba(147,51,234,0.06);border-bottom:1px solid var(--border)">
                        <ChevronDown class="w-3.5 h-3.5 flex-shrink-0 transition-transform" :class="collapsedGroups.kaufanbot ? '-rotate-90' : ''" style="color:#D4622B" />
                        <span class="text-xs font-semibold" style="color:#D4622B">Kaufanbot</span>
                        <span class="text-[10px] ml-1 font-bold px-1.5 rounded" style="background:rgba(147,51,234,0.06);color:#D4622B">{{ kaufanbotFollowups.length }}</span>
                        <span class="flex-1"></span>
                        <button v-if="kaufanbotFollowups.length && !collapsedGroups.kaufanbot" @click.stop="requestSendAll('kaufanbot', kaufanbotFollowups)" :disabled="sendAllRunning"
                            class="text-[10px] px-2.5 py-1 rounded-lg font-semibold transition-colors text-white" style="background:#D4622B">
                            <template v-if="sendAllRunning === 'kaufanbot'">{{ sendAllProgress.sent }}/{{ sendAllProgress.total }}...</template>
                            <template v-else>Alle senden ({{ kaufanbotFollowups.length }})</template>
                        </button>
                    </div>
                    <div v-if="collapsedGroups.kaufanbot" class="px-4 py-2 text-[10px] text-zinc-500">{{ kaufanbotFollowups.length }} Einträge eingeklappt</div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <template v-for="f in kaufanbotFollowups" :key="f.id">
                            <div @click="toggleFollowupDetail(f)" class="px-4 py-3 hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors group cursor-pointer">
                                <!-- Top row: name + days -->
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-semibold truncate flex-1 min-w-0">{{ f.from_name }}</span>
                                    <span class="text-xs font-bold tabular-nums px-1.5 py-0.5 rounded-lg flex-shrink-0" :class="f.days_waiting >= 14 ? 'bg-red-50 text-red-600' : f.days_waiting >= 7 ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600'">{{ f.days_waiting }}d</span>
                                    <ChevronDown class="w-4 h-4 text-zinc-400 flex-shrink-0 transition-transform" :class="expandedFollowup === f.id ? 'rotate-180' : ''" />
                                </div>
                                <!-- Bottom row: meta + actions -->
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap" @click.stop>
                                    <span class="badge badge-muted text-[10px]">{{ f.ref_id }}</span>
                                    <span v-if="f.contact_phone" class="text-[10px] flex items-center gap-0.5" style="color:#71717a"><Phone class="w-2.5 h-2.5" />{{ f.contact_phone }}</span>
                                    <span v-if="isAssistenz && f.broker_name" class="text-[10px] font-medium px-2 py-0.5 rounded-full flex-shrink-0" style="background:rgba(238,118,6,0.08);color:#D4622B">{{ f.broker_name }}</span>
                                    <div class="flex gap-1 ml-auto" :class="bulkMode ? '' : ''">
                                        <button @click.stop="toggleFollowupDetail(f)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97]" style="height:26px;background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none"><Sparkles class="w-3 h-3" /><span class="hidden sm:inline">KI-Entwurf</span></button>
                                        <button @click.stop="markHandled(f.from_name, f.property_id)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-white text-zinc-600 border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="height:26px"><CheckCircle class="w-3 h-3" /></button>
                                        <div class="relative">
                                            <button @click.stop="snoozeOpenId = snoozeOpenId === f.id ? null : f.id" class="inline-flex items-center justify-center rounded-lg hover:bg-zinc-100 transition-all duration-200 bg-white border border-zinc-200" style="height:26px;width:26px"><BellOff class="w-3 h-3" /></button>
                                            <div v-if="snoozeOpenId === f.id" class="absolute right-0 top-full mt-1 z-50 bg-white border border-zinc-200/80 rounded-lg shadow-lg py-1 min-w-[100px]">
                                                <button v-for="opt in snoozeOptions" :key="opt.days" @click="snoozeFollowup(f, opt.days)" class="w-full text-left px-3 py-1.5 text-xs hover:bg-[var(--accent)]">{{ opt.label }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Stage 2 Expanded Detail — 2-column layout -->
                            <div v-if="expandedFollowup === f.id" class="exp-detail" style="border-left:3px solid #D4622B">
                                <div v-if="followupDetailLoading && followupAiLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span><span class="text-sm text-zinc-500 ml-2">KI-Entwurf wird erstellt...</span></div>
                                <div v-else class="grid gap-4 p-4 two-col-grid">
                                    <!-- LEFT: KI Editor -->
                                    <div class="space-y-2 min-w-0">
                                        <!-- Call script -->
                                        <div v-if="followupAiDraft?.callScript" class="rounded-xl overflow-hidden" style="border:1px solid rgba(16,185,129,0.25)">
                                            <div class="px-4 py-2.5 flex items-center gap-2" style="background:rgba(16,185,129,0.06)">
                                                <Phone class="w-3.5 h-3.5" style="color:#10b981" />
                                                <span class="text-xs font-semibold" style="color:#10b981">Anrufen empfohlen</span>
                                                <a v-if="followupAiDraft?.phone" :href="'tel:' + followupAiDraft.phone" class="text-xs font-mono ml-1 underline" style="color:#10b981">{{ followupAiDraft.phone }}</a>
                                            </div>
                                            <div class="px-4 py-2.5 text-xs whitespace-pre-line" style="border-top:1px solid rgba(16,185,129,0.15);background:white">{{ followupAiDraft.callScript }}</div>
                                        </div>
                                        <!-- Lead badges -->
                                        <div v-if="followupAiDraft?.leadStatus || followupAiDraft?.leadPhase" class="flex items-center gap-2 flex-wrap">
                                            <span v-if="followupAiDraft?.leadPhase" class="text-[10px] font-bold px-2 py-1 rounded-lg" style="background:#D4622B;color:#fff">Phase {{ followupAiDraft.leadPhase }}</span>
                                            <span v-if="followupAiDraft?.mailType" class="text-[10px] font-medium px-2 py-1 rounded-lg" style="background:rgba(212,98,43,0.12);color:#7c3aed">Typ {{ followupAiDraft.mailType }}</span>
                                            <span v-if="followupAiDraft?.leadStatus" class="text-xs text-zinc-500">{{ followupAiDraft.leadStatus }}</span>
                                        </div>
                                        <div v-if="followupAiLoading" class="flex items-center justify-center gap-2 py-8">
                                            <span class="spinner" style="width:16px;height:16px"></span>
                                            <span class="text-sm text-zinc-500">KI-Entwurf wird generiert...</span>
                                        </div>
                                        <template v-else-if="followupAiDraft?.body">
                                            <!-- To + Subject (compact, expandable) -->
                                            <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);background:white">
                                                <div v-if="!showFollowupEmailFields" @click.stop="showFollowupEmailFields = true"
                                                    class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none hover:bg-[var(--accent)] transition-colors">
                                                    <Mail class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                    <span class="text-sm truncate flex-1 min-w-0"><span class="text-zinc-500">An:</span> <span class="font-medium">{{ followupAiDraft.to || 'Keine Adresse' }}</span></span>
                                                    <ChevronDown class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                </div>
                                                <template v-if="showFollowupEmailFields">
                                                    <div class="flex items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border)">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">An:</span>
                                                        <input v-model="followupAiDraft.to" class="flex-1 text-sm bg-transparent outline-none min-w-0" style="color:#18181b" placeholder="E-Mail-Adresse..." />
                                                        <button @click.stop="saveRecipientEmail(expandedFollowupItem?.from_name, expandedFollowupItem?.property_id, followupAiDraft.to)"
                                                            class="flex-shrink-0 px-2 py-1 rounded-lg text-[10px] font-medium transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                                            :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534' : 'color:#71717a'">
                                                            <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                                            <Check v-else-if="recipientEmailSaved" class="w-3.5 h-3.5" style="color:#166534" />
                                                            <span v-else>Speichern</span>
                                                        </button>
                                                    </div>
                                                    <div class="flex items-center gap-2 px-3 py-2">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">Betr:</span>
                                                        <input v-model="followupAiDraft.subject" class="flex-1 text-sm font-medium bg-transparent outline-none min-w-0" style="color:#18181b" />
                                                    </div>
                                                </template>
                                            </div>
                                            <!-- Textarea -->
                                            <textarea v-model="followupAiDraft.body"
                                                class="w-full rounded-xl p-4 text-sm leading-relaxed resize-y"
                                                style="border:1px solid rgba(228,228,231,0.8);background:white;color:#18181b;min-height:220px;font-family:inherit;outline:none"
                                                @focus="$event.target.style.borderColor='#D4622B'"
                                                @blur="$event.target.style.borderColor='var(--border)'"
                                            ></textarea>
                                            <!-- Toolbar -->
                                            <div class="flex items-center gap-2 pt-1">
                                                <select v-model="followupDetailLevel" @change="setFollowupDetailLevel(followupDetailLevel)"
                                                    class="h-10 sm:h-9 text-xs font-medium rounded-xl px-2.5 cursor-pointer"
                                                    style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                                                    <option value="brief">Knapp</option>
                                                    <option value="standard">Standard</option>
                                                    <option value="detailed">Ausführlich</option>
                                                </select>
                                                <button @click="regenerateFollowupDraft(f)" class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors" style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Neu generieren">
                                                    <Sparkles class="w-4 h-4" />
                                                </button>
                                                <div class="flex-1"></div>
                                                <button @click="markHandled(f.from_name, f.property_id)"
                                                    class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                    style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Erledigt">
                                                    <CheckCircle class="w-4 h-4" />
                                                </button>
                                                <button @click="sendFollowupDraft(f)" :disabled="followupSending"
                                                    class="h-10 sm:h-9 px-5 rounded-xl flex items-center justify-center gap-2 transition-all font-semibold text-sm"
                                                    style="background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none;box-shadow:0 2px 8px rgba(212,98,43,0.3)">
                                                    <span v-if="followupSending" class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span>
                                                    <Send v-else class="w-4 h-4" />
                                                    <span>Senden</span>
                                                </button>
                                            </div>
                                        </template>
                                        <div v-else-if="!followupAiLoading" class="text-sm text-zinc-500 py-4 text-center">KI-Entwurf konnte nicht erstellt werden.</div>
                                    </div>
                                    <!-- RIGHT: Thread / Context -->
                                    <div class="space-y-2 min-w-0">
                                        <div v-if="followupDetailLoading" class="flex items-center justify-center py-8"><span class="spinner" style="width:16px;height:16px"></span></div>
                                        <template v-else-if="followupDetail">
                                            <!-- Original Email -->
                                            <div v-if="followupDetail.email" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="followupBodyFull = !followupBodyFull" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background:#D4622B">
                                                        {{ (followupDetail.email.from_name || followupDetail.email.stakeholder || '?').charAt(0).toUpperCase() }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold truncate">{{ followupDetail.email.from_name || followupDetail.email.stakeholder }}</div>
                                                        <div class="text-xs text-zinc-500 truncate">{{ followupDetail.email.subject }}</div>
                                                    </div>
                                                    <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ formatDetailDate(followupDetail.email.email_date) }}</span>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="followupBodyFull ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="followupBodyFull" class="px-4 py-3 text-sm leading-relaxed whitespace-pre-line" style="border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word;background:white">{{ stripQuotedReply(followupDetail.email.body_text || followupDetail.email.ai_summary || 'Kein Inhalt') }}</div>
                                            </div>
                                            <!-- Thread / Verlauf -->
                                            <div v-if="followupDetail.thread.length > 0" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="showFollowupThread = !showFollowupThread" class="px-4 py-2.5 flex items-center gap-2 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <Clock class="w-3.5 h-3.5 text-zinc-500" />
                                                    <span class="text-xs font-semibold text-zinc-500">Verlauf</span>
                                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background:rgba(244,244,245,0.8);color:#71717a">{{ followupDetail.thread.length }}</span>
                                                    <div class="flex-1"></div>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="showFollowupThread ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="showFollowupThread" style="border-top:1px solid var(--border)">
                                                    <div v-for="(msg, mi) in followupDetail.thread" :key="mi"
                                                        class="cursor-pointer transition-colors hover:bg-[var(--accent)]"
                                                        :style="mi > 0 ? 'border-top:1px solid var(--border)' : ''"
                                                        @click.stop="followupThreadMsg = (followupThreadMsg === (msg.id || mi) ? null : (msg.id || mi))">
                                                        <div class="px-4 py-2 flex items-center gap-2">
                                                            <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0" :style="msg.direction === 'outbound' ? 'background:#3b82f6' : 'background:#6b7280'">
                                                                {{ msg.direction === 'outbound' ? '↑' : '↓' }}
                                                            </div>
                                                            <span class="text-xs font-medium truncate flex-1">{{ msg.direction === 'outbound' ? 'SR-Homes' : (msg.from_name || f.from_name) }}</span>
                                                            <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ (() => { const dt = msg.datetime || msg.created_at; const d = msg.date || msg.activity_date; if (dt) return dt.substring(5,10).split('-').reverse().join('.') + ' ' + dt.substring(11,16); if (d) return d.substring(5,10).split('-').reverse().join('.'); return ''; })() }}</span>
                                                        </div>
                                                        <div v-if="followupThreadMsg === (msg.id || mi)" class="px-4 py-2.5 text-xs leading-relaxed whitespace-pre-line" style="background:white;border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word">
                                                            <div class="text-[11px] font-semibold text-zinc-500 mb-1">{{ msg.email_subject || msg.subject || msg.text || '' }}</div>
                                                            <template v-if="msg.full_body && msg.full_body.trim()">{{ msg.full_body }}</template>
                                                            <template v-else-if="msg.body_text && msg.body_text.trim()">{{ msg.body_text }}</template>
                                                            <template v-else-if="msg.ai_summary">{{ msg.ai_summary }}</template>
                                                            <template v-else-if="msg.text">{{ msg.text }}</template>
                                                            <template v-else><em class="text-zinc-500">Kein Inhalt</em></template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Urgent group (14+ days) -->
                <div v-if="urgentFollowups.length" class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                    <div @click="toggleGroup('urgent')" class="px-4 py-1.5 flex items-center gap-2 cursor-pointer hover:brightness-95 transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" style="background:rgba(239,68,68,0.06);border-bottom:1px solid var(--border)">
                        <ChevronDown class="w-3.5 h-3.5 flex-shrink-0 transition-transform" :class="collapsedGroups.urgent ? '-rotate-90' : ''" style="color:#ef4444" />
                        <span class="text-xs font-semibold" style="color:#ef4444">Dringend (14+ Tage)</span>
                        <span class="text-[10px] ml-1 font-bold px-1.5 rounded" style="background:rgba(239,68,68,0.06);color:#ef4444">{{ urgentFollowups.length }}</span>
                        <span class="flex-1"></span>
                        <button v-if="urgentFollowups.length && !collapsedGroups.urgent" @click.stop="requestSendAll('urgent', urgentFollowups)" :disabled="sendAllRunning"
                            class="text-[10px] px-2.5 py-1 rounded-lg font-semibold transition-colors text-white" style="background:#ef4444">
                            <template v-if="sendAllRunning === 'urgent'">{{ sendAllProgress.sent }}/{{ sendAllProgress.total }}...</template>
                            <template v-else>Alle senden ({{ urgentFollowups.length }})</template>
                        </button>
                    </div>
                    <div v-if="collapsedGroups.urgent" class="px-4 py-2 text-[10px] text-zinc-500">{{ urgentFollowups.length }} Einträge eingeklappt</div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <template v-for="f in urgentFollowups" :key="f.id">
                            <div @click="toggleFollowupDetail(f)" class="px-4 py-3 hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors group cursor-pointer">
                                <!-- Top row: name + days -->
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-semibold truncate flex-1 min-w-0">{{ f.from_name }}</span>
                                    <span class="text-xs font-bold tabular-nums px-1.5 py-0.5 rounded-lg flex-shrink-0" :class="f.days_waiting >= 14 ? 'bg-red-50 text-red-600' : f.days_waiting >= 7 ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600'">{{ f.days_waiting }}d</span>
                                    <ChevronDown class="w-4 h-4 text-zinc-400 flex-shrink-0 transition-transform" :class="expandedFollowup === f.id ? 'rotate-180' : ''" />
                                </div>
                                <!-- Bottom row: meta + actions -->
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap" @click.stop>
                                    <span class="badge badge-muted text-[10px]">{{ f.ref_id }}</span>
                                    <span v-if="f.contact_phone" class="text-[10px] flex items-center gap-0.5" style="color:#71717a"><Phone class="w-2.5 h-2.5" />{{ f.contact_phone }}</span>
                                    <span v-if="isAssistenz && f.broker_name" class="text-[10px] font-medium px-2 py-0.5 rounded-full flex-shrink-0" style="background:rgba(238,118,6,0.08);color:#D4622B">{{ f.broker_name }}</span>
                                    <div class="flex gap-1 ml-auto" :class="bulkMode ? '' : ''">
                                        <button @click.stop="toggleFollowupDetail(f)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97]" style="height:26px;background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none"><Sparkles class="w-3 h-3" /><span class="hidden sm:inline">KI-Entwurf</span></button>
                                        <button @click.stop="markHandled(f.from_name, f.property_id)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-white text-zinc-600 border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="height:26px"><CheckCircle class="w-3 h-3" /></button>
                                        <div class="relative">
                                            <button @click.stop="snoozeOpenId = snoozeOpenId === f.id ? null : f.id" class="inline-flex items-center justify-center rounded-lg hover:bg-zinc-100 transition-all duration-200 bg-white border border-zinc-200" style="height:26px;width:26px"><BellOff class="w-3 h-3" /></button>
                                            <div v-if="snoozeOpenId === f.id" class="absolute right-0 top-full mt-1 z-50 bg-white border border-zinc-200/80 rounded-lg shadow-lg py-1 min-w-[100px]">
                                                <button v-for="opt in snoozeOptions" :key="opt.days" @click="snoozeFollowup(f, opt.days)" class="w-full text-left px-3 py-1.5 text-xs hover:bg-[var(--accent)]">{{ opt.label }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Stage 2 Expanded Detail — 2-column layout -->
                            <div v-if="expandedFollowup === f.id" class="exp-detail" style="border-left:3px solid #D4622B">
                                <div v-if="followupDetailLoading && followupAiLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span><span class="text-sm text-zinc-500 ml-2">KI-Entwurf wird erstellt...</span></div>
                                <div v-else class="grid gap-4 p-4 two-col-grid">
                                    <!-- LEFT: KI Editor -->
                                    <div class="space-y-2 min-w-0">
                                        <!-- Call script -->
                                        <div v-if="followupAiDraft?.callScript" class="rounded-xl overflow-hidden" style="border:1px solid rgba(16,185,129,0.25)">
                                            <div class="px-4 py-2.5 flex items-center gap-2" style="background:rgba(16,185,129,0.06)">
                                                <Phone class="w-3.5 h-3.5" style="color:#10b981" />
                                                <span class="text-xs font-semibold" style="color:#10b981">Anrufen empfohlen</span>
                                                <a v-if="followupAiDraft?.phone" :href="'tel:' + followupAiDraft.phone" class="text-xs font-mono ml-1 underline" style="color:#10b981">{{ followupAiDraft.phone }}</a>
                                            </div>
                                            <div class="px-4 py-2.5 text-xs whitespace-pre-line" style="border-top:1px solid rgba(16,185,129,0.15);background:white">{{ followupAiDraft.callScript }}</div>
                                        </div>
                                        <!-- Lead badges -->
                                        <div v-if="followupAiDraft?.leadStatus || followupAiDraft?.leadPhase" class="flex items-center gap-2 flex-wrap">
                                            <span v-if="followupAiDraft?.leadPhase" class="text-[10px] font-bold px-2 py-1 rounded-lg" style="background:#D4622B;color:#fff">Phase {{ followupAiDraft.leadPhase }}</span>
                                            <span v-if="followupAiDraft?.mailType" class="text-[10px] font-medium px-2 py-1 rounded-lg" style="background:rgba(212,98,43,0.12);color:#7c3aed">Typ {{ followupAiDraft.mailType }}</span>
                                            <span v-if="followupAiDraft?.leadStatus" class="text-xs text-zinc-500">{{ followupAiDraft.leadStatus }}</span>
                                        </div>
                                        <div v-if="followupAiLoading" class="flex items-center justify-center gap-2 py-8">
                                            <span class="spinner" style="width:16px;height:16px"></span>
                                            <span class="text-sm text-zinc-500">KI-Entwurf wird generiert...</span>
                                        </div>
                                        <template v-else-if="followupAiDraft?.body">
                                            <!-- To + Subject (compact, expandable) -->
                                            <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);background:white">
                                                <div v-if="!showFollowupEmailFields" @click.stop="showFollowupEmailFields = true"
                                                    class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none hover:bg-[var(--accent)] transition-colors">
                                                    <Mail class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                    <span class="text-sm truncate flex-1 min-w-0"><span class="text-zinc-500">An:</span> <span class="font-medium">{{ followupAiDraft.to || 'Keine Adresse' }}</span></span>
                                                    <ChevronDown class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                </div>
                                                <template v-if="showFollowupEmailFields">
                                                    <div class="flex items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border)">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">An:</span>
                                                        <input v-model="followupAiDraft.to" class="flex-1 text-sm bg-transparent outline-none min-w-0" style="color:#18181b" placeholder="E-Mail-Adresse..." />
                                                        <button @click.stop="saveRecipientEmail(expandedFollowupItem?.from_name, expandedFollowupItem?.property_id, followupAiDraft.to)"
                                                            class="flex-shrink-0 px-2 py-1 rounded-lg text-[10px] font-medium transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                                            :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534' : 'color:#71717a'">
                                                            <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                                            <Check v-else-if="recipientEmailSaved" class="w-3.5 h-3.5" style="color:#166534" />
                                                            <span v-else>Speichern</span>
                                                        </button>
                                                    </div>
                                                    <div class="flex items-center gap-2 px-3 py-2">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">Betr:</span>
                                                        <input v-model="followupAiDraft.subject" class="flex-1 text-sm font-medium bg-transparent outline-none min-w-0" style="color:#18181b" />
                                                    </div>
                                                </template>
                                            </div>
                                            <!-- Textarea -->
                                            <textarea v-model="followupAiDraft.body"
                                                class="w-full rounded-xl p-4 text-sm leading-relaxed resize-y"
                                                style="border:1px solid rgba(228,228,231,0.8);background:white;color:#18181b;min-height:220px;font-family:inherit;outline:none"
                                                @focus="$event.target.style.borderColor='#D4622B'"
                                                @blur="$event.target.style.borderColor='var(--border)'"
                                            ></textarea>
                                            <!-- Toolbar -->
                                            <div class="flex items-center gap-2 pt-1">
                                                <select v-model="followupDetailLevel" @change="setFollowupDetailLevel(followupDetailLevel)"
                                                    class="h-10 sm:h-9 text-xs font-medium rounded-xl px-2.5 cursor-pointer"
                                                    style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                                                    <option value="brief">Knapp</option>
                                                    <option value="standard">Standard</option>
                                                    <option value="detailed">Ausführlich</option>
                                                </select>
                                                <button @click="regenerateFollowupDraft(f)" class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors" style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Neu generieren">
                                                    <Sparkles class="w-4 h-4" />
                                                </button>
                                                <div class="flex-1"></div>
                                                <button @click="markHandled(f.from_name, f.property_id)"
                                                    class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                    style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Erledigt">
                                                    <CheckCircle class="w-4 h-4" />
                                                </button>
                                                <button @click="sendFollowupDraft(f)" :disabled="followupSending"
                                                    class="h-10 sm:h-9 px-5 rounded-xl flex items-center justify-center gap-2 transition-all font-semibold text-sm"
                                                    style="background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none;box-shadow:0 2px 8px rgba(212,98,43,0.3)">
                                                    <span v-if="followupSending" class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span>
                                                    <Send v-else class="w-4 h-4" />
                                                    <span>Senden</span>
                                                </button>
                                            </div>
                                        </template>
                                        <div v-else-if="!followupAiLoading" class="text-sm text-zinc-500 py-4 text-center">KI-Entwurf konnte nicht erstellt werden.</div>
                                    </div>
                                    <!-- RIGHT: Thread / Context -->
                                    <div class="space-y-2 min-w-0">
                                        <div v-if="followupDetailLoading" class="flex items-center justify-center py-8"><span class="spinner" style="width:16px;height:16px"></span></div>
                                        <template v-else-if="followupDetail">
                                            <!-- Original Email -->
                                            <div v-if="followupDetail.email" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="followupBodyFull = !followupBodyFull" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background:#D4622B">
                                                        {{ (followupDetail.email.from_name || followupDetail.email.stakeholder || '?').charAt(0).toUpperCase() }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold truncate">{{ followupDetail.email.from_name || followupDetail.email.stakeholder }}</div>
                                                        <div class="text-xs text-zinc-500 truncate">{{ followupDetail.email.subject }}</div>
                                                    </div>
                                                    <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ formatDetailDate(followupDetail.email.email_date) }}</span>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="followupBodyFull ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="followupBodyFull" class="px-4 py-3 text-sm leading-relaxed whitespace-pre-line" style="border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word;background:white">{{ stripQuotedReply(followupDetail.email.body_text || followupDetail.email.ai_summary || 'Kein Inhalt') }}</div>
                                            </div>
                                            <!-- Thread / Verlauf -->
                                            <div v-if="followupDetail.thread.length > 0" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="showFollowupThread = !showFollowupThread" class="px-4 py-2.5 flex items-center gap-2 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <Clock class="w-3.5 h-3.5 text-zinc-500" />
                                                    <span class="text-xs font-semibold text-zinc-500">Verlauf</span>
                                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background:rgba(244,244,245,0.8);color:#71717a">{{ followupDetail.thread.length }}</span>
                                                    <div class="flex-1"></div>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="showFollowupThread ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="showFollowupThread" style="border-top:1px solid var(--border)">
                                                    <div v-for="(msg, mi) in followupDetail.thread" :key="mi"
                                                        class="cursor-pointer transition-colors hover:bg-[var(--accent)]"
                                                        :style="mi > 0 ? 'border-top:1px solid var(--border)' : ''"
                                                        @click.stop="followupThreadMsg = (followupThreadMsg === (msg.id || mi) ? null : (msg.id || mi))">
                                                        <div class="px-4 py-2 flex items-center gap-2">
                                                            <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0" :style="msg.direction === 'outbound' ? 'background:#3b82f6' : 'background:#6b7280'">
                                                                {{ msg.direction === 'outbound' ? '↑' : '↓' }}
                                                            </div>
                                                            <span class="text-xs font-medium truncate flex-1">{{ msg.direction === 'outbound' ? 'SR-Homes' : (msg.from_name || f.from_name) }}</span>
                                                            <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ (() => { const dt = msg.datetime || msg.created_at; const d = msg.date || msg.activity_date; if (dt) return dt.substring(5,10).split('-').reverse().join('.') + ' ' + dt.substring(11,16); if (d) return d.substring(5,10).split('-').reverse().join('.'); return ''; })() }}</span>
                                                        </div>
                                                        <div v-if="followupThreadMsg === (msg.id || mi)" class="px-4 py-2.5 text-xs leading-relaxed whitespace-pre-line" style="background:white;border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word">
                                                            <div class="text-[11px] font-semibold text-zinc-500 mb-1">{{ msg.email_subject || msg.subject || msg.text || '' }}</div>
                                                            <template v-if="msg.full_body && msg.full_body.trim()">{{ msg.full_body }}</template>
                                                            <template v-else-if="msg.body_text && msg.body_text.trim()">{{ msg.body_text }}</template>
                                                            <template v-else-if="msg.ai_summary">{{ msg.ai_summary }}</template>
                                                            <template v-else-if="msg.text">{{ msg.text }}</template>
                                                            <template v-else><em class="text-zinc-500">Kein Inhalt</em></template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Warning group (7-14 days) -->
                <div v-if="warningFollowups.length" class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                    <div @click="toggleGroup('warning')" class="px-4 py-1.5 flex items-center gap-2 cursor-pointer hover:brightness-95 transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" style="background:rgba(245,158,11,0.06);border-bottom:1px solid var(--border)">
                        <ChevronDown class="w-3.5 h-3.5 flex-shrink-0 transition-transform" :class="collapsedGroups.warning ? '-rotate-90' : ''" style="color:#f59e0b" />
                        <span class="text-xs font-semibold" style="color:#f59e0b">Warnung (7-14 Tage)</span>
                        <span class="text-[10px] ml-1 font-bold px-1.5 rounded" style="background:rgba(245,158,11,0.06);color:#f59e0b">{{ warningFollowups.length }}</span>
                        <span class="flex-1"></span>
                        <button v-if="warningFollowups.length && !collapsedGroups.warning" @click.stop="requestSendAll('warning', warningFollowups)" :disabled="sendAllRunning"
                            class="text-[10px] px-2.5 py-1 rounded-lg font-semibold transition-colors text-white" style="background:#f59e0b">
                            <template v-if="sendAllRunning === 'warning'">{{ sendAllProgress.sent }}/{{ sendAllProgress.total }}...</template>
                            <template v-else>Alle senden ({{ warningFollowups.length }})</template>
                        </button>
                    </div>
                    <div v-if="collapsedGroups.warning" class="px-4 py-2 text-[10px] text-zinc-500">{{ warningFollowups.length }} Einträge eingeklappt</div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <template v-for="f in warningFollowups" :key="f.id">
                            <div @click="toggleFollowupDetail(f)" class="px-4 py-3 hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors group cursor-pointer">
                                <!-- Top row: name + days -->
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-semibold truncate flex-1 min-w-0">{{ f.from_name }}</span>
                                    <span class="text-xs font-bold tabular-nums px-1.5 py-0.5 rounded-lg flex-shrink-0" :class="f.days_waiting >= 14 ? 'bg-red-50 text-red-600' : f.days_waiting >= 7 ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600'">{{ f.days_waiting }}d</span>
                                    <ChevronDown class="w-4 h-4 text-zinc-400 flex-shrink-0 transition-transform" :class="expandedFollowup === f.id ? 'rotate-180' : ''" />
                                </div>
                                <!-- Bottom row: meta + actions -->
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap" @click.stop>
                                    <span class="badge badge-muted text-[10px]">{{ f.ref_id }}</span>
                                    <span v-if="f.contact_phone" class="text-[10px] flex items-center gap-0.5" style="color:#71717a"><Phone class="w-2.5 h-2.5" />{{ f.contact_phone }}</span>
                                    <span v-if="isAssistenz && f.broker_name" class="text-[10px] font-medium px-2 py-0.5 rounded-full flex-shrink-0" style="background:rgba(238,118,6,0.08);color:#D4622B">{{ f.broker_name }}</span>
                                    <div class="flex gap-1 ml-auto" :class="bulkMode ? '' : ''">
                                        <button @click.stop="toggleFollowupDetail(f)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97]" style="height:26px;background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none"><Sparkles class="w-3 h-3" /><span class="hidden sm:inline">KI-Entwurf</span></button>
                                        <button @click.stop="markHandled(f.from_name, f.property_id)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-white text-zinc-600 border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="height:26px"><CheckCircle class="w-3 h-3" /></button>
                                        <div class="relative">
                                            <button @click.stop="snoozeOpenId = snoozeOpenId === f.id ? null : f.id" class="inline-flex items-center justify-center rounded-lg hover:bg-zinc-100 transition-all duration-200 bg-white border border-zinc-200" style="height:26px;width:26px"><BellOff class="w-3 h-3" /></button>
                                            <div v-if="snoozeOpenId === f.id" class="absolute right-0 top-full mt-1 z-50 bg-white border border-zinc-200/80 rounded-lg shadow-lg py-1 min-w-[100px]">
                                                <button v-for="opt in snoozeOptions" :key="opt.days" @click="snoozeFollowup(f, opt.days)" class="w-full text-left px-3 py-1.5 text-xs hover:bg-[var(--accent)]">{{ opt.label }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Stage 2 Expanded Detail — 2-column layout -->
                            <div v-if="expandedFollowup === f.id" class="exp-detail" style="border-left:3px solid #D4622B">
                                <div v-if="followupDetailLoading && followupAiLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span><span class="text-sm text-zinc-500 ml-2">KI-Entwurf wird erstellt...</span></div>
                                <div v-else class="grid gap-4 p-4 two-col-grid">
                                    <!-- LEFT: KI Editor -->
                                    <div class="space-y-2 min-w-0">
                                        <!-- Call script -->
                                        <div v-if="followupAiDraft?.callScript" class="rounded-xl overflow-hidden" style="border:1px solid rgba(16,185,129,0.25)">
                                            <div class="px-4 py-2.5 flex items-center gap-2" style="background:rgba(16,185,129,0.06)">
                                                <Phone class="w-3.5 h-3.5" style="color:#10b981" />
                                                <span class="text-xs font-semibold" style="color:#10b981">Anrufen empfohlen</span>
                                                <a v-if="followupAiDraft?.phone" :href="'tel:' + followupAiDraft.phone" class="text-xs font-mono ml-1 underline" style="color:#10b981">{{ followupAiDraft.phone }}</a>
                                            </div>
                                            <div class="px-4 py-2.5 text-xs whitespace-pre-line" style="border-top:1px solid rgba(16,185,129,0.15);background:white">{{ followupAiDraft.callScript }}</div>
                                        </div>
                                        <!-- Lead badges -->
                                        <div v-if="followupAiDraft?.leadStatus || followupAiDraft?.leadPhase" class="flex items-center gap-2 flex-wrap">
                                            <span v-if="followupAiDraft?.leadPhase" class="text-[10px] font-bold px-2 py-1 rounded-lg" style="background:#D4622B;color:#fff">Phase {{ followupAiDraft.leadPhase }}</span>
                                            <span v-if="followupAiDraft?.mailType" class="text-[10px] font-medium px-2 py-1 rounded-lg" style="background:rgba(212,98,43,0.12);color:#7c3aed">Typ {{ followupAiDraft.mailType }}</span>
                                            <span v-if="followupAiDraft?.leadStatus" class="text-xs text-zinc-500">{{ followupAiDraft.leadStatus }}</span>
                                        </div>
                                        <div v-if="followupAiLoading" class="flex items-center justify-center gap-2 py-8">
                                            <span class="spinner" style="width:16px;height:16px"></span>
                                            <span class="text-sm text-zinc-500">KI-Entwurf wird generiert...</span>
                                        </div>
                                        <template v-else-if="followupAiDraft?.body">
                                            <!-- To + Subject (compact, expandable) -->
                                            <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);background:white">
                                                <div v-if="!showFollowupEmailFields" @click.stop="showFollowupEmailFields = true"
                                                    class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none hover:bg-[var(--accent)] transition-colors">
                                                    <Mail class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                    <span class="text-sm truncate flex-1 min-w-0"><span class="text-zinc-500">An:</span> <span class="font-medium">{{ followupAiDraft.to || 'Keine Adresse' }}</span></span>
                                                    <ChevronDown class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                </div>
                                                <template v-if="showFollowupEmailFields">
                                                    <div class="flex items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border)">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">An:</span>
                                                        <input v-model="followupAiDraft.to" class="flex-1 text-sm bg-transparent outline-none min-w-0" style="color:#18181b" placeholder="E-Mail-Adresse..." />
                                                        <button @click.stop="saveRecipientEmail(expandedFollowupItem?.from_name, expandedFollowupItem?.property_id, followupAiDraft.to)"
                                                            class="flex-shrink-0 px-2 py-1 rounded-lg text-[10px] font-medium transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                                            :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534' : 'color:#71717a'">
                                                            <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                                            <Check v-else-if="recipientEmailSaved" class="w-3.5 h-3.5" style="color:#166534" />
                                                            <span v-else>Speichern</span>
                                                        </button>
                                                    </div>
                                                    <div class="flex items-center gap-2 px-3 py-2">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">Betr:</span>
                                                        <input v-model="followupAiDraft.subject" class="flex-1 text-sm font-medium bg-transparent outline-none min-w-0" style="color:#18181b" />
                                                    </div>
                                                </template>
                                            </div>
                                            <!-- Textarea -->
                                            <textarea v-model="followupAiDraft.body"
                                                class="w-full rounded-xl p-4 text-sm leading-relaxed resize-y"
                                                style="border:1px solid rgba(228,228,231,0.8);background:white;color:#18181b;min-height:220px;font-family:inherit;outline:none"
                                                @focus="$event.target.style.borderColor='#D4622B'"
                                                @blur="$event.target.style.borderColor='var(--border)'"
                                            ></textarea>
                                            <!-- Toolbar -->
                                            <div class="flex items-center gap-2 pt-1">
                                                <select v-model="followupDetailLevel" @change="setFollowupDetailLevel(followupDetailLevel)"
                                                    class="h-10 sm:h-9 text-xs font-medium rounded-xl px-2.5 cursor-pointer"
                                                    style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                                                    <option value="brief">Knapp</option>
                                                    <option value="standard">Standard</option>
                                                    <option value="detailed">Ausführlich</option>
                                                </select>
                                                <button @click="regenerateFollowupDraft(f)" class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors" style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Neu generieren">
                                                    <Sparkles class="w-4 h-4" />
                                                </button>
                                                <div class="flex-1"></div>
                                                <button @click="markHandled(f.from_name, f.property_id)"
                                                    class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                    style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Erledigt">
                                                    <CheckCircle class="w-4 h-4" />
                                                </button>
                                                <button @click="sendFollowupDraft(f)" :disabled="followupSending"
                                                    class="h-10 sm:h-9 px-5 rounded-xl flex items-center justify-center gap-2 transition-all font-semibold text-sm"
                                                    style="background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none;box-shadow:0 2px 8px rgba(212,98,43,0.3)">
                                                    <span v-if="followupSending" class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span>
                                                    <Send v-else class="w-4 h-4" />
                                                    <span>Senden</span>
                                                </button>
                                            </div>
                                        </template>
                                        <div v-else-if="!followupAiLoading" class="text-sm text-zinc-500 py-4 text-center">KI-Entwurf konnte nicht erstellt werden.</div>
                                    </div>
                                    <!-- RIGHT: Thread / Context -->
                                    <div class="space-y-2 min-w-0">
                                        <div v-if="followupDetailLoading" class="flex items-center justify-center py-8"><span class="spinner" style="width:16px;height:16px"></span></div>
                                        <template v-else-if="followupDetail">
                                            <!-- Original Email -->
                                            <div v-if="followupDetail.email" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="followupBodyFull = !followupBodyFull" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background:#D4622B">
                                                        {{ (followupDetail.email.from_name || followupDetail.email.stakeholder || '?').charAt(0).toUpperCase() }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold truncate">{{ followupDetail.email.from_name || followupDetail.email.stakeholder }}</div>
                                                        <div class="text-xs text-zinc-500 truncate">{{ followupDetail.email.subject }}</div>
                                                    </div>
                                                    <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ formatDetailDate(followupDetail.email.email_date) }}</span>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="followupBodyFull ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="followupBodyFull" class="px-4 py-3 text-sm leading-relaxed whitespace-pre-line" style="border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word;background:white">{{ stripQuotedReply(followupDetail.email.body_text || followupDetail.email.ai_summary || 'Kein Inhalt') }}</div>
                                            </div>
                                            <!-- Thread / Verlauf -->
                                            <div v-if="followupDetail.thread.length > 0" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="showFollowupThread = !showFollowupThread" class="px-4 py-2.5 flex items-center gap-2 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <Clock class="w-3.5 h-3.5 text-zinc-500" />
                                                    <span class="text-xs font-semibold text-zinc-500">Verlauf</span>
                                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background:rgba(244,244,245,0.8);color:#71717a">{{ followupDetail.thread.length }}</span>
                                                    <div class="flex-1"></div>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="showFollowupThread ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="showFollowupThread" style="border-top:1px solid var(--border)">
                                                    <div v-for="(msg, mi) in followupDetail.thread" :key="mi"
                                                        class="cursor-pointer transition-colors hover:bg-[var(--accent)]"
                                                        :style="mi > 0 ? 'border-top:1px solid var(--border)' : ''"
                                                        @click.stop="followupThreadMsg = (followupThreadMsg === (msg.id || mi) ? null : (msg.id || mi))">
                                                        <div class="px-4 py-2 flex items-center gap-2">
                                                            <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0" :style="msg.direction === 'outbound' ? 'background:#3b82f6' : 'background:#6b7280'">
                                                                {{ msg.direction === 'outbound' ? '↑' : '↓' }}
                                                            </div>
                                                            <span class="text-xs font-medium truncate flex-1">{{ msg.direction === 'outbound' ? 'SR-Homes' : (msg.from_name || f.from_name) }}</span>
                                                            <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ (() => { const dt = msg.datetime || msg.created_at; const d = msg.date || msg.activity_date; if (dt) return dt.substring(5,10).split('-').reverse().join('.') + ' ' + dt.substring(11,16); if (d) return d.substring(5,10).split('-').reverse().join('.'); return ''; })() }}</span>
                                                        </div>
                                                        <div v-if="followupThreadMsg === (msg.id || mi)" class="px-4 py-2.5 text-xs leading-relaxed whitespace-pre-line" style="background:white;border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word">
                                                            <div class="text-[11px] font-semibold text-zinc-500 mb-1">{{ msg.email_subject || msg.subject || msg.text || '' }}</div>
                                                            <template v-if="msg.full_body && msg.full_body.trim()">{{ msg.full_body }}</template>
                                                            <template v-else-if="msg.body_text && msg.body_text.trim()">{{ msg.body_text }}</template>
                                                            <template v-else-if="msg.ai_summary">{{ msg.ai_summary }}</template>
                                                            <template v-else-if="msg.text">{{ msg.text }}</template>
                                                            <template v-else><em class="text-zinc-500">Kein Inhalt</em></template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Info group (3-7 days) -->
                <div v-if="infoFollowups.length" class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                    <div @click="toggleGroup('info')" class="px-4 py-1.5 flex items-center gap-2 cursor-pointer hover:brightness-95 transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" style="background:rgba(59,130,246,0.06);border-bottom:1px solid var(--border)">
                        <ChevronDown class="w-3.5 h-3.5 flex-shrink-0 transition-transform" :class="collapsedGroups.info ? '-rotate-90' : ''" style="color:#3b82f6" />
                        <span class="text-xs font-semibold" style="color:#3b82f6">Info (3-7 Tage)</span>
                        <span class="text-[10px] ml-1 font-bold px-1.5 rounded" style="background:rgba(59,130,246,0.06);color:#3b82f6">{{ infoFollowups.length }}</span>
                        <span class="flex-1"></span>
                        <button v-if="infoFollowups.length && !collapsedGroups.info" @click.stop="requestSendAll('info', infoFollowups)" :disabled="sendAllRunning"
                            class="text-[10px] px-2.5 py-1 rounded-lg font-semibold transition-colors text-white" style="background:#3b82f6">
                            <template v-if="sendAllRunning === 'info'">{{ sendAllProgress.sent }}/{{ sendAllProgress.total }}...</template>
                            <template v-else>Alle senden ({{ infoFollowups.length }})</template>
                        </button>
                    </div>
                    <div v-if="collapsedGroups.info" class="px-4 py-2 text-[10px] text-zinc-500">{{ infoFollowups.length }} Einträge eingeklappt</div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <template v-for="f in infoFollowups" :key="f.id">
                            <div @click="toggleFollowupDetail(f)" class="px-4 py-3 hover:bg-[var(--accent)] active:bg-[var(--accent)] transition-colors group cursor-pointer">
                                <!-- Top row: name + days -->
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-semibold truncate flex-1 min-w-0">{{ f.from_name }}</span>
                                    <span class="text-xs font-bold tabular-nums px-1.5 py-0.5 rounded-lg flex-shrink-0" :class="f.days_waiting >= 14 ? 'bg-red-50 text-red-600' : f.days_waiting >= 7 ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600'">{{ f.days_waiting }}d</span>
                                    <ChevronDown class="w-4 h-4 text-zinc-400 flex-shrink-0 transition-transform" :class="expandedFollowup === f.id ? 'rotate-180' : ''" />
                                </div>
                                <!-- Bottom row: meta + actions -->
                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap" @click.stop>
                                    <span class="badge badge-muted text-[10px]">{{ f.ref_id }}</span>
                                    <span v-if="f.contact_phone" class="text-[10px] flex items-center gap-0.5" style="color:#71717a"><Phone class="w-2.5 h-2.5" />{{ f.contact_phone }}</span>
                                    <span v-if="isAssistenz && f.broker_name" class="text-[10px] font-medium px-2 py-0.5 rounded-full flex-shrink-0" style="background:rgba(238,118,6,0.08);color:#D4622B">{{ f.broker_name }}</span>
                                    <div class="flex gap-1 ml-auto" :class="bulkMode ? '' : ''">
                                        <button @click.stop="toggleFollowupDetail(f)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all duration-200 active:scale-[0.97]" style="height:26px;background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none"><Sparkles class="w-3 h-3" /><span class="hidden sm:inline">KI-Entwurf</span></button>
                                        <button @click.stop="markHandled(f.from_name, f.property_id)" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-white text-zinc-600 border border-zinc-200 rounded-lg hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97]" style="height:26px"><CheckCircle class="w-3 h-3" /></button>
                                        <div class="relative">
                                            <button @click.stop="snoozeOpenId = snoozeOpenId === f.id ? null : f.id" class="inline-flex items-center justify-center rounded-lg hover:bg-zinc-100 transition-all duration-200 bg-white border border-zinc-200" style="height:26px;width:26px"><BellOff class="w-3 h-3" /></button>
                                            <div v-if="snoozeOpenId === f.id" class="absolute right-0 top-full mt-1 z-50 bg-white border border-zinc-200/80 rounded-lg shadow-lg py-1 min-w-[100px]">
                                                <button v-for="opt in snoozeOptions" :key="opt.days" @click="snoozeFollowup(f, opt.days)" class="w-full text-left px-3 py-1.5 text-xs hover:bg-[var(--accent)]">{{ opt.label }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Stage 2 Expanded Detail — 2-column layout -->
                            <div v-if="expandedFollowup === f.id" class="exp-detail" style="border-left:3px solid #D4622B">
                                <div v-if="followupDetailLoading && followupAiLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span><span class="text-sm text-zinc-500 ml-2">KI-Entwurf wird erstellt...</span></div>
                                <div v-else class="grid gap-4 p-4 two-col-grid">
                                    <!-- LEFT: KI Editor -->
                                    <div class="space-y-2 min-w-0">
                                        <!-- Call script -->
                                        <div v-if="followupAiDraft?.callScript" class="rounded-xl overflow-hidden" style="border:1px solid rgba(16,185,129,0.25)">
                                            <div class="px-4 py-2.5 flex items-center gap-2" style="background:rgba(16,185,129,0.06)">
                                                <Phone class="w-3.5 h-3.5" style="color:#10b981" />
                                                <span class="text-xs font-semibold" style="color:#10b981">Anrufen empfohlen</span>
                                                <a v-if="followupAiDraft?.phone" :href="'tel:' + followupAiDraft.phone" class="text-xs font-mono ml-1 underline" style="color:#10b981">{{ followupAiDraft.phone }}</a>
                                            </div>
                                            <div class="px-4 py-2.5 text-xs whitespace-pre-line" style="border-top:1px solid rgba(16,185,129,0.15);background:white">{{ followupAiDraft.callScript }}</div>
                                        </div>
                                        <!-- Lead badges -->
                                        <div v-if="followupAiDraft?.leadStatus || followupAiDraft?.leadPhase" class="flex items-center gap-2 flex-wrap">
                                            <span v-if="followupAiDraft?.leadPhase" class="text-[10px] font-bold px-2 py-1 rounded-lg" style="background:#D4622B;color:#fff">Phase {{ followupAiDraft.leadPhase }}</span>
                                            <span v-if="followupAiDraft?.mailType" class="text-[10px] font-medium px-2 py-1 rounded-lg" style="background:rgba(212,98,43,0.12);color:#7c3aed">Typ {{ followupAiDraft.mailType }}</span>
                                            <span v-if="followupAiDraft?.leadStatus" class="text-xs text-zinc-500">{{ followupAiDraft.leadStatus }}</span>
                                        </div>
                                        <div v-if="followupAiLoading" class="flex items-center justify-center gap-2 py-8">
                                            <span class="spinner" style="width:16px;height:16px"></span>
                                            <span class="text-sm text-zinc-500">KI-Entwurf wird generiert...</span>
                                        </div>
                                        <template v-else-if="followupAiDraft?.body">
                                            <!-- To + Subject (compact, expandable) -->
                                            <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8);background:white">
                                                <div v-if="!showFollowupEmailFields" @click.stop="showFollowupEmailFields = true"
                                                    class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none hover:bg-[var(--accent)] transition-colors">
                                                    <Mail class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                    <span class="text-sm truncate flex-1 min-w-0"><span class="text-zinc-500">An:</span> <span class="font-medium">{{ followupAiDraft.to || 'Keine Adresse' }}</span></span>
                                                    <ChevronDown class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" />
                                                </div>
                                                <template v-if="showFollowupEmailFields">
                                                    <div class="flex items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border)">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">An:</span>
                                                        <input v-model="followupAiDraft.to" class="flex-1 text-sm bg-transparent outline-none min-w-0" style="color:#18181b" placeholder="E-Mail-Adresse..." />
                                                        <button @click.stop="saveRecipientEmail(expandedFollowupItem?.from_name, expandedFollowupItem?.property_id, followupAiDraft.to)"
                                                            class="flex-shrink-0 px-2 py-1 rounded-lg text-[10px] font-medium transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                                            :style="recipientEmailSaved ? 'background:#dcfce7;color:#166534' : 'color:#71717a'">
                                                            <span v-if="recipientEmailSaving" class="spinner" style="width:10px;height:10px"></span>
                                                            <Check v-else-if="recipientEmailSaved" class="w-3.5 h-3.5" style="color:#166534" />
                                                            <span v-else>Speichern</span>
                                                        </button>
                                                    </div>
                                                    <div class="flex items-center gap-2 px-3 py-2">
                                                        <span class="text-xs text-zinc-500 flex-shrink-0 w-8">Betr:</span>
                                                        <input v-model="followupAiDraft.subject" class="flex-1 text-sm font-medium bg-transparent outline-none min-w-0" style="color:#18181b" />
                                                    </div>
                                                </template>
                                            </div>
                                            <!-- Textarea -->
                                            <textarea v-model="followupAiDraft.body"
                                                class="w-full rounded-xl p-4 text-sm leading-relaxed resize-y"
                                                style="border:1px solid rgba(228,228,231,0.8);background:white;color:#18181b;min-height:220px;font-family:inherit;outline:none"
                                                @focus="$event.target.style.borderColor='#D4622B'"
                                                @blur="$event.target.style.borderColor='var(--border)'"
                                            ></textarea>
                                            <!-- Toolbar -->
                                            <div class="flex items-center gap-2 pt-1">
                                                <select v-model="followupDetailLevel" @change="setFollowupDetailLevel(followupDetailLevel)"
                                                    class="h-10 sm:h-9 text-xs font-medium rounded-xl px-2.5 cursor-pointer"
                                                    style="background:white;color:#18181b;border:1px solid rgba(228,228,231,0.8)">
                                                    <option value="brief">Knapp</option>
                                                    <option value="standard">Standard</option>
                                                    <option value="detailed">Ausführlich</option>
                                                </select>
                                                <button @click="regenerateFollowupDraft(f)" class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors" style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Neu generieren">
                                                    <Sparkles class="w-4 h-4" />
                                                </button>
                                                <div class="flex-1"></div>
                                                <button @click="markHandled(f.from_name, f.property_id)"
                                                    class="h-10 sm:h-9 w-10 sm:w-9 rounded-xl flex items-center justify-center transition-colors"
                                                    style="background:white;color:#71717a;border:1px solid rgba(228,228,231,0.8)" title="Erledigt">
                                                    <CheckCircle class="w-4 h-4" />
                                                </button>
                                                <button @click="sendFollowupDraft(f)" :disabled="followupSending"
                                                    class="h-10 sm:h-9 px-5 rounded-xl flex items-center justify-center gap-2 transition-all font-semibold text-sm"
                                                    style="background:linear-gradient(135deg,#D4622B,#c25a25);color:#fff;border:none;box-shadow:0 2px 8px rgba(212,98,43,0.3)">
                                                    <span v-if="followupSending" class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,0.3);border-top-color:#fff"></span>
                                                    <Send v-else class="w-4 h-4" />
                                                    <span>Senden</span>
                                                </button>
                                            </div>
                                        </template>
                                        <div v-else-if="!followupAiLoading" class="text-sm text-zinc-500 py-4 text-center">KI-Entwurf konnte nicht erstellt werden.</div>
                                    </div>
                                    <!-- RIGHT: Thread / Context -->
                                    <div class="space-y-2 min-w-0">
                                        <div v-if="followupDetailLoading" class="flex items-center justify-center py-8"><span class="spinner" style="width:16px;height:16px"></span></div>
                                        <template v-else-if="followupDetail">
                                            <!-- Original Email -->
                                            <div v-if="followupDetail.email" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="followupBodyFull = !followupBodyFull" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background:#D4622B">
                                                        {{ (followupDetail.email.from_name || followupDetail.email.stakeholder || '?').charAt(0).toUpperCase() }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold truncate">{{ followupDetail.email.from_name || followupDetail.email.stakeholder }}</div>
                                                        <div class="text-xs text-zinc-500 truncate">{{ followupDetail.email.subject }}</div>
                                                    </div>
                                                    <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ formatDetailDate(followupDetail.email.email_date) }}</span>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="followupBodyFull ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="followupBodyFull" class="px-4 py-3 text-sm leading-relaxed whitespace-pre-line" style="border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word;background:white">{{ stripQuotedReply(followupDetail.email.body_text || followupDetail.email.ai_summary || 'Kein Inhalt') }}</div>
                                            </div>
                                            <!-- Thread / Verlauf -->
                                            <div v-if="followupDetail.thread.length > 0" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                                                <div @click.stop="showFollowupThread = !showFollowupThread" class="px-4 py-2.5 flex items-center gap-2 cursor-pointer select-none transition-colors hover:bg-[var(--accent)]" style="background:white">
                                                    <Clock class="w-3.5 h-3.5 text-zinc-500" />
                                                    <span class="text-xs font-semibold text-zinc-500">Verlauf</span>
                                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background:rgba(244,244,245,0.8);color:#71717a">{{ followupDetail.thread.length }}</span>
                                                    <div class="flex-1"></div>
                                                    <ChevronDown class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200" :class="showFollowupThread ? 'rotate-180' : ''" />
                                                </div>
                                                <div v-if="showFollowupThread" style="border-top:1px solid var(--border)">
                                                    <div v-for="(msg, mi) in followupDetail.thread" :key="mi"
                                                        class="cursor-pointer transition-colors hover:bg-[var(--accent)]"
                                                        :style="mi > 0 ? 'border-top:1px solid var(--border)' : ''"
                                                        @click.stop="followupThreadMsg = (followupThreadMsg === (msg.id || mi) ? null : (msg.id || mi))">
                                                        <div class="px-4 py-2 flex items-center gap-2">
                                                            <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0" :style="msg.direction === 'outbound' ? 'background:#3b82f6' : 'background:#6b7280'">
                                                                {{ msg.direction === 'outbound' ? '↑' : '↓' }}
                                                            </div>
                                                            <span class="text-xs font-medium truncate flex-1">{{ msg.direction === 'outbound' ? 'SR-Homes' : (msg.from_name || f.from_name) }}</span>
                                                            <span class="text-[10px] text-zinc-500 flex-shrink-0 tabular-nums">{{ (() => { const dt = msg.datetime || msg.created_at; const d = msg.date || msg.activity_date; if (dt) return dt.substring(5,10).split('-').reverse().join('.') + ' ' + dt.substring(11,16); if (d) return d.substring(5,10).split('-').reverse().join('.'); return ''; })() }}</span>
                                                        </div>
                                                        <div v-if="followupThreadMsg === (msg.id || mi)" class="px-4 py-2.5 text-xs leading-relaxed whitespace-pre-line" style="background:white;border-top:1px solid var(--border);max-height:300px;overflow-y:auto;word-break:break-word">
                                                            <div class="text-[11px] font-semibold text-zinc-500 mb-1">{{ msg.email_subject || msg.subject || msg.text || '' }}</div>
                                                            <template v-if="msg.full_body && msg.full_body.trim()">{{ msg.full_body }}</template>
                                                            <template v-else-if="msg.body_text && msg.body_text.trim()">{{ msg.body_text }}</template>
                                                            <template v-else-if="msg.ai_summary">{{ msg.ai_summary }}</template>
                                                            <template v-else-if="msg.text">{{ msg.text }}</template>
                                                            <template v-else><em class="text-zinc-500">Kein Inhalt</em></template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div v-if="!kaufanbotFollowups.length && !urgentFollowups.length && !warningFollowups.length && !infoFollowups.length"
                    class="bg-white rounded-2xl overflow-hidden px-6 py-8 text-center text-zinc-500 text-sm">
                    Keine Nachfass-Fälle
                </div>
            </div>
        </div>

        <!-- ============ KI-HINWEISE ============ -->
        <div v-if="activeSubTab === 'insights'">
            <div v-if="alertsLoading" class="px-6 py-8 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
            <div v-else class="space-y-4">
                <div v-if="alerts.length" class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                    <div class="px-4 py-2.5 flex items-center gap-2 border-b border-zinc-200/80">
                        <AlertCircle class="w-4 h-4 text-zinc-500" />
                        <span class="text-sm font-semibold">Proaktive Hinweise</span>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-zinc-50 ml-auto">{{ alerts.length }}</span>
                    </div>
                    <div class="divide-y divide-[var(--border)]">
                        <div v-for="alert in alerts" :key="alert.id"
                            class="px-4 py-2.5 flex items-center gap-3"
                            :class="{ 'bg-red-500/5': alert.severity === 'urgent', 'bg-amber-500/5': alert.severity === 'warning', 'bg-blue-500/5': alert.severity === 'info' }">
                            <div class="flex-shrink-0">
                                <AlertCircle v-if="alert.severity === 'urgent'" class="w-4 h-4 text-red-500" />
                                <AlertTriangle v-else-if="alert.severity === 'warning'" class="w-4 h-4 text-amber-500" />
                                <Info v-else class="w-4 h-4 text-blue-500" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <span class="text-[13px] font-semibold"
                                    :class="{ 'text-red-600 dark:text-red-400': alert.severity === 'urgent', 'text-amber-600 dark:text-amber-400': alert.severity === 'warning', 'text-blue-600 dark:text-blue-400': alert.severity === 'info' }">{{ alert.title }}</span>
                                <p class="text-xs text-zinc-500 mt-0.5">{{ alert.message }}</p>
                            </div>
                            <button v-if="alert.action" @click="alertActionClick(alert.action)"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-white text-zinc-600 border border-zinc-200 rounded-xl hover:bg-zinc-50 hover:border-zinc-300 transition-all duration-200 active:scale-[0.97] flex-shrink-0" style="height:26px;font-size:11px">
                                {{ alert.action.label }} <ArrowRight class="w-3 h-3" />
                            </button>
                            <button @click="dismissAlert(alert.id)" class="flex-shrink-0 w-6 h-6 rounded flex items-center justify-center hover:bg-[var(--accent)]">
                                <X class="w-3.5 h-3.5 text-zinc-500" />
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="!alerts.length" class="bg-white rounded-2xl overflow-hidden px-6 py-8 text-center text-zinc-500 text-sm">
                    Keine KI-Hinweise vorhanden
                </div>
            </div>
        </div>

        <!-- ============ KI-EMPFEHLUNGEN / MATCHING ============ -->
        <div v-if="activeSubTab === 'matching'">
            <div v-if="matchesLoading" class="text-center py-8"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
            <div v-else-if="!groupedByContact.length" class="bg-white rounded-2xl overflow-hidden text-center py-12">
                <Sparkles class="w-10 h-10 mx-auto mb-3 opacity-30" />
                <p class="text-sm text-zinc-500">Keine KI-Empfehlungen vorhanden</p>
                <p class="text-xs text-zinc-500 mt-1">Kontakte werden automatisch gematcht wenn Stadt, Typ und Preis ähnlich sind.</p>
            </div>
            <div v-else class="space-y-2">
                <p class="text-xs text-zinc-500 mb-3">Kontakte die auch zu anderen Objekten passen — basierend auf Stadt, Typ und Preisklasse. Exposé per E-Mail senden.</p>
                <div v-for="contact in groupedByContact" :key="contact.name" class="bg-white rounded-2xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                    <div @click="expandedContact = expandedContact === contact.name ? null : contact.name"
                        class="px-5 py-3 flex items-center gap-3 cursor-pointer hover:bg-[var(--accent)] transition-colors">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background:linear-gradient(135deg,#D4622B,#b5491f)">
                            {{ contact.name[0].toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold">{{ contact.name }}</div>
                            <div class="text-[11px] text-zinc-500">
                                Aktiv bei <span class="font-medium text-zinc-900">{{ contact.original.ref_id }}</span> ({{ contact.original.address }})
                                <span v-if="contact.last_contact"> &middot; {{ new Date(contact.last_contact).toLocaleDateString('de-AT') }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-[10px] font-bold px-2 py-1 rounded-full" style="background:rgba(212,98,43,0.1);color:#D4622B">
                                Passt zu {{ contact.suggestions.length }} {{ contact.suggestions.length === 1 ? 'Objekt' : 'Objekten' }}
                            </span>
                            <ChevronDown class="w-4 h-4 text-zinc-500 transition-transform" :class="expandedContact === contact.name ? 'rotate-180' : ''" />
                        </div>
                    </div>
                    <div v-if="expandedContact === contact.name" class="border-t border-zinc-200/80 px-5 py-3 space-y-2">
                        <div class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wide mb-2">Kunde passt zu</div>
                        <div v-for="(s, si) in contact.suggestions" :key="si"
                            class="flex items-center gap-3 p-3 rounded-xl bg-zinc-50 hover:bg-[var(--accent)] transition-colors">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(212,98,43,0.1)">
                                <Home class="w-4 h-4" style="color:#D4622B" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-1.5 mb-0.5">
                                    <span class="text-xs font-bold">{{ s.property.address }}</span>
                                    <span class="badge badge-purple text-[9px]">{{ s.property.ref_id }}</span>
                                    <span v-if="s.property.purchase_price" class="text-[10px] text-zinc-500">&euro; {{ Number(s.property.purchase_price).toLocaleString('de-AT') }}</span>
                                </div>
                                <div class="text-[10px] text-zinc-500">
                                    <span v-for="(reason, ri) in s.reasons" :key="ri">{{ reason }}<span v-if="ri < s.reasons.length - 1"> &middot; </span></span>
                                </div>
                            </div>
                            <button @click.stop="sendExposeEmail(contact.name, s)"
                                :disabled="matchAccepting === contact.name + '|' + s.property.id"
                                class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97] flex items-center gap-1 flex-shrink-0"
                                style="height:30px;font-size:11px;background:linear-gradient(135deg,#f97316,#ea580c);color:white;border:none;box-shadow:0 2px 6px rgba(249,115,22,0.3)">
                                <Send class="w-3.5 h-3.5" /> Exposé senden
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ AUTO-REPLY POPUP ============ -->
        <div v-if="showAutoReplyPopup" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showAutoReplyPopup = false">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showAutoReplyPopup = false"></div>
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 max-h-[85vh] overflow-hidden border border-zinc-200/80">
                <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border)">
                    <div>
                        <h2 class="text-lg font-bold">Auto-Replies</h2>
                        <p class="text-xs text-zinc-500">{{ autoReplyLogs.length }} automatisch gesendete Mails (letzte 24h)</p>
                    </div>
                    <button @click="showAutoReplyPopup = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                </div>
                <div class="overflow-y-auto" style="max-height:calc(85vh - 80px)">
                    <div v-if="autoReplyLoading" class="px-6 py-8 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                    <div v-else-if="!autoReplyLogs.length" class="px-6 py-12 text-center text-sm text-zinc-500">Keine automatischen Antworten in den letzten 24 Stunden.</div>
                    <div v-else class="divide-y divide-[var(--border)]">
                        <div v-for="log in autoReplyLogs" :key="log.id" class="px-6 py-3">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-semibold">{{ log.to_email }}</span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                                    :style="log.status === 'sent' ? 'background:rgba(16,185,129,0.08);color:#10b981' : log.status === 'failed' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : 'background:rgba(245,158,11,0.08);color:#f59e0b'">
                                    {{ log.status === 'sent' ? 'Gesendet' : log.status === 'failed' ? 'Fehlgeschlagen' : 'Übersprungen' }}
                                </span>
                            </div>
                            <div class="text-xs text-zinc-500 mb-0.5">{{ log.subject }}</div>
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] text-zinc-500">{{ log.address || 'Unbekannt' }}{{ log.city ? ', ' + log.city : '' }}</span>
                                <span class="text-[10px] text-zinc-500">{{ new Date(log.created_at).toLocaleString('de-AT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) }}</span>
                            </div>
                            <div v-if="log.attachments" class="mt-1 flex items-center gap-1">
                                <Paperclip class="w-3 h-3 text-zinc-500" />
                                <span class="text-[10px] text-zinc-500">{{ log.attachments }}</span>
                            </div>
                            <div v-if="log.error_message" class="mt-1 text-[10px] text-red-500">{{ log.error_message }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ KAUFANBOTE POPUP ============ -->
        <div v-if="showKaufanbotePopup" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="showKaufanbotePopup = false">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showKaufanbotePopup = false"></div>
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 max-h-[85vh] overflow-hidden border border-zinc-200/80">
                <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border)">
                    <div>
                        <h2 class="text-lg font-bold">Kaufanbote</h2>
                        <p class="text-xs text-zinc-500">{{ realKaufanbotePrio.length }} hochgeladene Kaufanbote</p>
                    </div>
                    <button @click="showKaufanbotePopup = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                </div>
                <div class="overflow-y-auto" style="max-height:calc(85vh - 80px)">
                    <div v-if="kanbanLoading" class="px-6 py-8 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                    <div v-else-if="!kaufanboteByProperty.length" class="px-6 py-12 text-center text-sm text-zinc-500">Noch keine Kaufanbote hochgeladen.</div>
                    <div v-else class="px-6 py-4 space-y-4">
                        <div v-for="group in kaufanboteByProperty" :key="group.property_id" class="rounded-xl overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                            <div class="px-4 py-2.5 text-xs font-bold" style="background:rgba(244,244,245,0.8)">{{ group.address }}</div>
                            <div class="divide-y divide-[var(--border)]">
                                <div v-for="ka in group.items" :key="ka.unit_id" class="px-4 py-3">
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold">{{ ka.buyer_name || 'Kein Name' }}</span>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                                                :style="ka.status === 'verkauft' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : ka.status === 'reserviert' ? 'background:rgba(245,158,11,0.08);color:#f59e0b' : 'background:rgba(16,185,129,0.08);color:#10b981'">{{ ka.status }}</span>
                                        </div>
                                        <span class="text-sm font-bold" style="color:#D4622B">&euro; {{ Number(ka.total_price || 0).toLocaleString('de-DE') }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] text-zinc-500">{{ ka.unit_number }} &middot; {{ ka.area_m2 }} m&sup2;</span>
                                        <a :href="'/storage/' + ka.kaufanbot_pdf" target="_blank" class="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-1 rounded-lg" style="color:#D4622B;background:rgba(238,118,6,0.06)">PDF</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ PAUSIERT ============ -->
        <div v-if="activeSubTab === 'onhold'">
            <div v-if="onHoldList.length" class="bg-white rounded-2xl overflow-hidden overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-zinc-200/80">
                    <Pause class="w-4 h-4 text-zinc-500" />
                    <span class="text-sm font-semibold">Pausierte Objekte</span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-zinc-50 ml-auto">{{ onHoldList.length }}</span>
                </div>
                <div class="divide-y divide-[var(--border)]">
                    <div v-for="item in onHoldList" :key="item.property_id" class="px-4 py-2.5 flex items-center gap-3 hover:bg-[var(--accent)] transition-colors">
                        <span class="text-sm font-medium flex-1">{{ item.address }}</span>
                        <span v-if="item.note" class="text-xs text-zinc-500 truncate" style="max-width:200px">{{ item.note }}</span>
                        <button @click="removeOnHold(item.property_id)" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-white text-zinc-600 border border-zinc-200 rounded-xl hover:bg-zinc-50 hover:border-zinc-300 transition-all duration-200 active:scale-[0.97] flex-shrink-0" style="color:#10b981;height:26px;font-size:11px"><Play class="w-3 h-3" /> Aktivieren</button>
                    </div>
                </div>
            </div>
            <div v-else class="bg-white rounded-2xl overflow-hidden px-6 py-8 text-center text-zinc-500 text-sm">
                Keine pausierten Objekte
            </div>
        </div>
    </div>

    <!-- ============ EXPOSÉ SENDE-POPUP ============ -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="exposePreview" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="exposePreview = null">
                <div class="bg-white rounded-2xl shadow-2xl border border-zinc-200/80 w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden" @click.stop>
                    <div class="px-6 py-3 border-b border-zinc-200/80 flex items-center justify-between bg-gradient-to-r from-orange-50 to-transparent dark:from-purple-900/10">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white" style="background:linear-gradient(135deg,#D4622B,#b5491f)">
                                <Send class="w-4 h-4" />
                            </div>
                            <div>
                                <h2 class="text-sm font-bold">Exposé an {{ exposePreview.contactName }}</h2>
                                <p class="text-[11px] text-zinc-500">{{ exposePreview.suggestion.property.ref_id }} — {{ exposePreview.suggestion.property.address }}</p>
                            </div>
                        </div>
                        <button @click="exposePreview = null" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="(r, i) in exposePreview.suggestion.reasons" :key="i" class="badge badge-purple text-[10px]">{{ r }}</span>
                        </div>
                        <div v-if="exposePreview.loading" class="py-4 text-center"><span class="w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin inline-block"></span></div>
                        <div v-else-if="exposePreview.thread.length">
                            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wide">Bisheriger Verlauf</span>
                            <div class="mt-1.5 rounded-xl border border-zinc-200/80 overflow-hidden max-h-36 overflow-y-auto">
                                <div v-for="(msg, mi) in exposePreview.thread" :key="mi"
                                    class="px-3 py-1.5 text-[12px] flex items-start gap-2 border-b border-zinc-200/80 last:border-b-0"
                                    :class="msg.direction === 'out' ? 'bg-blue-50/50 dark:bg-blue-900/10' : 'bg-zinc-50'">
                                    <span class="font-mono text-[10px] text-zinc-500 flex-shrink-0 mt-0.5">{{ msg.date?.substring(5, 10) }}</span>
                                    <span class="px-1 py-0.5 rounded text-[9px] font-bold flex-shrink-0"
                                        :class="msg.direction === 'out' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'">
                                        {{ msg.direction === 'out' ? 'SR' : 'IN' }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <span class="font-medium text-[11px]">{{ msg.subject }}</span>
                                        <p class="text-zinc-500 line-clamp-2">{{ msg.body?.substring(0, 200) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 w-8">An:</span>
                                <input v-model="exposePreview.email" class="form-input flex-1" style="height:30px;font-size:12px" placeholder="E-Mail-Adresse..." />
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 w-8">Betr:</span>
                                <input v-model="exposePreview.subject" class="form-input flex-1" style="height:30px;font-size:12px" />
                            </div>
                            <textarea v-model="exposePreview.body" class="form-textarea w-full" rows="7" style="font-size:13px;line-height:1.6"></textarea>
                        </div>
                    </div>
                    <div class="px-6 py-3 border-t border-zinc-200/80 flex items-center justify-between bg-zinc-50">
                        <div class="text-[10px] text-zinc-500">{{ userName }} &middot; SR-Homes</div>
                        <div class="flex gap-2">
                            <button @click="exposePreview = null" class="px-3 py-1.5 text-xs font-medium text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200 btn-sm">Abbrechen</button>
                            <button @click="exposeSend()" :disabled="exposePreview.sending || !exposePreview.email || !exposePreview.body"
                                class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97] flex items-center gap-1.5"
                                style="background:linear-gradient(135deg,#f97316,#ea580c);color:white;border:none;box-shadow:0 2px 6px rgba(249,115,22,0.3)">
                                <Send class="w-3.5 h-3.5" />
                                {{ exposePreview.sending ? 'Sende...' : 'Exposé senden' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ============ KALENDER POPUP ============ -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showCalendar" class="fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showCalendar = false">
                <div class="bg-white w-full sm:w-auto sm:min-w-[560px] sm:max-w-[720px] rounded-t-2xl sm:rounded-2xl shadow-2xl border border-zinc-200/80 overflow-hidden" style="max-height:85vh">
                    <div class="px-4 py-3 flex items-center justify-between" style="background:rgba(14,165,233,0.06);border-bottom:1px solid var(--border)">
                        <div class="flex items-center gap-2">
                            <CalendarDays class="w-4 h-4 text-sky-600" />
                            <span class="text-sm font-semibold text-sky-700">Kalender</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="https://calendar.google.com" target="_blank" class="text-xs text-sky-600 hover:underline">Öffnen ↗</a>
                            <button @click="showCalendar = false" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                        </div>
                    </div>
                    <iframe :src="calendarEmbedUrl" style="width:100%;height:min(500px,70vh);border:none;display:block" frameborder="0"></iframe>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ============ NACHFASS-WIZARD OVERLAY ============ -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="wizardOpen" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm">
                <div class="bg-white rounded-2xl shadow-2xl border border-zinc-200/80 w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden" @click.stop>
                    <div class="px-6 py-4 border-b border-zinc-200/80 flex items-center justify-between bg-gradient-to-r from-[var(--brand-light)] to-transparent">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,#f97316,#ea580c)">
                                <Sparkles class="w-4.5 h-4.5 text-white" />
                            </div>
                            <div>
                                <h2 class="text-base font-bold">Alle Nachfassen</h2>
                                <p class="text-[11px] text-zinc-500">{{ wizardIndex + 1 }} von {{ wizardItems.length }} Kontakten</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] font-medium text-zinc-500">{{ wizardCompleted.length }} erledigt</span>
                            <button @click="wizardClose()" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                        </div>
                    </div>
                    <div class="h-1 bg-zinc-50">
                        <div class="h-full rounded-r transition-all duration-500" style="background:linear-gradient(90deg,#f97316,#ea580c)" :style="'width:' + wizardProgress + '%'"></div>
                    </div>
                    <div class="px-6 py-2 border-b border-zinc-200/80 flex gap-1 overflow-x-auto">
                        <button v-for="(item, i) in wizardItems" :key="i" @click="wizardIndex = i; loadWizardDraft(i)"
                            class="flex-shrink-0 px-2 py-1 rounded-md text-[10px] font-medium transition-all border"
                            :class="[
                                i === wizardIndex ? 'border-[var(--brand)] bg-[var(--brand-light)] text-[var(--brand)]' : 'border-transparent',
                                wizardCompleted.includes(i) ? 'opacity-40 line-through' : 'hover:bg-[var(--accent)]'
                            ]">
                            {{ item.from_name?.split(' ')[0] || 'K' + (i+1) }}
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                        <template v-if="wizardCurrent">
                            <div class="flex items-start gap-4 p-4 rounded-xl bg-zinc-50">
                                <div class="w-11 h-11 rounded-xl flex items-center justify-center font-bold text-sm text-white flex-shrink-0"
                                    :style="'background:' + (wizardCurrent.days_waiting >= 14 ? '#ef4444' : wizardCurrent.days_waiting >= 7 ? '#f59e0b' : '#3b82f6')">
                                    {{ (wizardCurrent.from_name || 'U')[0].toUpperCase() }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-sm">{{ wizardCurrent.from_name }}</div>
                                    <div class="text-xs text-zinc-500 flex items-center gap-2 mt-0.5">
                                        <span class="badge badge-muted text-[10px]">{{ wizardCurrent.ref_id }}</span>
                                        <span>{{ wizardCurrent.address }}, {{ wizardCurrent.city }}</span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1.5 text-[11px]">
                                        <span class="font-medium" :class="wizardCurrent.days_waiting >= 14 ? 'text-red-500' : wizardCurrent.days_waiting >= 7 ? 'text-amber-500' : 'text-blue-500'">
                                            {{ wizardCurrent.days_waiting }} Tage wartend
                                        </span>
                                        <span v-if="wizardPhone" class="flex items-center gap-1 text-emerald-600"><Phone class="w-3 h-3" /> {{ wizardPhone }}</span>
                                        <span v-if="wizardEmail" class="flex items-center gap-1 text-zinc-500"><Mail class="w-3 h-3" /> {{ wizardEmail }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="wizardThread.length" class="space-y-1">
                                <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wide">Bisheriger Verlauf</span>
                                <div class="rounded-xl border border-zinc-200/80 overflow-hidden max-h-40 overflow-y-auto">
                                    <div v-for="(msg, mi) in wizardThread" :key="mi"
                                        class="px-3 py-1.5 text-[12px] flex items-start gap-2 border-b border-zinc-200/80 last:border-b-0"
                                        :class="msg.direction === 'out' ? 'bg-blue-50/50 dark:bg-blue-900/10' : 'bg-zinc-50'">
                                        <span class="font-mono text-[10px] text-zinc-500 flex-shrink-0 mt-0.5">{{ msg.date?.substring(5, 10) }}</span>
                                        <span class="px-1 py-0.5 rounded text-[9px] font-bold flex-shrink-0"
                                            :class="msg.direction === 'out' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'">
                                            {{ msg.direction === 'out' ? 'SR' : 'IN' }}
                                        </span>
                                        <span class="text-zinc-900 flex-1">{{ msg.text }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="wizardPhone && wizardDraft?.preferred_action === 'call'" class="p-4 rounded-xl border-2 border-emerald-200 bg-emerald-50 dark:bg-emerald-900/10 dark:border-emerald-800">
                                <div class="flex items-center gap-2 mb-2">
                                    <Phone class="w-4 h-4 text-emerald-600" />
                                    <span class="text-sm font-bold text-emerald-700 dark:text-emerald-400">Anruf empfohlen</span>
                                </div>
                                <a :href="'tel:' + wizardPhone" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-white font-semibold text-sm mb-3 hover:opacity-90" style="background:linear-gradient(135deg,#10b981,#059669)">
                                    <Phone class="w-4 h-4" /> {{ wizardPhone }}
                                </a>
                                <div v-if="wizardDraft.call_script" class="mt-2">
                                    <span class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wide">Gesprächsleitfaden</span>
                                    <div class="mt-1 p-3 rounded-lg bg-white dark:bg-white text-sm whitespace-pre-line leading-relaxed border border-emerald-100 dark:border-emerald-800">{{ wizardDraft.call_script }}</div>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center gap-2 justify-between">
                                    <div class="flex items-center gap-2">
                                        <Mail class="w-4 h-4 text-zinc-500" />
                                        <span class="text-sm font-semibold">{{ wizardPhone && wizardDraft?.preferred_action === 'call' ? 'Alternativ: E-Mail' : 'E-Mail Entwurf' }}</span>
                                    </div>
                                    <button @click="wizardGenerateAiDraft()" :disabled="wizardDraftLoading"
                                        class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97] flex items-center gap-1.5" style="height:26px;font-size:11px;background:linear-gradient(135deg,#D4622B,#b5491f);color:white;border:none">
                                        <Sparkles class="w-3 h-3" :class="{ 'animate-spin': wizardDraftLoading }" />
                                        {{ wizardDraftLoading ? 'Generiere...' : 'KI Entwurf' }}
                                    </button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-zinc-500 w-8">An:</span>
                                    <input v-model="wizardEmail" class="form-input flex-1" style="height:30px;font-size:12px" placeholder="E-Mail..." />
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-zinc-500 w-8">Betr:</span>
                                    <input v-model="wizardEditSubject" class="form-input flex-1" style="height:30px;font-size:12px" />
                                </div>
                                <textarea v-model="wizardEditBody" class="form-textarea w-full" rows="6" style="font-size:13px;line-height:1.6" placeholder="E-Mail Text..."></textarea>
                            </div>
                        </template>
                    </div>
                    <div class="px-6 py-3 border-t border-zinc-200/80 flex items-center justify-between bg-zinc-50">
                        <button @click="wizardBack()" :disabled="wizardIndex === 0" class="px-3 py-1.5 text-xs font-medium bg-white text-zinc-600 rounded-xl hover:bg-zinc-50 transition-all duration-200 active:scale-[0.97] btn-sm flex items-center gap-1.5">
                            <ChevronLeft class="w-3.5 h-3.5" /> Zurück
                        </button>
                        <div class="flex gap-2">
                            <button @click="wizardSkip()" class="px-3 py-1.5 text-xs font-medium text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50 rounded-xl transition-all duration-200 btn-sm">Überspringen</button>
                            <button v-if="wizardPhone" @click="wizardMarkCalled()" class="px-3 py-1.5 text-xs font-medium rounded-xl transition-all duration-200 active:scale-[0.97] flex items-center gap-1.5" :disabled="wizardSending"
                                style="background:#10b981;color:white;border:none">
                                <Phone class="w-3.5 h-3.5" /> Angerufen
                            </button>
                            <button @click="wizardDirectSend()" class="btn btn-brand btn-sm flex items-center gap-1.5" :disabled="wizardSending || !wizardEmail || !wizardEditBody">
                                <Send class="w-3.5 h-3.5" /> {{ wizardSending ? 'Sende...' : 'Senden' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Reassign / Change Category Modal -->
    <Teleport to="body">
        <div v-if="editingAssignment" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="editingAssignment = null">
            <div class="fixed inset-0 bg-black/40" @click="editingAssignment = null"></div>
            <div class="relative bg-white w-full sm:w-80 sm:rounded-2xl rounded-t-2xl shadow-2xl border border-zinc-200/80 overflow-hidden max-h-[70vh]">
                <div class="px-4 py-3 border-b border-zinc-200/80 flex items-center justify-between" style="background:rgba(244,244,245,0.8)">
                    <div class="text-sm font-semibold">{{ editingAssignment.type === 'prop' ? 'Objekt zuweisen' : 'Kategorie ändern' }}</div>
                    <button @click="editingAssignment = null" class="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-[var(--accent)]"><X class="w-4 h-4" /></button>
                </div>
                <div class="text-xs px-4 py-2 border-b border-zinc-200/80" style="color:#71717a">
                    {{ editingAssignment.item?.from_name || editingAssignment.item?.stakeholder }}
                </div>
                <div class="overflow-y-auto" style="max-height:calc(70vh - 90px)">
                    <template v-if="editingAssignment.type === 'prop'">
                        <div v-for="p in properties" :key="p.id"
                            @click="reassignItem(editingAssignment.item, p.id)"
                            class="px-4 py-3 text-sm hover:bg-[var(--accent)] cursor-pointer border-b border-zinc-200/80 last:border-0 flex items-center gap-2"
                            :style="editingAssignment.item?.property_id === p.id ? 'background:rgba(238,118,6,0.08)' : ''">
                            <Home class="w-3.5 h-3.5 flex-shrink-0" style="color:#71717a" />
                            <div class="min-w-0">
                                <div class="font-medium truncate">{{ p.ref_id }}</div>
                                <div class="text-[11px] text-zinc-500 truncate">{{ p.address }}, {{ p.city }}</div>
                            </div>
                            <Check v-if="editingAssignment.item?.property_id === p.id" class="w-4 h-4 ml-auto flex-shrink-0" style="color:#D4622B" />
                        </div>
                    </template>
                    <template v-else>
                        <div v-for="cat in ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','nachfassen','expose','sonstiges']" :key="cat"
                            @click="changeCategoryItem(editingAssignment.item, cat)"
                            class="px-4 py-3 text-sm hover:bg-[var(--accent)] cursor-pointer border-b border-zinc-200/80 last:border-0 flex items-center gap-3"
                            :style="editingAssignment.item?.category === cat ? 'background:rgba(238,118,6,0.08)' : ''">
                            <span class="badge text-[10px]" :style="catBadgeStyle(cat)">{{ catLabel(cat) }}</span>
                            <Check v-if="editingAssignment.item?.category === cat" class="w-4 h-4 ml-auto flex-shrink-0" style="color:#D4622B" />
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Alle Senden Confirm Popup -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="sendAllConfirm" class="fixed inset-0 z-[200] flex items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(2px)">
                <div class="bg-white rounded-2xl shadow-2xl w-[90vw] max-w-sm overflow-hidden" style="border:1px solid rgba(228,228,231,0.8)">
                    <div class="px-5 py-4 text-center">
                        <div class="w-12 h-12 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:rgba(238,118,6,0.1)">
                            <Send class="w-5 h-5" style="color:#D4622B" />
                        </div>
                        <h3 class="text-base font-semibold mb-1">Alle senden?</h3>
                        <p class="text-sm text-zinc-500">
                            {{ sendAllConfirm.count }} Nachfass-Mails werden nacheinander generiert und versendet.
                        </p>
                    </div>
                    <div class="px-5 pb-4 flex gap-2">
                        <button @click="sendAllConfirm = null" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-medium border border-zinc-200/80 hover:bg-zinc-50 transition-colors">
                            Abbrechen
                        </button>
                        <button @click="sendAllInGroup(sendAllConfirm.key, sendAllConfirm.items)" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-colors" style="background:#D4622B">
                            {{ sendAllConfirm.count }} Mails senden
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Alle Senden Progress Overlay -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0 translate-y-2" enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="sendAllRunning" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[200] bg-white rounded-2xl shadow-2xl px-5 py-3.5 flex items-center gap-3 min-w-[320px]" style="border:2px solid #D4622B">
                <span v-if="!sendAllAborted" class="spinner flex-shrink-0" style="width:18px;height:18px;border-color:rgba(238,118,6,0.2);border-top-color:#D4622B"></span>
                <span v-else class="flex-shrink-0 text-base">⏹</span>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold">{{ sendAllProgress.sent }}/{{ sendAllProgress.total }} gesendet</div>
                    <div v-if="sendAllAborted" class="text-xs text-amber-600">Wird nach aktuellem Versand gestoppt...</div>
                    <div v-else-if="sendAllProgress.current" class="text-xs text-zinc-500 truncate">{{ sendAllProgress.current }}</div>
                </div>
                <button v-if="!sendAllAborted" @click="sendAllAborted = true"
                    class="flex-shrink-0 px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors" style="background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.2)">
                    Stopp
                </button>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* ── Taste-Skill Design System ── */
/* Desktop: two-column layout for detail sections */
@media (min-width: 768px) {
  .two-col-grid { grid-template-columns: 1fr 360px; }
  .followup-expanded { grid-template-columns: 1fr 340px; }
}

/* Expanded detail panel — zinc surface */
.exp-detail {
  background: rgba(244,244,245,0.6);
  border-radius: 16px;
  transition: all 0.4s cubic-bezier(0.22,1,0.36,1);
}

/* Mobile optimizations */
@media (max-width: 767px) {
  .two-col-grid,
  .followup-expanded {
    grid-template-columns: 1fr !important;
    padding: 0.75rem !important;
    gap: 0.75rem !important;
  }

  .divide-y > div .text-sm.font-medium {
    font-size: 15px;
    line-height: 1.4;
  }

  .exp-detail {
    margin-left: -0.25rem;
    margin-right: -0.25rem;
    border-radius: 12px;
  }
}

/* Smooth scrollbar hiding */
::-webkit-scrollbar { width: 0; height: 0; }
</style>
