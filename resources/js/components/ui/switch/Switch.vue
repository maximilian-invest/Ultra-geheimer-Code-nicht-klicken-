<script setup>
import { reactiveOmit } from "@vueuse/core";
import { SwitchRoot, SwitchThumb } from "reka-ui";
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  defaultValue: { type: null, required: false },
  modelValue: { type: null, required: false },
  checked: { type: null, required: false },
  disabled: { type: Boolean, required: false },
  id: { type: String, required: false },
  value: { type: String, required: false },
  trueValue: { type: null, required: false },
  falseValue: { type: null, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  name: { type: String, required: false },
  required: { type: Boolean, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const emits = defineEmits(["update:modelValue", "update:checked"]);

// Exclude `checked` and `modelValue` — we bind modelValue reactively below.
const delegatedProps = reactiveOmit(props, "class", "checked", "modelValue");

// Reactive bridge so `:checked` from the parent always reaches SwitchRoot.
const modelValue = computed(() => props.modelValue ?? props.checked);

function handleUpdate(value) {
  emits("update:modelValue", value);
  emits("update:checked", value);
}
</script>

<template>
  <SwitchRoot
    v-bind="delegatedProps"
    :model-value="modelValue"
    @update:model-value="handleUpdate"
    :class="
      cn(
        'peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 border-zinc-900 data-[state=checked]:bg-zinc-900 data-[state=unchecked]:bg-white dark:border-zinc-100 dark:data-[state=checked]:bg-zinc-100 dark:data-[state=unchecked]:bg-zinc-900',
        props.class,
      )
    "
  >
    <SwitchThumb
      :class="
        cn(
          'pointer-events-none block h-3.5 w-3.5 rounded-full shadow ring-0 transition-transform data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0 data-[state=checked]:bg-white data-[state=unchecked]:bg-zinc-900 dark:data-[state=checked]:bg-zinc-900 dark:data-[state=unchecked]:bg-zinc-100',
        )
      "
    >
      <slot name="thumb" />
    </SwitchThumb>
  </SwitchRoot>
</template>
