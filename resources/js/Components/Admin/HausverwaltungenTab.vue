<script setup>
import { ref, inject, onMounted } from 'vue'
import { Search, Plus, Pencil, Trash2, Building2, Mail, Phone, MapPin } from 'lucide-vue-next'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import HausverwaltungFormDialog from './HausverwaltungFormDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const managers = ref([])
const loading = ref(false)
const search = ref('')
const dialogOpen = ref(false)
const editingManager = ref(null)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    const url = API.value + '&action=list_property_managers' + (search.value ? '&search=' + encodeURIComponent(search.value) : '')
    const r = await fetch(url)
    const d = await r.json()
    managers.value = d.managers || []
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingManager.value = null
  dialogOpen.value = true
}

function openEdit(m) {
  editingManager.value = { ...m }
  dialogOpen.value = true
}

async function onSave(payload) {
  saving.value = true
  try {
    const action = editingManager.value ? 'update_property_manager' : 'create_property_manager'
    const r = await fetch(API.value + '&action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const d = await r.json()
    if (d.success) {
      toast(editingManager.value ? 'Hausverwaltung aktualisiert' : 'Hausverwaltung angelegt')
      dialogOpen.value = false
      await load()
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    saving.value = false
  }
}

async function onDelete(m) {
  if (m.property_count > 0) {
    toast(`Kann nicht gelöscht werden — noch ${m.property_count} Objekt(en) zugewiesen`)
    return
  }
  if (!confirm(`Hausverwaltung "${m.company_name}" wirklich löschen?`)) return
  try {
    const r = await fetch(API.value + '&action=delete_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: m.id }),
    })
    const d = await r.json()
    if (d.success) {
      toast('Gelöscht')
      await load()
    } else {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
    }
  } catch (e) {
    toast('Fehler: ' + e.message)
  }
}

function addressLine(m) {
  const parts = [m.address_street, [m.address_zip, m.address_city].filter(Boolean).join(' ')].filter(Boolean)
  return parts.join(', ')
}

let debounce = null
function onSearchInput() {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => load(), 250)
}

onMounted(load)

defineExpose({ load })
</script>

<template>
  <div>
    <div class="flex items-center gap-2 mb-4">
      <div class="relative flex-1">
        <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
        <Input v-model="search" @input="onSearchInput" class="pl-9" placeholder="Hausverwaltung suchen…" />
      </div>
      <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="openCreate">
        <Plus class="w-4 h-4 mr-1" />
        Neue Hausverwaltung
      </Button>
    </div>

    <div v-if="loading" class="text-sm text-muted-foreground py-8 text-center">Lädt…</div>

    <div v-else-if="!managers.length" class="text-center py-12 text-sm text-muted-foreground">
      <Building2 class="w-10 h-10 mx-auto mb-2 text-muted-foreground/40" />
      <div>Noch keine Hausverwaltungen angelegt.</div>
      <div class="text-xs mt-1">Klick „Neue Hausverwaltung" um zu beginnen.</div>
    </div>

    <div v-else class="space-y-2">
      <div
        v-for="m in managers" :key="m.id"
        class="rounded-xl border border-border/60 bg-card p-4 hover:border-border transition-colors"
      >
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-semibold text-sm">{{ m.company_name }}</span>
              <Badge v-if="m.property_count" variant="outline" class="text-[10px]">
                {{ m.property_count }} Objekt{{ m.property_count > 1 ? 'e' : '' }}
              </Badge>
            </div>
            <div v-if="m.contact_person" class="text-xs text-muted-foreground mt-0.5">
              Ansprechpartner: {{ m.contact_person }}
            </div>
            <div class="text-xs text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1">
              <span class="flex items-center gap-1"><Mail class="w-3 h-3" /> {{ m.email }}</span>
              <span v-if="m.phone" class="flex items-center gap-1"><Phone class="w-3 h-3" /> {{ m.phone }}</span>
              <span v-if="addressLine(m)" class="flex items-center gap-1"><MapPin class="w-3 h-3" /> {{ addressLine(m) }}</span>
            </div>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <Button variant="ghost" size="icon" class="h-8 w-8" @click="openEdit(m)" title="Bearbeiten">
              <Pencil class="w-3.5 h-3.5" />
            </Button>
            <Button variant="ghost" size="icon" class="h-8 w-8 text-red-600 hover:text-red-700 hover:bg-red-50"
                    @click="onDelete(m)" title="Löschen" :disabled="m.property_count > 0">
              <Trash2 class="w-3.5 h-3.5" />
            </Button>
          </div>
        </div>
      </div>
    </div>

    <HausverwaltungFormDialog
      v-model:open="dialogOpen"
      :manager="editingManager"
      :saving="saving"
      @save="onSave"
    />
  </div>
</template>
