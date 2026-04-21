<script setup>
import { computed } from "vue";
import { ChevronRight } from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";

const props = defineProps({
    thread: { type: Object, required: true },
});
const emit = defineEmits(["open"]);

const dotColor = computed(() => {
    return {
        red: "bg-red-500",
        orange: "bg-orange-500",
        yellow: "bg-yellow-500",
        green: "bg-emerald-500",
    }[props.thread.priority] || "bg-gray-400";
});

const labelClasses = computed(() => {
    if (props.thread.priority === "red") return "bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300";
    if (props.thread.priority === "orange") return "bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-300";
    if (props.thread.priority === "yellow") return "bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300";
    return "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300";
});

const displayName = computed(() => {
    const name = props.thread.stakeholder || "Unbekannt";
    const ref = props.thread.property_ref;
    return ref ? `${name} @ ${ref}` : name;
});
</script>

<template>
    <div
        class="py-3 border-b border-border/60 last:border-b-0 cursor-pointer hover:bg-accent/40 -mx-3 px-3 rounded-md transition-colors"
        @click="emit('open', thread.id)"
    >
        <div class="flex items-center gap-2.5 mb-1.5 flex-wrap">
            <span class="w-2 h-2 rounded-full shrink-0" :class="dotColor"></span>
            <span class="text-sm font-semibold text-foreground">{{ displayName }}</span>
            <Badge v-if="thread.label" class="text-[10px] px-2 py-0.5 border-0" :class="labelClasses">
                {{ thread.label }}
            </Badge>
            <ChevronRight class="w-4 h-4 text-muted-foreground ml-auto" />
        </div>
        <div v-if="thread.trail && thread.trail.length" class="text-xs text-muted-foreground leading-relaxed pl-4">
            <span v-for="(entry, i) in thread.trail" :key="i">
                <span :class="i === thread.trail.length - 1 ? 'text-foreground font-medium' : ''">{{ entry }}</span>
                <span v-if="i < thread.trail.length - 1" class="mx-1.5">→</span>
            </span>
        </div>
    </div>
</template>
