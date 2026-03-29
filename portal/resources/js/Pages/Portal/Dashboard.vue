<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import PortalLayout from '@/Layouts/PortalLayout.vue'

const props = defineProps({
  customer: Object,
  properties: Array,
  projectGroups: Array,
  displayItems: Array,
  broker: Object,
})

// Items to display: use displayItems (grouped + ungrouped) or fall back to properties
const items = computed(() => props.displayItems || props.properties || [])

function statusLabel(status) {
  const map = {
    auftrag: 'Auftrag',
    inserat: 'Inserat live',
    anfragen: 'Anfragen',
    besichtigungen: 'Besichtigungen',
    angebote: 'Angebote',
    verhandlung: 'Verhandlung',
    verkauft: 'Verkauft',
  }
  if (status === 'inaktiv') return 'Inaktiv'
  return map[status] || status || 'Auftrag'
}

function statusColor(status) {
  if (status === 'inaktiv') return { bg: '#e4e4e7', text: '#71717a', dot: '#a1a1aa' }
  if (status === 'verkauft') return { bg: '#dcfce7', text: '#15803d', dot: '#22c55e' }
  if (status === 'verhandlung' || status === 'angebote') return { bg: '#fef3c7', text: '#b45309', dot: '#f59e0b' }
  return { bg: '#fff7ed', text: '#c2410c', dot: '#ee7606' }
}

function propertyInitials(address) {
  if (!address) return '?'
  return address.charAt(0).toUpperCase()
}
</script>

<template>
  <Head title="Dashboard" />
  <PortalLayout :customer="customer">
    <div class="dashboard-wrapper">

      <!-- Welcome Section -->
      <div class="welcome-section">
        <div class="welcome-content">
          <div class="welcome-text">
            <p class="welcome-eyebrow">KUNDENPORTAL</p>
            <h1 class="welcome-heading">
              Willkommen, {{ customer?.name?.split(' ')[0] }}
            </h1>
            <p class="welcome-sub">
              Hier sehen Sie den aktuellen Stand Ihrer Immobilien.
            </p>
          </div>
          <div class="welcome-decoration" aria-hidden="true">
            <div class="deco-ring deco-ring--1"></div>
            <div class="deco-ring deco-ring--2"></div>
            <div class="deco-dot"></div>
          </div>
        </div>
        <div class="welcome-divider"></div>
      </div>

      <!-- Properties Grid -->
      <div v-if="items && items.length > 0" class="properties-grid">
        <!-- Project Group Card -->
        <template v-for="(item, index) in items" :key="item.is_project_group ? 'g-'+item.id : item.id">
        <div v-if="item.is_project_group" class="property-card project-group-card" :class="'card-delay-' + (index % 6)">
          <!-- Top gradient accent (teal for groups) -->
          <div class="card-accent" style="background: linear-gradient(135deg, #0d9488, #14b8a6, #2dd4bf)"></div>

          <!-- Group badge -->
          <div class="card-status-float">
            <span class="status-pill" style="background: #ccfbf1; color: #0f766e;">
              <span class="status-dot" style="background: #14b8a6;"></span>
              Projekt · {{ item.properties?.length ?? 0 }} {{ (item.properties?.length ?? 0) === 1 ? 'Objekt' : 'Objekte' }}
            </span>
          </div>

          <div class="card-body">
            <div class="card-header">
              <div class="card-initial" style="background: linear-gradient(135deg, #0d9488, #14b8a6); color: white;">P</div>
              <div class="card-title-group">
                <h3 class="card-title">{{ item.name }}</h3>
                <div class="card-meta">
                  <span class="card-type">Projektgruppe</span>
                  <span v-if="item.description" class="card-location">{{ item.description }}</span>
                </div>
              </div>
            </div>

            <!-- Grouped Properties List -->
            <div style="margin: 12px 0; display: flex; flex-direction: column; gap: 6px;">
              <Link
                v-for="p in item.properties"
                :key="p.id"
                :href="route('portal.property', p.id)"
                style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f0fdfa; border-radius: 8px; text-decoration: none; color: inherit; transition: background 0.2s;"
                class="group-property-link"
              >
                <span style="width: 24px; height: 24px; border-radius: 6px; background: #ccfbf1; color: #0f766e; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600;">{{ p.ref_id ? p.ref_id.slice(-2) : '#' }}</span>
                <span style="flex: 1; font-size: 13px; font-weight: 500; color: #1e293b;">{{ p.title || p.city || p.address || p.ref_id || 'Einheit' }}</span>
                <span v-if="p.purchase_price" style="font-size: 12px; color: #64748b;">{{ Number(p.purchase_price).toLocaleString('de-AT') }} €</span>
                <svg style="width: 14px; height: 14px; color: #94a3b8;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
              </Link>
            </div>

            <!-- Aggregated Stats -->
            <div v-if="item.stats" class="stats-grid">
              <div class="stat-item">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="stat-value">{{ item.stats?.activities ?? 0 }}</div>
                <div class="stat-label">AKTIVITATEN</div>
              </div>
              <div class="stat-item stat-item--teal">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div class="stat-value stat-value--teal">{{ item.stats?.viewings ?? 0 }}</div>
                <div class="stat-label">BESICHTIGUNGEN</div>
              </div>
              <div class="stat-item stat-item--orange">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
                <div class="stat-value stat-value--orange">{{ item.stats?.offers ?? 0 }}</div>
                <div class="stat-label">KAUFANBOTE</div>
              </div>
              <div class="stat-item stat-item--green">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </div>
                <div class="stat-value stat-value--green">{{ item.stats?.followups ?? 0 }}</div>
                <div class="stat-label">NACHFASSEN</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Regular Property Card (unchanged) -->
        <!-- Inaktiv Property Card (locked) -->
        <div v-else-if="item.status === 'inaktiv'" class="property-card" :class="'card-delay-' + (index % 6)" style="opacity:0.55;pointer-events:none;position:relative">
          <div class="card-accent" style="background:linear-gradient(135deg,#a1a1aa 0%,#71717a 100%)"></div>
          <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;text-align:center">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#71717a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <div style="color:#71717a;font-size:12px;font-weight:600;margin-top:6px">Inaktiv</div>
          </div>
          <div class="card-status-float">
            <span class="status-pill" style="background:#e4e4e7;color:#71717a">
              <span class="status-dot" style="background:#a1a1aa"></span>
              Inaktiv
            </span>
          </div>
          <div class="card-body" style="filter:blur(1px)">
            <div class="card-header">
              <div class="card-initial" style="background:#e4e4e7;color:#71717a">{{ propertyInitials(item.address) }}</div>
              <div class="card-title-group">
                <h3 class="card-title">{{ item.project_name || item.address }}</h3>
                <div class="card-meta">
                  <span v-if="item.type" class="card-type">{{ item.type }}</span>
                  <span class="card-location">{{ item.zip }} {{ item.city }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Regular Property Card -->
        <Link
          v-else
          :href="route('portal.property', item.id)"
          class="property-card"
          :class="'card-delay-' + (index % 6)"
        >
          <!-- Top gradient accent -->
          <div class="card-accent"></div>

          <!-- Status badge floating -->
          <div class="card-status-float">
            <span
              class="status-pill"
              :style="{
                background: statusColor(item.status).bg,
                color: statusColor(item.status).text,
              }"
            >
              <span class="status-dot" :style="{ background: statusColor(item.status).dot }"></span>
              {{ statusLabel(item.status) }}
            </span>
          </div>

          <div class="card-body">
            <!-- Property Header -->
            <div class="card-header">
              <div class="card-initial">
                {{ propertyInitials(item.address) }}
              </div>
              <div class="card-title-group">
                <h3 class="card-title">{{ item.project_name || item.address }}</h3>
                <div class="card-meta">
                  <span v-if="item.type" class="card-type">{{ item.type }}</span>
                  <span class="card-location">{{ item.zip }} {{ item.city }}</span>
                </div>
              </div>
            </div>

            <!-- Stats Mini Dashboard -->
            <div v-if="item.stats" class="stats-grid">
              <div class="stat-item">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="stat-value">{{ item.stats?.activities ?? 0 }}</div>
                <div class="stat-label">AKTIVITATEN</div>
              </div>
              <div class="stat-item stat-item--teal">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div class="stat-value stat-value--teal">{{ item.stats?.viewings ?? 0 }}</div>
                <div class="stat-label">BESICHTIGUNGEN</div>
              </div>
              <div class="stat-item stat-item--orange">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
                <div class="stat-value stat-value--orange">{{ item.stats?.offers ?? 0 }}</div>
                <div class="stat-label">KAUFANBOTE</div>
              </div>
              <div class="stat-item stat-item--green">
                <div class="stat-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </div>
                <div class="stat-value stat-value--green">{{ item.stats?.followups ?? 0 }}</div>
                <div class="stat-label">FOLLOW-UPS</div>
              </div>
            </div>

            <!-- Units Overview (Neubauprojekte) -->
            <div v-if="item.units_summary" class="units-section">
              <div class="units-header">
                <span class="units-label">EINHEITEN</span>
                <span class="units-total">{{ item.units_summary.total }}</span>
              </div>
              <div class="units-bar">
                <div class="unit-block unit-block--frei">
                  <div class="unit-block-value">{{ item.units_summary.frei }}</div>
                  <div class="unit-block-label">frei</div>
                </div>
                <div v-if="item.units_summary.reserviert" class="unit-block unit-block--reserviert">
                  <div class="unit-block-value">{{ item.units_summary.reserviert }}</div>
                  <div class="unit-block-label">reserviert</div>
                </div>
                <div class="unit-block unit-block--verkauft">
                  <div class="unit-block-value">{{ item.units_summary.verkauft }}</div>
                  <div class="unit-block-label">verkauft</div>
                </div>
              </div>
            </div>

            <!-- Children (Unterobjekte) -->
            <div v-if="item.children && item.children.length" style="margin: 10px 0 4px; padding: 8px 0 0; border-top: 1px solid #f0f0f0;">
              <div style="font-size: 10px; font-weight: 600; letter-spacing: 0.05em; color: #6366f1; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 4px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                {{ item.children.length }} Unterobjekt{{ item.children.length > 1 ? 'e' : '' }}
              </div>
              <div style="display: flex; flex-direction: column; gap: 4px;">
                <div
                  v-for="child in item.children"
                  :key="child.id"
                  style="display: flex; align-items: center; gap: 8px; padding: 6px 8px; background: #f5f3ff; border-radius: 6px; border-left: 3px solid #6366f1; color: inherit;"
                >
                  <span style="flex: 1; font-size: 12px; font-weight: 500; color: #1e293b;">{{ child.title || child.address || child.ref_id || 'Unterobjekt' }}</span>
                  <span v-if="child.purchase_price" style="font-size: 11px; color: #64748b;">{{ Number(child.purchase_price).toLocaleString('de-AT') }} &euro;</span>
                </div>
              </div>
            </div>

            <!-- Ansprechpartner -->
            <div v-if="item.broker" class="card-broker">
              <div class="card-broker-avatar">
                {{ item.broker.initials || '?' }}
              </div>
              <div class="card-broker-info">
                <div class="card-broker-name">{{ item.broker.name }}</div>
                <div class="card-broker-contact">{{ item.broker.phone }}{{ item.broker.phone && item.broker.email ? ' · ' : '' }}{{ item.broker.email }}</div>
              </div>
            </div>

            <!-- Arrow indicator -->
            <div class="card-arrow">
              <span class="card-arrow-text">
                Details ansehen
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </span>
            </div>
          </div>
        </Link>
        </template>
      </div>

      <!-- Empty State -->
      <div v-else class="empty-state">
        <div class="empty-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#a8a29e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <p class="empty-text">Noch keine Immobilien vorhanden.</p>
      </div>

      <!-- Broker Contact Card -->
      <div v-if="broker" class="broker-card">
        <div class="broker-card-inner">
          <div class="broker-card-left">
            <div class="broker-avatar-lg">
              {{ broker.initials || 'SR' }}
            </div>
            <div class="broker-info">
              <p class="broker-role">IHR ANSPRECHPARTNER</p>
              <h3 class="broker-name">{{ broker.name }}</h3>
            </div>
          </div>
          <div class="broker-card-actions">
            <a v-if="broker.phone" :href="'tel:' + broker.phone.replace(/\s/g, '')" class="broker-btn broker-btn--phone">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              {{ broker.phone }}
            </a>
            <a v-if="broker.email" :href="'mailto:' + broker.email" class="broker-btn broker-btn--email">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
              {{ broker.email }}
            </a>
            <span class="broker-hours">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              Mo&#8211;Fr 8&#8211;18 Uhr
            </span>
          </div>
        </div>
      </div>

    </div>
  </PortalLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap');

/* ============================================
   Dashboard Wrapper
   ============================================ */
.dashboard-wrapper {
  font-family: 'DM Sans', sans-serif;
  min-height: 100vh;
  background: linear-gradient(180deg, #faf8f5 0%, #f5f0eb 100%);
  padding: 2rem 0;
}

/* ============================================
   Welcome Section
   ============================================ */
.welcome-section {
  position: relative;
  margin-bottom: 2.5rem;
}

.welcome-content {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}

.welcome-text {
  max-width: 480px;
}

.welcome-eyebrow {
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: #ee7606;
  margin-bottom: 0.5rem;
}

.welcome-heading {
  font-size: 1.75rem;
  font-weight: 700;
  color: #1c1917;
  letter-spacing: -0.025em;
  line-height: 1.2;
  margin-bottom: 0.5rem;
}

.welcome-sub {
  font-size: 0.9rem;
  color: #78716c;
  line-height: 1.6;
}

.welcome-decoration {
  position: relative;
  width: 80px;
  height: 80px;
  flex-shrink: 0;
  margin-top: 0.25rem;
}

.deco-ring {
  position: absolute;
  border-radius: 50%;
  border: 1.5px solid rgba(238, 118, 6, 0.1);
}

.deco-ring--1 {
  width: 64px;
  height: 64px;
  top: 0;
  right: 0;
  animation: decoSpin 20s linear infinite;
}

.deco-ring--2 {
  width: 40px;
  height: 40px;
  top: 12px;
  right: 12px;
  border-color: rgba(238, 118, 6, 0.18);
  animation: decoSpin 15s linear infinite reverse;
}

.deco-dot {
  position: absolute;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ee7606;
  top: 28px;
  right: 28px;
  opacity: 0.6;
}

.welcome-divider {
  height: 1px;
  background: linear-gradient(90deg, rgba(238, 118, 6, 0.15) 0%, rgba(168, 162, 158, 0.08) 100%);
  margin-top: 1.75rem;
}

/* ============================================
   Properties Grid
   ============================================ */
.properties-grid {
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1.25rem;
}

@media (min-width: 640px) {
  .properties-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* ============================================
   Property Card
   ============================================ */
.property-card {
  display: block;
  position: relative;
  background: #ffffff;
  border-radius: 1rem;
  border: 1px solid rgba(168, 162, 158, 0.12);
  overflow: hidden;
  text-decoration: none;
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1),
              box-shadow 0.3s cubic-bezier(0.22, 1, 0.36, 1),
              border-color 0.3s ease;
  box-shadow: 0 1px 3px rgba(120, 113, 108, 0.06),
              0 8px 24px rgba(120, 113, 108, 0.04);
  opacity: 0;
  animation: cardEntry 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}

.property-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(238, 118, 6, 0.08),
              0 16px 40px rgba(120, 113, 108, 0.08);
  border-color: rgba(238, 118, 6, 0.25);
}

/* Stagger animation delays */
.card-delay-0 { animation-delay: 0.05s; }
.card-delay-1 { animation-delay: 0.12s; }
.card-delay-2 { animation-delay: 0.19s; }
.card-delay-3 { animation-delay: 0.26s; }
.card-delay-4 { animation-delay: 0.33s; }
.card-delay-5 { animation-delay: 0.40s; }

.card-accent {
  height: 3px;
  background: linear-gradient(90deg, #ee7606 0%, rgba(238, 118, 6, 0.2) 100%);
}

.card-status-float {
  position: absolute;
  top: 1.1rem;
  right: 1.25rem;
  z-index: 2;
}

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.3rem 0.75rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.01em;
}

.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}

.card-body {
  padding: 1.35rem 1.25rem 1.25rem;
}

/* Card Header */
.card-header {
  display: flex;
  align-items: flex-start;
  gap: 0.875rem;
  margin-bottom: 1.25rem;
  padding-right: 5.5rem;
}

.card-initial {
  width: 44px;
  height: 44px;
  border-radius: 0.875rem;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 1rem;
  font-weight: 700;
  background: linear-gradient(135deg, rgba(238, 118, 6, 0.1) 0%, rgba(238, 118, 6, 0.05) 100%);
  color: #ee7606;
  border: 1px solid rgba(238, 118, 6, 0.08);
}

.card-title-group {
  flex: 1;
  min-width: 0;
}

.card-title {
  font-size: 0.938rem;
  font-weight: 700;
  color: #1c1917;
  letter-spacing: -0.015em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}

.card-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.3rem;
}

.card-type {
  font-size: 0.7rem;
  font-weight: 600;
  color: #ee7606;
  background: rgba(238, 118, 6, 0.06);
  padding: 0.125rem 0.5rem;
  border-radius: 4px;
  letter-spacing: 0.02em;
}

.card-location {
  font-size: 0.75rem;
  color: #a8a29e;
}

/* Stats Grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.5rem;
  padding: 1rem 0;
  border-top: 1px solid rgba(168, 162, 158, 0.1);
  border-bottom: 1px solid rgba(168, 162, 158, 0.1);
}

.stat-item {
  text-align: center;
  padding: 0.5rem 0.25rem;
  border-radius: 0.625rem;
  background: rgba(168, 162, 158, 0.04);
  transition: background 0.2s ease;
}

.stat-item:hover {
  background: rgba(168, 162, 158, 0.08);
}

.stat-item--teal { background: rgba(20, 184, 166, 0.04); }
.stat-item--teal:hover { background: rgba(20, 184, 166, 0.08); }
.stat-item--orange { background: rgba(238, 118, 6, 0.04); }
.stat-item--orange:hover { background: rgba(238, 118, 6, 0.08); }
.stat-item--green { background: rgba(16, 185, 129, 0.04); }
.stat-item--green:hover { background: rgba(16, 185, 129, 0.08); }

.stat-icon {
  color: #a8a29e;
  margin-bottom: 0.375rem;
  display: flex;
  justify-content: center;
}

.stat-item--teal .stat-icon { color: #14b8a6; }
.stat-item--orange .stat-icon { color: #ee7606; }
.stat-item--green .stat-icon { color: #10b981; }

.stat-value {
  font-size: 1.125rem;
  font-weight: 700;
  color: #1c1917;
  letter-spacing: -0.025em;
  line-height: 1;
}

.stat-value--teal { color: #14b8a6; }
.stat-value--orange { color: #ee7606; }
.stat-value--green { color: #10b981; }

.stat-label {
  font-size: 0.563rem;
  font-weight: 600;
  color: #a8a29e;
  letter-spacing: 0.08em;
  margin-top: 0.25rem;
}

/* Units Section */
.units-section {
  margin-top: 0.875rem;
  padding-top: 0.875rem;
  border-top: 1px solid rgba(168, 162, 158, 0.1);
}

.units-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.625rem;
}

.units-label {
  font-size: 0.625rem;
  font-weight: 700;
  color: #78716c;
  letter-spacing: 0.1em;
}

.units-total {
  font-size: 0.625rem;
  font-weight: 700;
  background: rgba(238, 118, 6, 0.08);
  color: #ee7606;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
}

.units-bar {
  display: flex;
  gap: 0.375rem;
}

.unit-block {
  flex: 1;
  border-radius: 0.5rem;
  padding: 0.5rem 0;
  text-align: center;
}

.unit-block--frei {
  background: rgba(16, 185, 129, 0.06);
  border-left: 2px solid rgba(16, 185, 129, 0.3);
}

.unit-block--reserviert {
  background: rgba(245, 158, 11, 0.06);
  border-left: 2px solid rgba(245, 158, 11, 0.3);
}

.unit-block--verkauft {
  background: rgba(239, 68, 68, 0.06);
  border-left: 2px solid rgba(239, 68, 68, 0.3);
}

.unit-block-value {
  font-size: 0.938rem;
  font-weight: 700;
}

.unit-block--frei .unit-block-value { color: #10b981; }
.unit-block--reserviert .unit-block-value { color: #f59e0b; }
.unit-block--verkauft .unit-block-value { color: #ef4444; }

.unit-block-label {
  font-size: 0.563rem;
  color: #78716c;
  margin-top: 0.125rem;
}

/* Card Broker */
.card-broker {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  margin-top: 0.875rem;
  padding-top: 0.875rem;
  border-top: 1px solid rgba(168, 162, 158, 0.1);
}

.card-broker-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.563rem;
  font-weight: 700;
  background: linear-gradient(135deg, rgba(238, 118, 6, 0.12) 0%, rgba(238, 118, 6, 0.04) 100%);
  color: #ee7606;
  flex-shrink: 0;
}

.card-broker-info {
  flex: 1;
  min-width: 0;
}

.card-broker-name {
  font-size: 0.75rem;
  font-weight: 600;
  color: #1c1917;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-broker-contact {
  font-size: 0.625rem;
  color: #a8a29e;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Card Arrow */
.card-arrow {
  display: flex;
  justify-content: flex-end;
  margin-top: 1rem;
}

.card-arrow-text {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.75rem;
  font-weight: 500;
  color: #a8a29e;
  transition: color 0.2s ease, gap 0.2s ease;
}

.property-card:hover .card-arrow-text {
  color: #ee7606;
  gap: 0.625rem;
}

/* ============================================
   Empty State
   ============================================ */
.empty-state {
  background: #ffffff;
  border-radius: 1.25rem;
  border: 1px solid rgba(168, 162, 158, 0.12);
  padding: 4rem 2rem;
  text-align: center;
  box-shadow: 0 1px 3px rgba(120, 113, 108, 0.06),
              0 8px 24px rgba(120, 113, 108, 0.04);
}

.empty-icon {
  width: 64px;
  height: 64px;
  margin: 0 auto 1.25rem;
  border-radius: 1.25rem;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #f5f0eb 0%, #faf8f5 100%);
  border: 1px solid rgba(168, 162, 158, 0.1);
}

.empty-text {
  font-size: 0.875rem;
  color: #78716c;
}

/* ============================================
   Broker Contact Card
   ============================================ */
.broker-card {
  margin-top: 2rem;
  border-radius: 1.25rem;
  background: linear-gradient(135deg, #fffbf7 0%, #fff7ed 50%, #fef3e2 100%);
  border: 1px solid rgba(238, 118, 6, 0.1);
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(238, 118, 6, 0.04),
              0 8px 24px rgba(238, 118, 6, 0.03);
  opacity: 0;
  animation: cardEntry 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.45s forwards;
}

.broker-card-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1.25rem;
  padding: 1.5rem 1.75rem;
}

.broker-card-left {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.broker-avatar-lg {
  width: 48px;
  height: 48px;
  border-radius: 0.875rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  font-weight: 700;
  background: linear-gradient(135deg, #ee7606 0%, #f59e0b 100%);
  color: #ffffff;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(238, 118, 6, 0.2);
}

.broker-info {
  min-width: 0;
}

.broker-role {
  font-size: 0.6rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: #ee7606;
  margin-bottom: 0.25rem;
}

.broker-name {
  font-size: 1.05rem;
  font-weight: 700;
  color: #1c1917;
  letter-spacing: -0.015em;
}

.broker-card-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.625rem;
}

.broker-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 0.625rem;
  font-size: 0.8rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s ease;
}

.broker-btn--phone {
  background: #ee7606;
  color: #ffffff;
  box-shadow: 0 2px 8px rgba(238, 118, 6, 0.2);
}

.broker-btn--phone:hover {
  background: #d16805;
  box-shadow: 0 4px 16px rgba(238, 118, 6, 0.3);
  transform: translateY(-1px);
}

.broker-btn--email {
  background: #ffffff;
  color: #1c1917;
  border: 1px solid rgba(168, 162, 158, 0.2);
  box-shadow: 0 1px 3px rgba(120, 113, 108, 0.06);
}

.broker-btn--email:hover {
  border-color: #ee7606;
  color: #ee7606;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(238, 118, 6, 0.08);
}

.broker-hours {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.7rem;
  font-weight: 500;
  color: #78716c;
  background: rgba(255, 255, 255, 0.6);
  padding: 0.375rem 0.75rem;
  border-radius: 999px;
  border: 1px solid rgba(168, 162, 158, 0.1);
}

/* ============================================
   Animations
   ============================================ */
@keyframes cardEntry {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes decoSpin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* ============================================
   Responsive
   ============================================ */
@media (max-width: 639px) {
  .welcome-decoration {
    display: none;
  }

  .broker-card-inner {
    flex-direction: column;
    align-items: flex-start;
    padding: 1.25rem;
  }

  .broker-card-actions {
    width: 100%;
  }

  .broker-btn {
    flex: 1;
    justify-content: center;
    min-width: 0;
  }

  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

.project-group-card {
  grid-column: 1 / -1;
}
.group-property-link:hover {
  background: #ccfbf1 !important;
}
</style>
