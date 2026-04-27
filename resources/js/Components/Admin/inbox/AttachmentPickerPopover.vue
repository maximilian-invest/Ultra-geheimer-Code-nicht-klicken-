<script setup>
import { ref, computed, onMounted, inject } from 'vue'
import { Upload, FileText, Check, X, Loader2 } from 'lucide-vue-next'

const props = defineProps({
  propertyId: { type: [Number, String, null], default: null },
})
const emit = defineEmits(['close'])

const inboxCompose = inject('inboxCompose', null)

const propertyFilesList = ref([])
const propertyFilesLoading = ref(false)
const fileInputRef = ref(null)

// IDs der bereits ausgewaehlten property_files (string-coerced fuer
// Vergleich, da selectedFiles int + string-prefixed (doc_/global_)
// enthaelt).
const selectedIds = computed(() => {
  const list = inboxCompose?.selectedFiles?.value || []
  return new Set(list.map((x) => String(x)))
})

function isFileSelected(item) {
  return selectedIds.value.has(String(item.id))
}

function fmtBytes(n) {
  if (!n) return ''
  if (n < 1024) return n + ' B'
  if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB'
  return (n / 1024 / 1024).toFixed(1) + ' MB'
}

function onPickPropertyFile(item) {
  inboxCompose?.toggleFile(item.id)
}

function onUploadInput(ev) {
  inboxCompose?.addUploads(ev.target.files)
  if (fileInputRef.value) fileInputRef.value.value = ''
}

onMounted(async () => {
  if (!props.propertyId || !inboxCompose?.fetchPropertyFiles) return
  propertyFilesLoading.value = true
  try {
    propertyFilesList.value = await inboxCompose.fetchPropertyFiles(props.propertyId)
  } finally {
    propertyFilesLoading.value = false
  }
})
</script>

<template>
  <div class="popover" @click.stop>
    <header>
      <h4>Anhang einfügen</h4>
      <button class="close" @click="emit('close')" title="Schließen">×</button>
    </header>

    <!-- Hochladen -->
    <div class="section">
      <button type="button" class="upload-btn" @click="fileInputRef?.click()">
        <Upload class="icon" />
        <div class="upload-text">
          <strong>Datei hochladen</strong>
          <span>vom Computer wählen</span>
        </div>
      </button>
      <input ref="fileInputRef" type="file" class="hidden" multiple @change="onUploadInput" />
    </div>

    <div class="divider">
      <span>oder aus bereits hochgeladenen Dateien</span>
    </div>

    <!-- Dateien durchsuchen -->
    <div class="files-section">
      <div v-if="!propertyId" class="empty">
        Keine Property zugeordnet — keine Dateien verfügbar.
      </div>
      <div v-else-if="propertyFilesLoading" class="loading">
        <Loader2 class="w-3.5 h-3.5 animate-spin" />
        Lade Dateien…
      </div>
      <div v-else-if="!propertyFilesList.length" class="empty">
        Keine Dateien zu diesem Objekt.
      </div>
      <ul v-else>
        <li v-for="f in propertyFilesList" :key="String(f.id)">
          <button
            type="button"
            class="file-item"
            :class="isFileSelected(f) ? 'is-active' : ''"
            @click="onPickPropertyFile(f)"
          >
            <span class="check-box">
              <Check v-if="isFileSelected(f)" class="w-3 h-3" />
            </span>
            <FileText class="file-icon" />
            <span class="file-name">{{ f.label || f.filename }}</span>
            <span class="file-size">{{ fmtBytes(f.file_size) }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.popover {
  position: absolute;
  bottom: 60px;
  right: 20px;
  width: 360px;
  max-height: 480px;
  background: white;
  border: 1px solid hsl(36 25% 88%);
  border-radius: 12px;
  box-shadow: 0 12px 48px rgba(10, 10, 8, 0.16);
  z-index: 50;
  display: flex;
  flex-direction: column;
}
header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid hsl(36 25% 92%);
  flex-shrink: 0;
}
header h4 {
  font-size: 14px;
  font-weight: 600;
  color: hsl(0 0% 4%);
}
.close {
  background: transparent;
  border: none;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  color: hsl(0 0% 35%);
  padding: 0 4px;
}
.close:hover {
  color: hsl(0 0% 0%);
}

.section {
  padding: 10px 12px;
  flex-shrink: 0;
}
.upload-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: hsl(20 95% 97%);
  border: 1px dashed hsl(20 95% 70%);
  border-radius: 10px;
  cursor: pointer;
  transition: background 100ms ease, border-color 100ms ease;
  text-align: left;
}
.upload-btn:hover {
  background: hsl(20 95% 94%);
  border-color: #ee7600;
}
.upload-btn .icon {
  width: 18px;
  height: 18px;
  color: #ee7600;
  flex-shrink: 0;
}
.upload-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.upload-text strong {
  font-size: 13px;
  font-weight: 600;
  color: hsl(0 0% 10%);
}
.upload-text span {
  font-size: 11px;
  color: hsl(0 0% 45%);
}

.divider {
  display: flex;
  align-items: center;
  padding: 4px 12px;
  flex-shrink: 0;
}
.divider span {
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: hsl(0 0% 55%);
  flex: 1;
  text-align: center;
  position: relative;
}
.divider span::before,
.divider span::after {
  content: '';
  position: absolute;
  top: 50%;
  width: 30%;
  height: 1px;
  background: hsl(36 25% 90%);
}
.divider span::before { left: 0; }
.divider span::after { right: 0; }

.files-section {
  flex: 1;
  min-height: 80px;
  max-height: 280px;
  overflow-y: auto;
  padding: 4px 6px 10px;
}
.empty,
.loading {
  padding: 16px;
  text-align: center;
  color: hsl(0 0% 45%);
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
ul {
  list-style: none;
  padding: 0;
  margin: 0;
}
.file-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 10px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  cursor: pointer;
  text-align: left;
  font-size: 12px;
  color: hsl(0 0% 20%);
  transition: background 80ms ease;
}
.file-item:hover {
  background: hsl(36 25% 97%);
}
.file-item.is-active {
  background: hsl(20 95% 97%);
  border-color: hsl(20 95% 85%);
  color: hsl(0 0% 10%);
  font-weight: 500;
}
.check-box {
  width: 14px;
  height: 14px;
  border: 1px solid hsl(0 0% 80%);
  background: white;
  border-radius: 3px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: white;
  flex-shrink: 0;
}
.is-active .check-box {
  background: #ee7600;
  border-color: #ee7600;
}
.file-icon {
  width: 14px;
  height: 14px;
  color: hsl(0 0% 55%);
  flex-shrink: 0;
}
.is-active .file-icon {
  color: #ee7600;
}
.file-name {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.file-size {
  font-size: 10px;
  color: hsl(0 0% 55%);
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}

.hidden {
  display: none;
}
</style>
