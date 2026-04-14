<template>
  <div class="detail-page">
    <header class="detail-header">
      <a :href="`/admin/properties/${property.id}`" class="back">← Zurueck zu {{ property.address }}</a>
      <h1>{{ link.name }}</h1>
      <div class="meta">
        <span class="status" :data-status="link.status">{{ statusLabel(link.status) }}</span>
        <span>Laeuft am {{ formatDate(link.expires_at) }}</span>
        <span>Erstellt am {{ formatDate(link.created_at) }}</span>
      </div>
      <div class="url-box">
        <code>{{ link.url }}</code>
        <button @click="copyUrl">Kopieren</button>
      </div>
    </header>

    <section class="metrics">
      <div class="metric">
        <strong>{{ totalOpens }}</strong>
        <span>Aufrufe</span>
      </div>
      <div class="metric">
        <strong>{{ sessions.length }}</strong>
        <span>Personen</span>
      </div>
      <div class="metric">
        <strong>{{ totalViews }}</strong>
        <span>Dokument-Ansichten</span>
      </div>
      <div class="metric">
        <strong>{{ totalDownloads }}</strong>
        <span>Downloads</span>
      </div>
    </section>

    <section class="timeline">
      <h2>Aktivitaet</h2>
      <div v-if="sessions.length === 0" class="empty">Noch keine Zugriffe.</div>
      <ul v-else>
        <li v-for="session in sessions" :key="session.id">
          <div class="session-head">
            <strong>{{ session.email }}</strong>
            <span>{{ formatDateTime(session.first_seen_at) }}</span>
          </div>
          <ul class="events">
            <li v-for="event in session.events" :key="event.id">
              <span class="event-type" :data-type="event.event_type">{{ eventLabel(event.event_type) }}</span>
              <span class="event-meta">{{ formatDateTime(event.created_at) }}</span>
            </li>
          </ul>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  link: Object,
  sessions: Array,
  property: Object,
});

const totalOpens = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'link_opened').length, 0)
);
const totalViews = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'doc_viewed').length, 0)
);
const totalDownloads = computed(() =>
  props.sessions.reduce((acc, s) => acc + s.events.filter(e => e.event_type === 'doc_downloaded').length, 0)
);

function statusLabel(s) {
  return { active: 'AKTIV', expired: 'ABGELAUFEN', revoked: 'GESPERRT' }[s] || s;
}
function formatDate(iso) {
  if (!iso) return 'unbegrenzt';
  return new Date(iso).toLocaleDateString('de-AT');
}
function formatDateTime(iso) {
  return new Date(iso).toLocaleString('de-AT');
}
function eventLabel(t) {
  return { link_opened: 'Link geoeffnet', doc_viewed: 'Dokument angesehen', doc_downloaded: 'Heruntergeladen' }[t] || t;
}
async function copyUrl() {
  await navigator.clipboard.writeText(props.link.url);
  window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'URL kopiert' } }));
}
</script>

<style scoped>
.detail-page { max-width: 1100px; margin: 0 auto; padding: 40px 32px; font-family: 'Outfit', sans-serif; color: #0A0A08; }
.back { color: #5A564E; text-decoration: none; font-size: 14px; }
.back:hover { color: #D4743B; }
h1 { font-size: 32px; font-weight: 600; margin: 12px 0 6px; }
.meta { display: flex; gap: 16px; color: #5A564E; font-size: 14px; margin-bottom: 20px; }
.status { font-weight: 600; }
.status[data-status="active"] { color: #15803d; }
.status[data-status="expired"] { color: #b45309; }
.status[data-status="revoked"] { color: #b91c1c; }
.url-box { display: flex; gap: 8px; background: #FAF8F5; border: 1px solid #E5E0D8; border-radius: 12px; padding: 14px 18px; align-items: center; max-width: 720px; }
.url-box code { flex: 1; color: #0A0A08; font-family: 'JetBrains Mono', monospace; font-size: 13px; }
.url-box button { background: #D4743B; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; }
.metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 32px 0; }
.metric { background: white; border: 1px solid #E5E0D8; border-radius: 12px; padding: 24px; text-align: center; }
.metric strong { display: block; font-size: 32px; font-weight: 600; color: #D4743B; margin-bottom: 4px; }
.metric span { font-size: 13px; color: #5A564E; }
.timeline h2 { font-size: 22px; font-weight: 600; margin-bottom: 16px; }
.timeline ul { list-style: none; padding: 0; }
.timeline > ul > li { border-bottom: 1px solid #E5E0D8; padding: 16px 0; }
.session-head { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
.events { padding-left: 16px; }
.events li { display: flex; justify-content: space-between; font-size: 13px; color: #5A564E; padding: 4px 0; }
.event-type { font-weight: 500; color: #0A0A08; }
.event-type[data-type="doc_downloaded"] { color: #D4743B; }
.empty { padding: 40px; text-align: center; color: #5A564E; background: #FAF8F5; border-radius: 12px; }
</style>
