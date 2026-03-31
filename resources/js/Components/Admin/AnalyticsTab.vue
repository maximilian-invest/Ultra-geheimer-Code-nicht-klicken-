<script setup>
import { ref, computed, onMounted } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { RefreshCw, TrendingUp, TrendingDown, Minus, AlertTriangle, Lightbulb, Globe, BarChart3, Shield, ChevronDown, ExternalLink } from "lucide-vue-next";

const props = defineProps({ darkMode: { type: Boolean, default: false } });
const API = inject("API");
const toast = inject("toast");

import { inject } from "vue";

const loading = ref(true);
const refreshing = ref(false);
const marketData = ref(null);
const analysis = ref(null);
const lastUpdated = ref(null);
const expandedRegion = ref(null);
const expandedRisk = ref(null);
const showAllNews = ref(false);

onMounted(loadData);

async function loadData() {
    loading.value = true;
    try {
        const r = await fetch(API.value + "&action=market_intelligence");
        const d = await r.json();
        if (d.status === 'ok' && d.data) {
            marketData.value = d.data;
            analysis.value = d.data.ai_analysis?.value || null;
            lastUpdated.value = d.data.ai_analysis?.updated_at || null;
        }
    } catch (e) { toast("Fehler: " + e.message); }
    loading.value = false;
}

async function refreshData() {
    refreshing.value = true;
    toast("Marktdaten werden aktualisiert... (ca. 30-60 Sekunden)");
    try {
        const r = await fetch(API.value + "&action=refresh_market", { method: "POST" });
        const d = await r.json();
        if (d.status === 'ok') {
            toast("Marktdaten aktualisiert!");
            await loadData();
        } else {
            toast("Fehler: " + (d.message || "Unbekannt"));
        }
    } catch (e) { toast("Fehler: " + e.message); }
    refreshing.value = false;
}

// Chart: ECB Rate History
const rateChartOptions = computed(() => {
    const rates = marketData.value?.ecb_rates?.value || [];
    if (!rates.length) return {};
    const isDark = props.darkMode;
    return {
        chart: { type: 'area', height: 260, fontFamily: 'Inter', toolbar: { show: false }, background: 'transparent', zoom: { enabled: false } },
        xaxis: { categories: rates.map(r => r.date), labels: { style: { colors: isDark ? '#94a3b8' : '#64748b', fontSize: '10px' }, rotate: -45, rotateAlways: rates.length > 20 }, tickAmount: 10 },
        yaxis: { labels: { style: { colors: isDark ? '#94a3b8' : '#64748b', fontSize: '11px' }, formatter: v => v.toFixed(2) + '%' }, min: 0 },
        colors: ['#6366f1'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 95] } },
        stroke: { curve: 'smooth', width: 2.5 },
        grid: { borderColor: isDark ? '#334155' : '#f1f5f9', strokeDashArray: 4 },
        dataLabels: { enabled: false },
        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: v => v.toFixed(2) + '%' } },
    };
});
const rateChartSeries = computed(() => {
    const rates = marketData.value?.ecb_rates?.value || [];
    return [{ name: 'EZB Leitzins', data: rates.map(r => r.rate) }];
});

const sentimentColor = computed(() => {
    const s = analysis.value?.sentiment || '';
    if (s.includes('bullish')) return '#10b981';
    if (s.includes('bearish')) return '#ef4444';
    return '#f59e0b';
});
const sentimentBg = computed(() => {
    const s = analysis.value?.sentiment || '';
    if (s.includes('bullish')) return 'rgba(16,185,129,0.08)';
    if (s.includes('bearish')) return 'rgba(239,68,68,0.08)';
    return 'rgba(245,158,11,0.08)';
});

const visibleNews = computed(() => {
    const news = analysis.value?.news_highlights || [];
    return showAllNews.value ? news : news.slice(0, 6);
});

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 3600) return Math.floor(diff / 60) + ' Min.';
    if (diff < 86400) return Math.floor(diff / 3600) + ' Std.';
    return Math.floor(diff / 86400) + ' Tage';
}

function dirIcon(dir) {
    if (dir === 'up') return TrendingUp;
    if (dir === 'down') return TrendingDown;
    return Minus;
}

function dirColor(dir) {
    if (dir === 'up') return '#10b981';
    if (dir === 'down') return '#ef4444';
    return '#94a3b8';
}

function impactColor(impact) {
    if (impact === 'positive') return '#10b981';
    if (impact === 'negative') return '#ef4444';
    return '#6b7280';
}

function impactDot(impact) {
    if (impact === 'positive') return '🟢';
    if (impact === 'negative') return '🔴';
    return '🟡';
}

function severityColor(sev) {
    if (sev === 'high') return '#ef4444';
    if (sev === 'medium') return '#f59e0b';
    return '#6b7280';
}

function trendColor(trend) {
    if (trend === 'steigend') return '#10b981';
    if (trend === 'fallend') return '#ef4444';
    return '#3b82f6';
}

function demandLabel(d) {
    if (d === 'hoch') return 'Hoch';
    if (d === 'mittel') return 'Mittel';
    return 'Gering';
}
</script>

<template>
<div class="px-4 py-6 space-y-5 max-w-[1400px] mx-auto">

    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-bold">Marktanalyse</h2>
            <p v-if="lastUpdated" class="text-xs text-[var(--muted-foreground)] mt-0.5">
                Aktualisiert: {{ new Date(lastUpdated).toLocaleString('de-AT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
            </p>
        </div>
        <button @click="refreshData" :disabled="refreshing"
            class="h-9 px-4 rounded-xl flex items-center gap-2 text-sm font-medium transition-all"
            style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;box-shadow:0 2px 8px rgba(99,102,241,0.25)">
            <RefreshCw class="w-4 h-4" :class="refreshing ? 'animate-spin' : ''" />
            {{ refreshing ? 'Wird aktualisiert...' : 'Aktualisieren' }}
        </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-20">
        <span class="spinner" style="width:24px;height:24px"></span>
        <p class="text-sm text-[var(--muted-foreground)] mt-3">Marktdaten werden geladen...</p>
    </div>

    <!-- Empty State -->
    <div v-else-if="!analysis" class="text-center py-20">
        <BarChart3 class="w-12 h-12 mx-auto mb-3" style="color:var(--muted-foreground)" />
        <p class="text-lg font-semibold mb-1">Keine Marktdaten vorhanden</p>
        <p class="text-sm text-[var(--muted-foreground)] mb-4">Klicke auf "Aktualisieren" um die erste Analyse zu starten.</p>
        <button @click="refreshData" :disabled="refreshing"
            class="h-10 px-6 rounded-xl text-sm font-semibold"
            style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none">
            <RefreshCw class="w-4 h-4 inline mr-1" :class="refreshing ? 'animate-spin' : ''" />
            {{ refreshing ? 'Wird geladen...' : 'Erste Analyse starten' }}
        </button>
    </div>

    <template v-else>

        <!-- ═══ EXECUTIVE SUMMARY ═══ -->
        <div class="rounded-2xl p-5 sm:p-6" :style="{ background: sentimentBg, border: '1px solid ' + sentimentColor + '20' }">
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl font-bold"
                    :style="{ background: sentimentColor + '18', color: sentimentColor }">
                    {{ analysis.sentiment_score || '—' }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        <span class="text-xs font-bold uppercase tracking-wider" :style="{ color: sentimentColor }">
                            {{ analysis.sentiment_label || analysis.sentiment?.replace('_', ' ') || 'Analyse' }}
                        </span>
                    </div>
                    <p class="text-sm leading-relaxed" style="color:var(--foreground)">{{ analysis.executive_summary }}</p>
                </div>
            </div>
        </div>

        <!-- ═══ KEY METRICS ═══ -->
        <div v-if="analysis.key_metrics?.length" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div v-for="(m, i) in analysis.key_metrics" :key="i"
                class="rounded-xl p-3.5 group cursor-default" style="background:var(--card);border:1px solid var(--border)" :title="m.context">
                <div class="text-[10px] font-medium text-[var(--muted-foreground)] uppercase tracking-wide mb-1.5 truncate">{{ m.label }}</div>
                <div class="text-lg font-bold tabular-nums" style="color:var(--foreground)">{{ m.value }}</div>
                <div v-if="m.change" class="flex items-center gap-1 mt-1">
                    <component :is="dirIcon(m.direction)" class="w-3 h-3" :style="{ color: dirColor(m.direction) }" />
                    <span class="text-[11px] font-semibold" :style="{ color: dirColor(m.direction) }">{{ m.change }}</span>
                </div>
            </div>
        </div>

        <!-- ═══ LEITZINS CHART ═══ -->
        <div v-if="rateChartSeries[0]?.data?.length" class="card">
            <div class="px-5 py-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">EZB Leitzins-Verlauf</h3>
                <div v-if="marketData?.ecb_current?.value" class="text-right">
                    <span class="text-lg font-bold" style="color:#6366f1">{{ marketData.ecb_current.value.rate }}%</span>
                    <span class="text-[10px] text-[var(--muted-foreground)] ml-1">aktuell</span>
                </div>
            </div>
            <div class="px-4 pb-4">
                <VueApexCharts type="area" :options="rateChartOptions" :series="rateChartSeries" height="260" />
            </div>
        </div>

        <!-- ═══ ASSET COMPARISON ═══ -->
        <div v-if="analysis.asset_comparison" class="card overflow-hidden">
            <div class="px-5 py-3 border-b" style="border-color:var(--border)">
                <h3 class="text-sm font-semibold">Asset-Klassen Vergleich</h3>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 divide-x divide-y lg:divide-y-0" style="border-color:var(--border)">
                <div v-for="(asset, key) in analysis.asset_comparison" :key="key" class="p-4">
                    <div class="text-xs font-bold uppercase tracking-wide mb-2" :style="{ color: key === 'immobilien' ? '#ee7606' : 'var(--muted-foreground)' }">
                        {{ key === 'immobilien' ? '🏠 Immobilien' : key === 'aktien' ? '📈 Aktien' : key === 'anleihen' ? '📊 Anleihen' : '🪙 Gold' }}
                    </div>
                    <div class="text-lg font-bold mb-1">{{ asset.expected_return }}</div>
                    <div class="text-[11px] text-[var(--muted-foreground)] leading-relaxed">{{ asset.verdict }}</div>
                </div>
            </div>
        </div>

        <!-- ═══ REGIONALE ANALYSE ═══ -->
        <div v-if="analysis.regional?.length" class="card overflow-hidden">
            <div class="px-5 py-3 border-b" style="border-color:var(--border)">
                <h3 class="text-sm font-semibold">Regionale Marktanalyse</h3>
            </div>
            <div class="divide-y" style="border-color:var(--border)">
                <div v-for="(r, i) in analysis.regional" :key="i">
                    <div @click="expandedRegion = expandedRegion === i ? null : i"
                        class="px-5 py-3 flex items-center gap-3 cursor-pointer hover:bg-[var(--accent)] transition-colors">
                        <span class="text-sm font-semibold flex-1">{{ r.region }}</span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full" :style="{ background: trendColor(r.trend) + '15', color: trendColor(r.trend) }">
                            {{ r.trend === 'steigend' ? '↑' : r.trend === 'fallend' ? '↓' : '→' }} {{ r.price_yoy }}
                        </span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded text-[var(--muted-foreground)]" style="background:var(--muted)">
                            Nachfrage: {{ demandLabel(r.demand) }}
                        </span>
                        <ChevronDown class="w-4 h-4 text-[var(--muted-foreground)] transition-transform" :class="expandedRegion === i ? 'rotate-180' : ''" />
                    </div>
                    <div v-if="expandedRegion === i" class="px-5 py-3 text-sm leading-relaxed" style="background:var(--muted);border-top:1px solid var(--border)">
                        {{ r.outlook }}
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ RISIKEN & CHANCEN ═══ -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <!-- Risks -->
            <div v-if="analysis.risk_factors?.length" class="card overflow-hidden">
                <div class="px-5 py-3 border-b flex items-center gap-2" style="border-color:var(--border)">
                    <AlertTriangle class="w-4 h-4" style="color:#ef4444" />
                    <h3 class="text-sm font-semibold">Risikofaktoren</h3>
                </div>
                <div class="divide-y" style="border-color:var(--border)">
                    <div v-for="(risk, i) in analysis.risk_factors" :key="i">
                        <div @click="expandedRisk = expandedRisk === 'r'+i ? null : 'r'+i"
                            class="px-5 py-2.5 flex items-center gap-2 cursor-pointer hover:bg-[var(--accent)] transition-colors">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ background: severityColor(risk.severity) }"></span>
                            <span class="text-sm flex-1">{{ risk.title }}</span>
                            <ChevronDown class="w-3.5 h-3.5 text-[var(--muted-foreground)] transition-transform" :class="expandedRisk === 'r'+i ? 'rotate-180' : ''" />
                        </div>
                        <div v-if="expandedRisk === 'r'+i" class="px-5 py-2.5 text-xs leading-relaxed" style="background:var(--muted);border-top:1px solid var(--border)">
                            <p>{{ risk.description }}</p>
                            <p v-if="risk.transmission" class="mt-1 font-medium" style="color:#ef4444">→ {{ risk.transmission }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Opportunities -->
            <div v-if="analysis.opportunities?.length" class="card overflow-hidden">
                <div class="px-5 py-3 border-b flex items-center gap-2" style="border-color:var(--border)">
                    <Lightbulb class="w-4 h-4" style="color:#10b981" />
                    <h3 class="text-sm font-semibold">Chancen</h3>
                </div>
                <div class="divide-y" style="border-color:var(--border)">
                    <div v-for="(opp, i) in analysis.opportunities" :key="i">
                        <div @click="expandedRisk = expandedRisk === 'o'+i ? null : 'o'+i"
                            class="px-5 py-2.5 flex items-center gap-2 cursor-pointer hover:bg-[var(--accent)] transition-colors">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ background: opp.potential === 'high' ? '#10b981' : opp.potential === 'medium' ? '#3b82f6' : '#94a3b8' }"></span>
                            <span class="text-sm flex-1">{{ opp.title }}</span>
                            <ChevronDown class="w-3.5 h-3.5 text-[var(--muted-foreground)] transition-transform" :class="expandedRisk === 'o'+i ? 'rotate-180' : ''" />
                        </div>
                        <div v-if="expandedRisk === 'o'+i" class="px-5 py-2.5 text-xs leading-relaxed" style="background:var(--muted);border-top:1px solid var(--border)">
                            {{ opp.description }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ NEWS FEED ═══ -->
        <div v-if="analysis.news_highlights?.length" class="card overflow-hidden">
            <div class="px-5 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
                <div class="flex items-center gap-2">
                    <Globe class="w-4 h-4 text-[var(--muted-foreground)]" />
                    <h3 class="text-sm font-semibold">Marktrelevante Nachrichten</h3>
                </div>
                <span class="text-[10px] text-[var(--muted-foreground)]">{{ analysis.news_highlights.length }} Meldungen</span>
            </div>
            <div class="divide-y" style="border-color:var(--border)">
                <div v-for="(news, i) in visibleNews" :key="i" class="px-5 py-3 flex items-start gap-3">
                    <span class="text-sm flex-shrink-0 mt-0.5">{{ impactDot(news.impact) }}</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium leading-snug">{{ news.headline }}</div>
                        <div class="text-xs text-[var(--muted-foreground)] mt-0.5 leading-relaxed">{{ news.summary }}</div>
                    </div>
                    <span class="text-[10px] text-[var(--muted-foreground)] flex-shrink-0 whitespace-nowrap">{{ news.category }}</span>
                </div>
            </div>
            <div v-if="analysis.news_highlights.length > 6" class="px-5 py-2 text-center" style="border-top:1px solid var(--border)">
                <button @click="showAllNews = !showAllNews" class="text-xs font-medium" style="color:#6366f1">
                    {{ showAllNews ? 'Weniger anzeigen' : 'Alle ' + analysis.news_highlights.length + ' Meldungen anzeigen' }}
                </button>
            </div>
        </div>

        <!-- ═══ INVESTMENT OUTLOOK ═══ -->
        <div v-if="analysis.investment_outlook" class="card overflow-hidden">
            <div class="px-5 py-3 border-b flex items-center gap-2" style="border-color:var(--border)">
                <Shield class="w-4 h-4" style="color:#6366f1" />
                <h3 class="text-sm font-semibold">Investment Outlook</h3>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl p-4" style="background:var(--muted)">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-[var(--muted-foreground)] mb-2">Kurzfristig (6M)</div>
                        <p class="text-xs leading-relaxed">{{ analysis.investment_outlook.short_term }}</p>
                    </div>
                    <div class="rounded-xl p-4" style="background:var(--muted)">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-[var(--muted-foreground)] mb-2">Mittelfristig (1-2J)</div>
                        <p class="text-xs leading-relaxed">{{ analysis.investment_outlook.medium_term }}</p>
                    </div>
                    <div class="rounded-xl p-4" style="background:var(--muted)">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-[var(--muted-foreground)] mb-2">Langfristig (5-10J)</div>
                        <p class="text-xs leading-relaxed">{{ analysis.investment_outlook.long_term }}</p>
                    </div>
                </div>
                <div v-if="analysis.investment_outlook.recommendation" class="rounded-xl p-4" :style="{ background: sentimentBg, border: '1px solid ' + sentimentColor + '20' }">
                    <div class="text-[10px] font-bold uppercase tracking-wider mb-2" :style="{ color: sentimentColor }">Empfehlung</div>
                    <p class="text-sm leading-relaxed font-medium">{{ analysis.investment_outlook.recommendation }}</p>
                </div>
                <div v-if="analysis.investment_outlook.geopolitical" class="rounded-xl p-4" style="background:rgba(99,102,241,0.04);border:1px solid rgba(99,102,241,0.12)">
                    <div class="text-[10px] font-bold uppercase tracking-wider mb-2" style="color:#6366f1">Geopolitische Einschätzung</div>
                    <p class="text-xs leading-relaxed">{{ analysis.investment_outlook.geopolitical }}</p>
                </div>
            </div>
        </div>

        <!-- ═══ REGULATION WATCH ═══ -->
        <div v-if="analysis.regulation_watch?.length" class="card overflow-hidden">
            <div class="px-5 py-3 border-b" style="border-color:var(--border)">
                <h3 class="text-sm font-semibold">Regulierung & Gesetzgebung</h3>
            </div>
            <div class="divide-y" style="border-color:var(--border)">
                <div v-for="(reg, i) in analysis.regulation_watch" :key="i" class="px-5 py-3 flex items-start gap-3">
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 mt-0.5"
                        :style="{ background: reg.status === 'in_kraft' ? 'rgba(16,185,129,0.12)' : reg.status === 'geplant' ? 'rgba(245,158,11,0.12)' : 'rgba(107,114,128,0.12)', color: reg.status === 'in_kraft' ? '#10b981' : reg.status === 'geplant' ? '#f59e0b' : '#6b7280' }">
                        {{ reg.status === 'in_kraft' ? 'In Kraft' : reg.status === 'geplant' ? 'Geplant' : 'Diskutiert' }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium">{{ reg.title }}</div>
                        <div class="text-xs text-[var(--muted-foreground)] mt-0.5">{{ reg.impact }}</div>
                    </div>
                </div>
            </div>
        </div>

    </template>
</div>
</template>
