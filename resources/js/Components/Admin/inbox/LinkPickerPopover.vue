<!-- resources/js/Components/Admin/inbox/LinkPickerPopover.vue -->
<template>
  <div class="popover" @click.stop>
    <header>
      <h4>Link einfuegen</h4>
      <button class="close" @click="$emit('close')">×</button>
    </header>
    <div v-if="loading" class="loading">Lade Links …</div>
    <div v-else-if="links.length === 0" class="empty">
      <p>Keine aktiven Links fuer dieses Objekt.</p>
      <button
        type="button"
        class="create-link-btn"
        @click="openPropertySettings"
      >
        Link in Objekt-Einstellungen anlegen →
      </button>
      <p class="empty-hint">Du wirst direkt zum Bereich "Properties" weitergeleitet.</p>
    </div>
    <ul v-else>
      <li v-for="link in links" :key="link.id">
        <button type="button" @click="$emit('pick', link)">
          <strong>{{ link.name }}</strong>
          <span>{{ link.document_ids.length }} Dokument(e){{ link.expires_at ? ' · laeuft am ' + formatDate(link.expires_at) : '' }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({ propertyId: { type: Number, required: true } });
const emit = defineEmits(['close', 'pick']);

const links = ref([]);
const loading = ref(true);
const errorMsg = ref('');

function formatDate(iso) {
  return new Date(iso).toLocaleDateString('de-AT');
}

onMounted(async () => {
  try {
    const { data } = await axios.get(`/admin/properties/${props.propertyId}/links/active`);
    links.value = data.links || [];
  } catch (e) {
    errorMsg.value = 'Links konnten nicht geladen werden.';
  } finally {
    loading.value = false;
  }
});

function openPropertySettings() {
  try {
    localStorage.setItem('sr-admin-tab', 'properties')
  } catch {}
  window.location.href = '/admin'
}
</script>

<style scoped>
.popover { position: absolute; bottom: 60px; right: 20px; width: 340px; background: white; border: 1px solid #E5E0D8; border-radius: 12px; box-shadow: 0 12px 48px rgba(10,10,8,0.16); z-index: 50; }
header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #F0ECE5; }
header h4 { font-size: 14px; font-weight: 600; color: #0A0A08; }
.close { background: transparent; border: none; font-size: 20px; cursor: pointer; color: #5A564E; }
.loading, .empty { padding: 20px; text-align: center; color: #5A564E; font-size: 13px; }
.create-link-btn {
  display: inline-block;
  margin-top: 8px;
  color: #D4743B;
  background: transparent;
  border: none;
  text-decoration: none;
  font-weight: 600;
  cursor: pointer;
}
.empty-hint { margin-top: 8px; font-size: 11px; color: #7a766f; }
.empty-hint.error { color: #b42318; }
ul { list-style: none; padding: 6px; max-height: 300px; overflow-y: auto; }
ul li button { width: 100%; text-align: left; padding: 10px 14px; background: transparent; border: none; border-radius: 8px; cursor: pointer; transition: background 150ms; }
ul li button:hover { background: #FAF8F5; }
ul li strong { display: block; font-size: 13px; color: #0A0A08; margin-bottom: 2px; }
ul li span { font-size: 12px; color: #5A564E; }
</style>
