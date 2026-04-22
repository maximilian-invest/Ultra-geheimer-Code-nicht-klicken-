<script setup>
import { computed } from 'vue';
import PhotoCategoryUploader from '../shared/PhotoCategoryUploader.vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps({
  form: { type: Object, required: true },
});

function makeCat(cat) {
  return computed({
    get: () => (props.form.photos || []).filter(p => p.category === cat),
    set: (v) => {
      const others = (props.form.photos || []).filter(p => p.category !== cat);
      props.form.photos = [...others, ...v];
    },
  });
}

const exterior  = makeCat('exterior');
const interior  = makeCat('interior');
const floorPlan = makeCat('floor_plan');
const documents = makeCat('documents');

const totalPhotos = computed(() => (props.form.photos || []).length);
</script>

<template>
  <div class="p-4 space-y-4">

    <div class="flex items-center justify-between px-1">
      <span class="text-xs text-muted-foreground">Optional, kann später ergänzt werden</span>
      <Badge variant="secondary">{{ totalPhotos }} Fotos gesamt</Badge>
    </div>

    <PhotoCategoryUploader category="exterior"   label="Außenansichten" icon="🏠"
                           :model-value="exterior"  @update:model-value="exterior = $event" />
    <PhotoCategoryUploader category="interior"   label="Innenräume"     icon="🛋️"
                           :model-value="interior"  @update:model-value="interior = $event" />
    <PhotoCategoryUploader category="floor_plan" label="Grundrisse"     icon="📐"
                           :model-value="floorPlan" @update:model-value="floorPlan = $event" />
    <PhotoCategoryUploader category="documents"  label="Dokumente"      icon="📄"
                           :model-value="documents" @update:model-value="documents = $event" />

    <p class="text-[11px] text-muted-foreground px-2">
      Fotos werden nach dem Absenden im Hintergrund komprimiert und dem Objekt zugeordnet.
    </p>
  </div>
</template>
