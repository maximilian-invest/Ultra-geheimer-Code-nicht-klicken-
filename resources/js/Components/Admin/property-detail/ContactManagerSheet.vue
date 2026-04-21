<script setup>
import { ref, inject } from 'vue'
import { FileText, AlertTriangle, Pencil, ArrowRight } from 'lucide-vue-next'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import MissingAvaDialog from './MissingAvaDialog.vue'

const API = inject('API')
const toast = inject('toast', () => {})

const props = defineProps({
  open: { type: Boolean, default: false },
  propertyId: { type: [Number, String], required: true },
  availableTemplates: { type: Array, default: () => ['unterlagen', 'freitext'] },
  sourceEmailId: { type: [Number, String, null], default: null },
})

const emit = defineEmits(['update:open', 'draft-ready'])

const loading = ref(false)
const avaDialogOpen = ref(false)
const pendingDraft = ref(null)

async function pickTemplate(kind) {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=contact_property_manager', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        property_id: props.propertyId,
        template_kind: kind,
        source_email_id: props.sourceEmailId,
      }),
    })
    const d = await r.json()
    if (!d.success) {
      toast('Fehler: ' + (d.error || 'Unbekannt'))
      loading.value = false
      return
    }

    if (d.ava_missing) {
      pendingDraft.value = { ...d.draft, manager: d.manager }
      avaDialogOpen.value = true
      loading.value = false
      return
    }

    emit('draft-ready', { ...d.draft, manager: d.manager })
    emit('update:open', false)
  } catch (e) {
    toast('Fehler: ' + e.message)
  } finally {
    loading.value = false
  }
}

async function onAvaUpload(file) {
  const fd = new FormData()
  fd.append('property_id', String(props.propertyId))
  fd.append('file', file)

  try {
    const r = await fetch(API.value + '&action=upload_ava', { method: 'POST', body: fd })
    const d = await r.json()
    if (!d.success) {
      toast('Upload fehlgeschlagen: ' + (d.error || 'Unbekannt'))
      return
    }
    avaDialogOpen.value = false
    await pickTemplate('unterlagen')
  } catch (e) {
    toast('Fehler: ' + e.message)
  }
}

function onAvaSkip() {
  if (pendingDraft.value) {
    emit('draft-ready', pendingDraft.value)
    emit('update:open', false)
    pendingDraft.value = null
  }
}
</script>

<template>
  <Sheet :open="open" @update:open="emit('update:open', $event)">
    <SheetContent side="right" class="w-full sm:max-w-md bg-white dark:bg-zinc-950">
      <SheetHeader>
        <SheetTitle>Hausverwaltung kontaktieren</SheetTitle>
        <SheetDescription>Vorgefertigtes Anschreiben oder leeren Entwurf wählen.</SheetDescription>
      </SheetHeader>

      <div class="mt-6 space-y-3">
        <button v-if="availableTemplates.includes('unterlagen')"
                class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
                :disabled="loading" @click="pickTemplate('unterlagen')">
          <div class="w-10 h-10 rounded-lg bg-[#fff7ed] flex items-center justify-center shrink-0">
            <FileText class="w-5 h-5 text-[#EE7600]" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Unterlagen anfordern</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              Betriebskostenabrechnung, Nutzwertgutachten, Pläne, Energieausweis, Rücklagenstand u. a.
            </div>
            <div class="text-[10px] text-[#c2410c] mt-1.5 font-medium">Anhang: Alleinvermittlungsauftrag</div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button v-if="availableTemplates.includes('mieter_meldung')"
                class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
                :disabled="loading || !sourceEmailId" @click="pickTemplate('mieter_meldung')">
          <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
            <AlertTriangle class="w-5 h-5 text-amber-600" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Mieter-Meldung weiterleiten</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              KI fasst die Original-Mail zusammen und bereitet ein höfliches Anschreiben vor.
            </div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button v-if="availableTemplates.includes('freitext')"
                class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
                :disabled="loading" @click="pickTemplate('freitext')">
          <div class="w-10 h-10 rounded-lg bg-muted flex items-center justify-center shrink-0">
            <Pencil class="w-5 h-5 text-muted-foreground" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Freitext</div>
            <div class="text-xs text-muted-foreground mt-0.5">Leeren Entwurf starten — HV-Empfänger ist bereits eingesetzt.</div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>
      </div>

      <MissingAvaDialog
        v-model:open="avaDialogOpen"
        :property-id="propertyId"
        @upload="onAvaUpload"
        @skip="onAvaSkip"
      />
    </SheetContent>
  </Sheet>
</template>
