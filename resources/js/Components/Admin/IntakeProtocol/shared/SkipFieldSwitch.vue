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
    variant="ghost"
    size="xs"
    :class="modelValue
      ? 'bg-primary text-primary-foreground hover:bg-primary/90'
      : 'text-primary hover:bg-primary/10'"
    @click="$emit('update:modelValue', !modelValue)"
  >
    <Check v-if="modelValue" class="h-3 w-3" />
    <ArrowRight v-else class="h-3 w-3" />
    <span class="text-[11px]">{{ modelValue ? 'später ergänzen' : 'später' }}</span>
  </Button>
</template>
