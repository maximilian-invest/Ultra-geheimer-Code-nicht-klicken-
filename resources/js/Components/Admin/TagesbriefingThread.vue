<script setup>
import { computed } from "vue";
import { ChevronRight } from "lucide-vue-next";

const props = defineProps({
    thread: { type: Object, required: true },
});
const emit = defineEmits(["open"]);

// Nur noch farbiger Dot statt Dot + Badge — reduziert visuelle Unruhe
const dotColor = computed(() => {
    return {
        red: "bg-red-500",
        orange: "bg-orange-500",
        yellow: "bg-yellow-500",
        green: "bg-emerald-500",
    }[props.thread.priority] || "bg-gray-300";
});

const displayName = computed(() => {
    const name = props.thread.stakeholder || "Unbekannt";
    const ref = props.thread.property_ref;
    return ref ? `${name} @ ${ref}` : name;
});

// Label wird jetzt inline nach dem Namen angezeigt (keine Badge-Box mehr)
const labelClass = computed(() => {
    if (props.thread.priority === "red") return "text-red-600 dark:text-red-400";
    if (props.thread.priority === "orange") return "text-orange-600 dark:text-orange-400";
    if (props.thread.priority === "yellow") return "text-amber-700 dark:text-amber-400";
    return "text-muted-foreground";
});
</script>

<template>
    <div
        class="group py-2.5 cursor-pointer hover:bg-accent/30 -mx-3 px-3 rounded-md transition-colors"
        @click="emit('open', thread.id)"
    >
        <div class="flex items-center gap-2.5">
            <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="dotColor"></span>
            <span class="text-sm font-medium text-foreground">{{ displayName }}</span>
            <span v-if="thread.label" class="text-xs" :class="labelClass">· {{ thread.label }}</span>
            <ChevronRight class="w-3.5 h-3.5 text-muted-foreground/50 ml-auto group-hover:text-muted-foreground transition-colors" />
        </div>
        <div v-if="thread.trail && thread.trail.length" class="text-xs text-muted-foreground leading-relaxed pl-4 mt-1">
            <span v-for="(entry, i) in thread.trail" :key="i">
                <span :class="i === thread.trail.length - 1 ? 'text-foreground' : ''">{{ entry }}</span>
                <span v-if="i < thread.trail.length - 1" class="mx-1 text-muted-foreground/50">·</span>
            </span>
        </div>
    </div>
</template>
