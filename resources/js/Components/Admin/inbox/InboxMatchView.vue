<script setup>
import { ref, computed, onMounted, inject } from 'vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import InboxMatchCard from './InboxMatchCard.vue'

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['dismiss', 'generateDraft'])

const API = inject('API')
const loading = ref(true)
const generating = ref(false)
const criteria = ref(null)
const matches = ref([])
const selectedIds = ref(new Set())

onMounted(async () => {
  await loadMatches()
})

async function loadMatches() {
  loading.value = true
  try {
    const r = await fetch(API.value + '&action=match_list&conversation_id=' + props.item.id)
    const d = await r.json()
    criteria.value = d.criteria
    matches.value = d.matches || []
    // Pre-select high-score matches
    matches.value.forEach(m => {
      if (m.score >= 70) selectedIds.value.add(m.property_id)
    })
  } catch (e) {
    console.error('Failed to load matches', e)
  } finally {
    loading.value = false
  }
}

function toggleSelection(propertyId) {
  if (selectedIds.value.has(propertyId)) {
    selectedIds.value.delete(propertyId)
  } else {
    selectedIds.value.add(propertyId)
  }
  // Force reactivity
  selectedIds.value = new Set(selectedIds.value)
}

const selectedCount = computed(() => selectedIds.value.size)

const criteriaPills = computed(() => {
  if (!criteria.value) return []
  const pills = []
  if (criteria.value.object_types?.length) pills.push(...criteria.value.object_types)
  if (criteria.value.min_area) pills.push('ab ' + criteria.value.min_area + ' m²')
  if (criteria.value.max_price) pills.push('bis € ' + Number(criteria.value.max_price).toLocaleString('de-AT'))
  if (criteria.value.locations?.length) pills.push(...criteria.value.locations)
  if (criteria.value.features?.length) pills.push(...criteria.value.features)
  return pills
})

async function generateDraft() {
  if (selectedCount.value === 0) return
  generating.value = true
  try {
    const r = await fetch(API.value + '&action=match_generate_draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        conversation_id: props.item.id,
        property_ids: [...selectedIds.value],
      }),
    })
    const d = await r.json()
    if (d.error) {
      console.error('Draft generation failed:', d.error)
      return
    }
    emit('generateDraft', {
      draft_body: d.draft_body,
      draft_subject: d.draft_subject,
      draft_to: d.draft_to,
      file_ids: d.file_ids || [],
    })
  } catch (e) {
    console.error('Failed to generate draft', e)
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="flex flex-col h-full bg-background">
    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-cyan-500 flex items-center justify-center">
          <span class="text-white text-sm font-bold">✦</span>
        </div>
        <div>
          <h2 class="text-base font-semibold">Property Matching</h2>
          <p class="text-xs text-muted-foreground">{{ item.stakeholder || item.from_name }}</p>
        </div>
      </div>
      <Button variant="ghost" size="sm" @click="emit('dismiss')">
        Überspringen
      </Button>
    </div>

    <!-- Criteria pills -->
    <div v-if="criteriaPills.length" class="px-6 py-3 border-b flex items-center gap-2 flex-wrap">
      <span class="text-xs text-muted-foreground font-medium">Suchkriterien:</span>
      <Badge v-for="pill in criteriaPills" :key="pill" variant="outline" class="text-xs">
        {{ pill }}
      </Badge>
    </div>

    <!-- Match cards -->
    <ScrollArea class="flex-1">
      <div class="p-6 space-y-3">
        <div v-if="loading" class="flex items-center justify-center py-20">
          <div class="animate-spin w-6 h-6 border-2 border-violet-500 border-t-transparent rounded-full" />
        </div>

        <template v-else-if="matches.length">
          <InboxMatchCard
            v-for="m in matches"
            :key="m.property_id"
            :match="m"
            :selected="selectedIds.has(m.property_id)"
            @toggle="toggleSelection"
          />
        </template>

        <div v-else class="text-center py-20 text-muted-foreground text-sm">
          Keine passenden Objekte gefunden.
        </div>
      </div>
    </ScrollArea>

    <!-- Bottom bar -->
    <div class="px-6 py-4 border-t flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-sm text-muted-foreground">
          <strong class="text-violet-600">{{ selectedCount }}</strong> ausgewählt
        </span>
      </div>
      <Button
        :disabled="selectedCount === 0 || generating"
        class="bg-gradient-to-r from-violet-500 to-cyan-500 text-white hover:opacity-90 disabled:opacity-50"
        @click="generateDraft"
      >
        <span class="mr-1.5">✦</span>
        {{ generating ? 'Generiere...' : 'Entwurf generieren' }}
      </Button>
    </div>
  </div>
</template>
