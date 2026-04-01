# Sidebar Redesign — shadcn/vue

## Summary
Redesign the admin sidebar using shadcn/vue component patterns. Same functionality, new design. Mobile-first.

## Design Decisions
- **Style**: Light background (white), orange accent on active item (`--sidebar-accent: #fff7ed`, `--sidebar-accent-foreground: #ea580c`)
- **Collapsible**: Toggle button (not hover). State persisted in localStorage. Collapsed = icon-only with Tooltip on each item.
- **Mobile**: Sheet overlay from left (shadcn Sheet component). Hamburger trigger in top bar.
- **Groups**: Navigation split into 3 labeled groups with Separator between them:
  - **Hauptnavigation**: Dashboard, Aktionen, Aufgaben, Kommunikation, Objekte
  - **Auswertungen**: Berichte, Marktanalyse
  - **System**: Kalender, Kontakte, Website (admin-only)
- **Footer**: Avatar with user initials + name, Settings gear, Logout, Dark Mode toggle
- **Dark Mode**: Sidebar gets dark variant CSS variables (already exists in codebase)

## Components Used
- shadcn `Button` — for SidebarTrigger toggle
- shadcn `Tooltip` + `TooltipProvider` — show label on hover when collapsed
- shadcn `Sheet` + `SheetContent` + `SheetTrigger` — mobile overlay sidebar
- shadcn `Avatar` + `AvatarFallback` — user avatar in footer
- shadcn `Separator` — between nav groups
- lucide-vue-next icons — already installed

## Files Modified
- `resources/js/Pages/Admin/Dashboard.vue` — sidebar template + styles rewritten
- No new components needed (sidebar built directly in Dashboard.vue with shadcn primitives)

## Architecture
The sidebar stays in Dashboard.vue (not extracted to separate component) to maintain all existing provide/inject, tab switching, and state management. Only the `<aside>` template section and related CSS are replaced.

## Behavior Specs
1. **Desktop expanded** (default on first visit): 240px wide, shows icons + labels + badges + group labels
2. **Desktop collapsed**: 48px wide, icon-only, Tooltip on hover showing label + badge count
3. **Toggle**: Button at bottom of sidebar. Click toggles collapsed state. Saved to localStorage key `sr-sidebar-collapsed`.
4. **Mobile (<768px)**: No sidebar visible by default. Hamburger button in top bar opens Sheet from left with full sidebar content. Clicking nav item closes Sheet.
5. **Badges**: Same logic as current (navBadge function unchanged)
6. **Active state**: Orange background tint (`#fff7ed`) + orange text (`#ea580c`)
7. **Dark mode**: CSS variables switch to dark variant (existing `html.dark` selectors)

## What Does NOT Change
- Tab switching logic (switchTab function)
- All provide/inject values
- Notification bell + dropdown
- Viewing creation modal
- Toast system
- All tab components
- navBadge logic
- adminOnly / assistenzOnly filtering
