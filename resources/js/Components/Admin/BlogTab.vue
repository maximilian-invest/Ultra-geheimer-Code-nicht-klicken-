<script setup>
import { ref, inject, onMounted, computed } from "vue";
import {
  FileText, Plus, Edit3, Trash2, Eye, EyeOff, Globe, Image,
  Sparkles, Wand2, Save, ArrowLeft, ExternalLink, Loader2,
  Clock, Search, Upload
} from "lucide-vue-next";
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

const API = inject("API");
const toast = inject("toast");

// ── Mode ──────────────────────────────────────────────
const mode = ref("list"); // 'list' | 'edit'

// ── List state ────────────────────────────────────────
const posts = ref([]);
const loading = ref(true);
const searchQuery = ref("");

// ── AI Generate state ─────────────────────────────────
const aiTopic = ref("");
const aiKeywords = ref("");
const aiGenerating = ref(false);

// ── Edit state ────────────────────────────────────────
const saving = ref(false);
const generatingImage = ref(false);
const uploadingImage = ref(false);
const imageFileInput = ref(null);

const form = ref({
  id: null,
  title: "",
  slug: "",
  seo_title: "",
  meta_description: "",
  excerpt: "",
  content: "",
  featured_image: "",
  featured_image_alt: "",
  author: "",
  category: "ratgeber",
  tags: [],
  internal_links: [],
  status: "draft",
  published_at: "",
  reading_time_min: 5,
  sort_order: 0,
});

const tagsInput = ref("");
const dallePrompt = ref("");

// ── Computed ──────────────────────────────────────────
const publishedCount = computed(() => posts.value.filter(p => p.status === "published").length);
const draftCount = computed(() => posts.value.filter(p => p.status !== "published").length);

const filteredPosts = computed(() => {
  if (!searchQuery.value.trim()) return posts.value;
  const q = searchQuery.value.toLowerCase();
  return posts.value.filter(p =>
    (p.title || "").toLowerCase().includes(q) ||
    (p.category || "").toLowerCase().includes(q) ||
    (p.author || "").toLowerCase().includes(q)
  );
});

const isNew = computed(() => !form.value.id);

// ── Load ──────────────────────────────────────────────
onMounted(loadPosts);

async function loadPosts() {
  loading.value = true;
  try {
    const r = await fetch(API.value + "&action=blog_list");
    const d = await r.json();
    if (d.success) posts.value = d.posts || [];
    else toast("Fehler beim Laden der Artikel");
  } catch (e) {
    toast("Fehler: " + e.message);
  } finally {
    loading.value = false;
  }
}

// ── Navigation ────────────────────────────────────────
function openNew() {
  form.value = {
    id: null, title: "", slug: "", seo_title: "", meta_description: "",
    excerpt: "", content: "", featured_image: "", featured_image_alt: "",
    author: "", category: "ratgeber", tags: [], internal_links: [],
    status: "draft", published_at: "", reading_time_min: 5, sort_order: 0,
  };
  tagsInput.value = "";
  dallePrompt.value = "";
  mode.value = "edit";
}

async function openEdit(post) {
  try {
    const r = await fetch(API.value + "&action=blog_get&id=" + post.id);
    const d = await r.json();
    if (d.success && d.post) {
      const p = d.post;
      form.value = {
        id: p.id,
        title: p.title || "",
        slug: p.slug || "",
        seo_title: p.seo_title || "",
        meta_description: p.meta_description || "",
        excerpt: p.excerpt || "",
        content: p.content || "",
        featured_image: p.featured_image || "",
        featured_image_alt: p.featured_image_alt || "",
        author: p.author || "",
        category: p.category || "ratgeber",
        tags: Array.isArray(p.tags) ? p.tags : [],
        internal_links: Array.isArray(p.internal_links) ? p.internal_links : [],
        status: p.status || "draft",
        published_at: p.published_at || "",
        reading_time_min: p.reading_time_min || 5,
        sort_order: p.sort_order || 0,
      };
      tagsInput.value = form.value.tags.join(", ");
      dallePrompt.value = "";
      mode.value = "edit";
    } else {
      toast("Artikel nicht gefunden");
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  }
}

function goBack() {
  mode.value = "list";
  loadPosts();
}

// ── Slug generation ───────────────────────────────────
function generateSlug(title) {
  return title
    .toLowerCase()
    .replace(/ä/g, "ae").replace(/ö/g, "oe").replace(/ü/g, "ue").replace(/ß/g, "ss")
    .replace(/[^a-z0-9\s-]/g, "")
    .trim()
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-");
}

function onTitleInput() {
  if (!form.value.id || !form.value.slug) {
    form.value.slug = generateSlug(form.value.title);
  }
  if (!form.value.seo_title) {
    form.value.seo_title = form.value.title;
  }
  estimateReadingTime();
}

function estimateReadingTime() {
  const words = (form.value.content || "").split(/\s+/).filter(Boolean).length;
  form.value.reading_time_min = Math.max(1, Math.round(words / 200));
}

// ── Save ──────────────────────────────────────────────
async function savePost() {
  if (!form.value.title.trim()) {
    toast("Bitte Titel eingeben");
    return;
  }
  saving.value = true;
  try {
    // Parse tags from input
    form.value.tags = tagsInput.value
      .split(",")
      .map(t => t.trim())
      .filter(Boolean);

    const body = { ...form.value };
    const r = await fetch(API.value + "&action=blog_save", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const d = await r.json();
    if (d.success) {
      if (!form.value.id) form.value.id = d.id;
      toast("Artikel gespeichert!");
    } else {
      toast("Fehler: " + (d.error || "Unbekannt"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  } finally {
    saving.value = false;
  }
}

// ── Publish / Unpublish ───────────────────────────────
async function publishPost(post) {
  try {
    const r = await fetch(API.value + "&action=blog_publish", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: post.id }),
    });
    const d = await r.json();
    if (d.success) {
      post.status = "published";
      toast("Artikel veröffentlicht!");
    } else toast("Fehler beim Veröffentlichen");
  } catch (e) { toast("Fehler: " + e.message); }
}

async function unpublishPost(post) {
  try {
    const r = await fetch(API.value + "&action=blog_unpublish", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: post.id }),
    });
    const d = await r.json();
    if (d.success) {
      post.status = "draft";
      toast("Artikel zurückgezogen");
    } else toast("Fehler");
  } catch (e) { toast("Fehler: " + e.message); }
}

// ── Delete ────────────────────────────────────────────
async function deletePost(post) {
  if (!confirm(`Artikel "${post.title}" wirklich löschen?`)) return;
  try {
    const r = await fetch(API.value + "&action=blog_delete", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: post.id }),
    });
    const d = await r.json();
    if (d.success) {
      posts.value = posts.value.filter(p => p.id !== post.id);
      toast("Artikel gelöscht");
    } else toast("Fehler beim Löschen");
  } catch (e) { toast("Fehler: " + e.message); }
}

// ── AI Generate Article ───────────────────────────────
async function generateArticle() {
  if (!aiTopic.value.trim()) {
    toast("Bitte Thema eingeben");
    return;
  }
  aiGenerating.value = true;
  try {
    const r = await fetch(API.value + "&action=blog_generate_article", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        topic: aiTopic.value,
        keywords: aiKeywords.value,
        tone: "professional",
        target_length: "medium",
      }),
    });
    const d = await r.json();
    if (d.success && d.article) {
      const a = d.article;
      form.value = {
        id: null,
        title: a.title || "",
        slug: a.slug || generateSlug(a.title || ""),
        seo_title: a.seo_title || a.title || "",
        meta_description: a.meta_description || "",
        excerpt: a.excerpt || "",
        content: a.content || "",
        featured_image: "",
        featured_image_alt: a.featured_image_alt || "",
        author: "",
        category: a.category || "ratgeber",
        tags: Array.isArray(a.tags) ? a.tags : [],
        internal_links: [],
        status: "draft",
        published_at: "",
        reading_time_min: 5,
        sort_order: 0,
      };
      tagsInput.value = form.value.tags.join(", ");
      dallePrompt.value = "";
      estimateReadingTime();
      aiTopic.value = "";
      aiKeywords.value = "";
      mode.value = "edit";
      toast("Artikel generiert!");
    } else {
      toast("Fehler: " + (d.error || "Generierung fehlgeschlagen"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  } finally {
    aiGenerating.value = false;
  }
}

// ── AI Generate Image ─────────────────────────────────
async function generateImage() {
  if (!dallePrompt.value.trim()) {
    toast("Bitte DALL-E Prompt eingeben");
    return;
  }
  generatingImage.value = true;
  try {
    const body = { prompt: dallePrompt.value };
    if (form.value.id) body.post_id = form.value.id;
    const r = await fetch(API.value + "&action=blog_generate_image", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const d = await r.json();
    if (d.success && d.url) {
      form.value.featured_image = d.url;
      toast("Bild generiert!");
    } else {
      toast("Fehler: " + (d.error || "Bildgenerierung fehlgeschlagen"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  } finally {
    generatingImage.value = false;
  }
}

// ── Upload Image ──────────────────────────────────────
function triggerUpload() {
  imageFileInput.value?.click();
}

async function onImageUpload(event) {
  const file = event.target.files[0];
  if (!file) return;
  event.target.value = "";
  uploadingImage.value = true;
  try {
    const fd = new FormData();
    fd.append("image", file);
    if (form.value.id) fd.append("post_id", form.value.id);
    const r = await fetch(API.value + "&action=blog_upload_image", {
      method: "POST",
      body: fd,
    });
    const d = await r.json();
    if (d.success && d.url) {
      form.value.featured_image = d.url;
      toast("Bild hochgeladen!");
    } else {
      toast("Fehler: " + (d.error || "Upload fehlgeschlagen"));
    }
  } catch (e) {
    toast("Fehler: " + e.message);
  } finally {
    uploadingImage.value = false;
  }
}

// ── Helpers ───────────────────────────────────────────
function formatDate(dateStr) {
  if (!dateStr) return "—";
  try {
    return new Date(dateStr).toLocaleDateString("de-AT", { day: "2-digit", month: "short", year: "numeric" });
  } catch { return dateStr; }
}

function postExternalUrl(post) {
  return "/blog/" + (post.slug || post.id);
}
</script>

<template>
  <div class="p-4 md:p-6 min-h-full bg-zinc-50/50">

    <!-- ═══════════════════════════════════════════════ LIST MODE -->
    <template v-if="mode === 'list'">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-orange-50 border border-orange-100 flex items-center justify-center">
            <FileText class="w-4 h-4 text-orange-500" />
          </div>
          <div>
            <h1 class="text-[13px] font-semibold text-foreground">Blog</h1>
            <p class="text-[12px] text-muted-foreground mt-0.5">
              <span class="text-emerald-600 font-medium">{{ publishedCount }} veröffentlicht</span>
              <span class="mx-1.5 text-zinc-300">·</span>
              <span>{{ draftCount }} Entwürfe</span>
            </p>
          </div>
        </div>
        <Button @click="openNew" size="sm" class="gap-2 bg-orange-500 hover:bg-orange-600 text-white border-0">
          <Plus class="w-3.5 h-3.5" />
          Neuer Artikel
        </Button>
      </div>

      <!-- AI Quick Generate Panel -->
      <div class="mb-5 rounded-xl border border-violet-100 bg-gradient-to-br from-violet-50/60 to-white p-4">
        <div class="flex items-center gap-2 mb-3">
          <div class="w-5 h-5 rounded-md bg-violet-100 flex items-center justify-center">
            <Sparkles class="w-3 h-3 text-violet-500" />
          </div>
          <span class="text-[13px] font-semibold text-foreground">KI-Artikel generieren</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-2">
          <Input
            v-model="aiTopic"
            type="text"
            placeholder="Thema (z.B. Immobilienkauf in Wien)"
            class="sm:col-span-2 text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
            @keydown.enter="generateArticle"
          />
          <Input
            v-model="aiKeywords"
            type="text"
            placeholder="Keywords (kommagetrennt)"
            class="sm:col-span-2 text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
            @keydown.enter="generateArticle"
          />
          <Button
            @click="generateArticle"
            :disabled="aiGenerating"
            variant="outline"
            size="sm"
            class="gap-2 border-violet-200 text-violet-700 hover:bg-violet-50 hover:border-violet-300 disabled:opacity-50"
          >
            <Loader2 v-if="aiGenerating" class="w-3.5 h-3.5 animate-spin" />
            <Wand2 v-else class="w-3.5 h-3.5" />
            <span>{{ aiGenerating ? "Generiere..." : "Generieren" }}</span>
          </Button>
        </div>
      </div>

      <!-- Search -->
      <div class="relative mb-4">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground pointer-events-none" />
        <Input
          v-model="searchQuery"
          type="text"
          placeholder="Artikel suchen..."
          class="pl-9 text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
        />
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-16">
        <Loader2 class="w-5 h-5 text-muted-foreground animate-spin" />
      </div>

      <!-- Empty state -->
      <div
        v-else-if="filteredPosts.length === 0 && posts.length === 0"
        class="rounded-xl border border-zinc-100 bg-white flex flex-col items-center justify-center py-16 text-center"
      >
        <div class="w-12 h-12 rounded-2xl bg-zinc-50 border border-zinc-100 flex items-center justify-center mb-3">
          <FileText class="w-5 h-5 text-zinc-300" />
        </div>
        <p class="text-[13px] font-medium text-foreground">Noch keine Artikel</p>
        <p class="text-[12px] text-muted-foreground mt-1">Erstelle deinen ersten Blog-Artikel</p>
        <Button
          @click="openNew"
          variant="outline"
          size="sm"
          class="mt-4 gap-1.5 border-orange-200 text-orange-600 hover:bg-orange-50 hover:border-orange-300"
        >
          <Plus class="w-3.5 h-3.5" />
          Neuer Artikel
        </Button>
      </div>

      <!-- No search results -->
      <div v-else-if="filteredPosts.length === 0" class="py-12 text-center">
        <p class="text-[13px] text-muted-foreground">Keine Artikel für "{{ searchQuery }}"</p>
      </div>

      <!-- Posts list -->
      <div v-else class="rounded-xl border border-zinc-100 bg-white overflow-hidden divide-y divide-zinc-100">
        <div
          v-for="post in filteredPosts"
          :key="post.id"
          class="group flex items-center gap-4 px-4 py-3 hover:bg-zinc-50 transition-colors"
        >
          <!-- Thumbnail -->
          <div class="w-16 h-12 rounded-lg overflow-hidden flex-shrink-0 bg-zinc-50 border border-zinc-100">
            <img
              v-if="post.featured_image"
              :src="post.featured_image ? '/storage/' + post.featured_image : ''"
              :alt="post.featured_image_alt || post.title"
              class="w-full h-full object-cover"
            />
            <div v-else class="w-full h-full flex items-center justify-center">
              <Image class="w-4 h-4 text-zinc-300" />
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <span class="text-[13px] font-medium text-foreground truncate">{{ post.title }}</span>
              <Badge
                variant="outline"
                class="flex-shrink-0 text-[10px] font-semibold px-1.5 py-0 h-4"
                :class="post.status === 'published'
                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                  : 'bg-zinc-50 text-zinc-500 border-zinc-200'"
              >
                {{ post.status === 'published' ? 'Live' : 'Entwurf' }}
              </Badge>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-muted-foreground">
              <span v-if="post.category" class="capitalize">{{ post.category }}</span>
              <span v-if="post.category && (post.reading_time_min || post.published_at)" class="text-zinc-300">·</span>
              <span v-if="post.reading_time_min" class="flex items-center gap-0.5">
                <Clock class="w-2.5 h-2.5" />
                {{ post.reading_time_min }} Min
              </span>
              <span v-if="post.reading_time_min && post.published_at" class="text-zinc-300">·</span>
              <span v-if="post.published_at">{{ formatDate(post.published_at) }}</span>
            </div>
          </div>

          <!-- Actions (visible on hover) -->
          <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
            <button
              v-if="post.status !== 'published'"
              @click="publishPost(post)"
              title="Veröffentlichen"
              class="w-7 h-7 rounded-lg flex items-center justify-center text-muted-foreground hover:text-emerald-600 hover:bg-emerald-50 transition-colors"
            >
              <Eye class="w-3.5 h-3.5" />
            </button>
            <button
              v-else
              @click="unpublishPost(post)"
              title="Zurückziehen"
              class="w-7 h-7 rounded-lg flex items-center justify-center text-muted-foreground hover:text-amber-600 hover:bg-amber-50 transition-colors"
            >
              <EyeOff class="w-3.5 h-3.5" />
            </button>
            <button
              @click="openEdit(post)"
              title="Bearbeiten"
              class="w-7 h-7 rounded-lg flex items-center justify-center text-muted-foreground hover:text-orange-500 hover:bg-orange-50 transition-colors"
            >
              <Edit3 class="w-3.5 h-3.5" />
            </button>
            <a
              :href="postExternalUrl(post)"
              target="_blank"
              title="Artikel ansehen"
              class="w-7 h-7 rounded-lg flex items-center justify-center text-muted-foreground hover:text-blue-500 hover:bg-blue-50 transition-colors"
            >
              <ExternalLink class="w-3.5 h-3.5" />
            </a>
            <button
              @click="deletePost(post)"
              title="Löschen"
              class="w-7 h-7 rounded-lg flex items-center justify-center text-muted-foreground hover:text-red-500 hover:bg-red-50 transition-colors"
            >
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>

    </template>

    <!-- ═══════════════════════════════════════════════ EDIT MODE -->
    <template v-else-if="mode === 'edit'">

      <!-- Header -->
      <div class="flex items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
          <Button
            @click="goBack"
            variant="outline"
            size="icon"
            class="w-8 h-8 border-zinc-200 text-muted-foreground hover:text-foreground hover:bg-zinc-50"
          >
            <ArrowLeft class="w-4 h-4" />
          </Button>
          <div>
            <h1 class="text-[13px] font-semibold text-foreground">{{ isNew ? 'Neuer Artikel' : 'Artikel bearbeiten' }}</h1>
            <p v-if="!isNew" class="text-[11px] text-muted-foreground mt-0.5 font-mono">ID {{ form.id }}</p>
          </div>
        </div>
        <Button
          @click="savePost"
          :disabled="saving"
          size="sm"
          class="gap-2 bg-orange-500 hover:bg-orange-600 text-white border-0 disabled:opacity-50"
        >
          <Loader2 v-if="saving" class="w-3.5 h-3.5 animate-spin" />
          <Save v-else class="w-3.5 h-3.5" />
          {{ saving ? "Speichern..." : "Speichern" }}
        </Button>
      </div>

      <!-- 3-Column Grid -->
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        <!-- Main Content (2/3) -->
        <div class="xl:col-span-2 space-y-4">

          <!-- Title -->
          <div>
            <Input
              v-model="form.title"
              @input="onTitleInput"
              type="text"
              placeholder="Artikeltitel..."
              class="w-full px-4 py-3 h-auto text-xl font-semibold border-zinc-200 bg-white placeholder:text-zinc-300 focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
            />
          </div>

          <!-- Content -->
          <div class="rounded-xl border border-zinc-100 bg-white overflow-hidden">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-zinc-100 bg-zinc-50/50">
              <FileText class="w-3.5 h-3.5 text-muted-foreground" />
              <span class="text-[12px] font-medium text-foreground">Inhalt (Markdown)</span>
              <span class="ml-auto text-[11px] text-muted-foreground font-mono">{{ form.reading_time_min }} Min Lesezeit</span>
            </div>
            <Textarea
              v-model="form.content"
              @input="estimateReadingTime"
              rows="24"
              placeholder="Artikel-Inhalt in Markdown..."
              class="w-full rounded-none border-0 text-[13px] text-foreground placeholder:text-zinc-300 font-mono leading-relaxed focus-visible:ring-0 resize-none bg-white"
            />
          </div>

          <!-- Excerpt -->
          <div class="rounded-xl border border-zinc-100 bg-white overflow-hidden">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-zinc-100 bg-zinc-50/50">
              <span class="text-[12px] font-medium text-foreground">Kurzfassung / Teaser</span>
            </div>
            <Textarea
              v-model="form.excerpt"
              rows="3"
              placeholder="Kurze Beschreibung für Vorschau und Listen..."
              class="w-full rounded-none border-0 text-[13px] text-foreground placeholder:text-zinc-300 focus-visible:ring-0 resize-none bg-white"
            />
          </div>

        </div>

        <!-- Sidebar (1/3) -->
        <div class="space-y-4">

          <!-- SEO Panel -->
          <Card class="border-zinc-100 shadow-none">
            <CardHeader class="px-4 py-3 border-b border-zinc-100 bg-zinc-50/50 rounded-t-xl">
              <CardTitle class="flex items-center gap-2 text-[13px] font-semibold text-foreground">
                <Globe class="w-3.5 h-3.5 text-orange-500" />
                SEO
              </CardTitle>
            </CardHeader>
            <CardContent class="p-4 space-y-3">
              <!-- SEO Title -->
              <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                  <label class="text-[12px] text-muted-foreground font-normal">SEO-Titel</label>
                  <span
                    class="text-[10px] font-mono"
                    :class="form.seo_title.length > 60 ? 'text-red-500' : 'text-muted-foreground'"
                  >
                    {{ form.seo_title.length }}/60
                  </span>
                </div>
                <Input
                  v-model="form.seo_title"
                  type="text"
                  placeholder="SEO-Titel..."
                  class="text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
                />
              </div>
              <!-- Slug -->
              <div class="space-y-1.5">
                <label class="text-[12px] text-muted-foreground font-normal">URL-Slug</label>
                <Input
                  v-model="form.slug"
                  type="text"
                  placeholder="url-freundlicher-slug"
                  class="text-[13px] font-mono border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
                />
              </div>
              <!-- Meta Description -->
              <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                  <label class="text-[12px] text-muted-foreground font-normal">Meta-Beschreibung</label>
                  <span
                    class="text-[10px] font-mono"
                    :class="form.meta_description.length > 155 ? 'text-red-500' : 'text-muted-foreground'"
                  >
                    {{ form.meta_description.length }}/155
                  </span>
                </div>
                <Textarea
                  v-model="form.meta_description"
                  rows="3"
                  placeholder="Kurze Beschreibung für Suchergebnisse..."
                  class="text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300 resize-none"
                />
              </div>
            </CardContent>
          </Card>

          <!-- Featured Image Panel -->
          <Card class="border-zinc-100 shadow-none">
            <CardHeader class="px-4 py-3 border-b border-zinc-100 bg-zinc-50/50 rounded-t-xl">
              <CardTitle class="flex items-center gap-2 text-[13px] font-semibold text-foreground">
                <Image class="w-3.5 h-3.5 text-orange-500" />
                Titelbild
              </CardTitle>
            </CardHeader>
            <CardContent class="p-4 space-y-3">
              <!-- Preview -->
              <div v-if="form.featured_image" class="rounded-lg overflow-hidden border border-zinc-100 bg-zinc-50">
                <img :src="form.featured_image ? '/storage/' + form.featured_image : ''" alt="Vorschau" class="w-full h-32 object-cover" />
                <div class="px-2 py-1.5 flex items-center gap-1 bg-white border-t border-zinc-100">
                  <span class="text-[10px] text-muted-foreground truncate font-mono flex-1">{{ form.featured_image }}</span>
                  <button @click="form.featured_image = ''" class="text-muted-foreground hover:text-red-500 transition-colors flex-shrink-0">
                    <Trash2 class="w-3 h-3" />
                  </button>
                </div>
              </div>
              <div v-else class="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/50 h-24 flex items-center justify-center">
                <div class="text-center">
                  <Image class="w-5 h-5 text-zinc-300 mx-auto mb-1" />
                  <span class="text-[11px] text-muted-foreground">Kein Bild</span>
                </div>
              </div>
              <!-- Alt text -->
              <div class="space-y-1.5">
                <label class="text-[12px] text-muted-foreground font-normal">Alt-Text</label>
                <Input
                  v-model="form.featured_image_alt"
                  type="text"
                  placeholder="Bildbeschreibung für SEO..."
                  class="text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
                />
              </div>
              <!-- DALL-E prompt -->
              <div class="space-y-1.5">
                <label class="text-[12px] text-muted-foreground font-normal">DALL-E Prompt</label>
                <Textarea
                  v-model="dallePrompt"
                  rows="2"
                  placeholder="Beschreibe das gewünschte Bild..."
                  class="text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300 resize-none"
                />
              </div>
              <!-- Actions -->
              <div class="flex gap-2">
                <Button
                  @click="generateImage"
                  :disabled="generatingImage || uploadingImage"
                  variant="outline"
                  size="sm"
                  class="flex-1 gap-1.5 border-violet-200 text-violet-700 hover:bg-violet-50 hover:border-violet-300 disabled:opacity-50 text-[12px]"
                >
                  <Loader2 v-if="generatingImage" class="w-3.5 h-3.5 animate-spin" />
                  <Sparkles v-else class="w-3.5 h-3.5" />
                  DALL-E
                </Button>
                <Button
                  @click="triggerUpload"
                  :disabled="generatingImage || uploadingImage"
                  variant="outline"
                  size="sm"
                  class="flex-1 gap-1.5 border-zinc-200 text-muted-foreground hover:bg-zinc-50 disabled:opacity-50 text-[12px]"
                >
                  <Loader2 v-if="uploadingImage" class="w-3.5 h-3.5 animate-spin" />
                  <Upload v-else class="w-3.5 h-3.5" />
                  Upload
                </Button>
                <input
                  ref="imageFileInput"
                  type="file"
                  accept="image/*"
                  class="hidden"
                  @change="onImageUpload"
                />
              </div>
            </CardContent>
          </Card>

          <!-- Category + Author Panel -->
          <Card class="border-zinc-100 shadow-none">
            <CardHeader class="px-4 py-3 border-b border-zinc-100 bg-zinc-50/50 rounded-t-xl">
              <CardTitle class="text-[13px] font-semibold text-foreground">Kategorie & Autor</CardTitle>
            </CardHeader>
            <CardContent class="p-4 space-y-3">
              <div class="space-y-1.5">
                <label class="text-[12px] text-muted-foreground font-normal">Kategorie</label>
                <select
                  v-model="form.category"
                  class="w-full px-3 py-2 rounded-lg border border-zinc-200 bg-white text-[13px] text-foreground focus:outline-none focus:ring-2 focus:ring-orange-400/30 focus:border-orange-300 transition-colors"
                >
                  <option value="ratgeber">Ratgeber</option>
                  <option value="news">News</option>
                  <option value="markt">Markt</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <label class="text-[12px] text-muted-foreground font-normal">Autor</label>
                <Input
                  v-model="form.author"
                  type="text"
                  placeholder="Name des Autors..."
                  class="text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
                />
              </div>
              <div class="space-y-1.5">
                <label class="text-[12px] text-muted-foreground font-normal">Tags (kommagetrennt)</label>
                <Input
                  v-model="tagsInput"
                  type="text"
                  placeholder="immobilien, wien, kauf..."
                  class="text-[13px] border-zinc-200 bg-white focus-visible:ring-orange-400/30 focus-visible:border-orange-300"
                />
              </div>
            </CardContent>
          </Card>

          <!-- Status Panel -->
          <Card class="border-zinc-100 shadow-none">
            <CardHeader class="px-4 py-3 border-b border-zinc-100 bg-zinc-50/50 rounded-t-xl">
              <CardTitle class="text-[13px] font-semibold text-foreground">Status</CardTitle>
            </CardHeader>
            <CardContent class="p-4 space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-[12px] text-muted-foreground">Veröffentlichungsstatus</span>
                <Badge
                  variant="outline"
                  :class="form.status === 'published'
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    : 'bg-zinc-50 text-zinc-500 border-zinc-200'"
                  class="text-[11px] font-semibold"
                >
                  {{ form.status === 'published' ? 'Veröffentlicht' : 'Entwurf' }}
                </Badge>
              </div>
              <div v-if="form.published_at" class="flex items-center justify-between">
                <span class="text-[12px] text-muted-foreground">Veröffentlicht am</span>
                <span class="text-[12px] text-foreground font-mono">{{ formatDate(form.published_at) }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-[12px] text-muted-foreground">Lesezeit</span>
                <span class="text-[12px] text-foreground font-mono flex items-center gap-1">
                  <Clock class="w-3 h-3 text-muted-foreground" />
                  {{ form.reading_time_min }} Min
                </span>
              </div>
              <!-- Quick publish/unpublish -->
              <div class="pt-1 border-t border-zinc-100">
                <Button
                  v-if="form.status !== 'published' && form.id"
                  @click="publishPost(form); form.status = 'published'"
                  variant="outline"
                  size="sm"
                  class="w-full gap-2 border-emerald-200 text-emerald-700 hover:bg-emerald-50 hover:border-emerald-300 text-[12px]"
                >
                  <Eye class="w-3.5 h-3.5" />
                  Jetzt veröffentlichen
                </Button>
                <Button
                  v-else-if="form.status === 'published' && form.id"
                  @click="unpublishPost(form); form.status = 'draft'"
                  variant="outline"
                  size="sm"
                  class="w-full gap-2 border-amber-200 text-amber-700 hover:bg-amber-50 hover:border-amber-300 text-[12px]"
                >
                  <EyeOff class="w-3.5 h-3.5" />
                  Zurückziehen
                </Button>
                <p v-else class="text-[11px] text-muted-foreground text-center">Erst speichern, dann veröffentlichen</p>
              </div>
            </CardContent>
          </Card>

        </div>
      </div>

    </template>

  </div>
</template>
