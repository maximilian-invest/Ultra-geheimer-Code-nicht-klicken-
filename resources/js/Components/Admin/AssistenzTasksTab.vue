<script setup>
import { ref, computed, inject, onMounted } from "vue";
import {
  CheckCircle2, Circle, Plus, Sparkles, Trash2, Edit3, Calendar,
  User, Home, ArrowUpRight, Clock, AlertTriangle, Filter, Send
} from "lucide-vue-next";

const API = inject("API");
const toast = inject("toast");
const userType = inject("userType", ref("makler"));
const isAssistenz = computed(() => ["assistenz","backoffice"].includes(userType.value));

const tasks = ref([]);
const loading = ref(false);
const brokerFilter = ref("all");
const brokerList = ref([]);
const teamMembers = ref([]);
const userId = inject("userId", ref(null));
const showDone = ref(false);
const showCompleted = ref(false);
const showAddForm = ref(false);
const aiLoading = ref(false);

const newTask = ref({ text: "", priority: "medium", due_date: "", property_id: null, assigned_to: null });

const editingId = ref(null);
const editAssignedTo = ref(null);
const editTitle = ref("");
const editPriority = ref("medium");
const editDueDate = ref("");

const stats = computed(() => {
  const all = tasks.value;
  const open = all.filter(t => !t.is_done);
  const today = new Date().toISOString().slice(0, 10);
  return {
    open: open.length,
    today: open.filter(t => t.due_date && t.due_date.slice(0, 10) === today).length,
    overdue: open.filter(t => t.due_date && t.due_date.slice(0, 10) < today).length,
    doneWeek: all.filter(t => t.is_done && t.updated_at && new Date(t.updated_at) > new Date(Date.now() - 7 * 86400000)).length,
  };
});

// Split tasks into "mine" and "delegated"
const myTasks = computed(() => {
  return tasks.value.filter(t => !t.is_done && (t.assigned_to == userId.value || (!t.assigned_to && t.created_by == userId.value)));
});

const delegatedTasks = computed(() => {
  return tasks.value.filter(t => !t.is_done && t.assigned_to && t.assigned_to != userId.value && (t.created_by == userId.value || t.assigned_by == userId.value));
});

const completedTasks = computed(() => {
  return tasks.value.filter(t => t.is_done).sort((a, b) => {
    const da = a.completed_at || a.updated_at || '';
    const db = b.completed_at || b.updated_at || '';
    return db.localeCompare(da);
  });
});

function groupByPriority(list) {
  const groups = { critical: [], high: [], medium: [], low: [] };
  for (const t of list) {
    const p = t.priority || "medium";
    if (groups[p]) groups[p].push(t);
    else groups.medium.push(t);
  }
  return groups;
}

const priorityLabels = { critical: "Kritisch", high: "Hoch", medium: "Mittel", low: "Niedrig" };
const priorityColors = {
  critical: "bg-red-100 text-red-700 border-red-200",
  high: "bg-orange-100 text-orange-700 border-orange-200",
  medium: "bg-blue-100 text-blue-700 border-blue-200",
  low: "bg-zinc-100 text-zinc-600 border-zinc-200",
};
const priorityDots = { critical: "bg-red-500", high: "bg-orange-500", medium: "bg-blue-500", low: "bg-zinc-400" };

async function loadTasks() {
  loading.value = true;
  try {
    let url = API.value + "&action=getTasks&done=1" + (isAssistenz.value ? "&scope=assistenz" : "");
    if (brokerFilter.value !== "all") url += "&broker_filter=" + brokerFilter.value;
    const r = await fetch(url);
    const d = await r.json();
    tasks.value = d.tasks || [];
  } catch (e) { toast("Fehler: " + e.message); }
  loading.value = false;
}

async function loadBrokers() {
  try {
    const r = await fetch(API.value + "&action=list_brokers");
    const d = await r.json();
    brokerList.value = (d.brokers || []).filter(b => ["admin", "makler"].includes(b.user_type));
    teamMembers.value = (d.brokers || []).filter(b => b.id !== userId?.value);
  } catch (e) {}
}

async function addTask() {
  if (!newTask.value.text.trim()) return;
  try {
    const r = await fetch(API.value + "&action=addTask", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify(newTask.value),
    });
    const d = await r.json();
    if (d.success) {
      toast("Aufgabe erstellt");
      newTask.value = { text: "", priority: "medium", due_date: "", property_id: null, assigned_to: null };
      showAddForm.value = false;
      loadTasks();
    }
  } catch (e) { toast("Fehler: " + e.message); }
}

async function completeTask(task) {
  try {
    await fetch(API.value + "&action=doneTask", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ task_id: task.id }),
    });
    loadTasks();
    toast("Erledigt");
  } catch (e) { toast("Fehler: " + e.message); }
}

async function deleteTask(task) {
  try {
    await fetch(API.value + "&action=delete_task", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: task.id }),
    });
    tasks.value = tasks.value.filter(t => t.id !== task.id);
    toast("Geloescht");
  } catch (e) { toast("Fehler: " + e.message); }
}

function startEdit(task) {
  editingId.value = task.id;
  editTitle.value = task.title;
  editPriority.value = task.priority || "medium";
  editDueDate.value = task.due_date ? task.due_date.slice(0, 10) : "";
  editAssignedTo.value = task.assigned_to || null;
}

async function saveEdit() {
  try {
    await fetch(API.value + "&action=update_task", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: editingId.value, title: editTitle.value, priority: editPriority.value, due_date: editDueDate.value || null, assigned_to: editAssignedTo.value }),
    });
    editingId.value = null;
    loadTasks();
    toast("Aktualisiert");
  } catch (e) { toast("Fehler: " + e.message); }
}

async function generateAiTodos() {
  aiLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=generateTodos", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    const d = await r.json();
    if (d.generated !== undefined) {
      toast(d.generated + " Aufgaben generiert");
      loadTasks();
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) { toast("Fehler: " + e.message); }
  aiLoading.value = false;
}

function formatDate(d) {
  if (!d) return "";
  const dt = new Date(d);
  return dt.toLocaleDateString("de-AT", { day: "2-digit", month: "2-digit" });
}

function isOverdue(d) {
  if (!d) return false;
  return d.slice(0, 10) < new Date().toISOString().slice(0, 10);
}

function isToday(d) {
  if (!d) return false;
  return d.slice(0, 10) === new Date().toISOString().slice(0, 10);
}

onMounted(() => { loadBrokers(); loadTasks(); });
</script>

<template>
  <div class="max-w-6xl mx-auto space-y-6">

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="bg-white border border-zinc-200/80 rounded-2xl p-4 text-center">
        <div class="text-2xl font-bold text-zinc-900">{{ stats.open }}</div>
        <div class="text-xs text-zinc-500 mt-0.5">Offen</div>
      </div>
      <div class="bg-white border border-zinc-200/80 rounded-2xl p-4 text-center">
        <div class="text-2xl font-bold" :class="stats.today ? 'text-orange-600' : 'text-zinc-400'">{{ stats.today }}</div>
        <div class="text-xs text-zinc-500 mt-0.5">Heute faellig</div>
      </div>
      <div class="bg-white border border-zinc-200/80 rounded-2xl p-4 text-center">
        <div class="text-2xl font-bold" :class="stats.overdue ? 'text-red-600' : 'text-zinc-400'">{{ stats.overdue }}</div>
        <div class="text-xs text-zinc-500 mt-0.5">Ueberfaellig</div>
      </div>
      <div class="bg-white border border-zinc-200/80 rounded-2xl p-4 text-center">
        <div class="text-2xl font-bold text-emerald-600">{{ stats.doneWeek }}</div>
        <div class="text-xs text-zinc-500 mt-0.5">Diese Woche erledigt</div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="flex items-center gap-3 flex-wrap">
      <div v-if="isAssistenz" class="flex items-center gap-2">
        <Filter class="w-4 h-4 text-zinc-400" />
        <select v-model="brokerFilter" @change="loadTasks()" class="text-sm bg-white border border-zinc-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-zinc-900/10">
          <option value="all">Alle Makler</option>
          <option v-for="b in brokerList" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>

      <div class="flex-1"></div>

      <button @click="generateAiTodos()" :disabled="aiLoading"
        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl transition-all duration-200"
        :class="aiLoading ? 'bg-zinc-100 text-zinc-400' : 'bg-gradient-to-r from-violet-500 to-purple-600 text-white hover:from-violet-600 hover:to-purple-700 shadow-sm'">
        <Sparkles class="w-4 h-4" :class="{ 'animate-spin': aiLoading }" />
        {{ aiLoading ? 'Generiere...' : 'KI Aufgaben' }}
      </button>

      <button @click="showAddForm = !showAddForm"
        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium bg-zinc-900 text-white rounded-xl hover:bg-zinc-800 transition-all duration-200 shadow-sm">
        <Plus class="w-4 h-4" />
        Neue Aufgabe
      </button>
    </div>

    <!-- Add form -->
    <div v-if="showAddForm" class="bg-white border border-zinc-200/80 rounded-2xl p-5 space-y-3">
      <input v-model="newTask.text" @keyup.enter="addTask()" type="text" placeholder="Aufgabe beschreiben..."
        class="w-full px-4 py-3 bg-zinc-50 border border-zinc-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900/10 focus:bg-white transition-all" autofocus />
      <div class="flex items-center gap-3">
        <select v-model="newTask.priority" class="text-sm bg-zinc-50 border border-zinc-200 rounded-xl px-3 py-2 focus:outline-none">
          <option value="low">Niedrig</option>
          <option value="medium">Mittel</option>
          <option value="high">Hoch</option>
          <option value="critical">Kritisch</option>
        </select>
        <input v-model="newTask.due_date" type="date" class="text-sm bg-zinc-50 border border-zinc-200 rounded-xl px-3 py-2 focus:outline-none" />
        <select v-model="newTask.assigned_to" class="text-sm bg-zinc-50 border border-zinc-200 rounded-xl px-3 py-2 focus:outline-none">
          <option :value="null">Mir selbst</option>
          <option v-for="m in teamMembers" :key="m.id" :value="m.id">{{ m.name }}</option>
        </select>
        <div class="flex-1"></div>
        <button @click="showAddForm = false" class="text-sm text-zinc-400 hover:text-zinc-600">Abbrechen</button>
        <button @click="addTask()" class="px-4 py-2 text-sm font-medium bg-zinc-900 text-white rounded-xl hover:bg-zinc-800">Erstellen</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12 text-zinc-400 text-sm">Lade Aufgaben...</div>

    <!-- Two-column layout -->
    <div v-if="!loading" class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Column 1: Meine Aufgaben -->
      <div class="space-y-3">
        <div class="flex items-center gap-2 px-1 mb-2">
          <User class="w-4 h-4 text-zinc-500" />
          <span class="text-sm font-semibold text-zinc-700">Meine Aufgaben</span>
          <span class="text-xs text-zinc-400 bg-zinc-100 px-2 py-0.5 rounded-full">{{ myTasks.length }}</span>
        </div>

        <div v-if="myTasks.length === 0" class="bg-white border border-zinc-200/80 rounded-2xl p-8 text-center">
          <CheckCircle2 class="w-8 h-8 text-emerald-400 mx-auto mb-2" />
          <p class="text-sm text-zinc-500">Keine offenen Aufgaben</p>
        </div>

        <template v-for="(items, prio) in groupByPriority(myTasks)" :key="'my-' + prio">
          <div v-if="items.length" class="space-y-1.5">
            <div class="flex items-center gap-2 px-1">
              <span class="w-2 h-2 rounded-full" :class="priorityDots[prio]"></span>
              <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">{{ priorityLabels[prio] }}</span>
              <span class="text-xs text-zinc-400">({{ items.length }})</span>
            </div>
            <div v-for="task in items" :key="task.id"
              class="group bg-white border border-zinc-200/60 rounded-xl px-4 py-3 flex items-start gap-3 hover:border-zinc-300 hover:shadow-sm transition-all duration-200">
              <button @click="completeTask(task)" class="mt-0.5 flex-shrink-0 text-zinc-300 hover:text-emerald-500 transition-colors">
                <Circle class="w-5 h-5" />
              </button>
              <div v-if="editingId !== task.id" class="flex-1 min-w-0">
                <div class="text-sm text-zinc-900">{{ task.title }}</div>
                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                  <span v-if="task.created_by_name && task.created_by != userId" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 bg-violet-50 text-violet-600 rounded-md border border-violet-100">
                    <User class="w-2.5 h-2.5" />von {{ task.created_by_name }}
                  </span>
                  <span v-if="task.ref_id" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 bg-amber-50 text-amber-700 rounded-md border border-amber-100">
                    <Home class="w-2.5 h-2.5" />{{ task.ref_id }}
                  </span>
                  <span v-if="task.due_date" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded-md border"
                    :class="isOverdue(task.due_date) ? 'bg-red-50 text-red-600 border-red-100' : isToday(task.due_date) ? 'bg-orange-50 text-orange-600 border-orange-100' : 'bg-zinc-50 text-zinc-500 border-zinc-100'">
                    <Calendar class="w-2.5 h-2.5" />{{ formatDate(task.due_date) }}
                  </span>
                  <span v-if="task.source === 'ai'" class="text-[10px] font-medium px-1.5 py-0.5 bg-purple-50 text-purple-600 rounded-md border border-purple-100">KI</span>
                </div>
              </div>
              <div v-else class="flex-1 space-y-2">
                <input v-model="editTitle" class="w-full px-3 py-2 text-sm bg-zinc-50 border border-zinc-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-zinc-900/10" />
                <div class="flex items-center gap-2 flex-wrap">
                  <select v-model="editPriority" class="text-xs bg-zinc-50 border border-zinc-200 rounded-lg px-2 py-1.5">
                    <option value="low">Niedrig</option><option value="medium">Mittel</option><option value="high">Hoch</option><option value="critical">Kritisch</option>
                  </select>
                  <input v-model="editDueDate" type="date" class="text-xs bg-zinc-50 border border-zinc-200 rounded-lg px-2 py-1.5" />
                  <select v-model="editAssignedTo" class="text-xs bg-zinc-50 border border-zinc-200 rounded-lg px-2 py-1.5">
                    <option :value="null">Mir selbst</option>
                    <option v-for="m in teamMembers" :key="m.id" :value="m.id">{{ m.name }}</option>
                  </select>
                  <button @click="saveEdit()" class="text-xs px-2.5 py-1.5 bg-zinc-900 text-white rounded-lg">Speichern</button>
                  <button @click="editingId = null" class="text-xs px-2 py-1.5 text-zinc-400">Abbrechen</button>
                </div>
              </div>
              <div v-if="editingId !== task.id" class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                <button @click="startEdit(task)" class="p-1.5 text-zinc-400 hover:text-zinc-600 rounded-lg hover:bg-zinc-100 transition-colors"><Edit3 class="w-3.5 h-3.5" /></button>
                <button @click="deleteTask(task)" class="p-1.5 text-zinc-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-colors"><Trash2 class="w-3.5 h-3.5" /></button>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Column 2: Delegierte Aufgaben -->
      <div class="space-y-3">
        <div class="flex items-center gap-2 px-1 mb-2">
          <Send class="w-4 h-4 text-zinc-500" />
          <span class="text-sm font-semibold text-zinc-700">Delegierte Aufgaben</span>
          <span class="text-xs text-zinc-400 bg-zinc-100 px-2 py-0.5 rounded-full">{{ delegatedTasks.length }}</span>
        </div>

        <div v-if="delegatedTasks.length === 0" class="bg-white border border-zinc-200/80 rounded-2xl p-8 text-center">
          <Send class="w-8 h-8 text-zinc-300 mx-auto mb-2" />
          <p class="text-sm text-zinc-500">Keine delegierten Aufgaben</p>
        </div>

        <template v-for="(items, prio) in groupByPriority(delegatedTasks)" :key="'del-' + prio">
          <div v-if="items.length" class="space-y-1.5">
            <div class="flex items-center gap-2 px-1">
              <span class="w-2 h-2 rounded-full" :class="priorityDots[prio]"></span>
              <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">{{ priorityLabels[prio] }}</span>
              <span class="text-xs text-zinc-400">({{ items.length }})</span>
            </div>
            <div v-for="task in items" :key="task.id"
              class="group bg-white border border-zinc-200/60 rounded-xl px-4 py-3 flex items-start gap-3 hover:border-zinc-300 hover:shadow-sm transition-all duration-200">
              <div class="mt-0.5 flex-shrink-0 text-zinc-300">
                <component :is="task.is_done ? CheckCircle2 : Clock" class="w-5 h-5" />
              </div>
              <div v-if="editingId !== task.id" class="flex-1 min-w-0">
                <div class="text-sm text-zinc-900">{{ task.title }}</div>
                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                  <span v-if="task.assigned_to_name" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 bg-emerald-50 text-emerald-600 rounded-md border border-emerald-100">
                    <User class="w-2.5 h-2.5" />{{ task.assigned_to_name }}
                  </span>
                  <span v-if="task.ref_id" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 bg-amber-50 text-amber-700 rounded-md border border-amber-100">
                    <Home class="w-2.5 h-2.5" />{{ task.ref_id }}
                  </span>
                  <span v-if="task.due_date" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded-md border"
                    :class="isOverdue(task.due_date) ? 'bg-red-50 text-red-600 border-red-100' : isToday(task.due_date) ? 'bg-orange-50 text-orange-600 border-orange-100' : 'bg-zinc-50 text-zinc-500 border-zinc-100'">
                    <Calendar class="w-2.5 h-2.5" />{{ formatDate(task.due_date) }}
                  </span>
                  <span v-if="task.source === 'ai'" class="text-[10px] font-medium px-1.5 py-0.5 bg-purple-50 text-purple-600 rounded-md border border-purple-100">KI</span>
                </div>
              </div>
              <div v-else class="flex-1 space-y-2">
                <input v-model="editTitle" class="w-full px-3 py-2 text-sm bg-zinc-50 border border-zinc-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-zinc-900/10" />
                <div class="flex items-center gap-2 flex-wrap">
                  <select v-model="editPriority" class="text-xs bg-zinc-50 border border-zinc-200 rounded-lg px-2 py-1.5">
                    <option value="low">Niedrig</option><option value="medium">Mittel</option><option value="high">Hoch</option><option value="critical">Kritisch</option>
                  </select>
                  <input v-model="editDueDate" type="date" class="text-xs bg-zinc-50 border border-zinc-200 rounded-lg px-2 py-1.5" />
                  <select v-model="editAssignedTo" class="text-xs bg-zinc-50 border border-zinc-200 rounded-lg px-2 py-1.5">
                    <option :value="null">Mir selbst</option>
                    <option v-for="m in teamMembers" :key="m.id" :value="m.id">{{ m.name }}</option>
                  </select>
                  <button @click="saveEdit()" class="text-xs px-2.5 py-1.5 bg-zinc-900 text-white rounded-lg">Speichern</button>
                  <button @click="editingId = null" class="text-xs px-2 py-1.5 text-zinc-400">Abbrechen</button>
                </div>
              </div>
              <div v-if="editingId !== task.id" class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                <button @click="startEdit(task)" class="p-1.5 text-zinc-400 hover:text-zinc-600 rounded-lg hover:bg-zinc-100 transition-colors"><Edit3 class="w-3.5 h-3.5" /></button>
                <button @click="deleteTask(task)" class="p-1.5 text-zinc-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-colors"><Trash2 class="w-3.5 h-3.5" /></button>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Completed section -->
    <div v-if="completedTasks.length" class="mt-8">
      <button @click="showCompleted = !showCompleted"
        class="flex items-center gap-2 text-sm text-zinc-400 hover:text-zinc-600 transition-colors mb-3">
        <CheckCircle2 class="w-4 h-4" />
        <span>{{ completedTasks.length }} erledigt</span>
        <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': showCompleted }" viewBox="0 0 12 12"><path d="M3 5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>

      <div v-if="showCompleted" class="space-y-1.5">
        <div v-for="task in completedTasks" :key="task.id"
          class="bg-zinc-50/50 border border-zinc-100 rounded-xl px-4 py-3 flex items-center gap-3 opacity-60">
          <CheckCircle2 class="w-5 h-5 text-emerald-400 flex-shrink-0" />
          <div class="flex-1 min-w-0">
            <div class="text-sm text-zinc-500 line-through">{{ task.title }}</div>
            <div class="flex items-center gap-2 mt-1">
              <span v-if="task.completed_by_name" class="text-[10px] text-emerald-600 font-medium">Erledigt von {{ task.completed_by_name }}</span>
              <span v-else-if="task.is_done" class="text-[10px] text-emerald-600 font-medium">Erledigt</span>
              <span v-if="task.completed_at" class="text-[10px] text-zinc-400">{{ formatDate(task.completed_at) }}</span>
              <span v-if="task.assigned_to_name" class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 bg-emerald-50 text-emerald-500 rounded-md border border-emerald-100"><User class="w-2.5 h-2.5" />{{ task.assigned_to_name }}</span>
              <span v-if="task.ref_id" class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 bg-amber-50 text-amber-700 rounded-md border border-amber-100"><Home class="w-2.5 h-2.5" />{{ task.ref_id }}</span>
            </div>
          </div>
          <button @click="deleteTask(task)" class="p-1.5 text-zinc-300 hover:text-red-400 rounded-lg transition-colors flex-shrink-0">
            <Trash2 class="w-3.5 h-3.5" />
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
