/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Immobilien Listings Page
   ═══════════════════════════════════════════════════════════════ */

(async function() {
  const grid = document.getElementById('property-grid');
  const subtitle = document.getElementById('listing-subtitle');
  const countEl = document.getElementById('result-count');
  if (!grid) return;

  const raw = await fetchProperties();
  const props = raw.map(mapProperty);

  // Filter-State: kann durch URL-Params (von der Startseite) vor-belegt sein.
  const urlParams = new URLSearchParams(window.location.search);
  let activeCat = (urlParams.get('deal') === 'miete') ? 'miete' : 'alle';
  let activeType = urlParams.get('type') || 'alle';
  let activeRegion = urlParams.get('region') || '';
  let activePriceMax = parseInt(urlParams.get('price_max') || '0', 10) || 0;

  // Region-Mapping: aus dem Hero-Filter kommen normalisierte Werte; mappen
  // wir auf Strings, die in p.city/p.zip vorkommen.
  const REGION_MATCH = {
    'salzburg':    p => /salzburg/i.test(p.city || '') && /^50/.test(String(p.zip || '')),
    'flachgau':    p => /^(50|51)/.test(String(p.zip || '')) && !/^5020/.test(String(p.zip || '')),
    'innviertel':  p => /^(49|52)/.test(String(p.zip || '')),
    'mondseeland': p => /mondsee|st\.? lorenz|tiefgraben|oberhofen|zell am moos/i.test(p.city || ''),
  };

  function render() {
    const filtered = props.filter(p => {
      // Listings page should only show currently active objects.
      if (p.realty_status === 'verkauft' || p.realty_status === 'inaktiv') return false;
      // Filter nach Vermarktungsart (marketing_type). Kauf/Miete — 'alle' zeigt beide.
      if (activeCat !== 'alle') {
        const mt = (p.marketing_type || 'kauf').toLowerCase();
        if (activeCat !== mt) return false;
      }
      if (activeType !== 'alle') {
        const t = (p.type || '').toLowerCase();
        const cat = (p.property_category || '').toLowerCase();
        // Engmaschiger: Neubau zeigt NUR Master-Neubauprojekte (keine Tops).
        // Tops haben einen parent_id; das Master selber hat type=Neubauprojekt
        // oder property_category=newbuild bei Single-Listings.
        if (activeType === 'haus' && !t.includes('haus') && !t.includes('einfamilien') && !t.includes('reihen') && cat !== 'house') return false;
        if (activeType === 'wohnung' && !t.includes('wohnung') && !t.includes('zimmer') && cat !== 'apartment') return false;
        if (activeType === 'neubau' && !t.includes('neubau') && cat !== 'newbuild') return false;
        if (activeType === 'grundst' && !t.includes('grund') && cat !== 'land') return false;
        if (activeType === 'gewerbe' && !t.includes('gewerbe') && !t.includes('büro') && !t.includes('lokal')) return false;
      }
      if (activeRegion && REGION_MATCH[activeRegion] && !REGION_MATCH[activeRegion](p)) return false;
      if (activePriceMax > 0 && p.price > activePriceMax) return false;
      return true;
    });

    if (subtitle) subtitle.textContent = `${filtered.length} ausgewählte Immobilien in Salzburg und Oberösterreich.`;
    if (countEl) countEl.textContent = `${filtered.length} Ergebnisse`;

    if (!filtered.length) {
      grid.innerHTML = '<p class="text-sm col-span-3" style="color:#9A958C">Keine Immobilien für diesen Filter gefunden.</p>';
      return;
    }

    grid.innerHTML = filtered.map(p => card(p)).join('');
  }

  function card(p) {
    const img = p.images[0] || '';
    const price = fmtPrice(p.price, p.isNewbuild, (p.marketing_type || '').toLowerCase() === 'miete');
    return `
      <a href="/objekt.html?id=${p.id}" class="hover-lift hover-glow cursor-pointer rounded-2xl overflow-hidden block" style="background:#fff;border:1px solid #F0ECE6">
        <div class="card-img relative">
          ${img ? `<img src="${esc(img)}" alt="${esc(p.title)}" loading="lazy" decoding="async" class="w-full h-full object-cover" />` : `<div class="w-full h-full flex items-center justify-center" style="background:#F0ECE6"><span class="text-sm" style="color:#9A958C">Kein Bild</span></div>`}
          <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,0.5) 0%,transparent 50%)"></div>
          <div class="absolute top-4 left-4 flex gap-2 flex-wrap">
            ${p.badge ? `<span class="px-3 py-1.5 rounded-full text-xs font-bold tracking-wider uppercase text-white" style="background:#D4743B;box-shadow:0 4px 12px rgba(212,116,59,0.4)">${esc(p.badge)}</span>` : ''}
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold tracking-wider uppercase text-white" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(12px)">${esc(p.type)}</span>
            ${p.units_total ? `<span class="px-3 py-1.5 rounded-full text-xs font-semibold text-white" style="background:#D4743B">${p.units_free || p.units_total} Einheiten frei</span>` : ''}
          </div>
          <button class="absolute top-4 right-4 w-10 h-10 rounded-full flex items-center justify-center transition-all hover:scale-110" style="background:rgba(255,255,255,0.15);backdrop-filter:blur(8px)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          </button>
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
          <p class="text-sm mb-4" style="color:#5A564E">${esc(p.description ? p.description.substring(0, 80).replace(/\s+\S*$/, '') + '…' : (p.address || '') + ', ' + (p.city || ''))}</p>
          <div class="flex items-center gap-5 pt-4" style="border-top:1px solid #F0ECE6">
            ${p.area > 0 ? `<span class="flex items-center gap-1.5 text-xs font-medium" style="color:#9A958C"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg> ${p.area} m²</span>` : ''}
            ${p.rooms > 0 ? `<span class="flex items-center gap-1.5 text-xs font-medium" style="color:#9A958C"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg> ${p.rooms} Zimmer</span>` : ''}
          </div>
        </div>
      </a>`;
  }

  /* Filter click handlers */
  function syncCatButtons() {
    document.querySelectorAll('.filter-cat').forEach(b => {
      if (b.dataset.cat === activeCat) { b.style.background = '#D4743B'; b.style.color = '#fff'; b.style.border = 'none'; }
      else { b.style.background = 'transparent'; b.style.color = '#5A564E'; b.style.border = '1.5px solid #E5E0D8'; }
    });
  }
  function syncTypeButtons() {
    document.querySelectorAll('.filter-type').forEach(b => {
      if (b.dataset.type === activeType) { b.style.background = '#0A0A08'; b.style.color = '#fff'; b.style.border = 'none'; }
      else { b.style.background = 'transparent'; b.style.color = '#5A564E'; b.style.border = '1.5px solid #E5E0D8'; }
    });
  }
  document.querySelectorAll('.filter-cat').forEach(btn => {
    btn.addEventListener('click', () => { activeCat = btn.dataset.cat; syncCatButtons(); render(); });
  });
  document.querySelectorAll('.filter-type').forEach(btn => {
    btn.addEventListener('click', () => { activeType = btn.dataset.type; syncTypeButtons(); render(); });
  });

  // Filter-Bar gemäß URL-Params initial setzen, sodass Hero-Filter sichtbar greift.
  syncCatButtons();
  syncTypeButtons();
  render();
})();
