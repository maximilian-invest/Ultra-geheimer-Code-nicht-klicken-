<script setup>
import { computed } from "vue";
import { RefreshCw } from "lucide-vue-next";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { Button } from "@/components/ui/button";
import TagesbriefingThread from "./TagesbriefingThread.vue";

const props = defineProps({
    open: { type: Boolean, default: false },
    briefing: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    date: { type: String, default: "" },
});
const emit = defineEmits(["update:open", "regenerate", "open-conversation", "open-task", "open-viewing"]);

const narrativeHtml = computed(() => {
    return props.briefing?.narrative || "";
});

const weekday = computed(() => {
    if (!props.date) return "";
    try {
        const d = new Date(props.date + "T00:00:00");
        return d.toLocaleDateString("de-AT", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
    } catch (e) {
        return props.date;
    }
});

const anomalyBorderClass = (kind) => ({
    hot: "border-red-400 dark:border-red-500/60",
    cool: "border-blue-400 dark:border-blue-500/60",
    warn: "border-amber-400 dark:border-amber-500/60",
}[kind] || "border-border");

function handleOpen(val) { emit("update:open", val); }
</script>

<template>
    <Sheet :open="open" @update:open="handleOpen">
        <SheetContent side="right" class="w-full sm:max-w-2xl overflow-y-auto bg-white dark:bg-zinc-950 px-0">
            <SheetHeader class="px-6 pt-1 pb-4 pr-12 border-b border-border/40">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <SheetTitle class="text-base font-semibold">Tagesbriefing</SheetTitle>
                        <SheetDescription class="text-xs text-muted-foreground mt-0.5">{{ weekday }}</SheetDescription>
                    </div>
                    <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0" @click="emit('regenerate')" :disabled="loading" title="Neu generieren">
                        <RefreshCw :class="['w-4 h-4', loading && 'animate-spin']" />
                    </Button>
                </div>
            </SheetHeader>

            <div v-if="loading && !briefing" class="mt-10 flex items-center justify-center h-24 text-sm text-muted-foreground">
                Briefing wird generiert…
            </div>

            <div v-else-if="briefing" class="px-6 py-5 space-y-7">

                <!-- Block A: Narrative - ganz schlicht, kein Kasten -->
                <section>
                    <div
                        class="text-sm leading-relaxed text-foreground prose-briefing"
                        v-html="narrativeHtml"
                    ></div>
                </section>

                <!-- Block B: Threads -->
                <section v-if="briefing.threads && briefing.threads.length">
                    <div class="flex items-baseline gap-2 mb-1">
                        <h3 class="text-sm font-semibold text-foreground">Aktive Threads</h3>
                        <span class="text-xs text-muted-foreground">{{ briefing.threads.length }}</span>
                    </div>
                    <div class="divide-y divide-border/50">
                        <TagesbriefingThread
                            v-for="t in briefing.threads"
                            :key="t.id"
                            :thread="t"
                            @open="emit('open-conversation', $event)"
                        />
                    </div>
                </section>

                <!-- Block C: Agenda -->
                <section v-if="briefing.agenda">
                    <h3 class="text-sm font-semibold text-foreground mb-1">Heute</h3>

                    <div v-if="briefing.agenda.termine && briefing.agenda.termine.length" class="divide-y divide-border/50">
                        <div
                            v-for="(item, i) in briefing.agenda.termine" :key="'t'+i"
                            class="flex items-center gap-3 py-2.5 text-sm cursor-pointer hover:bg-accent/30 -mx-2 px-2 rounded-md transition-colors"
                            @click="item.kind === 'viewing' ? emit('open-viewing', item.property_id) : emit('open-task', item.task_id)"
                        >
                            <span class="text-xs font-medium text-[#EE7600] w-12 shrink-0 tabular-nums">{{ item.time }}</span>
                            <span class="flex-1">{{ item.text }}</span>
                        </div>
                    </div>

                    <div v-if="briefing.agenda.offen && briefing.agenda.offen.length" :class="briefing.agenda.termine?.length ? 'mt-2 pt-2 border-t border-border/30' : ''">
                        <div v-for="(item, i) in briefing.agenda.offen" :key="'o'+i" class="flex items-center gap-3 py-2 text-sm">
                            <span class="text-[11px] text-muted-foreground w-12 shrink-0 lowercase">{{ item.label }}</span>
                            <span class="flex-1">{{ item.text }}</span>
                        </div>
                    </div>

                    <p v-if="!briefing.agenda.termine?.length && !briefing.agenda.offen?.length" class="text-sm text-muted-foreground">
                        Keine Termine geplant.
                    </p>
                </section>

                <!-- Block D: Anomalies -->
                <section v-if="briefing.anomalies && briefing.anomalies.length">
                    <h3 class="text-sm font-semibold text-foreground mb-1">Wichtig</h3>
                    <div class="divide-y divide-border/50">
                        <div
                            v-for="(a, i) in briefing.anomalies" :key="'a'+i"
                            class="flex gap-3 py-2.5 text-sm leading-relaxed border-l-2 pl-3 -ml-3 first:border-t-0"
                            :class="anomalyBorderClass(a.kind)"
                        >
                            <div class="flex-1" v-html="a.text"></div>
                        </div>
                    </div>
                </section>

            </div>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.prose-briefing :deep(strong) { font-weight: 600; color: hsl(var(--foreground)); }
.prose-briefing :deep(mark) {
    background: rgb(254 226 226);
    color: rgb(153 27 27);
    font-weight: 500;
    padding: 0 3px;
    border-radius: 3px;
}
:global(.dark) .prose-briefing :deep(mark) {
    background: rgb(69 10 10 / 0.4);
    color: rgb(254 202 202);
}
</style>
