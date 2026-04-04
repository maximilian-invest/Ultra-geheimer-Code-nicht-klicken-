<script setup>
import { computed } from "vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { CheckCircle, Trash2 } from "lucide-vue-next";

const props = defineProps({
  item: { type: Object, required: true },
  active: { type: Boolean, default: false },
  subtab: { type: String, default: "offen" },
});

const emit = defineEmits(["click", "delete"]);

function getInitials(name) {
  if (!name) return "??";
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  return name.substring(0, 2).toUpperCase();
}

function formatDateTime(dateStr) {
  if (!dateStr) return "";
  const now = new Date();
  const d = new Date(dateStr.replace(" ", "T"));
  if (isNaN(d.getTime())) return "";

  const pad = (n) => String(n).padStart(2, "0");
  const time = pad(d.getHours()) + ":" + pad(d.getMinutes());

  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const yesterdayStart = new Date(todayStart);
  yesterdayStart.setDate(yesterdayStart.getDate() - 1);

  if (d >= todayStart) {
    return "Heute, " + time;
  }
  if (d >= yesterdayStart) {
    return "Gestern, " + time;
  }

  // Check if same week (within last 7 days)
  const weekAgo = new Date(todayStart);
  weekAgo.setDate(weekAgo.getDate() - 6);
  if (d >= weekAgo) {
    const days = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
    return days[d.getDay()] + ", " + time;
  }

  // Older: DD.MM HH:MM
  return pad(d.getDate()) + "." + pad(d.getMonth() + 1) + " " + time;
}

const displayName = computed(() => props.item.from_name || props.item.stakeholder || props.item.from_email || "Unbekannt");
const initials = computed(() => getInitials(displayName.value));

const timestamp = computed(() => {
  const dateStr = props.item.last_inbound_at || props.item.email_date || props.item.last_activity_at;
  return formatDateTime(dateStr);
});

const subject = computed(() => {
  const s = props.item.subject;
  if (!s || s === "Kein Betreff") return null;
  return s;
});

const refId = computed(() => props.item.ref_id || "");

const sourcePlatform = computed(() => {
  const p = props.item.source_platform || "";
  if (!p || p.toLowerCase() === "direkt") return null;
  return p;
});

const daysWaiting = computed(() => {
  const d = props.item.days_waiting ?? props.item.days_unanswered;
  return typeof d === "number" ? d : null;
});

const hasDraft = computed(() => !!props.item.draft_body);

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

function getAvatarColor(name) {
  const colors = ["bg-zinc-800", "bg-zinc-700", "bg-zinc-600", "bg-zinc-500", "bg-zinc-400"];
  const idx = (name || "").length % colors.length;
  return colors[idx];
}
</script>

<template>
  <div
    @click="emit('click', item)"
    class="group flex gap-2.5 px-3 py-2.5 cursor-pointer transition-colors hover:bg-gradient-to-r hover:from-orange-100/70 hover:to-transparent relative"
    :class="active
      ? 'bg-background border-l-2 border-l-foreground'
      : 'border-l-2 border-l-transparent'"
  >
    <!-- Avatar -->
    <Avatar class="h-[34px] w-[34px] rounded-lg flex-shrink-0 mt-0.5">
      <AvatarFallback :class="['rounded-lg text-white text-[11px] font-semibold', getAvatarColor(displayName)]">
        {{ initials }}
      </AvatarFallback>
    </Avatar>

    <!-- Content -->
    <div class="flex-1 min-w-0">
      <!-- Row 1: Name + DateTime -->
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-1 min-w-0">
          <span v-if="subtab === 'posteingang' && !item.is_read" class="w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
          <span
            class="text-[13px] text-foreground truncate"
            :class="(subtab === 'posteingang' && !item.is_read) ? 'font-bold' : 'font-semibold'"
          >{{ displayName }}</span>
          <CheckCircle v-if="item.has_reply && item.direction === 'inbound'" class="w-3 h-3 text-green-500 flex-shrink-0" title="Beantwortet" />
        </div>
        <div class="flex items-center gap-1 flex-shrink-0">
          <button
            @click.stop="emit('delete', item)"
            class="hidden group-hover:flex items-center justify-center w-5 h-5 rounded hover:bg-red-100 text-zinc-400 hover:text-red-500 transition-colors"
            title="Löschen"
          >
            <Trash2 class="w-3 h-3" />
          </button>
          <span class="text-[10px] text-muted-foreground whitespace-nowrap">{{ timestamp }}</span>
        </div>
      </div>

      <!-- Row 2: Subject (only if exists and not "Kein Betreff") -->
      <div v-if="subject" class="text-[11px] text-muted-foreground truncate mt-0.5">{{ subject }}</div>

      <!-- Row 3: Badges -->
      <div class="flex items-center gap-1 mt-1 flex-wrap">
        <!-- Ref ID -->
        <Badge v-if="refId" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-zinc-200 text-zinc-500">
          {{ refId }}
        </Badge>

        <!-- Platform (amber, hidden for direkt) -->
        <Badge v-if="sourcePlatform" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-amber-200 text-amber-700 bg-amber-50">
          {{ sourcePlatform }}
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
