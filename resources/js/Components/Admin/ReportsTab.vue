<script setup>
import { ref, inject } from "vue";
import { FileText, RefreshCw, XCircle, BarChart2, Home, Users, TrendingDown, ChevronDown, ChevronUp, Copy, Check, AlertTriangle, TrendingUp, Activity, Shield, Target, Clock, Download } from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");
const properties = inject("properties");

const reportPropertyId = ref(null);
const reportContent = ref(null);
const reportLoading = ref(false);
const reportTimestamp = ref(null);

// Tab: schnellanalyse | vermarktungsbericht
const reportTab = ref('vermarktungsbericht');

// Vermarktungsbericht
const vBericht = ref(null);
const vLoading = ref(false);
const pdfLoading = ref(false);

const feedbackData = ref(null);
const feedbackLoading = ref(false);

// Expandable sections
const expandedSections = ref({});
const copiedField = ref(null);

function toggleSection(key) { expandedSections.value[key] = !expandedSections.value[key]; }

function copyText(text, field) {
    navigator.clipboard.writeText(text);
    copiedField.value = field;
    setTimeout(() => { copiedField.value = null; }, 2000);
}

async function loadReport(propertyId) {
    reportPropertyId.value = propertyId;
    reportContent.value = null;
    reportLoading.value = false;
    vBericht.value = null;
    feedbackData.value = null;
    loadFeedback(propertyId);
    loadStoredReport(propertyId);
    loadStoredVBericht(propertyId);
}

async function loadStoredReport(propertyId) {
    reportLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=generate_analysis&property_id=" + propertyId + "&stored=1");
        const d = await r.json();
        if (!d.empty) { reportContent.value = d; reportTimestamp.value = d.generatedAt || null; }
    } catch (e) { toast("Fehler: " + e.message); }
    reportLoading.value = false;
}

async function generateReport() {
    if (!reportPropertyId.value) return;
    reportLoading.value = true;
    reportContent.value = null;
    try {
        const r = await fetch(API.value + "&action=generate_analysis&property_id=" + reportPropertyId.value);
        const d = await r.json();
        if (d.error) toast("Fehler: " + d.error);
        else { reportContent.value = d; reportTimestamp.value = d.generatedAt || null; toast("Bericht generiert!"); }
    } catch (e) { toast("Fehler: " + e.message); }
    reportLoading.value = false;
}

async function loadStoredVBericht(propertyId) {
    try {
        const r = await fetch(API.value + "&action=get_vermarktungsbericht&property_id=" + propertyId);
        const d = await r.json();
        if (!d.empty && d.owner) {
            vBericht.value = d;
            // Auto-switch to Vermarktungsbericht tab if one exists
            reportTab.value = 'vermarktungsbericht';
        }
    } catch (e) {}
}

async function generateVBericht() {
    if (!reportPropertyId.value) return;
    vLoading.value = true;
    vBericht.value = null;
    reportTab.value = 'vermarktungsbericht';
    try {
        const r = await fetch(API.value + "&action=generate_vermarktungsbericht&property_id=" + reportPropertyId.value);
        const d = await r.json();
        if (d.error) { toast("Fehler: " + d.error); }
        else { vBericht.value = d; toast("Vermarktungsbericht generiert!"); }
    } catch (e) { toast("Fehler: " + e.message); }
    vLoading.value = false;
}


async function downloadPdf() {
    if (!reportPropertyId.value || !vBericht.value) return;
    pdfLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=export_vermarktungsbericht_pdf&property_id=" + reportPropertyId.value);
        if (!r.ok) {
            const err = await r.json().catch(() => ({ error: 'PDF-Export fehlgeschlagen' }));
            toast("Fehler: " + (err.error || 'Unbekannter Fehler'));
            pdfLoading.value = false;
            return;
        }
        const blob = await r.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        const prop = properties.value?.find(p => p.id === reportPropertyId.value);
        a.href = url;
        a.download = "Vermarktungsbericht_" + (prop?.address || "Objekt").replace(/[^a-zA-Z0-9_-]/g, "_") + ".pdf";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        toast("PDF heruntergeladen!");
    } catch (e) { toast("Fehler: " + e.message); }
    pdfLoading.value = false;
}

async function loadFeedback(propertyId) {
    feedbackLoading.value = true;
    try {
        const r = await fetch(API.value + "&action=property_feedback&property_id=" + propertyId);
        const d = await r.json();
        if (!d.error) feedbackData.value = d;
    } catch (e) {}
    feedbackLoading.value = false;
}

function formatDate(s) {
    if (!s) return "";
    return new Date(s).toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

function absageBarWidth(count) {
    const total = feedbackData.value?.stats?.absagen || 1;
    return Math.min(100, Math.round(count / total * 100)) + '%';
}

const statusColors = { green: '#16a34a', yellow: '#ca8a04', orange: '#ea580c', red: '#dc2626' };
const statusLabels = { green: 'Planmässig', yellow: 'Optimierung möglich', orange: 'Aufmerksamkeit nötig', red: 'Handlungsbedarf' };
const statusBg = { green: '#dcfce7', yellow: '#fef9c3', orange: '#ffedd5', red: '#fee2e2' };

const fitColors = { passend: '#16a34a', leicht_ambitioniert: '#ca8a04', deutlich_ambitioniert: '#ea580c', marktfern: '#dc2626' };
const fitLabels = { passend: 'Passend', leicht_ambitioniert: 'Leicht ambitioniert', deutlich_ambitioniert: 'Deutlich ambitioniert', marktfern: 'Marktfern' };

const dringColors = { hoch: '#dc2626', mittel: '#ca8a04', niedrig: '#6b7280' };
const gewichtColors = { transaktionskritisch: '#dc2626', substanziell: '#ea580c', sekundaer: '#6b7280' };
const confColors = { hoch: '#16a34a', mittel: '#ca8a04', niedrig: '#dc2626' };
</script>

<template>
    <div class="px-4 py-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold">Berichte</h2>
        </div>

        <!-- Property Select + Actions -->
        <div class="card">
            <div class="px-6 py-4 border-b border-[var(--border)]">
                <div class="flex items-center gap-4 flex-wrap">
                    <select v-model="reportPropertyId" @change="reportPropertyId && loadReport(reportPropertyId)" class="form-select" style="max-width:400px">
                        <option :value="null">Objekt wählen...</option>
                        <option v-for="p in properties" :key="p.id" :value="p.id">{{ p.ref_id }} - {{ p.address }}, {{ p.city }}</option>
                    </select>
                    <button v-if="reportPropertyId" @click="generateVBericht()" :disabled="vLoading" class="btn btn-brand btn-sm">
                        <span v-if="vLoading" class="spinner" style="width:14px;height:14px"></span>
                        <BarChart2 v-else class="w-3.5 h-3.5" />
                        <span>Vermarktungsbericht</span>
                    </button>
                    <button v-if="vBericht && reportPropertyId" @click="downloadPdf()" :disabled="pdfLoading" class="btn btn-sm" style="background:#16a34a;color:#fff;border:none">
                        <span v-if="pdfLoading" class="spinner" style="width:14px;height:14px"></span>
                        <Download v-else class="w-3.5 h-3.5" />
                        <span>PDF</span>
                    </button>
                </div>
            </div>

            <!-- Tab Switch -->

            <!-- SCHNELLANALYSE TAB -->
            <!-- VERMARKTUNGSBERICHT -->
            <div v-if="reportTab==='vermarktungsbericht'" class="px-6 py-6">
                <div v-if="vLoading" class="text-center py-16">
                    <span class="spinner" style="width:24px;height:24px"></span>
                    <p class="text-sm text-[var(--muted-foreground)] mt-3">Vermarktungsbericht wird erstellt...</p>
                    <p class="text-[10px] text-[var(--muted-foreground)] mt-1">Dies kann 15-30 Sekunden dauern</p>
                </div>
                <div v-else-if="!vBericht" class="text-center py-16 text-[var(--muted-foreground)]">
                    <BarChart2 class="w-8 h-8 mx-auto mb-2 opacity-50" />
                    <p class="text-sm">Kein Vermarktungsbericht vorhanden.</p>
                    <p class="text-xs mt-1">Klicken Sie auf "Vermarktungsbericht" um einen zu generieren.</p>
                </div>
                <div v-else class="space-y-5">

                    <!-- Meta -->
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-bold px-3 py-1 rounded-full" :style="{background: statusBg[vBericht.owner?.status] || '#f1f5f9', color: statusColors[vBericht.owner?.status] || '#64748b'}">
                                {{ statusLabels[vBericht.owner?.status] || vBericht.owner?.status }}
                            </span>
                            <span v-if="vBericht.meta?.data_quality" class="text-[10px] px-2 py-0.5 rounded-full" :style="{background: confColors[vBericht.meta.data_quality]==='#16a34a' ? '#dcfce7' : confColors[vBericht.meta.data_quality]==='#ca8a04' ? '#fef9c3' : '#fee2e2', color: confColors[vBericht.meta.data_quality]}">
                                Datenqualität: {{ vBericht.meta.data_quality }}
                            </span>
                        </div>
                        <span class="text-[10px] text-[var(--muted-foreground)]">{{ formatDate(vBericht.generatedAt || vBericht.meta?.generated_at) }}</span>
                    </div>

                    <!-- ═══ MAKLER-ARBEITSANSICHT (broker) ═══ -->
                    <template v-if="vBericht.broker">

                        <!-- Gesamteinschätzung -->
                        <div class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                                <Target class="w-4 h-4 text-blue-500" />
                                Gesamteinschätzung
                                <span v-if="vBericht.broker.gesamteinschaetzung?.confidence" class="ml-auto text-[10px] px-2 py-0.5 rounded-full" :style="{color: confColors[vBericht.broker.gesamteinschaetzung.confidence], background: confColors[vBericht.broker.gesamteinschaetzung.confidence]==='#16a34a' ? '#dcfce7' : confColors[vBericht.broker.gesamteinschaetzung.confidence]==='#ca8a04' ? '#fef9c3' : '#fee2e2'}">
                                    Confidence: {{ vBericht.broker.gesamteinschaetzung.confidence }}
                                </span>
                            </h4>
                            <div class="space-y-2 text-sm text-[var(--muted-foreground)]">
                                <p><span class="font-medium text-[var(--foreground)]">Vermarktungsqualität:</span> {{ vBericht.broker.gesamteinschaetzung?.vermarktungsqualitaet }}</p>
                                <p><span class="font-medium text-[var(--foreground)]">Marktvalidierung:</span> {{ vBericht.broker.gesamteinschaetzung?.marktvalidierung }}</p>
                                <p class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
                                    <span class="font-medium text-amber-700 dark:text-amber-400">Engpass:</span> {{ vBericht.broker.gesamteinschaetzung?.engpass }}
                                </p>
                            </div>
                        </div>

                        <!-- Preis-Markt-Fit -->
                        <div class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                                <Activity class="w-4 h-4" :style="{color: fitColors[vBericht.broker.preis_markt_fit?.bewertung] || '#6b7280'}" />
                                Preis-Markt-Fit
                                <span class="ml-2 text-xs font-bold px-2 py-0.5 rounded-full" :style="{color: fitColors[vBericht.broker.preis_markt_fit?.bewertung] || '#6b7280', background: (fitColors[vBericht.broker.preis_markt_fit?.bewertung] || '#6b7280') + '18'}">
                                    {{ fitLabels[vBericht.broker.preis_markt_fit?.bewertung] || vBericht.broker.preis_markt_fit?.bewertung }}
                                </span>
                            </h4>
                            <p class="text-sm text-[var(--muted-foreground)]">{{ vBericht.broker.preis_markt_fit?.begruendung }}</p>
                        </div>

                        <!-- Nachfragequalität -->
                        <div v-if="vBericht.broker.nachfragequalitaet" class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                                <TrendingUp class="w-4 h-4 text-blue-500" />
                                Nachfragequalität
                            </h4>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div><span class="text-[10px] uppercase tracking-wider text-[var(--muted-foreground)]">Quantität</span><p>{{ vBericht.broker.nachfragequalitaet.quantitaet }}</p></div>
                                <div><span class="text-[10px] uppercase tracking-wider text-[var(--muted-foreground)]">Qualität</span><p>{{ vBericht.broker.nachfragequalitaet.qualitaet }}</p></div>
                                <div><span class="text-[10px] uppercase tracking-wider text-[var(--muted-foreground)]">Reifegrad</span><p>{{ vBericht.broker.nachfragequalitaet.reifegrad }}</p></div>
                                <div><span class="text-[10px] uppercase tracking-wider text-[var(--muted-foreground)]">Progression</span><p>{{ vBericht.broker.nachfragequalitaet.progression }}</p></div>
                            </div>
                        </div>

                        <!-- Feedback-Cluster -->
                        <div v-if="vBericht.broker.feedback_cluster?.length" class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                                <AlertTriangle class="w-4 h-4 text-orange-500" />
                                Feedback-Cluster
                            </h4>
                            <div class="space-y-3">
                                <div v-for="(fc, i) in vBericht.broker.feedback_cluster" :key="i" class="flex items-start gap-3">
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-full mt-0.5 flex-shrink-0" :style="{color: gewichtColors[fc.gewicht] || '#6b7280', background: (gewichtColors[fc.gewicht] || '#6b7280') + '18'}">{{ fc.thema }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-xs font-semibold">{{ fc.anzahl }}×</span>
                                            <span class="text-[10px] text-[var(--muted-foreground)]">{{ fc.gewicht }}</span>
                                        </div>
                                        <p class="text-xs text-[var(--muted-foreground)]">{{ fc.details }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Risiko -->
                        <div v-if="vBericht.broker.risiko" class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                                <Shield class="w-4 h-4 text-red-500" />
                                Risiko-Indikatoren
                            </h4>
                            <div class="space-y-2 text-sm">
                                <div><span class="font-medium text-red-600 dark:text-red-400">Marktalterung:</span> <span class="text-[var(--muted-foreground)]">{{ vBericht.broker.risiko.marktalterung }}</span></div>
                                <div><span class="font-medium text-orange-600 dark:text-orange-400">Imageverlust:</span> <span class="text-[var(--muted-foreground)]">{{ vBericht.broker.risiko.imageverlust }}</span></div>
                                <div><span class="font-medium text-amber-600 dark:text-amber-400">Zeitverlust:</span> <span class="text-[var(--muted-foreground)]">{{ vBericht.broker.risiko.zeitverlust }}</span></div>
                            </div>
                        </div>

                        <!-- Preisargumentation (ausklappbar) -->
                        <div v-if="vBericht.broker.preisargumentation" class="card overflow-hidden">
                            <button @click="toggleSection('preis')" class="w-full px-5 py-3 flex items-center justify-between hover:bg-[var(--bg-muted)] transition-colors">
                                <h4 class="text-sm font-semibold flex items-center gap-2">
                                    <BarChart2 class="w-4 h-4 text-purple-500" />
                                    Preisargumentation
                                </h4>
                                <component :is="expandedSections.preis ? ChevronUp : ChevronDown" class="w-4 h-4 text-[var(--muted-foreground)]" />
                            </button>
                            <div v-if="expandedSections.preis" class="px-5 pb-5 space-y-3 text-sm">
                                <div><span class="font-medium">These:</span> <span class="text-[var(--muted-foreground)]">{{ vBericht.broker.preisargumentation.these }}</span></div>
                                <div v-if="vBericht.broker.preisargumentation.belege?.length">
                                    <span class="font-medium">Belege:</span>
                                    <ul class="mt-1 space-y-0.5"><li v-for="(b,i) in vBericht.broker.preisargumentation.belege" :key="i" class="text-[var(--muted-foreground)] flex items-start gap-1.5"><span class="text-purple-500 mt-0.5">•</span>{{ b }}</li></ul>
                                </div>
                                <div v-if="vBericht.broker.preisargumentation.alternativerklaerungen?.length">
                                    <span class="font-medium">Alternativerklärungen:</span>
                                    <ul class="mt-1 space-y-0.5"><li v-for="(a,i) in vBericht.broker.preisargumentation.alternativerklaerungen" :key="i" class="text-[var(--muted-foreground)] flex items-start gap-1.5"><span class="text-gray-400 mt-0.5">•</span>{{ a }}</li></ul>
                                </div>
                                <div><span class="font-medium">Schlussfolgerung:</span> <span class="text-[var(--muted-foreground)]">{{ vBericht.broker.preisargumentation.schlussfolgerung }}</span></div>
                                <div class="p-3 rounded-lg bg-purple-50 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800">
                                    <span class="font-medium text-purple-700 dark:text-purple-400">Empfehlung:</span> <span class="text-[var(--muted-foreground)]">{{ vBericht.broker.preisargumentation.empfehlung }}</span>
                                </div>
                                <div v-if="vBericht.broker.preisargumentation.eigentuemer_gespraech" class="p-3 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 relative">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <span class="text-[10px] uppercase tracking-wider text-blue-600 dark:text-blue-400 font-semibold">Gesprächsvorlage Eigentümer</span>
                                            <p class="text-sm mt-1 text-[var(--muted-foreground)]">{{ vBericht.broker.preisargumentation.eigentuemer_gespraech }}</p>
                                        </div>
                                        <button @click="copyText(vBericht.broker.preisargumentation.eigentuemer_gespraech, 'eigentuemer')" class="flex-shrink-0 p-1.5 rounded hover:bg-blue-100 dark:hover:bg-blue-900 transition-colors">
                                            <component :is="copiedField === 'eigentuemer' ? Check : Copy" class="w-3.5 h-3.5" :class="copiedField === 'eigentuemer' ? 'text-green-500' : 'text-blue-500'" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Empfehlungslogik -->
                        <div v-if="vBericht.broker.empfehlungslogik?.length" class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                                <Target class="w-4 h-4 text-green-500" />
                                Handlungsempfehlungen
                            </h4>
                            <div class="space-y-4">
                                <div v-for="(e, i) in vBericht.broker.empfehlungslogik" :key="i" class="p-3 rounded-lg bg-[var(--bg-muted)] border border-[var(--border)]">
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <span class="text-sm font-semibold">{{ e.was }}</span>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0" :style="{color: dringColors[e.dringlichkeit] || '#6b7280', background: (dringColors[e.dringlichkeit] || '#6b7280') + '18'}">{{ e.dringlichkeit }}</span>
                                    </div>
                                    <p class="text-xs text-[var(--muted-foreground)] mb-2">{{ e.warum }}</p>
                                    <div v-if="e.signale?.length" class="flex flex-wrap gap-1 mb-2">
                                        <span v-for="(s,si) in e.signale" :key="si" class="text-[10px] px-1.5 py-0.5 rounded bg-blue-50 dark:bg-blue-950/30 text-blue-600 dark:text-blue-400">{{ s }}</span>
                                    </div>
                                    <p v-if="e.erwarteter_effekt" class="text-[10px] text-green-600 dark:text-green-400">↳ {{ e.erwarteter_effekt }}</p>
                                </div>
                            </div>
                        </div>

                    </template>

                    <!-- ═══ EIGENTÜMER-ANSICHT (owner) — immer sichtbar als Referenz ═══ -->
                    <div class="flex items-center gap-3 py-1">
                        <div class="flex-1 h-px bg-[var(--border)]"></div>
                        <span class="text-[10px] font-bold tracking-widest uppercase text-[var(--muted-foreground)]">Eigentümer-Ansicht (Vorschau)</span>
                        <div class="flex-1 h-px bg-[var(--border)]"></div>
                    </div>

                    <template v-if="vBericht.owner">
                        <!-- Kurzfazit -->
                        <div class="card p-5">
                            <div class="space-y-2 text-sm">
                                <p><span class="font-medium">Stand:</span> {{ vBericht.owner.kurzfazit?.stand }}</p>
                                <p><span class="font-medium">Erkenntnis:</span> {{ vBericht.owner.kurzfazit?.erkenntnis }}</p>
                                <p><span class="font-medium">Ausblick:</span> {{ vBericht.owner.kurzfazit?.ausblick }}</p>
                            </div>
                        </div>

                        <!-- Marktaufnahme -->
                        <div v-if="vBericht.owner.marktaufnahme" class="card p-5">
                            <h4 class="text-sm font-semibold mb-2">Marktaufnahme: <span class="text-xs font-bold px-2 py-0.5 rounded-full ml-1" :style="{color: vBericht.owner.marktaufnahme.resonanz==='stark' ? '#16a34a' : vBericht.owner.marktaufnahme.resonanz==='verhalten' ? '#ca8a04' : '#dc2626', background: vBericht.owner.marktaufnahme.resonanz==='stark' ? '#dcfce7' : vBericht.owner.marktaufnahme.resonanz==='verhalten' ? '#fef9c3' : '#fee2e2'}">{{ vBericht.owner.marktaufnahme.resonanz }}</span></h4>
                            <p class="text-sm text-[var(--muted-foreground)]">{{ vBericht.owner.marktaufnahme.text }}</p>
                        </div>

                        <!-- Transaktionsausblick -->
                        <div v-if="vBericht.owner.transaktionsausblick" class="card p-5">
                            <h4 class="text-sm font-semibold mb-3 flex items-center gap-2"><Clock class="w-4 h-4 text-blue-500" /> Transaktionsausblick</h4>
                            <div class="grid grid-cols-3 gap-3">
                                <div v-for="(label, key) in {tage_14: '14 Tage', tage_30: '30 Tage', tage_90: '90 Tage'}"  :key="key" class="text-center p-3 rounded-lg bg-[var(--bg-muted)]">
                                    <p class="text-2xl font-bold font-display" :style="{color: (vBericht.owner.transaktionsausblick[key]?.prozent || 0) > 50 ? '#16a34a' : (vBericht.owner.transaktionsausblick[key]?.prozent || 0) > 20 ? '#ca8a04' : '#dc2626'}">{{ vBericht.owner.transaktionsausblick[key]?.prozent || 0 }}%</p>
                                    <p class="text-[10px] text-[var(--muted-foreground)] mt-0.5">{{ label }}</p>
                                    <p class="text-[10px] text-[var(--muted-foreground)] mt-1">{{ vBericht.owner.transaktionsausblick[key]?.text }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Stärken + Hemmnisse -->
                        <div class="grid grid-cols-2 gap-3">
                            <div v-if="vBericht.owner.staerken?.length" class="card p-5">
                                <h4 class="text-sm font-semibold mb-2 text-green-600">Stärken</h4>
                                <ul class="space-y-1"><li v-for="(s,i) in vBericht.owner.staerken" :key="i" class="text-xs text-[var(--muted-foreground)] flex gap-1.5"><span class="text-green-500">+</span>{{ s }}</li></ul>
                            </div>
                            <div v-if="vBericht.owner.hemmnisse?.length" class="card p-5">
                                <h4 class="text-sm font-semibold mb-2 text-orange-600">Hemmnisse</h4>
                                <ul class="space-y-1"><li v-for="(h,i) in vBericht.owner.hemmnisse" :key="i" class="text-xs text-[var(--muted-foreground)] flex gap-1.5"><span class="text-orange-500">−</span>{{ h }}</li></ul>
                            </div>
                        </div>

                        <!-- Empfohlene Schritte -->
                        <div v-if="vBericht.owner.empfohlene_schritte?.length" class="card p-5">
                            <h4 class="text-sm font-semibold mb-3">Empfohlene Schritte</h4>
                            <div class="space-y-2">
                                <div v-for="(s,i) in vBericht.owner.empfohlene_schritte" :key="i" class="flex gap-3 items-start">
                                    <span class="w-5 h-5 min-w-[20px] rounded-full bg-blue-500 text-white text-[10px] flex items-center justify-center font-bold">{{ s.prioritaet || i+1 }}</span>
                                    <div><p class="text-sm font-medium">{{ s.titel }}</p><p class="text-xs text-[var(--muted-foreground)]">{{ s.text }}</p></div>
                                </div>
                            </div>
                        </div>

                        <!-- Szenarien -->
                        <div v-if="vBericht.owner.szenario_ohne_aktion || vBericht.owner.szenario_mit_aktion" class="grid grid-cols-2 gap-3">
                            <div class="card p-4 border-l-2 border-l-red-400">
                                <p class="text-[10px] font-semibold text-red-500 mb-1">Ohne Aktion</p>
                                <p class="text-xs text-[var(--muted-foreground)]">{{ vBericht.owner.szenario_ohne_aktion }}</p>
                            </div>
                            <div class="card p-4 border-l-2 border-l-green-400">
                                <p class="text-[10px] font-semibold text-green-500 mb-1">Mit Aktion</p>
                                <p class="text-xs text-[var(--muted-foreground)]">{{ vBericht.owner.szenario_mit_aktion }}</p>
                            </div>
                        </div>
                    </template>

                </div>
            </div>
        </div>

        <!-- ═══════════════════════════ KUNDENFEEDBACK ═══════════════════════════ -->
        <template v-if="reportPropertyId">
            <div v-if="feedbackLoading" class="card p-6 text-center">
                <span class="spinner" style="width:18px;height:18px;margin:0 auto"></span>
                <p class="text-xs text-[var(--muted-foreground)] mt-2">Lade Kundenfeedback...</p>
            </div>

            <template v-if="!feedbackLoading && feedbackData">
                <div class="flex items-center gap-3 py-1">
                    <div class="flex-1 h-px bg-[var(--border)]"></div>
                    <span class="text-[10px] font-bold tracking-widest uppercase text-[var(--muted-foreground)]">Kundenfeedback</span>
                    <div class="flex-1 h-px bg-[var(--border)]"></div>
                </div>

                <div class="grid grid-cols-5 gap-3">
                    <div class="stat-tile text-center">
                        <p class="text-2xl font-bold font-display">{{ feedbackData.stats.anfragen }}</p>
                        <p class="text-[10px] text-[var(--muted-foreground)] mt-0.5">Anfragen</p>
                    </div>
                    <div class="stat-tile text-center">
                        <p class="text-2xl font-bold font-display text-sky-600">{{ feedbackData.stats.besichtigungen }}</p>
                        <p class="text-[10px] text-[var(--muted-foreground)] mt-0.5">Besichtigungen</p>
                    </div>
                    <div class="stat-tile text-center">
                        <p class="text-2xl font-bold font-display text-emerald-600">{{ feedbackData.stats.kaufanbote }}</p>
                        <p class="text-[10px] text-[var(--muted-foreground)] mt-0.5">Kaufanbote</p>
                    </div>
                    <div class="stat-tile text-center">
                        <p class="text-2xl font-bold font-display text-red-500">{{ feedbackData.stats.absagen }}</p>
                        <p class="text-[10px] text-[var(--muted-foreground)] mt-0.5">Absagen</p>
                    </div>
                    <div class="stat-tile text-center">
                        <p class="text-2xl font-bold font-display text-amber-500">{{ feedbackData.stats.positiv }}</p>
                        <p class="text-[10px] text-[var(--muted-foreground)] mt-0.5">Positiv-FB</p>
                    </div>
                </div>

                <div v-if="feedbackData.gruende && feedbackData.gruende.length > 0" class="card p-5">
                    <h4 class="text-sm font-semibold mb-4 flex items-center gap-2">
                        <TrendingDown class="w-4 h-4 text-red-400" />
                        Häufigste Absagegründe
                    </h4>
                    <div class="space-y-3">
                        <div v-for="(g, i) in feedbackData.gruende" :key="i" class="flex items-center gap-3">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm">{{ g.grund }}</span>
                                    <span class="text-xs font-semibold text-[var(--muted-foreground)]">{{ g.count }}×</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-[var(--border)] overflow-hidden">
                                    <div class="h-full rounded-full bg-orange-400 transition-all" :style="{ width: absageBarWidth(g.count) }"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="feedbackData.absagen && feedbackData.absagen.length > 0" class="card">
                    <div class="px-5 py-3 border-b border-[var(--border)] flex items-center gap-2">
                        <XCircle class="w-4 h-4 text-red-400" />
                        <h4 class="text-sm font-semibold flex-1">Absagen</h4>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:#fee2e2;color:#991b1b">{{ feedbackData.absagen.length }}</span>
                    </div>
                    <div class="divide-y divide-[var(--border)]">
                        <div v-for="(a, i) in feedbackData.absagen" :key="i" class="px-5 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-semibold">{{ a.stakeholder || '(unbekannt)' }}</span>
                                        <span v-for="(gr, gi) in a.gruende" :key="gi" class="text-[10px] px-1.5 py-0.5 rounded font-medium" style="background:#fee2e2;color:#991b1b">{{ gr }}</span>
                                    </div>
                                    <p v-if="a.summary" class="text-xs text-[var(--muted-foreground)] mt-1 leading-relaxed">{{ a.summary }}</p>
                                </div>
                                <span class="text-[10px] text-[var(--muted-foreground)] whitespace-nowrap flex-shrink-0 mt-0.5">{{ a.activity_date }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="feedbackData.absagen && feedbackData.absagen.length === 0 && feedbackData.stats.anfragen > 0"
                     class="card p-6 text-center text-[var(--muted-foreground)]">
                    <p class="text-sm">Noch keine Absagen erfasst – alles im grünen Bereich!</p>
                </div>

                <div v-if="feedbackData.besichtigungen && feedbackData.besichtigungen.filter(b => b.summary).length > 0" class="card">
                    <div class="px-5 py-3 border-b border-[var(--border)] flex items-center gap-2">
                        <Home class="w-4 h-4 text-sky-400" />
                        <h4 class="text-sm font-semibold">Besichtigungs-Feedback</h4>
                    </div>
                    <div class="divide-y divide-[var(--border)]">
                        <div v-for="(b, i) in feedbackData.besichtigungen.filter(x => x.summary)" :key="i" class="px-5 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">{{ b.stakeholder || '(unbekannt)' }}</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-medium"
                                            :style="b.category === 'feedback_positiv' ? 'background:#dcfce7;color:#166534' : b.category === 'feedback_negativ' ? 'background:#fee2e2;color:#991b1b' : 'background:#e0f2fe;color:#0369a1'">
                                            {{ b.category === 'feedback_positiv' ? 'positiv' : b.category === 'feedback_negativ' ? 'negativ' : 'besichtigung' }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-[var(--muted-foreground)] mt-1 leading-relaxed">{{ b.summary }}</p>
                                </div>
                                <span class="text-[10px] text-[var(--muted-foreground)] whitespace-nowrap flex-shrink-0 mt-0.5">{{ b.activity_date }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="feedbackData.aktive_interessenten && feedbackData.aktive_interessenten.length > 0" class="card p-5">
                    <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">
                        <Users class="w-4 h-4 text-emerald-500" />
                        Aktive Interessenten
                        <span class="ml-auto text-xs font-semibold px-2 py-0.5 rounded-full" style="background:#dcfce7;color:#166534">{{ feedbackData.aktive_interessenten.length }}</span>
                    </h4>
                    <div class="flex flex-wrap gap-2">
                        <div v-for="(p, i) in feedbackData.aktive_interessenten" :key="i" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm bg-[var(--bg-muted)]">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 flex-shrink-0"></span>
                            <span class="font-medium">{{ p.stakeholder }}</span>
                            <span class="text-[10px] text-[var(--muted-foreground)]">{{ p.last_contact }}</span>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>
