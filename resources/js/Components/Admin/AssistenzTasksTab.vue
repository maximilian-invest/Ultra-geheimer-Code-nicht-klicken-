<script setup>
import { ref, computed, inject, onMounted } from "vue";
import {
  CheckCircle2,
  Circle,
  Plus,
  Sparkles,
  Trash2,
  Edit3,
  Calendar,
  User,
  Home,
  Clock,
  Filter,
  Send,
  ChevronDown,
  Loader2,
} from "lucide-vue-next";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Skeleton } from "@/components/ui/skeleton";

const API = inject("API");
const toast = inject("toast");
const userType = inject("userType", ref("makler"));
const isAssistenz = computed(() =>
  ["assistenz", "backoffice"].includes(userType.value),
);

const tasks = ref([]);
const loading = ref(false);
const brokerFilter = ref("all");
const brokerList = ref([]);
const teamMembers = ref([]);
const userId = inject("userId", ref(null));
const showCompleted = ref(false);
const showAddForm = ref(false);
const aiLoading = ref(false);

const newTask = ref({
  text: "",
  priority: "medium",
  due_date: "",
  property_id: null,
  assigned_to: null,
});

const editingId = ref(null);
const editAssignedTo = ref(null);
const editTitle = ref("");
const editPriority = ref("medium");
const editDueDate = ref("");

const stats = computed(() => {
  const all = tasks.value;
  const open = all.filter((t) => !t.is_done);
  const today = new Date().toISOString().slice(0, 10);
  return {
    open: open.length,
    today: open.filter(
      (t) => t.due_date && t.due_date.slice(0, 10) === today,
    ).length,
    overdue: open.filter(
      (t) => t.due_date && t.due_date.slice(0, 10) < today,
    ).length,
    doneWeek: all.filter(
      (t) =>
        t.is_done &&
        t.updated_at &&
        new Date(t.updated_at) > new Date(Date.now() - 7 * 86400000),
    ).length,
  };
});

const myTasks = computed(() => {
  return tasks.value.filter(
    (t) =>
      !t.is_done &&
      (t.assigned_to == userId.value ||
        (!t.assigned_to && t.created_by == userId.value)),
  );
});

const delegatedTasks = computed(() => {
  return tasks.value.filter(
    (t) =>
      !t.is_done &&
      t.assigned_to &&
      t.assigned_to != userId.value &&
      (t.created_by == userId.value || t.assigned_by == userId.value),
  );
});

const completedTasks = computed(() => {
  return tasks.value
    .filter((t) => t.is_done)
    .sort((a, b) => {
      const da = a.completed_at || a.updated_at || "";
      const db = b.completed_at || b.updated_at || "";
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

const priorityLabels = {
  critical: "Kritisch",
  high: "Hoch",
  medium: "Mittel",
  low: "Niedrig",
};
const priorityDots = {
  critical: "bg-destructive",
  high: "bg-foreground",
  medium: "bg-muted-foreground",
  low: "bg-muted-foreground/50",
};

const myTaskSections = computed(() => {
  const g = groupByPriority(myTasks.value);
  return ["critical", "high", "medium", "low"]
    .filter((p) => g[p].length)
    .map((p) => ({ prio: p, items: g[p] }));
});

const delegatedTaskSections = computed(() => {
  const g = groupByPriority(delegatedTasks.value);
  return ["critical", "high", "medium", "low"]
    .filter((p) => g[p].length)
    .map((p) => ({ prio: p, items: g[p] }));
});

const brokerFilterModel = computed({
  get: () =>
    brokerFilter.value === "all" ? "all" : String(brokerFilter.value),
  set: (v) => {
    brokerFilter.value = v === "all" ? "all" : Number(v);
  },
});

function setBrokerFilter(v) {
  brokerFilterModel.value = v;
  loadTasks();
}

function assignKey(v) {
  return v == null || v === "" ? "__self__" : String(v);
}

function parseAssignKey(v) {
  return v === "__self__" || v == null || v === "" ? null : Number(v);
}

function resolveAssignedTo(v) {
  if (v == null || v === "") return userId?.value ?? null;
  const numeric = Number(v);
  return Number.isFinite(numeric) ? numeric : userId?.value ?? null;
}

async function loadTasks() {
  loading.value = true;
  try {
    let url = API.value + "&action=getTasks&done=1" + (isAssistenz.value ? "&scope=assistenz" : "");
    if (brokerFilter.value !== "all")
      url += "&broker_filter=" + brokerFilter.value;
    const r = await fetch(url);
    const d = await r.json();
    tasks.value = d.tasks || [];
  } catch (e) {
    toast("Fehler: " + e.message);
  }
  loading.value = false;
}

async function loadBrokers() {
  try {
    const r = await fetch(API.value + "&action=list_brokers");
    const d = await r.json();
    brokerList.value = (d.brokers || []).filter((b) =>
      ["admin", "makler"].includes(b.user_type),
    );
    teamMembers.value = (d.brokers || []).filter((b) => b.id !== userId?.value);
  } catch (e) {}
}

async function addTask() {
  if (!newTask.value.text.trim()) return;
  try {
    const payload = {
      ...newTask.value,
      assigned_to: resolveAssignedTo(newTask.value.assigned_to),
    };
    const r = await fetch(API.value + "&action=addTask", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
      toast("Aufgabe erstellt");
      newTask.value = {
        text: "",
        priority: "medium",
        due_date: "",
        property_id: null,
        assigned_to: null,
      };
      showAddForm.value = false;
      loadTasks();
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

async function completeTask(task) {
  try {
    await fetch(API.value + "&action=doneTask", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ task_id: task.id }),
    });
    loadTasks();
    toast("Erledigt");
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

async function deleteTask(task) {
  try {
    await fetch(API.value + "&action=delete_task", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: task.id }),
    });
    tasks.value = tasks.value.filter((t) => t.id !== task.id);
    toast("Geloescht");
  } catch (e) {
    toast("Fehler: " + e.message);
  }
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
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        id: editingId.value,
        title: editTitle.value,
        priority: editPriority.value,
        due_date: editDueDate.value || null,
        assigned_to: resolveAssignedTo(editAssignedTo.value),
      }),
    });
    editingId.value = null;
    loadTasks();
    toast("Aktualisiert");
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

async function generateAiTodos() {
  aiLoading.value = true;
  try {
    const r = await fetch(API.value + "&action=generateTodos", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    const d = await r.json();
    if (d.generated !== undefined) {
      toast(d.generated + " Aufgaben generiert");
      loadTasks();
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
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

onMounted(() => {
  loadBrokers();
  loadTasks();
});
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 pt-4 md:pt-6">
    <!-- Kennzahlen (shadcn Metric-Card Muster) -->
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
      <Card>
        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardDescription>Offen</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="text-2xl font-bold tabular-nums">
            {{ stats.open }}
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardDescription>Heute fällig</CardDescription>
        </CardHeader>
        <CardContent>
          <div
            :class="
              cn(
                'text-2xl font-bold tabular-nums',
                stats.today ? 'text-foreground' : 'text-muted-foreground',
              )
            "
          >
            {{ stats.today }}
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardDescription>Überfällig</CardDescription>
        </CardHeader>
        <CardContent>
          <div
            :class="
              cn(
                'text-2xl font-bold tabular-nums',
                stats.overdue ? 'text-destructive' : 'text-muted-foreground',
              )
            "
          >
            {{ stats.overdue }}
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardDescription>Diese Woche erledigt</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="text-2xl font-bold tabular-nums text-foreground">
            {{ stats.doneWeek }}
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Aktionen -->
    <div class="flex flex-wrap items-center gap-3">
        <div v-if="isAssistenz" class="flex items-center gap-2">
          <Filter class="size-4 shrink-0 text-muted-foreground" />
          <Select
            :model-value="brokerFilterModel"
            @update:model-value="setBrokerFilter"
          >
            <SelectTrigger class="h-9 w-[200px]">
              <SelectValue placeholder="Makler" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Alle Makler</SelectItem>
              <SelectItem
                v-for="b in brokerList"
                :key="b.id"
                :value="String(b.id)"
              >
                {{ b.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="min-w-0 flex-1" />

        <Button
          type="button"
          variant="default"
          size="sm"
          :disabled="aiLoading"
          class="shrink-0 shadow-none bg-zinc-900 text-white hover:bg-zinc-800"
          @click="generateAiTodos()"
        >
          <Loader2 v-if="aiLoading" class="animate-spin" />
          <Sparkles v-else />
          {{ aiLoading ? "Generiere…" : "KI Aufgaben" }}
        </Button>

        <Button
          type="button"
          variant="default"
          size="sm"
          class="shrink-0 shadow-none bg-zinc-900 text-white hover:bg-zinc-800"
          @click="showAddForm = !showAddForm"
        >
          <Plus />
          Neue Aufgabe
        </Button>
    </div>

    <!-- Neue Aufgabe -->
    <Card v-if="showAddForm">
      <CardHeader>
        <CardTitle class="text-base">Neue Aufgabe</CardTitle>
        <CardDescription>
          Beschreibung, Priorität und Fälligkeit
        </CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-3">
        <Input
          v-model="newTask.text"
          placeholder="Aufgabe beschreiben…"
          @keyup.enter="addTask()"
        />
        <div class="flex flex-wrap items-center gap-3">
          <Select v-model="newTask.priority">
            <SelectTrigger class="h-9 w-[140px]">
              <SelectValue placeholder="Priorität" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="low">Niedrig</SelectItem>
              <SelectItem value="medium">Mittel</SelectItem>
              <SelectItem value="high">Hoch</SelectItem>
              <SelectItem value="critical">Kritisch</SelectItem>
            </SelectContent>
          </Select>
          <Input
            v-model="newTask.due_date"
            type="date"
            class="h-9 w-[160px]"
          />
          <Select
            :model-value="assignKey(newTask.assigned_to)"
            @update:model-value="
              (v) => (newTask.assigned_to = parseAssignKey(v))
            "
          >
            <SelectTrigger class="h-9 min-w-[160px]">
              <SelectValue placeholder="Zuweisen" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__self__">Mir selbst</SelectItem>
              <SelectItem
                v-for="m in teamMembers"
                :key="m.id"
                :value="String(m.id)"
              >
                {{ m.name }}
              </SelectItem>
            </SelectContent>
          </Select>
          <div class="min-w-0 flex-1" />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            class="shadow-none"
            @click="showAddForm = false"
          >
            Abbrechen
          </Button>
          <Button
            type="button"
            size="sm"
            class="shadow-none bg-zinc-900 text-white hover:bg-zinc-800"
            @click="addTask()"
          >
            Erstellen
          </Button>
        </div>
      </CardContent>
    </Card>

    <!-- Laden -->
    <Card v-if="loading">
      <CardContent class="flex flex-col gap-4 p-6">
        <p class="text-center text-sm text-muted-foreground">
          Lade Aufgaben…
        </p>
        <div class="flex flex-col gap-2">
          <Skeleton class="h-12 w-full" />
          <Skeleton class="h-12 w-full" />
          <Skeleton class="h-12 w-full" />
        </div>
      </CardContent>
    </Card>

    <!-- Listen -->
    <div
      v-if="!loading"
      class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_1px_minmax(0,1fr)] lg:items-start"
    >
      <section class="flex min-h-0 flex-col">
        <div class="flex flex-row flex-wrap items-center gap-2 pb-3">
          <div class="flex items-center gap-2">
            <User class="size-4 text-muted-foreground" />
            <h3 class="text-base font-semibold">Meine Aufgaben</h3>
          </div>
          <Badge variant="secondary" class="font-normal tabular-nums">
            {{ myTasks.length }}
          </Badge>
        </div>
        <div>
          <div
            v-if="myTasks.length === 0"
            class="flex flex-col items-center justify-center gap-2 py-12 text-center"
          >
            <CheckCircle2 class="size-8 text-muted-foreground/50" />
            <p class="text-sm text-muted-foreground">Keine offenen Aufgaben</p>
          </div>
          <div v-else class="flex flex-col gap-2 px-2 pb-2">
            <template v-for="section in myTaskSections" :key="'my-' + section.prio">
              <div
                class="flex items-center gap-2 px-2 py-1.5 text-xs font-medium text-muted-foreground"
              >
                <span
                  class="size-1.5 shrink-0 rounded-full"
                  :class="priorityDots[section.prio]"
                />
                {{ priorityLabels[section.prio] }}
                <span class="tabular-nums">({{ section.items.length }})</span>
              </div>
              <div
                v-for="task in section.items"
                :key="task.id"
                class="group flex items-start gap-3 rounded-lg bg-zinc-100 px-3 py-3 transition-colors hover:bg-zinc-200"
              >
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  class="mt-0.5 shrink-0"
                  @click="completeTask(task)"
                >
                  <Circle />
                </Button>
                <div v-if="editingId !== task.id" class="min-w-0 flex-1">
                  <p class="text-sm font-medium leading-snug text-foreground">
                    {{ task.title }}
                  </p>
                  <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <Badge
                      v-if="task.created_by_name && task.created_by != userId"
                      variant="outline"
                      class="gap-1 font-normal [&_svg]:size-3"
                    >
                      <User />
                      von {{ task.created_by_name }}
                    </Badge>
                    <Badge
                      v-if="task.ref_id"
                      variant="outline"
                      class="gap-1 border-zinc-300 bg-zinc-200 font-normal text-zinc-800 [&_svg]:size-3"
                    >
                      <Home />
                      {{ task.ref_id }}
                    </Badge>
                    <Badge
                      v-if="task.due_date"
                      variant="outline"
                      class="gap-1 font-normal [&_svg]:size-3"
                      :class="
                        isOverdue(task.due_date)
                          ? 'border-red-300 bg-red-100 text-red-800'
                          : isToday(task.due_date)
                            ? 'border-amber-300 bg-amber-100 text-amber-800'
                            : 'border-zinc-300 bg-zinc-200 text-zinc-800'
                      "
                    >
                      <Calendar />
                      {{ formatDate(task.due_date) }}
                    </Badge>
                    <Badge
                      v-if="task.source === 'ai'"
                      variant="outline"
                      class="border-zinc-300 bg-zinc-200 font-normal text-zinc-800"
                    >
                      KI
                    </Badge>
                  </div>
                </div>
                <div v-else class="flex min-w-0 flex-1 flex-col gap-2">
                  <Input v-model="editTitle" />
                  <div class="flex flex-wrap items-center gap-2">
                    <Select v-model="editPriority">
                      <SelectTrigger class="h-8 w-[120px] text-xs">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="low">Niedrig</SelectItem>
                        <SelectItem value="medium">Mittel</SelectItem>
                        <SelectItem value="high">Hoch</SelectItem>
                        <SelectItem value="critical">Kritisch</SelectItem>
                      </SelectContent>
                    </Select>
                    <Input
                      v-model="editDueDate"
                      type="date"
                      class="h-8 w-[140px] text-xs"
                    />
                    <Select
                      :model-value="assignKey(editAssignedTo)"
                      @update:model-value="
                        (v) => (editAssignedTo = parseAssignKey(v))
                      "
                    >
                      <SelectTrigger class="h-8 min-w-[140px] text-xs">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="__self__">Mir selbst</SelectItem>
                        <SelectItem
                          v-for="m in teamMembers"
                          :key="m.id"
                          :value="String(m.id)"
                        >
                          {{ m.name }}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                    <Button
                      type="button"
                      size="xs"
                      class="bg-zinc-900 text-white hover:bg-zinc-800"
                      @click="saveEdit()"
                    >
                      Speichern
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="xs"
                      @click="editingId = null"
                    >
                      Abbrechen
                    </Button>
                  </div>
                </div>
                <div
                  v-if="editingId !== task.id"
                  class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100"
                >
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    @click="startEdit(task)"
                  >
                    <Edit3 />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="hover:text-destructive"
                    @click="deleteTask(task)"
                  >
                    <Trash2 />
                  </Button>
                </div>
              </div>
            </template>
          </div>
        </div>
      </section>

      <div class="hidden lg:block w-px self-stretch bg-border/60" />

      <section class="flex min-h-0 flex-col">
        <div class="flex flex-row flex-wrap items-center gap-2 pb-3">
          <div class="flex items-center gap-2">
            <Send class="size-4 text-muted-foreground" />
            <h3 class="text-base font-semibold">Delegierte Aufgaben</h3>
          </div>
          <Badge variant="secondary" class="font-normal tabular-nums">
            {{ delegatedTasks.length }}
          </Badge>
        </div>
        <div>
          <div
            v-if="delegatedTasks.length === 0"
            class="flex flex-col items-center justify-center gap-2 py-12 text-center"
          >
            <Send class="size-8 text-muted-foreground/50" />
            <p class="text-sm text-muted-foreground">
              Keine delegierten Aufgaben
            </p>
          </div>
          <div v-else class="flex flex-col gap-2 px-2 pb-2">
            <template
              v-for="section in delegatedTaskSections"
              :key="'del-' + section.prio"
            >
              <div
                class="flex items-center gap-2 px-2 py-1.5 text-xs font-medium text-muted-foreground"
              >
                <span
                  class="size-1.5 shrink-0 rounded-full"
                  :class="priorityDots[section.prio]"
                />
                {{ priorityLabels[section.prio] }}
                <span class="tabular-nums">({{ section.items.length }})</span>
              </div>
              <div
                v-for="task in section.items"
                :key="task.id"
                class="group flex items-start gap-3 rounded-lg bg-zinc-100 px-3 py-3 transition-colors hover:bg-zinc-200"
              >
                <div
                  class="mt-0.5 shrink-0 text-muted-foreground [&_svg]:size-4"
                >
                  <component :is="task.is_done ? CheckCircle2 : Clock" />
                </div>
                <div v-if="editingId !== task.id" class="min-w-0 flex-1">
                  <p class="text-sm font-medium leading-snug text-foreground">
                    {{ task.title }}
                  </p>
                  <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <Badge
                      v-if="task.assigned_to_name"
                      variant="outline"
                      class="gap-1 border-zinc-300 bg-zinc-200 font-normal text-zinc-800 [&_svg]:size-3"
                    >
                      <User />
                      {{ task.assigned_to_name }}
                    </Badge>
                    <Badge
                      v-if="task.ref_id"
                      variant="outline"
                      class="gap-1 border-zinc-300 bg-zinc-200 font-normal text-zinc-800 [&_svg]:size-3"
                    >
                      <Home />
                      {{ task.ref_id }}
                    </Badge>
                    <Badge
                      v-if="task.due_date"
                      variant="outline"
                      class="gap-1 font-normal [&_svg]:size-3"
                      :class="
                        isOverdue(task.due_date)
                          ? 'border-red-300 bg-red-100 text-red-800'
                          : isToday(task.due_date)
                            ? 'border-amber-300 bg-amber-100 text-amber-800'
                            : 'border-zinc-300 bg-zinc-200 text-zinc-800'
                      "
                    >
                      <Calendar />
                      {{ formatDate(task.due_date) }}
                    </Badge>
                    <Badge
                      v-if="task.source === 'ai'"
                      variant="outline"
                      class="border-zinc-300 bg-zinc-200 font-normal text-zinc-800"
                    >
                      KI
                    </Badge>
                  </div>
                </div>
                <div v-else class="flex min-w-0 flex-1 flex-col gap-2">
                  <Input v-model="editTitle" />
                  <div class="flex flex-wrap items-center gap-2">
                    <Select v-model="editPriority">
                      <SelectTrigger class="h-8 w-[120px] text-xs">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="low">Niedrig</SelectItem>
                        <SelectItem value="medium">Mittel</SelectItem>
                        <SelectItem value="high">Hoch</SelectItem>
                        <SelectItem value="critical">Kritisch</SelectItem>
                      </SelectContent>
                    </Select>
                    <Input
                      v-model="editDueDate"
                      type="date"
                      class="h-8 w-[140px] text-xs"
                    />
                    <Select
                      :model-value="assignKey(editAssignedTo)"
                      @update:model-value="
                        (v) => (editAssignedTo = parseAssignKey(v))
                      "
                    >
                      <SelectTrigger class="h-8 min-w-[140px] text-xs">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="__self__">Mir selbst</SelectItem>
                        <SelectItem
                          v-for="m in teamMembers"
                          :key="m.id"
                          :value="String(m.id)"
                        >
                          {{ m.name }}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                    <Button
                      type="button"
                      size="xs"
                      class="bg-zinc-900 text-white hover:bg-zinc-800"
                      @click="saveEdit()"
                    >
                      Speichern
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="xs"
                      @click="editingId = null"
                    >
                      Abbrechen
                    </Button>
                  </div>
                </div>
                <div
                  v-if="editingId !== task.id"
                  class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100"
                >
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    @click="startEdit(task)"
                  >
                    <Edit3 />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="hover:text-destructive"
                    @click="deleteTask(task)"
                  >
                    <Trash2 />
                  </Button>
                </div>
              </div>
            </template>
          </div>
        </div>
      </section>
    </div>

    <!-- Erledigt -->
    <Card v-if="completedTasks.length" class="mb-4">
      <Collapsible v-model:open="showCompleted">
        <CollapsibleTrigger
          class="flex w-full items-center justify-between gap-2 px-6 py-4 text-left text-sm font-medium transition-colors hover:bg-muted/50"
        >
          <span class="flex items-center gap-2 text-foreground">
            <CheckCircle2 class="size-4 text-muted-foreground" />
            {{ completedTasks.length }} erledigt
          </span>
          <ChevronDown
            class="size-4 shrink-0 text-muted-foreground transition-transform"
            :class="{ 'rotate-180': showCompleted }"
          />
        </CollapsibleTrigger>
        <CollapsibleContent>
          <div class="flex flex-col gap-2 px-2 pb-2">
            <div
              v-for="task in completedTasks"
              :key="task.id"
              class="flex items-center gap-3 rounded-lg bg-zinc-100 px-4 py-3 hover:bg-zinc-200"
            >
              <CheckCircle2 class="size-4 shrink-0 text-muted-foreground" />
              <div class="min-w-0 flex-1">
                <p class="text-sm text-muted-foreground line-through">
                  {{ task.title }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <span
                    v-if="task.completed_by_name"
                    class="text-xs text-muted-foreground"
                    >Erledigt von {{ task.completed_by_name }}</span
                  >
                  <span
                    v-else-if="task.is_done"
                    class="text-xs text-muted-foreground"
                    >Erledigt</span
                  >
                  <span
                    v-if="task.completed_at"
                    class="text-xs text-muted-foreground"
                    >{{ formatDate(task.completed_at) }}</span
                  >
                  <Badge
                    v-if="task.assigned_to_name"
                    variant="outline"
                    class="gap-1 border-zinc-300 bg-zinc-200 font-normal text-zinc-800 [&_svg]:size-3"
                  >
                    <User />
                    {{ task.assigned_to_name }}
                  </Badge>
                  <Badge
                    v-if="task.ref_id"
                    variant="outline"
                    class="gap-1 font-normal [&_svg]:size-3"
                  >
                    <Home />
                    {{ task.ref_id }}
                  </Badge>
                </div>
              </div>
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                class="shrink-0 hover:text-destructive"
                @click="deleteTask(task)"
              >
                <Trash2 />
              </Button>
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </Card>
  </div>
</template>
