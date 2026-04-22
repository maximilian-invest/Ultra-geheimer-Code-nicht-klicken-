<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, X } from 'lucide-vue-next';

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
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center justify-between">
        <span class="flex items-center gap-2">
          <span class="text-lg">{{ icon }}</span>
          <span>{{ label }}</span>
        </span>
        <Badge variant="secondary">{{ modelValue.length }}</Badge>
      </CardTitle>
    </CardHeader>
    <CardContent class="space-y-3">
      <div v-if="modelValue.length > 0" class="grid grid-cols-3 gap-2">
        <div v-for="p in modelValue" :key="p.id" class="relative aspect-square rounded-md overflow-hidden bg-muted">
          <img :src="p.dataUrl" class="w-full h-full object-cover" alt="" />
          <Button
            variant="destructive"
            size="icon-sm"
            class="absolute top-1 right-1 rounded-full size-6"
            @click="remove(p.id)"
            type="button"
            aria-label="Entfernen"
          >
            <X class="h-3.5 w-3.5" />
          </Button>
        </div>
      </div>

      <Button
        variant="outline"
        class="w-full border-dashed"
        @click="openPicker"
      >
        <Plus class="h-4 w-4" />
        {{ modelValue.length === 0 ? 'Fotos aufnehmen' : 'Mehr hinzufügen' }}
      </Button>

      <input ref="fileInput" type="file" accept="image/*" multiple capture="environment"
             class="hidden" @change="onFiles" />
    </CardContent>
  </Card>
</template>
