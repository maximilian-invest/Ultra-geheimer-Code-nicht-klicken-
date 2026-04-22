<script setup>
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const alertVariants = cva(
  'relative w-full rounded-lg border px-4 py-3 text-sm [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground [&>svg~*]:pl-7',
  {
    variants: {
      variant: {
        default: 'bg-background text-foreground',
        destructive:
          'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
        warning:
          'border-amber-300/60 bg-amber-50/50 text-amber-900 [&>svg]:text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-200',
        info:
          'border-blue-300/60 bg-blue-50/50 text-blue-900 [&>svg]:text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/20 dark:text-blue-200',
        success:
          'border-emerald-300/60 bg-emerald-50/50 text-emerald-900 [&>svg]:text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-200',
      },
    },
    defaultVariants: { variant: 'default' },
  }
);

const props = defineProps({
  variant: { type: String, default: 'default' },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <div role="alert" :class="cn(alertVariants({ variant: props.variant }), props.class)">
    <slot />
  </div>
</template>

<script>
export const alertVariants = {};
</script>
