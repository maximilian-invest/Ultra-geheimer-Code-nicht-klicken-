/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Property Detail Page JS
   Lightbox, Gallery, Downloads, Units Table, Objektdaten
   ═══════════════════════════════════════════════════════════════ */

(async function() {
  const params = new URLSearchParams(window.location.search);
  const id = params.get('id');
  if (!id) return;

  const p = await fetchProperty(id);
  if (!p) { document.getElementById('detail-content').innerHTML = '<p class="text-lg py-20 text-center" style="color:#9A958C">Immobilie nicht gefunden.</p>'; return; }

  const mapped = mapProperty(p);
  const price = fmtPrice(mapped.price, mapped.isNewbuild);

  /* ─── Title ─── */
  document.title = `${mapped.title} | SR-Homes`;
  document.getElementById('prop-type').textContent = mapped.type;
  document.getElementById('prop-ref').textContent = p.ref_id ? `Ref: ${p.ref_id}` : '';
  document.getElementById('prop-title').textContent = mapped.title;
  document.getElementById('prop-subtitle').textContent = p.description ? p.description.substring(0, 80).replace(/\s+\S*$/, '') + '…' : '';
  document.getElementById('prop-address').textContent = `${p.address || ''}, ${p.zip || ''} ${p.city || ''}`;
  document.getElementById('prop-price').textContent = price;

  /* ─── Gallery ─── */
  const gallery = document.getElementById('gallery');
  const imgs = mapped.images;
  if (imgs.length > 0) {
    let gh = `<div class="grid grid-cols-1 md:grid-cols-3 gap-3 rounded-2xl overflow-hidden" style="max-height:500px">`;
    imgs.slice(0, 3).forEach((src, i) => {
      const cls = i === 0 ? 'md:col-span-2 md:row-span-2' : '';
      gh += `<div class="relative overflow-hidden cursor-pointer gallery-img ${cls}" style="min-height:${i===0?'400px':'200px'}" data-idx="${i}">
        <img src="${esc(src)}" alt="" class="w-full h-full object-cover hover-scale" />
        ${i === 0 && imgs.length > 3 ? `<div class="absolute bottom-4 right-4 px-3 py-1.5 rounded-full text-xs font-semibold text-white" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(8px)">${Math.min(3, imgs.length)} / ${imgs.length}</div>` : ''}
      </div>`;
    });
    gh += '</div>';
    gallery.innerHTML = gh;
  }

  /* ─── Stats Grid ─── */
  const statsEl = document.getElementById('stats-grid');
  const statItems = [];
  if (p.area_living) statItems.push({ icon: 'area', val: `${p.area_living} m²`, label: 'Wohnfläche' });
  if (p.rooms) statItems.push({ icon: 'rooms', val: p.rooms, label: 'Zimmer' });
  if (p.bathrooms) statItems.push({ icon: 'bath', val: p.bathrooms, label: 'Badezimmer' });
  if (p.features?.includes('Garten')) statItems.push({ icon: 'garden', val: 'Ja', label: 'Garten' });
  if (p.features?.includes('Terrasse')) statItems.push({ icon: 'terrace', val: 'Ja', label: 'Terrasse' });
  if (p.features?.includes('Balkon')) statItems.push({ icon: 'balcony', val: 'Ja', label: 'Balkon' });
  if (p.year_built) statItems.push({ icon: 'year', val: p.year_built, label: 'Baujahr' });

  const svgIcons = {
    area: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
    rooms: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>',
    bath: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16a1 1 0 0 1 1 1v3a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-3a1 1 0 0 1 1-1z"/><path d="M6 12V5a2 2 0 0 1 2-2h3v2.25"/><line x1="8" y1="20" x2="7" y2="22"/><line x1="16" y1="20" x2="17" y2="22"/></svg>',
    garden: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V10"/><path d="M6 22V16c0-3.3 2.7-6 6-6s6 2.7 6 6v6"/></svg>',
    terrace: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="12" width="20" height="2" rx="1"/><path d="M4 14v8"/><path d="M20 14v8"/><path d="M12 14v8"/><path d="M2 22h20"/></svg>',
    balcony: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="10" width="18" height="2" rx="1"/><path d="M5 12v8"/><path d="M19 12v8"/><path d="M12 12v8"/><path d="M3 20h18"/></svg>',
    year: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
  };

  if (statItems.length && statsEl) {
    statsEl.innerHTML = `<div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6 rounded-2xl" style="background:#F0ECE6">
      ${statItems.slice(0, 4).map(s => `<div class="text-center"><div class="flex justify-center mb-2">${svgIcons[s.icon] || ''}</div><div class="text-lg font-bold" style="color:#0A0A08">${s.val}</div><div class="text-xs" style="color:#9A958C">${s.label}</div></div>`).join('')}
    </div>`;
  }

  /* ─── Description ─── */
  const descEl = document.getElementById('description');
  if (descEl && p.description) {
    const tabs = [];
    tabs.push({ id: 'desc', label: 'Beschreibung', content: esc(p.description).replace(/\n/g, '<br>') });
    if (p.location_description) tabs.push({ id: 'lage', label: 'Lage', content: esc(p.location_description).replace(/\n/g, '<br>') });
    if (p.equipment_description) tabs.push({ id: 'ausstattung', label: 'Ausstattung', content: esc(p.equipment_description).replace(/\n/g, '<br>') });
    if (p.other_description) tabs.push({ id: 'sonstiges', label: 'Sonstiges', content: esc(p.other_description).replace(/\n/g, '<br>') });

    // Historie tab
    let historyData = p.property_history;
    if (typeof historyData === 'string') { try { historyData = JSON.parse(historyData); } catch(e) { historyData = null; } }
    if (historyData && historyData.length) {
      let tlHtml = '<div class="relative py-4">';
      tlHtml += '<div class="absolute left-[28px] top-0 bottom-0 w-[2px]" style="background:linear-gradient(to bottom, #D4743B, #D4743B22)"></div>';
      historyData.forEach((h, i) => {
        const delay = i * 150;
        tlHtml += '<div class="relative flex gap-6 mb-8 group" style="opacity:0;animation:tlFadeIn 0.6s ease ' + delay + 'ms forwards">' +
          '<div class="flex-shrink-0 relative z-10" style="width:58px">' +
            '<div class="w-[58px] h-[58px] rounded-2xl flex items-center justify-center text-sm font-black tracking-tight text-white shadow-lg" style="background:linear-gradient(135deg, #D4743B, #B85A2A)">' + esc(h.year) + '</div>' +
          '</div>' +
          '<div class="flex-1 pt-1">' +
            '<div class="text-base font-bold tracking-tight" style="color:#0A0A08">' + esc(h.title) + '</div>' +
            (h.description ? '<p class="text-sm leading-relaxed mt-2" style="color:#5A564E">' + esc(h.description).replace(/\n/g, '<br>') + '</p>' : '') +
          '</div>' +
        '</div>';
      });
      tlHtml += '</div><style>@keyframes tlFadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}</style>';
      tabs.push({ id: 'historie', label: 'Historie', content: tlHtml, isHtml: true });
    }

    let dh = '';
    dh += '<div class="flex gap-1 mb-6" style="border-bottom:2px solid #F0ECE6">';
    tabs.forEach((t, i) => {
      dh += `<button onclick="document.querySelectorAll('.desc-tab-content').forEach(el=>el.style.display='none');document.getElementById('tab-${t.id}').style.display='';document.querySelectorAll('.desc-tab-btn').forEach(b=>{b.style.borderColor='transparent';b.style.color='#9A958C'});this.style.borderColor='#D4743B';this.style.color='#0A0A08'" class="desc-tab-btn px-5 py-3 text-sm font-semibold transition-colors" style="border-bottom:2px solid ${i === 0 ? '#D4743B' : 'transparent'};margin-bottom:-2px;color:${i === 0 ? '#0A0A08' : '#9A958C'};background:none;cursor:pointer">${t.label}</button>`;
    });
    dh += '</div>';
    tabs.forEach((t, i) => {
      dh += `<div id="tab-${t.id}" class="desc-tab-content" style="display:${i === 0 ? 'block' : 'none'}">${t.isHtml ? t.content : `<div class="text-sm leading-relaxed" style="color:#5A564E">${t.content}</div>`}</div>`;
    });
    descEl.innerHTML = dh;
  }

  /* ─── Features ─── */
  const featEl = document.getElementById('features');
  if (featEl && p.features?.length) {
    featEl.innerHTML = `<h2 class="text-xl font-bold mb-4" style="color:#0A0A08">Ausstattung</h2>
      <div class="flex flex-wrap gap-2">${p.features.map(f => `<span class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium" style="background:#F0ECE6;color:#0A0A08"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>${esc(f)}</span>`).join('')}</div>`;
  }

  /* ─── Details Table ─── */
  const detailsEl = document.getElementById('details-table');
  if (detailsEl) {
    const rows = [];
    if (p.type) rows.push(['Objekttyp', p.type]);
    if (p.area_living) rows.push(['Wohnfläche', `${p.area_living} m²`]);
    if (p.total_area && p.total_area != p.area_living) rows.push(['Gesamtfläche', `${p.total_area} m²`]);
    if (p.free_area) rows.push(['Grundstücksfläche', `${p.free_area} m²`]);
    if (p.rooms) rows.push(['Zimmer', p.rooms]);
    if (p.bathrooms) rows.push(['Badezimmer', p.bathrooms]);
    if (p.area_balcony) rows.push(['Balkonfläche', `${p.area_balcony} m²`]);
    if (p.area_terrace) rows.push(['Terrasse', `${p.area_terrace} m²`]);
    if (p.area_garden) rows.push(['Gartenfläche', `${p.area_garden} m²`]);
    if (p.year_built) rows.push(['Baujahr', p.year_built]);
    if (p.year_renovated) rows.push(['Renoviert', p.year_renovated]);
    if (p.heating) rows.push(['Heizung', p.heating]);
    if (p.energy_hwb) rows.push(['HWB', `${p.energy_hwb} kWh/m²a`]);
    if (p.energy_fgee) rows.push(['fGEE', p.energy_fgee]);
    if (p.energy_class) rows.push(['Energieklasse', p.energy_class]);
    if (p.energy_certificate && !p.energy_hwb) rows.push(['Energieausweis', p.energy_certificate]);
    if (p.heating_demand_value && !p.energy_hwb) rows.push(['Heizwärmebedarf', `${p.heating_demand_value} kWh/m²a`]);
    if (p.operating_costs) rows.push(['Betriebskosten', `€ ${Number(p.operating_costs).toLocaleString('de-AT')}`]);
    if (p.condition_note) rows.push(['Zustand', p.condition_note]);
    if (p.available_from) rows.push(['Verfügbar ab', p.available_from]);
    if (p.city) rows.push(['Region', p.city]);
    if (rows.length) {
      detailsEl.innerHTML = `<h2 class="text-xl font-bold mb-4" style="color:#0A0A08">Details</h2>
        <div class="divide-y" style="border-color:#F0ECE6">${rows.map(([k, v]) => `<div class="flex justify-between py-3"><span class="text-sm" style="color:#9A958C">${k}</span><span class="text-sm font-medium" style="color:#0A0A08">${v}</span></div>`).join('')}</div>`;
    }
  }

  /* ─── Downloads ─── */
  const dlEl = document.getElementById('downloads');
  if (dlEl && p.downloads?.length) {
    dlEl.innerHTML = `<h2 class="text-xl font-bold mb-4" style="color:#0A0A08">Downloads</h2>
      <div class="space-y-3">${p.downloads.map(d => `<a href="${esc(d.url)}" target="_blank" class="flex items-center justify-between p-4 rounded-xl hover-lift" style="background:#F0ECE6">
        <div class="flex items-center gap-3">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <div><div class="text-sm font-semibold" style="color:#0A0A08">${esc(d.label || d.filename)}</div>${d.file_size ? `<div class="text-xs" style="color:#9A958C">${d.file_size}</div>` : ''}</div>
        </div>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </a>`).join('')}</div>`;
  }

  /* ─── Units Table (Neubauprojekte) ─── */
  const unitsEl = document.getElementById('units-section');
  if (unitsEl && p.units?.length) {
    const s = u => (u.status || '').toLowerCase();
    const available = p.units.filter(u => ['available','verfügbar','frei','free'].includes(s(u)));
    const sold = p.units.filter(u => ['sold','verkauft'].includes(s(u)));
    const reserved = p.units.filter(u => ['reserved','reserviert'].includes(s(u)));

    let uh = `<h2 class="text-xl font-bold mb-4" style="color:#0A0A08">Verfügbare Einheiten</h2>`;
    uh += `<div class="p-4 rounded-xl mb-6" style="background:#F0ECE6">
      <span class="text-3xl font-black" style="color:#D4743B">${available.length + reserved.length}</span>
      <span class="text-sm ml-2" style="color:#9A958C">von ${p.units.length} frei</span>
      <div class="w-full h-2 rounded-full mt-3" style="background:#E5E0D8">
        <div class="h-full rounded-full" style="width:${((available.length + reserved.length) / p.units.length * 100)}%;background:#D4743B"></div>
      </div>
    </div>`;

    if (available.length + reserved.length > 0) {
      uh += `<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr style="border-bottom:2px solid #E5E0D8">
        <th class="text-left py-3 font-semibold" style="color:#9A958C">Einheit</th>
        <th class="text-left py-3 font-semibold" style="color:#9A958C">Typ</th>
        <th class="text-left py-3 font-semibold" style="color:#9A958C">Zimmer</th>
        <th class="text-left py-3 font-semibold" style="color:#9A958C">Fläche</th>
        <th class="text-left py-3 font-semibold" style="color:#9A958C">Preis</th>
        <th class="text-left py-3 font-semibold" style="color:#9A958C">Status</th>
      </tr></thead><tbody>`;
      [...available, ...reserved].forEach(u => {
        const st = (u.status || '').toLowerCase();
        const badge = ['reserved','reserviert'].includes(st) ? 'reserved' : 'available';
        const label = badge === 'reserved' ? 'Reserviert' : 'Verfügbar';
        uh += `<tr class="units-row" style="border-bottom:1px solid #F0ECE6">
          <td class="py-3 font-semibold" style="color:#0A0A08">${esc(u.unit_number || '')}</td>
          <td class="py-3" style="color:#5A564E">${esc(u.unit_type || '')}</td>
          <td class="py-3" style="color:#5A564E">${u.rooms ? parseFloat(u.rooms) : '-'}</td>
          <td class="py-3" style="color:#5A564E">${u.area_m2 ? parseFloat(u.area_m2) + ' m²' : '-'}</td>
          <td class="py-3 font-semibold" style="color:#0A0A08">${u.price ? 'EUR ' + fmt(parseFloat(u.price)) : '-'}</td>
          <td class="py-3"><span class="unit-badge ${badge}">${label}</span></td>
        </tr>`;
      });
      uh += '</tbody></table></div>';
    }

    if (sold.length) {
      uh += `<div class="mt-8 opacity-50"><h3 class="text-sm font-bold mb-3" style="color:#9A958C">Verkaufte Einheiten</h3>
        <div class="space-y-2">${sold.map(u => `<div class="flex justify-between py-2 text-sm" style="border-bottom:1px solid #F0ECE6;color:#9A958C">
          <span>${esc(u.unit_number || '')}</span><span>${u.area_m2 ? u.area_m2 + ' m²' : ''}</span><span class="unit-badge sold">Verkauft</span>
        </div>`).join('')}</div></div>`;
    }

    unitsEl.innerHTML = uh;
  }

  /* ─── Related Properties ─── */
  const relatedEl = document.getElementById('related');
  if (relatedEl) {
    const all = await fetchProperties();
    const others = all
      .filter(o =>
        o.id !== parseInt(id) &&
        o.realty_status !== 'verkauft' &&
        o.realty_status !== 'inaktiv'
      )
      .slice(0, 3)
      .map(mapProperty);
    if (others.length) {
      relatedEl.innerHTML = `<h2 class="font-display text-2xl md:text-3xl font-bold mb-8" style="color:#0A0A08">Weitere Objekte</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">${others.map(o => {
          const img = o.images[0] || '';
          const pr = fmtPrice(o.price, o.isNewbuild);
          return `<a href="/objekt.html?id=${o.id}" class="hover-lift hover-glow rounded-2xl overflow-hidden block" style="background:#fff;border:1px solid #F0ECE6">
            <div class="card-img relative">
              ${img ? `<img src="${esc(img)}" alt="${esc(o.title)}" class="w-full h-full object-cover" />` : `<div class="w-full h-full flex items-center justify-center" style="background:#F0ECE6;min-height:200px"><span class="text-sm" style="color:#9A958C">Kein Bild</span></div>`}
              <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,0.5) 0%,transparent 50%)"></div>
              <div class="absolute top-4 left-4"><span class="px-3 py-1.5 rounded-full text-xs font-semibold uppercase text-white" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(12px)">${esc(o.type)}</span></div>
              <div class="absolute bottom-4 left-4"><div class="text-white text-xl font-bold">${pr}</div></div>
            </div>
            <div class="p-5">
              <h3 class="text-base font-bold tracking-tight" style="color:#0A0A08">${esc(o.title)}</h3>
              <p class="text-xs mt-1" style="color:#9A958C">${esc(o.address)}, ${esc(o.city)}</p>
            </div>
          </a>`;
        }).join('')}</div>`;
    }
  }

  /* ─── LIGHTBOX ─── */
  if (imgs.length > 0) {
    const overlay = document.createElement('div');
    overlay.className = 'lightbox-overlay';
    overlay.innerHTML = `
      <button class="lightbox-btn lightbox-prev">&lsaquo;</button>
      <img src="" alt="" />
      <button class="lightbox-btn lightbox-next">&rsaquo;</button>
      <button class="lightbox-btn lightbox-close">&times;</button>
      <div class="lightbox-counter"></div>`;
    document.body.appendChild(overlay);

    const lbImg = overlay.querySelector('img');
    const lbCounter = overlay.querySelector('.lightbox-counter');
    let currentIdx = 0;

    function showImg(idx) {
      currentIdx = ((idx % imgs.length) + imgs.length) % imgs.length;
      lbImg.src = imgs[currentIdx];
      lbCounter.textContent = `${currentIdx + 1} / ${imgs.length}`;
    }

    function openLightbox(idx) {
      showImg(idx);
      overlay.classList.add('active');
    }

    function closeLightbox() { overlay.classList.remove('active'); }

    overlay.querySelector('.lightbox-prev').addEventListener('click', e => { e.stopPropagation(); showImg(currentIdx - 1); });
    overlay.querySelector('.lightbox-next').addEventListener('click', e => { e.stopPropagation(); showImg(currentIdx + 1); });
    overlay.querySelector('.lightbox-close').addEventListener('click', closeLightbox);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeLightbox(); });

    document.addEventListener('keydown', e => {
      if (!overlay.classList.contains('active')) return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowLeft') showImg(currentIdx - 1);
      if (e.key === 'ArrowRight') showImg(currentIdx + 1);
    });

    document.querySelectorAll('.gallery-img').forEach(el => {
      el.addEventListener('click', () => openLightbox(parseInt(el.dataset.idx) || 0));
    });
  }
})();
