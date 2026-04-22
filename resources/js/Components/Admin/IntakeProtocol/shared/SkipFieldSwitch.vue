<script setup>
import { Button } from '@/components/ui/button';
import { Check, ArrowRight } from 'lucide-vue-next';

defineProps({
  modelValue: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>

<template>
  <Button
    :variant="modelValue ? 'secondary' : 'ghost'"
    size="xs"
    @click="$emit('update:modelValue', !modelValue)"
  >
    <Check v-if="modelValue" class="h-3 w-3" />
    <ArrowRight v-else class="h-3 w-3" />
    <span>{{ modelValue ? 'später ergänzen' : 'später' }}</span>
  </Button>
</template>
