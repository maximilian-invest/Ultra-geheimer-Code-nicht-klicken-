<script setup>
import { computed } from "vue";
import { Sun, ArrowRight, Loader2 } from "lucide-vue-next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

const props = defineProps({
    briefing: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    date: { type: String, default: "" },
});
const emit = defineEmits(["open"]);

const weekday = computed(() => {
    if (!props.date) return "";
    try {
        const d = new Date(props.date + "T00:00:00");
        return d.toLocaleDateString("de-AT", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
    } catch (e) {
        return props.date;
    }
});

const preview = computed(() => {
    if (props.loading && !props.briefing) return "Briefing wird generiert…";
    if (!props.briefing?.preview) return "Noch kein Briefing für heute — klicke zum Öffnen.";
    return props.briefing.preview;
});
</script>

<template>
    <div
        class="relative rounded-xl border border-border/40 bg-card shadow-sm overflow-hidden cursor-pointer hover:shadow-md transition-shadow"
        @click="emit('open')"
    >
        <!-- Orange Akzent-Leiste links -->
        <div class="absolute left-0 top-0 bottom-0 w-[3px] bg-[#EE7600]"></div>

        <div class="flex items-center gap-4 p-5 pl-6">
            <!-- Icon-Box -->
            <div class="w-10 h-10 rounded-lg bg-[#fff7ed] dark:bg-orange-950/20 flex items-center justify-center shrink-0">
                <Sun class="w-5 h-5 text-[#EE7600]" />
            </div>

            <!-- Body -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                    <span class="text-base font-semibold tracking-tight">Tagesbriefing</span>
                    <Badge variant="secondary" class="text-[10px] px-2 py-0.5">KI</Badge>
                    <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">{{ weekday }}</span>
                </div>
                <div class="text-sm text-muted-foreground leading-snug">
                    <Loader2 v-if="loading && !briefing" class="inline w-3.5 h-3.5 animate-spin mr-1" />
                    <span>{{ preview }}</span>
                </div>
            </div>

            <!-- CTA Button -->
            <Button size="sm" class="shrink-0 hidden sm:inline-flex" @click.stop="emit('open')">
                Vollständig lesen
                <ArrowRight class="w-3.5 h-3.5 ml-1" />
            </Button>
        </div>
    </div>
</template>
