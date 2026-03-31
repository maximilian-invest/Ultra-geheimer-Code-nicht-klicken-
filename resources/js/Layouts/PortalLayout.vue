<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
  customer: Object,
})

const mobileMenuOpen = ref(false)

const logoutForm = useForm({})

function logout() {
  logoutForm.post(route('logout'))
}

const initials = computed(() => {
  if (!props.customer?.name) return '??'
  return props.customer.name
    .split(' ')
    .map(p => p.charAt(0).toUpperCase())
    .slice(0, 2)
    .join('')
})
</script>

<template>
  <div class="min-h-screen portal-shell">
    <div class="fixed inset-0 pointer-events-none" style="background: radial-gradient(ellipse 80% 50% at 50% -20%, rgba(238,118,6,0.04), transparent); z-index: 0;"></div>
    <header class="portal-header sticky top-0 z-50">
      <div class="portal-header-inner">
        <div class="flex items-center justify-between h-[72px]">
          <Link :href="route('portal.dashboard')" class="flex items-center gap-3.5 no-underline group">
            <div class="portal-logo-mark">
              <span class="relative z-10 font-bold text-[11px] tracking-wider text-white">SR</span>
            </div>
            <div class="flex flex-col">
              <span class="font-semibold text-[13px] tracking-tight" style="color: #1c1917;">SR-Homes</span>
              <span class="text-[10px] font-medium tracking-wide uppercase" style="color: #a8a29e; letter-spacing: 0.08em;">Kundenportal</span>
            </div>
          </Link>
          <div class="hidden sm:flex items-center gap-3">
            <div class="portal-user-pill">
              <div class="portal-avatar">{{ initials }}</div>
              <span class="text-[13px] font-medium" style="color: #57534e;">{{ customer?.name }}</span>
            </div>
            <button @click="logout" class="portal-logout-btn">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
          </div>
          <button @click="mobileMenuOpen = !mobileMenuOpen" class="sm:hidden w-10 h-10 rounded-xl flex items-center justify-center transition-all" :style="mobileMenuOpen ? 'background: #f5f0eb; color: #ee7606;' : 'color: #a8a29e;'">
            <svg v-if="!mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <Transition name="slide">
          <div v-if="mobileMenuOpen" class="sm:hidden pb-4 pt-2">
            <div class="flex items-center gap-3 p-3 rounded-2xl" style="background: #faf8f5;">
              <div class="portal-avatar">{{ initials }}</div>
              <div class="flex-1 min-w-0">
                <span class="text-sm font-medium block" style="color: #1c1917;">{{ customer?.name }}</span>
              </div>
              <button @click="logout" class="portal-logout-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </header>
    <main class="relative z-10 max-w-6xl mx-auto px-4 sm:px-8 py-8 sm:py-10">
      <slot />
    </main>
    <footer class="relative z-10 border-t" style="border-color: rgba(168,162,158,0.12);">
      <div class="max-w-6xl mx-auto px-4 sm:px-8 py-6 flex items-center justify-between">
        <span class="text-[11px] font-medium" style="color: #a8a29e;">&copy; 2026 SR-Homes Immobilien GmbH</span>
        <span class="text-[11px]" style="color: #d6d3d1;">kundenportal.sr-homes.at</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.portal-shell {
  background: linear-gradient(180deg, #faf8f5 0%, #f5f0eb 100%);
  font-family: 'DM Sans', 'Segoe UI', system-ui, -apple-system, sans-serif;
  min-height: 100vh;
}
.portal-header {
  background: rgba(255,255,255,0.82);
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border-bottom: 1px solid rgba(168,162,158,0.1);
}
.portal-header-inner {
  max-width: 72rem;
  margin: 0 auto;
  padding: 0 1rem;
}
@media (min-width: 640px) {
  .portal-header-inner { padding: 0 2rem; }
}
.portal-logo-mark {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: linear-gradient(135deg, #ee7606 0%, #d16805 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(238,118,6,0.25), inset 0 1px 0 rgba(255,255,255,0.15);
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s ease;
}
.portal-logo-mark:hover,
.group:hover .portal-logo-mark {
  transform: scale(1.05) rotate(-2deg);
  box-shadow: 0 4px 16px rgba(238,118,6,0.3), inset 0 1px 0 rgba(255,255,255,0.15);
}
.portal-avatar {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.02em;
  background: linear-gradient(135deg, #fef3e6 0%, #fde8d0 100%);
  color: #ee7606;
  border: 1px solid rgba(238,118,6,0.1);
}
.portal-user-pill {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  padding: 5px 14px 5px 5px;
  border-radius: 14px;
  background: #faf8f5;
  border: 1px solid rgba(168,162,158,0.08);
  transition: all 0.2s ease;
}
.portal-user-pill:hover {
  background: #f5f0eb;
  border-color: rgba(168,162,158,0.15);
}
.portal-logout-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #a8a29e;
  background: transparent;
  border: 1px solid transparent;
  cursor: pointer;
  transition: all 0.2s ease;
}
.portal-logout-btn:hover {
  background: #fef2f2;
  color: #ef4444;
  border-color: rgba(239,68,68,0.1);
}
.slide-enter-active,
.slide-leave-active {
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  overflow: hidden;
}
.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}
.slide-enter-to,
.slide-leave-from {
  opacity: 1;
  max-height: 200px;
  transform: translateY(0);
}
</style>
