<script setup>
import { computed } from 'vue'
import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

const props = defineProps({
  match: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle'])

const area = computed(() => props.match.area ? props.match.area + ' m²' : null)
const rooms = computed(() => props.match.rooms ? props.match.rooms + ' Zi.' : null)
const price = computed(() => {
  if (!props.match.price) return 'Preis auf Anfrage'
  return '€ ' + Number(props.match.price).toLocaleString('de-AT')
})

const scoreBg = computed(() => {
  if (props.match.score >= 80) return 'bg-emerald-50 text-emerald-700 border-emerald-200'
  if (props.match.score >= 60) return 'bg-amber-50 text-amber-700 border-amber-200'
  return 'bg-muted text-muted-foreground border-border'
})
</script>

<template>
  <Card
    class="relative cursor-pointer transition-all duration-150 hover:border-violet-300"
    :class="selected
      ? 'border-violet-500 shadow-[0_0_0_1px_hsl(263_70%_58%),0_4px_16px_hsl(263_70%_58%/0.1)]'
      : 'border-border'"
    @click="emit('toggle', match.property_id)"
  >
    <div class="p-4 flex gap-4">
      <!-- Image -->
      <div class="w-20 h-16 rounded-md bg-muted flex-shrink-0 overflow-hidden flex items-center justify-center">
        <img v-if="match.image_url" :src="match.image_url" class="w-full h-full object-cover" />
        <span v-else class="text-2xl text-muted-foreground/50">🏠</span>
      </div>

      <!-- Info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="text-sm font-semibold truncate">{{ match.title }}</span>
          <Badge variant="outline" :class="scoreBg" class="text-[10px] px-1.5 py-0 font-bold flex-shrink-0">
            {{ match.score }}%
          </Badge>
        </div>
        <p class="text-xs text-muted-foreground truncate">{{ match.address }} — {{ price }}</p>
        <div class="flex gap-1.5 mt-2 flex-wrap">
          <Badge v-if="area" variant="secondary" class="text-[10px] px-1.5 py-0">{{ area }}</Badge>
          <Badge v-if="rooms" variant="secondary" class="text-[10px] px-1.5 py-0">{{ rooms }}</Badge>
          <Badge v-if="match.object_type" variant="secondary" class="text-[10px] px-1.5 py-0">{{ match.object_type }}</Badge>
          <Badge v-if="match.has_expose" variant="secondary" class="text-[10px] px-1.5 py-0 text-violet-600">Exposé</Badge>
        </div>
        <p v-if="match.match_reason" class="text-[11px] text-violet-600 mt-1.5">{{ match.match_reason }}</p>
      </div>

      <!-- Checkbox -->
      <div class="flex-shrink-0 flex items-start pt-0.5">
        <div
          class="w-5 h-5 rounded border-2 flex items-center justify-center transition-all"
          :class="selected
            ? 'bg-gradient-to-br from-violet-500 to-cyan-500 border-violet-500'
            : 'border-muted-foreground/30'"
        >
          <svg v-if="selected" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
      </div>
    </div>
  </Card>
</template>
