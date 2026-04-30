<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { X, Settings2 } from "lucide-vue-next";

const props = defineProps({
  currentColor: { type: String, default: null },
  labels: { type: Object, required: true },
  showSettings: { type: Boolean, default: true },
  // "right" (default) | "left" — wo der Picker relativ zum Trigger erscheint
  align: { type: String, default: "right" },
});
const emit = defineEmits(["select", "close", "open-settings"]);

const COLORS = [
  { id: "red",    bg: "bg-red-500" },
  { id: "orange", bg: "bg-orange-500" },
  { id: "yellow", bg: "bg-yellow-400" },
  { id: "green",  bg: "bg-emerald-500" },
  { id: "blue",   bg: "bg-blue-500" },
  { id: "purple", bg: "bg-purple-500" },
];

function pick(color) {
  emit("select", color);
}
function clear() {
  emit("select", null);
}

const root = ref(null);
function onDocMousedown(e) {
  if (root.value && !root.value.contains(e.target)) emit("close");
}
function onKeydown(e) {
  if (e.key === "Escape") emit("close");
}
onMounted(() => {
  document.addEventListener("mousedown", onDocMousedown);
  document.addEventListener("keydown", onKeydown);
});
onBeforeUnmount(() => {
  document.removeEventListener("mousedown", onDocMousedown);
  document.removeEventListener("keydown", onKeydown);
});
</script>

<template>
  <div
    ref="root"
    class="absolute top-full mt-1 z-50 bg-white border border-zinc-200 rounded-lg shadow-lg p-1.5 w-56"
    :class="align === 'left' ? 'left-0' : 'right-0'"
    @click.stop
  >
    <div class="text-[10px] uppercase tracking-wide text-zinc-400 font-semibold px-2 pb-1.5 pt-0.5">
      Markierung
    </div>
    <button
      v-for="c in COLORS"
      :key="c.id"
      type="button"
      class="w-full flex items-center gap-2 px-2 py-1.5 rounded hover:bg-zinc-100 transition-colors text-left"
      :class="currentColor === c.id ? 'bg-zinc-100' : ''"
      @click="pick(c.id)"
    >
      <span class="w-3 h-3 rounded-full flex-shrink-0" :class="c.bg"></span>
      <span class="text-[12px] flex-1 truncate text-zinc-700">{{ labels[c.id] || c.id }}</span>
      <span v-if="currentColor === c.id" class="text-[9px] uppercase text-zinc-400 tracking-wide">aktiv</span>
    </button>
    <div class="border-t border-zinc-100 mt-1 pt-1">
      <button
        v-if="currentColor"
        type="button"
        class="w-full flex items-center gap-2 px-2 py-1.5 rounded hover:bg-zinc-100 text-left text-[12px] text-zinc-600"
        @click="clear()"
      >
        <X class="w-3 h-3" />
        Markierung entfernen
      </button>
      <button
        v-if="showSettings"
        type="button"
        class="w-full flex items-center gap-2 px-2 py-1.5 rounded hover:bg-zinc-100 text-left text-[11px] text-zinc-500"
        @click="emit('open-settings')"
      >
        <Settings2 class="w-3 h-3" />
        Bezeichnungen anpassen…
      </button>
    </div>
  </div>
</template>
