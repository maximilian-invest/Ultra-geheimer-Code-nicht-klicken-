<!-- resources/js/Components/Admin/Property/PropertyLinkForm.vue -->
<template>
  <Teleport to="body">
    <div class="slideover-backdrop" @click.self="$emit('close')">
      <aside class="slideover">
        <header>
          <h3>{{ link ? 'Link bearbeiten' : 'Neuer Link' }}</h3>
          <button class="close-btn" @click="$emit('close')">×</button>
        </header>

        <div class="body">
          <label class="field">
            <span>Name</span>
            <input v-model="form.name" type="text" placeholder="z.B. Erstanfrage / Phase 2 / Besichtigung" maxlength="120" />
          </label>

          <label class="field field-toggle">
            <input v-model="form.is_default" type="checkbox" />
            <span>Als Standard-Link fuer Erstanfragen verwenden</span>
          </label>

          <label class="field">
            <span>Gueltig fuer</span>
            <select v-model="form.expiry_days">
              <option :value="7">7 Tage</option>
              <option :value="14">14 Tage</option>
              <option :value="30">30 Tage</option>
              <option :value="90">90 Tage</option>
              <option :value="null">Unbegrenzt</option>
            </select>
          </label>

          <div v-if="activeExpose" class="field">
            <span>Exposé</span>
            <label class="expose-row">
              <input type="checkbox" v-model="includeExpose" />
              <span class="expose-icon">SR</span>
              <div class="expose-body">
                <div class="expose-title">{{ activeExpose.name || 'Aktives Exposé' }}</div>
                <div class="expose-meta">{{ activeExpose.page_count }} Seiten · adaptiv</div>
              </div>
              <span class="badge-expose">EXPOSÉ</span>
            </label>
          </div>

          <div class="field">
            <span>Dokumente ({{ selectedIds.length }} / {{ availableFiles.length }})</span>
            <ul class="file-list">
              <li v-for="file in availableFiles" :key="file.id">
                <label>
                  <input
                    type="checkbox"
                    :value="file.id"
                    :checked="selectedIds.includes(file.id)"
                    @change="toggleFile(file.id)"
                  />
                  <span class="file-label">{{ file.label || file.filename }}</span>
                  <span class="file-size">{{ formatSize(file.file_size) }}</span>
                </label>
              </li>
            </ul>
          </div>
        </div>

        <footer>
          <button class="btn-secondary" @click="$emit('close')">Abbrechen</button>
          <button class="btn-primary" :disabled="!canSave || saving" @click="save">
            {{ saving ? 'Speichere …' : 'Speichern' }}
          </button>
        </footer>
      </aside>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  propertyId: { type: Number, required: true },
  link: { type: Object, default: null },
  availableFiles: { type: Array, required: true },
  activeExpose: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const form = ref({
  name: props.link?.name ?? '',
  is_default: props.link?.is_default ?? false,
  expiry_days: 30,
});
const selectedIds = ref(props.link?.document_ids ?? []);
// Exposé-Auswahl: checked wenn der Link bereits das aktive Exposé enthält
// oder wenn der Makler beim neuen Link auf "Exposé mit anhängen" klickt.
const includeExpose = ref(
  Boolean(props.link && props.activeExpose && props.link.expose_version_id === props.activeExpose.version_id)
);
const saving = ref(false);

const canSave = computed(() => form.value.name.trim() && (selectedIds.value.length > 0 || includeExpose.value));

function toggleFile(id) {
  const i = selectedIds.value.indexOf(id);
  if (i >= 0) selectedIds.value.splice(i, 1);
  else selectedIds.value.push(id);
}

function formatSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

async function save() {
  saving.value = true;
  const payload = {
    name: form.value.name.trim(),
    is_default: form.value.is_default,
    expires_at: form.value.expiry_days ? new Date(Date.now() + form.value.expiry_days * 86400000).toISOString() : null,
    file_ids: selectedIds.value,
    expose_version_id: includeExpose.value && props.activeExpose ? props.activeExpose.version_id : null,
  };

  try {
    const url = props.link
      ? `/admin/properties/${props.propertyId}/links/${props.link.id}`
      : `/admin/properties/${props.propertyId}/links`;
    const method = props.link ? 'put' : 'post';
    const { data } = await axios[method](url, payload);
    emit('saved', data.link);
  } catch (e) {
    // The backend now uses Laravel's standard validation envelope: {message, errors: {file_ids: [...]}}
    // Fall back to legacy {error: ...} and finally a generic message.
    const msg = e.response?.data?.errors?.file_ids?.[0]
      || e.response?.data?.message
      || e.response?.data?.error
      || 'Fehler beim Speichern';
    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: msg } }));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  if (props.link) {
    const days = props.link.expires_at
      ? Math.round((new Date(props.link.expires_at) - Date.now()) / 86400000)
      : null;
    form.value.expiry_days = [7, 14, 30, 90].includes(days) ? days : null;
  }
});
</script>

<style scoped>
.slideover-backdrop { position: fixed; inset: 0; background: rgba(10,10,8,0.4); z-index: 1000; display: flex; justify-content: flex-end; }
.slideover { width: 480px; max-width: 100vw; background: white; height: 100vh; display: flex; flex-direction: column; animation: slide-in 300ms cubic-bezier(0.25,0.46,0.45,0.94); }
@keyframes slide-in { from { transform: translateX(100%); } to { transform: translateX(0); } }
header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #E5E0D8; }
header h3 { font-size: 18px; font-weight: 600; color: #0A0A08; }
.close-btn { background: transparent; border: none; font-size: 28px; color: #5A564E; cursor: pointer; }
.body { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 18px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field > span { font-size: 13px; font-weight: 500; color: #0A0A08; }
.field input[type="text"], .field select { padding: 10px 12px; border: 1px solid #E5E0D8; border-radius: 8px; font-size: 14px; font-family: inherit; }
.field input:focus, .field select:focus { outline: none; border-color: #D4743B; }
.field-toggle { flex-direction: row; align-items: center; gap: 10px; }
.file-list { list-style: none; padding: 0; max-height: 260px; overflow-y: auto; border: 1px solid #E5E0D8; border-radius: 8px; }
.file-list li { padding: 10px 12px; border-bottom: 1px solid #F0ECE5; }
.file-list li:last-child { border-bottom: none; }
.file-list label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; }
.file-label { flex: 1; color: #0A0A08; }
.file-size { color: #5A564E; font-variant-numeric: tabular-nums; }
footer { display: flex; justify-content: flex-end; gap: 10px; padding: 20px 24px; border-top: 1px solid #E5E0D8; }
.btn-primary, .btn-secondary { padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }
.btn-primary { background: #D4743B; color: white; border: none; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary:hover:not(:disabled) { background: #C0551F; }
.btn-secondary { background: transparent; border: 1px solid #E5E0D8; color: #0A0A08; }
.btn-secondary:hover { border-color: #D4743B; }

/* Exposé-Zeile */
.expose-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: linear-gradient(90deg, #fff7ed 0%, #ffffff 55%); border: 1px solid #fed7aa; border-radius: 8px; cursor: pointer; }
.expose-row:hover { border-color: #fb923c; }
.expose-row input[type="checkbox"] { flex-shrink: 0; }
.expose-icon { width: 36px; height: 36px; border-radius: 6px; background: linear-gradient(135deg, #ee7600, #c95b00); color: #fff; display: flex; align-items: center; justify-content: center; font-family: Georgia, serif; font-weight: 600; font-size: 14px; flex-shrink: 0; }
.expose-body { flex: 1; min-width: 0; }
.expose-title { font-size: 13px; font-weight: 600; color: #0A0A08; }
.expose-meta { font-size: 11px; color: #5A564E; margin-top: 2px; }
.badge-expose { font-size: 9px; font-weight: 700; letter-spacing: 1px; padding: 3px 7px; background: #ee7600; color: #fff; border-radius: 4px; }
</style>
