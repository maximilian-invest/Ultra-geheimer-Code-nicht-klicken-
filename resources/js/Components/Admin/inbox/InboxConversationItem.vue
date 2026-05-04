<script setup>
import { computed, inject, ref } from "vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { CheckCircle, Trash2, Paperclip, CheckCheck, Flag } from "lucide-vue-next";
import InboxFlagPicker from "./InboxFlagPicker.vue";

const props = defineProps({
  item: { type: Object, required: true },
  active: { type: Boolean, default: false },
  subtab: { type: String, default: "offen" },
});

const emit = defineEmits(["click", "delete", "flag-change", "open-flag-settings"]);

// Optionaler Flag-Kontext (von InboxTab via provide bereitgestellt). Nicht
// vorhanden? -> Flag-UI bleibt nicht-funktional aber crasht nicht.
const flagContext = inject("inboxFlags", null);
const flagLabels = computed(() => flagContext?.labels?.value || {});

const flagColor = computed(() => props.item.flag_color || null);
const flagSwatchTextClass = computed(() => {
  switch (flagColor.value) {
    case "red":    return "text-red-500";
    case "orange": return "text-orange-500";
    case "yellow": return "text-yellow-500";
    case "green":  return "text-emerald-500";
    case "blue":   return "text-blue-500";
    case "purple": return "text-purple-500";
    default: return "text-zinc-400";
  }
});
const flagPickerOpen = ref(false);
async function onFlagSelect(color) {
  flagPickerOpen.value = false;
  if (!flagContext?.setFlag) return;
  // Optimistic: lokal sofort spiegeln, falls die Liste daraufhin neu laden moechte.
  props.item.flag_color = color || null;
  await flagContext.setFlag(props.item, color);
  emit("flag-change", { item: props.item, color });
}
function openFlagSettings() {
  flagPickerOpen.value = false;
  emit("open-flag-settings");
}

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

// Display name resolution:
// - For real human senders (not platform noreply), prefer the actual mail
//   from_name header — it's the literal "From:" on the envelope and cannot
//   be wrong. The AI-derived stakeholder field is an inferred guess (e.g.
//   extracts a name mentioned in the body) and regularly disagrees with
//   reality. Example: newsletter from insights@wkooe.at has from_name
//   "Doris Hummer" but AI stakeholder "Hannes Buchinger (WKOÖ)" because
//   an article inside was authored by Buchinger.
// - For platform noreply senders (willhaben / immoscout / typeform), the
//   from_name is useless (it's "Typeform Notifications" etc.) and the AI
//   stakeholder is actually the correct real person, so we prefer it.
function isNoReplyFrom(email) {
  if (!email) return false;
  const e = String(email).toLowerCase();
  return /noreply|no-reply|notifications?@|followups\.typeform|bounce|mailer-daemon/.test(e);
}
const displayName = computed(() => {
  const item = props.item;

  // Im Gesendet-Ordner ist die Eigen-Absender-Info (Makler) uninteressant —
  // der User will sehen WEM er geschrieben hat, nicht sich selbst. Wir
  // zeigen also den Empfaenger: stakeholder > to-Name aus attachment_names
  // Metadaten > to_email > Fallback. ConversationService setzt stakeholder
  // fuer outbound-Mails bereits auf den Kundennamen.
  if (props.subtab === 'gesendet') {
    const toEmail = item.to_email || item.contact_email || '';
    return item.stakeholder || item.to_name || toEmail || 'Unbekannt';
  }

  const from = item.from_email || "";
  if (isNoReplyFrom(from)) {
    return item.stakeholder || item.from_name || from || "Unbekannt";
  }
  return item.from_name || item.stakeholder || from || "Unbekannt";
});
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

// Anhang-Anzeige: Paperclip-Icon im Header bei gesendeten/empfangenen Mails
// mit Anhang. Tooltip listet die Dateinamen aus attachment_names (CSV).
const attachmentNames = computed(() => {
  const raw = props.item.attachment_names;
  if (!raw) return [];
  const names = String(raw).split(",").map(n => n.trim()).filter(Boolean);
  return names;
});
const hasAttachment = computed(() => {
  return !!props.item.has_attachment || attachmentNames.value.length > 0;
});
const attachmentCount = computed(() => {
  const n = Number(props.item.attachment_count);
  if (n > 0) return n;
  return attachmentNames.value.length;
});
const attachmentTooltip = computed(() => {
  if (attachmentNames.value.length) return "Anhänge: " + attachmentNames.value.join(", ");
  return "Hat Anhang";
});

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

const isInfoCc = computed(() => {
  const cat = (props.item.category || "").toLowerCase();
  return cat === "info-cc";
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
  // For conversations: check the contact OR the resolved stakeholder
  // (ConversationService stores a placeholder contact_email for internal
  // senders and puts the real address on the stakeholder column, so we
  // have to look at both).
  const contactEmail = (props.item.contact_email || "").toLowerCase();
  if (contactEmail && contactEmail.endsWith("@sr-homes.at")) return true;
  const stakeholder = (props.item.stakeholder || "").toLowerCase();
  if (stakeholder && stakeholder.endsWith("@sr-homes.at")) return true;
  // For posteingang/gesendet emails: both from AND to must be sr-homes
  const from = (props.item.from_email || "").toLowerCase();
  const to = (props.item.to_email || "").toLowerCase();
  if (from.endsWith("@sr-homes.at") && to.endsWith("@sr-homes.at")) return true;
  // Inbound copy of a mail sent by a colleague (we were CC'd) — still intern.
  const direction = (props.item.direction || "").toLowerCase();
  if (from.endsWith("@sr-homes.at") && direction === "inbound") return true;
  return false;
});

const hasBeenReplied = computed(() => {
  const item = props.item;

  // Im Nachfassen-Tab ist "beantwortet" tautologisch — eine Conv landet
  // dort nur, wenn wir bereits geantwortet haben und der Kunde noch nicht
  // erneut geschrieben hat. Den Marker dort einzublenden waere redundant
  // und macht die Liste unruhig (alle Eintraege gruen). Also off.
  if (props.subtab === "nachfassen") return false;

  // Posteingang: per-mail has_reply flag is correct (set when the reply
  // was sent, scoped to that specific inbound mail).
  if (item.direction === "inbound" && item.has_reply) return true;

  // Conversations: the reply indicator must track "is the LATEST inbound
  // mail answered yet?". A cumulative outbound_count > 0 check is wrong
  // because the customer can always come back with a new question after
  // we replied — in that case the indicator must disappear again.
  // So we compare timestamps: last outbound must be strictly newer than
  // last inbound.
  const lastInRaw = item.last_inbound_at;
  const lastOutRaw = item.last_outbound_at;
  const lastIn = lastInRaw ? new Date(lastInRaw).getTime() : 0;
  const lastOut = lastOutRaw ? new Date(lastOutRaw).getTime() : 0;
  if (lastOut && lastIn && lastOut > lastIn) return true;

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
      class="group flex w-full max-w-full gap-2.5 px-3 py-2.5 cursor-pointer transition-colors relative overflow-x-clip rounded-lg"
      :class="[
        active
          ? 'border-l-[6px] border-l-foreground'
          : hasBeenReplied
            ? 'border-l-[6px] border-l-emerald-600'
            : 'border-l-[3px] border-l-transparent',
        hasMatches ? 'ai-match-border' : '',
        hasBeenReplied
          ? 'bg-emerald-100/70 hover:bg-emerald-200/70'
          : 'hover:bg-gradient-to-r hover:from-orange-100/70 hover:to-transparent'
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
            <span v-if="subtab === 'posteingang' && !item.is_read && !hasBeenReplied" class="w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
            <span
              class="text-[13px] text-foreground truncate"
              :class="(subtab === 'posteingang' && !item.is_read && !hasBeenReplied) ? 'font-bold' : 'font-semibold'"
            >{{ displayName }}</span>
            <span
              v-if="hasBeenReplied"
              class="inline-flex items-center gap-0.5 px-1.5 h-4 rounded-full text-[9px] font-bold uppercase tracking-wide bg-emerald-600 text-white shadow-sm flex-shrink-0"
              title="Diese Nachricht wurde bereits beantwortet"
            >
              <CheckCheck class="w-2.5 h-2.5" />
              <span>Beantwortet</span>
            </span>
          </div>
          <div class="flex items-center gap-1 flex-shrink-0 relative">
            <span v-if="hasAttachment" class="inline-flex items-center gap-0.5 text-muted-foreground" :title="attachmentTooltip">
              <Paperclip class="w-3 h-3" />
              <span v-if="attachmentCount > 1" class="text-[10px] tabular-nums">{{ attachmentCount }}</span>
            </span>
            <!-- Outlook-Style Flag-Button: bei aktiver Markierung dauerhaft
                 voll sichtbar in der gewaehlten Farbe; sonst dauerhaft als
                 dezenter Outline-Button mit reduzierter Opacity, beim Hover
                 voll sichtbar. Vor das Datum gezogen damit der Erledigt-
                 Overlay-Button (absolute right-2 top-2 in den Subtabs
                 'offen'/'nachfassen') das Flag nicht abdeckt. -->
            <button
              v-if="flagContext"
              type="button"
              class="inline-flex items-center justify-center w-5 h-5 rounded transition-all hover:bg-zinc-200"
              :class="flagColor ? 'opacity-100' : 'opacity-50 group-hover:opacity-100'"
              :title="flagColor ? (flagLabels[flagColor] || 'Markiert') : 'Markieren'"
              @click.stop="flagPickerOpen = !flagPickerOpen"
            >
              <Flag
                class="w-3.5 h-3.5"
                :class="flagColor ? [flagSwatchTextClass, 'fill-current'] : 'text-zinc-600'"
              />
            </button>
            <span class="text-[10px] text-muted-foreground whitespace-nowrap">{{ timestamp }}</span>
            <InboxFlagPicker
              v-if="flagPickerOpen"
              :current-color="flagColor"
              :labels="flagLabels"
              align="right"
              @select="onFlagSelect"
              @close="flagPickerOpen = false"
              @open-settings="openFlagSettings"
            />
          </div>
        </div>

        <!-- Row 2: Subject (only if exists and not "Kein Betreff") -->
        <div v-if="subject" class="text-[11px] text-muted-foreground truncate mt-0.5">{{ subject }}</div>

        <!-- Row 3: Badges -->
        <div class="flex items-center gap-1 mt-1 flex-wrap">
          <!-- Erledigt/Delete overlay button. Bottom-rechts statt top-rechts,
               damit das Flag-Symbol oben rechts beim Hover nicht ueber-
               lagert wird. -->
          <button
            v-if="subtab === 'offen' || subtab === 'nachfassen'"
            @click.stop="emit('delete', item)"
            class="hidden group-hover:flex absolute right-2 bottom-2 items-center gap-1 px-2 py-1 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 transition-all text-[10px] font-medium shadow-sm z-10"
          >
            <CheckCircle class="w-3 h-3" />
            Erledigt
          </button>
          <button
            v-else-if="subtab === 'posteingang' || subtab === 'gesendet' || subtab === 'papierkorb'"
            @click.stop="emit('delete', item)"
            class="hidden group-hover:flex absolute right-2 bottom-2 items-center gap-1 px-2 py-1 rounded-md bg-red-50 border border-red-200 text-red-600 hover:bg-red-100 transition-all text-[10px] font-medium shadow-sm z-10"
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
          <Badge
            v-if="isInfoCc"
            variant="secondary"
            class="text-[9px] px-1.5 py-0 h-4 font-medium bg-zinc-100 text-zinc-600 border border-zinc-300"
          >
            zur Info (CC)
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
