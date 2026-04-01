# Sidebar Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the admin sidebar in Dashboard.vue with shadcn/vue component patterns — light theme, orange accent, toggle collapse, Sheet on mobile, grouped navigation.

**Architecture:** All changes are in `Dashboard.vue`. The `<aside>` template block, its CSS, and related script refs are replaced. Existing provide/inject, tab switching, navBadge, and all other logic remain untouched. shadcn components (Tooltip, Sheet, Avatar, Separator, Button) are imported and used directly.

**Tech Stack:** Vue 3, shadcn-vue (Tooltip, Sheet, Avatar, Separator, Button), Tailwind CSS, lucide-vue-next

---

### Task 1: Update script imports and sidebar state

**Files:**
- Modify: `resources/js/Pages/Admin/Dashboard.vue` (lines 1-42)

- [ ] **Step 1: Add shadcn component imports after existing imports**

Add these imports after the lucide imports (around line 21):

```javascript
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
```

- [ ] **Step 2: Update sidebar state refs**

Replace the existing `sidebarOpen` and `sidebarCollapsed` refs (around line 40-41):

```javascript
const sidebarCollapsed = ref(localStorage.getItem("sr-sidebar-collapsed") === "1");
const mobileOpen = ref(false);
```

Remove the old `sidebarOpen` ref entirely. Add toggle function:

```javascript
function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem("sr-sidebar-collapsed", sidebarCollapsed.value ? "1" : "0");
}
```

- [ ] **Step 3: Update navItems to include group info**

Replace the `allNavItems` array with grouped structure:

```javascript
const navGroups = [
    {
        label: "Hauptnavigation",
        items: [
            { key: "today", label: "Dashboard", icon: LayoutDashboard },
            { key: "priorities", label: "Aktionen", icon: Zap },
            { key: "tasks", label: "Aufgaben", icon: ListTodo },
            { key: "comms", label: "Kommunikation", icon: MessageSquare },
            { key: "properties", label: "Objekte", icon: Home },
        ],
    },
    {
        label: "Auswertungen",
        items: [
            { key: "reports", label: "Berichte", icon: FileText },
            { key: "analytics", label: "Marktanalyse", icon: TrendingUp },
        ],
    },
    {
        label: "System",
        items: [
            { key: "calendar", label: "Kalender", icon: Calendar },
            { key: "admin", label: "Kontakte", icon: Users },
            { key: "website", label: "Website", icon: Globe, adminOnly: true },
        ],
    },
];

const filteredGroups = computed(() =>
    navGroups.map(g => ({
        ...g,
        items: g.items.filter(i => {
            if (i.adminOnly && !isAdmin.value) return false;
            if (i.assistenzOnly && !isAssistenz.value) return false;
            return true;
        }),
    })).filter(g => g.items.length > 0)
);
```

- [ ] **Step 4: Update switchTab to close mobile sheet**

```javascript
function switchTab(t) {
    tab.value = t;
    mobileOpen.value = false;
    window.scrollTo({ top: 0, behavior: "smooth" });
}
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Dashboard.vue
git commit -m "feat(sidebar): update imports, state, and nav groups for redesign"
```

---

### Task 2: Replace sidebar template

**Files:**
- Modify: `resources/js/Pages/Admin/Dashboard.vue` (template section, lines 358-413)

- [ ] **Step 1: Replace the entire sidebar template block**

Remove everything from `<!-- Mobile overlay -->` through `</aside>` (lines 361-413) and the hover trigger zone. Replace with:

```html
        <!-- Mobile Sheet Sidebar -->
        <Sheet v-model:open="mobileOpen">
            <SheetContent side="left" class="w-64 p-0 md:hidden">
                <nav class="flex flex-col h-full">
                    <div class="px-4 py-5 flex items-center gap-2">
                        <img v-if="!darkMode" src="/assets/logo-full-orange.svg" alt="SR-Homes" style="height:24px" />
                        <img v-else src="/assets/logo-full-white.svg" alt="SR-Homes" style="height:24px" />
                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded text-white bg-[#EE7600]">Cockpit</span>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 pb-3">
                        <template v-for="(group, gi) in filteredGroups" :key="gi">
                            <Separator v-if="gi > 0" class="my-2" />
                            <p class="px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ group.label }}</p>
                            <div class="flex flex-col gap-0.5">
                                <button v-for="item in group.items" :key="item.key" @click="switchTab(item.key)"
                                    class="flex items-center gap-2.5 px-2.5 py-2 rounded-md text-sm transition-colors"
                                    :class="tab === item.key
                                        ? 'bg-[#fff7ed] text-[#ea580c] font-medium'
                                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'">
                                    <component :is="item.icon" class="w-4 h-4 shrink-0" />
                                    <span class="flex-1 text-left">{{ item.label }}</span>
                                    <span v-if="navBadge(item.key)" class="text-[10px] font-medium px-1.5 py-0.5 rounded-md"
                                        :class="tab === item.key ? 'bg-orange-100 text-orange-600' : 'bg-muted text-muted-foreground'">{{ navBadge(item.key) }}</span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <div class="border-t border-border p-3 space-y-2">
                        <div class="flex items-center gap-2.5 px-2">
                            <Avatar class="h-7 w-7 rounded-md">
                                <AvatarFallback class="rounded-md bg-[#fff7ed] text-[#ea580c] text-[10px] font-semibold">{{ userInitials }}</AvatarFallback>
                            </Avatar>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-medium truncate">{{ userName }}</div>
                            </div>
                            <Button v-if="isAdmin" variant="ghost" size="icon-sm" @click="switchTab('settings')"><Settings class="w-3.5 h-3.5" /></Button>
                            <Button variant="ghost" size="icon-sm" @click.prevent="useForm({}).post(route('logout'))"><LogOut class="w-3.5 h-3.5" /></Button>
                        </div>
                        <button @click="toggleDarkMode()" class="flex items-center gap-2 px-2 w-full text-left">
                            <Moon v-if="!darkMode" class="w-3.5 h-3.5 text-muted-foreground" />
                            <Sun v-else class="w-3.5 h-3.5 text-muted-foreground" />
                            <span class="text-[10px] font-medium text-muted-foreground">{{ darkMode ? 'Light Mode' : 'Dark Mode' }}</span>
                        </button>
                    </div>
                </nav>
            </SheetContent>
        </Sheet>

        <!-- Desktop Sidebar -->
        <TooltipProvider :delay-duration="0">
        <aside class="hidden md:flex flex-col h-screen sticky top-0 border-r border-border bg-background transition-all duration-200"
            :style="{ width: sidebarCollapsed ? '48px' : '240px' }">
            <div class="px-3 py-4 flex items-center" :class="sidebarCollapsed ? 'justify-center' : 'gap-2'">
                <img v-if="sidebarCollapsed && !darkMode" src="/assets/logo-icon-orange.svg" alt="SR" class="shrink-0" style="width:28px" />
                <img v-if="sidebarCollapsed && darkMode" src="/assets/logo-icon-white.svg" alt="SR" class="shrink-0" style="width:28px" />
                <img v-if="!sidebarCollapsed && !darkMode" src="/assets/logo-full-orange.svg" alt="SR-Homes" class="shrink-0" style="height:24px" />
                <img v-if="!sidebarCollapsed && darkMode" src="/assets/logo-full-white.svg" alt="SR-Homes" class="shrink-0" style="height:24px" />
                <span v-if="!sidebarCollapsed" class="text-[10px] font-semibold px-1.5 py-0.5 rounded text-white bg-[#EE7600]">Cockpit</span>
            </div>
            <nav class="flex-1 overflow-y-auto px-2 pb-2">
                <template v-for="(group, gi) in filteredGroups" :key="gi">
                    <Separator v-if="gi > 0" class="my-2" />
                    <p v-if="!sidebarCollapsed" class="px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ group.label }}</p>
                    <div class="flex flex-col gap-0.5" :class="sidebarCollapsed ? 'items-center' : ''">
                        <template v-for="item in group.items" :key="item.key">
                            <Tooltip v-if="sidebarCollapsed">
                                <TooltipTrigger asChild>
                                    <button @click="switchTab(item.key)"
                                        class="relative flex items-center justify-center w-9 h-9 rounded-md transition-colors"
                                        :class="tab === item.key
                                            ? 'bg-[#fff7ed] text-[#ea580c]'
                                            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'">
                                        <component :is="item.icon" class="w-4 h-4" />
                                        <span v-if="navBadge(item.key)" class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 flex items-center justify-center rounded-full text-[9px] font-bold bg-orange-500 text-white px-1">{{ navBadge(item.key) }}</span>
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="right" :side-offset="8">
                                    {{ item.label }}<span v-if="navBadge(item.key)"> ({{ navBadge(item.key) }})</span>
                                </TooltipContent>
                            </Tooltip>
                            <button v-else @click="switchTab(item.key)"
                                class="flex items-center gap-2.5 px-2.5 py-2 rounded-md text-sm transition-colors"
                                :class="tab === item.key
                                    ? 'bg-[#fff7ed] text-[#ea580c] font-medium'
                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'">
                                <component :is="item.icon" class="w-4 h-4 shrink-0" />
                                <span class="flex-1 text-left">{{ item.label }}</span>
                                <span v-if="navBadge(item.key)" class="text-[10px] font-medium px-1.5 py-0.5 rounded-md"
                                    :class="tab === item.key ? 'bg-orange-100 text-orange-600' : 'bg-muted text-muted-foreground'">{{ navBadge(item.key) }}</span>
                            </button>
                        </template>
                    </div>
                </template>
            </nav>
            <div class="border-t border-border p-2 space-y-1">
                <div v-if="!sidebarCollapsed" class="flex items-center gap-2.5 px-2 py-1">
                    <Avatar class="h-7 w-7 rounded-md">
                        <AvatarFallback class="rounded-md bg-[#fff7ed] text-[#ea580c] text-[10px] font-semibold">{{ userInitials }}</AvatarFallback>
                    </Avatar>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium truncate">{{ userName }}</div>
                    </div>
                    <Button v-if="isAdmin" variant="ghost" size="icon-sm" @click="switchTab('settings')" title="Einstellungen"><Settings class="w-3.5 h-3.5" /></Button>
                    <Button variant="ghost" size="icon-sm" @click.prevent="useForm({}).post(route('logout'))" title="Abmelden"><LogOut class="w-3.5 h-3.5" /></Button>
                </div>
                <div v-if="sidebarCollapsed" class="flex flex-col items-center gap-1">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Avatar class="h-7 w-7 rounded-md cursor-default">
                                <AvatarFallback class="rounded-md bg-[#fff7ed] text-[#ea580c] text-[10px] font-semibold">{{ userInitials }}</AvatarFallback>
                            </Avatar>
                        </TooltipTrigger>
                        <TooltipContent side="right">{{ userName }}</TooltipContent>
                    </Tooltip>
                    <Tooltip v-if="isAdmin">
                        <TooltipTrigger asChild>
                            <Button variant="ghost" size="icon-sm" @click="switchTab('settings')"><Settings class="w-3.5 h-3.5" /></Button>
                        </TooltipTrigger>
                        <TooltipContent side="right">Einstellungen</TooltipContent>
                    </Tooltip>
                </div>
                <button v-if="!sidebarCollapsed" @click="toggleDarkMode()" class="flex items-center gap-2 px-2 py-1 w-full text-left rounded-md hover:bg-accent transition-colors">
                    <Moon v-if="!darkMode" class="w-3.5 h-3.5 text-muted-foreground" />
                    <Sun v-else class="w-3.5 h-3.5 text-muted-foreground" />
                    <span class="text-[10px] font-medium text-muted-foreground">{{ darkMode ? 'Light Mode' : 'Dark Mode' }}</span>
                </button>
            </div>
            <button @click="toggleSidebar()"
                class="flex items-center justify-center gap-2 py-2 border-t border-border text-muted-foreground hover:text-foreground hover:bg-accent transition-colors">
                <ChevronsLeft class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-180': sidebarCollapsed }" />
                <span v-if="!sidebarCollapsed" class="text-[10px] font-medium">Einklappen</span>
            </button>
        </aside>
        </TooltipProvider>
```

- [ ] **Step 2: Update the top bar hamburger button**

Replace the hamburger button in the main content area (around line 419):

```html
<button @click="mobileOpen = true" class="md:hidden flex items-center justify-center w-9 h-9 rounded-lg -ml-1 mr-1 text-foreground bg-muted" title="Menü"><Menu class="w-5 h-5" /></button>
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Admin/Dashboard.vue
git commit -m "feat(sidebar): replace template with shadcn components"
```

---

### Task 3: Clean up CSS

**Files:**
- Modify: `resources/js/Pages/Admin/Dashboard.vue` (style section, lines 552-660)

- [ ] **Step 1: Remove old sidebar CSS**

Delete these CSS rules that are no longer needed:
- `.admin-sidebar` and all related rules
- `.sidebar-collapsed-width`
- `.sidebar-expanded-width`
- `.nav-icon-circle`
- `.nav-item` and `.nav-item.active` and `.nav-item:hover`
- `.admin-sidebar-toggle`
- The mobile `@media (max-width: 767px)` rule for `.admin-sidebar`

Add sidebar CSS variables for dark mode under `html.dark`:

```css
html.dark {
    --sidebar-accent: rgba(249,115,22,0.12);
    --sidebar-accent-foreground: #fb923c;
}
```

- [ ] **Step 2: Remove unused script refs**

Remove `sidebarOpen` from any remaining template references. Remove `allNavItems` and `navItems` computed (replaced by `navGroups` and `filteredGroups`).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Admin/Dashboard.vue
git commit -m "feat(sidebar): clean up old CSS and unused refs"
```

---

### Task 4: Build and verify

- [ ] **Step 1: Build JS**

```bash
cd /var/www/srhomes && npx vite build
```

Expected: Build succeeds with no errors.

- [ ] **Step 2: Clear cache**

```bash
php artisan optimize:clear && php artisan optimize
```

- [ ] **Step 3: Visual verification in browser**

Open https://kundenportal.sr-homes.at/admin and verify:
- Desktop: Sidebar shows grouped navigation with labels
- Toggle button collapses to icon-only with tooltips
- Mobile: Hamburger opens Sheet from left
- All tabs work, badges show, dark mode works
- User avatar + settings + logout in footer

- [ ] **Step 4: Final commit and push**

```bash
git add -A
git commit -m "feat(sidebar): complete redesign with shadcn/vue components

- Light theme with orange accent on active items
- Toggle collapse with localStorage persistence
- Sheet overlay on mobile
- Grouped navigation (Hauptnavigation/Auswertungen/System)
- Tooltip on collapsed icons
- Avatar component in footer

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
git push origin main
```
