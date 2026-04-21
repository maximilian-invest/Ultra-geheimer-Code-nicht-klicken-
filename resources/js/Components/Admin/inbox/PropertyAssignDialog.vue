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
  const q = search.value.trim().toLowerCase()
  if (!q) return list.slice(0, 50)
  return list.filter(p => {
    const hay = [p.ref_id, p.address, p.city, p.title].filter(Boolean).join(' ').toLowerCase()
    return hay.includes(q)
  }).slice(0, 100)
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
}

function onUnassign() {
  selectedId.value = null
}

function onConfirm() {
  if (!hasChange.value) {
    emit('update:open', false)
    return
  }
  saving.value = true
  emit('confirm', {
    property_id: selectedId.value || null,
    migrate_activities: migrate.value === 'migrate',
  })
}

function onCancel() {
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-xl max-h-[90vh] flex flex-col p-0">
      <DialogHeader class="px-6 pt-6 pb-2">
        <DialogTitle>Objekt-Zuordnung ändern</DialogTitle>
        <DialogDescription class="text-xs">
          <span v-if="currentPropertyRef">
            Aktuell: <strong>{{ currentPropertyRef }}</strong>
            <span v-if="currentPropertyAddress" class="text-muted-foreground"> · {{ currentPropertyAddress }}</span>
          </span>
          <span v-else>Aktuell: <strong>Nicht zugeordnet</strong></span>
        </DialogDescription>
      </DialogHeader>

      <!-- Search -->
      <div class="px-6 py-2">
        <div class="relative">
          <Search class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input v-model="search" placeholder="Ref-ID, Adresse oder Stadt suchen…" class="pl-8" />
        </div>
      </div>

      <!-- Property list -->
      <div class="flex-1 overflow-y-auto px-6 min-h-[200px] max-h-[40vh]">
        <div class="space-y-1">
          <button
            type="button"
            class="w-full text-left px-3 py-2 rounded-md border border-dashed hover:bg-accent/40 transition-colors flex items-center gap-2 text-sm"
            :class="selectedId === null ? 'border-foreground bg-accent/60' : 'border-border'"
            @click="onUnassign"
          >
            <XIcon class="w-4 h-4 text-muted-foreground" />
            <span class="flex-1">Nicht zugeordnet (aus allen Listen entfernen)</span>
            <Check v-if="selectedId === null" class="w-4 h-4 text-foreground" />
          </button>

          <button
            v-for="p in filteredProperties" :key="p.id"
            type="button"
            class="w-full text-left px-3 py-2 rounded-md border hover:bg-accent/40 transition-colors flex items-center gap-2 text-sm"
            :class="Number(selectedId) === Number(p.id) ? 'border-foreground bg-accent/60' : 'border-border'"
            @click="onSelect(p.id)"
          >
            <Home class="w-4 h-4 text-muted-foreground shrink-0" />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <Badge variant="outline" class="text-[10px] px-1.5 py-0 h-5 font-medium">{{ p.ref_id }}</Badge>
                <span class="truncate text-xs font-medium">{{ p.address || p.title || '—' }}</span>
              </div>
              <div v-if="p.city" class="text-[11px] text-muted-foreground truncate">{{ p.city }}</div>
            </div>
            <Check v-if="Number(selectedId) === Number(p.id)" class="w-4 h-4 text-foreground" />
          </button>

          <div v-if="!filteredProperties.length" class="text-center text-xs text-muted-foreground py-8">
            Keine Objekte gefunden.
          </div>
        </div>
      </div>

      <!-- Migration option -->
      <div v-if="hasChange" class="px-6 py-3 border-t border-border bg-muted/30">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Bisherige Aktivitäten</div>
        <div class="space-y-1.5">
          <label class="flex items-start gap-2 cursor-pointer text-sm">
            <input type="radio" v-model="migrate" value="keep" class="mt-0.5" />
            <div>
              <div class="font-medium">Beim alten Objekt lassen</div>
              <div class="text-[11px] text-muted-foreground">Vergangene E-Mails &amp; Aktivitäten bleiben beim alten Objekt. Ab jetzt gehen neue zum neuen Objekt.</div>
            </div>
          </label>
          <label class="flex items-start gap-2 cursor-pointer text-sm">
            <input type="radio" v-model="migrate" value="migrate" class="mt-0.5" />
            <div>
              <div class="font-medium">Alle bisherigen mit-verschieben</div>
              <div class="text-[11px] text-muted-foreground">Alle E-Mails und Aktivitäten dieser Konversation werden aufs neue Objekt umgehängt.</div>
            </div>
          </label>
        </div>
      </div>

      <DialogFooter class="px-6 py-3 border-t border-border">
        <Button variant="outline" @click="onCancel" :disabled="saving">Abbrechen</Button>
        <Button @click="onConfirm" :disabled="!hasChange || saving">
          <span v-if="saving">Speichere…</span>
          <span v-else>Zuordnung speichern</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
