/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Shared JavaScript
   ═══════════════════════════════════════════════════════════════ */

// API base: localhost -> lokaler Laravel (:8000), sonst Produktion
const API = (() => {
  const h = typeof window !== 'undefined' ? window.location.hostname : '';
  if (h === 'localhost' || h === '127.0.0.1') {
    return 'http://localhost:8000/api/website';
  }
  return 'https://kundenportal.sr-homes.at/api/website';
})();
const ACCENT = '#D4743B';
const ACCENT_HOVER = '#C0551F';

/* ─── Assets ───────────────────────────────────────────────── */
const ASSETS = {
  logoColor: 'https://api.immoji.org/image/c602e391-bb1f-445e-b783-70d7fd1de866.svg',
  logoWhite: 'https://api.immoji.org/image/e747b1e6-58ee-4dc8-a2ab-28ab72798310.svg',
  heroVideo: 'https://cdn.coverr.co/videos/coverr-aerial-shot-of-city-surrounded-by-mountains-1868/1080p.mp4',
  homeParallax: 'https://api.immoji.org/image/desktop-7214fedb-3350-449d-ae94-09510717b479.webp',
  teamImage: 'https://api.immoji.org/image/original/507f07b3-579d-4b20-a74b-90146e3abb02',
  contactImage: 'https://api.immoji.org/image/original/cff9990a-00ab-402a-b8f9-0836c62e8ebb',
  sellImage: 'https://api.immoji.org/image/original/2986c7bc-27ba-40f2-a829-791b2d7ec8e5',
  rentImage: 'https://api.immoji.org/image/original/a23dce9b-08ae-41df-9cc0-0dbe7b529a85',
  valueImage: 'https://api.immoji.org/image/original/03c539c9-0829-4aef-9270-3c5f368a2e13',
};

/* ─── Helpers ──────────────────────────────────────────────── */
const fmt = n => new Intl.NumberFormat('de-AT', { maximumFractionDigits: 0 }).format(n);
const esc = s => s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : '';
const buildApiUrl = (path, params = {}) => {
  const url = new URL(`${API}${path}`);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      url.searchParams.set(key, String(value));
    }
  });
  return url.toString();
};

/* ─── API Fetch ────────────────────────────────────────────── */
async function fetchProperties() {
  try {
    const r = await fetch(buildApiUrl('/properties', { refresh: 1, _: Date.now() }), { cache: 'no-store' });
    const d = await r.json();
    if (d.success && d.properties) return d.properties;
  } catch(e) { console.error('API fetch failed:', e); }
  return [];
}

async function fetchProperty(id) {
  try {
    const r = await fetch(buildApiUrl(`/property/${id}`, { refresh: 1, _: Date.now() }), { cache: 'no-store' });
    const d = await r.json();
    if (d.success && d.property) return d.property;
  } catch(e) { console.error('Property fetch failed:', e); }
  return null;
}

async function fetchCmsContent() {
  try {
    const r = await fetch(`${API}/content`);
    const d = await r.json();
    if (d.success && d.content) return d.content;
  } catch(e) { console.error('CMS fetch failed:', e); }
  return {};
}

/* ─── Property Mapper ──────────────────────────────────────── */
function mapProperty(p) {
  const price = p.price ? parseFloat(p.price) : 0;
  const area = p.area_living || p.area_land || p.size_m2 || 0;
  let title = p.title || p.project_name || '';
  if (!title) {
    const t = p.type || 'Immobilie';
    title = p.address ? `${t} — ${p.address}` : `${t} in ${p.city || 'Salzburg'}`;
  }
  const imgs = [];
  if (p.main_image_url) imgs.push(p.main_image_url);
  // /properties endpoint returns gallery_urls as flat array
  if (p.gallery_urls?.length) p.gallery_urls.forEach(u => { if (u && !imgs.includes(u)) imgs.push(u); });
  // /property/{id} endpoint returns images as array of objects with .url
  if (p.images?.length) p.images.forEach(img => { const u = img.url || img; if (u && !imgs.includes(u)) imgs.push(u); });
  const hasImages = imgs.length > 0;
  return { ...p, title, price, area, rooms: p.rooms || 0, images: imgs, hasImages, isNewbuild: p.property_category === 'newbuild' };
}

/* ─── Format Price ─────────────────────────────────────────── */
function fmtPrice(price, isNewbuild, isRental) {
  const pre = isNewbuild ? 'ab ' : '';
  const suffix = isRental ? ' / Monat' : '';
  const body = price >= 1e6
    ? `${pre}EUR ${(price/1e6).toFixed(2).replace('.',',')} Mio.`
    : `${pre}EUR ${fmt(price)}`;
  return body + suffix;
}

/* ─── Scroll Animations (IntersectionObserver) ─────────────── */
function initScrollAnimations() {
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('anim-fade-up');
        obs.unobserve(e.target);
      }
    });
  }, { threshold: 0.15 });
  document.querySelectorAll('[data-animate]').forEach(el => obs.observe(el));
}

/* ─── Nav Scroll Effect ────────────────────────────────────── */
function initNavScroll() {
  const nav = document.getElementById('main-nav');
  if (!nav) return;
  const isHome = document.body.dataset.page === 'home';
  function update() {
    const scrolled = window.scrollY > 60;
    if (scrolled || !isHome) {
      nav.style.background = 'rgba(250,248,245,0.7)';
      nav.style.backdropFilter = 'blur(24px) saturate(1.3)';
      nav.style.borderBottom = '1px solid rgba(229,224,216,0.5)';
      nav.querySelectorAll('.nav-link').forEach(l => l.style.color = '#5A564E');
      nav.querySelector('.nav-logo-color').style.display = '';
      nav.querySelector('.nav-logo-white').style.display = 'none';
      nav.querySelector('.nav-phone').style.color = '#5A564E';
      const burger = nav.querySelector('.nav-burger');
      if (burger) burger.style.color = '#0A0A08';
    } else {
      nav.style.background = 'transparent';
      nav.style.backdropFilter = 'none';
      nav.style.borderBottom = '1px solid transparent';
      nav.querySelectorAll('.nav-link').forEach(l => l.style.color = 'rgba(255,255,255,0.7)');
      nav.querySelector('.nav-logo-color').style.display = 'none';
      nav.querySelector('.nav-logo-white').style.display = '';
      nav.querySelector('.nav-phone').style.color = 'rgba(255,255,255,0.7)';
      const burger = nav.querySelector('.nav-burger');
      if (burger) burger.style.color = '#fff';
    }
  }
  window.addEventListener('scroll', update, { passive: true });
  update();
}

/* ─── Mobile Menu ──────────────────────────────────────────── */
function initMobileMenu() {
  const btn = document.getElementById('menu-toggle');
  const menu = document.getElementById('mobile-menu');
  const close = document.getElementById('menu-close');
  if (!btn || !menu) return;
  btn.addEventListener('click', () => { menu.classList.remove('hidden'); menu.classList.add('anim-fade-in'); });
  close?.addEventListener('click', () => menu.classList.add('hidden'));
  menu.querySelectorAll('a').forEach(a => a.addEventListener('click', () => menu.classList.add('hidden')));

  // Services Mega-Menu (Desktop)
  const dropdown = document.getElementById('services-dropdown');
  const panel = document.getElementById('services-panel');
  const chevron = document.getElementById('services-chevron');
  if (dropdown && panel) {
    let timeout;
    const show = () => { clearTimeout(timeout); panel.style.opacity='1'; panel.style.pointerEvents='auto'; panel.style.transform='translateX(-50%) translateY(0)'; if(chevron) chevron.style.transform='rotate(180deg)'; };
    const hide = () => { timeout = setTimeout(() => { panel.style.opacity='0'; panel.style.pointerEvents='none'; panel.style.transform='translateX(-50%) translateY(-8px)'; if(chevron) chevron.style.transform='rotate(0)'; }, 150); };
    dropdown.addEventListener('mouseenter', show);
    dropdown.addEventListener('mouseleave', hide);
    dropdown.querySelector('#services-trigger')?.addEventListener('click', (e) => {
      e.preventDefault();
      if (panel.style.opacity === '1') hide(); else show();
    });
  }

  // Mobile Services Accordion
  const mobileToggle = document.getElementById('mobile-services-toggle');
  const mobilePanel = document.getElementById('mobile-services-panel');
  const mobileChevron = document.getElementById('mobile-services-chevron');
  if (mobileToggle && mobilePanel) {
    let open = false;
    mobileToggle.addEventListener('click', () => {
      open = !open;
      mobilePanel.style.maxHeight = open ? mobilePanel.scrollHeight + 'px' : '0';
      if (mobileChevron) mobileChevron.style.transform = open ? 'rotate(180deg)' : 'rotate(0)';
    });
  }
}

/* ─── Component Loader ─────────────────────────────────────── */
async function loadComponents() {
  try {
    const base = document.querySelector('base')?.href || '';
    const root = base || (location.pathname.endsWith('/') ? location.href : location.href.replace(/\/[^/]*$/, '/'));
    const [navHtml, footerHtml] = await Promise.all([
      fetch(root + 'components/nav.html').then(r => r.text()),
      fetch(root + 'components/footer.html').then(r => r.text()),
    ]);
    const navEl = document.getElementById('nav-placeholder');
    if (navEl) { navEl.insertAdjacentHTML('afterend', navHtml); navEl.remove(); }
    const footerEl = document.getElementById('footer-placeholder');
    if (footerEl) { footerEl.insertAdjacentHTML('beforebegin', footerHtml); footerEl.remove(); }
  } catch(e) { console.error('Component load failed:', e); }
  initNavScroll();
  initMobileMenu();
  setTimeout(initScrollAnimations, 100);

  // Apply CMS phone number to nav + footer
  try {
    const cms = await fetchCmsContent();
    if (cms.contact?.phone) {
      const phone = cms.contact.phone;
      const phoneClean = phone.replace(/\s/g, '');
      document.querySelectorAll('a[href^="tel:"]').forEach(el => {
        el.href = 'tel:' + phoneClean;
        if (el.textContent.trim().match(/^\+?[0-9\s]+$/)) el.textContent = phone;
        el.querySelectorAll('span').forEach(span => {
          if (span.textContent.trim().match(/^\+?[0-9\s]+$/)) span.textContent = phone;
        });
      });
      // Also update non-link phone text in footer
      document.querySelectorAll('.footer-phone, [data-phone]').forEach(el => {
        el.textContent = phone;
      });
    }
  } catch(e) { console.error('CMS phone update failed:', e); }
}

/* ─── Init ─────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', loadComponents);
