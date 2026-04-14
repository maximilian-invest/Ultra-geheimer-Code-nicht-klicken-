<!-- resources/js/Components/Admin/Property/PropertyLinksTab.vue -->
<template>
  <div class="property-links-tab">
    <header class="toolbar">
      <h3>Zugriffs-Links</h3>
      <button type="button" class="btn-primary" @click="openCreate">+ Neuer Link</button>
    </header>

    <div v-if="loading" class="skeleton">Lade Links …</div>

    <div v-else-if="links.length === 0" class="empty-state">
      <p>Noch keine Links fuer dieses Objekt. Erstelle den ersten Link fuer Erstanfragen.</p>
    </div>

    <ul v-else class="cards">
      <li
        v-for="link in sortedLinks"
        :key="link.id"
        class="card"
        :class="{ 'is-dimmed': link.status !== 'active', 'is-default': link.is_default }"
      >
        <div class="card-head">
          <h4>
            <span v-if="link.is_default" class="badge badge-default">Standard</span>
            {{ link.name }}
          </h4>
          <span class="status" :data-status="link.status">{{ statusLabel(link.status) }}</span>
        </div>
        <div class="card-meta">
          {{ link.document_ids.length }} Dokument(e) · {{ link.sessions_count }} Aufrufe
          · laeuft am {{ formatDate(link.expires_at) }}
        </div>
        <div class="card-actions">
          <button @click="copyUrl(link)">URL kopieren</button>
          <button @click="openEdit(link)">Bearbeiten</button>
          <button v-if="link.status === 'active'" @click="revoke(link)">Sperren</button>
          <button v-else-if="link.status === 'revoked'" @click="reactivate(link)">Reaktivieren</button>
          <a :href="`/admin/properties/${propertyId}/links/${link.id}`">Details →</a>
        </div>
      </li>
    </ul>

    <PropertyLinkForm
      v-if="formOpen"
      :property-id="propertyId"
      :link="editingLink"
      :available-files="files"
      @close="closeForm"
      @saved="onSaved"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import PropertyLinkForm from './PropertyLinkForm.vue';

const props = defineProps({
  propertyId: { type: Number, required: true },
});

const links = ref([]);
const files = ref([]);
const loading = ref(true);
const formOpen = ref(false);
const editingLink = ref(null);

const sortedLinks = computed(() => {
  return [...links.value].sort((a, b) => {
    if (a.is_default !== b.is_default) return a.is_default ? -1 : 1;
    if (a.status !== b.status) return a.status === 'active' ? -1 : 1;
    return new Date(b.created_at) - new Date(a.created_at);
  });
});

async function fetchLinks() {
  loading.value = true;
  const { data } = await axios.get(`/admin/properties/${props.propertyId}/links`);
  links.value = data.links;
  files.value = data.files || [];
  loading.value = false;
}

function statusLabel(s) {
  return { active: 'AKTIV', expired: 'ABGELAUFEN', revoked: 'GESPERRT' }[s] || s;
}

function formatDate(iso) {
  if (!iso) return 'unbegrenzt';
  return new Date(iso).toLocaleDateString('de-AT');
}

async function copyUrl(link) {
  await navigator.clipboard.writeText(link.url);
  window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'Link kopiert' } }));
}

async function revoke(link) {
  if (!confirm(`Link "${link.name}" wirklich sperren?`)) return;
  await axios.post(`/admin/properties/${props.propertyId}/links/${link.id}/revoke`);
  await fetchLinks();
}

async function reactivate(link) {
  await axios.post(`/admin/properties/${props.propertyId}/links/${link.id}/reactivate`);
  await fetchLinks();
}

function openCreate() {
  editingLink.value = null;
  formOpen.value = true;
}

function openEdit(link) {
  editingLink.value = link;
  formOpen.value = true;
}

function closeForm() {
  formOpen.value = false;
  editingLink.value = null;
}

async function onSaved(link) {
  closeForm();
  if (link?.url) {
    try {
      await navigator.clipboard.writeText(link.url);
      window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'Link erstellt & kopiert' } }));
    } catch (e) {
      window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', text: 'Link erstellt' } }));
    }
  }
  await fetchLinks();
}

onMounted(fetchLinks);
</script>

<style scoped>
.property-links-tab { padding: 24px; }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.toolbar h3 { font-size: 20px; font-weight: 600; color: #0A0A08; }
.btn-primary { background: #D4743B; color: white; padding: 10px 18px; border-radius: 8px; border: none; cursor: pointer; font-weight: 500; transition: background 200ms; }
.btn-primary:hover { background: #C0551F; }
.cards { list-style: none; padding: 0; display: grid; gap: 14px; }
.card { border: 1px solid #E5E0D8; border-radius: 12px; padding: 18px; background: #FFFFFF; transition: all 250ms cubic-bezier(0.25,0.46,0.45,0.94); }
.card:hover { box-shadow: 0 4px 24px rgba(10,10,8,0.08); transform: translateY(-2px); }
.card.is-dimmed { opacity: 0.55; }
.card.is-default { border-color: #D4743B; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.card-head h4 { font-size: 16px; font-weight: 600; color: #0A0A08; display: flex; gap: 8px; align-items: center; }
.badge-default { background: #D4743B; color: white; font-size: 11px; font-weight: 500; padding: 2px 8px; border-radius: 4px; }
.status { font-size: 11px; font-weight: 600; color: #5A564E; }
.status[data-status="active"] { color: #15803d; }
.status[data-status="expired"] { color: #b45309; }
.status[data-status="revoked"] { color: #b91c1c; }
.card-meta { font-size: 13px; color: #5A564E; margin-bottom: 12px; }
.card-actions { display: flex; gap: 10px; font-size: 13px; }
.card-actions button, .card-actions a { background: transparent; border: 1px solid #E5E0D8; color: #0A0A08; padding: 6px 12px; border-radius: 6px; cursor: pointer; text-decoration: none; }
.card-actions button:hover, .card-actions a:hover { border-color: #D4743B; color: #D4743B; }
.empty-state { padding: 40px; text-align: center; color: #5A564E; background: #FAF8F5; border-radius: 12px; }
.skeleton { padding: 20px; color: #5A564E; }
</style>
