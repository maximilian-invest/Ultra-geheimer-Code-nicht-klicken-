<script setup>
import { computed } from 'vue'
import { Home, Globe, Users, Lock } from 'lucide-vue-next'
import { visForField } from '@/utils/propertyFieldExports.js'

const props = defineProps({
  field: { type: String, required: true },
})

const ICON_MAP = {
  i: Home,    // Immoji
  w: Globe,   // Website
  p: Users,   // Kundenportal
  l: Lock,    // Intern
}

const data = computed(() => visForField(props.field))
</script>

<template>
  <span
    v-if="data.icons.length"
    class="inline-flex items-center gap-0.5 cursor-help"
    :title="data.tooltip"
  >
    <component
      v-for="t in data.icons"
      :key="t"
      :is="ICON_MAP[t]"
      class="w-3 h-3 text-orange-400 shrink-0"
    />
  </span>
</template>
