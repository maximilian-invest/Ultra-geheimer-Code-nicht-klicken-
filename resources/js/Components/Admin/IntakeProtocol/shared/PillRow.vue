<script setup>
defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },
  multiline: { type: Boolean, default: true },
});
defineEmits(['update:modelValue']);

function normalize(opt) {
  if (typeof opt === 'string') return { value: opt, label: opt };
  return opt;
}
</script>

<template>
  <div :class="['flex gap-1.5', multiline ? 'flex-wrap' : '']">
    <button
      v-for="(opt, i) in options" :key="i"
      type="button"
      @click="$emit('update:modelValue', normalize(opt).value)"
      :class="[
        'px-3 py-1.5 rounded-full text-[12px] font-medium transition-colors',
        modelValue === normalize(opt).value
          ? 'bg-[#EE7600] text-white'
          : 'bg-white border border-border text-foreground hover:border-[#EE7600]/40'
      ]"
    >{{ normalize(opt).label }}</button>
  </div>
</template>
