<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const canvas = ref(null);
let ctx = null;
let drawing = false;
let hasDrawn = false;
let lastX = 0, lastY = 0;

function setupCanvas() {
  const c = canvas.value;
  if (!c) return;
  const rect = c.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  c.width  = rect.width  * dpr;
  c.height = rect.height * dpr;
  ctx = c.getContext('2d');
  ctx.scale(dpr, dpr);
  ctx.strokeStyle = '#000';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
}

function pos(e) {
  const rect = canvas.value.getBoundingClientRect();
  const t = e.touches ? e.touches[0] : e;
  return { x: t.clientX - rect.left, y: t.clientY - rect.top };
}

function start(e) {
  e.preventDefault();
  drawing = true;
  const { x, y } = pos(e);
  lastX = x; lastY = y;
}

function move(e) {
  if (!drawing) return;
  e.preventDefault();
  const { x, y } = pos(e);
  ctx.beginPath();
  ctx.moveTo(lastX, lastY);
  ctx.lineTo(x, y);
  ctx.stroke();
  lastX = x; lastY = y;
  hasDrawn = true;
}

function end() {
  if (!drawing) return;
  drawing = false;
  if (hasDrawn) {
    emit('update:modelValue', canvas.value.toDataURL('image/png'));
  }
}

function clear() {
  ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
  hasDrawn = false;
  emit('update:modelValue', '');
}

defineExpose({ clear });

onMounted(() => {
  setupCanvas();
  window.addEventListener('resize', setupCanvas);
});

onUnmounted(() => {
  window.removeEventListener('resize', setupCanvas);
});
</script>

<template>
  <div class="space-y-2">
    <div class="relative">
      <canvas
        ref="canvas"
        class="w-full h-48 bg-white border-2 border-border rounded-md touch-none"
        @mousedown="start" @mousemove="move" @mouseup="end" @mouseleave="end"
        @touchstart="start" @touchmove="move" @touchend="end"
      ></canvas>
      <div v-if="!modelValue" class="absolute inset-0 flex items-center justify-center pointer-events-none text-zinc-400 text-sm">
        Hier unterschreiben
      </div>
    </div>
    <button @click="clear" type="button" class="text-xs text-muted-foreground underline">
      Zurücksetzen
    </button>
  </div>
</template>
