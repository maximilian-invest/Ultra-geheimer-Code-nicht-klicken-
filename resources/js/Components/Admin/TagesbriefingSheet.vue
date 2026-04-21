<script setup>
import { computed } from "vue";
import { RefreshCw, Flame, TrendingDown, AlertTriangle, Clock, CheckSquare, Mail, Eye } from "lucide-vue-next";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
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

const anomalyIcon = (kind) => ({ hot: Flame, cool: TrendingDown, warn: AlertTriangle })[kind] || AlertTriangle;
const anomalyClasses = (kind) => ({
    hot: "bg-red-50 border-red-200 text-red-900 dark:bg-red-950/20 dark:border-red-900/40 dark:text-red-200",
    cool: "bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/20 dark:border-blue-900/40 dark:text-blue-200",
    warn: "bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/20 dark:border-amber-900/40 dark:text-amber-200",
}[kind] || "bg-muted text-foreground");

function handleOpen(val) { emit("update:open", val); }
</script>

<template>
    <Sheet :open="open" @update:open="handleOpen">
        <SheetContent side="right" class="w-full sm:max-w-2xl overflow-y-auto">
            <SheetHeader class="pr-8">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <SheetTitle class="text-lg">Tagesbriefing</SheetTitle>
                        <SheetDescription class="text-xs mt-1">{{ weekday }} · Zusammenfassung gestern &amp; heute</SheetDescription>
                    </div>
                    <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0" @click="emit('regenerate')" :disabled="loading" title="Neu generieren">
                        <RefreshCw :class="['w-4 h-4', loading && 'animate-spin']" />
                    </Button>
                </div>
            </SheetHeader>

            <div v-if="loading && !briefing" class="mt-6 flex items-center justify-center h-32 text-sm text-muted-foreground">
                Briefing wird generiert…
            </div>

            <div v-else-if="briefing" class="mt-6 space-y-6">

                <!-- Block A: Narrative -->
                <section>
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2.5">Gestern in 3 Sätzen</h3>
                    <div
                        class="bg-muted rounded-lg p-4 text-sm leading-relaxed prose-briefing"
                        v-html="narrativeHtml"
                    ></div>
                </section>

                <!-- Block B: Threads -->
                <section v-if="briefing.threads && briefing.threads.length">
                    <div class="flex items-center gap-2 mb-2.5">
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Aktive Threads mit Kontext</h3>
                        <Badge variant="outline" class="ml-auto text-[10px]">{{ briefing.threads.length }} offen</Badge>
                    </div>
                    <div>
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
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2.5">Anstehend heute</h3>

                    <div v-if="briefing.agenda.termine && briefing.agenda.termine.length">
                        <div
                            v-for="(item, i) in briefing.agenda.termine" :key="'t'+i"
                            class="flex items-center gap-3 py-2 text-sm cursor-pointer hover:bg-accent/40 -mx-2 px-2 rounded-md"
                            @click="item.kind === 'viewing' ? emit('open-viewing', item.property_id) : emit('open-task', item.task_id)"
                        >
                            <span class="text-xs font-semibold text-[#EE7600] w-14 shrink-0">{{ item.time }}</span>
                            <Eye v-if="item.kind === 'viewing'" class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <CheckSquare v-else class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <span>{{ item.text }}</span>
                        </div>
                    </div>

                    <Separator v-if="briefing.agenda.termine?.length && briefing.agenda.offen?.length" class="my-3" />

                    <div v-if="briefing.agenda.offen && briefing.agenda.offen.length">
                        <div v-for="(item, i) in briefing.agenda.offen" :key="'o'+i" class="flex items-center gap-3 py-2 text-sm">
                            <span class="text-[11px] font-medium text-muted-foreground w-14 shrink-0">{{ item.label }}</span>
                            <Mail v-if="item.kind === 'nachfass'" class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <Clock v-else class="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                            <span>{{ item.text }}</span>
                        </div>
                    </div>

                    <p v-if="!briefing.agenda.termine?.length && !briefing.agenda.offen?.length" class="text-sm text-muted-foreground italic">
                        Keine Termine geplant.
                    </p>
                </section>

                <!-- Block D: Anomalies -->
                <section v-if="briefing.anomalies && briefing.anomalies.length">
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2.5">Auffälligkeiten</h3>
                    <div class="space-y-2">
                        <div
                            v-for="(a, i) in briefing.anomalies" :key="'a'+i"
                            class="flex gap-2.5 p-3 rounded-lg border text-sm leading-relaxed"
                            :class="anomalyClasses(a.kind)"
                        >
                            <component :is="anomalyIcon(a.kind)" class="w-4 h-4 shrink-0 mt-0.5" />
                            <div v-html="a.text"></div>
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
