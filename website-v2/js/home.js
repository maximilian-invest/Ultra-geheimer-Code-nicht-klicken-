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

  const container = document.getElementById('featured-properties');
  const countLabel = document.getElementById('property-count-label');
  if (!container) return;

  const raw = await fetchProperties();
  if (!raw.length) { container.innerHTML = '<p class="text-sm" style="color:#9A958C">Keine Immobilien gefunden.</p>'; return; }

  const props = raw.map(mapProperty);
  if (countLabel) countLabel.textContent = `Alle ${props.length} Objekte`;

  /* ─── Featured Cards (first 3 large, rest as regular cards) ─── */
  const featured = props.slice(0, Math.min(3, props.length));
  const rest = props.slice(Math.min(3, props.length));

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

  /* ─── Featured Card HTML ─── */
  function featuredCard(p, idx) {
    const h = idx === 0 ? 560 : 380;
    const img = p.images[0] || '';
    const price = fmtPrice(p.price, p.isNewbuild);
    const titleSize = idx === 0 ? 'text-3xl md:text-4xl' : 'text-xl md:text-2xl';
    return `
      <a href="/objekt.html?id=${p.id}" class="cursor-pointer group relative overflow-hidden rounded-3xl block" style="height:${h}px;background:#fff">
        <img src="${esc(img)}" alt="${esc(p.title)}" class="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-105" />
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
    const price = fmtPrice(p.price, p.isNewbuild);
    return `
      <a href="/objekt.html?id=${p.id}" class="hover-lift hover-glow cursor-pointer rounded-2xl overflow-hidden block" style="background:#fff;border:1px solid #F0ECE6">
        <div class="card-img relative">
          <img src="${esc(img)}" alt="${esc(p.title)}" class="w-full h-full object-cover" />
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
