<script setup>
import { ref, computed } from 'vue'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Sparkles, RefreshCw, Loader2, ChevronDown } from 'lucide-vue-next'

const props = defineProps({
  draft: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  mode: { type: String, default: 'offen' },
  sendAccounts: { type: Array, default: () => [] },
  sendAccountId: { type: [String, Number], default: null },
  showEmailFields: { type: Boolean, default: false },
  stage: { type: Number, default: 1 },
})

const emit = defineEmits([
  'update:draft',
  'update:sendAccountId',
  'update:showEmailFields',
  'regenerate',
  'improve',
  'update:tone',
])

const toneModel = ref('standard')

const isNachfassen = computed(() => props.mode === 'nachfassen')

const headerLabel = computed(() =>
  isNachfassen.value ? 'KI-Nachfass-Entwurf' : 'KI-Entwurf'
)

const stageBadge = computed(() => {
  if (!isNachfassen.value) return null
  const s = props.stage
  if (s >= 2) return { label: `NF${s}`, variant: 'destructive' }
  return { label: 'NF1', variant: 'outline', class: 'border-amber-300 bg-amber-50 text-amber-700' }
})

const senderEmail = computed(() => {
  if (!props.sendAccounts?.length || !props.sendAccountId) return ''
  const acc = props.sendAccounts.find(a => String(a.id) === String(props.sendAccountId))
  return acc?.email || ''
})

function updateDraftField(field, value) {
  if (!props.draft) return
  emit('update:draft', { ...props.draft, [field]: value })
}

function onToneChange(val) {
  toneModel.value = val
  emit('update:tone', val)
}
</script>

<template>
  <div class="flex flex-col border-t border-border bg-background">
    <!-- LOADING STATE -->
    <div v-if="loading && !draft" class="flex items-center justify-center gap-2 py-8">
      <Loader2 class="w-4 h-4 animate-spin text-orange-500" />
      <span class="text-xs text-muted-foreground">KI-Entwurf wird generiert...</span>
    </div>

    <!-- NO DRAFT / RETRY STATE -->
    <div v-else-if="!loading && !draft" class="flex flex-col items-center justify-center gap-2 py-6">
      <span class="text-xs text-muted-foreground">Kein KI-Entwurf verfuegbar</span>
      <Button variant="outline" size="sm" class="h-7 text-xs gap-1.5" @click="emit('regenerate')">
        <RefreshCw class="w-3 h-3" />
        Erneut versuchen
      </Button>
    </div>

    <!-- DRAFT AVAILABLE -->
    <template v-else-if="draft">
      <!-- Header bar -->
      <div class="flex items-center gap-2 px-4 py-2 bg-muted/50 border-b border-border">
        <Sparkles class="w-3.5 h-3.5 text-orange-500 flex-shrink-0" />
        <span class="text-xs font-medium">{{ headerLabel }}</span>

        <Badge
          v-if="stageBadge"
          :variant="stageBadge.variant"
          :class="stageBadge.class"
          class="text-[10px] px-1.5 py-0"
        >
          {{ stageBadge.label }}
        </Badge>

        <div class="flex-1" />

        <button
          class="flex items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground transition-colors"
          @click="emit('update:showEmailFields', !showEmailFields)"
        >
          Von/An/Betr.
          <ChevronDown
            class="w-3 h-3 transition-transform"
            :class="showEmailFields ? 'rotate-180' : ''"
          />
        </button>

        <span v-if="senderEmail" class="text-[10px] text-muted-foreground ml-2">
          {{ senderEmail }}
        </span>
      </div>

      <!-- Email fields (collapsible) -->
      <div v-if="showEmailFields" class="px-4 py-2 space-y-1.5 border-b border-border bg-muted/20">
        <!-- Von -->
        <div class="flex items-center gap-2">
          <span class="text-[11px] text-muted-foreground w-8 flex-shrink-0">Von:</span>
          <Select
            v-if="sendAccounts.length > 1"
            :model-value="String(sendAccountId)"
            @update:model-value="emit('update:sendAccountId', $event)"
          >
            <SelectTrigger class="h-7 text-xs">
              <SelectValue placeholder="Konto waehlen" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="acc in sendAccounts"
                :key="acc.id"
                :value="String(acc.id)"
              >
                {{ acc.email }}
              </SelectItem>
            </SelectContent>
          </Select>
          <span v-else class="text-xs">{{ senderEmail || 'Kein Konto' }}</span>
        </div>

        <!-- An -->
        <div class="flex items-center gap-2">
          <span class="text-[11px] text-muted-foreground w-8 flex-shrink-0">An:</span>
          <Input
            :model-value="draft.to || ''"
            @update:model-value="updateDraftField('to', $event)"
            class="h-7 text-xs"
            placeholder="Empfaenger E-Mail"
          />
        </div>

        <!-- Betr. -->
        <div class="flex items-center gap-2">
          <span class="text-[11px] text-muted-foreground w-8 flex-shrink-0">Betr.:</span>
          <Input
            :model-value="draft.subject || ''"
            @update:model-value="updateDraftField('subject', $event)"
            class="h-7 text-xs"
            placeholder="Betreff"
          />
        </div>
      </div>

      <!-- Textarea -->
      <div class="px-4 pt-3 pb-2 flex-1">
        <textarea
          :value="draft.body || ''"
          @input="updateDraftField('body', $event.target.value)"
          class="ai-draft-textarea w-full min-h-[300px] text-[13px] leading-relaxed bg-transparent border-0 outline-none resize-vertical p-0 placeholder:text-muted-foreground/50"
          placeholder="KI-Entwurf erscheint hier..."
        />
      </div>

      <!-- Actions bar -->
      <div class="flex items-center gap-2 px-4 py-2 bg-muted/30 border-t border-border">
        <!-- Tone select -->
        <Select :model-value="toneModel" @update:model-value="onToneChange">
          <SelectTrigger class="w-[110px] h-7 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="brief">Kurz</SelectItem>
            <SelectItem value="standard">Standard</SelectItem>
            <SelectItem value="ausfuehrlich">Ausfuehrlich</SelectItem>
          </SelectContent>
        </Select>

        <!-- Regenerate -->
        <Button
          variant="outline"
          size="sm"
          class="h-7 text-xs gap-1.5"
          :disabled="loading"
          @click="emit('regenerate')"
        >
          <RefreshCw class="w-3 h-3" :class="loading ? 'animate-spin' : ''" />
          Neu generieren
        </Button>

        <!-- Improve with AI -->
        <Button
          variant="outline"
          size="sm"
          class="h-7 text-xs gap-1.5 border-purple-200 bg-purple-50 text-purple-700 hover:bg-purple-100 hover:text-purple-800"
          :disabled="loading"
          @click="emit('improve')"
        >
          <Sparkles class="w-3 h-3" />
          Mit KI verbessern
        </Button>

        <div class="flex-1" />

        <!-- Subject preview -->
        <span v-if="draft.subject" class="text-[10px] text-muted-foreground truncate max-w-[200px]">
          {{ draft.subject }}
        </span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ai-draft-textarea {
  transition: min-height 0.2s ease;
}
.ai-draft-textarea:focus {
  min-height: 420px;
}
</style>
