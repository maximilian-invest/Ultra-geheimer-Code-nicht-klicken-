<script setup>
import { ref, computed, watch } from 'vue'
import { Search, Home, Check, X as XIcon } from 'lucide-vue-next'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'

const props = defineProps({
  open: { type: Boolean, default: false },
  currentPropertyId: { type: [Number, String, null], default: null },
  currentPropertyRef: { type: String, default: '' },
  currentPropertyAddress: { type: String, default: '' },
  // Properties werden als Prop uebergeben — inject klappt nicht, weil
  // shadcn Dialog via reka-ui Portal an document.body teleportiert und
  // dabei die provide/inject-Chain vom InboxTab-Tree verliert.
  properties: { type: Array, default: () => [] },
  // Wenn true: kein Migrate-Block, kein "Aktuell:"-Hinweis. Fuer Compose-
  // Flow wo es noch keine Mail-Historie gibt die umgehaengt werden koennte.
  hideMigrate: { type: Boolean, default: false },
  // Wenn true: Klick auf eine Property uebernimmt sofort und schliesst den
  // Dialog. Kein "Speichern"-Footer-Button noetig. Fuer Neue-Mail-Flow,
  // wo der zusaetzliche Klick stoert.
  autoConfirm: { type: Boolean, default: false },
  // Optionale Anpassungen fuer Compose-Use-Case.
  title: { type: String, default: 'Objekt zuordnen' },
  unassignLabel: { type: String, default: 'Nicht zugeordnet' },
  unassignHint: { type: String, default: 'Mail aus allen Objektlisten entfernen' },
})
const emit = defineEmits(['update:open', 'confirm'])

const search = ref('')
const selectedId = ref(null)
const migrate = ref('keep') // 'keep' = bisherige beim alten lassen, 'migrate' = alle umhängen
const saving = ref(false)

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    selectedId.value = props.currentPropertyId ? Number(props.currentPropertyId) : null
    search.value = ''
    migrate.value = 'keep'
    saving.value = false
  }
})

const filteredProperties = computed(() => {
  const list = Array.isArray(props.properties) ? props.properties : []
  // Sortierung: zuerst zuletzt-bearbeitet (created_at desc als Proxy
  // wenn updated_at nicht im Inertia-Payload steckt), dann Ref-ID.
  const sorted = [...list].sort((a, b) => {
    const ad = String(b.created_at || '').localeCompare(String(a.created_at || ''))
    if (ad !== 0) return ad
    return String(a.ref_id || '').localeCompare(String(b.ref_id || ''))
  })
  const q = search.value.trim().toLowerCase()
  if (!q) return sorted
  return sorted.filter(p => {
    const hay = [p.ref_id, p.address, p.city, p.title, p.project_name].filter(Boolean).join(' ').toLowerCase()
    return hay.includes(q)
  })
})

const selectedProperty = computed(() => {
  if (!selectedId.value) return null
  return (props.properties || []).find(p => Number(p.id) === Number(selectedId.value)) || null
})

const hasChange = computed(() => {
  return Number(selectedId.value || 0) !== Number(props.currentPropertyId || 0)
})

function onSelect(id) {
  selectedId.value = Number(id)
  if (props.autoConfirm) onConfirm()
}

function onUnassign() {
  selectedId.value = null
  if (props.autoConfirm) onConfirm()
}

function onConfirm() {
  if (!hasChange.value) {
    emit('update:open', false)
    return
  }
  saving.value = true
  emit('confirm', {
    property_id: selectedId.value || null,
    // Compose-Flow ueberspringt Migration komplett (keine Aktivitaeten zum
    // Umhaengen). Default fuer normalen Assign-Flow bleibt das Radio.
    migrate_activities: props.hideMigrate ? false : (migrate.value === 'migrate'),
  })
}

function onCancel() {
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-lg max-h-[85vh] flex flex-col p-0 gap-0">
      <DialogHeader class="px-6 pt-5 pb-4">
        <DialogTitle class="text-base">{{ title }}</DialogTitle>
        <DialogDescription v-if="!hideMigrate" class="text-xs text-muted-foreground">
          <span v-if="currentPropertyRef">
            Aktuell: <span class="font-medium text-foreground">{{ currentPropertyRef }}</span>
            <span v-if="currentPropertyAddress"> · {{ currentPropertyAddress }}</span>
          </span>
          <span v-else class="italic">Momentan nicht zugeordnet</span>
        </DialogDescription>
        <DialogDescription v-else class="text-xs text-muted-foreground">
          Eines deiner aktiven Objekte auswählen
        </DialogDescription>
      </DialogHeader>

      <!-- Search -->
      <div class="px-6 pb-3">
        <div class="relative">
          <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground pointer-events-none" />
          <Input v-model="search" placeholder="Ref-ID, Adresse oder Stadt suchen…" class="pl-9 h-9 text-sm" />
        </div>
      </div>

      <!-- Property list -->
      <div class="flex-1 overflow-y-auto px-3 py-1 min-h-[240px] max-h-[50vh]">
        <!-- Unassign row -->
        <button
          type="button"
          class="w-full text-left px-3 py-2 rounded-md hover:bg-accent/50 transition-colors flex items-center gap-3 text-sm group"
          :class="selectedId === null ? 'bg-accent' : ''"
          @click="onUnassign"
        >
          <div class="w-7 h-7 rounded-md bg-muted flex items-center justify-center shrink-0">
            <XIcon class="w-3.5 h-3.5 text-muted-foreground" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium">{{ unassignLabel }}</div>
            <div class="text-[11px] text-muted-foreground">{{ unassignHint }}</div>
          </div>
          <Check v-if="selectedId === null" class="w-4 h-4 text-foreground shrink-0" />
        </button>

        <div v-if="filteredProperties.length" class="h-px bg-border my-2 mx-3"></div>

        <!-- Properties — kompakt, mit Thumbnail + Ref-ID prominent -->
        <button
          v-for="p in filteredProperties" :key="p.id"
          type="button"
          class="w-full text-left px-2 py-1.5 rounded-md hover:bg-accent/50 transition-colors flex items-center gap-2.5 text-sm"
          :class="Number(selectedId) === Number(p.id) ? 'bg-accent' : ''"
          @click="onSelect(p.id)"
        >
          <!-- Thumbnail (echt) oder Fallback-Box -->
          <div class="w-9 h-9 rounded-md overflow-hidden shrink-0 bg-zinc-100 flex items-center justify-center">
            <img
              v-if="p.thumbnail_url"
              :src="p.thumbnail_url"
              alt=""
              class="w-full h-full object-cover"
              loading="lazy"
            />
            <Home v-else class="w-4 h-4 text-[#EE7600]" />
          </div>
          <!-- Ref-ID + Adresse (1 Zeile + 1 Zeile) -->
          <div class="flex-1 min-w-0">
            <div class="font-mono text-[11px] font-semibold tracking-tight text-zinc-900">
              {{ p.ref_id || 'ohne Ref-ID' }}
            </div>
            <div class="text-[12px] text-zinc-600 truncate leading-tight">
              {{ p.address || p.title || p.project_name || '—' }}<span v-if="p.city" class="text-muted-foreground"> · {{ p.city }}</span>
            </div>
          </div>
          <Check v-if="Number(selectedId) === Number(p.id)" class="w-4 h-4 text-foreground shrink-0" />
        </button>

        <div v-if="!filteredProperties.length && !search" class="text-center text-xs text-muted-foreground py-10 px-6">
          Du hast noch keine aktiven Objekte in deinem Portfolio.
        </div>
        <div v-else-if="!filteredProperties.length" class="text-center text-xs text-muted-foreground py-10 px-6">
          Keine Treffer für „{{ search }}".
        </div>
      </div>

      <!-- Migration option (nicht im Compose-Flow noetig) -->
      <div v-if="hasChange && !hideMigrate" class="px-6 py-4 border-t bg-muted/20">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-2.5">Bisherige Aktivitäten</div>
        <div class="space-y-0">
          <label class="flex items-start gap-2.5 cursor-pointer py-1.5 hover:opacity-80 transition-opacity">
            <input type="radio" v-model="migrate" value="keep" class="mt-0.5 accent-foreground" />
            <div class="flex-1">
              <div class="text-sm font-medium leading-snug">Beim alten Objekt lassen</div>
              <div class="text-[11px] text-muted-foreground leading-snug mt-0.5">Historische Aktivitäten bleiben dort. Ab jetzt gehen neue zum neuen Objekt.</div>
            </div>
          </label>
          <label class="flex items-start gap-2.5 cursor-pointer py-1.5 hover:opacity-80 transition-opacity">
            <input type="radio" v-model="migrate" value="migrate" class="mt-0.5 accent-foreground" />
            <div class="flex-1">
              <div class="text-sm font-medium leading-snug">Alle bisherigen mit-verschieben</div>
              <div class="text-[11px] text-muted-foreground leading-snug mt-0.5">Historische Aktivitäten werden aufs neue Objekt umgehängt.</div>
            </div>
          </label>
        </div>
      </div>

      <DialogFooter class="px-6 py-3 border-t">
        <Button variant="ghost" size="sm" @click="onCancel" :disabled="saving">Abbrechen</Button>
        <Button v-if="!autoConfirm" size="sm" @click="onConfirm" :disabled="!hasChange || saving">
          <span v-if="saving">Speichere…</span>
          <span v-else>Speichern</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
