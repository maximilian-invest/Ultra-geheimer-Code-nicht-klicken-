<script setup>
import { ref, inject, onMounted } from "vue";
import { Save, Lock, User, Phone, Mail, FileSignature, Globe, Building, Image, Trash2, Upload, Plus } from "lucide-vue-next";
import EmailAccountsTab from "./EmailAccountsTab.vue";

const API = inject("API");
const toast = inject("toast");

const loading = ref(true);
const saving = ref(false);

// Profile
const profileName = ref("");
const profileEmail = ref("");
const profilePhone = ref("");

// Signature
const sigName = ref("");
const sigTitle = ref("");
const sigCompany = ref("");
const sigPhone = ref("");
const sigWebsite = ref("");
const sigLogoUrl = ref(null);
const sigBannerUrl = ref(null);
const sigPhotoUrl = ref(null);
const sigLogoUploading = ref(false);
const sigBannerUploading = ref(false);
const sigPhotoUploading = ref(false);

// Password
// Auto-Reply
const autoReplyEnabled = ref(false);
const autoReplyText = ref('');
const autoReplyLog = ref([]);
const autoReplyToggling = ref(false);
const autoReplyPropertyIds = ref([]);  // selected property IDs
const allProperties = ref([]);  // all properties for selection

const pwCurrent = ref("");
const pwNew = ref("");
const pwConfirm = ref("");
const pwSaving = ref(false);
const inboxRules = ref([]);
const inboxRulesLoading = ref(false);
const inboxRuleSaving = ref(false);
const inboxRulePattern = ref("");

onMounted(() => { loadSettings(); loadFeedStatus(); loadInboxRules(); });

async function loadSettings() {
    loading.value = true;
    try {
        const r = await fetch(API.value + "&action=get_settings");
        const d = await r.json();
        profileName.value = d.name || "";
        profileEmail.value = d.email || "";
        profilePhone.value = d.phone || "";
        sigName.value = d.signature_name || "";
        sigTitle.value = d.signature_title || "";
        sigCompany.value = d.signature_company || "";
        sigPhone.value = d.signature_phone || "";
        sigWebsite.value = d.signature_website || "";
        sigLogoUrl.value = d.signature_logo_url || null;
        sigBannerUrl.value = d.signature_banner_url || null;
        sigPhotoUrl.value = d.signature_photo_url || null;
        autoReplyEnabled.value = !!d.auto_reply_enabled;
        autoReplyText.value = d.auto_reply_text || '';
        autoReplyLog.value = d.auto_reply_log || [];
        autoReplyPropertyIds.value = d.auto_reply_property_ids 
            ? d.auto_reply_property_ids.split(',').map(Number).filter(Boolean) 
            : [];
        
        // Load all properties for whitelist selection
        try {
            const pr = await fetch(API.value + "&action=list_properties");
            const pd = await pr.json();
            allProperties.value = pd.properties || [];
        } catch(e) {}
    } catch (e) { toast("Fehler: " + e.message); }
    loading.value = false;
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
        } else {
            toast("Fehler: " + (d.error || "Unbekannt"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
    autoReplyToggling.value = false;
}

async function saveAutoReplyText() {
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
        if (d.success) toast("Text gespeichert!");
    } catch(e) { toast("Fehler: " + e.message); }
}

async function saveProfile() {
    saving.value = true;
    try {
        const r = await fetch(API.value + "&action=save_settings", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                name: profileName.value,
                email: profileEmail.value,
                phone: profilePhone.value,
                signature_name: sigName.value,
                signature_title: sigTitle.value,
                signature_company: sigCompany.value,
                signature_phone: sigPhone.value,
                signature_website: sigWebsite.value,
            }),
        });
        const d = await r.json();
        if (d.success) toast("Einstellungen gespeichert!");
        else toast("Fehler: " + (d.error || "Unbekannt"));
    } catch (e) { toast("Fehler: " + e.message); }
    saving.value = false;
}

async function uploadSigImage(event, type) {
    const file = event.target.files[0];
    if (!file) return;
    event.target.value = "";

    const loading = type === 'logo' ? sigLogoUploading : sigBannerUploading;
    loading.value = true;
    try {
        const fd = new FormData();
        fd.append("type", type);
        fd.append("image", file);
        const r = await fetch(API.value + "&action=upload_signature_image", { method: "POST", body: fd });
        const d = await r.json();
        if (d.success) {
            if (type === 'logo') sigLogoUrl.value = d.url;
            else if (type === 'banner') sigBannerUrl.value = d.url;
            else sigPhotoUrl.value = d.url;
            toast({logo:'Logo',banner:'Banner',photo:'Portrait'}[type] + ' hochgeladen!');
        } else toast("Fehler: " + (d.error || "Unbekannt"));
    } catch (e) { toast("Fehler: " + e.message); }
    loading.value = false;
}

async function deleteSigImage(type) {
    try {
        const r = await fetch(API.value + "&action=delete_signature_image", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ type }),
        });
        const d = await r.json();
        if (d.success) {
            if (type === 'logo') sigLogoUrl.value = null;
            else if (type === 'banner') sigBannerUrl.value = null;
            else sigPhotoUrl.value = null;
            toast({logo:'Logo',banner:'Banner',photo:'Portrait'}[type] + ' entfernt.');
        }
    } catch (e) { toast("Fehler: " + e.message); }
}


// willhaben Feed
const feedLoading = ref(false);
const feedStatus = ref(null);
const feedCopied = ref(false);
const FEED_URL = 'https://kundenportal.sr-homes.at/api/openimmo/willhaben.xml';

async function loadFeedStatus() {
    feedLoading.value = true;
    try {
        const r = await fetch('/api/openimmo/status');
        feedStatus.value = await r.json();
    } catch (e) { toast('Feed-Status konnte nicht geladen werden'); }
    feedLoading.value = false;
}

function copyFeedUrl() {
    navigator.clipboard.writeText(FEED_URL);
    feedCopied.value = true;
    toast('Feed-URL kopiert!');
    setTimeout(() => feedCopied.value = false, 2000);
}

async function changePassword() {
    if (pwNew.value !== pwConfirm.value) { toast("Passwörter stimmen nicht überein"); return; }
    if (pwNew.value.length < 8) { toast("Mindestens 8 Zeichen"); return; }
    pwSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=change_password", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ current_password: pwCurrent.value, new_password: pwNew.value }),
        });
        const d = await r.json();
        if (d.success) { toast("Passwort geändert!"); pwCurrent.value = ""; pwNew.value = ""; pwConfirm.value = ""; }
        else toast(d.error || "Fehler");
    } catch (e) { toast("Fehler: " + e.message); }
    pwSaving.value = false;
}

async function loadInboxRules() {
    inboxRulesLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=list_inbox_rules");
        const d = await r.json();
        inboxRules.value = d.rules || [];
    } catch (e) { toast("Fehler: " + e.message); }
    inboxRulesLoading.value = false;
}

async function addInboxRule() {
    const pattern = inboxRulePattern.value.trim();
    if (!pattern) return;
    inboxRuleSaving.value = true;
    try {
        const r = await fetch(API.value + "&action=save_inbox_rule", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ pattern, action: "exclude_anfragen", enabled: true }),
        });
        const d = await r.json();
        if (d.success) {
            inboxRulePattern.value = "";
            await loadInboxRules();
            toast("Inbox-Regel gespeichert");
        } else {
            toast("Fehler: " + (d.error || "Unbekannt"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
    inboxRuleSaving.value = false;
}

async function deleteInboxRule(id) {
    try {
        const r = await fetch(API.value + "&action=delete_inbox_rule", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id }),
        });
        const d = await r.json();
        if (d.success) {
            await loadInboxRules();
            toast("Inbox-Regel gelöscht");
        } else {
            toast("Fehler: " + (d.error || "Unbekannt"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
}
</script>

<template>
    <div class="px-4 py-6 max-w-2xl mx-auto space-y-6">
        <h2 class="text-lg font-bold">Einstellungen</h2>

        <div v-if="loading" class="text-center py-12"><span class="spinner"></span></div>
        <template v-else>
            <!-- Profile -->
            <div class="card">
                <div class="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                    <User class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">Profil</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Name</label>
                            <input v-model="profileName" class="form-input" />
                        </div>
                        <div>
                            <label class="form-label">Telefon</label>
                            <input v-model="profilePhone" class="form-input" placeholder="+43 ..." />
                        </div>
                    </div>
                    <div>
                        <label class="form-label">E-Mail</label>
                        <input v-model="profileEmail" type="email" class="form-input" />
                    </div>
                </div>
            </div>

            <!-- Signature -->
            <div class="card">
                <div class="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                    <FileSignature class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">E-Mail Signatur</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Name</label>
                            <input v-model="sigName" class="form-input" />
                        </div>
                        <div>
                            <label class="form-label">Titel / Funktion</label>
                            <input v-model="sigTitle" class="form-input" />
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Firma</label>
                            <input v-model="sigCompany" class="form-input" />
                        </div>
                        <div>
                            <label class="form-label">Telefon (Signatur)</label>
                            <input v-model="sigPhone" class="form-input" />
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Website</label>
                        <input v-model="sigWebsite" class="form-input" placeholder="www...." />
                    </div>

                    <!-- Signature Images -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="form-label flex items-center gap-1.5"><Image class="w-3.5 h-3.5" /> Logo</label>
                            <div v-if="sigLogoUrl" class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border)] bg-[var(--muted)]">
                                <img :src="sigLogoUrl" alt="Logo" class="max-h-12 max-w-[120px] object-contain" />
                                <button @click="deleteSigImage('logo')" class="btn btn-ghost btn-sm btn-icon text-red-500"><Trash2 class="w-3.5 h-3.5" /></button>
                            </div>
                            <label v-else class="flex items-center justify-center gap-2 p-4 rounded-lg border-2 border-dashed border-[var(--border)] cursor-pointer hover:border-[var(--brand)] transition text-xs text-[var(--muted-foreground)]">
                                <Upload class="w-4 h-4" />
                                <span>{{ sigLogoUploading ? 'Uploading...' : 'Logo hochladen' }}</span>
                                <input type="file" accept="image/*" @change="e => uploadSigImage(e, 'logo')" class="hidden" :disabled="sigLogoUploading" />
                            </label>
                            <p class="text-[10px] text-[var(--muted-foreground)] mt-1">Oberhalb der Signatur</p>
                        </div>
                        <div>
                            <label class="form-label flex items-center gap-1.5"><Image class="w-3.5 h-3.5" /> Portrait</label>
                            <div v-if="sigPhotoUrl" class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border)] bg-[var(--muted)]">
                                <img :src="sigPhotoUrl" alt="Portrait" class="max-h-20 max-w-[60px] object-cover rounded" />
                                <button @click="deleteSigImage('photo')" class="btn btn-ghost btn-sm btn-icon text-red-500"><Trash2 class="w-3.5 h-3.5" /></button>
                            </div>
                            <label v-else class="flex items-center justify-center gap-2 p-4 rounded-lg border-2 border-dashed border-[var(--border)] cursor-pointer hover:border-[var(--brand)] transition text-xs text-[var(--muted-foreground)]">
                                <Upload class="w-4 h-4" />
                                <span>{{ sigPhotoUploading ? 'Uploading...' : 'Portrait hochladen' }}</span>
                                <input type="file" accept="image/*" @change="e => uploadSigImage(e, 'photo')" class="hidden" :disabled="sigPhotoUploading" />
                            </label>
                            <p class="text-[10px] text-[var(--muted-foreground)] mt-1">Hochformat, neben Text</p>
                        </div>
                        <div>
                            <label class="form-label flex items-center gap-1.5"><Image class="w-3.5 h-3.5" /> Banner</label>
                            <div v-if="sigBannerUrl" class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border)] bg-[var(--muted)]">
                                <img :src="sigBannerUrl" alt="Banner" class="max-h-16 max-w-[180px] object-contain rounded" />
                                <button @click="deleteSigImage('banner')" class="btn btn-ghost btn-sm btn-icon text-red-500"><Trash2 class="w-3.5 h-3.5" /></button>
                            </div>
                            <label v-else class="flex items-center justify-center gap-2 p-4 rounded-lg border-2 border-dashed border-[var(--border)] cursor-pointer hover:border-[var(--brand)] transition text-xs text-[var(--muted-foreground)]">
                                <Upload class="w-4 h-4" />
                                <span>{{ sigBannerUploading ? 'Uploading...' : 'Banner hochladen' }}</span>
                                <input type="file" accept="image/*" @change="e => uploadSigImage(e, 'banner')" class="hidden" :disabled="sigBannerUploading" />
                            </label>
                            <p class="text-[10px] text-[var(--muted-foreground)] mt-1">Unterhalb der Signatur</p>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="rounded-lg border border-dashed border-[var(--border)] p-4 text-xs">
                        <div class="text-[10px] font-semibold uppercase tracking-wider mb-2 text-[var(--muted-foreground)]">Vorschau</div>
                        <table cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333">
                            <tr v-if="sigLogoUrl"><td :colspan="sigPhotoUrl ? 2 : 1" style="padding-bottom:8px"><img :src="sigLogoUrl" alt="Logo" style="max-height:60px;max-width:200px" /></td></tr>
                            <tr><td v-if="sigPhotoUrl" style="border-top:2px solid #ee7606;padding-top:8px;padding-right:12px;vertical-align:top"><img :src="sigPhotoUrl" alt="Portrait" style="width:70px;height:90px;object-fit:cover;border-radius:4px" /></td>
                            <td style="border-top:2px solid #ee7606;padding-top:8px">
                                <strong style="font-size:14px;color:#222">{{ sigName || 'Name' }}</strong>
                                <br v-if="sigTitle" /><span v-if="sigTitle" style="color:#666">{{ sigTitle }}</span>
                                <br /><span style="color:#666">{{ sigCompany || 'Firma' }}</span>
                                <br />Tel: <span style="color:#ee7606">{{ sigPhone || 'Telefon' }}</span>
                                <br /><span style="color:#ee7606">{{ sigWebsite || 'Website' }}</span>
                            </td></tr>
                            <tr v-if="sigBannerUrl"><td :colspan="sigPhotoUrl ? 2 : 1" style="padding-top:8px"><img :src="sigBannerUrl" alt="Banner" style="max-width:400px;width:100%;border-radius:4px" /></td></tr>
                        </table>
                    </div>

                    <button @click="saveProfile()" :disabled="saving" class="btn btn-brand">
                        <Save class="w-4 h-4" />
                        {{ saving ? 'Speichern...' : 'Speichern' }}
                    </button>
                </div>
            </div>

            <!-- Auto-Reply moved to PrioritiesTab -->

            <!-- Inbox Regeln -->
            <div class="card">
                <div class="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                    <Mail class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">Inbox-Regeln</h3>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-xs text-[var(--muted-foreground)]">
                        Absender, die hier hinterlegt sind, werden nicht mehr unter <strong>Anfragen</strong> gelistet.
                        Beispiele: <code class="text-[11px]">newsletter@example.com</code> oder <code class="text-[11px]">@portal.com</code>
                    </p>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="inboxRulePattern"
                            class="form-input"
                            placeholder="Absender oder Domain eingeben..."
                            @keyup.enter="addInboxRule()"
                        />
                        <button @click="addInboxRule()" :disabled="inboxRuleSaving || !inboxRulePattern.trim()" class="btn btn-outline btn-sm whitespace-nowrap">
                            <Plus class="w-4 h-4" />
                            Hinzufügen
                        </button>
                    </div>

                    <div v-if="inboxRulesLoading" class="text-center py-3"><span class="spinner"></span></div>
                    <div v-else-if="!inboxRules.length" class="text-xs text-[var(--muted-foreground)] italic">
                        Keine Regeln hinterlegt.
                    </div>
                    <div v-else class="space-y-2">
                        <div
                            v-for="rule in inboxRules"
                            :key="rule.id"
                            class="flex items-center justify-between rounded-lg border border-[var(--border)] bg-[var(--muted)] px-3 py-2"
                        >
                            <div class="min-w-0">
                                <div class="text-sm font-medium break-all">{{ rule.pattern }}</div>
                                <div class="text-[11px] text-[var(--muted-foreground)]">Aktion: nicht unter Anfragen listen</div>
                            </div>
                            <button @click="deleteInboxRule(rule.id)" class="btn btn-ghost btn-icon btn-sm text-red-500">
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>


            <!-- willhaben Feed / Portale -->
            <div class="card">
                <div class="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                    <Globe class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">Portale & Feeds</h3>
                </div>
                <div class="p-6 space-y-4">
                    <!-- Feed URL -->
                    <div>
                        <label class="form-label">willhaben.at OpenImmo Feed</label>
                        <div class="flex items-center gap-2">
                            <input :value="FEED_URL" readonly class="form-input flex-1 text-xs font-mono bg-[var(--muted)]" />
                            <button @click="copyFeedUrl()" class="btn btn-outline btn-sm whitespace-nowrap">
                                {{ feedCopied ? 'Kopiert!' : 'Kopieren' }}
                            </button>
                        </div>
                        <p class="text-[10px] text-[var(--muted-foreground)] mt-1">Diese URL bei willhaben.at als Daten-Feed hinterlegen</p>
                    </div>

                    <!-- Feed Status -->
                    <div v-if="feedLoading" class="text-center py-4"><span class="spinner"></span></div>
                    <div v-else-if="feedStatus" class="space-y-3">
                        <div class="flex items-center gap-4 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="feedStatus.exported_count > 0 ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'">
                                {{ feedStatus.exported_count }} {{ feedStatus.exported_count === 1 ? 'Objekt' : 'Objekte' }} im Feed
                            </span>
                            <span class="text-[var(--muted-foreground)] text-xs">
                                Zuletzt generiert: {{ feedStatus.last_generated ? new Date(feedStatus.last_generated).toLocaleString('de-AT') : 'noch nie' }}
                            </span>
                        </div>

                        <!-- Exported Properties List -->
                        <div v-if="feedStatus.properties && feedStatus.properties.length > 0">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b border-[var(--border)]">
                                        <th class="text-left py-1.5 font-medium text-[var(--muted-foreground)]">Ref-ID</th>
                                        <th class="text-left py-1.5 font-medium text-[var(--muted-foreground)]">Adresse</th>
                                        <th class="text-left py-1.5 font-medium text-[var(--muted-foreground)]">Ort</th>
                                        <th class="text-right py-1.5 font-medium text-[var(--muted-foreground)]">Bilder</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in feedStatus.properties" :key="p.id" class="border-b border-[var(--border)] last:border-0">
                                        <td class="py-1.5 font-mono">{{ p.ref_id || '-' }}</td>
                                        <td class="py-1.5">{{ p.address || '-' }}</td>
                                        <td class="py-1.5">{{ p.city || '-' }}</td>
                                        <td class="py-1.5 text-right">{{ p.image_count }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="text-xs text-[var(--muted-foreground)] italic">
                            Keine Objekte im Feed. Aktiviere &quot;willhaben&quot; bei einem Objekt unter Portal-Zuordnung.
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button @click="loadFeedStatus()" :disabled="feedLoading" class="btn btn-outline btn-sm">
                            {{ feedLoading ? 'Laden...' : 'Feed-Status aktualisieren' }}
                        </button>
                        <a :href="FEED_URL" target="_blank" class="btn btn-ghost btn-sm text-xs">XML ansehen</a>
                    </div>
                </div>
            </div>

            <!-- Password -->
            <div class="card">
                <div class="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                    <Lock class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">Passwort ändern</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="form-label">Aktuelles Passwort</label>
                        <input v-model="pwCurrent" type="password" class="form-input" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Neues Passwort</label>
                            <input v-model="pwNew" type="password" class="form-input" placeholder="Mind. 8 Zeichen" />
                        </div>
                        <div>
                            <label class="form-label">Passwort bestätigen</label>
                            <input v-model="pwConfirm" type="password" class="form-input" />
                        </div>
                    </div>
                    <button @click="changePassword()" :disabled="pwSaving || !pwCurrent || !pwNew" class="btn btn-outline">
                        <Lock class="w-4 h-4" />
                        {{ pwSaving ? 'Ändern...' : 'Passwort ändern' }}
                    </button>
                </div>
            </div>
            <!-- E-Mail Konten -->
            <div class="card">
                <div class="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                    <Mail class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">E-Mail Konten</h3>
                </div>
                <div class="p-4">
                    <EmailAccountsTab />
                </div>
            </div>

        </template>
    </div>
</template>
