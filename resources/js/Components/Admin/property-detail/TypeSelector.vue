<script setup>
import { Building2, Home, LandPlot, Building, Warehouse } from "lucide-vue-next";

const emit = defineEmits(["selected"]);

const typeCategories = [
  { key: "wohnung", label: "Wohnung", icon: Building2, desc: "Eigentumswohnung, Gartenwohnung, Penthouse, etc.", types: ["Eigentumswohnung", "Gartenwohnung", "Dachgeschosswohnung", "Penthouse", "Maisonette"], color: "bg-blue-50 border-blue-200 hover:border-blue-400 text-blue-700" },
  { key: "haus", label: "Haus", icon: Home, desc: "Einfamilienhaus, Reihenhaus, Doppelhaushaelfte", types: ["Haus", "Einfamilienhaus", "Reihenhaus", "Doppelhaushaelfte"], color: "bg-emerald-50 border-emerald-200 hover:border-emerald-400 text-emerald-700" },
  { key: "grundstueck", label: "Grundstueck", icon: LandPlot, desc: "Baugrund, Freizeitgrund, Landwirtschaft", types: ["Grundstueck"], color: "bg-amber-50 border-amber-200 hover:border-amber-400 text-amber-700" },
  { key: "neubauprojekt", label: "Neubauprojekt", icon: Building, desc: "Projekt mit Einheiten & Stellplaetzen", types: ["Neubauprojekt", "Neubau"], color: "bg-violet-50 border-violet-200 hover:border-violet-400 text-violet-700" },
  { key: "sonstige", label: "Gewerbe / Sonstiges", icon: Warehouse, desc: "Buero, Anlage, Sonstiges", types: ["Gewerbe", "Buero", "Anlage", "Sonstiges"], color: "bg-zinc-50 border-zinc-200 hover:border-zinc-400 text-zinc-700" },
];

const categoryMap = { neubauprojekt: 'newbuild', haus: 'house', wohnung: 'apartment', grundstueck: 'land' };

function handleSelect(cat) {
  emit('selected', {
    ...cat,
    type: cat.types[0],
    category: categoryMap[cat.key] || '',
  });
}
</script>

<template>
  <div class="py-8">
    <h3 class="text-lg font-semibold text-foreground text-center mb-2">Was moechtest du anlegen?</h3>
    <p class="text-sm text-muted-foreground text-center mb-8">Waehle den Typ -- die passenden Felder werden automatisch angezeigt.</p>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 max-w-3xl mx-auto">
      <button v-for="cat in typeCategories" :key="cat.key"
        @click="handleSelect(cat)"
        :class="['flex flex-col items-center gap-3 p-6 rounded-xl border-2 transition-all cursor-pointer active:scale-[0.97]', cat.color]">
        <div class="w-14 h-14 rounded-xl bg-white/80 flex items-center justify-center shadow-sm">
          <component :is="cat.icon" :size="28" />
        </div>
        <div class="text-center">
          <p class="text-sm font-semibold">{{ cat.label }}</p>
          <p class="text-xs opacity-70 mt-1">{{ cat.desc }}</p>
        </div>
      </button>
    </div>
  </div>
</template>
