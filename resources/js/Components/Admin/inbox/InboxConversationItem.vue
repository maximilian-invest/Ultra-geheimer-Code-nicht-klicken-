<script setup>
import { computed } from "vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { CheckCircle, Trash2, Reply } from "lucide-vue-next";

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

const displayName = computed(() => props.item.stakeholder || props.item.from_name || props.item.from_email || "Unbekannt");
const initials = computed(() => getInitials(displayName.value));

const timestamp = computed(() => {
  let dateStr;
  if (props.subtab === "nachfassen") {
    dateStr = props.item.last_outbound_at || props.item.last_activity_at;
  } else {
    dateStr = props.item.last_inbound_at || props.item.email_date || props.item.last_activity_at;
  }
  return formatDateTime(dateStr);
});

const subject = computed(() => {
  const s = props.item.subject;
  if (!s || s === "Kein Betreff") return null;
  return s;
});

const refId = computed(() => props.item.ref_id || "");
const isUnmatched = computed(() => !props.item.property_id && !refId.value);

const sourcePlatform = computed(() => {
  // From conversations table
  const p = props.item.source_platform || "";
  if (p && p.toLowerCase() !== "direkt") return p;
  // Derive from from_email for posteingang/gesendet
  const from = (props.item.from_email || "").toLowerCase();
  if (from.includes("willhaben")) return "willhaben";
  if (from.includes("typeform") || from.includes("followups.typeform")) return "typeform";
  if (from.includes("immoscout") || from.includes("immobilienscout")) return "ImmoScout";
  if (from.includes("immowelt")) return "immowelt";
  if (from.includes("calendly")) return "calendly";
  return null;
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

const isAbsage = computed(() => {
  const cat = (props.item.category || "").toLowerCase();
  return cat === "absage";
});

const isBesichtigung = computed(() => {
  const cat = (props.item.category || "").toLowerCase();
  return cat === "besichtigung";
});

const isIntern = computed(() => {
  const cat = (props.item.category || "").toLowerCase();
  if (cat === "intern") return true;
  // For conversations: check if the CONTACT (external person) is sr-homes
  const contactEmail = (props.item.contact_email || "").toLowerCase();
  if (contactEmail && contactEmail.endsWith("@sr-homes.at")) return true;
  // For posteingang/gesendet emails: both from AND to must be sr-homes
  const from = (props.item.from_email || "").toLowerCase();
  const to = (props.item.to_email || "").toLowerCase();
  if (from.endsWith("@sr-homes.at") && to.endsWith("@sr-homes.at")) return true;
  return false;
});

const hasBeenReplied = computed(() => {
  // Conversations: outbound_count > 0 means we replied
  if (props.item.outbound_count > 0) return true;
  // Posteingang emails: has_reply flag
  if (props.item.has_reply && props.item.direction === "inbound") return true;
  return false;
});

const hasMatches = computed(() => props.item.match_count > 0 && !props.item.match_dismissed);

function getAvatarColor(name) {
  const colors = ["bg-zinc-800", "bg-zinc-700", "bg-zinc-600", "bg-zinc-500", "bg-zinc-400"];
  const idx = (name || "").length % colors.length;
  return colors[idx];
}
</script>

<template>
    <div
      @click="emit('click', item)"
      class="group flex gap-2.5 px-3 py-2.5 cursor-pointer transition-colors hover:bg-gradient-to-r hover:from-orange-100/70 hover:to-transparent relative overflow-hidden rounded-lg"
      :class="[
        active
          ? 'border-l-2 border-l-foreground'
          : 'border-l-2 border-l-transparent',
        hasMatches ? 'ai-match-border' : ''
      ]"
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
            <Reply v-if="hasBeenReplied" class="w-3.5 h-3.5 text-blue-500 flex-shrink-0 -scale-x-100" title="Beantwortet" />
          </div>
          <div class="flex items-center gap-1 flex-shrink-0">
            <span class="text-[10px] text-muted-foreground whitespace-nowrap">{{ timestamp }}</span>
          </div>
        </div>

        <!-- Row 2: Subject (only if exists and not "Kein Betreff") -->
        <div v-if="subject" class="text-[11px] text-muted-foreground truncate mt-0.5">{{ subject }}</div>

        <!-- Row 3: Badges -->
        <div class="flex items-center gap-1 mt-1 flex-wrap">
          <!-- Erledigt/Delete overlay button -->
          <button
            v-if="subtab === 'offen' || subtab === 'nachfassen'"
            @click.stop="emit('delete', item)"
            class="hidden group-hover:flex absolute right-2 top-2 items-center gap-1 px-2 py-1 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 transition-all text-[10px] font-medium shadow-sm z-10"
          >
            <CheckCircle class="w-3 h-3" />
            Erledigt
          </button>
          <button
            v-else-if="subtab === 'posteingang' || subtab === 'gesendet' || subtab === 'papierkorb'"
            @click.stop="emit('delete', item)"
            class="hidden group-hover:flex absolute right-2 top-2 items-center gap-1 px-2 py-1 rounded-md bg-red-50 border border-red-200 text-red-600 hover:bg-red-100 transition-all text-[10px] font-medium shadow-sm z-10"
          >
            <Trash2 class="w-3 h-3" />
          </button>
          <!-- Ref ID -->
          <Badge v-if="refId" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-zinc-200 text-zinc-500">
            {{ refId }}
          </Badge>

          <!-- Nicht zugeordnet -->
          <Badge v-if="isUnmatched" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-red-200 text-red-600 bg-red-50">
            Nicht zugeordnet
          </Badge>

          <!-- Platform (amber, hidden for direkt) -->
          <Badge v-if="sourcePlatform" variant="outline" class="text-[9px] px-1.5 py-0 h-4 font-normal border-amber-200 text-amber-700 bg-amber-50">
            {{ sourcePlatform }}
          </Badge>

          <!-- Category badges (visible everywhere) -->
          <Badge
            v-if="isAbsage"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-red-100 text-red-700 border border-red-300"
          >
            Absage
          </Badge>
          <Badge
            v-if="isBesichtigung"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-teal-100 text-teal-700 border border-teal-200"
          >
            Besichtigung
          </Badge>
          <Badge
            v-if="isKaufanbot"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-purple-100 text-purple-700 border-purple-200"
          >
            Kaufanbot
          </Badge>
          <Badge
            v-if="isIntern"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-sky-100 text-sky-700 border border-sky-300"
          >
            Intern
          </Badge>

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
          </template>

          <!-- AI Match badge -->
          <Badge
            v-if="hasMatches"
            class="bg-gradient-to-r from-violet-500 to-cyan-500 text-white text-[10px] px-1.5 py-0 border-0"
          >
            ✦ {{ item.match_count }} {{ item.match_count === 1 ? 'Match' : 'Matches' }}
          </Badge>
        </div>
      </div>
    </div>
</template>

<style scoped>
.ai-match-border {
  position: relative;
}
.ai-match-border::before {
  content: '';
  position: absolute;
  inset: -2px;
  border-radius: 10px;
  background: linear-gradient(270deg, hsl(263 70% 58%), hsl(187 72% 53%), hsl(263 70% 58%));
  background-size: 200% 200%;
  animation: aiBorderShift 6s ease infinite;
  z-index: -1;
}

@keyframes aiBorderShift {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}
</style>
