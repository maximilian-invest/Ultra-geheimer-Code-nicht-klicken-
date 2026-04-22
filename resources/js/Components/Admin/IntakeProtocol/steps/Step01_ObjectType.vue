<script setup>
import { computed, toRef } from 'vue';
import PillRow from '../shared/PillRow.vue';
import SkipFieldSwitch from '../shared/SkipFieldSwitch.vue';
import { useSubtypes } from '../composables/useSubtypes';

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
  <div class="p-4 space-y-6">

    <!-- Objekttyp -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
        Hauptkategorie <span class="text-red-500">*</span>
      </div>
      <div class="grid grid-cols-4 gap-2">
        <button
          v-for="t in TYPE_TILES" :key="t.key"
          type="button"
          @click="selectType(t.key)"
          :class="[
            'rounded-xl p-3 text-center transition-colors',
            form.object_type === t.key
              ? 'bg-white border-2 border-[#EE7600] shadow-md'
              : 'bg-white border border-border'
          ]"
        >
          <div class="text-2xl">{{ t.icon }}</div>
          <div class="text-[11px] font-medium mt-1">{{ t.label }}</div>
        </button>
      </div>
    </div>

    <!-- Subtyp -->
    <div v-if="subtypes.length">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
        Bauweise / Subtyp
      </div>
      <PillRow v-model="form.object_subtype" :options="subtypes" />
    </div>

    <!-- Vermarktung -->
    <div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
        Vermarktungsart <span class="text-red-500">*</span>
      </div>
      <PillRow v-model="form.marketing_type" :options="[
        {value: 'kauf', label: 'Kauf'},
        {value: 'miete', label: 'Miete'},
        {value: 'pacht', label: 'Pacht'},
      ]" />
    </div>

    <!-- Ref-ID -->
    <div>
      <div class="flex items-center justify-between mb-1">
        <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          Ref-ID
        </label>
        <SkipFieldSwitch v-model="skippedRefId" />
      </div>
      <input
        v-model="form.ref_id"
        :placeholder="refIdSuggestion || 'z.B. Kau-Woh-Mus-01'"
        class="w-full h-11 rounded-lg border border-border px-3 font-mono text-sm bg-white"
      />
      <p class="text-[11px] text-muted-foreground mt-1">
        Leer lassen für Auto-Vorschlag: <code>{{ refIdSuggestion || 'wird nach Eigentümer-Auswahl vorgeschlagen' }}</code>
      </p>
    </div>

  </div>
</template>
