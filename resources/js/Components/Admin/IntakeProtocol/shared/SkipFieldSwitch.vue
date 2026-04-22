<script setup>
defineProps({
  modelValue: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>

<template>
  <button
    type="button"
    @click="$emit('update:modelValue', !modelValue)"
    :class="[
      'text-[11px] px-2 py-0.5 rounded transition-colors',
      modelValue
        ? 'bg-[#EE7600] text-white'
        : 'bg-orange-50 text-[#EE7600] hover:bg-orange-100'
    ]"
  >
    <span v-if="modelValue">✓ später ergänzen</span>
    <span v-else>↷ später</span>
  </button>
</template>
