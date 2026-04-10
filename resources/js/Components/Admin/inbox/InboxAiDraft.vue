<script setup>
import { ref, computed, watch } from 'vue'
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
import { Sparkles, RefreshCw, Loader2, ChevronDown, ChevronUp, Send, Paperclip, CalendarDays, CheckCircle } from 'lucide-vue-next'

const props = defineProps({
  draft: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  mode: { type: String, default: 'offen' },
  sendAccounts: { type: Array, default: () => [] },
  sendAccountId: { type: [String, Number], default: null },
  showEmailFields: { type: Boolean, default: false },
  stage: { type: Number, default: 1 },
  sending: { type: Boolean, default: false },
  canSend: { type: Boolean, default: false },
  attachmentCount: { type: Number, default: 0 },
  matchPropertyCount: { type: Number, default: 0 },
  showCalendar: { type: Boolean, default: false },
  files: { type: Array, default: () => [] },
  filesLoading: { type: Boolean, default: false },
  selectedFileIds: { type: Array, default: () => [] },
})

const emit = defineEmits([
  'update:draft',
  'update:sendAccountId',
  'update:showEmailFields',
  'regenerate',
  'improve',
  'update:tone',
  'send',
  'markHandled',
  'toggleAttach',
  'toggleCalendar',
  'toggleFile',
])

const toneModel = ref('standard')
const collapsed = ref(true)
const showFiles = ref(false)

// Auto-open file panel and expand draft when match files arrive
watch(() => props.selectedFileIds, (newIds, oldIds) => {
  if (newIds.length > 0 && (!oldIds || oldIds.length === 0)) {
    showFiles.value = true
    collapsed.value = false
  }
})

const isNachfassen = computed(() => props.mode === 'nachfassen')
const sendLabel = computed(() => isNachfassen.value ? 'Nachfassen' : 'Senden')

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
  <div class="flex-shrink-0">
    <!-- LOADING STATE -->
    <div v-if="loading && !draft" class="mx-4 mb-4 rounded-2xl shadow-lg overflow-hidden" style="background: linear-gradient(135deg, #f97316, #ea580c)">
      <div class="flex items-center justify-center gap-2 px-5 py-3.5">
        <Loader2 class="w-4 h-4 animate-spin text-white" />
        <span class="text-[13px] text-white font-medium">KI-Entwurf wird generiert...</span>
      </div>
    </div>

    <!-- NO DRAFT / RETRY STATE -->
    <div v-else-if="!loading && !draft" class="mx-4 mb-4 rounded-2xl shadow-lg overflow-hidden border border-zinc-100 bg-white">
      <div class="flex flex-col items-center justify-center gap-2 py-5">
        <span class="text-xs text-muted-foreground">Kein KI-Entwurf verfuegbar</span>
        <Button variant="outline" size="sm" class="h-7 text-xs gap-1.5" @click="emit('regenerate')">
          <RefreshCw class="w-3 h-3" />
          Erneut versuchen
        </Button>
      </div>
    </div>

    <!-- DRAFT AVAILABLE -->
    <template v-else-if="draft">
      <!-- ===== COLLAPSED: Floating orange pill ===== -->
      <div
        v-if="collapsed"
        class="mx-4 mb-4 rounded-2xl cursor-pointer shadow-lg overflow-hidden transition-all duration-200 hover:shadow-xl hover:scale-[1.01]"
        style="background: linear-gradient(135deg, #f97316, #ea580c)"
        @click="collapsed = false"
      >
        <div class="flex items-center gap-3 px-5 py-3.5 text-white">
          <Sparkles class="w-4 h-4 flex-shrink-0" />
          <span class="text-[13px] font-medium flex-1">{{ headerLabel }} antworten...</span>
          <span
            v-if="matchPropertyCount > 0"
            class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-white/20 text-white border border-white/30"
          >
            inkl. {{ matchPropertyCount }} {{ matchPropertyCount === 1 ? 'Objekt' : 'Objekte' }}
          </span>
          <Badge
            v-if="stageBadge"
            :variant="stageBadge.variant"
            class="text-[10px] px-1.5 py-0 bg-white/20 text-white border-white/30"
          >
            {{ stageBadge.label }}
          </Badge>
          <span class="text-[11px] opacity-70">Klicken zum Oeffnen</span>
        </div>
      </div>

      <!-- ===== EXPANDED: Full draft editor ===== -->
      <div
        v-else
        class="mx-4 mb-4 rounded-2xl shadow-lg overflow-hidden border border-orange-200"
      >
        <!-- Orange gradient header -->
        <div
          class="flex items-center gap-2 px-4 py-2.5 cursor-pointer select-none"
          style="background: linear-gradient(135deg, #f97316, #ea580c)"
          @click="collapsed = true"
        >
          <Sparkles class="w-3.5 h-3.5 text-white flex-shrink-0" />
          <span class="text-xs font-medium text-white">{{ headerLabel }}</span>
          <span
            v-if="matchPropertyCount > 0"
            class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-gradient-to-r from-violet-500/10 to-cyan-500/10 text-violet-700 border border-violet-200"
          >
            inkl. {{ matchPropertyCount }} {{ matchPropertyCount === 1 ? 'Objekt' : 'Objekte' }}
          </span>

          <Badge
            v-if="stageBadge"
            :variant="stageBadge.variant"
            class="text-[10px] px-1.5 py-0 bg-white/20 text-white border-white/30"
          >
            {{ stageBadge.label }}
          </Badge>

          <div class="flex-1" />

          <button
            class="flex items-center gap-1 text-[10px] text-white/80 hover:text-white transition-colors"
            @click.stop="emit('update:showEmailFields', !showEmailFields)"
          >
            Von/An/Betr.
            <ChevronDown
              class="w-3 h-3 transition-transform"
              :class="showEmailFields ? 'rotate-180' : ''"
            />
          </button>

          <span v-if="senderEmail" class="text-[10px] text-white/70 ml-2">
            {{ senderEmail }}
          </span>
          <ChevronUp class="w-3.5 h-3.5 text-white/80" />
        </div>

        <!-- White body area -->
        <div class="bg-white">
          <!-- Email fields (collapsible) -->
          <div v-if="showEmailFields" class="px-4 py-2 space-y-1.5 border-b border-zinc-100 bg-zinc-50/50">
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
                    {{ acc.email_address || acc.email }}
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
          <div class="px-4 pt-3 pb-2">
            <textarea
              :value="draft.body || ''"
              @input="updateDraftField('body', $event.target.value)"
              class="ai-draft-textarea w-full min-h-[120px] md:min-h-[180px] text-[13px] leading-relaxed bg-transparent border-0 outline-none resize-vertical p-0 placeholder:text-muted-foreground/50"
              placeholder="KI-Entwurf erscheint hier..."
            />
          </div>

          <!-- Attachment panel -->
          <div v-if="showFiles" class="px-4 py-2 border-t border-zinc-100 bg-zinc-50/50 max-h-[180px] overflow-y-auto">
            <div v-if="filesLoading" class="text-[11px] text-muted-foreground py-2">Dateien laden...</div>
            <div v-else-if="!files.length" class="text-[11px] text-muted-foreground py-2">Keine Dateien verfügbar</div>
            <div v-else class="space-y-0.5">
              <template v-for="(f, fi) in files" :key="f.id">
                <!-- Property group header for cross-match files -->
                <div
                  v-if="f._matchProperty && (fi === 0 || files[fi - 1]?._matchProperty !== f._matchProperty)"
                  class="text-[10px] font-medium text-violet-600 pt-1.5 pb-0.5 px-2 flex items-center gap-1"
                >
                  <span class="text-[9px]">&#10022;</span>
                  {{ f._matchProperty }}
                </div>
                <label
                  class="flex items-center gap-2 px-2 py-1 rounded hover:bg-zinc-100 cursor-pointer text-[11px]"
                  :class="f._matchProperty ? 'ml-2' : ''"
                >
                  <input
                    type="checkbox"
                    :checked="selectedFileIds.includes(f.id)"
                    @change="emit('toggleFile', f.id)"
                    class="rounded border-zinc-300 text-primary w-3.5 h-3.5"
                  />
                  <Paperclip class="w-3 h-3 text-muted-foreground flex-shrink-0" />
                  <span class="truncate">{{ f.label || f.filename || f.original_name }}</span>
                </label>
              </template>
            </div>
          </div>

          <!-- Main action bar -->
          <div class="flex items-center gap-1.5 px-4 py-2 bg-zinc-50/80 border-t border-zinc-100 rounded-b-2xl flex-wrap">
            <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" @click="showFiles = !showFiles">
              <Paperclip class="w-3 h-3" />
              <span v-if="attachmentCount" class="inline-flex items-center justify-center rounded-full bg-primary text-primary-foreground text-[9px] font-medium w-3.5 h-3.5 leading-none">{{ attachmentCount }}</span>
            </Button>
            <Button variant="outline" size="icon" class="w-7 h-7" :class="showCalendar ? 'bg-accent' : ''" @click="emit('toggleCalendar')">
              <CalendarDays class="w-3 h-3" />
            </Button>
            <div class="w-px h-5 bg-zinc-200 mx-0.5" />
            <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1" :disabled="loading" @click="emit('regenerate')">
              <RefreshCw class="w-3 h-3" :class="loading ? 'animate-spin' : ''" />
              Neu
            </Button>
            <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1 border-purple-200 bg-purple-50 text-purple-700 hover:bg-purple-100" :disabled="loading" @click="emit('improve')">
              <Sparkles class="w-3 h-3" />
              Wording
            </Button>
            <div class="flex-1" />
            <Button variant="outline" size="sm" class="h-7 text-[11px] gap-1 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 border-emerald-200" @click="emit('markHandled')">
              <CheckCircle class="w-3 h-3" />
              Erledigt
            </Button>
            <Button size="sm" class="h-7 text-[11px] gap-1" :disabled="!canSend || sending" @click="emit('send')">
              <Loader2 v-if="sending" class="w-3 h-3 animate-spin" />
              <Send v-else class="w-3 h-3" />
              {{ sendLabel }}
            </Button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ai-draft-textarea {
  transition: min-height 0.2s ease;
}
.ai-draft-textarea:focus {
  min-height: 280px;
}
</style>
