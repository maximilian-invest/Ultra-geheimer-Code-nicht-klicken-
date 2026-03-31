<script setup>
import { Head, Link } from '@inertiajs/vue3'
import PortalLayout from '@/Layouts/PortalLayout.vue'
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
  customer: Object,
  property: Object,
  activities: Array,
  units: { type: Array, default: () => [] },
  parking: { type: Array, default: () => [] },
  messages: Array,
  documents: Array,
  viewings: Array,
  broker: { type: Object, default: () => ({ name: 'Maximilian Hölzl', email: 'hoelzl@sr-homes.at', phone: '+43 664 2600 930', initials: 'MH' }) },
})

// ── Tab management ──
const activeTab = ref('overview')
const refreshing = ref(false)
const showKaufanbote = ref(false)
const expandedUnitId = ref(null)
const tabs = [
  { key: 'overview', label: 'Uebersicht' },
  { key: 'activities', label: 'Aktivitaeten' },
  ...(props.units && props.units.length ? [{ key: 'units', label: 'Einheiten (' + props.units.length + ')' }] : []),
  { key: 'viewings', label: 'Besichtigungen' },
  { key: 'documents', label: 'Dokumente' },
  { key: 'messages', label: 'Nachrichten' },
]

// ── AI Analysis ──
const analysis = ref(null)
const analysisLoading = ref(false)
const analysisError = ref(null)

function refreshData() {
  refreshing.value = true
  window.location.reload()
}

async function loadAnalysis() {
  if (!props.property?.id) return
  analysisLoading.value = true
  analysisError.value = null
  try {
    const res = await axios.get(`/portal/api/analysis/${props.property.id}`)
    analysis.value = res.data
  } catch (e) {
    analysisError.value = 'Analyse konnte nicht geladen werden.'
  } finally {
    analysisLoading.value = false
  }
}

onMounted(() => {
  loadAnalysis()
  // Scroll messages to bottom on mount
  if (props.messages?.length) {
    setTimeout(() => scrollMsgsToBottom(), 100)
  }
})

// ── Overview Stats ──
const systemNames = ['Info', 'Noreply', 'noreply', 'Calendly', 'System', 'admin', 'postmaster', 'mailer-daemon']

const overviewStats = computed(() => {
  const acts = props.activities || []
  const uniquePersons = new Set()
  const uniqueExpose = new Set()
  let viewings = 0
  let kaufanbote = 0
  const _kaufanbotNames = new Set()

  acts.forEach(a => {
    const name = a.canonical_name || a.stakeholder || ''
    if (!name || systemNames.includes(name) || name.length <= 2) return
    if (name.toLowerCase().startsWith('noreply') || name.toLowerCase().startsWith('no-reply')) return

    if (['anfrage', 'email-in', 'besichtigung', 'kaufanbot', 'expose'].includes(a.category)) {
      uniquePersons.add(name)
    }
    if (a.category === 'expose') uniqueExpose.add(name)
    if (a.category === 'besichtigung') viewings++
    if (a.category === 'kaufanbot' && a.kaufanbot_status === 'akzeptiert') { const kn = (a.canonical_name || a.stakeholder||'').toLowerCase().trim(); if (!_kaufanbotNames.has(kn)) { _kaufanbotNames.add(kn); kaufanbote++; } }
  })

  // Use server-provided kaufanbot_count from KaufanbotHelper
  if (props.property?.kaufanbot_count !== undefined) {
    kaufanbote = props.property.kaufanbot_count;
  }

  // Calculate Verkaufsvolumen from units
  const soldUnits = (props.units || []).filter(u => u.status === 'verkauft');
  const verkaufsvolumen = soldUnits.reduce((sum, u) => sum + (parseFloat(u.total_price || u.price) || 0), 0);

  return {
    total: acts.filter(a => !['intern','bounce','update','sonstiges','partner','makler'].includes(a.category)).length,
    interessenten: uniquePersons.size,
    exposes: uniqueExpose.size,
    viewings,
    kaufanbote,
    verkaufsvolumen,
  }
})

// ── Status ──
const statusSteps = ['auftrag', 'inserat', 'anfragen', 'besichtigungen', 'angebote', 'verhandlung', 'verkauft']
const expandedFloors = ref({});
function toggleFloor(key) { expandedFloors.value[key] = !expandedFloors.value[key]; }
const unitsByFloor = computed(() => {
  const groups = {};
  (props.units || []).forEach(u => {
    const f = u.floor ?? 0;
    if (!groups[f]) groups[f] = { key: f, units: [] };
    groups[f].units.push(u);
  });
  // Parking shown separately below units
  return Object.values(groups).sort((a, b) => a.key - b.key);
});

const statusLabels = { auftrag: 'Auftrag', inserat: 'Inserat live', anfragen: 'Anfragen', besichtigungen: 'Besichtigungen', angebote: 'Angebote', verhandlung: 'Verhandlung', verkauft: 'Verkauft' }

function statusLabel(s) {
  return statusLabels[s] || s || 'Auftrag'
}

function statusColor(s) {
  if (s === 'verkauft') return { bg: '#dcfce7', text: '#15803d', dot: '#22c55e' }
  if (s === 'verhandlung' || s === 'angebote') return { bg: '#fef3c7', text: '#b45309', dot: '#f59e0b' }
  return { bg: '#fff7ed', text: '#c2410c', dot: '#ee7606' }
}

// ── Activities Tab ──
const activityFilter = ref('all')
const timeFilter = ref('all')
const activityPage = ref(1)
const activityPerPage = 20
const searchQuery = ref('')
const viewMode = ref('person')
watch([timeFilter, activityFilter, viewMode], () => { activityPage.value = 1 }) // 'person' or 'timeline'

const categoryConfig = {
  nachfassen: { label: 'Follow-up', color: '#10b981', bg: 'rgba(16,185,129,0.08)', icon: '↩' },
  'anfrage': { label: 'Erstanfrage', color: '#7c3aed', bg: 'rgba(124,58,237,0.08)' },
  'email-in': { label: 'Anfrage', color: '#3b82f6', bg: 'rgba(59,130,246,0.08)' },
  'email-out': { label: 'Antwort', color: '#8b5cf6', bg: 'rgba(139,92,246,0.08)' },
  'expose': { label: 'Expose', color: '#f59e0b', bg: 'rgba(245,158,11,0.08)' },
  'besichtigung': { label: 'Besichtigung', color: '#10b981', bg: 'rgba(16,185,129,0.08)' },
  'kaufanbot': { label: 'Kaufanbot', color: '#059669', bg: 'rgba(16,185,129,0.12)' },
  'absage': { label: 'Absage', color: '#ef4444', bg: 'rgba(239,68,68,0.08)' },
  'update': { label: 'Update', color: '#64748b', bg: 'rgba(100,116,139,0.06)' },
  'sonstiges': { label: 'Sonstiges', color: '#64748b', bg: 'rgba(100,116,139,0.06)' },
}

const kaufanbotActivities = computed(() => {
  return (props.activities || []).filter(a => a.category === 'kaufanbot' && a.kaufanbot_status === 'akzeptiert').sort((a, b) => b.activity_date?.localeCompare(a.activity_date))
})
const kaufanbotUnits = computed(() => {
  return (props.units || []).filter(u => u.kaufanbot_pdf).map(u => ({
    id: u.id,
    unit_number: u.unit_number,
    buyer_name: u.buyer_name || 'Unbekannt',
    price: u.total_price || u.price || 0,
    status: u.status,
    kaufanbot_pdf: u.kaufanbot_pdf,
    area_m2: u.area_m2,
  }))
})

const filteredActivities = computed(() => {
  let acts = [...(props.activities || [])]
  if (timeFilter.value !== 'all') {
    const now = new Date()
    let cutoff
    if (timeFilter.value === 'month') {
      cutoff = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0]
    } else if (timeFilter.value === '3months') {
      cutoff = new Date(now.getFullYear(), now.getMonth() - 3, now.getDate()).toISOString().split('T')[0]
    }
    if (cutoff) acts = acts.filter(a => a.activity_date >= cutoff)
  }
  if (activityFilter.value !== 'all') {
    acts = acts.filter(a => a.category === activityFilter.value)
  }
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.trim().toLowerCase()
    acts = acts.filter(a =>
      (a.canonical_name || '').toLowerCase().includes(q) ||
      (a.stakeholder || '').toLowerCase().includes(q) ||
      (a.activity || '').toLowerCase().includes(q) ||
      (a.result || '').toLowerCase().includes(q) ||
      (a.category || '').toLowerCase().includes(q)
    )
  }
  return acts
})

const paginatedActivities = computed(() => {
  return filteredActivities.value.slice(0, activityPage.value * activityPerPage)
})
const hasMoreActs = computed(() => {
  return filteredActivities.value.length > activityPage.value * activityPerPage
})

// ── Timeline-style grouped activities by date ──
const timelineActivities = computed(() => {
  const acts = paginatedActivities.value
  const dateGroups = {}
  acts.forEach(a => {
    const date = a.activity_date || 'Unbekannt'
    if (!dateGroups[date]) dateGroups[date] = []
    dateGroups[date].push(a)
  })
  return Object.entries(dateGroups).sort((a, b) => b[0].localeCompare(a[0]))
})

const personGroups = computed(() => {
  const groups = {}
  paginatedActivities.value.forEach(a => {
    const name = a.canonical_name || a.stakeholder || '(Unbekannt)'
    if (!groups[name]) {
      groups[name] = { activities: [], categories: {}, lastDate: a.activity_date, email: null, phone: null }
    }
    groups[name].activities.push(a)
    groups[name].categories[a.category] = (groups[name].categories[a.category] || 0) + 1
    if (a.activity_date > groups[name].lastDate) groups[name].lastDate = a.activity_date
    // Collect email from stakeholder_email field
    if (!groups[name].email && a.stakeholder_email) {
      const em = a.stakeholder_email.replace(/.*<([^>]+)>.*/, '$1').trim()
      if (em && !em.includes('sr-homes') && !em.includes('hoelzl')) {
        groups[name].email = em
      }
    }
    // Collect phone
    if (!groups[name].phone && a.stakeholder_phone) {
      groups[name].phone = a.stakeholder_phone
    }
  })
  return Object.entries(groups).sort((a, b) => b[1].lastDate.localeCompare(a[1].lastDate))
})

const expandedPersons = ref(new Set())

onMounted(() => {
  personGroups.value.slice(0, 3).forEach(([name]) => {
    expandedPersons.value.add(name)
  })
})

function togglePerson(name) {
  if (expandedPersons.value.has(name)) expandedPersons.value.delete(name)
  else expandedPersons.value.add(name)
}

function highestStage(categories) {
  const order = { kaufanbot: 5, besichtigung: 4, expose: 3, 'email-out': 2, 'email-in': 1, anfrage: 1, absage: 0 }
  let highest = -1, cat = 'sonstiges'
  for (const c in categories) {
    if ((order[c] ?? -1) > highest) { highest = order[c]; cat = c }
  }
  if (categories.absage) cat = 'absage'
  return categoryConfig[cat] || categoryConfig.sonstiges
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const months = ['Jan', 'Feb', 'Maer', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
  return d.getDate() + '. ' + months[d.getMonth()] + ' ' + d.getFullYear()
}

function formatShortDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.'
}

function daysAgoLabel(dateStr) {
  if (!dateStr) return ''
  const diff = Math.floor((new Date() - new Date(dateStr)) / 86400000)
  if (diff === 0) return 'heute'
  if (diff === 1) return 'gestern'
  return 'vor ' + diff + 'd'
}

function isNew(dateStr) {
  if (!dateStr) return false
  const yesterday = new Date()
  yesterday.setDate(yesterday.getDate() - 1)
  return new Date(dateStr) >= yesterday
}

// ── Messages/Chat ──
const localMessages = ref([...(props.messages || [])])
const newMessageText = ref('')
const messageSending = ref(false)
const messageError = ref(null)
const messagesContainer = ref(null)

function scrollMsgsToBottom() {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

async function sendMessage() {
  const text = newMessageText.value.trim()
  if (!text || messageSending.value) return

  messageSending.value = true
  messageError.value = null
  try {
    const res = await axios.post(`/portal/property/${props.property.id}/message`, { message: text })
    if (res.data?.message) {
      localMessages.value.push(res.data.message)
    }
    newMessageText.value = ''
    setTimeout(() => scrollMsgsToBottom(), 100)
  } catch (e) {
    messageError.value = 'Nachricht konnte nicht gesendet werden.'
  } finally {
    messageSending.value = false
  }
}

function handleMsgKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendMessage()
  }
}

function formatMsgTime(dt) {
  if (!dt) return ''
  const d = new Date(dt)
  return d.toLocaleString('de-AT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

// ── Viewings ──
const upcomingViewings = computed(() => {
  const today = new Date().toISOString().split('T')[0]
  return (props.viewings || []).filter(v => v.viewing_date >= today).sort((a, b) => {
    if (a.viewing_date !== b.viewing_date) return a.viewing_date.localeCompare(b.viewing_date)
    return (a.viewing_time || '').localeCompare(b.viewing_time || '')
  })
})

const pastViewings = computed(() => {
  const today = new Date().toISOString().split('T')[0]
  return (props.viewings || []).filter(v => v.viewing_date < today).sort((a, b) => b.viewing_date.localeCompare(a.viewing_date))
})

function viewingStatusLabel(s) {
  const map = { geplant: 'Geplant', bestaetigt: 'Bestaetigt', abgesagt: 'Abgesagt', durchgefuehrt: 'Durchgefuehrt' }
  return map[s] || s
}

function viewingStatusColor(s) {
  if (s === 'bestaetigt') return { bg: '#dcfce7', color: '#15803d' }
  if (s === 'abgesagt') return { bg: '#fee2e2', color: '#b91c1c' }
  if (s === 'durchgefuehrt') return { bg: '#e0f2fe', color: '#0369a1' }
  return { bg: '#fff7ed', color: '#c2410c' }
}

function formatViewingDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('de-AT', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatViewingTime(timeStr) {
  if (!timeStr) return ''
  return timeStr.substring(0, 5) + ' Uhr'
}

// ── Documents ──
function formatFileSize(bytes) {
  if (!bytes) return '0 B'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB'
  if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB'
  return bytes + ' B'
}

function timeAgo(datetime) {
  if (!datetime) return ''
  const diff = Math.floor((new Date() - new Date(datetime)) / 86400000)
  if (diff === 0) return 'Heute'
  if (diff === 1) return 'Gestern'
  if (diff < 7) return 'Vor ' + diff + ' Tagen'
  if (diff < 30) return 'Vor ' + Math.ceil(diff / 7) + ' Wochen'
  return formatDate(datetime)
}

const daysOnMarket = computed(() => {
  if (!props.property?.inserat_since) return null
  return Math.floor((new Date() - new Date(props.property.inserat_since)) / 86400000)
})

const categoryCounts = computed(() => {
  const counts = { all: (props.activities || []).length }
  ;(props.activities || []).forEach(a => {
    counts[a.category] = (counts[a.category] || 0) + 1
  })
  return counts
})

// ── Weekly trend data (last 8 weeks) ──
const weeklyTrend = computed(() => {
  const acts = props.activities || []
  const weeks = []
  const now = new Date()
  for (let i = 7; i >= 0; i--) {
    const weekStart = new Date(now)
    weekStart.setDate(weekStart.getDate() - (i * 7 + weekStart.getDay()))
    const weekEnd = new Date(weekStart)
    weekEnd.setDate(weekEnd.getDate() + 6)
    const count = acts.filter(a => {
      const d = new Date(a.activity_date)
      return d >= weekStart && d <= weekEnd
    }).length
    weeks.push({ label: 'KW' + getWeekNumber(weekStart), count })
  }
  return weeks
})

function getWeekNumber(d) {
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
  const dayNum = date.getUTCDay() || 7
  date.setUTCDate(date.getUTCDate() + 4 - dayNum)
  const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1))
  return Math.ceil(((date - yearStart) / 86400000 + 1) / 7)
}

const maxWeeklyCount = computed(() => Math.max(...weeklyTrend.value.map(w => w.count), 1))

// ── This week summary ──
const thisWeekStats = computed(() => {
  const acts = props.activities || []
  const weekAgo = new Date()
  weekAgo.setDate(weekAgo.getDate() - 7)
  const weekAgoStr = weekAgo.toISOString().split('T')[0]
  const weekActs = acts.filter(a => a.activity_date >= weekAgoStr)
  const counts = {}
  weekActs.forEach(a => {
    counts[a.category] = (counts[a.category] || 0) + 1
  })
  return { total: weekActs.length, counts }
})
</script>

<template>
  <Head :title="property?.address || 'Immobilie'" />
  <PortalLayout :customer="customer">
    <!-- Back + Header -->
    <div class="mb-6">
      <Link :href="route('portal.dashboard')" class="inline-flex items-center gap-1 text-[13px] font-medium mb-4 transition-all hover:underline" style="color: #a8a29e;">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Zurueck
      </Link>

      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-bold tracking-tight" style="color: #1c1917;">{{ property?.address }}</h1>
          <div class="flex items-center gap-2 mt-1 text-xs" style="color: #78716c;">
            <span v-if="property?.type">{{ property.type }}</span>
            <span v-if="property?.type && (property?.zip || property?.city)" class="opacity-40">|</span>
            <span>{{ property?.zip }} {{ property?.city }}</span>
            <template v-if="daysOnMarket !== null">
              <span class="opacity-40">|</span>
              <span>{{ daysOnMarket }} Tage am Markt</span>
            </template>
          </div>
        </div>
        <span
          class="glass-badge inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium flex-shrink-0"
          :style="{
            background: statusColor(property?.status).bg,
            color: statusColor(property?.status).text,
          }"
        >
          <span class="w-1.5 h-1.5 rounded-full" :style="{ background: statusColor(property?.status).dot }"></span>
          {{ statusLabel(property?.status) }}
        </span>
      </div>
    </div>

    <!-- Tab Navigation -->
    <div class="flex items-center gap-1 p-1 rounded-xl mb-6 overflow-x-auto" style="background: rgba(168,162,158,0.08); -ms-overflow-style: none; scrollbar-width: none;">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        @click="activeTab = tab.key"
        class="flex-shrink-0 px-3.5 py-2 rounded-xl text-[13px] font-medium transition-all cursor-pointer border-0"
        :style="{
          background: activeTab === tab.key ? '#ffffff' : 'transparent',
          color: activeTab === tab.key ? '#1c1917' : '#78716c',
          boxShadow: activeTab === tab.key ? '0 1px 3px rgba(120,113,108,0.08)' : 'none',
          transform: activeTab === tab.key ? 'scale(1.02)' : 'scale(1)',
        }"
      >
        {{ tab.label }}
        <span v-if="tab.key === 'messages' && localMessages.filter(m => m.author_role === 'customer').length > 0"
          class="ml-1 inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-bold"
          style="background: #ee7606; color: white;">
          {{ localMessages.filter(m => m.author_role === 'customer').length }}
        </span>
      </button>
    </div>

    <!-- ═══ TAB: UEBERSICHT ═══ -->
    <div v-if="activeTab === 'overview'">
      <!-- Refresh button -->
      <div class="flex justify-end mb-3">
        <button @click="refreshData()" :disabled="refreshing" class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-xl transition-all"
          :style="refreshing ? 'background:rgba(168,162,158,0.08);color:#a8a29e' : 'background:rgba(238,118,6,0.08);color:#ee7606'" >
          <svg :class="refreshing ? 'animate-spin' : ''" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
          {{ refreshing ? 'Aktualisiere...' : 'Aktualisieren' }}
        </button>
      </div>
      <!-- KPI Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <div class="portal-card bg-white rounded-2xl p-4 stagger-item" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04); animation-delay: 0ms;">
          <div class="text-3xl font-bold tracking-tight" style="color: #7c3aed; letter-spacing: -0.01em;">{{ overviewStats.interessenten }}</div>
          <div class="text-[11px] mt-1 font-medium tracking-wide uppercase" style="color: #78716c;">Interessenten</div>
        </div>
        <div class="portal-card bg-white rounded-2xl p-4 stagger-item" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04); animation-delay: 60ms;">
          <div class="text-3xl font-bold tracking-tight" style="color: #f59e0b; letter-spacing: -0.01em;">{{ overviewStats.exposes }}</div>
          <div class="text-[11px] mt-1 font-medium tracking-wide uppercase" style="color: #78716c;">Exposes</div>
        </div>
        <div class="portal-card bg-white rounded-2xl p-4 stagger-item" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04); animation-delay: 120ms;">
          <div class="text-3xl font-bold tracking-tight" style="color: #14b8a6; letter-spacing: -0.01em;">{{ overviewStats.viewings }}</div>
          <div class="text-[11px] mt-1 font-medium tracking-wide uppercase" style="color: #78716c;">Besichtigungen</div>
        </div>
        <div @click="showKaufanbote = true" class="portal-card bg-white rounded-2xl p-4 cursor-pointer stagger-item" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04); animation-delay: 180ms;">
          <div class="text-3xl font-bold tracking-tight" style="color: #ee7606; letter-spacing: -0.01em;">{{ overviewStats.kaufanbote }}</div>
          <div class="text-[11px] mt-1 font-medium tracking-wide uppercase" style="color: #78716c;">Kaufanbote ›</div>
        </div>
      </div>

      <!-- Verkaufsvolumen -->
      <div v-if="overviewStats.verkaufsvolumen > 0" class="portal-card bg-white rounded-2xl p-4 mt-3" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-[11px] font-medium mb-1 tracking-wide uppercase" style="color: #78716c;">Verkaufsvolumen</div>
            <div class="text-xl font-bold tracking-tight" style="color: #10b981; letter-spacing: -0.01em;">€ {{ Number(overviewStats.verkaufsvolumen).toLocaleString('de-AT', {minimumFractionDigits: 0, maximumFractionDigits: 0}) }}</div>
          </div>
          <div class="text-right">
            <div class="text-[11px]" style="color: #78716c;">{{ (units || []).filter(u => u.status === 'verkauft').length }} Einheiten verkauft</div>
          </div>
        </div>
      </div>

      <!-- Kaufanbote Modal -->
      <div v-if="showKaufanbote" class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(28,25,23,0.5);backdrop-filter:blur(4px)" @click.self="showKaufanbote = false">
        <div class="bg-white rounded-2xl w-full max-w-lg mx-4 max-h-[80vh] overflow-hidden" style="box-shadow: 0 25px 60px rgba(120,113,108,0.2);">
          <div class="px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid rgba(168,162,158,0.12)">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-xl flex items-center justify-center" style="background:rgba(16,185,129,0.12)">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              </div>
              <h3 class="font-bold tracking-tight" style="color:#1c1917">Kaufanbote ({{ kaufanbotUnits.length }})</h3>
            </div>
            <button @click="showKaufanbote = false" class="w-8 h-8 rounded-xl flex items-center justify-center hover:bg-gray-100 transition" style="color:#a8a29e">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="overflow-y-auto" style="max-height:calc(80vh - 65px)">
            <div v-if="!kaufanbotUnits.length" class="px-6 py-12 text-center" style="color:#a8a29e">
              <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-3 opacity-50"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              <p class="text-sm">Noch keine Kaufanbote vorhanden.</p>
            </div>
            <div v-else class="divide-y" style="border-color:rgba(168,162,158,0.12)">
              <div v-for="ku in kaufanbotUnits" :key="ku.id" class="px-6 py-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm font-semibold tracking-tight" style="color:#1c1917">{{ ku.buyer_name }}</span>
                  <span class="text-xs px-2 py-0.5 rounded-xl font-medium" :style="ku.status === 'verkauft' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : ku.status === 'reserviert' ? 'background:rgba(245,158,11,0.08);color:#f59e0b' : 'background:rgba(16,185,129,0.08);color:#10b981'">{{ ku.status }}</span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-xs" style="color:#78716c">{{ ku.unit_number }} · {{ ku.area_m2 }} m²</span>
                  <span class="text-sm font-bold tracking-tight" style="color:#ee7606">&euro; {{ Number(ku.price).toLocaleString('de-DE') }}</span>
                </div>
                <a v-if="ku.kaufanbot_pdf" :href="'/storage/' + ku.kaufanbot_pdf" target="_blank" class="inline-flex items-center gap-1.5 mt-2 text-xs font-medium px-3 py-1.5 rounded-xl transition" style="color:#ee7606;background:rgba(238,118,6,0.06)">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  PDF anzeigen
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- AI Analysis -->
      <div class="gradient-top-border bg-white rounded-2xl p-5 mb-5" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
        <div class="flex items-center gap-2.5 mb-4">
          <div class="w-9 h-9 rounded-xl flex items-center justify-center ai-glow" style="background: rgba(168, 85, 247, 0.1);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
          </div>
          <h3 class="font-bold text-sm tracking-tight" style="color: #1c1917;">KI-Analyse</h3>
          <a v-if="analysis && analysis.owner" :href="'/portal/bericht/' + property.id + '/pdf'"
            class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition-all"
            style="background: #ee7606; color: #fff; text-decoration: none;"
            onmouseover="this.style.background='#d16805'" onmouseout="this.style.background='#ee7606'">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            PDF herunterladen
          </a>
          <a v-if="units && units.length > 0" :href="'/portal/bericht/' + property.id + '/bankbericht'" class="ml-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition-all" style="background: #10b981; color: #fff; text-decoration: none;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Bankbericht</a>
        </div>
        <div v-if="analysisLoading" class="flex items-center justify-center py-8">
          <div class="inline-block w-5 h-5 border-2 border-t-transparent rounded-full animate-spin" style="border-color: #d6d3d1; border-top-color: transparent;"></div>
          <span class="text-xs ml-2" style="color: #a8a29e;">Analyse wird erstellt...</span>
        </div>

        <div v-else-if="analysisError" class="text-center py-6">
          <p class="text-xs" style="color: #a8a29e;">{{ analysisError }}</p>
          <button @click="loadAnalysis" class="mt-2 text-xs font-medium px-3 py-1.5 rounded-xl cursor-pointer border-0" style="color: #ee7606; background: rgba(238,118,6,0.06);">Erneut versuchen</button>
        </div>

        <!-- Vermarktungsbericht (Eigentümer-Ebene) -->
        <div v-else-if="analysis && analysis.report_type === 'vermarktungsbericht' && analysis.owner">

          <!-- Status Badge -->
          <div v-if="analysis.owner.status" class="mb-4">
            <span class="glass-badge inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold"
              :style="{
                background: analysis.owner.status === 'green' ? '#f0fdf4' : analysis.owner.status === 'yellow' ? '#fefce8' : analysis.owner.status === 'orange' ? '#fff7ed' : '#fef2f2',
                color: analysis.owner.status === 'green' ? '#15803d' : analysis.owner.status === 'yellow' ? '#a16207' : analysis.owner.status === 'orange' ? '#c2410c' : '#b91c1c',
              }">
              <span class="w-2 h-2 rounded-full" :style="{
                background: analysis.owner.status === 'green' ? '#22c55e' : analysis.owner.status === 'yellow' ? '#eab308' : analysis.owner.status === 'orange' ? '#f97316' : '#ef4444',
              }"></span>
              {{ analysis.owner.status === 'green' ? 'Planmaessig' : analysis.owner.status === 'yellow' ? 'Optimierung moeglich' : analysis.owner.status === 'orange' ? 'Aufmerksamkeit noetig' : 'Handlungsbedarf' }}
            </span>
          </div>

          <!-- Kurzfazit -->
          <div v-if="analysis.owner.kurzfazit" class="space-y-2 mb-5">
            <p class="text-sm leading-relaxed" style="color: #44403c;"><strong>Stand:</strong> {{ analysis.owner.kurzfazit.stand }}</p>
            <p class="text-sm leading-relaxed" style="color: #44403c;"><strong>Erkenntnis:</strong> {{ analysis.owner.kurzfazit.erkenntnis }}</p>
            <p class="text-sm leading-relaxed" style="color: #44403c;"><strong>Ausblick:</strong> {{ analysis.owner.kurzfazit.ausblick }}</p>
          </div>

          <!-- Marktaufnahme -->
          <div v-if="analysis.owner.marktaufnahme" class="mb-5 p-3.5 rounded-xl" style="background: #faf8f5;">
            <div class="flex items-center gap-2 mb-1.5">
              <span class="text-xs font-bold tracking-tight" style="color: #1c1917;">Marktaufnahme</span>
              <span class="text-[10px] font-semibold px-2 py-0.5 rounded-xl"
                :style="{
                  background: analysis.owner.marktaufnahme.resonanz === 'stark' ? '#f0fdf4' : analysis.owner.marktaufnahme.resonanz === 'verhalten' ? '#fefce8' : '#fef2f2',
                  color: analysis.owner.marktaufnahme.resonanz === 'stark' ? '#15803d' : analysis.owner.marktaufnahme.resonanz === 'verhalten' ? '#a16207' : '#b91c1c',
                }">
                {{ analysis.owner.marktaufnahme.resonanz === 'stark' ? 'Starke Resonanz' : analysis.owner.marktaufnahme.resonanz === 'verhalten' ? 'Verhaltene Resonanz' : 'Kritische Resonanz' }}
              </span>
            </div>
            <p class="text-sm" style="color: #44403c;">{{ analysis.owner.marktaufnahme.text }}</p>
          </div>

          <!-- Transaktionsausblick -->
          <div v-if="analysis.owner.transaktionsausblick" class="mb-5">
            <p class="text-xs font-bold tracking-tight mb-2" style="color: #1c1917;">Transaktionsausblick</p>
            <div class="grid grid-cols-3 gap-2">
              <div v-for="(label, key) in {tage_14: '14 Tage', tage_30: '30 Tage', tage_90: '90 Tage'}"  :key="key"
                   class="text-center p-3 rounded-xl" style="background: #faf8f5;">
                <p class="text-xl font-bold tracking-tight"
                   :style="{color: (analysis.owner.transaktionsausblick[key]?.prozent || 0) > 50 ? '#16a34a' : (analysis.owner.transaktionsausblick[key]?.prozent || 0) > 20 ? '#ca8a04' : '#a8a29e', letterSpacing: '-0.01em'}">
                  {{ analysis.owner.transaktionsausblick[key]?.prozent || 0 }}%
                </p>
                <p class="text-[10px] font-medium mt-0.5 tracking-wide uppercase" style="color: #a8a29e;">{{ label }}</p>
              </div>
            </div>
          </div>

          <!-- Stärken + Hemmnisse -->
          <div v-if="(analysis.owner.staerken?.length || analysis.owner.hemmnisse?.length)" class="grid grid-cols-2 gap-3 mb-5">
            <div v-if="analysis.owner.staerken?.length">
              <p class="text-xs font-bold tracking-tight mb-1.5" style="color: #15803d;">Staerken</p>
              <ul class="space-y-1">
                <li v-for="(s,i) in analysis.owner.staerken" :key="i" class="text-xs flex items-start gap-1.5" style="color: #44403c;">
                  <span style="color: #22c55e; margin-top: 2px;">+</span> {{ s }}
                </li>
              </ul>
            </div>
            <div v-if="analysis.owner.hemmnisse?.length">
              <p class="text-xs font-bold tracking-tight mb-1.5" style="color: #c2410c;">Hemmnisse</p>
              <ul class="space-y-1">
                <li v-for="(h,i) in analysis.owner.hemmnisse" :key="i" class="text-xs flex items-start gap-1.5" style="color: #44403c;">
                  <span style="color: #f97316; margin-top: 2px;">-</span> {{ h }}
                </li>
              </ul>
            </div>
          </div>

          <!-- Empfohlene Schritte -->
          <div v-if="analysis.owner.empfohlene_schritte?.length" class="mb-5">
            <p class="text-xs font-bold tracking-tight mb-2" style="color: #1c1917;">Empfohlene naechste Schritte</p>
            <div class="space-y-2">
              <div v-for="(s,i) in analysis.owner.empfohlene_schritte" :key="i" class="flex gap-2 items-start p-2.5 rounded-xl" style="background: #faf8f5;">
                <span class="w-5 h-5 min-w-[20px] rounded-full flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0" style="background: #ee7606;">{{ s.prioritaet || i+1 }}</span>
                <div>
                  <p class="text-xs font-bold tracking-tight" style="color: #1c1917;">{{ s.titel }}</p>
                  <p class="text-xs mt-0.5" style="color: #78716c;">{{ s.text }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Szenarien -->
          <div v-if="analysis.owner.szenario_ohne_aktion || analysis.owner.szenario_mit_aktion" class="grid grid-cols-2 gap-2">
            <div class="p-3 rounded-xl" style="background: #fef2f2; border-left: 3px solid #fca5a5;">
              <p class="text-[10px] font-bold tracking-wide uppercase mb-1" style="color: #b91c1c;">Ohne Aktion</p>
              <p class="text-xs" style="color: #7f1d1d;">{{ analysis.owner.szenario_ohne_aktion }}</p>
            </div>
            <div class="p-3 rounded-xl" style="background: #f0fdf4; border-left: 3px solid #86efac;">
              <p class="text-[10px] font-bold tracking-wide uppercase mb-1" style="color: #15803d;">Mit Aktion</p>
              <p class="text-xs" style="color: #14532d;">{{ analysis.owner.szenario_mit_aktion }}</p>
            </div>
          </div>

        </div>

        <!-- Fallback: bisherige einfache Analyse -->
        <div v-else-if="analysis">
          <div v-if="analysis.status" class="mb-3">
            <span
              class="glass-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold"
              :style="{
                background: analysis.status === 'green' ? '#f0fdf4' : analysis.status === 'yellow' ? '#fefce8' : '#fff7ed',
                color: analysis.status === 'green' ? '#15803d' : analysis.status === 'yellow' ? '#a16207' : '#c2410c',
              }"
            >
              <span class="w-1.5 h-1.5 rounded-full" :style="{
                background: analysis.status === 'green' ? '#22c55e' : analysis.status === 'yellow' ? '#eab308' : '#ee7606',
              }"></span>
              {{ analysis.status === 'green' ? 'Sehr gut' : analysis.status === 'yellow' ? 'Normal' : 'Aufmerksamkeit noetig' }}
            </span>
          </div>

          <p v-if="analysis.summary" class="text-sm leading-relaxed mb-4" style="color: #44403c;">{{ analysis.summary }}</p>

          <div v-if="analysis.highlights && analysis.highlights.length > 0" class="space-y-2 mb-4">
            <div v-for="(h, i) in analysis.highlights" :key="i" class="flex items-start gap-2 text-sm" style="color: #44403c;">
              <span class="mt-0.5 flex-shrink-0 w-1 h-1 rounded-full" style="background: #ee7606; margin-top: 7px;"></span>
              <span>{{ h }}</span>
            </div>
          </div>

          <p v-if="analysis.recommendation" class="text-sm leading-relaxed p-3 rounded-xl" style="color: #44403c; background: #faf8f5;">
            {{ analysis.recommendation }}
          </p>
        </div>

        <div v-else class="text-center py-6">
          <p class="text-xs" style="color: #a8a29e;">Analyse wird vorbereitet...</p>
        </div>
      </div>

      <!-- 8-Week Activity Trend -->
      <div class="portal-card bg-white rounded-2xl p-5 mb-5" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
        <h3 class="text-sm font-bold tracking-tight mb-4 flex items-center gap-2" style="color: #1c1917;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ee7606" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Aktivitaeten-Trend (8 Wochen)
        </h3>
        <div class="flex items-end gap-2" style="height: 120px;">
          <div v-for="(week, idx) in weeklyTrend" :key="idx" class="flex-1 flex flex-col items-center justify-end gap-1">
            <span class="text-[10px] font-bold" style="color: #1c1917;">{{ week.count }}</span>
            <div
              class="w-full rounded-full transition-all duration-500"
              :style="{
                height: (week.count / maxWeeklyCount * 80) + 'px',
                minHeight: week.count > 0 ? '4px' : '2px',
                background: week.count > 0 ? 'linear-gradient(180deg, #ee7606, #f59e0b)' : 'rgba(168,162,158,0.08)',
                opacity: week.count > 0 ? (0.4 + (week.count / maxWeeklyCount) * 0.6) : 0.3,
              }"
            ></div>
            <span class="text-[9px] font-medium" style="color: #a8a29e;">{{ week.label }}</span>
          </div>
        </div>
      </div>

      <!-- This Week Summary -->
      <div class="portal-card bg-white rounded-2xl p-5 mb-5" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
        <h3 class="text-sm font-bold tracking-tight mb-3 flex items-center gap-2" style="color: #1c1917;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ee7606" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Diese Woche
          <span class="text-xs font-bold tracking-tight ml-auto" style="color: #ee7606;">{{ thisWeekStats.total }} Aktivitaeten</span>
        </h3>
        <div v-if="thisWeekStats.total > 0" class="space-y-1.5">
          <div v-for="(count, cat) in thisWeekStats.counts" :key="cat" class="flex items-center justify-between py-1.5 px-3 rounded-xl" style="background: #faf8f5;">
            <span
              class="text-xs font-medium px-2 py-0.5 rounded-xl"
              :style="{ background: (categoryConfig[cat] || categoryConfig.sonstiges).bg, color: (categoryConfig[cat] || categoryConfig.sonstiges).color }"
            >{{ (categoryConfig[cat] || categoryConfig.sonstiges).label }}</span>
            <span class="text-sm font-bold tracking-tight" style="color: #1c1917;">{{ count }}</span>
          </div>
        </div>
        <p v-else class="text-sm text-center py-3" style="color: #a8a29e;">Keine Aktivitaeten diese Woche.</p>
      </div>

      <!-- Contact Card -->
      <div class="portal-card bg-white rounded-2xl p-5" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 rounded-full flex items-center justify-center font-semibold text-xs flex-shrink-0" style="background: rgba(238, 118, 6, 0.08); color: #ee7606;">{{ broker?.initials || 'SR' }}</div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-bold tracking-tight" style="color: #1c1917;">
              {{ broker?.name || 'SR-Homes' }} <span class="font-normal text-xs ml-1" style="color: #a8a29e;">Ihr Ansprechpartner</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 mt-1.5">
              <a v-if="broker?.phone" :href="'tel:' + broker.phone.replace(/\s/g, '')" class="text-xs font-medium flex items-center gap-1 hover:opacity-70 transition-opacity" style="color: #ee7606;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                {{ broker.phone }}
              </a>
              <a v-if="broker?.email" :href="'mailto:' + broker.email" class="text-xs font-medium flex items-center gap-1 hover:opacity-70 transition-opacity" style="color: #ee7606;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                {{ broker.email }}
              </a>
              <span class="text-xs" style="color: #a8a29e;">Mo-Fr 8-18 Uhr</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ TAB: AKTIVITAETEN ═══ -->
    <div v-if="activeTab === 'activities'">
      <!-- Summary bar -->
      <div class="grid grid-cols-4 gap-2 mb-4">
        <div v-for="(cfg, cat) in { anfrage: categoryConfig.anfrage, besichtigung: categoryConfig.besichtigung, kaufanbot: categoryConfig.kaufanbot, absage: categoryConfig.absage }" :key="cat"
          class="rounded-xl p-3 text-center" :style="{ background: cfg.bg }">
          <div class="text-xl font-bold tracking-tight" :style="{ color: cfg.color, letterSpacing: '-0.01em' }">{{ categoryCounts[cat] || 0 }}</div>
          <div class="text-[10px] mt-0.5 font-medium tracking-wide uppercase" :style="{ color: cfg.color, opacity: 0.8 }">{{ cfg.label }}</div>
        </div>
      </div>

      <!-- Filter row: Time + Category + Search + View toggle -->
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="flex items-center gap-1 p-0.5 rounded-xl" style="background: rgba(168,162,158,0.08);">
          <button v-for="tf in [{k:'all',l:'Alle'},{k:'month',l:'Monat'},{k:'3months',l:'3 Mon.'}]" :key="tf.k"
            @click="timeFilter = tf.k"
            class="px-3 py-1.5 rounded-xl text-xs font-medium transition-all cursor-pointer border-0"
            :style="{
              background: timeFilter === tf.k ? '#ffffff' : 'transparent',
              color: timeFilter === tf.k ? '#1c1917' : '#78716c',
              boxShadow: timeFilter === tf.k ? '0 1px 3px rgba(120,113,108,0.08)' : 'none',
            }"
          >{{ tf.l }}</button>
        </div>
        <select v-model="activityFilter" class="text-xs px-3 py-1.5 rounded-xl border appearance-none cursor-pointer" style="border-color: rgba(168,162,158,0.12); color: #1c1917; background: white;">
          <option value="all">Alle Kategorien ({{ categoryCounts.all || 0 }})</option>
          <option v-for="(cfg, key) in categoryConfig" :key="key" :value="key" v-show="categoryCounts[key]">
            {{ cfg.label }} ({{ categoryCounts[key] || 0 }})
          </option>
        </select>
        <!-- View mode toggle -->
        <div class="flex items-center gap-1 p-0.5 rounded-xl ml-auto" style="background: rgba(168,162,158,0.08);">
          <button @click="viewMode = 'person'"
            class="px-2.5 py-1.5 rounded-xl text-xs font-medium transition-all cursor-pointer border-0"
            :style="{
              background: viewMode === 'person' ? '#ffffff' : 'transparent',
              color: viewMode === 'person' ? '#1c1917' : '#78716c',
              boxShadow: viewMode === 'person' ? '0 1px 3px rgba(120,113,108,0.08)' : 'none',
            }">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </button>
          <button @click="viewMode = 'timeline'"
            class="px-2.5 py-1.5 rounded-xl text-xs font-medium transition-all cursor-pointer border-0"
            :style="{
              background: viewMode === 'timeline' ? '#ffffff' : 'transparent',
              color: viewMode === 'timeline' ? '#1c1917' : '#78716c',
              boxShadow: viewMode === 'timeline' ? '0 1px 3px rgba(120,113,108,0.08)' : 'none',
            }">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          </button>
        </div>
      </div>

      <!-- Search bar -->
      <div class="relative mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input v-model="searchQuery" type="text" placeholder="Suche nach Person, Aktivitaet, Kategorie..."
          class="w-full text-sm pl-10 pr-10 py-2.5 rounded-xl border transition-all"
          :style="{ borderColor: searchQuery ? '#ee7606' : 'rgba(168,162,158,0.12)', background: '#fff', color: '#1c1917', outline: 'none' }"
          @focus="$event.target.style.borderColor='#ee7606'; $event.target.style.boxShadow='0 0 0 3px rgba(238,118,6,0.1)'"
          @blur="$event.target.style.borderColor = searchQuery ? '#ee7606' : 'rgba(168,162,158,0.12)'; $event.target.style.boxShadow='none'"
        />
        <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-3 top-1/2 -translate-y-1/2 border-0 bg-transparent cursor-pointer p-0" style="color:#a8a29e;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Search result count -->
      <div v-if="searchQuery.trim()" class="mb-3 text-xs" style="color: #78716c;">
        {{ filteredActivities.length }} Ergebnis{{ filteredActivities.length !== 1 ? 'se' : '' }} fuer &laquo;{{ searchQuery }}&raquo;
      </div>

      <!-- ═══ VIEW: PERSON GROUPS (default) ═══ -->
      <div v-if="viewMode === 'person'" class="space-y-3">
        <div v-for="([name, data], pidx) in personGroups" :key="name"
          class="bg-white rounded-2xl overflow-hidden stagger-item" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);" :style="{ animationDelay: (pidx * 40) + 'ms' }">
          <!-- Person header (clickable) -->
          <button @click="togglePerson(name)"
            class="w-full px-4 py-3 flex items-center gap-3 cursor-pointer transition-colors border-0 bg-transparent person-header"
            style="border-bottom: 1px solid rgba(168,162,158,0.08);">
            <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold avatar-ring"
              style="background: #fff7ed; color: #ee7606;">
              {{ (name || '?')[0].toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0 text-left">
              <div class="flex items-center gap-2">
                <span class="text-sm font-bold tracking-tight truncate" style="color: #1c1917;">{{ name }}</span>
                <span v-if="data.email" class="text-[10px] truncate hidden sm:inline" style="color: #a8a29e;">{{ data.email }}</span>
                <span v-if="data.phone" class="text-[10px] truncate hidden sm:inline" style="color: #a8a29e;">{{ data.phone }}</span>
                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-xl"
                  :style="{ background: highestStage(data.categories).bg, color: highestStage(data.categories).color }">
                  {{ highestStage(data.categories).label }}
                </span>
              </div>
              <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <span class="text-[11px]" style="color: #a8a29e;">{{ data.activities.length }} Aktivitaet{{ data.activities.length > 1 ? 'en' : '' }}</span>
                <span class="text-[11px]" style="color: #a8a29e;">&middot;</span>
                <span class="text-[11px]" :style="{ color: daysAgoLabel(data.lastDate) === 'heute' || daysAgoLabel(data.lastDate) === 'gestern' ? '#ee7606' : '#a8a29e' }">
                  {{ daysAgoLabel(data.lastDate) }}
                </span>
                <template v-for="(cnt, cat) in data.categories" :key="cat">
                  <span class="text-[9px] font-bold px-1 py-0 rounded-xl hidden sm:inline"
                    :style="{ background: (categoryConfig[cat] || categoryConfig.sonstiges).bg, color: (categoryConfig[cat] || categoryConfig.sonstiges).color }">
                    {{ cnt }}&times; {{ (categoryConfig[cat] || categoryConfig.sonstiges).label }}
                  </span>
                </template>
              </div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="flex-shrink-0 transition-transform" :style="{ transform: expandedPersons.has(name) ? 'rotate(180deg)' : 'rotate(0)' }">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>
          <!-- Person activities (collapsible) -->
          <div v-if="expandedPersons.has(name)">
            <div v-for="(act, aidx) in data.activities" :key="act.id || aidx"
              class="px-4 py-2.5 flex items-start gap-3 transition-colors activity-row"
              :style="{ borderBottom: '1px solid rgba(168,162,158,0.06)', background: isNew(act.activity_date) ? 'rgba(238,118,6,0.03)' : aidx % 2 === 0 ? 'transparent' : 'rgba(168,162,158,0.02)' }">
              <div class="w-6 h-6 rounded-xl flex items-center justify-center flex-shrink-0 mt-0.5"
                :style="{ background: (categoryConfig[act.category] || categoryConfig.sonstiges).bg }">
                <svg v-if="act.category === 'besichtigung'" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.besichtigung.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <svg v-else-if="act.category === 'kaufanbot'" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.kaufanbot.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <svg v-else-if="act.category === 'expose'" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.expose.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <svg v-else-if="act.category === 'absage'" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.absage.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span v-else class="w-2 h-2 rounded-full" :style="{ background: (categoryConfig[act.category] || categoryConfig.sonstiges).color }"></span>
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-xs font-semibold" :style="{ color: (categoryConfig[act.category] || categoryConfig.sonstiges).color }">
                    {{ (categoryConfig[act.category] || categoryConfig.sonstiges).label }}
                  </span>
                  <span class="text-[10px]" style="color: #a8a29e;">{{ formatShortDate(act.activity_date) }}<template v-if="act.activity_time">, {{ act.activity_time }}</template></span>
                  <span v-if="isNew(act.activity_date)" class="text-[9px] font-bold px-1 py-0 rounded-xl" style="background: rgba(238,118,6,0.12); color: #ee7606;">Neu</span>
                </div>
                <p class="text-xs mt-0.5 leading-relaxed" style="color: #44403c;">{{ act.activity }}</p>
                <p v-if="act.result" class="text-[10px] mt-0.5"
                  :style="{ color: act.category === 'absage' ? '#ef4444' : '#a8a29e', fontStyle: 'italic' }">
                  {{ act.category === 'absage' ? 'Grund: ' : '' }}{{ act.result }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <div v-if="personGroups.length === 0" class="bg-white rounded-2xl p-12 text-center" style="border: 1px solid rgba(168,162,158,0.12);">
          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d6d3d1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-3"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <p class="text-sm" style="color: #a8a29e;">{{ searchQuery ? 'Keine Ergebnisse fuer diese Suche.' : 'Keine Aktivitaeten im gewaehlten Zeitraum.' }}</p>
        </div>
      </div>

      <!-- ═══ VIEW: TIMELINE (date-grouped) ═══ -->
      <div v-if="viewMode === 'timeline'" class="space-y-6 timeline-view">
        <div v-for="([date, acts], didx) in timelineActivities" :key="date">
          <div class="flex items-center gap-3 mb-3">
            <div class="text-xs font-bold tracking-tight px-3 py-1.5 rounded-xl" style="background: rgba(168,162,158,0.08); color: #78716c;">
              {{ formatDate(date) }}
            </div>
            <div class="flex-1 h-px" style="background: rgba(168,162,158,0.12);"></div>
            <span class="text-[10px] font-medium" style="color: #a8a29e;">{{ acts.length }} Eintr{{ acts.length === 1 ? 'ag' : 'aege' }}</span>
          </div>
          <div class="space-y-2 relative timeline-entries">
            <div v-for="(act, aidx) in acts" :key="act.id || aidx"
              class="bg-white rounded-2xl p-4 flex items-start gap-3 timeline-entry"
              :style="{ border: '1px solid ' + (isNew(act.activity_date) ? '#fdba74' : 'rgba(168,162,158,0.12)'), boxShadow: '0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04)' }">
              <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
                :style="{ background: (categoryConfig[act.category] || categoryConfig.sonstiges).bg }">
                <svg v-if="act.category === 'besichtigung'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.besichtigung.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <svg v-else-if="act.category === 'kaufanbot'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.kaufanbot.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <svg v-else-if="act.category === 'expose'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.expose.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <svg v-else-if="act.category === 'absage'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" :stroke="categoryConfig.absage.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span v-else class="w-2 h-2 rounded-full" :style="{ background: (categoryConfig[act.category] || categoryConfig.sonstiges).color }"></span>
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-xs font-semibold px-2 py-0.5 rounded-xl"
                    :style="{ background: (categoryConfig[act.category] || categoryConfig.sonstiges).bg, color: (categoryConfig[act.category] || categoryConfig.sonstiges).color }">
                    {{ (categoryConfig[act.category] || categoryConfig.sonstiges).label }}
                  </span>
                  <span class="text-xs" style="color: #a8a29e;">{{ act.canonical_name || act.stakeholder }}</span>
                   <span v-if="act.stakeholder_email" class="text-[10px] hidden sm:inline" style="color: #a8a29e;">{{ act.stakeholder_email.replace(/.*<([^>]+)>.*/, '$1') }}</span>
                   <span v-if="act.activity_time" class="text-[10px]" style="color: #a8a29e;">{{ act.activity_time }}</span>
                  <span v-if="isNew(act.activity_date)" class="text-[9px] font-bold px-1.5 py-0.5 rounded-xl" style="background: rgba(238,118,6,0.12); color: #ee7606;">Neu</span>
                </div>
                <p class="text-sm mt-1.5 leading-relaxed" style="color: #44403c;">{{ act.activity }}</p>
                <p v-if="act.result" class="text-xs mt-1"
                  :style="{ color: act.category === 'absage' ? '#ef4444' : '#a8a29e', fontStyle: 'italic' }">
                  {{ act.result }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="viewMode === 'timeline' && timelineActivities.length === 0" class="bg-white rounded-2xl p-12 text-center" style="border: 1px solid rgba(168,162,158,0.12);">
        <p class="text-sm" style="color: #a8a29e;">{{ searchQuery ? 'Keine Ergebnisse fuer diese Suche.' : 'Keine Aktivitaeten im gewaehlten Zeitraum.' }}</p>
      </div>

      <!-- Load More Button -->
      <div v-if="hasMoreActs" class="text-center py-6">
        <button @click="activityPage++" class="px-8 py-3 text-sm font-semibold rounded-2xl transition-all hover:shadow-md" style="background:linear-gradient(135deg,#ee7606,#f59e0b);color:white">
          Mehr laden · {{ filteredActivities.length - activityPage * activityPerPage }} weitere
        </button>
      </div>

    </div>

    <!-- ═══ TAB: EINHEITEN ═══ -->
    <div v-if="activeTab === 'units'">
      <div v-if="!units || !units.length" class="bg-white rounded-2xl p-8 text-center" style="border: 1px solid rgba(168,162,158,0.12)">
        <p class="text-sm" style="color:#a8a29e">Keine Einheiten vorhanden.</p>
      </div>
      <div v-else>
        <!-- Summary -->
        <div class="grid grid-cols-4 gap-2 mb-4">
          <div class="portal-card bg-white rounded-2xl p-3 text-center" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
            <div class="text-xl font-bold tracking-tight" style="color:#1c1917; letter-spacing: -0.01em;">{{ units.length }}</div>
            <div class="text-[10px] font-medium tracking-wide uppercase" style="color:#a8a29e">Gesamt</div>
          </div>
          <div class="portal-card bg-white rounded-2xl p-3 text-center" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
            <div class="text-xl font-bold tracking-tight" style="color:#10b981; letter-spacing: -0.01em;">{{ units.filter(u => u.status === 'frei').length }}</div>
            <div class="text-[10px] font-medium tracking-wide uppercase" style="color:#a8a29e">Frei</div>
          </div>
          <div class="portal-card bg-white rounded-2xl p-3 text-center" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
            <div class="text-xl font-bold tracking-tight" style="color:#f59e0b; letter-spacing: -0.01em;">{{ units.filter(u => u.status === 'reserviert').length }}</div>
            <div class="text-[10px] font-medium tracking-wide uppercase" style="color:#a8a29e">Reserviert</div>
          </div>
          <div class="portal-card bg-white rounded-2xl p-3 text-center" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
            <div class="text-xl font-bold tracking-tight" style="color:#ef4444; letter-spacing: -0.01em;">{{ units.filter(u => u.status === 'verkauft').length }}</div>
            <div class="text-[10px] font-medium tracking-wide uppercase" style="color:#a8a29e">Verkauft</div>
          </div>
        </div>

        <!-- Progress Bar -->
        <div class="portal-card bg-white rounded-2xl p-4 mb-4" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold tracking-tight" style="color:#1c1917">Verkaufsfortschritt</span>
            <span class="text-xs font-bold tracking-tight" style="color:#ee7606">{{ units.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) > 0 ? Math.round((units.filter(u => u.status === 'verkauft').reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) / units.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0)) * 100) : Math.round((units.filter(u => u.status === 'verkauft').length / units.length) * 100) }}% verkauft</span>
          </div>
          <div class="w-full h-4 rounded-full overflow-hidden progress-glow" style="background:#f0ebe7">
            <div class="h-full rounded-full transition-all" :style="'width:' + (units.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) > 0 ? (units.filter(u => u.status === 'verkauft').reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) / units.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) * 100) : (units.filter(u => u.status === 'verkauft').length / units.length * 100)) + '%;background:linear-gradient(90deg,#10b981,#ee7606)'"></div>
          </div>
          <div class="flex items-center justify-between mt-2">
            <span class="text-[11px]" style="color:#a8a29e">{{ units.filter(u => u.status === 'verkauft').length }} von {{ units.length }} Einheiten verkauft</span>
            <span class="text-[11px] font-semibold" style="color:#a8a29e" v-if="units.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0) > 0">{{ units.filter(u => u.status === 'verkauft').reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0).toFixed(0) }} / {{ units.reduce((s,u) => s + (parseFloat(u.area_m2)||0), 0).toFixed(0) }} m²</span>
            
          </div>
        </div>

        <!-- Units by Floor -->
        <div v-for="floor in unitsByFloor" :key="floor.key" class="mb-4">
          <div @click="toggleFloor(floor.key)" class="flex items-center gap-2 mb-2 cursor-pointer group">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="flex-shrink-0 transition-transform" :style="expandedFloors[floor.key] === false ? '' : 'transform:rotate(90deg)'"><polyline points="9 18 15 12 9 6"/></svg>
            <span class="text-[11px] font-bold uppercase tracking-wider" style="color:#78716c">
              {{ String(floor.key) === '-1' ? 'Tiefgarage / Stellplätze' : floor.key == 0 ? 'Erdgeschoss' : floor.key == 1 ? '1. Obergeschoss' : floor.key == 2 ? '2. Obergeschoss' : floor.key == 3 ? 'Dachgeschoss' : floor.key + '. OG' }}
            </span>
            <span class="text-[10px] px-1.5 py-0.5 rounded-xl font-medium" style="background:rgba(168,162,158,0.08);color:#78716c">{{ floor.units.length }}</span>
            <div class="flex-1" style="border-bottom:1px solid rgba(168,162,158,0.08)"></div>
            <span class="text-[10px] font-medium" style="color:#a8a29e">{{ floor.units.filter(u => u.status === 'verkauft').length }}/{{ floor.units.length }}</span>
          </div>
          <div v-if="expandedFloors[floor.key] !== false" class="bg-white rounded-2xl overflow-hidden" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
            <div v-for="(u, idx) in floor.units" :key="u.id">
              <div @click="u.kaufanbot ? expandedUnitId = expandedUnitId === u.id ? null : u.id : null"
                class="flex items-center gap-3 px-4 py-3 transition-colors unit-row"
                :class="u.kaufanbot ? 'cursor-pointer hover:bg-gray-50' : ''"
                :style="(idx < floor.units.length - 1 && expandedUnitId !== u.id) ? 'border-bottom:1px solid rgba(168,162,158,0.06)' : ''">
                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                  :style="u.status === 'verkauft' ? 'background:#ef4444' : u.status === 'reserviert' ? 'background:#f59e0b' : 'background:#10b981'"></div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="text-sm font-bold tracking-tight" style="color:#1c1917">{{ u.unit_number }}</span>
                    <span v-if="u.unit_type" class="text-xs" style="color:#a8a29e">{{ u.unit_type }}</span>
                  </div>
                  <div v-if="u.buyer_name" class="text-[11px] mt-0.5" style="color:#8b5cf6">{{ u.buyer_name }}</div>
                </div>
                <div class="text-right flex-shrink-0">
                  <div class="text-xs tabular-nums" style="color:#a8a29e">{{ u.rooms_amount ? u.rooms_amount + ' Zi' : '' }} {{ u.area_m2 ? '· ' + u.area_m2 + ' m²' : '' }}</div>
                  <div class="text-sm font-bold tracking-tight tabular-nums" style="color:#1c1917" v-if="u.total_price || u.price">€ {{ Number(u.total_price || u.price).toLocaleString('de-AT') }}</div>
                </div>
                <span class="text-[10px] px-2 py-1 rounded-xl font-medium flex-shrink-0"
                  :style="u.status === 'verkauft' ? 'background:rgba(239,68,68,0.08);color:#ef4444' : u.status === 'reserviert' ? 'background:rgba(245,158,11,0.08);color:#f59e0b' : 'background:rgba(16,185,129,0.08);color:#10b981'">
                  {{ u.status }}
                </span>
                <svg v-if="u.kaufanbot" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  :style="expandedUnitId === u.id ? 'transform:rotate(180deg)' : ''" class="flex-shrink-0 transition-transform">
                  <polyline points="6 9 12 15 18 9"/></svg>
              </div>
              <!-- Kaufanbot Detail Panel -->
              <div v-if="expandedUnitId === u.id && u.kaufanbot" class="px-4 py-3" style="background:#faf5ff;border-top:1px solid #e9d5ff;border-bottom:1px solid rgba(168,162,158,0.06)">
                <div class="flex items-start gap-3">
                  <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(139,92,246,0.1)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs font-bold tracking-tight" style="color:#7c3aed">Kaufanbot</div>
                    <div class="text-[13px] font-bold tracking-tight mt-0.5" style="color:#1c1917">{{ u.kaufanbot.stakeholder }}</div>
                    <div class="text-xs mt-1" style="color:#78716c">{{ u.kaufanbot.activity }}</div>
                    <div class="text-[11px] mt-1 tabular-nums" style="color:#a8a29e">{{ new Date(u.kaufanbot.date).toLocaleDateString('de-AT', { day: '2-digit', month: 'long', year: 'numeric' }) }}</div>
                  </div>
                  <span class="text-[10px] px-2 py-1 rounded-xl font-bold flex-shrink-0" style="background:rgba(16,185,129,0.08);color:#10b981">Akzeptiert</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    
      <!-- Stellplätze -->
      <div v-if="parking && parking.length" class="mt-6">
        <div class="flex items-center justify-between mb-3 px-1">
          <h3 class="text-sm font-bold tracking-tight" style="color:#1c1917">Stellplätze</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-xl font-medium" style="background:rgba(168,162,158,0.08);color:#78716c">{{ parking.length }}</span>
        </div>
        <div class="bg-white rounded-2xl overflow-hidden" style="border: 1px solid rgba(168,162,158,0.12)">
          <div v-for="(p, idx) in parking" :key="p.id"
            class="flex items-center gap-3 px-4 py-3"
            :style="idx < parking.length - 1 ? 'border-bottom:1px solid rgba(168,162,158,0.06)' : ''">
            <div class="flex-1 min-w-0">
              <span class="text-sm font-bold tracking-tight" style="color:#1c1917">{{ p.unit_number }}</span>
              <span class="text-xs ml-2" style="color:#78716c">{{ p.unit_type }}</span>
              <span v-if="p.purchase_price" class="text-xs ml-2" style="color:#78716c">&euro; {{ Number(p.purchase_price).toLocaleString('de-DE') }}</span>
            </div>
            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium"
              :style="p.status === 'frei' ? 'background:rgba(16,185,129,0.08);color:#10b981' : p.status === 'reserviert' ? 'background:rgba(245,158,11,0.08);color:#f59e0b' : 'background:rgba(239,68,68,0.08);color:#ef4444'">
              {{ p.status }}
            </span>
          </div>
        </div>
      </div>
</div>

    <!-- ═══ TAB: BESICHTIGUNGEN ═══ -->
    <div v-if="activeTab === 'viewings'">
      <!-- Upcoming -->
      <div v-if="upcomingViewings.length > 0" class="mb-6">
        <h3 class="text-sm font-bold tracking-tight mb-3" style="color: #1c1917;">Geplante Besichtigungen</h3>
        <div class="space-y-3">
          <div v-for="v in upcomingViewings" :key="v.id"
            class="portal-card bg-white rounded-2xl p-4 flex items-start gap-4"
            style="border: 1px solid rgba(168,162,158,0.12); border-left: 3px solid #ee7606; box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
            <!-- Date block -->
            <div class="flex-shrink-0 w-16 h-16 rounded-xl flex flex-col items-center justify-center text-center" style="background: rgba(238,118,6,0.06); box-shadow: 0 1px 3px rgba(120,113,108,0.06);">
              <span class="text-2xl font-bold leading-none tracking-tight" style="color: #ee7606;">{{ new Date(v.viewing_date).getDate() }}</span>
              <span class="text-[10px] font-medium mt-0.5 tracking-wide uppercase" style="color: #a8a29e;">
                {{ ['Jan','Feb','Maer','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'][new Date(v.viewing_date).getMonth()] }}
              </span>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap mb-1">
                <span class="text-sm font-medium" style="color: #1c1917;">{{ formatViewingDate(v.viewing_date) }}</span>
                <span v-if="v.viewing_time" class="text-xs font-bold tracking-tight" style="color: #ee7606;">{{ formatViewingTime(v.viewing_time) }}</span>
                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-xl"
                  :style="viewingStatusColor(v.status)">
                  {{ viewingStatusLabel(v.status) }}
                </span>
              </div>
              <p v-if="v.notes" class="text-xs" style="color: #78716c;">{{ v.notes }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Past viewings -->
      <div v-if="pastViewings.length > 0">
        <h3 class="text-sm font-bold tracking-tight mb-3" style="color: #78716c;">Vergangene Besichtigungen</h3>
        <div class="space-y-2">
          <div v-for="v in pastViewings" :key="v.id"
            class="bg-white rounded-2xl p-3.5 flex items-center gap-3 opacity-70"
            style="border: 1px solid rgba(168,162,158,0.12); border-left: 3px solid #d6d3d1;">
            <div class="flex-1">
              <span class="text-sm" style="color: #44403c;">{{ formatViewingDate(v.viewing_date) }}</span>
              <span v-if="v.viewing_time" class="text-xs ml-2" style="color: #a8a29e;">{{ formatViewingTime(v.viewing_time) }}</span>
            </div>
            <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-xl"
              :style="viewingStatusColor(v.status)">
              {{ viewingStatusLabel(v.status) }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="(!viewings || viewings.length === 0)" class="bg-white rounded-2xl p-12 text-center" style="border: 1px solid rgba(168,162,158,0.12);">
        <div class="w-14 h-14 mx-auto mb-3 rounded-xl flex items-center justify-center" style="background: #faf8f5;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <p class="text-sm" style="color: #78716c;">Noch keine Besichtigungen geplant.</p>
        <p class="text-xs mt-1" style="color: #a8a29e;">Besichtigungstermine werden hier angezeigt sobald sie vereinbart sind.</p>
      </div>
    </div>

    <!-- ═══ TAB: DOKUMENTE ═══ -->
    <div v-if="activeTab === 'documents'">

      <div v-if="documents && documents.length > 0" class="space-y-2">
        <div v-for="doc in documents" :key="doc.id" class="portal-card bg-white rounded-2xl p-4 flex items-center justify-between" style="border: 1px solid rgba(168,162,158,0.12); box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center" :style="{
              background: (doc.mime_type || '').includes('pdf') ? 'rgba(239,68,68,0.08)' : (doc.mime_type || '').includes('image') ? 'rgba(59,130,246,0.08)' : 'rgba(120,113,108,0.06)',
            }">
              <svg v-if="(doc.mime_type || '').includes('pdf')" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
              <svg v-else-if="(doc.mime_type || '').includes('image')" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#78716c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
              <div class="text-sm font-medium tracking-tight" style="color: #1c1917;">{{ doc.file_name || doc.original_name || doc.filename }}</div>
              <div class="text-xs" style="color: #a8a29e;">
                {{ formatFileSize(doc.file_size) }}
                <span v-if="doc.description" class="ml-1 opacity-70">· {{ doc.description }}</span>
                <span class="ml-1">· {{ timeAgo(doc.created_at || doc.uploaded_at) }}</span>
              </div>
            </div>
          </div>
          <a :href="doc.file_url || '/portal/documents/download/' + doc.id"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium transition-colors download-btn"
            style="color: #ee7606; background: rgba(238, 118, 6, 0.06);">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download
          </a>
        </div>
      </div>
      <div v-else class="bg-white rounded-2xl p-12 text-center" style="border: 1px solid rgba(168,162,158,0.12);">
        <div class="w-14 h-14 mx-auto mb-3 rounded-xl flex items-center justify-center" style="background: #faf8f5;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <p class="text-sm" style="color: #78716c;">Noch keine Dokumente vorhanden</p>
        <p class="text-xs mt-1" style="color: #a8a29e;">Dokumente wie Exposes und Grundrisse werden hier bereitgestellt.</p>
      </div>
    </div>

    <!-- ═══ TAB: NACHRICHTEN (CHAT) ═══ -->
    <div v-if="activeTab === 'messages'" class="flex flex-col" style="min-height: 500px;">
      <div class="bg-white rounded-2xl flex flex-col overflow-hidden" style="border: 1px solid rgba(168,162,158,0.12); min-height: 500px; box-shadow: 0 1px 3px rgba(120,113,108,0.06), 0 8px 24px rgba(120,113,108,0.04);">
        <!-- Header -->
        <div class="px-4 py-3 border-b flex items-center gap-2" style="border-color: rgba(168,162,158,0.08);">
          <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold" style="background: rgba(238,118,6,0.08); color: #ee7606;">SR</div>
          <div>
            <div class="text-sm font-bold tracking-tight" style="color: #1c1917;">Nachrichten an SR-Homes</div>
            <div class="text-[10px]" style="color: #a8a29e;">Direkter Kontakt zu Ihrem Ansprechpartner</div>
          </div>
        </div>

        <!-- Chat messages area -->
        <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3" style="max-height: 400px;">
          <div v-if="localMessages.length === 0" class="text-center py-12">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center" style="background: #faf8f5;">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </div>
            <p class="text-sm" style="color: #a8a29e;">Noch keine Nachrichten.</p>
            <p class="text-xs mt-1" style="color: #d6d3d1;">Schreiben Sie Ihrem Makler direkt hier.</p>
          </div>

          <div v-for="msg in localMessages" :key="msg.id"
            class="flex"
            :class="msg.author_role === 'customer' ? 'justify-end' : 'justify-start'">
            <!-- Admin message (left) -->
            <div v-if="msg.author_role === 'admin'" class="flex items-end gap-2 max-w-[80%]">
              <div class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold flex-shrink-0 mb-1" style="background: rgba(238,118,6,0.08); color: #ee7606;">SR</div>
              <div>
                <div class="px-3.5 py-2.5 rounded-2xl rounded-bl-sm text-sm leading-relaxed chat-bubble-in" style="background: #faf8f5; color: #1c1917; max-width: 100%;">
                  {{ msg.message }}
                </div>
                <div class="text-[10px] mt-1 ml-1" style="color: #a8a29e;">
                  {{ msg.author_name || 'SR-Homes' }} · {{ formatMsgTime(msg.created_at) }}
                </div>
              </div>
            </div>

            <!-- Customer message (right) -->
            <div v-else class="flex items-end gap-2 max-w-[80%]">
              <div>
                <div class="px-3.5 py-2.5 rounded-2xl rounded-br-sm text-sm leading-relaxed text-white chat-bubble-out" style="background: linear-gradient(135deg, #ee7606, #f59e0b); max-width: 100%;">
                  {{ msg.message }}
                </div>
                <div class="text-[10px] mt-1 mr-1 text-right" style="color: #a8a29e;">
                  Sie · {{ formatMsgTime(msg.created_at) }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Input area -->
        <div class="p-3 border-t chat-input-area" style="border-color: rgba(168,162,158,0.08);">
          <div v-if="messageError" class="text-xs text-red-500 mb-2">{{ messageError }}</div>
          <div class="flex items-end gap-2">
            <textarea
              v-model="newMessageText"
              @keydown="handleMsgKeydown"
              placeholder="Nachricht schreiben... (Enter zum Senden)"
              rows="2"
              class="flex-1 text-sm px-3 py-2 rounded-xl border resize-none outline-none transition-colors"
              style="border-color: rgba(168,162,158,0.12); color: #1c1917; background: #faf8f5; line-height: 1.4;"
            ></textarea>
            <button
              @click="sendMessage"
              :disabled="!newMessageText.trim() || messageSending"
              class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center cursor-pointer border-0 transition-all"
              :style="{
                background: newMessageText.trim() ? 'linear-gradient(135deg, #ee7606, #f59e0b)' : 'rgba(168,162,158,0.08)',
                color: newMessageText.trim() ? 'white' : '#a8a29e',
              }"
            >
              <svg v-if="!messageSending" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              <div v-else class="w-4 h-4 border-2 border-t-transparent rounded-full animate-spin" style="border-color: currentColor; border-top-color: transparent;"></div>
            </button>
          </div>
          <p class="text-[10px] mt-1.5" style="color: #a8a29e;">Enter senden · Shift+Enter neue Zeile</p>
        </div>
      </div>
    </div>
  </PortalLayout>
</template>

<style scoped>
/* Premium hover effects */
.portal-card {
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.portal-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(120,113,108,0.08), 0 16px 40px rgba(120,113,108,0.06);
}

/* Stagger animation */
@keyframes fadeSlideUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
.stagger-item {
  animation: fadeSlideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1) both;
}

/* Glass effect */
.glass-badge {
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  background: rgba(255,255,255,0.7);
  border: 1px solid rgba(255,255,255,0.3);
}

/* Gradient top border for AI card */
.gradient-top-border {
  position: relative;
  overflow: hidden;
}
.gradient-top-border::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, #ee7606, #f59e0b);
  border-radius: 16px 16px 0 0;
}

/* AI icon glow */
.ai-glow {
  box-shadow: 0 0 12px rgba(168, 85, 247, 0.15);
}

/* Avatar gradient ring */
.avatar-ring {
  box-shadow: 0 0 0 2px rgba(238,118,6,0.15);
}

/* Person and activity hover */
.person-header:hover { background: #faf8f5 !important; }
.activity-row:hover { background: #faf8f5 !important; }
.unit-row:hover { background: #faf8f5 !important; }

/* Download button hover */
.download-btn:hover {
  background: rgba(238,118,6,0.12) !important;
  color: #d16805 !important;
}

/* Progress bar glow */
.progress-glow {
  box-shadow: inset 0 1px 2px rgba(120,113,108,0.06);
}

/* Timeline view connector */
.timeline-entries {
  padding-left: 16px;
  border-left: 2px solid rgba(168,162,158,0.12);
  margin-left: 8px;
}
.timeline-entry {
  position: relative;
}
.timeline-entry::before {
  content: '';
  position: absolute;
  left: -22px;
  top: 18px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ee7606;
  border: 2px solid white;
  box-shadow: 0 0 0 2px rgba(238,118,6,0.15);
}

/* Chat bubbles */
.chat-bubble-in {
  box-shadow: 0 1px 2px rgba(120,113,108,0.06);
}
.chat-bubble-out {
  box-shadow: 0 2px 8px rgba(238,118,6,0.2);
}

/* Chat input frosted glass */
.chat-input-area {
  background: rgba(250,248,245,0.8);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
</style>
