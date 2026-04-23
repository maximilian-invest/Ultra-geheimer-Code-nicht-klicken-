<script setup>
import { ref, watch } from 'vue'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const props = defineProps({
  open: { type: Boolean, default: false },
  manager: { type: Object, default: null },
  prefillName: { type: String, default: '' },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'save', 'cancel'])

const form = ref({
  company_name: '', email: '', address_street: '', address_zip: '', address_city: '',
  phone: '', contact_person: '', notes: '',
})

const errorMessage = ref('')

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    errorMessage.value = ''
    if (props.manager) {
      form.value = {
        company_name: props.manager.company_name || '',
        email: props.manager.email || '',
        address_street: props.manager.address_street || '',
        address_zip: props.manager.address_zip || '',
        address_city: props.manager.address_city || '',
        phone: props.manager.phone || '',
        contact_person: props.manager.contact_person || '',
        notes: props.manager.notes || '',
      }
    } else {
      form.value = {
        company_name: props.prefillName || '',
        email: '', address_street: '', address_zip: '', address_city: '',
        phone: '', contact_person: '', notes: '',
      }
    }
  }
})

const isEditing = () => !!props.manager

function onSubmit() {
  errorMessage.value = ''
  const name = (form.value.company_name || '').trim()
  const email = (form.value.email || '').trim()
  if (!name) { errorMessage.value = 'Firmenname ist Pflicht.'; return }
  if (!email) { errorMessage.value = 'E-Mail ist Pflicht.'; return }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
    errorMessage.value = 'E-Mail-Format ist ungültig.'; return
  }
  const payload = { ...form.value }
  if (isEditing()) payload.id = props.manager.id
  emit('save', payload)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent
      class="sm:max-w-lg"
      @pointer-down-outside.prevent
      @interact-outside.prevent
    >
      <DialogHeader>
        <DialogTitle>{{ isEditing() ? 'Hausverwaltung bearbeiten' : 'Neue Hausverwaltung' }}</DialogTitle>
        <DialogDescription>
          Felder mit <span class="text-red-600">*</span> sind Pflicht.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4 py-2">
        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">
            Firmenname <span class="text-red-600">*</span>
          </label>
          <Input v-model="form.company_name" placeholder="z. B. ImmoFirst Hausverwaltung GmbH" />
        </div>

        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">
            E-Mail <span class="text-red-600">*</span>
          </label>
          <Input v-model="form.email" type="email" placeholder="verwaltung@…" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label class="text-xs font-medium text-muted-foreground mb-1 block">Straße</label>
            <Input v-model="form.address_street" placeholder="z. B. Getreidegasse 18" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted-foreground mb-1 block">PLZ</label>
            <Input v-model="form.address_zip" placeholder="5020" />
          </div>
        </div>

        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Ort</label>
          <Input v-model="form.address_city" placeholder="Salzburg" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-muted-foreground mb-1 block">Telefon</label>
            <Input v-model="form.phone" placeholder="+43 …" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted-foreground mb-1 block">Ansprechpartner</label>
            <Input v-model="form.contact_person" placeholder="z. B. Frau Meier" />
          </div>
        </div>

        <div>
          <label class="text-xs font-medium text-muted-foreground mb-1 block">Notizen</label>
          <textarea v-model="form.notes" rows="2" class="w-full text-sm rounded-md border border-input px-3 py-2 bg-background" placeholder="Interne Notizen (optional)"></textarea>
        </div>

        <div v-if="errorMessage" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-2">
          {{ errorMessage }}
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" size="sm" @click="emit('update:open', false)" :disabled="saving">Abbrechen</Button>
        <Button size="sm" @click="onSubmit" :disabled="saving">
          <span v-if="saving">Speichere…</span>
          <span v-else>{{ isEditing() ? 'Speichern' : 'Anlegen' }}</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
