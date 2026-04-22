<script setup>
import { ref, inject, watch, computed } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ChevronDown, UserCheck } from 'lucide-vue-next';

const props = defineProps({
  form: { type: Object, required: true },
});

const API = inject('API');
const suggestions = ref([]);
const showSuggestions = ref(false);
let debounce = null;

// Wenn der Nutzer im Namen-Feld tippt: Vorschlaege holen, aber den Namen
// SOFORT in form.owner.name uebernehmen. Kein „Neuer-Eigentuemer"-Zwischenschritt mehr.
async function onNameInput() {
  const q = (props.form.owner.name || '').trim();

  // Wenn der User den Namen manuell aendert, verlieren wir die customer-id.
  // (Sonst wuerde der Submit denken, wir haben einen bestehenden Kontakt.)
  if (props.form.owner_customer_id) {
    props.form.owner_customer_id = null;
  }

  if (q.length < 2) {
    suggestions.value = [];
    showSuggestions.value = false;
    return;
  }

  if (debounce) clearTimeout(debounce);
  debounce = setTimeout(async () => {
    try {
      const r = await fetch(API.value + '&action=contacts&search=' + encodeURIComponent(q));
      const d = await r.json();
      suggestions.value = (d.contacts || []).slice(0, 5);
      showSuggestions.value = suggestions.value.length > 0;
    } catch (e) {
      suggestions.value = [];
      showSuggestions.value = false;
    }
  }, 300);
}

function pickContact(c) {
  // Uebernimmt name/email/phone aus dem bestehenden Kontakt.
  // Adresse wird nicht ueberschrieben falls schon eingetragen.
  props.form.owner.name  = c.full_name || '';
  props.form.owner.email = c.email || props.form.owner.email || '';
  props.form.owner.phone = c.phone || props.form.owner.phone || '';
  props.form.owner_customer_id = c.id || null;
  suggestions.value = [];
  showSuggestions.value = false;
}

// Close suggestions on blur (mit kleiner Verzoegerung damit click auf Vorschlag durchgeht)
function onNameBlur() {
  setTimeout(() => { showSuggestions.value = false; }, 200);
}

// Wenn der User ueber die URL / Auto-Save einen existierenden owner_customer_id
// geladen hat, sollte das Namensfeld nicht mehr Vorschlaege triggern.
watch(() => props.form.owner_customer_id, (v) => {
  if (v) showSuggestions.value = false;
});

const initial = computed(() => {
  const n = (props.form.owner?.name || '').trim();
  if (!n) return '?';
  return n.charAt(0).toUpperCase();
});
</script>

<template>
  <div class="space-y-4">

    <!-- Selected Owner (wenn bestehender Kontakt) -->
    <Alert v-if="form.owner_customer_id" variant="success">
      <UserCheck class="size-4" />
      <AlertDescription>
        <div class="flex items-center gap-3">
          <Avatar size="sm">
            <AvatarFallback>{{ initial }}</AvatarFallback>
          </Avatar>
          <div class="flex-1 min-w-0">
            <div class="font-medium truncate">{{ form.owner.name }}</div>
            <div class="text-xs truncate opacity-80">
              {{ [form.owner.email, form.owner.phone].filter(Boolean).join(' · ') || '—' }}
            </div>
          </div>
          <span class="text-xs whitespace-nowrap">Bestehender Kontakt</span>
        </div>
      </AlertDescription>
    </Alert>

    <!-- Name (immer sichtbar, treibt Autocomplete) -->
    <div class="relative space-y-2">
      <Label for="owner-name">
        Name des Eigentümers <span class="text-destructive">*</span>
      </Label>
      <Input
        id="owner-name"
        v-model="form.owner.name"
        @input="onNameInput"
        @focus="onNameInput"
        @blur="onNameBlur"
        placeholder="Vor- und Nachname"
        autocomplete="off"
      />
      <!-- Autocomplete-Panel (nur wenn es Vorschlaege gibt) -->
      <Card
        v-if="showSuggestions && suggestions.length"
        class="absolute left-0 right-0 top-full mt-1 shadow-lg z-20 max-h-64 overflow-y-auto p-0"
      >
        <button
          v-for="c in suggestions" :key="c.id"
          type="button"
          @mousedown.prevent="pickContact(c)"
          class="w-full text-left px-3 py-2 hover:bg-accent hover:text-accent-foreground transition-colors border-b last:border-b-0"
        >
          <div class="text-sm font-medium">{{ c.full_name }}</div>
          <div class="text-xs text-muted-foreground">
            {{ [c.email, c.phone].filter(Boolean).join(' · ') }}
          </div>
        </button>
        <div class="px-3 py-2 text-xs text-muted-foreground border-t bg-muted/50">
          Auswählen übernimmt E-Mail/Telefon. Oder einfach weiter tippen für neuen Eigentümer.
        </div>
      </Card>
    </div>

    <!-- Email + Phone (immer sichtbar, optional, aber E-Mail empfohlen fuers PDF) -->
    <div class="space-y-4">
      <div class="space-y-2">
        <Label for="owner-email">
          E-Mail <span class="text-xs font-normal text-muted-foreground">(für PDF-Versand empfohlen)</span>
        </Label>
        <Input
          id="owner-email"
          v-model="form.owner.email"
          type="email"
          placeholder="name@example.com"
          autocomplete="off"
        />
      </div>
      <div class="space-y-2">
        <Label for="owner-phone">Telefon</Label>
        <Input
          id="owner-phone"
          v-model="form.owner.phone"
          type="tel"
          inputmode="tel"
          placeholder="+43 …"
          autocomplete="off"
        />
      </div>
    </div>

    <!-- Wohnsitz (optional, zusammenklappbar) -->
    <Collapsible>
      <Card>
        <CollapsibleTrigger as-child>
          <button type="button" class="w-full flex items-center justify-between p-3 text-left">
            <span class="text-xs font-medium text-muted-foreground">
              Wohnsitz-Adresse <span class="text-xs">(optional — nur falls abweichend vom Objekt)</span>
            </span>
            <ChevronDown class="h-4 w-4 text-muted-foreground transition-transform data-[state=open]:rotate-180" />
          </button>
        </CollapsibleTrigger>
        <CollapsibleContent>
          <div class="p-3 pt-0 space-y-2">
            <Input
              v-model="form.owner.address"
              placeholder="Straße + Hausnr."
            />
            <div class="grid grid-cols-[1fr_2fr] gap-2">
              <Input v-model="form.owner.zip" placeholder="PLZ" inputmode="numeric" />
              <Input v-model="form.owner.city" placeholder="Stadt" />
            </div>
          </div>
        </CollapsibleContent>
      </Card>
    </Collapsible>

  </div>
</template>
