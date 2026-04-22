<script setup>
import { computed, toRef } from 'vue';
import PillRow from '../shared/PillRow.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { useSubtypes } from '../composables/useSubtypes';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const props = defineProps({
  form: { type: Object, required: true },
  isSkipped: Function,
  markSkipped: Function,
  unmarkSkipped: Function,
});

const getSubtypes = useSubtypes(toRef(props.form, 'object_type'));
const subtypes = computed(() => getSubtypes());

const TYPE_TILES = [
  { key: 'Haus',       icon: '🏠', label: 'Haus' },
  { key: 'Wohnung',    icon: '🏢', label: 'Wohnung' },
  { key: 'Grundstück', icon: '🌱', label: 'Grundstück' },
  { key: 'Gewerbe',    icon: '🏭', label: 'Gewerbe' },
];

function selectType(key) {
  props.form.object_type = key;
  props.form.object_subtype = '';
}

const refIdSuggestion = computed(() => {
  const mt = (props.form.marketing_type || '').substring(0, 3).toLowerCase();
  const typ = (props.form.object_type || '').substring(0, 3);
  const name = ((props.form.owner?.name || '').split(' ').pop() || 'xx').substring(0, 3);
  if (!mt || !typ) return '';
  return `${mt.charAt(0).toUpperCase()}${mt.substring(1)}-${typ}-${name}-01`;
});

const skippedRefId = computed({
  get: () => props.isSkipped('ref_id'),
  set: (v) => v ? props.markSkipped('ref_id') : props.unmarkSkipped('ref_id'),
});
</script>

<template>
  <div class="p-4 space-y-4">

    <!-- Objekttyp -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">
          Hauptkategorie <span class="text-red-500">*</span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-4 gap-2">
          <button
            v-for="t in TYPE_TILES" :key="t.key"
            type="button"
            @click="selectType(t.key)"
            :class="[
              'rounded-xl p-3 text-center transition-colors border',
              form.object_type === t.key
                ? 'bg-primary/5 border-2 border-primary shadow-sm'
                : 'bg-white border-border hover:border-primary/30'
            ]"
          >
            <div class="text-2xl">{{ t.icon }}</div>
            <div class="text-[11px] font-medium mt-1">{{ t.label }}</div>
          </button>
        </div>
      </CardContent>
    </Card>

    <!-- Subtyp -->
    <Card v-if="subtypes.length">
      <CardHeader class="pb-3">
        <CardTitle class="text-base">Bauweise / Subtyp</CardTitle>
      </CardHeader>
      <CardContent>
        <PillRow v-model="form.object_subtype" :options="subtypes" />
      </CardContent>
    </Card>

    <!-- Vermarktung -->
    <Card>
      <CardHeader class="pb-3">
        <CardTitle class="text-base">
          Vermarktungsart <span class="text-red-500">*</span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <PillRow v-model="form.marketing_type" :options="[
          {value: 'kauf', label: 'Kauf'},
          {value: 'miete', label: 'Miete'},
          {value: 'pacht', label: 'Pacht'},
        ]" />
      </CardContent>
    </Card>

    <!-- Ref-ID -->
    <Card>
      <CardHeader class="pb-3">
        <div class="flex items-center justify-between">
          <CardTitle class="text-base">Ref-ID</CardTitle>
          <SkipFieldSwitch v-model="skippedRefId" />
        </div>
      </CardHeader>
      <CardContent class="space-y-2">
        <Input
          v-model="form.ref_id"
          :placeholder="refIdSuggestion || 'z.B. Kau-Woh-Mus-01'"
          class="h-11 font-mono"
        />
        <p class="text-[11px] text-muted-foreground">
          Leer lassen für Auto-Vorschlag: <code>{{ refIdSuggestion || 'wird nach Eigentümer-Auswahl vorgeschlagen' }}</code>
        </p>
      </CardContent>
    </Card>

  </div>
</template>
