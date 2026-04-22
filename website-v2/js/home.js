/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Homepage JS (Property Cards from API)
   ═══════════════════════════════════════════════════════════════ */

(async function() {
  /* ─── Load CMS content for hero video ─── */
  const cms = await fetchCmsContent();
  const heroVideo = document.querySelector('.hero-video');
  if (heroVideo && cms.hero?.video_url) {
    heroVideo.src = cms.hero.video_url;
  }

  /* ─── Apply CMS hero headline + subheadline ─── */
  const heroH1 = document.querySelector('.hero-h1');
  if (heroH1 && cms.hero?.headline) {
    const accent = cms.hero.headline_accent || 'Zuhause';
    const hl = cms.hero.headline;
    heroH1.innerHTML = hl.replace(accent, '<span style="color:#E8743A">' + accent + '</span>');
  }
  const heroSub = heroH1 && heroH1.nextElementSibling;
  if (heroSub && heroSub.tagName === 'P' && cms.hero?.subheadline) {
    heroSub.textContent = cms.hero.subheadline;
  }

  /* ─── Apply CMS portal headline + subheadline ─── */
  const portalH = document.getElementById('portal-headline');
  if (portalH && cms.portal?.headline) portalH.textContent = cms.portal.headline;
  const portalSub = document.getElementById('portal-subheadline');
  if (portalSub && cms.portal?.subheadline) portalSub.textContent = cms.portal.subheadline;

  /* ─── Apply CMS about/parallax section ─── */
  const aboutH = document.getElementById('about-headline');
  if (aboutH && cms.about?.parallax_headline) aboutH.textContent = cms.about.parallax_headline;
  const aboutP = document.getElementById('about-text');
  if (aboutP && cms.about?.parallax_text) aboutP.textContent = cms.about.parallax_text;

  /* ─── Apply CMS stats ─── */
  const statsGrid = document.getElementById('stats-grid');
  if (statsGrid && cms.stats) {
    const stats = Object.values(cms.stats).filter(s => s && s.value);
    if (stats.length) {
      statsGrid.innerHTML = stats.map(s => '<div class="text-center" data-animate><div class="flex items-baseline justify-center gap-1"><span class="stat-number text-5xl md:text-7xl font-bold tracking-tighter" style="color:#0A0A08">' + s.value + '</span><span class="text-lg md:text-xl font-semibold" style="color:#D4743B">' + (s.suffix || '') + '</span></div><span class="text-sm font-medium mt-2 block uppercase tracking-widest" style="color:#9A958C;letter-spacing:0.15em">' + (s.label || '') + '</span></div>').join('');
    }
  }

  /* ─── Apply CMS services ─── */
  const servicesGrid = document.getElementById('services-grid');
  if (servicesGrid && cms.services) {
    const svcs = Object.values(cms.services).filter(s => s && s.title);
    if (svcs.length) {
      servicesGrid.innerHTML = svcs.map(s => '<div class="hover-lift hover-glow p-7 rounded-2xl" style="background:#fff;border:1px solid #F0ECE6"><div class="w-12 h-12 rounded-xl flex items-center justify-center mb-5" style="background:rgba(212,116,59,0.08)"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><h3 class="text-base font-bold tracking-tight mb-2" style="color:#0A0A08">' + s.title + '</h3><p class="text-sm leading-relaxed" style="color:#9A958C">' + (s.desc || '') + '</p></div>').join('');
    }
  }

  /* ─── Apply CMS phone number ─── */
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
  }

  const container = document.getElementById('featured-properties');
  const countLabel = document.getElementById('property-count-label');
  if (!container) return;

  const raw = await fetchProperties();
  if (!raw.length) { container.innerHTML = '<p class="text-sm" style="color:#9A958C">Keine Immobilien gefunden.</p>'; return; }

  const allProps = raw.map(mapProperty);
  const props = allProps.filter(p => p.realty_status !== 'verkauft');
  if (countLabel) countLabel.textContent = `Alle ${props.length} Objekte`;

  /* ─── Featured Cards (first 3 large, rest as regular cards) ─── */
  const maxFeatured = 5;
  const featured = props.slice(0, Math.min(3, maxFeatured));
  const rest = props.slice(3, maxFeatured);

  let html = '';

  /* Large featured grid: 1 big left + 2 stacked right */
  if (featured.length > 0) {
    html += '<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">';
    html += featuredCard(featured[0], 0);
    if (featured.length > 1) {
      html += '<div class="grid grid-rows-2 gap-5">';
      if (featured[1]) html += featuredCard(featured[1], 1);
      if (featured[2]) html += featuredCard(featured[2], 2);
      html += '</div>';
    }
    html += '</div>';
  }

  /* Regular cards grid — auto-fill based on count */
  if (rest.length > 0) {
    const cols = rest.length === 1 ? 'lg:grid-cols-2' : rest.length === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-3';
    html += `<div class="grid grid-cols-1 md:grid-cols-2 ${cols} gap-6 mt-6">`;
    rest.forEach(p => { html += propertyCard(p); });
    html += '</div>';
  }

  container.innerHTML = html;

  /* ─── Sold Properties Section ─── */
  const soldSection = document.getElementById('sold-section');
  const soldContainer = document.getElementById('sold-properties');
  const soldStatsEl = document.getElementById('sold-stats');
  const soldProps = allProps.filter(p => p.realty_status === 'verkauft');
  if (soldSection && soldContainer && soldProps.length) {
    soldProps.sort((a, b) => (b.sold_at || '').localeCompare(a.sold_at || ''));

    // Filter to last 3 months only
    const cutoff = new Date();
    cutoff.setMonth(cutoff.getMonth() - 3);
    const recentSold = soldProps.filter(p => p.sold_at && new Date(p.sold_at) >= cutoff);

    if (!recentSold.length) { soldSection.style.display = 'none'; return; }
    soldSection.style.display = '';

    // Stats — only recent
    if (soldStatsEl) {
      const vol = recentSold.reduce((s, p) => s + parseFloat(p.purchase_price || p.rental_price || 0), 0);
      const volStr = vol >= 1e6 ? '\u20ac ' + (vol / 1e6).toFixed(1).replace('.', ',') + ' Mio.' : '\u20ac ' + fmt(vol);
      soldStatsEl.innerHTML =
        '<div style="text-align:center"><div style="font-size:clamp(2rem,4vw,3.5rem);font-weight:900;letter-spacing:-0.04em;line-height:1;color:#0A0A08">' + recentSold.length + '</div><div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.2em;color:#9A958C;margin-top:8px;font-weight:600">K\u00fcrzlich vermittelt</div></div>' +
        '<div style="text-align:center"><div style="font-size:clamp(1.8rem,3.5vw,2.8rem);font-weight:900;letter-spacing:-0.04em;line-height:1;color:#0A0A08">' + volStr + '</div><div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.2em;color:#9A958C;margin-top:8px;font-weight:600">Volumen</div></div>';
    }

    soldContainer.innerHTML = recentSold.slice(0, 3).map(p => soldCardHome(p)).join('');
  }

  /* ─── Sold Card (Homepage Light Theme) ─── */
  function soldCardHome(p) {
    const img = p.images[0] || '';
    const soldDate = p.sold_at ? fmtSoldDate(p.sold_at) : '';
    const price = fmtSoldPrice(p);
    const broker = p.broker_name || '';
    const brokerTitle = p.broker_title || 'Immobilienmakler/in';
    const initials = broker ? broker.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase() : '?';
    return '<div style="position:relative;border-radius:20px;overflow:hidden;background:#fff;border:1px solid #F0ECE6;transition:transform 0.6s cubic-bezier(0.22,1,0.36,1);box-shadow:0 2px 12px rgba(0,0,0,0.04)" onmouseover="this.style.transform=\'translateY(-6px)\';this.style.boxShadow=\'0 12px 32px rgba(0,0,0,0.08)\'" onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'0 2px 12px rgba(0,0,0,0.04)\'">' +
      '<div style="position:relative;height:260px;overflow:hidden">' +
        (img ? '<img src="' + esc(img) + '" alt="' + esc(p.title) + '" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover" />' : '<div style="width:100%;height:100%;background:#F0ECE6"></div>') +
        '<div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.6) 0%,rgba(0,0,0,0.05) 50%,transparent 100%)"></div>' +
        '<div style="position:absolute;top:16px;left:16px;z-index:2;display:flex;align-items:center;gap:6px;padding:6px 14px;border-radius:100px;font-size:0.65rem;font-weight:800;text-transform:uppercase;letter-spacing:0.15em;color:#fff;background:#D4743B"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Verkauft</div>' +
        (soldDate ? '<div style="position:absolute;top:16px;right:16px;z-index:2;padding:5px 12px;border-radius:100px;font-size:0.65rem;font-weight:600;color:rgba(255,255,255,0.85);background:rgba(0,0,0,0.4);backdrop-filter:blur(12px)">' + soldDate + '</div>' : '') +
        '<div style="position:absolute;bottom:16px;left:16px;right:16px;z-index:2;font-size:1.25rem;font-weight:700;color:#fff;letter-spacing:-0.02em">' + esc(p.title) + '</div>' +
      '</div>' +
      '<div style="padding:20px">' +
        (price ? '<div style="font-size:1.1rem;font-weight:800;color:#0A0A08;margin-bottom:12px;letter-spacing:-0.02em">' + price + '</div>' : '') +
        '<div style="font-size:0.75rem;color:#9A958C;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:14px;display:flex;align-items:center;gap:6px"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>' + esc(p.address) + (p.city ? ', ' + esc(p.city) : '') + '</div>' +
        '<div style="display:flex;gap:16px;margin-bottom:16px">' +
          (p.area > 0 ? '<span style="font-size:0.75rem;color:#9A958C">' + p.area + ' m²</span>' : '') +
          (p.rooms > 0 ? '<span style="font-size:0.75rem;color:#9A958C">' + p.rooms + ' Zimmer</span>' : '') +
          (p.object_type ? '<span style="font-size:0.75rem;color:#9A958C">' + esc(p.object_type) + '</span>' : '') +
        '</div>' +
        (broker ? '<div style="display:flex;align-items:center;gap:10px;padding-top:16px;border-top:1px solid #F0ECE6"><div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#D4743B,#E8934A);display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:800;color:#fff;flex-shrink:0">' + initials + '</div><div><div style="font-size:0.8rem;font-weight:600;color:#0A0A08">' + esc(broker) + '</div><div style="font-size:0.65rem;color:#9A958C">' + esc(brokerTitle) + '</div></div></div>' : '') +
      '</div>' +
    '</div>';
  }

  /* ─── Format Sold Date ─── */
  function fmtSoldDate(dateStr) {
    try {
      const d = new Date(dateStr);
      const months = ['Jän.','Feb.','März','Apr.','Mai','Juni','Juli','Aug.','Sep.','Okt.','Nov.','Dez.'];
      return months[d.getMonth()] + ' ' + d.getFullYear();
    } catch(e) { return ''; }
  }

  /* ─── Format Sold Price ─── */
  function fmtSoldPrice(p) {
    const price = parseFloat(p.purchase_price || p.rental_price || 0);
    if (!price) return '';
    return price >= 1e6
      ? 'EUR ' + (price / 1e6).toFixed(2).replace('.', ',') + ' Mio.'
      : 'EUR ' + fmt(price);
  }

  /* ─── Featured Card HTML ─── */
  function featuredCard(p, idx) {
    const h = idx === 0 ? 560 : 380;
    const img = p.images[0] || '';
    const price = fmtPrice(p.price, p.isNewbuild, (p.marketing_type || '').toLowerCase() === 'miete');
    const titleSize = idx === 0 ? 'text-3xl md:text-4xl' : 'text-xl md:text-2xl';
    return `
      <a href="/objekt.html?id=${p.id}" class="cursor-pointer group relative overflow-hidden rounded-3xl block" style="height:${h}px;background:#fff">
        <img src="${esc(img)}" alt="${esc(p.title)}" loading="${idx === 0 ? 'eager' : 'lazy'}" decoding="async" fetchpriority="${idx === 0 ? 'high' : 'auto'}" class="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-105" />
        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,0.8) 0%,rgba(0,0,0,0.1) 50%,transparent 100%)"></div>
        <div class="absolute top-5 left-5 flex gap-2">
          <span class="px-4 py-2 rounded-full text-xs font-bold tracking-widest uppercase text-white" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(12px)">${esc(p.type)}</span>
          ${p.units_total ? `<span class="px-4 py-2 rounded-full text-xs font-bold text-white" style="background:#D4743B">${p.units_free || p.units_total} Einheiten frei</span>` : ''}
        </div>
        <div class="absolute bottom-0 left-0 right-0 p-6 md:p-8">
          <div class="flex items-center gap-2 mb-2">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span class="text-xs font-medium uppercase tracking-wider" style="color:rgba(255,255,255,0.6)">${esc(p.address)}, ${esc(p.city)}</span>
          </div>
          <h3 class="${titleSize} font-bold tracking-tight text-white mb-2">${esc(p.title)}</h3>
          <div class="flex items-center gap-6">
            <span class="text-xl font-bold text-white">${price}</span>
            <div class="flex gap-4">
              ${p.area > 0 ? `<span class="text-xs text-white/60">${p.area} m²</span>` : ''}
              ${p.rooms > 0 ? `<span class="text-xs text-white/60">${p.rooms} Zi.</span>` : ''}
            </div>
          </div>
        </div>
      </a>`;
  }

  /* ─── Regular Card HTML ─── */
  function propertyCard(p) {
    const img = p.images[0] || '';
    const price = fmtPrice(p.price, p.isNewbuild, (p.marketing_type || '').toLowerCase() === 'miete');
    return `
      <a href="/objekt.html?id=${p.id}" class="hover-lift hover-glow cursor-pointer rounded-2xl overflow-hidden block" style="background:#fff;border:1px solid #F0ECE6">
        <div class="card-img relative">
          <img src="${esc(img)}" alt="${esc(p.title)}" loading="lazy" decoding="async" class="w-full h-full object-cover" />
          <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,0.5) 0%,transparent 50%)"></div>
          <div class="absolute top-4 left-4 flex gap-2">
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold tracking-wider uppercase text-white" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(12px)">${esc(p.type)}</span>
            ${p.units_total ? `<span class="px-3 py-1.5 rounded-full text-xs font-semibold text-white" style="background:#D4743B">${p.units_free || p.units_total} Einheiten frei</span>` : ''}
          </div>
          <div class="absolute bottom-4 left-4 right-4">
            <div class="text-white text-2xl font-bold tracking-tight">${price}</div>
          </div>
        </div>
        <div class="p-6">
          <div class="flex items-center gap-2 mb-2">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#9A958C" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span class="text-xs font-medium uppercase tracking-wider" style="color:#9A958C">${esc(p.city)} | ${esc(p.city)}</span>
          </div>
          <h3 class="text-lg font-bold tracking-tight mb-1" style="color:#0A0A08">${esc(p.title)}</h3>
          <p class="text-sm mb-4" style="color:#5A564E">${esc(p.description ? p.description.substring(0, 80).replace(/\s+\S*$/, '') + '…' : p.address + ', ' + p.city)}</p>
          <div class="flex items-center gap-5 pt-4" style="border-top:1px solid #F0ECE6">
            ${p.area > 0 ? `<span class="flex items-center gap-1.5 text-xs font-medium" style="color:#9A958C"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg> ${p.area} m²</span>` : ''}
            ${p.rooms > 0 ? `<span class="flex items-center gap-1.5 text-xs font-medium" style="color:#9A958C"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg> ${p.rooms} Zimmer</span>` : ''}
          </div>
        </div>
      </a>`;
  }
})();
