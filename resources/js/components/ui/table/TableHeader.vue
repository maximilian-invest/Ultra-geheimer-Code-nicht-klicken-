<script setup>
import { cn } from "@/lib/utils";

const props = defineProps({
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <thead :class="cn('[&_tr]:border-b [&_tr]:border-gray-200', props.class)">
    <slot />
  </thead>
</template>
