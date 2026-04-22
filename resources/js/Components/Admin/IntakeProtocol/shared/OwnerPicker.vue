<script setup>
import { ref, inject } from 'vue';

const props = defineProps({
  form: { type: Object, required: true },
});

const API = inject('API');
const search = ref('');
const results = ref([]);
const showNewForm = ref(false);
let debounce = null;

async function searchContacts(q) {
  if (q.trim().length < 2) { results.value = []; return; }
  if (debounce) clearTimeout(debounce);
  debounce = setTimeout(async () => {
    try {
      const r = await fetch(API.value + '&action=contacts&search=' + encodeURIComponent(q));
      const d = await r.json();
      results.value = (d.contacts || []).slice(0, 5);
    } catch (e) { results.value = []; }
  }, 300);
}

function pickContact(c) {
  props.form.owner = {
    name: c.full_name || '',
    email: c.email || '',
    phone: c.phone || '',
    address: '', zip: '', city: '',
  };
  props.form.owner_customer_id = c.id || null;
  search.value = '';
  results.value = [];
  showNewForm.value = false;
}

function openNewOwnerForm() {
  props.form.owner_customer_id = null;
  showNewForm.value = true;
}
</script>

<template>
  <div class="space-y-3">

    <div v-if="form.owner.name && !showNewForm" class="bg-white border border-border rounded-xl p-3 flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-orange-100 text-[#EE7600] flex items-center justify-center font-semibold">
        {{ (form.owner.name || '?').charAt(0).toUpperCase() }}
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-sm font-semibold truncate">{{ form.owner.name }}</div>
        <div class="text-xs text-muted-foreground truncate">{{ form.owner.email }}</div>
      </div>
      <button type="button" @click="form.owner = { name:'', email:'', phone:'', address:'', zip:'', city:'' }; form.owner_customer_id=null; showNewForm=false;"
              class="text-xs text-muted-foreground">Ändern</button>
    </div>

    <div v-else>
      <label class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground block mb-1">
        Eigentümer suchen oder anlegen
      </label>
      <input
        v-model="search"
        @input="searchContacts(search)"
        placeholder="Name oder E-Mail..."
        class="w-full h-11 rounded-lg border border-border px-3 bg-white"
      />
      <div v-if="results.length" class="bg-white border border-border rounded-lg mt-2 divide-y divide-border/40">
        <button v-for="c in results" :key="c.id" type="button" @click="pickContact(c)"
                class="w-full text-left px-3 py-2 hover:bg-zinc-50">
          <div class="text-sm font-medium">{{ c.full_name }}</div>
          <div class="text-xs text-muted-foreground">{{ c.email }}</div>
        </button>
      </div>

      <button type="button" @click="openNewOwnerForm" class="mt-2 text-sm text-[#EE7600] font-medium">
        + Neuer Eigentümer
      </button>
    </div>

    <div v-if="showNewForm" class="bg-white border border-border rounded-xl p-4 space-y-3">
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Name *</label>
        <input v-model="form.owner.name" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">E-Mail * <span class="text-[10px]">(für PDF-Versand)</span></label>
        <input v-model="form.owner.email" type="email" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Telefon</label>
        <input v-model="form.owner.phone" class="w-full h-11 rounded-lg border border-border px-3" />
      </div>
      <div>
        <label class="text-[11px] text-muted-foreground block mb-1">Adresse (Wohnsitz)</label>
        <input v-model="form.owner.address" class="w-full h-11 rounded-lg border border-border px-3 mb-2" placeholder="Straße Nr." />
        <div class="grid grid-cols-2 gap-2">
          <input v-model="form.owner.zip" placeholder="PLZ" class="h-11 rounded-lg border border-border px-3" />
          <input v-model="form.owner.city" placeholder="Stadt" class="h-11 rounded-lg border border-border px-3" />
        </div>
      </div>
    </div>

  </div>
</template>
