<script setup>
import { ref, reactive, onMounted, inject } from "vue";
import { Star, Trash2, Upload, ImageOff } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const props = defineProps({
  property: { type: Object, required: true },
});

const emit = defineEmits(["dirty"]);

const API = inject("API");
const toast = inject("toast");

// ─── Images ───
const images = ref([]);
const imageUploading = ref(false);
const dragOver = ref(false);
const imageInput = ref(null);

const imageCategories = [
  { value: "titelbild",       label: "Titelbild" },
  { value: "innenansicht",    label: "Innenansicht" },
  { value: "aussenansicht",   label: "Aussenansicht" },
  { value: "grundriss",       label: "Grundriss" },
  { value: "badezimmer",      label: "Badezimmer" },
  { value: "kueche",          label: "Kueche" },
  { value: "schlafzimmer",    label: "Schlafzimmer" },
  { value: "wohnzimmer",      label: "Wohnzimmer" },
  { value: "balkon_terrasse", label: "Balkon/Terrasse" },
  { value: "garten",          label: "Garten" },
  { value: "garage_stellplatz", label: "Garage/Stellplatz" },
  { value: "keller",          label: "Keller" },
  { value: "umgebung",        label: "Umgebung" },
  { value: "sonstiges",       label: "Sonstiges" },
];

async function loadImages() {
  if (!props.property?.id) return;
  try {
    const res = await fetch(API.value + "&action=list_property_images&property_id=" + props.property.id);
    const data = await res.json();
    images.value = data.images || [];
  } catch (e) {
    toast("Fehler beim Laden der Bilder: " + e.message);
  }
}

async function handleImageUpload(e) {
  const files = e.target?.files || e.dataTransfer?.files;
  if (!files || !files.length || !props.property?.id) return;
  dragOver.value = false;
  imageUploading.value = true;
  try {
    for (const file of files) {
      const fd = new FormData();
      fd.append("images[]", file);
      fd.append("property_id", props.property.id);
      await fetch(API.value + "&action=upload_property_image", { method: "POST", body: fd });
    }
    await loadImages();
    toast("Bilder hochgeladen");
    emit("dirty");
  } catch (err) {
    toast("Upload fehlgeschlagen: " + err.message);
  }
  imageUploading.value = false;
  if (e.target) e.target.value = "";
}

async function setTitleImage(img) {
  try {
    await fetch(API.value + "&action=update_property_image", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: img.id, is_title_image: 1 }),
    });
    images.value.forEach(i => (i.is_title_image = i.id === img.id ? 1 : 0));
    toast("Titelbild gesetzt");
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

async function deleteImage(img) {
  if (!confirm("Bild wirklich loeschen?")) return;
  try {
    await fetch(API.value + "&action=delete_property_image", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: img.id }),
    });
    images.value = images.value.filter(i => i.id !== img.id);
    toast("Bild geloescht");
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

async function updateImageCategory(img, cat) {
  try {
    await fetch(API.value + "&action=update_property_image", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: img.id, category: cat }),
    });
    img.category = cat;
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

// ─── Text descriptions (local copy for dirty tracking) ───
const texts = reactive({
  realty_description:    "",
  location_description:  "",
  equipment_description: "",
  other_description:     "",
  highlights:            "",
});

const textFields = [
  { key: "realty_description",    label: "Objektbeschreibung",      placeholder: "Allgemeine Beschreibung des Objekts..." },
  { key: "location_description",  label: "Lagebeschreibung",        placeholder: "Beschreibung der Lage und Umgebung..." },
  { key: "equipment_description", label: "Ausstattungsbeschreibung", placeholder: "Detaillierte Ausstattung..." },
  { key: "other_description",     label: "Sonstige Angaben",        placeholder: "Weitere relevante Informationen..." },
  { key: "highlights",            label: "Highlights",              placeholder: "Besondere Highlights (zeilenweise)..." },
];

function initTexts() {
  for (const f of textFields) {
    texts[f.key] = props.property[f.key] || "";
  }
}

function onTextInput() {
  emit("dirty");
}

// ─── Save (exposed) ───
async function save() {
  try {
    const payload = { id: props.property.id };
    for (const f of textFields) {
      payload[f.key] = texts[f.key];
    }
    const r = await fetch(API.value + "&action=save_full_property", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ ...props.property, ...payload }),
    });
    const d = await r.json();
    if (d.success) {
      // sync back to parent property object
      for (const f of textFields) {
        props.property[f.key] = texts[f.key];
      }
      toast("Texte gespeichert");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

defineExpose({ save });

onMounted(() => {
  initTexts();
  loadImages();
});
</script>

<template>
  <div class="space-y-6">

    <!-- ── Bilder ── -->
    <div>
      <h2 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider mb-4">Bilder</h2>

      <!-- No property ID warning -->
      <div v-if="!property?.id" class="flex items-center gap-3 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-700">
        <ImageOff class="w-4 h-4 shrink-0" />
        Bitte speichere das Objekt zuerst, bevor du Bilder hochlaedst.
      </div>

      <template v-else>
        <!-- Dropzone -->
        <div
          @drop.prevent="handleImageUpload"
          @dragover.prevent="dragOver = true"
          @dragleave="dragOver = false"
          @click="imageInput.click()"
          :class="[
            'border-2 border-dashed rounded-xl p-8 text-center transition-all duration-200 cursor-pointer select-none',
            dragOver
              ? 'border-zinc-800 bg-zinc-50'
              : 'border-zinc-200 hover:border-zinc-400 hover:bg-zinc-50/50'
          ]">
          <input
            ref="imageInput"
            type="file"
            multiple
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="handleImageUpload"
          />
          <Upload class="w-7 h-7 mx-auto text-zinc-400 mb-2" />
          <p class="text-sm font-medium text-zinc-700">Bilder hierher ziehen oder klicken</p>
          <p class="text-xs text-zinc-400 mt-1">JPG, PNG, WebP — Mehrere gleichzeitig moeglich</p>
        </div>

        <!-- Upload spinner -->
        <div v-if="imageUploading" class="flex items-center gap-2 text-sm text-zinc-500 mt-3">
          <div class="w-4 h-4 border-2 border-zinc-400 border-t-transparent rounded-full animate-spin"></div>
          Lade hoch...
        </div>

        <!-- Image grid -->
        <div v-if="images.length" class="grid grid-cols-5 max-sm:grid-cols-3 gap-2 mt-4">
          <div
            v-for="img in images"
            :key="img.id"
            class="group relative rounded-lg overflow-hidden border border-zinc-200 hover:border-zinc-300 transition-all duration-200"
          >
            <!-- Thumbnail -->
            <div class="aspect-[4/3] bg-zinc-100">
              <img
                :src="img.url"
                :alt="img.original_name || 'Bild'"
                class="w-full h-full object-cover"
                loading="lazy"
              />
            </div>

            <!-- Title badge -->
            <div v-if="img.is_title_image" class="absolute top-1.5 left-1.5 z-10">
              <Badge class="text-[10px] px-1.5 py-0.5 gap-0.5 bg-zinc-900 text-white border-0">
                <Star class="w-2.5 h-2.5" /> Titel
              </Badge>
            </div>

            <!-- Hover overlay -->
            <div class="absolute inset-0 bg-black/55 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex flex-col justify-end p-2 gap-1">
              <!-- Set title image -->
              <button
                v-if="!img.is_title_image"
                @click.stop="setTitleImage(img)"
                class="w-full flex items-center justify-center gap-1 px-2 py-1 bg-white/90 hover:bg-white text-zinc-900 text-[11px] font-medium rounded-md transition-colors active:scale-[0.97]"
              >
                <Star class="w-3 h-3" /> Titelbild
              </button>

              <!-- Category select -->
              <Select :model-value="img.category || ''" @update:model-value="(v) => updateImageCategory(img, v)">
                <SelectTrigger class="h-6 text-[11px] bg-white/90 border-0 rounded-md px-2">
                  <SelectValue placeholder="Kategorie" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="c in imageCategories" :key="c.value" :value="c.value" class="text-xs">
                    {{ c.label }}
                  </SelectItem>
                </SelectContent>
              </Select>

              <!-- Delete -->
              <button
                @click.stop="deleteImage(img)"
                class="w-full flex items-center justify-center gap-1 px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-[11px] font-medium rounded-md transition-colors active:scale-[0.97]"
              >
                <Trash2 class="w-3 h-3" /> Loeschen
              </button>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="!imageUploading" class="flex flex-col items-center justify-center py-10 text-center mt-4">
          <ImageOff class="w-8 h-8 text-zinc-300 mb-2" />
          <p class="text-sm text-zinc-400">Noch keine Bilder hochgeladen.</p>
        </div>
      </template>
    </div>

    <!-- ── Beschreibungen ── -->
    <div class="border-t border-border/50 pt-6">
      <h2 class="text-sm font-semibold text-zinc-900 uppercase tracking-wider mb-4">Beschreibungen</h2>

      <div class="space-y-5">
        <div v-for="f in textFields" :key="f.key" class="space-y-1.5">
          <label class="block text-xs font-medium text-zinc-500">{{ f.label }}</label>
          <Textarea
            v-model="texts[f.key]"
            :placeholder="f.placeholder"
            rows="5"
            class="w-full resize-y text-sm bg-zinc-50 border-zinc-200 focus:bg-white transition-colors"
            @input="onTextInput"
          />
        </div>
      </div>
    </div>

  </div>
</template>
