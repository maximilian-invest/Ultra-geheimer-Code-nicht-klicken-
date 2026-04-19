<script setup>
import { ref, inject, onMounted } from "vue";
import { Plus, Pencil, Wifi, Trash2, X, Mail } from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");

const emailAccounts = ref([]);
const showModal = ref(false);
const emailAccountForm = ref({});
const testResult = ref(null);
const testing = ref(false);
const saving = ref(false);

onMounted(() => loadEmailAccounts());

async function loadEmailAccounts() {
    try {
        const r = await fetch(API.value + "&action=email_accounts");
        const d = await r.json();
        emailAccounts.value = d.accounts || [];
    } catch (e) { toast("Fehler: " + e.message); }
}

function openModal(account = null) {
    if (account) {
        emailAccountForm.value = { ...account, imap_password: "", smtp_password: "" };
    } else {
        emailAccountForm.value = {
            label: "", email_address: "", from_name: "SR-Homes Immobilien",
            imap_host: "imap.hostinger.com", imap_port: 993, imap_encryption: "ssl",
            imap_username: "", imap_password: "",
            smtp_host: "smtp.hostinger.com", smtp_port: 587, smtp_encryption: "tls",
            smtp_username: "", smtp_password: "", is_active: 1,
        };
    }
    testResult.value = null;
    showModal.value = true;
}

async function saveAccount() {
    saving.value = true;
    try {
        const r = await fetch(API.value + "&action=save_email_account", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify(emailAccountForm.value),
        });
        const d = await r.json();
        if (d.success) { toast("Konto gespeichert!"); showModal.value = false; loadEmailAccounts(); }
        else toast("Fehler: " + (d.error || "Unbekannt"));
    } catch (e) { toast("Fehler: " + e.message); }
    saving.value = false;
}

async function deleteAccount(id) {
    if (!confirm("E-Mail Konto wirklich loschen?")) return;
    try {
        const r = await fetch(API.value + "&action=delete_email_account&force=1", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id }),
        });
        const d = await r.json();
        if (d.success) { toast("Konto geloscht"); loadEmailAccounts(); }
        else toast("Fehler: " + (d.error || "Unbekannt"));
    } catch (e) { toast("Fehler: " + e.message); }
}

async function testAccount() {
    testing.value = true;
    testResult.value = null;
    try {
        const r = await fetch(API.value + "&action=test_email_account", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify(emailAccountForm.value),
        });
        testResult.value = await r.json();
    } catch (e) { testResult.value = { error: e.message }; }
    testing.value = false;
}

function formatDate(s) {
    if (!s) return "";
    return new Date(s).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}
</script>

<template>
    <div class="px-4 py-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold">E-Mail Konten</h2>
            <button @click="openModal()" class="btn btn-primary btn-sm"><Plus class="w-3.5 h-3.5" /> Neues Konto</button>
        </div>

        <div v-if="!emailAccounts.length" class="card px-6 py-12 text-center text-[var(--muted-foreground)]">
            <Mail class="w-8 h-8 mx-auto mb-2 opacity-50" />
            <p class="text-sm">Noch keine E-Mail Konten konfiguriert</p>
        </div>

        <div v-for="account in emailAccounts" :key="account.id" class="card">
            <div class="px-6 py-4 flex items-center gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-sm font-semibold">{{ account.label }}</span>
                        <span :class="['badge', account.is_active ? 'badge-success' : 'badge-muted']">{{ account.is_active ? 'Aktiv' : 'Inaktiv' }}</span>
                    </div>
                    <div class="text-xs text-[var(--muted-foreground)]">{{ account.email_address }}</div>
                    <div class="text-[10px] text-[var(--muted-foreground)] mt-1 flex gap-3">
                        <span>IMAP: {{ account.imap_host }}:{{ account.imap_port }}</span>
                        <span>SMTP: {{ account.smtp_host }}:{{ account.smtp_port }}</span>
                        <span>Letzter Abruf: {{ account.last_fetch_at ? formatDate(account.last_fetch_at) : 'Noch nie' }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button @click="openModal(account)" class="btn btn-outline btn-sm"><Pencil class="w-3.5 h-3.5" /> Bearbeiten</button>
                    <button @click="emailAccountForm = {...account, imap_password:'', smtp_password:''}; testAccount();" class="btn btn-outline btn-sm"><Wifi class="w-3.5 h-3.5" /> Testen</button>
                    <button @click="deleteAccount(account.id)" class="btn btn-outline btn-icon btn-sm" style="color:hsl(var(--destructive))"><Trash2 class="w-3.5 h-3.5" /></button>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-[var(--card)] rounded-xl shadow-lg w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="px-6 py-4 flex items-center justify-between border-b border-[var(--border)]">
                    <h3 class="text-sm font-semibold">{{ emailAccountForm.id ? 'Konto bearbeiten' : 'Neues Konto' }}</h3>
                    <button @click="showModal = false" class="btn btn-ghost btn-icon btn-sm"><X class="w-4 h-4" /></button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="form-label">Bezeichnung</label><input v-model="emailAccountForm.label" class="form-input" /></div>
                        <div><label class="form-label">E-Mail Adresse</label><input v-model="emailAccountForm.email_address" class="form-input" /></div>
                    </div>
                    <div><label class="form-label">Absendername</label><input v-model="emailAccountForm.from_name" class="form-input" /></div>
                    <div class="border-t border-[var(--border)] pt-4">
                        <h4 class="text-xs font-semibold mb-3">IMAP (Eingang)</h4>
                        <div class="grid grid-cols-3 gap-4">
                            <div><label class="form-label">Host</label><input v-model="emailAccountForm.imap_host" class="form-input" /></div>
                            <div><label class="form-label">Port</label><input v-model.number="emailAccountForm.imap_port" class="form-input" type="number" /></div>
                            <div><label class="form-label">Verschlusselung</label><select v-model="emailAccountForm.imap_encryption" class="form-select"><option>ssl</option><option>tls</option><option>none</option></select></div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-3">
                            <div><label class="form-label">Benutzername</label><input v-model="emailAccountForm.imap_username" class="form-input" /></div>
                            <div><label class="form-label">Passwort</label><input v-model="emailAccountForm.imap_password" class="form-input" type="password" placeholder="Leer = unverandert" /></div>
                        </div>
                    </div>
                    <div class="border-t border-[var(--border)] pt-4">
                        <h4 class="text-xs font-semibold mb-3">SMTP (Ausgang)</h4>
                        <div class="grid grid-cols-3 gap-4">
                            <div><label class="form-label">Host</label><input v-model="emailAccountForm.smtp_host" class="form-input" /></div>
                            <div><label class="form-label">Port</label><input v-model.number="emailAccountForm.smtp_port" class="form-input" type="number" /></div>
                            <div><label class="form-label">Verschlusselung</label><select v-model="emailAccountForm.smtp_encryption" class="form-select"><option>tls</option><option>ssl</option><option>none</option></select></div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-3">
                            <div><label class="form-label">Benutzername</label><input v-model="emailAccountForm.smtp_username" class="form-input" /></div>
                            <div><label class="form-label">Passwort</label><input v-model="emailAccountForm.smtp_password" class="form-input" type="password" placeholder="Leer = unverandert" /></div>
                        </div>
                    </div>

                    <!-- Test result -->
                    <div v-if="testResult" class="p-3 rounded-lg text-xs" :class="testResult.error ? 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' : 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400'">
                        <div v-if="testResult.error">Fehler: {{ testResult.error }}</div>
                        <div v-else>
                            <div>IMAP: {{ testResult.imap_ok ? 'OK' : 'Fehler' }}</div>
                            <div>SMTP: {{ testResult.smtp_ok ? 'OK' : 'Fehler' }}</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button @click="saveAccount()" :disabled="saving" class="btn btn-primary">
                            <span v-if="saving" class="spinner" style="width:14px;height:14px"></span>
                            <span>Speichern</span>
                        </button>
                        <button @click="testAccount()" :disabled="testing" class="btn btn-outline">
                            <span v-if="testing" class="spinner" style="width:14px;height:14px"></span>
                            <Wifi v-else class="w-4 h-4" />
                            <span>Verbindung testen</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
