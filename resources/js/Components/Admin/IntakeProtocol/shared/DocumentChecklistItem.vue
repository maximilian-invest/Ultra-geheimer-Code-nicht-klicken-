<script setup>
defineProps({
  docKey: { type: String, required: true },
  label: { type: String, required: true },
  modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

function emitVal(v) { emit('update:modelValue', v); }
</script>

<template>
  <div :class="[
    'rounded-lg p-3 flex items-center gap-2',
    modelValue === 'available' ? 'bg-green-50' :
    modelValue === 'missing' ? 'bg-red-50' :
    modelValue === 'na' ? 'bg-zinc-100' : 'bg-zinc-50'
  ]">
    <div class="flex-1 text-sm" :class="modelValue === 'available' ? 'text-green-900 font-medium' : modelValue === 'missing' ? 'text-red-900 font-medium' : ''">
      {{ label }}
    </div>
    <div class="flex gap-1">
      <button type="button" @click="emitVal('available')"
              :class="[
                'px-2 py-1 rounded-md text-[10px] font-semibold',
                modelValue === 'available' ? 'bg-green-600 text-white' : 'bg-transparent border border-border text-muted-foreground'
              ]">✓ Da</button>
      <button type="button" @click="emitVal('missing')"
              :class="[
                'px-2 py-1 rounded-md text-[10px] font-semibold',
                modelValue === 'missing' ? 'bg-red-600 text-white' : 'bg-transparent border border-border text-muted-foreground'
              ]">✗ Fehlt</button>
      <button type="button" @click="emitVal('na')"
              :class="[
                'px-2 py-1 rounded-md text-[10px] font-semibold',
                modelValue === 'na' ? 'bg-zinc-600 text-white' : 'bg-transparent border border-border text-muted-foreground'
              ]">N/A</button>
    </div>
  </div>
</template>
