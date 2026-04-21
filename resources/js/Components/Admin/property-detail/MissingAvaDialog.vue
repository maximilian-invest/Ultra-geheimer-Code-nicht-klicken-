<script setup>
import { ref, watch } from 'vue'
import { Paperclip } from 'lucide-vue-next'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

const props = defineProps({
  open: { type: Boolean, default: false },
  propertyId: { type: [Number, String], required: true },
  uploading: { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'upload', 'skip'])

const fileInput = ref(null)
const chosenFile = ref(null)

watch(() => props.open, (isOpen) => {
  if (isOpen) chosenFile.value = null
})

function onFileChange(e) {
  const file = e.target.files?.[0]
  if (file) chosenFile.value = file
}

function onUploadClick() {
  if (!chosenFile.value) {
    fileInput.value?.click()
    return
  }
  emit('upload', chosenFile.value)
}

function onSkipClick() {
  emit('skip')
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Alleinvermittlungsauftrag fehlt</DialogTitle>
        <DialogDescription>
          Für dieses Template brauchen wir den unterzeichneten Alleinvermittlungsauftrag als Anhang.
        </DialogDescription>
      </DialogHeader>

      <div class="py-4">
        <label for="ava-upload"
               class="flex flex-col items-center justify-center py-8 px-4 border-2 border-dashed border-[#fed7aa] bg-[#fff7ed] rounded-lg cursor-pointer hover:bg-[#ffedd5] transition-colors">
          <Paperclip class="w-8 h-8 text-[#EE7600] mb-2" />
          <div v-if="!chosenFile" class="text-sm font-semibold text-[#7c2d12]">PDF hier ablegen oder klicken</div>
          <div v-if="!chosenFile" class="text-xs text-[#a16207] mt-1">Max. 10 MB</div>
          <div v-else class="text-sm font-medium text-[#7c2d12] truncate max-w-full">{{ chosenFile.name }}</div>
          <div v-if="chosenFile" class="text-xs text-[#a16207] mt-1">
            {{ Math.round(chosenFile.size / 1024) }} KB — Klick zum Ändern
          </div>
          <input ref="fileInput" id="ava-upload" type="file" accept="application/pdf" class="hidden" @change="onFileChange" />
        </label>
      </div>

      <DialogFooter class="flex-col sm:flex-row gap-2">
        <Button variant="ghost" size="sm" @click="onSkipClick" :disabled="uploading">Ohne Anhang senden</Button>
        <Button size="sm" class="bg-[#EE7600] hover:bg-[#EE7600]/90 text-white" @click="onUploadClick" :disabled="uploading">
          <span v-if="uploading">Lädt hoch…</span>
          <span v-else-if="chosenFile">Hochladen &amp; senden</span>
          <span v-else>Datei wählen</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
