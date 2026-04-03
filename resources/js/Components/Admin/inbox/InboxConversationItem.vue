<script setup>
import { computed } from "vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";

const props = defineProps({
  item: { type: Object, required: true },
  active: { type: Boolean, default: false },
  subtab: { type: String, default: "offen" },
});

const emit = defineEmits(["click"]);

function getInitials(name) {
  if (!name) return "??";
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  return name.substring(0, 2).toUpperCase();
}

function timeAgo(dateStr) {
  if (!dateStr) return "";
  const now = new Date();
  const d = new Date(dateStr.replace(" ", "T"));
  const diffMs = now - d;
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return "gerade";
  if (mins < 60) return "vor " + mins + " Min.";
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return "vor " + hrs + " Std.";
  const days = Math.floor(hrs / 24);
  if (days < 30) return "vor " + days + " Tag" + (days > 1 ? "en" : "");
  const months = Math.floor(days / 30);
  return "vor " + months + " Mon.";
}

const displayName = computed(() => props.item.from_name || props.item.stakeholder || props.item.from_email || "Unbekannt");
const initials = computed(() => getInitials(displayName.value));
const timestamp = computed(() => timeAgo(props.item.email_date || props.item.last_activity || props.item.created_at));
const subject = computed(() => props.item.subject || props.item.activity || "Kein Betreff");
const refId = computed(() => props.item.ref_id || "");
const platform = computed(() => props.item.platform || props.item.source || "");

const daysWaiting = computed(() => {
  const d = props.item.days_waiting ?? props.item.days_unanswered;
  return typeof d === "number" ? d : null;
});

const hasDraft = computed(() => !!(props.item._hasDraft || props.item.draft));

const stageLabel = computed(() => {
  const s = props.item._stage || props.item.stage;
  if (s === 1) return "NF1";
  if (s === 2) return "NF2";
  if (s === 3) return "NF3";
  return null;
});

const stageColor = computed(() => {
  const s = props.item._stage || props.item.stage;
  if (s === 1) return "bg-amber-100 text-amber-800 border-amber-200";
  if (s === 2) return "bg-orange-100 text-orange-800 border-orange-200";
  if (s === 3) return "bg-red-100 text-red-800 border-red-200";
  return "bg-zinc-100 text-zinc-700 border-zinc-200";
});

const isKaufanbot = computed(() => {
  const cat = (props.item.category || "").toLowerCase();
  return cat === "kaufanbot" || cat === "anbot";
});
</script>

<template>
  <div
    @click="emit('click', item)"
    class="flex gap-2.5 px-3 py-2.5 cursor-pointer transition-colors hover:bg-accent/50"
    :class="active
      ? 'bg-background border-l-2 border-l-foreground'
      : 'border-l-2 border-l-transparent'"
  >
    <!-- Avatar -->
    <Avatar class="h-[34px] w-[34px] rounded-lg flex-shrink-0">
      <AvatarFallback class="rounded-lg bg-zinc-100 text-zinc-600 text-[11px] font-semibold">
        {{ initials }}
      </AvatarFallback>
    </Avatar>

    <!-- Content -->
    <div class="flex-1 min-w-0">
      <!-- Row 1: Name + Time -->
      <div class="flex items-baseline justify-between gap-2">
        <span class="text-[13px] font-semibold text-foreground truncate">{{ displayName }}</span>
        <span class="text-[10px] text-muted-foreground whitespace-nowrap flex-shrink-0">{{ timestamp }}</span>
      </div>

      <!-- Row 2: Subject -->
      <div class="text-[11px] text-muted-foreground truncate mt-0.5">{{ subject }}</div>

      <!-- Row 3: Tags -->
      <div class="flex items-center gap-1 mt-1 flex-wrap">
        <!-- Ref ID -->
        <Badge v-if="refId" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-zinc-200 text-zinc-500">
          {{ refId }}
        </Badge>

        <!-- Platform -->
        <Badge v-if="platform" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-zinc-200 text-zinc-500">
          {{ platform }}
        </Badge>

        <!-- Offen subtab badges -->
        <template v-if="subtab === 'offen'">
          <Badge
            v-if="daysWaiting !== null"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium"
            :class="daysWaiting >= 3 ? 'bg-red-100 text-red-700 border-red-200' : 'bg-zinc-100 text-zinc-600'"
          >
            {{ daysWaiting }}d
          </Badge>
          <Badge
            v-if="hasDraft"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-emerald-100 text-emerald-700 border-emerald-200"
          >
            KI bereit
          </Badge>
        </template>

        <!-- Nachfassen subtab badges -->
        <template v-if="subtab === 'nachfassen'">
          <Badge
            v-if="stageLabel"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium border"
            :class="stageColor"
          >
            {{ stageLabel }}
          </Badge>
          <Badge
            v-if="isKaufanbot"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-purple-100 text-purple-700 border-purple-200"
          >
            Kaufanbot
          </Badge>
        </template>
      </div>
    </div>
  </div>
</template>
