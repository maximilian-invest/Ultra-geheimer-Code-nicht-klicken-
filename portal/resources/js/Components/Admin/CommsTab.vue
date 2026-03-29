<script setup>
import { catBadgeStyle, catLabel, catIsInbound } from '@/utils/categoryBadge.js';
import { ref, inject, onMounted, computed, watch } from "vue";
import { Send, Sparkles, PenSquare, History, FileEdit, Trash2, Search, ChevronLeft, ChevronRight, Reply, Paperclip, X, Save, Inbox, ChevronDown, ChevronUp, LayoutTemplate, Plus, Pencil, MailQuestion } from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");
const properties = inject("properties");
const unmatchedCount = inject("unmatchedCount");
const refreshCounts = inject("refreshCounts");

// Signature from settings
const sigData = ref(null);
async function searchContacts(query) {
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

const commsView = ref(localStorage.getItem("sr-admin-commsview") || "posteingang");
watch(commsView, (v) => localStorage.setItem("sr-admin-commsview", v));

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
const selectedAccountId = ref(null);
const emailAccountsSelect = ref([]);
const aiLoading = ref(false);
const emailSending = ref(false);

// Contact search autocomplete
const contactSearchResults = ref([]);
const contactSearchLoading = ref(false);
const showContactSearch = ref(false);
let contactSearchTimeout = null;
const replyContext = ref(null);
const replyContextLoading = ref(false);
const currentDraftId = ref(null);
const aiOriginalBody = ref(""); // Track AI-generated body for learning

// History state
const ehData = ref([]);
const ehLoading = ref(false);
const ehTotal = ref(0);
const ehPage = ref(1);
const ehPerPage = ref(30);
const ehTotalPages = ref(0);
const ehSearch = ref("");
const ehPropertyId = ref("0");
const ehCategory = ref("");
const ehDirection = ref("");
const ehShowUnmatched = ref(false);
const ehExpanded = ref(null);
const ehSelected = ref([]);
const ehSelectAll = ref(false);
const ehThreadLoading = ref(null);
const ehThreadMessages = ref({})
const ehThreadExpanded = ref(null) // expanded message index within thread;

// Attachment handling
const attachAssignOpen = ref(null); // "emailId-fileIndex" key of open assign dropdown
const attachAssigning = ref(null);  // currently saving attachment key

function parseAttachmentNames(names) {
    if (!names) return [];
    // Could be comma-separated or JSON
    try {
        const parsed = JSON.parse(names);
        if (Array.isArray(parsed)) return parsed;
    } catch {}
    return names.split(',').map(n => n.trim()).filter(Boolean);
}

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

// Email Templates state
const templates = ref([]);
const templatesLoading = ref(false);
const templateEdit = ref(null); // null = list view, {} = edit/new
const templateSaving = ref(false);

const subTabs = [
    { key: "compose", label: "Verfassen", icon: PenSquare },
    { key: "posteingang", label: "Posteingang", icon: Inbox },
    { key: "gesendet", label: "Gesendet", icon: Send },
    { key: "drafts", label: "Entwürfe", icon: FileEdit },
    { key: "trash", label: "Papierkorb", icon: Trash2 },
    { key: "templates", label: "Templates", icon: LayoutTemplate },
];

onMounted(() => {
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
            commsView.value = 'compose';
            // Load original email context
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

                    // Set prospect email if composeTo is empty or a noreply address
                    if (d.prospect_email && (!composeTo.value || /noreply|no-reply|notification|typeform|followups/i.test(composeTo.value))) {
                        composeTo.value = d.prospect_email;
                    }

                    // Load available property files for checkbox selection
                    if (data.propertyId) {
                        loadPropertyFiles(data.propertyId);
                    }
                }).catch(() => { replyContextLoading.value = false; });
            }
            // Load property files for checkbox selection
            if (data.propertyId) {
                loadPropertyFiles(data.propertyId).then(() => {
                    // Auto-select files that were pre-selected in KI-Entwurf view
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
    loadTemplates();
    if (commsView.value === "posteingang" || commsView.value === "gesendet" || commsView.value === "history") { ehDirection.value = commsView.value === "gesendet" ? "outbound" : (commsView.value === "posteingang" ? "inbound" : ""); loadEmailHistory(); }
    if (commsView.value === "inbox") loadInbox();
    if (commsView.value === "drafts") loadDrafts();
    if (commsView.value === "trash") loadTrash();
});

function switchView(v) {
    commsView.value = v;
    if (v === "posteingang") { ehDirection.value = "inbound"; ehShowUnmatched.value = false; ehPage.value = 1; loadEmailHistory(); }
    if (v === "gesendet") { ehDirection.value = "outbound"; ehPage.value = 1; loadEmailHistory(); }
    if (v === "history") { ehDirection.value = ""; ehPage.value = 1; loadEmailHistory(); }
    if (v === "inbox") loadInbox();
    if (v === "drafts") loadDrafts();
    if (v === "trash") loadTrash();
    if (v === "templates") loadTemplates();
}

async function loadEmailAccountsSelect() {
    try {
        const r = await fetch(API.value + "&action=get_email_accounts_select");
        const d = await r.json();
        emailAccountsSelect.value = d.accounts || [];
        if (!selectedAccountId.value && emailAccountsSelect.value.length) selectedAccountId.value = emailAccountsSelect.value[0].id;
    } catch (e) { /* silent */ }
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
        if (f.size > 50 * 1024 * 1024) { toast("Datei zu groß (max 50 MB): " + f.name); continue; }
        composeAttachments.value.push(f);
    }
    event.target.value = "";
}

async function sendEmail() {
    if (!composeTo.value || !composeSubject.value || !composeBody.value) { toast("Bitte alle Felder ausfüllen."); return; }
    if (!selectedAccountId.value) { toast("Bitte Absender-Konto wählen."); return; }
    emailSending.value = true;
    try {
        const sigHtml = buildSignatureHtml();
        const sig = buildSignature();
        let htmlBody = composeBody.value.replace(/\n/g, "<br>") + sigHtml;

        // Add quoted original message for replies (like a real email client)
        if (emailSourceId.value && replyContext.value && replyContext.value.originalBody) {
            const origDate = replyContext.value.originalDate || "";
            const origFrom = replyContext.value.originalFrom || replyContext.value.stakeholder || "";
            const origSubject = replyContext.value.originalSubject || "";
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
            // Save AI style feedback if user modified an AI draft
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
            toast("Email erfolgreich gesendet!" + (composeAttachments.value.length ? " (" + composeAttachments.value.length + " Anhänge)" : ""));
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
    if (!selectedAccountId.value) { toast("Bitte Absender-Konto wählen."); return; }
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

// === HISTORY with expandable threads ===
async function loadEmailHistory() {
    ehLoading.value = true;
    try {
        let url = API.value + "&action=email_history&per_page=" + ehPerPage.value + "&page=" + ehPage.value;
        if (ehPropertyId.value !== "0") url += "&property_id=" + ehPropertyId.value;
        if (ehSearch.value.trim()) url += "&search=" + encodeURIComponent(ehSearch.value.trim());
        if (ehCategory.value) url += "&category=" + ehCategory.value;
        if (ehDirection.value) url += "&direction=" + ehDirection.value;
        if (ehShowUnmatched.value) url += "&unmatched=1";
        if (ehShowUnmatched.value && !inboxProps.value.length) loadInbox();
        const r = await fetch(url);
        const d = await r.json();
        ehData.value = (d.emails || []).map(e => ({ ...e, _assignTo: e._assignTo || "" }));
        ehTotal.value = d.total || 0;
        ehTotalPages.value = d.total_pages || 0;
    } catch (e) { toast("Fehler: " + e.message); }
    ehLoading.value = false;
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

// Property files available for attachment (loaded when property is selected)
const propertyFiles = ref([]); // [{id, label, filename, url, checked}]
const propertyFilesLoading = ref(false);

async function loadPropertyFiles(propertyId) {
    if (!propertyId) {
        // Still load global files even without property
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
        // Load from property_files table
        const r1 = await fetch(API.value + "&action=get_property_files&property_id=" + propertyId);
        const d1 = await r1.json();
        for (const f of (d1.files || [])) {
            items.push({ id: "pf-" + f.id, label: f.label, filename: f.filename, url: f.url, checked: false });
        }
        // Load from portal_documents table
        const r2 = await fetch(API.value + "&action=list_portal_documents&property_id=" + propertyId);
        const d2 = await r2.json();
        for (const doc of (d2.documents || [])) {
            items.push({ id: "doc-" + doc.id, label: doc.description || doc.original_name, filename: doc.original_name, url: doc.file_url, checked: false });
        }
        // Also load global files (always available)
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

// Toggle a single property file attachment on/off
async function onPropertyFileToggle(pf) {
    pf.checked = !pf.checked;
    if (pf.checked) {
        // Add this file if not already attached
        if (!composeAttachments.value.some(a => a.name === pf.filename)) {
            try {
                const resp = await fetch(pf.url);
                const blob = await resp.blob();
                const file = new File([blob], pf.filename, { type: blob.type });
                composeAttachments.value.push(file);
            } catch (e) { toast("Fehler beim Laden: " + pf.filename); pf.checked = false; }
        }
    } else {
        // Remove this file from attachments
        composeAttachments.value = composeAttachments.value.filter(a => a.name !== pf.filename);
    }
}

// Reload property files when property dropdown changes
watch(composePropertyId, (newId) => loadPropertyFiles(newId), { immediate: true });

function extractRealEmail(fromEmail, bodyText) {
    // If from_email is a real person's email, use it
    if (fromEmail && !/noreply|no-reply|mailer|notification|typeform|followups|info@willhaben|info@immowelt/i.test(fromEmail)) {
        return fromEmail;
    }
    // Extract from body with known TLD whitelist (prevents matching Typeform hash codes)
    if (bodyText) {
        const tlds = "at|de|com|net|org|info|io|eu|ch|uk|biz|me|cc|tv|top|to|li|hr|si|ro|bg|rs|cz|hu|sk|pl|it|fr|es|nl|be|se|no|fi|dk|pt|ru|us|ca|au|nz|jp|cn|in|br|mx|za|online|app|dev|gmbh|wien|mobi|xyz|live|email";
        const emailRe = new RegExp("[\\w.+\\-]+@[\\w.\\-]+\\.(?:" + tlds + ")(?=[^a-z]|$)", "i");
        const flat = bodyText.replace(/\r?\n/g, ' ');
        let m = flat.match(new RegExp("E-?Mail[=:\\s]+(" + emailRe.source + ")", "i"));
        if (m) return m[1].toLowerCase();
        m = flat.match(new RegExp("(?:email|e-mail)(" + emailRe.source + ")", "i"));
        if (m) return m[1].toLowerCase();
        // Fallback: any email not from a platform
        const allEmails = flat.match(new RegExp(emailRe.source, "gi")) || [];
        for (const e of allEmails) {
            if (!/willhaben|immowelt|noreply|typeform|followups|scout24|sr-homes/i.test(e) && e.toLowerCase() !== (fromEmail||'').toLowerCase()) {
                return e.toLowerCase();
            }
        }
    }
    return fromEmail || '';
}

async function replyToEmail(em) {
    commsView.value = "compose";
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

    // Show original email as context
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

    // Load thread context
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
            // Update composeTo with prospect_email from server if current is empty or noreply
            if (d.prospect_email && (!composeTo.value || /noreply|no-reply|notification|typeform|followups/i.test(composeTo.value))) {
                composeTo.value = d.prospect_email;
            }
        } catch (e) { /* silent */ }
        replyContextLoading.value = false;
    }

    // Load available property files for checkbox selection
    if (em.property_id) {
        loadPropertyFiles(em.property_id);
    }
}

const trashConfirmId = ref(null);

async function trashEmail(id) {
    if (trashConfirmId.value !== id) {
        trashConfirmId.value = id;
        setTimeout(() => { if (trashConfirmId.value === id) trashConfirmId.value = null; }, 3000);
        return;
    }
    trashConfirmId.value = null;
    try {
        const r = await fetch(API.value + "&action=trash_emails", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ids: [id] }) });
        const d = await r.json();
        if (d.ok) { ehData.value = ehData.value.filter((e) => e.id !== id); ehTotal.value--; trashCount.value++; toast("In Papierkorb verschoben"); }
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
    if (!em._assignTo) { toast("Bitte ein Objekt auswählen."); return; }
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
    if (!email._assignTo) { toast("Bitte ein Objekt auswählen."); return; }
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

function loadDraftIntoComposer(dr) {
    commsView.value = "compose";
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

    // Reload reply context if draft has a source email
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

    // Load property files
    if (dr.property_id) loadPropertyFiles(dr.property_id);
}

async function deleteDraft(id) {
    if (!confirm("Entwurf löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_draft", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id }) });
        const d = await r.json();
        if (d.ok) { draftsData.value = draftsData.value.filter((dr) => dr.id !== id); draftsCount.value--; toast("Entwurf gelöscht"); }
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
    if (!confirm("Template wirklich löschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_template", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id }),
        });
        const d = await r.json();
        if (d.ok) { templates.value = templates.value.filter(t => t.id !== id); toast("Template gelöscht."); }
        else toast("Fehler: " + (d.error || "Unbekannt"));
    } catch (e) { toast("Fehler: " + e.message); }
}

const formatDate = (s) => {
    if (!s) return "";
    // If it's just a date (YYYY-MM-DD) without time, show only date
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
        const [y, m, d] = s.split("-");
        return d + "." + m + "." + y;
    }
    // If it has time info, show date + time
    const dt = new Date(s);
    const h = dt.getHours(), mi = dt.getMinutes();
    const dateStr = dt.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric" });
    // Only show time if it's not midnight (which means no real time data)
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

// catLabel + catBadgeClass → importiert aus @/utils/categoryBadge.js
</script>

<template>
    <div class="px-4 py-6 space-y-4">
        <!-- Sub-tabs -->
        <div class="flex gap-1 border-b border-[var(--border)] pb-2 overflow-x-auto">
            <button v-for="st in subTabs" :key="st.key" @click="switchView(st.key)"
                class="btn btn-sm flex-shrink-0 whitespace-nowrap" :class="commsView === st.key ? 'btn-primary' : 'btn-ghost'">
                <component :is="st.icon" class="w-3.5 h-3.5" />
                <span>{{ st.label }}</span>
                <span v-if="st.key === 'inbox' && unmatchedCount" class="text-[10px] px-1 rounded bg-[var(--muted)] text-[var(--muted-foreground)]">{{ unmatchedCount }}</span>
                <span v-if="st.key === 'drafts' && draftsCount" class="text-[10px] px-1 rounded bg-[var(--muted)] text-[var(--muted-foreground)]">{{ draftsCount }}</span>
                <span v-if="st.key === 'trash' && trashCount" class="text-[10px] px-1 rounded bg-[var(--muted)] text-[var(--muted-foreground)]">{{ trashCount }}</span>
            </button>
        </div>

        <!-- COMPOSE -->
        <div v-if="commsView === 'compose'" class="card">
            <div class="px-4 py-2 border-b border-[var(--border)]">
                <h3 class="text-sm font-semibold">E-Mail verfassen</h3>
            </div>
            <div class="px-4 py-2 space-y-2">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div>
                        <label class="form-label text-xs"  style="margin-bottom:2px">Absender-Konto</label>
                        <select v-model="selectedAccountId" class="form-select">
                            <option v-for="a in emailAccountsSelect" :key="a.id" :value="a.id">{{ a.label }} ({{ a.email_address }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label text-xs" style="margin-bottom:2px">Objekt</label>
                        <select v-model="composePropertyId" class="form-select">
                            <option :value="null">Kein Objekt</option>
                            <option v-for="p in properties" :key="p.id" :value="p.id">{{ p.ref_id }} - {{ p.address }}</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <div class="flex items-center justify-between" style="margin-bottom:2px">
                            <label class="form-label text-xs mb-0">An</label>
                            <button v-if="!showCcBcc" @click="showCcBcc = true" class="text-[11px] px-2 py-0.5 rounded-md font-medium transition-colors" style="background:var(--muted);color:var(--muted-foreground);border:1px solid var(--border)">+ CC / BCC</button>
                        </div>
                        <div class="relative">
                            <input v-model="composeTo" @input="onComposeToInput" @focus="searchContacts(composeTo)" @blur="onComposeToBlur" class="form-input" placeholder="empfaenger@email.com" autocomplete="off" />
                            <div v-if="showContactSearch && (contactSearchResults.length || contactSearchLoading)" class="absolute left-0 right-0 top-full mt-1 z-50 bg-[var(--card)] border border-[var(--border)] rounded-lg shadow-lg overflow-hidden max-h-48 overflow-y-auto">
                                <div v-if="contactSearchLoading" class="px-3 py-2 text-xs text-center"><span class="spinner" style="width:12px;height:12px"></span></div>
                                <div v-for="ct in contactSearchResults" :key="ct.id" @mousedown.prevent="selectContact(ct)" class="px-3 py-2 hover:bg-[var(--accent)] cursor-pointer flex items-center gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-medium truncate">{{ ct.full_name }}</div>
                                        <div class="text-[10px] text-[var(--muted-foreground)] truncate">{{ ct.email || "Keine E-Mail" }}</div>
                                    </div>
                                    <div v-if="ct.phone" class="text-[10px] text-[var(--muted-foreground)] flex-shrink-0">{{ ct.phone }}</div>
                                </div>
                                <div v-if="!contactSearchLoading && !contactSearchResults.length" class="px-3 py-2 text-xs text-[var(--muted-foreground)]">Kein Kontakt gefunden</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="form-label text-xs" style="margin-bottom:2px">Betreff</label>
                        <input v-model="composeSubject" class="form-input" placeholder="Betreff..." />
                    </div>
                </div>
                <div v-if="showCcBcc" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label text-xs" style="margin-bottom:2px">CC</label>
                        <input v-model="composeCc" class="form-input" placeholder="cc@email.com (mehrere mit Komma trennen)" />
                    </div>
                    <div>
                        <label class="form-label text-xs" style="margin-bottom:2px">BCC</label>
                        <input v-model="composeBcc" class="form-input" placeholder="bcc@email.com (mehrere mit Komma trennen)" />
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="form-label mb-0 text-xs">Nachricht</label>
                        <div class="flex gap-2 items-center">
                            <select @change="e => { const t = templates.find(x => x.id == e.target.value); if(t) applyTemplate(t); e.target.value = ''; }" class="form-select" style="max-width:140px;height:28px;font-size:11px;padding:2px 8px" :disabled="!templates.length">
                                <option value="">Vorlage...</option>
                                <optgroup v-for="cat in [...new Set(templates.map(t => t.category))]" :key="cat" :label="cat">
                                    <option v-for="t in templates.filter(x => x.category === cat)" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </optgroup>
                            </select>
                            <select v-model="composeTone" class="form-select" style="max-width:120px;height:28px;font-size:11px;padding:2px 8px">
                                <option value="professional">Professionell</option>
                                <option value="friendly">Freundlich</option>
                                <option value="formal">Formell</option>
                            </select>
                            <button v-if="emailSourceId" @click="generateAiReply()" :disabled="aiLoading" class="btn btn-outline btn-sm" style="height:28px">
                                <span v-if="aiLoading" class="spinner" style="width:12px;height:12px"></span>
                                <Sparkles v-else class="w-3 h-3" />
                                <span>KI</span>
                            </button>
                        </div>
                    </div>
                    <textarea v-model="composeBody" class="form-textarea" rows="8" placeholder="Ihre Nachricht..." style="min-height:200px;font-size:16px"></textarea>
                </div>

                <div v-if="composeAttachments.length" class="flex flex-wrap gap-2">
                    <div v-for="(file, idx) in composeAttachments" :key="idx" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-xs font-medium border border-[var(--border)] bg-[var(--muted)]">
                        <span>{{ file.name }}</span>
                        <span class="text-[10px] opacity-60">{{ (file.size / 1024 / 1024).toFixed(1) }} MB</span>
                        <button @click="composeAttachments.splice(idx, 1)"><X class="w-3 h-3" /></button>
                    </div>
                </div>

                <div class="text-[10px] text-[var(--muted-foreground)] border-t border-dashed border-[var(--border)] pt-1">
                    <span class="font-medium text-[var(--foreground)]">{{ sigData ? sigData.signature_name : 'Signatur' }}</span>
                    <template v-if="sigData"> &middot; {{ sigData.signature_company }} &middot; {{ sigData.signature_phone }}</template>
                </div>

                <div class="flex items-center gap-3 flex-wrap">
                    <button @click="sendEmail()" :disabled="emailSending" class="btn btn-brand">
                        <span v-if="emailSending" class="spinner" style="width:14px;height:14px"></span>
                        <Send v-else class="w-4 h-4" />
                        <span>Senden</span>
                    </button>
                    <button @click="saveDraft()" class="btn btn-outline"><Save class="w-4 h-4" /> Entwurf</button>
                    <label class="btn btn-ghost cursor-pointer">
                        <Paperclip class="w-4 h-4" /> Anhang
                        <input type="file" multiple @change="addAttachments" class="hidden" />
                    </label>
                    <!-- Property file checkboxes -->
                    <template v-if="propertyFiles.length">
                        <span class="text-[10px] text-[var(--muted-foreground)] ml-1">|</span>
                        <div v-for="pf in propertyFiles" :key="pf.id" class="flex items-center gap-1.5">
                            <input type="checkbox" :checked="pf.checked" @change="onPropertyFileToggle(pf)" class="form-checkbox" :id="'pf-' + pf.id" />
                            <label :for="'pf-' + pf.id" class="text-xs cursor-pointer select-none" :class="pf.checked ? 'text-[var(--foreground)] font-medium' : 'text-[var(--muted-foreground)]'">
                                {{ pf.label }}
                            </label>
                        </div>
                    </template>
                    <span v-if="propertyFilesLoading" class="spinner" style="width:12px;height:12px"></span>
                </div>

                <div v-if="replyContext" class="rounded-lg border border-[var(--border)] text-xs overflow-hidden" style="border-left: 3px solid #ee7606">
                    <div class="px-4 py-2.5 bg-[var(--muted)] flex items-center justify-between">
                        <div>
                            <span class="font-semibold">Antwort an {{ replyContext.stakeholder }}</span>
                            <span v-if="replyContext.ref_id" class="text-[var(--muted-foreground)] ml-2">{{ replyContext.ref_id }} - {{ replyContext.address }}</span>
                        </div>
                        <button @click="replyContext = null" class="btn btn-ghost btn-icon btn-sm" style="height:20px;width:20px"><X class="w-3 h-3" /></button>
                    </div>
                    <!-- Original email -->
                    <div v-if="replyContext.originalBody" class="px-4 py-3 border-t border-[var(--border)]">
                        <div class="flex items-center gap-2 mb-2 text-[var(--muted-foreground)]">
                            <span class="font-medium text-[var(--foreground)]">{{ replyContext.originalFrom }}</span>
                            <span>&middot;</span>
                            <span>{{ formatDate(replyContext.originalDate) }}</span>
                        </div>
                        <div class="font-medium mb-1.5 text-[var(--foreground)]">{{ replyContext.originalSubject }}</div>
                        <div class="whitespace-pre-wrap text-[var(--muted-foreground)] max-h-60 overflow-y-auto p-2 rounded bg-[var(--card)] border border-[var(--border)] leading-relaxed text-[11px]">{{ replyContext.originalBody }}</div>
                    </div>
                    <!-- Thread -->
                    <div v-if="replyContextLoading" class="px-4 py-3 text-center border-t border-[var(--border)]"><span class="spinner" style="width:12px;height:12px"></span></div>
                    <div v-else-if="replyContext.messages && replyContext.messages.length" class="px-4 py-3 border-t border-[var(--border)]">
                        <div class="text-[10px] font-semibold text-[var(--muted-foreground)] uppercase tracking-wider mb-2">Bisheriger Verlauf ({{ replyContext.messages.length }})</div>
                        <div class="space-y-1.5 max-h-40 overflow-y-auto">
                            <div v-for="(m, i) in replyContext.messages.slice(-8)" :key="i" class="text-[11px] flex gap-2">
                                <span class="text-[var(--muted-foreground)] flex-shrink-0 w-16">{{ m.activity_date ? m.activity_date.substring(5, 10) : '' }}</span>
                                <span class="font-medium flex-shrink-0" :style="{ color: catIsInbound(m.category) ? '#3b82f6' : '#10b981' }">{{ catIsInbound(m.category) ? '← ' : '→ ' }}</span>
                                <span class="truncate">{{ m.activity || m.subject || '' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- POSTEINGANG -->
        <div v-if="commsView === 'posteingang' || commsView === 'gesendet'" class="card">
            <div class="px-6 py-4 border-b border-[var(--border)] flex items-center justify-between">
                <h3 class="text-sm font-semibold">{{ commsView === "posteingang" ? "Posteingang" : "Gesendet" }} <span class="text-[var(--muted-foreground)] font-normal">({{ ehTotal }})</span></h3>
                <div v-if="ehSelected.length" class="flex items-center gap-2">
                    <span class="text-xs text-[var(--muted-foreground)]">{{ ehSelected.length }} ausgewählt</span>
                    <button @click="bulkTrash()" class="btn btn-sm btn-danger"><Trash2 class="w-3 h-3" /> Löschen</button>
                </div>
            </div>
            <div class="px-5 py-3 flex flex-wrap gap-2 items-center border-b border-[var(--border)]">
                <div class="relative flex-1" style="min-width:120px;max-width:100%">
                    <Search class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted-foreground)]" />
                    <input v-model="ehSearch" @keyup.enter="ehPage = 1; loadEmailHistory()" class="form-input" style="padding-left:36px" placeholder="Suche..." />
                </div>
                <button v-if="commsView === 'posteingang'" @click="ehShowUnmatched = !ehShowUnmatched; ehPage = 1; loadEmailHistory()"
                    class="btn btn-sm flex-shrink-0" :class="ehShowUnmatched ? 'btn-primary' : 'btn-outline'"
                    style="white-space:nowrap">
                    <MailQuestion class="w-3 h-3" /> Keinem Objekt zugeordnet
                    <span v-if="unmatchedCount" class="text-[10px] ml-1 px-1 rounded" :class="ehShowUnmatched ? 'bg-white/20' : 'bg-[var(--muted)]'">{{ unmatchedCount }}</span>
                </button>
                <select v-model="ehPropertyId" @change="ehPage = 1; loadEmailHistory()" class="form-select" style="width:auto">
                    <option value="0">Alle Objekte</option>
                    <option v-for="p in properties" :key="p.id" :value="String(p.id)">{{ p.ref_id }} - {{ p.address }}</option>
                </select>
                <select v-model="ehCategory" @change="ehPage = 1; loadEmailHistory()" class="form-select" style="width:auto">
                    <option value="">Alle Kategorien</option>
                    <option value="anfrage">Erstanfrage</option>
                    <option value="email-in">Eingehend</option>
                    <option value="email-out">Ausgehend</option>
                    <option value="expose">Exposé</option>
                    <option value="besichtigung">Besichtigung</option>
                    <option value="kaufanbot">Kaufanbot</option>
                    <option value="absage">Absage</option>
                    <option value="sonstiges">Sonstiges</option>
                </select>

            </div>
            <div v-if="ehLoading" class="px-5 py-8 text-center"><span class="spinner"></span></div>
            <div v-else-if="!ehData.length" class="px-5 py-8 text-center text-sm text-[var(--muted-foreground)]">Keine E-Mails gefunden.</div>
            <div v-else class="divide-y divide-[var(--border)]">
                <div v-for="em in ehData" :key="em.id">
                    <div class="px-4 sm:px-5 py-2.5 hover:bg-[var(--muted)] transition cursor-pointer" @click="toggleEmailThread(em)"
                        :style="ehExpanded === em.id ? 'background:var(--muted)' : ''">
                        <!-- Outlook-style: clean single row -->
                        <div class="flex items-center gap-3">
                            <input type="checkbox" :checked="ehSelected.includes(em.id)" @click.stop @change="toggleEmailSelect(em.id)" class="form-checkbox flex-shrink-0 hidden sm:block" />
                            <span class="w-4 flex-shrink-0 text-center" :style="{ color: em.direction === 'inbound' ? '#3b82f6' : '#10b981' }">{{ em.direction === 'inbound' ? '←' : '→' }}</span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold truncate" :style="em.direction === 'inbound' && !em.is_read ? 'color:var(--foreground)' : 'color:var(--muted-foreground)'">
                                        {{ em.direction === 'inbound' ? (em.from_name || em.from_email) : (em.to_email || em.stakeholder) }}
                                    </span>
                                    <span v-if="em.has_attachment" class="flex-shrink-0" style="color:var(--muted-foreground)">
                                        <Paperclip class="w-3 h-3" />
                                    </span>
                                    <span v-if="em.property_ref_id" class="text-[10px] px-1.5 py-0.5 rounded flex-shrink-0" style="background:var(--muted);color:var(--muted-foreground)">{{ em.property_ref_id }}</span>
                                </div>
                                <div class="text-xs text-[var(--muted-foreground)] truncate mt-0.5">{{ em.subject || '(kein Betreff)' }}</div>
                            </div>
                            <span class="text-[11px] text-[var(--muted-foreground)] tabular-nums flex-shrink-0 w-24 text-right hidden sm:block">{{ formatDateShort(em.email_date) }}</span>
                            <span class="text-[11px] text-[var(--muted-foreground)] tabular-nums flex-shrink-0 sm:hidden">{{ formatDateShort(em.email_date) }}</span>
                            <button @click.stop="trashEmail(em.id)" class="flex-shrink-0 p-1.5 rounded-md hover:bg-red-100 dark:hover:bg-red-900/30 text-[var(--muted-foreground)] hover:text-red-600 transition-colors opacity-0 group-hover:opacity-100 sm:opacity-0 sm:hover:opacity-100" :class="trashConfirmId === em.id ? '!opacity-100 !text-red-600' : ''" :title="trashConfirmId === em.id ? 'Nochmal klicken zum Löschen' : 'Löschen'">
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                    <!-- Assignment bar for unmatched emails -->
                    <div v-if="ehShowUnmatched && !em.property_id" class="px-5 py-2 flex items-center gap-2 bg-amber-50 dark:bg-amber-950/20 border-t border-[var(--border)]" @click.stop>
                        <span class="text-[11px] text-amber-600 font-medium flex-shrink-0">Zuordnen:</span>
                        <select v-model="em._assignTo" class="form-select flex-1" style="height:34px;font-size:12px">
                            <option value="">Objekt wählen...</option>
                            <option v-for="p in inboxProps" :key="p.id" :value="String(p.id)">{{ p.ref_id }} - {{ p.address }}</option>
                        </select>
                        <button @click="assignEmailFromHistory(em)" :disabled="!em._assignTo" class="btn btn-brand btn-sm" style="height:34px;font-size:11px">Zuordnen</button>
                        <button @click="dismissEmailFromHistory(em)" class="btn btn-ghost btn-sm btn-icon" style="height:34px" title="Ausblenden"><X class="w-3 h-3" /></button>
                    </div>
                    <!-- Expanded detail -->
                    <div v-if="ehExpanded === em.id" class="border-t border-[var(--border)]">
                        <!-- Action bar -->
                        <div class="px-5 py-2.5 flex items-center gap-2 flex-wrap" style="background:rgba(99,102,241,0.04);border-bottom:1px solid var(--border)">
                            <button @click.stop="replyToEmail(em)" class="btn btn-sm flex items-center gap-1.5" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff;border:none;height:30px;padding:0 12px;border-radius:8px">
                                <Reply class="w-3.5 h-3.5" /> Antworten
                            </button>
                            <span class="badge text-[10px]" :style="catBadgeStyle(em.category)">{{ catLabel(em.category) }}</span>
                            <span v-if="em.stakeholder" class="text-[11px] text-[var(--muted-foreground)]">{{ em.stakeholder }}</span>
                            <span class="text-[11px] text-[var(--muted-foreground)]">{{ em.from_email }}</span>
                            <div class="flex-1"></div>
                            <span class="text-[11px] text-[var(--muted-foreground)] tabular-nums">{{ formatDate(em.email_date) }}</span>
                        </div>
                        <!-- Email body preview -->
                        <div v-if="em.ai_summary" class="px-5 py-3 text-xs text-[var(--muted-foreground)] leading-relaxed" style="background:var(--card);border-bottom:1px solid var(--border)">
                            {{ em.ai_summary }}
                        </div>
                        <!-- Attachments -->
                        <div v-if="em.has_attachment && em.attachment_names" class="px-5 py-2 flex items-center gap-2 flex-wrap" style="background:rgba(238,118,6,0.04);border-bottom:1px solid var(--border)">
                            <Paperclip class="w-3.5 h-3.5 text-[var(--muted-foreground)] flex-shrink-0" />
                            <template v-for="(att, ai) in parseAttachmentNames(em.attachment_names)" :key="ai">
                                <div class="flex items-center gap-1 text-[11px] px-2 py-1 rounded-md" style="background:var(--muted)">
                                    <span class="truncate max-w-[150px]">{{ att }}</span>
                                    <button @click.stop="downloadAttachment(em.id, ai, att)" class="text-blue-600 hover:underline font-medium" title="Herunterladen">↓</button>
                                    <span class="text-[var(--border)]">|</span>
                                    <div class="relative">
                                        <button @click.stop="attachAssignOpen = attachAssignOpen === em.id + '-' + ai ? null : em.id + '-' + ai" class="text-orange-600 hover:underline font-medium" title="Objekt zuweisen">Objekt</button>
                                        <div v-if="attachAssignOpen === em.id + '-' + ai" class="absolute left-0 top-full mt-1 z-50 bg-[var(--card)] border border-[var(--border)] rounded-lg shadow-lg p-2 min-w-[220px] max-h-[200px] overflow-y-auto">
                                            <div v-for="p in properties" :key="p.id"
                                                @click.stop="saveAttachmentToProperty(em.id, ai, p.id, att)"
                                                class="px-2 py-1.5 text-[11px] rounded hover:bg-[var(--accent)] cursor-pointer truncate"
                                                :class="{ 'opacity-50 pointer-events-none': attachAssigning === em.id + '-' + ai }">
                                                {{ p.ref_id }} – {{ p.address }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <!-- Thread -->
                        <div class="px-5 py-3" style="background:var(--muted)">
                        <div v-if="ehThreadLoading === em.id" class="text-center py-4"><span class="spinner" style="width:14px;height:14px"></span></div>
                        <div v-else-if="ehThreadMessages[em.id] && ehThreadMessages[em.id].length" class="space-y-2">
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--muted-foreground)] mb-2">Konversation ({{ ehThreadMessages[em.id].length }})</div>
                            <div v-for="(msg, mi) in ehThreadMessages[em.id]" :key="mi"
                                class="text-xs rounded-lg transition-colors cursor-pointer"
                                :class="ehThreadExpanded === em.id + '-' + mi ? 'bg-[var(--card)] border border-[var(--border)]' : 'hover:bg-[var(--card)]/50'"
                                @click="ehThreadExpanded = ehThreadExpanded === em.id + '-' + mi ? null : em.id + '-' + mi">
                                <div class="flex gap-3 py-2 px-2">
                                    <span class="text-[var(--muted-foreground)] flex-shrink-0 w-28 tabular-nums">{{ formatDate(msg.email_date || msg.created_at || msg.activity_date) }}</span>
                                    <span class="font-medium flex-shrink-0" :style="{ color: catIsInbound(msg.category) ? '#3b82f6' : '#10b981' }">{{ catIsInbound(msg.category) ? '←' : '→' }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate">{{ msg.subject || msg.activity || '' }}</div>
                                        <div v-if="msg.ai_summary && ehThreadExpanded !== em.id + '-' + mi" class="text-[var(--muted-foreground)] mt-0.5 truncate">{{ msg.ai_summary }}</div>
                                    </div>
                                    <svg :class="ehThreadExpanded === em.id + '-' + mi ? 'rotate-180' : ''" class="w-3.5 h-3.5 flex-shrink-0 text-[var(--muted-foreground)] transition-transform mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                                <!-- Expanded body -->
                                <div v-if="ehThreadExpanded === em.id + '-' + mi" class="px-3 pb-3 pt-1 border-t border-[var(--border)]">
                                    <div v-if="msg.body_text || msg.result" class="text-xs whitespace-pre-wrap leading-relaxed text-[var(--foreground)]" style="max-height:300px;overflow-y:auto">{{ msg.body_text || msg.result || '' }}</div>
                                    <div v-else-if="msg.ai_summary" class="text-xs text-[var(--muted-foreground)] italic">{{ msg.ai_summary }}</div>
                                    <div v-else class="text-xs text-[var(--muted-foreground)] italic">Kein Inhalt verfügbar</div>
                                    <!-- Attachments -->
                                    <div v-if="msg.has_attachment && msg.attachment_names" class="mt-3 pt-2 border-t border-[var(--border)]">
                                        <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--muted-foreground)] mb-1.5">Anhänge</div>
                                        <div v-for="(att, ai) in parseAttachmentNames(msg.attachment_names)" :key="ai" class="flex items-center gap-2 py-1.5 px-2 rounded hover:bg-[var(--muted)] group text-xs">
                                            <span class="flex-shrink-0">📎</span>
                                            <span class="flex-1 truncate font-medium">{{ att }}</span>
                                            <button @click.stop="downloadAttachment(msg.email_id || msg.id, ai, att)" class="btn btn-ghost btn-sm text-[10px] px-2 py-0.5 opacity-70 group-hover:opacity-100" title="Herunterladen">
                                                ↓ Download
                                            </button>
                                            <div class="relative" @click.stop>
                                                <button @click="attachAssignOpen = attachAssignOpen === (msg.email_id || msg.id) + '-' + ai ? null : (msg.email_id || msg.id) + '-' + ai" class="btn btn-ghost btn-sm text-[10px] px-2 py-0.5 opacity-70 group-hover:opacity-100" title="Objekt zuweisen">
                                                    📁 Objekt zuweisen
                                                </button>
                                                <div v-if="attachAssignOpen === (msg.email_id || msg.id) + '-' + ai" class="absolute right-0 top-full mt-1 z-50 bg-[var(--card)] border border-[var(--border)] rounded-lg shadow-lg p-2 min-w-[240px] max-h-[200px] overflow-y-auto">
                                                    <div class="text-[10px] font-semibold text-[var(--muted-foreground)] mb-1 px-1">Objekt wählen:</div>
                                                    <div v-for="p in properties" :key="p.id"
                                                        @click="saveAttachmentToProperty(msg.email_id || msg.id, ai, p.id, att)"
                                                        class="px-2 py-1.5 text-[11px] rounded cursor-pointer hover:bg-[var(--muted)] flex items-center gap-2"
                                                        :class="{ 'opacity-50 pointer-events-none': attachAssigning === (msg.email_id || msg.id) + '-' + ai }">
                                                        <span class="font-medium">{{ p.ref_id }}</span>
                                                        <span class="text-[var(--muted-foreground)] truncate">{{ p.address }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-3 text-xs text-[var(--muted-foreground)]">
                            <div>Keine weiteren Nachrichten im Thread.</div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Pagination -->
            <div v-if="ehTotalPages > 1" class="px-5 py-3 flex items-center justify-between border-t border-[var(--border)]">
                <span class="text-xs text-[var(--muted-foreground)]">Seite {{ ehPage }} von {{ ehTotalPages }} ({{ ehTotal }} gesamt)</span>
                <div class="flex gap-1">
                    <button @click="ehPageChange(ehPage - 1)" :disabled="ehPage <= 1" class="btn btn-ghost btn-sm btn-icon"><ChevronLeft class="w-4 h-4" /></button>
                    <button @click="ehPageChange(ehPage + 1)" :disabled="ehPage >= ehTotalPages" class="btn btn-ghost btn-sm btn-icon"><ChevronRight class="w-4 h-4" /></button>
                </div>
            </div>
        </div>

        <!-- INBOX (nicht zugeordnet) -->

                <!-- DRAFTS -->
        <div v-if="commsView === 'drafts'" class="card">
            <div class="px-6 py-4 border-b border-[var(--border)]">
                <h3 class="text-sm font-semibold">Entwürfe <span class="text-[var(--muted-foreground)] font-normal">({{ draftsCount }})</span></h3>
            </div>
            <div v-if="draftsLoading" class="px-5 py-8 text-center"><span class="spinner"></span></div>
            <div v-else-if="!draftsData.length" class="px-5 py-8 text-center text-sm text-[var(--muted-foreground)]">Keine Entwürfe vorhanden.</div>
            <div v-else class="divide-y divide-[var(--border)]">
                <div v-for="dr in draftsData" :key="dr.id" class="px-5 py-3 flex items-center gap-3 hover:bg-[var(--muted)] transition">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate">{{ dr.subject || '(kein Betreff)' }}</div>
                        <div class="text-[11px] text-[var(--muted-foreground)] truncate mt-0.5">
                            An: {{ dr.to_email || '—' }}
                            <span v-if="dr.stakeholder" class="ml-2">· {{ dr.stakeholder }}</span>
                        </div>
                    </div>
                    <span class="text-[11px] text-[var(--muted-foreground)]">{{ formatDate(dr.updated_at || dr.created_at) }}</span>
                    <button @click="loadDraftIntoComposer(dr)" class="btn btn-outline btn-sm"><PenSquare class="w-3 h-3" /> Bearbeiten</button>
                    <button @click="deleteDraft(dr.id)" class="btn btn-ghost btn-sm btn-icon"><Trash2 class="w-3.5 h-3.5" /></button>
                </div>
            </div>
        </div>

        <!-- TRASH -->
        <div v-if="commsView === 'trash'" class="card">
            <div class="px-6 py-4 border-b border-[var(--border)] flex items-center justify-between">
                <h3 class="text-sm font-semibold">Papierkorb <span class="text-[var(--muted-foreground)] font-normal">({{ trashCount }})</span></h3>
                <div v-if="trashSelected.length" class="flex items-center gap-2">
                    <span class="text-xs text-[var(--muted-foreground)]">{{ trashSelected.length }} ausgewählt</span>
                    <button @click="restoreEmails(trashSelected)" class="btn btn-sm btn-outline">Wiederherstellen</button>
                </div>
            </div>
            <div v-if="trashLoading" class="px-5 py-8 text-center"><span class="spinner"></span></div>
            <div v-else-if="!trashData.length" class="px-5 py-8 text-center text-sm text-[var(--muted-foreground)]">Papierkorb ist leer.</div>
            <div v-else class="divide-y divide-[var(--border)]">
                <div v-for="em in trashData" :key="em.id" class="px-5 py-3 flex items-center gap-2 hover:bg-[var(--muted)] transition">
                    <input type="checkbox" :checked="trashSelected.includes(em.id)" @change="trashSelected.includes(em.id) ? trashSelected = trashSelected.filter(i => i !== em.id) : trashSelected.push(em.id)" class="form-checkbox" />
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate">{{ em.subject || '(kein Betreff)' }}</div>
                        <div class="text-[11px] text-[var(--muted-foreground)] truncate mt-0.5">
                            {{ em.from_name || em.from_email }} &middot; {{ formatDate(em.email_date) }}
                        </div>
                    </div>
                    <button @click="restoreEmails([em.id])" class="btn btn-outline btn-sm">Wiederherstellen</button>
                </div>
            </div>
        </div>

        <!-- TEMPLATES -->
        <div v-if="commsView === 'templates'" class="card">
            <div class="px-6 py-4 border-b border-[var(--border)] flex items-center justify-between">
                <h3 class="text-sm font-semibold">E-Mail Vorlagen</h3>
                <button v-if="!templateEdit" @click="startNewTemplate()" class="btn btn-brand btn-sm"><Plus class="w-3.5 h-3.5" /> Neue Vorlage</button>
            </div>
            <!-- Template Edit Form -->
            <div v-if="templateEdit" class="px-3 sm:px-5 py-4 space-y-3 border-b border-[var(--border)]">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Name</label>
                        <input v-model="templateEdit.name" class="form-input" placeholder="Template-Name..." />
                    </div>
                    <div>
                        <label class="form-label">Kategorie</label>
                        <select v-model="templateEdit.category" class="form-select">
                            <option value="allgemein">Allgemein</option>
                            <option value="anfrage">Anfrage</option>
                            <option value="besichtigung">Besichtigung</option>
                            <option value="angebot">Angebot</option>
                            <option value="absage">Absage</option>
                            <option value="nachfassen">Nachfassen</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label text-xs" style="margin-bottom:2px">Betreff</label>
                    <input v-model="templateEdit.subject" class="form-input" placeholder="Betreff... (Platzhalter: {OBJEKT})" />
                </div>
                <div>
                    <label class="form-label">Inhalt</label>
                    <textarea v-model="templateEdit.body" class="form-textarea" rows="6" placeholder="Template-Inhalt... (Platzhalter: {OBJEKT})"></textarea>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="saveTemplate()" :disabled="templateSaving" class="btn btn-brand btn-sm">
                        <span v-if="templateSaving" class="spinner" style="width:12px;height:12px"></span>
                        <Save v-else class="w-3.5 h-3.5" /> Speichern
                    </button>
                    <button @click="cancelTemplateEdit()" class="btn btn-ghost btn-sm">Abbrechen</button>
                </div>
            </div>
            <!-- Template List -->
            <div v-if="templatesLoading" class="px-5 py-8 text-center"><span class="spinner"></span></div>
            <div v-else-if="!templates.length && !templateEdit" class="px-5 py-8 text-center text-sm text-[var(--muted-foreground)]">Keine Vorlagen vorhanden.</div>
            <div v-else-if="!templateEdit" class="divide-y divide-[var(--border)]">
                <div v-for="tpl in templates" :key="tpl.id" class="px-5 py-3 flex items-center gap-3 hover:bg-[var(--muted)] transition">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium">{{ tpl.name }}</div>
                        <div class="text-[11px] text-[var(--muted-foreground)] truncate mt-0.5">{{ tpl.subject || '(kein Betreff)' }} · {{ tpl.category }}</div>
                    </div>
                    <button @click="editTemplate(tpl)" class="btn btn-ghost btn-sm btn-icon"><Pencil class="w-3.5 h-3.5" /></button>
                    <button @click="deleteTemplate(tpl.id)" class="btn btn-ghost btn-sm btn-icon"><Trash2 class="w-3.5 h-3.5" /></button>
                </div>
            </div>
        </div>
    </div>
</template>
