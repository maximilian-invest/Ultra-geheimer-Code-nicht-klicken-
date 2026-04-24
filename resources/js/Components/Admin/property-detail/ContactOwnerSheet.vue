<script setup>
import { ref, computed } from 'vue'
import { FileText, TrendingUp, Info, Pencil, ArrowRight } from 'lucide-vue-next'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet'

const props = defineProps({
  open: { type: Boolean, default: false },
  property: { type: Object, required: true },
})

const emit = defineEmits(['update:open', 'draft-ready'])

const loading = ref(false)

const ownerName = computed(() => (props.property?.owner_name || '').trim() || 'Eigentümer:in')
const ownerEmail = computed(() => (props.property?.owner_email || '').trim())
const addressLine = computed(() => {
  const parts = [props.property?.address, props.property?.zip, props.property?.city].filter(Boolean)
  return parts.join(' ').trim() || 'Ihr Objekt'
})
const refId = computed(() => props.property?.ref_id || '')

// Hoefliche Anrede — nutzt die erste Komponente wenn kein Vorname/Nachname zu
// unterscheiden ist, sonst "Herr/Frau Nachname". Sehr defensiv.
function buildAnrede() {
  const n = ownerName.value
  if (!n || n === 'Eigentümer:in') return 'Sehr geehrte Damen und Herren'
  // Nur erste Zeile nehmen (falls Mehrzeiler), sonst nur ein Nachname-Fallback
  const first = String(n).split('\n')[0].trim()
  // Keine intelligente Geschlechts-Detection — nutzt einen generischen Respekt-Gruß.
  return `Sehr geehrte:r ${first}`
}

function buildSignoff() {
  return '\n\nMit besten Grüßen\n'
}

function templateExpose() {
  return {
    subject: `Aktuelles Exposé — ${addressLine.value}${refId.value ? ' (' + refId.value + ')' : ''}`,
    body:
`${buildAnrede()},

anbei darf ich Ihnen das aktuelle Exposé zu Ihrem Objekt ${addressLine.value} zur Durchsicht zusenden. Das Dokument spiegelt den aktuellen Stand der Vermarktung wider — Fotos, Texte und Daten sind freigegeben zur Veröffentlichung.

Falls Ihnen am Inhalt etwas fehlt oder Sie etwas anders gestaltet wünschen, geben Sie mir bitte kurz Bescheid. Ich passe gerne an.${buildSignoff()}`,
  }
}

function templateVermarktung() {
  return {
    subject: `Vermarktungs-Update — ${addressLine.value}`,
    body:
`${buildAnrede()},

ein kurzes Update zum Vermarktungsstand Ihres Objekts ${addressLine.value}:

• Anfragen: (wird manuell ergänzt)
• Besichtigungen: (wird manuell ergänzt)
• Feedback der Interessent:innen: (wird manuell ergänzt)
• Nächste Schritte: (wird manuell ergänzt)

Sollten Sie Fragen haben oder Details besprechen wollen, melden Sie sich jederzeit gerne telefonisch.${buildSignoff()}`,
  }
}

function templateBesichtigungFeedback() {
  return {
    subject: `Rückmeldung nach der Besichtigung — ${addressLine.value}`,
    body:
`${buildAnrede()},

herzlichen Dank, dass Sie die heutige Besichtigung ermöglicht haben. Die Interessent:innen haben einen guten Eindruck von ${addressLine.value} mitgenommen.

Eine erste Rückmeldung der Interessent:innen kann ich Ihnen in den kommenden Tagen weiterleiten, sobald wir das Feedback gebündelt haben.

Falls Ihnen seitens der Besichtigung noch etwas aufgefallen ist oder Sie Anmerkungen haben, lassen Sie es mich wissen.${buildSignoff()}`,
  }
}

function templateFreitext() {
  return {
    subject: `${addressLine.value}`,
    body: `${buildAnrede()},\n\n${buildSignoff()}`,
  }
}

function pickTemplate(kind) {
  if (!ownerEmail.value) {
    // Kein Empfaenger — an die UI signalisieren und abbrechen. Der Kontakt-
    // Kaestchen-Edit-Flow in OverviewTab bleibt die einzige Stelle an der
    // der Eigentuemer anlegbar ist, hier kein Zweitpfad.
    emit('update:open', false)
    alert('Fuer diese Property ist keine Eigentuemer-E-Mail hinterlegt. Bitte im Block "Eigentümer" hinterlegen.')
    return
  }
  loading.value = true
  try {
    const tpl =
      kind === 'expose' ? templateExpose()
      : kind === 'vermarktung' ? templateVermarktung()
      : kind === 'besichtigung' ? templateBesichtigungFeedback()
      : templateFreitext()

    emit('draft-ready', {
      property_id: props.property?.id,
      owner: {
        name: ownerName.value,
        email: ownerEmail.value,
      },
      to: ownerEmail.value,
      subject: tpl.subject,
      body: tpl.body,
    })
    emit('update:open', false)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Sheet :open="open" @update:open="emit('update:open', $event)">
    <SheetContent side="right" class="w-full sm:max-w-md bg-white dark:bg-zinc-950">
      <SheetHeader>
        <SheetTitle>Eigentümer kontaktieren</SheetTitle>
        <SheetDescription>
          An {{ ownerName }}<span v-if="ownerEmail"> · {{ ownerEmail }}</span>.
          Vorgefertigter Entwurf oder leere E-Mail — du kannst vor dem Senden alles anpassen.
        </SheetDescription>
      </SheetHeader>

      <div class="mt-6 space-y-3">
        <button
          class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
          :disabled="loading"
          @click="pickTemplate('expose')"
        >
          <div class="w-10 h-10 rounded-lg bg-[#fff7ed] flex items-center justify-center shrink-0">
            <FileText class="w-5 h-5 text-[#EE7600]" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Exposé zusenden</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              Kurzer Begleittext zur Exposé-Freigabe. PDF kannst du im nächsten Schritt anhängen.
            </div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button
          class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
          :disabled="loading"
          @click="pickTemplate('vermarktung')"
        >
          <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
            <TrendingUp class="w-5 h-5 text-emerald-600" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Vermarktungs-Update</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              Stichpunkte zu Anfragen, Besichtigungen und nächsten Schritten — du füllst die Zahlen.
            </div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button
          class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
          :disabled="loading"
          @click="pickTemplate('besichtigung')"
        >
          <div class="w-10 h-10 rounded-lg bg-sky-50 flex items-center justify-center shrink-0">
            <Info class="w-5 h-5 text-sky-600" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Nach Besichtigung / Feedback</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              Dank für die Besichtigung, Feedback-Ausblick, offene Fragen abholen.
            </div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>

        <button
          class="w-full flex items-start gap-3 p-4 rounded-xl border border-border/60 hover:border-border hover:bg-accent/30 transition-colors text-left disabled:opacity-50"
          :disabled="loading"
          @click="pickTemplate('freitext')"
        >
          <div class="w-10 h-10 rounded-lg bg-muted flex items-center justify-center shrink-0">
            <Pencil class="w-5 h-5 text-muted-foreground" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm">Freitext</div>
            <div class="text-xs text-muted-foreground mt-0.5">
              Leere E-Mail mit vorausgefülltem Empfänger und Anrede.
            </div>
          </div>
          <ArrowRight class="w-4 h-4 text-muted-foreground shrink-0 mt-2" />
        </button>
      </div>
    </SheetContent>
  </Sheet>
</template>
