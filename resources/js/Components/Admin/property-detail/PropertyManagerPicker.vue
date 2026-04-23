<script setup>
import { ref, computed, inject, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { Search, Building2, ChevronDown, X, Plus } from 'lucide-vue-next'
import HausverwaltungFormDialog from '../HausverwaltungFormDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  propertyId: { type: [Number, String], required: true },
  managerId: { type: [Number, String, null], default: null },
  managerName: { type: String, default: '' },
})

const emit = defineEmits(['assigned'])

const open = ref(false)
const managers = ref([])
const loading = ref(false)
const search = ref('')
const selectedManager = ref(null)
const dialogOpen = ref(false)
const dialogSaving = ref(false)

const triggerRef = ref(null)

async function loadManagers() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=list_property_managers')
    const d = await r.json()
    managers.value = d.managers || []
  } catch (e) {
    toast('Laden fehlgeschlagen')
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return managers.value
  return managers.value.filter(m => {
    return [m.company_name, m.email, m.address_city, m.contact_person]
      .filter(Boolean).join(' ').toLowerCase().includes(q)
  })
})

const showCreateOption = computed(() => {
  const q = search.value.trim()
  if (!q) return false
  return !managers.value.some(m => (m.company_name || '').toLowerCase() === q.toLowerCase())
})

function toggleOpen() {
  open.value = !open.value
  if (open.value && !managers.value.length) loadManagers()
  if (open.value) nextTick(() => { search.value = '' })
}

async function select(m) {
  try {
    const r = await fetch(API.value + '&action=assign_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, property_manager_id: m.id }),
    })
    const d = await r.json()
    if (d.success) {
      selectedManager.value = m
      open.value = false
      emit('assigned', { id: m.id, company_name: m.company_name })
      toast('Hausverwaltung zugewiesen')
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  }
}

async function clearSelection() {
  if (!confirm('Hausverwaltung-Zuordnung wirklich entfernen?')) return
  try {
    const r = await fetch(API.value + '&action=assign_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, property_manager_id: null }),
    })
    const d = await r.json()
    if (d.success) {
      selectedManager.value = null
      open.value = false
      emit('assigned', null)
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  }
}

function openCreateDialog() {
  open.value = false
  dialogOpen.value = true
}

async function onSaveFromDialog(payload) {
  dialogSaving.value = true
  try {
    const r = await fetch(API.value + '&action=quick_create_and_assign_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: props.propertyId, ...payload }),
    })
    const d = await r.json()
    if (d.success) {
      selectedManager.value = d.manager
      if (!managers.value.some(m => m.id === d.manager.id)) {
        managers.value.unshift(d.manager)
      }
      dialogOpen.value = false
      emit('assigned', { id: d.manager.id, company_name: d.manager.company_name })
      toast('Hausverwaltung angelegt und zugewiesen')
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    dialogSaving.value = false
  }
}

watch(() => props.managerId, async (id) => {
  if (!id) { selectedManager.value = null; return }
  if (selectedManager.value?.id === Number(id)) return
  if (managers.value.length) {
    const m = managers.value.find(x => x.id === Number(id))
    if (m) { selectedManager.value = m; return }
  }
  if (!managers.value.length) await loadManagers()
  selectedManager.value = managers.value.find(x => x.id === Number(id)) || {
    id: Number(id),
    company_name: props.managerName || 'Hausverwaltung',
  }
}, { immediate: true })

function onDocClick(e) {
  if (triggerRef.value && !triggerRef.value.contains(e.target) && !popupRef.value?.contains(e.target)) {
    open.value = false
  }
}

// Position-Tracking fuer die Teleport-Dropdown — damit das Menu auch dann
// korrekt sitzt wenn der Picker in einem Accordion / Card / overflow:hidden-
// Container gerendert wird. Wir berechnen die Koordinaten beim Oeffnen und
// bei Scroll/Resize neu.
const popupRef = ref(null)
const popupStyle = ref({ top: '0px', left: '0px', width: '0px' })

function updatePopupPosition() {
  if (!open.value || !triggerRef.value) return
  const rect = triggerRef.value.getBoundingClientRect()
  popupStyle.value = {
    position: 'fixed',
    top: (rect.bottom + 4) + 'px',
    left: rect.left + 'px',
    width: rect.width + 'px',
    zIndex: 60,
  }
}

watch(open, async (v) => {
  if (v) {
    await nextTick()
    updatePopupPosition()
  }
})

onMounted(() => {
  document.addEventListener('click', onDocClick)
  window.addEventListener('scroll', updatePopupPosition, true)
  window.addEventListener('resize', updatePopupPosition)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('scroll', updatePopupPosition, true)
  window.removeEventListener('resize', updatePopupPosition)
})
</script>

<template>
  <div class="relative" ref="triggerRef">
    <button type="button"
            class="w-full flex items-center justify-between gap-2 border border-input rounded-md px-3 py-2 text-sm bg-background hover:bg-accent/40 transition-colors"
            @click.stop="toggleOpen">
      <div v-if="selectedManager" class="flex items-center gap-2 min-w-0 flex-1">
        <div class="w-7 h-7 rounded-md bg-[#fff7ed] flex items-center justify-center shrink-0">
          <Building2 class="w-3.5 h-3.5 text-[#EE7600]" />
        </div>
        <div class="text-left min-w-0">
          <div class="font-medium truncate">{{ selectedManager.company_name }}</div>
          <div v-if="selectedManager.email || selectedManager.address_city" class="text-xs text-muted-foreground truncate">
            {{ [selectedManager.email, selectedManager.address_city].filter(Boolean).join(' · ') }}
          </div>
        </div>
      </div>
      <span v-else class="text-muted-foreground flex-1 text-left">Hausverwaltung wählen oder neu anlegen…</span>
      <ChevronDown class="w-4 h-4 text-muted-foreground shrink-0" />
    </button>

    <!-- Dropdown wird nach <body> geteleported damit es NICHT von Card/Accordion
         overflow:hidden abgeschnitten wird. Position wird via updatePopupPosition
         synchron zur Trigger-Box mitgefuehrt (scroll/resize watched). -->
    <Teleport to="body">
      <div v-if="open" ref="popupRef" :style="popupStyle"
           class="bg-popover border border-border rounded-lg shadow-lg p-1 max-h-72 overflow-y-auto"
           @click.stop>
        <div class="relative mb-1">
          <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
          <input v-model="search" type="text"
                 class="w-full border-0 bg-muted/50 rounded-md pl-7 pr-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                 placeholder="Suchen…" @click.stop />
        </div>

        <div v-if="loading" class="text-xs text-muted-foreground py-3 text-center">Lädt…</div>

        <div v-else>
          <button v-for="m in filtered" :key="m.id" type="button"
                  class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-accent/60 text-left"
                  @click="select(m)">
            <Building2 class="w-3.5 h-3.5 text-[#EE7600] shrink-0" />
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium truncate">{{ m.company_name }}</div>
              <div class="text-[11px] text-muted-foreground truncate">
                {{ [m.email, m.address_city].filter(Boolean).join(' · ') }}
              </div>
            </div>
          </button>

          <div v-if="!filtered.length && !showCreateOption" class="text-xs text-muted-foreground py-3 text-center">
            Keine Treffer.
          </div>

          <button v-if="showCreateOption" type="button"
                  class="w-full flex items-center gap-2 px-2 py-2 mt-1 border-t border-border rounded-md hover:bg-accent/60 text-left text-[#c2410c]"
                  @click="openCreateDialog">
            <Plus class="w-4 h-4 shrink-0" />
            <span class="text-sm font-medium">Neue Hausverwaltung „{{ search }}" anlegen</span>
          </button>

          <button v-if="selectedManager" type="button"
                  class="w-full flex items-center gap-2 px-2 py-2 mt-1 border-t border-border rounded-md hover:bg-accent/60 text-left text-red-600"
                  @click="clearSelection">
            <X class="w-4 h-4 shrink-0" />
            <span class="text-sm">Zuordnung entfernen</span>
          </button>
        </div>
      </div>
    </Teleport>

    <HausverwaltungFormDialog
      v-model:open="dialogOpen"
      :prefill-name="search"
      :saving="dialogSaving"
      @save="onSaveFromDialog"
    />
  </div>
</template>
