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
  const isRental = (p.marketing_type || '').toLowerCase() === 'miete';
  const price = fmtPrice(mapped.price, mapped.isNewbuild, isRental);

  /* ─── Title ─── */
  document.title = `${mapped.title} | SR-Homes`;
  document.getElementById('prop-type').textContent = mapped.type;
  document.getElementById('prop-ref').textContent = p.ref_id ? `Ref: ${p.ref_id}` : '';
  document.getElementById('prop-title').textContent = mapped.title;
  document.getElementById('prop-subtitle').textContent = p.description ? p.description.substring(0, 80).replace(/\s+\S*$/, '') + '…' : '';
  document.getElementById('prop-address').textContent = `${p.address || ''}, ${p.zip || ''} ${p.city || ''}`;
  document.getElementById('prop-price').textContent = price;

  // Preis-Label in der Sidebar ("Kaufpreis" vs "Mietpreis")
  const priceLabelEl = document.getElementById('prop-price-label');
  if (priceLabelEl && isRental) priceLabelEl.textContent = 'Mietpreis';

  /* ─── Makler-Card dynamisch je Objekt ─── */
  const brokerName = p.broker_name || 'SR-Homes';
  const brokerTitle = p.broker_title || 'Immobilienmakler/in';
  const brokerEmail = p.broker_email || '';
  const brokerPhone = p.broker_phone || '';
  const brokerImage = p.broker_image || '';
  const initials = brokerName.split(/\s+/).filter(Boolean).map(w => w[0]).join('').substring(0, 2).toUpperCase();

  const nameEl = document.getElementById('broker-name');
  const titleEl = document.getElementById('broker-title');
  const emailEl = document.getElementById('broker-email');
  const phoneEl = document.getElementById('broker-phone');
  const emailRow = document.getElementById('broker-email-row');
  const phoneRow = document.getElementById('broker-phone-row');
  const avatarEl = document.getElementById('broker-avatar');

  if (nameEl) nameEl.textContent = brokerName;
  if (titleEl) titleEl.textContent = brokerTitle;
  if (emailEl) {
    emailEl.textContent = brokerEmail;
    if (emailRow) emailRow.style.display = brokerEmail ? '' : 'none';
  }
  if (phoneEl) {
    phoneEl.textContent = brokerPhone;
    if (phoneRow) phoneRow.style.display = brokerPhone ? '' : 'none';
  }
  if (avatarEl) {
    if (brokerImage) {
      avatarEl.innerHTML = `<img src="${esc(brokerImage)}" alt="${esc(brokerName)}" class="w-full h-full object-cover" />`;
    } else {
      avatarEl.textContent = initials || 'SR';
    }
  }

  /* ─── Betriebskosten: Gesamtsumme in der Sidebar + Aufschluesselung unten ─── */
  const costItems = [
    { key: 'operating_costs',       label: 'Betriebskosten' },
    { key: 'heating_costs',         label: 'Heizkosten' },
    { key: 'warm_water_costs',      label: 'Warmwasserkosten' },
    { key: 'cooling_costs',         label: 'Kuehlungskosten' },
    { key: 'maintenance_reserves',  label: 'Rücklage' },
    { key: 'admin_costs',           label: 'Verwaltungskosten' },
    { key: 'elevator_costs',        label: 'Aufzugskosten' },
    { key: 'parking_costs_monthly', label: 'Stellplatzkosten' },
    { key: 'other_costs',           label: 'Sonstige Kosten' },
  ];
  const filled = costItems
    .map(it => ({ ...it, val: parseFloat(p[it.key] || 0) }))
    .filter(it => it.val > 0);
  const sumSub = filled.reduce((s, it) => s + it.val, 0);
  const bkTotal = parseFloat(p.monthly_costs || 0) || sumSub;

  const bkTotalEl = document.getElementById('prop-bk-total');
  if (bkTotalEl && bkTotal > 0) {
    bkTotalEl.textContent = `zzgl. Betriebskosten: EUR ${fmt(bkTotal)} / Monat`;
  }

  const breakdownEl = document.getElementById('costs-breakdown');
  if (breakdownEl && filled.length) {
    breakdownEl.innerHTML = `
      <div class="mt-16">
        <h2 class="text-sm font-bold uppercase tracking-widest mb-6" style="color:#9A958C">Nebenkosten (mtl.)</h2>
        <div class="rounded-2xl overflow-hidden" style="background:#fff;border:1px solid #F0ECE6">
          ${filled.map((it, i) => `
            <div class="flex items-center justify-between px-6 py-4" style="${i < filled.length - 1 ? 'border-bottom:1px solid #F0ECE6' : ''}">
              <span class="text-sm" style="color:#5A564E">${esc(it.label)}</span>
              <span class="text-sm font-semibold tabular-nums" style="color:#0A0A08">EUR ${fmt(it.val)}</span>
            </div>`).join('')}
          ${parseFloat(p.monthly_costs || 0) > 0 && Math.abs(parseFloat(p.monthly_costs) - sumSub) > 1 ? `
            <div class="flex items-center justify-between px-6 py-4" style="background:#FAF8F5;border-top:1px solid #F0ECE6">
              <span class="text-sm font-bold" style="color:#0A0A08">Gesamt (mtl.)</span>
              <span class="text-sm font-bold tabular-nums" style="color:#0A0A08">EUR ${fmt(parseFloat(p.monthly_costs))}</span>
            </div>` : `
            <div class="flex items-center justify-between px-6 py-4" style="background:#FAF8F5;border-top:1px solid #F0ECE6">
              <span class="text-sm font-bold" style="color:#0A0A08">Gesamt (mtl.)</span>
              <span class="text-sm font-bold tabular-nums" style="color:#0A0A08">EUR ${fmt(sumSub)}</span>
            </div>`}
        </div>
      </div>`;
  }

  /* ─── Allgemeinräume (website-only, nur wenn gepflegt) ─── */
  const commonAreasEl = document.getElementById('common-areas');
  const commonAreasTxt = (p.common_areas || '').toString().trim();
  if (commonAreasEl && commonAreasTxt) {
    commonAreasEl.innerHTML = `
      <div class="mt-16">
        <h2 class="text-sm font-bold uppercase tracking-widest mb-6" style="color:#9A958C">Allgemeinräume</h2>
        <div class="rounded-2xl p-6" style="background:#fff;border:1px solid #F0ECE6;color:#0A0A08;line-height:1.7;font-size:0.95rem">
          ${commonAreasTxt.split(/\n\s*\n/).map(p => '<p class="mb-3 last:mb-0">' + esc(p.trim()).replace(/\n/g, '<br>') + '</p>').join('')}
        </div>
      </div>`;
  }

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
  const isNewbuildProj = (p.property_category === 'newbuild') || /Neubauprojekt/i.test(p.type || '');
  // Neubauprojekt: Wohnfläche + Zimmer als Range (falls vorhanden), sonst Einzelwert
  if (isNewbuildProj && p.area_range) {
    statItems.push({ icon: 'area', val: p.area_range, label: 'Wohnfläche' });
  } else if (p.area_living) {
    statItems.push({ icon: 'area', val: `${p.area_living} m²`, label: 'Wohnfläche' });
  }
  if (isNewbuildProj && p.rooms_range) {
    statItems.push({ icon: 'rooms', val: p.rooms_range, label: 'Zimmer' });
  } else if (p.rooms) {
    statItems.push({ icon: 'rooms', val: p.rooms, label: 'Zimmer' });
  }
  if (p.bathrooms) statItems.push({ icon: 'bath', val: p.bathrooms, label: 'Badezimmer' });
  // Balkon/Terrasse als Range bei Neubau
  if (isNewbuildProj && p.balcony_terrace_range) {
    statItems.push({ icon: 'balcony', val: p.balcony_terrace_range, label: 'Balkon/Terrasse' });
  } else {
    if (p.features?.includes('Garten')) statItems.push({ icon: 'garden', val: 'Ja', label: 'Garten' });
    if (p.features?.includes('Terrasse')) statItems.push({ icon: 'terrace', val: 'Ja', label: 'Terrasse' });
    if (p.features?.includes('Balkon')) statItems.push({ icon: 'balcony', val: 'Ja', label: 'Balkon' });
  }
  // Garten bei Neubau als Range
  if (isNewbuildProj && p.garden_range) {
    statItems.push({ icon: 'garden', val: p.garden_range, label: 'Garten' });
  }
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
    // Lucide-style thin-line icons (stroke-width 1.5)
    const svg = (path) => `<svg class="detail-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
    const ICONS = {
      home: svg('<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/>'),
      ruler: svg('<path d="M21 3 3 21"/><path d="M9 8l2 2"/><path d="M12 5l2 2"/><path d="M15 11l2 2"/><path d="M18 8l2 2"/><path d="M6 11l2 2"/><path d="M5 14l2 2"/><path d="M8 17l2 2"/><path d="M11 20l2 2"/>'),
      door: svg('<path d="M18 20V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14"/><path d="M2 20h20"/><circle cx="15" cy="12" r="0.5" fill="currentColor"/>'),
      drop: svg('<path d="M12 2v6"/><path d="M12 22a7 7 0 0 1-7-7c0-3 3-7 7-11 4 4 7 8 7 11a7 7 0 0 1-7 7z"/>'),
      layout: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="12" x2="21" y2="12"/>'),
      tree: svg('<path d="M17 14v5"/><path d="M7 14v5"/><path d="M12 2c3 0 5 2 5 5s-2 5-5 5-5-2-5-5 2-5 5-5z"/><path d="M12 12v10"/>'),
      box: svg('<path d="m21 16-9 5-9-5V8l9-5 9 5v8z"/><polyline points="3 8 12 13 21 8"/><line x1="12" y1="22" x2="12" y2="13"/>'),
      calendar: svg('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'),
      flag: svg('<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>'),
      hammer: svg('<path d="m15 12-8.5 8.5c-.83.83-2.17.83-3 0 0 0 0 0 0 0a2.12 2.12 0 0 1 0-3L12 9"/><path d="M17.64 15 22 10.64"/><path d="m20.91 11.7-1.25-1.25c-.6-.6-.93-1.4-.93-2.25v-.86L16.01 4.6a5.56 5.56 0 0 0-3.94-1.64H9l.92.82A6.18 6.18 0 0 1 12 8.4v1.56l2 2h2.47l2.26 1.91"/>'),
      check: svg('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'),
      key: svg('<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/>'),
      sofa: svg('<path d="M20 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v3"/><path d="M2 11v5a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H6v-2a2 2 0 0 0-4 0z"/><path d="M4 18v2"/><path d="M20 18v2"/>'),
      flame: svg('<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>'),
      thermometer: svg('<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>'),
      zap: svg('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
      euro: svg('<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12a7.9 7.9 0 0 0 7.8 8 7.7 7.7 0 0 0 5.2-2"/>'),
      mapPin: svg('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>'),
      layers: svg('<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>'),
      clock: svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
      sun: svg('<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>'),
    };

    const CONDITION_LABELS = {
      NEW_BUILT: 'Erstbezug', FIRST_TIME_USE: 'Erstbezug', USED: 'Gebraucht',
      RECONSTRUCTED: 'Saniert', CORE_REFURBISHED: 'Kernsaniert',
      RENOVATED: 'Renoviert', WELL_MAINTAINED: 'Gepflegt',
      NEEDS_RENOVATION: 'Renovierungsbedürftig', RAW: 'Rohbau',
      neuwertig: 'Neuwertig', gebraucht: 'Gebraucht', saniert: 'Saniert',
      renoviert: 'Renoviert', erstbezug: 'Erstbezug', abbruchreif: 'Abbruchreif',
    };
    const OWNERSHIP_LABELS = {
      eigentum: 'Eigentum', baurecht: 'Baurecht', pacht: 'Pacht', miete: 'Miete',
    };
    const FURNISHING_LABELS = {
      unfurnished: 'Unmöbliert', partially: 'Teilmöbliert', fully: 'Vollmöbliert',
      unmoebliert: 'Unmöbliert', teilmoebliert: 'Teilmöbliert',
      vollmoebliert: 'Vollmöbliert',
    };
    const CONSTRUCTION_TYPE_LABELS = {
      massiv: 'Massivbauweise', holz: 'Holzbauweise', fertigteil: 'Fertigteilbau',
      mischbau: 'Mischbauweise', sonstige: 'Sonstige',
    };
    const translate = (val, map) => val && map[String(val).toLowerCase()] ? map[String(val).toLowerCase()] : val;

    const rows = [];  // [icon, label, value]
    const isNewbuild = (p.property_category === 'newbuild') || /Neubauprojekt/i.test(p.type || '');

    if (p.type) rows.push([ICONS.home, 'Objekttyp', p.type]);
    // Wohnfläche: Neubau zeigt Range, Bestand zeigt Einzelwert
    if (isNewbuild && p.area_range) {
      rows.push([ICONS.ruler, 'Wohnfläche', p.area_range]);
    } else if (p.area_living) {
      rows.push([ICONS.ruler, 'Wohnfläche', `${p.area_living} m²`]);
    }
    if (p.total_area && p.total_area != p.area_living) rows.push([ICONS.ruler, 'Gesamtfläche', `${p.total_area} m²`]);
    if (p.free_area) rows.push([ICONS.ruler, 'Grundstücksfläche', `${p.free_area} m²`]);
    if (p.total_units) rows.push([ICONS.layers, 'Wohneinheiten', p.total_units]);
    // Zimmer
    if (isNewbuild && p.rooms_range) {
      rows.push([ICONS.door, 'Zimmer', p.rooms_range]);
    } else if (p.rooms) {
      rows.push([ICONS.door, 'Zimmer', p.rooms]);
    }
    if (p.bathrooms) rows.push([ICONS.drop, 'Badezimmer', p.bathrooms]);
    // Balkon/Terrasse: Neubau zeigt Range (balcony_terrace kombiniert), Bestand einzeln
    if (isNewbuild && p.balcony_terrace_range) {
      rows.push([ICONS.layout, 'Balkon/Terrasse', p.balcony_terrace_range]);
    } else {
      if (p.area_balcony) rows.push([ICONS.layout, 'Balkonfläche', `${p.area_balcony} m²`]);
      if (p.area_terrace) rows.push([ICONS.layout, 'Terrasse', `${p.area_terrace} m²`]);
    }
    if (p.area_loggia) rows.push([ICONS.layout, 'Loggia', `${p.area_loggia} m²`]);
    // Garten: Neubau Range, Bestand Einzelwert
    if (isNewbuild && p.garden_range) {
      rows.push([ICONS.tree, 'Garten', p.garden_range]);
    } else if (p.area_garden) {
      rows.push([ICONS.tree, 'Gartenfläche', `${p.area_garden} m²`]);
    }
    if (p.area_basement) rows.push([ICONS.box, 'Kellerfläche', `${p.area_basement} m²`]);
    if (p.year_built) rows.push([ICONS.calendar, 'Baujahr', p.year_built]);
    if (p.year_renovated) rows.push([ICONS.calendar, 'Renoviert', p.year_renovated]);
    if (p.construction_end) rows.push([ICONS.flag, 'Fertigstellung', p.construction_end]);
    if (p.construction_type) rows.push([ICONS.hammer, 'Bauart', translate(p.construction_type, CONSTRUCTION_TYPE_LABELS)]);
    if (p.realty_condition) rows.push([ICONS.check, 'Zustand', translate(p.realty_condition, CONDITION_LABELS)]);
    if (p.ownership_type) rows.push([ICONS.key, 'Eigentumsform', translate(p.ownership_type, OWNERSHIP_LABELS)]);
    if (p.furnishing) rows.push([ICONS.sofa, 'Möblierung', translate(p.furnishing, FURNISHING_LABELS)]);
    // Heizung / Warmwasser / Befeuerung aus building_details
    if (p.heating_types && p.heating_types.length) rows.push([ICONS.thermometer, 'Heizungsart', p.heating_types.join(', ')]);
    if (p.heating_fuel) rows.push([ICONS.flame, 'Befeuerung', p.heating_fuel]);
    if (p.heating_hot_water) rows.push([ICONS.drop, 'Warmwasser', p.heating_hot_water]);
    if (p.heating && (!p.heating_types || !p.heating_types.length)) rows.push([ICONS.thermometer, 'Heizung', p.heating]);
    if (p.energy_primary_source) rows.push([ICONS.sun, 'Primärenergie', p.energy_primary_source]);
    if (p.energy_hwb) rows.push([ICONS.zap, 'HWB', `${p.energy_hwb} kWh/m²a`]);
    if (p.energy_fgee) rows.push([ICONS.zap, 'fGEE', p.energy_fgee]);
    if (p.energy_class) rows.push([ICONS.zap, 'Energieklasse', p.energy_class]);
    if (p.energy_certificate && !p.energy_hwb) rows.push([ICONS.zap, 'Energieausweis', p.energy_certificate]);
    if (p.heating_demand_value && !p.energy_hwb) rows.push([ICONS.zap, 'Heizwärmebedarf', `${p.heating_demand_value} kWh/m²a`]);
    if (p.operating_costs) rows.push([ICONS.euro, 'Betriebskosten', `€ ${Number(p.operating_costs).toLocaleString('de-AT')}`]);
    if (p.condition_note) rows.push([ICONS.check, 'Zustand-Details', p.condition_note]);
    if (p.available_from) rows.push([ICONS.clock, 'Beziehbar ab', p.available_from]);
    if (p.city) rows.push([ICONS.mapPin, 'Region', p.city]);

    if (rows.length) {
      detailsEl.innerHTML = `<h2 class="text-xl font-bold mb-4" style="color:#0A0A08">Details</h2>
        <style>.detail-icon{color:#9A958C;flex-shrink:0}</style>
        <div class="divide-y" style="border-color:#F0ECE6">${rows.map(([ic, k, v]) => `<div class="flex items-center justify-between py-3 gap-3"><span class="flex items-center gap-2.5 text-sm" style="color:#9A958C">${ic}<span>${k}</span></span><span class="text-sm font-medium" style="color:#0A0A08">${v}</span></div>`).join('')}</div>`;
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
