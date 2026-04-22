<script setup>
import { ref } from 'vue';

const props = defineProps({
  category: { type: String, required: true },
  label:    { type: String, required: true },
  icon:     { type: String, default: '📸' },
  modelValue: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

const fileInput = ref(null);

function openPicker() { fileInput.value?.click(); }

async function onFiles(e) {
  const files = Array.from(e.target.files || []);
  const newItems = [];
  for (const file of files) {
    const dataUrl = await readAsDataUrl(file);
    newItems.push({
      id: crypto.randomUUID(),
      dataUrl,
      filename: file.name,
      category: props.category,
      size: file.size,
    });
  }
  emit('update:modelValue', [...props.modelValue, ...newItems]);
  e.target.value = '';
}

function remove(id) {
  emit('update:modelValue', props.modelValue.filter(p => p.id !== id));
}

function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = reject;
    r.readAsDataURL(file);
  });
}
</script>

<template>
  <div class="bg-white border border-border rounded-xl p-4 space-y-3">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-lg">{{ icon }}</span>
        <span class="text-sm font-medium">{{ label }}</span>
      </div>
      <span class="text-[11px] text-muted-foreground">{{ modelValue.length }} Fotos</span>
    </div>

    <div v-if="modelValue.length > 0" class="grid grid-cols-3 gap-2">
      <div v-for="p in modelValue" :key="p.id" class="relative aspect-square rounded-md overflow-hidden bg-zinc-100">
        <img :src="p.dataUrl" class="w-full h-full object-cover" alt="" />
        <button @click="remove(p.id)" type="button"
                class="absolute top-1 right-1 bg-red-600 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center">×</button>
      </div>
    </div>

    <button @click="openPicker" type="button"
            class="w-full h-11 bg-zinc-100 border-2 border-dashed border-zinc-300 rounded-md text-sm font-medium text-zinc-600 hover:bg-zinc-50">
      + {{ modelValue.length === 0 ? 'Fotos aufnehmen' : 'Mehr hinzufügen' }}
    </button>

    <input ref="fileInput" type="file" accept="image/*" multiple capture="environment"
           class="hidden" @change="onFiles" />
  </div>
</template>
