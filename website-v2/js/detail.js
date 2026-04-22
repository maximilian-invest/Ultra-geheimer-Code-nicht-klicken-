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
      avatarEl.innerHTML = `<img src="${esc(brokerImage)}" alt="${esc(brokerName)}" loading="lazy" decoding="async" class="w-full h-full object-cover" />`;
    } else {
      avatarEl.textContent = initials || 'SR';
    }
  }

  /* ─── Betriebskosten in der Sidebar — ausklappbar wenn Aufschluesselung ─── */
  const costItems = [
    { key: 'operating_costs',       label: 'Betriebskosten' },
    { key: 'heating_costs',         label: 'Heizkosten' },
    { key: 'warm_water_costs',      label: 'Warmwasser' },
    { key: 'cooling_costs',         label: 'Kühlung' },
    { key: 'maintenance_reserves',  label: 'Rücklage' },
    { key: 'admin_costs',           label: 'Verwaltung' },
    { key: 'elevator_costs',        label: 'Aufzug' },
    { key: 'parking_costs_monthly', label: 'Stellplatz' },
    { key: 'other_costs',           label: 'Sonstige Kosten' },
  ];
  const filled = costItems
    .map(it => ({ ...it, val: parseFloat(p[it.key] || 0) }))
    .filter(it => it.val > 0);
  const sumSub = filled.reduce((s, it) => s + it.val, 0);
  const bkTotal = parseFloat(p.monthly_costs || 0) || sumSub;

  const bkTotalEl = document.getElementById('prop-bk-total');
  if (bkTotalEl && bkTotal > 0) {
    // Wenn mehr als 1 Position eingetragen ist -> Aufschluesselung ausklappbar anbieten.
    const hasBreakdown = filled.length > 1;
    if (hasBreakdown) {
      bkTotalEl.innerHTML = `
        <button type="button" id="bk-toggle" class="w-full flex items-center justify-between text-left py-0.5"
                style="color:#5A564E">
          <span class="text-sm">zzgl. Betriebskosten: <span style="color:#0A0A08;font-weight:500">EUR ${fmt(bkTotal)} / Monat</span></span>
          <svg id="bk-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9A958C" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0;margin-left:8px"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="bk-body" style="display:none;margin-top:8px;padding:10px 0;border-top:1px solid #F0ECE6">
          ${filled.map(it => `
            <div class="flex items-center justify-between py-1 text-[12px]">
              <span style="color:#5A564E">${esc(it.label)}</span>
              <span class="tabular-nums" style="color:#0A0A08">€ ${fmt(it.val)}</span>
            </div>`).join('')}
          ${parseFloat(p.monthly_costs || 0) > 0 && Math.abs(parseFloat(p.monthly_costs) - sumSub) > 1 ? `
            <div class="flex items-center justify-between py-1 text-[12px] mt-1" style="border-top:1px solid #F0ECE6;padding-top:6px">
              <span style="color:#0A0A08;font-weight:600">Gesamt</span>
              <span class="tabular-nums font-semibold" style="color:#0A0A08">€ ${fmt(parseFloat(p.monthly_costs))}</span>
            </div>` : ''}
        </div>`;
      const bkToggle = document.getElementById('bk-toggle');
      const bkBody = document.getElementById('bk-body');
      const bkChevron = document.getElementById('bk-chevron');
      if (bkToggle && bkBody) {
        bkToggle.addEventListener('click', () => {
          const open = bkBody.style.display !== 'none';
          bkBody.style.display = open ? 'none' : 'block';
          if (bkChevron) bkChevron.style.transform = open ? 'rotate(0)' : 'rotate(180deg)';
        });
      }
    } else {
      // Nur 1 Position -> kein Toggle, nur Text.
      bkTotalEl.innerHTML = `zzgl. Betriebskosten: <span style="color:#0A0A08;font-weight:500">EUR ${fmt(bkTotal)} / Monat</span>`;
    }
  }

  /* ─── Einmalige Nebenkosten beim Kauf (rechts unter Kaufpreis) ─── */
  // Nur Positionen mit Wert > 0 werden angezeigt. Leere Felder zeigen nichts.
  // Neue Projekte bekommen bei Anlage oesterreichische Standardsaetze (im Admin UI).
  const nkEl = document.getElementById('prop-nebenkosten');
  const priceNum = parseFloat(p.purchase_price || p.price || 0) || 0;
  const showNk = !isRental && priceNum > 0;
  if (nkEl && showNk) {
    const pct = (v) => {
      const raw = parseFloat(v);
      return isFinite(raw) && raw > 0 ? raw : 0;
    };
    // Absolute EUR-Werte fuer jede Position aus den Property-Feldern.
    const items = [
      { label: 'Grunderwerbsteuer',     pct: pct(p.land_transfer_tax_pct),     eur: priceNum * pct(p.land_transfer_tax_pct) / 100 },
      { label: 'Grundbuch-Eintragung',  pct: pct(p.land_register_fee_pct),     eur: priceNum * pct(p.land_register_fee_pct) / 100 },
      { label: 'Pfandrecht-Eintragung', pct: pct(p.mortgage_register_fee_pct), eur: priceNum * pct(p.mortgage_register_fee_pct) / 100 },
      { label: 'Vertragserrichtung',    pct: pct(p.contract_fee_pct),          eur: priceNum * pct(p.contract_fee_pct) / 100 },
    ].filter(it => it.eur > 0);
    // Maklerprovision: Provisionsfrei-Flag dominiert.
    if (p.buyer_commission_free) {
      items.push({ label: 'Maklerprovision', pct: 0, eur: 0, free: true });
    } else {
      const provPct = pct(p.buyer_commission_percent);
      if (provPct > 0) {
        items.push({
          label: 'Maklerprovision',
          pct: provPct,
          eur: priceNum * provPct / 100,
        });
      }
    }
    const sumNk = items.reduce((s, it) => s + it.eur, 0);

    if (items.length > 0) {
      const fmtPct = (v) => v > 0 ? v.toString().replace('.', ',') + '%' : '';
      const rows = items.map(it => {
        const right = it.free
          ? `<span class="text-[12px] font-medium" style="color:#D4743B">provisionsfrei</span>`
          : `<span class="tabular-nums font-medium" style="color:#0A0A08">€ ${fmt(it.eur)}</span>`;
        return `
        <div class="flex items-center justify-between py-1.5 text-[13px]">
          <span style="color:#5A564E">${esc(it.label)}${it.pct ? ` <span style="color:#9A958C;font-size:11px">(${fmtPct(it.pct)})</span>` : ''}</span>
          ${right}
        </div>`;
      }).join('');

      const note = p.nebenkosten_note ? `
        <div class="text-[11px] mt-2 pt-2" style="color:#9A958C;border-top:1px solid #F0ECE6">${esc(p.nebenkosten_note)}</div>` : '';

      nkEl.innerHTML = `
        <button type="button" id="nk-toggle" class="w-full flex items-center justify-between text-left py-2"
                style="border-top:1px solid #F0ECE6;border-bottom:1px solid #F0ECE6">
          <span class="text-[11px] font-bold uppercase tracking-widest" style="color:#9A958C">Nebenkosten (Kauf)</span>
          <span class="flex items-center gap-2">
            <span class="text-[13px] tabular-nums font-semibold" style="color:#0A0A08">ca. € ${fmt(sumNk)}</span>
            <svg id="nk-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9A958C" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s"><polyline points="6 9 12 15 18 9"/></svg>
          </span>
        </button>
        <div id="nk-body" style="display:none" class="pt-2">${rows}${note}
          <div class="text-[10.5px] mt-2" style="color:#9A958C">
            Gesetzliche Nebenkosten zusätzlich zum Kaufpreis (ca.). Finaler Betrag abhängig von konkreter Finanzierung.
          </div>
        </div>`;
      const toggle = document.getElementById('nk-toggle');
      const body = document.getElementById('nk-body');
      const chevron = document.getElementById('nk-chevron');
      if (toggle && body) {
        toggle.addEventListener('click', () => {
          const open = body.style.display !== 'none';
          body.style.display = open ? 'none' : 'block';
          if (chevron) chevron.style.transform = open ? 'rotate(0)' : 'rotate(180deg)';
        });
      }
    }
  }

  // (Die frühere separate "Nebenkosten (mtl.)"-Kachel ist weg — Infos sind in der Sidebar ausklappbar.)

  /* ─── Allgemeinräume werden weiter unten als Zeile in der Details-Tabelle gerendert ─── */
  const COMMON_AREA_LABELS = {
    fahrradraum:          'Fahrradraum',
    muellraum:            'Müllraum',
    trockenraum:          'Trockenraum',
    waschkueche:          'Waschküche',
    kinderwagenraum:      'Kinderwagenraum',
    hobbyraum:            'Hobbyraum',
    partyraum:            'Partyraum',
    fitnessraum:          'Fitnessraum',
    gemeinschaftssauna:   'Gemeinschafts-Sauna',
    spielplatz:           'Kinderspielplatz',
    dachterrasse:         'Gemeinschafts-Dachterrasse',
    gemeinschaftsgarten:  'Gemeinschaftsgarten',
    heizraum:             'Heizraum',
    lagerraum:            'Lagerraum',
  };
  const parseCommonAreas = (raw) => {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw;
    const t = String(raw).trim();
    if (!t) return [];
    if (t.startsWith('[')) {
      try { return JSON.parse(t) || []; } catch { return []; }
    }
    return t.split(/[,;\n]/).map(s => s.trim()).filter(Boolean);
  };
  const commonAreaItems = parseCommonAreas(p.common_areas)
    .map(item => COMMON_AREA_LABELS[item] || item)
    .filter(Boolean);

  /* ─── OpenStreetMap — Lage (verschleiert + klappbar + minimalistisch) ─── */
  const mapEl = document.getElementById('prop-map');
  const hasCoords = mapEl && p.latitude && p.longitude
    && !isNaN(parseFloat(p.latitude)) && !isNaN(parseFloat(p.longitude))
    && parseFloat(p.latitude) !== 0 && parseFloat(p.longitude) !== 0;
  if (hasCoords) {
    const lat = parseFloat(p.latitude);
    const lng = parseFloat(p.longitude);
    const regionLabel = [p.zip, p.city].filter(Boolean).join(' ') || 'Region';
    // Flag steuert, ob die Karte beim Laden offen ist (Detail-Website: zu,
    // Kunden-Link: offen — siehe window.SR_MAP_OPEN)
    const startOpen = window.SR_MAP_OPEN === true;
    mapEl.innerHTML = `
      <details class="sr-map-details" ${startOpen ? 'open' : ''}>
        <summary class="inline-flex items-center gap-1.5 text-xs cursor-pointer list-none select-none hover:opacity-80"
                 style="color:#D4743B">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>Lage auf Karte ansehen</span>
          <svg class="sr-map-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="mt-3 rounded-xl overflow-hidden" style="background:#fff;border:1px solid #F0ECE6">
          <div id="prop-map-canvas" class="sr-map-canvas" style="height:320px;width:100%;background:#F0ECE6"></div>
          <div class="px-4 py-2 text-[11px]" style="color:#9A958C;border-top:1px solid #F0ECE6">
            Ungefährer Standort in ${esc(regionLabel)}. Die exakte Adresse wird nach Terminvereinbarung mitgeteilt.
          </div>
        </div>
      </details>`;
    // Styles fuer Karte + Chevron-Rotation + monochromes Black/White-Theme
    if (!document.getElementById('sr-map-style')) {
      const style = document.createElement('style');
      style.id = 'sr-map-style';
      style.textContent = `
        .sr-map-details summary::-webkit-details-marker{display:none}
        .sr-map-details[open] summary .sr-map-chev{transform:rotate(180deg)}
        /* Schwarz/Weiss-Look: nur grayscale + leicht mehr Kontrast,
           keine Aufhellung (sonst werden die light-Tiles komplett weiss). */
        .sr-map-canvas .leaflet-tile-pane{
          filter:grayscale(1) contrast(1.1);
        }
        .sr-map-canvas .leaflet-container{background:#FAF8F5}
        .sr-map-canvas .leaflet-control-attribution{
          background:rgba(255,255,255,0.9);font-size:10px;
        }
      `;
      document.head.appendChild(style);
    }
    // Leaflet init — bei open=false warten wir bis summary geklickt wird,
    // sonst berechnet Leaflet die Groesse falsch (display:none container).
    const initMap = () => {
      if (typeof L === 'undefined') return;
      const canvas = document.getElementById('prop-map-canvas');
      if (!canvas || canvas.dataset.inited === '1') return;
      canvas.dataset.inited = '1';
      const map = L.map(canvas, {
        scrollWheelZoom: false,
        zoomControl: true,
        attributionControl: true,
      }).setView([lat, lng], 13);  // etwas raus fuer Stadt-Kontext
      // CARTO Positron (light_all) — inkl. Stadtnamen + Straßennamen.
      // Per grayscale-Filter oben werden die Straßen quasi schwarz.
      L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        maxZoom: 19,
        subdomains: 'abcd',
        attribution: '&copy; OpenStreetMap · &copy; CARTO',
      }).addTo(map);
      // Orangefarbener Umkreis (ca. 350m) — keine exakte Adresse, nur Region
      L.circle([lat, lng], {
        radius: 350,
        color: '#D4743B',
        weight: 2.5,
        fillColor: '#D4743B',
        fillOpacity: 0.2,
      }).addTo(map);
    };
    const details = mapEl.querySelector('.sr-map-details');
    if (details?.open) {
      setTimeout(initMap, 0);
    } else if (details) {
      details.addEventListener('toggle', () => {
        if (details.open) setTimeout(initMap, 0);
      });
    }
  }

  /* ─── Gallery ─── */
  const gallery = document.getElementById('gallery');
  const imgs = mapped.images;
  if (imgs.length > 0) {
    let gh = `<div class="grid grid-cols-1 md:grid-cols-3 gap-3 rounded-2xl overflow-hidden" style="max-height:500px">`;
    imgs.slice(0, 3).forEach((src, i) => {
      const cls = i === 0 ? 'md:col-span-2 md:row-span-2' : '';
      gh += `<div class="relative overflow-hidden cursor-pointer gallery-img ${cls}" style="min-height:${i===0?'400px':'200px'}" data-idx="${i}">
        <img src="${esc(src)}" alt="" loading="${i === 0 ? 'eager' : 'lazy'}" decoding="async" fetchpriority="${i === 0 ? 'high' : 'auto'}" class="w-full h-full object-cover hover-scale" />
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

  // Wohnfläche: manueller Wert vor Range
  if (p.area_living) {
    statItems.push({ icon: 'area', val: `${p.area_living} m²`, label: 'Wohnfläche' });
  } else if (isNewbuildProj && p.area_range) {
    statItems.push({ icon: 'area', val: p.area_range, label: 'Wohnfläche' });
  }

  // Zimmer: manuell vor Range
  if (p.rooms) {
    statItems.push({ icon: 'rooms', val: p.rooms, label: 'Zimmer' });
  } else if (isNewbuildProj && p.rooms_range) {
    statItems.push({ icon: 'rooms', val: p.rooms_range, label: 'Zimmer' });
  }

  if (p.bathrooms) statItems.push({ icon: 'bath', val: p.bathrooms, label: 'Badezimmer' });

  // Balkon: nur mit echter m²-Angabe oder Range. Kein "Ja"-Fallback mehr.
  if (p.area_balcony) {
    statItems.push({ icon: 'balcony', val: `${p.area_balcony} m²`, label: 'Balkon' });
  } else if (isNewbuildProj && p.balcony_terrace_range) {
    statItems.push({ icon: 'balcony', val: p.balcony_terrace_range, label: 'Balkon/Terrasse' });
  }

  // Terrasse: nur wenn eigener m²-Wert (ansonsten wird Balkon+Terrasse zusammengefuehrt)
  if (p.area_terrace && !p.area_balcony) {
    statItems.push({ icon: 'terrace', val: `${p.area_terrace} m²`, label: 'Terrasse' });
  }

  // Garten: nur mit echter m²-Angabe oder Range. Kein "Ja"-Fallback.
  if (p.area_garden) {
    statItems.push({ icon: 'garden', val: `${p.area_garden} m²`, label: 'Garten' });
  } else if (isNewbuildProj && p.garden_range) {
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
      star: svg('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'),
      grid: svg('<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'),
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
    const QUALITY_LABELS = {
      einfach: 'Einfach', normal: 'Normal',
      gehoben: 'Gehoben', luxurioes: 'Luxuriös', luxuriös: 'Luxuriös',
    };
    const translate = (val, map) => val && map[String(val).toLowerCase()] ? map[String(val).toLowerCase()] : val;

    // Details in 4 logische Gruppen aufgeteilt. Jede Gruppe wird nur
    // angezeigt, wenn sie mindestens eine Zeile enthält.
    const isNewbuild = (p.property_category === 'newbuild') || /Neubauprojekt/i.test(p.type || '');
    const groups = {
      flaechen:  { title: 'Flächen & Räume',    rows: [] },
      bau:       { title: 'Bau & Ausstattung',  rows: [] },
      energie:   { title: 'Energie & Heizung',  rows: [] },
      weiteres:  { title: 'Weiteres',           rows: [] },
    };

    // === Flächen & Räume ============================================
    if (p.type) groups.flaechen.rows.push([ICONS.home, 'Objekttyp', p.type]);
    if (p.area_living) {
      groups.flaechen.rows.push([ICONS.ruler, 'Wohnfläche', `${p.area_living} m²`]);
    } else if (isNewbuild && p.area_range) {
      groups.flaechen.rows.push([ICONS.ruler, 'Wohnfläche', p.area_range]);
    }
    if (p.total_area && p.total_area != p.area_living) {
      groups.flaechen.rows.push([ICONS.ruler, 'Gesamtfläche', `${p.total_area} m²`]);
    }
    if (p.free_area) groups.flaechen.rows.push([ICONS.ruler, 'Grundstücksfläche', `${p.free_area} m²`]);
    if (p.total_units) groups.flaechen.rows.push([ICONS.layers, 'Wohneinheiten', p.total_units]);
    if (p.rooms) {
      groups.flaechen.rows.push([ICONS.door, 'Zimmer', p.rooms]);
    } else if (isNewbuild && p.rooms_range) {
      groups.flaechen.rows.push([ICONS.door, 'Zimmer', p.rooms_range]);
    }
    if (p.bathrooms) groups.flaechen.rows.push([ICONS.drop, 'Badezimmer', p.bathrooms]);
    if (p.floor_count) groups.flaechen.rows.push([ICONS.layers, 'Stockwerke', p.floor_count]);
    if (p.area_balcony) {
      groups.flaechen.rows.push([ICONS.layout, 'Balkon', `${p.area_balcony} m²`]);
    } else if (isNewbuild && p.balcony_terrace_range) {
      groups.flaechen.rows.push([ICONS.layout, 'Balkon/Terrasse', p.balcony_terrace_range]);
    }
    if (p.area_terrace) groups.flaechen.rows.push([ICONS.layout, 'Terrasse', `${p.area_terrace} m²`]);
    if (p.area_loggia)  groups.flaechen.rows.push([ICONS.layout, 'Loggia',   `${p.area_loggia} m²`]);
    if (p.area_garden) {
      groups.flaechen.rows.push([ICONS.tree, 'Garten', `${p.area_garden} m²`]);
    } else if (isNewbuild && p.garden_range) {
      groups.flaechen.rows.push([ICONS.tree, 'Garten', p.garden_range]);
    }
    if (p.area_basement) groups.flaechen.rows.push([ICONS.box, 'Kellerfläche', `${p.area_basement} m²`]);

    // === Bau & Ausstattung ==========================================
    if (p.year_built)        groups.bau.rows.push([ICONS.calendar, 'Baujahr', p.year_built]);
    if (p.year_renovated)    groups.bau.rows.push([ICONS.calendar, 'Sanierungsjahr', p.year_renovated]);
    if (p.construction_end)  groups.bau.rows.push([ICONS.flag, 'Fertigstellung', p.construction_end]);
    if (p.construction_type) groups.bau.rows.push([ICONS.hammer, 'Bauart', translate(p.construction_type, CONSTRUCTION_TYPE_LABELS)]);
    if (p.realty_condition)  groups.bau.rows.push([ICONS.check, 'Zustand', translate(p.realty_condition, CONDITION_LABELS)]);
    if (p.condition_note)    groups.bau.rows.push([ICONS.check, 'Zustand-Details', p.condition_note]);
    if (p.quality)           groups.bau.rows.push([ICONS.star, 'Qualität', translate(p.quality, QUALITY_LABELS)]);
    if (p.flooring)          groups.bau.rows.push([ICONS.grid, 'Bodenbelag', p.flooring]);
    if (p.furnishing)        groups.bau.rows.push([ICONS.sofa, 'Möblierung', translate(p.furnishing, FURNISHING_LABELS)]);
    if (p.ownership_type)    groups.bau.rows.push([ICONS.key, 'Eigentumsform', translate(p.ownership_type, OWNERSHIP_LABELS)]);
    if (commonAreaItems.length) {
      groups.bau.rows.push([ICONS.layers, 'Allgemeinräume', commonAreaItems.join(' · ')]);
    }

    // === Energie & Heizung ==========================================
    if (p.heating_types && p.heating_types.length) {
      groups.energie.rows.push([ICONS.thermometer, 'Heizungsart', p.heating_types.join(', ')]);
    } else if (p.heating) {
      groups.energie.rows.push([ICONS.thermometer, 'Heizung', p.heating]);
    }
    if (p.heating_fuel)          groups.energie.rows.push([ICONS.flame, 'Befeuerung', p.heating_fuel]);
    if (p.heating_hot_water)     groups.energie.rows.push([ICONS.drop, 'Warmwasser', p.heating_hot_water]);
    if (p.energy_primary_source) groups.energie.rows.push([ICONS.sun, 'Primärenergie', p.energy_primary_source]);
    if (p.heating_demand_value)  groups.energie.rows.push([ICONS.zap, 'HWB', `${p.heating_demand_value} kWh/m²a`]);
    if (p.energy_efficiency_value) groups.energie.rows.push([ICONS.zap, 'fGEE', p.energy_efficiency_value]);
    if (p.heating_demand_class)  groups.energie.rows.push([ICONS.zap, 'Energieklasse', p.heating_demand_class]);
    if (p.energy_certificate && !p.heating_demand_value) {
      groups.energie.rows.push([ICONS.zap, 'Energieausweis', p.energy_certificate]);
    }

    // === Weiteres (Verfuegbarkeit, Lage) ============================
    // Hinweis: Monatliche Kosten stehen nur noch in der Sidebar (ausklappbar).
    if (p.available_from
        && typeof p.available_from === 'string'
        && p.available_from.trim() !== ''
        && !/^0{4}-0{2}-0{2}/.test(p.available_from)
        && !isNaN(new Date(p.available_from).getTime())) {
      const d = new Date(p.available_from);
      const formatted = `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}.${d.getFullYear()}`;
      groups.weiteres.rows.push([ICONS.clock, 'Beziehbar ab', formatted]);
    }
    if (p.city) groups.weiteres.rows.push([ICONS.mapPin, 'Region', p.city]);

    const renderRow = ([ic, k, v]) =>
      `<div class="flex items-center justify-between py-3 gap-3">
        <span class="flex items-center gap-2.5 text-sm" style="color:#9A958C">${ic}<span>${k}</span></span>
        <span class="text-sm font-medium text-right" style="color:#0A0A08">${v}</span>
      </div>`;

    // Jede Gruppe ist eigenes <details>-Element — accordion-artig ausklappbar.
    // Erste Gruppe (Flächen & Räume) ist per Default offen, alle anderen zu.
    const groupKeys = Object.keys(groups);
    const renderGroup = (key, idx) => {
      const g = groups[key];
      if (g.rows.length === 0) return '';
      const open = idx === 0 ? 'open' : '';
      return `
      <details class="detail-group mb-3" ${open}>
        <summary class="flex items-center justify-between py-3 px-1 cursor-pointer list-none select-none"
                 style="border-bottom:1px solid #F0ECE6">
          <h3 class="text-[11px] font-bold uppercase tracking-widest" style="color:#0A0A08">${g.title}</h3>
          <span class="flex items-center gap-2">
            <span class="text-[10px]" style="color:#9A958C">${g.rows.length} ${g.rows.length === 1 ? 'Eintrag' : 'Einträge'}</span>
            <svg class="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9A958C" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 style="transition:transform 0.2s"><polyline points="6 9 12 15 18 9"/></svg>
          </span>
        </summary>
        <div class="divide-y pt-1" style="border-color:#F0ECE6">
          ${g.rows.map(renderRow).join('')}
        </div>
      </details>`;
    };

    const hasAny = groupKeys.some(k => groups[k].rows.length > 0);
    if (hasAny) {
      detailsEl.innerHTML = `
        <h2 class="text-xl font-bold mb-4" style="color:#0A0A08">Details</h2>
        <style>
          .detail-icon{color:#9A958C;flex-shrink:0}
          .detail-group summary::-webkit-details-marker{display:none}
          .detail-group[open] summary .chev{transform:rotate(180deg)}
          .detail-group summary:hover h3{color:#D4743B}
        </style>
        ${groupKeys.map((k, i) => renderGroup(k, i)).join('')}`;
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
              ${img ? `<img src="${esc(img)}" alt="${esc(o.title)}" loading="lazy" decoding="async" class="w-full h-full object-cover" />` : `<div class="w-full h-full flex items-center justify-center" style="background:#F0ECE6;min-height:200px"><span class="text-sm" style="color:#9A958C">Kein Bild</span></div>`}
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

  /* ─── Anfrage-Modal ─── */
  const refId = p.ref_id || ('ID-' + p.id);
  const propLabel = mapped.title;
  const subjectText = `Anfrage ${refId} — ${propLabel}`.slice(0, 180);

  const openBtn = document.getElementById('open-inquiry');
  const modal   = document.getElementById('inquiry-modal');
  const form    = document.getElementById('inquiry-form');
  const subDisp = document.getElementById('inquiry-subject-display');
  const subInp  = document.getElementById('inquiry-subject-readonly');
  const propIdInp = document.getElementById('inquiry-property-id');
  const errEl   = document.getElementById('inquiry-error');
  const okEl    = document.getElementById('inquiry-success');
  const submitBtn = document.getElementById('inquiry-submit');
  const submitLbl = document.getElementById('inquiry-submit-label');

  if (openBtn && modal && form) {
    if (subDisp) subDisp.textContent = subjectText;
    if (subInp) subInp.value = subjectText;
    if (propIdInp) propIdInp.value = p.id;

    const openModal = () => {
      modal.style.display = 'block';
      document.body.style.overflow = 'hidden';
      if (errEl) errEl.classList.add('hidden');
      if (okEl) okEl.classList.add('hidden');
      setTimeout(() => form.querySelector('input[name="name"]')?.focus(), 100);
    };
    const closeModal = () => {
      modal.style.display = 'none';
      document.body.style.overflow = '';
    };

    openBtn.addEventListener('click', openModal);
    document.getElementById('inquiry-close')?.addEventListener('click', closeModal);
    document.getElementById('inquiry-cancel')?.addEventListener('click', closeModal);
    document.getElementById('inquiry-backdrop')?.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (errEl) errEl.classList.add('hidden');
      if (okEl) okEl.classList.add('hidden');
      submitBtn.disabled = true;
      if (submitLbl) submitLbl.textContent = 'Wird gesendet …';

      const fd = new FormData(form);
      const payload = {
        name:        String(fd.get('name') || '').trim(),
        email:       String(fd.get('email') || '').trim(),
        phone:       String(fd.get('phone') || '').trim(),
        message:     String(fd.get('message') || '').trim(),
        property_id: parseInt(fd.get('property_id'), 10) || null,
        honeypot:    String(fd.get('honeypot') || ''),
      };

      try {
        const r = await fetch('https://kundenportal.sr-homes.at/api/website/inquiry', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(payload),
        });
        const j = await r.json().catch(() => ({ success: false, error: 'Ungültige Antwort' }));
        if (r.ok && j.success) {
          if (okEl) {
            okEl.textContent = '✓ Anfrage gesendet. Wir melden uns bei Ihnen.';
            okEl.classList.remove('hidden');
          }
          form.reset();
          // Nach 3s schliessen, damit User Bestaetigung sieht
          setTimeout(closeModal, 2500);
        } else {
          const msg = j.error || 'Nachricht konnte nicht gesendet werden.';
          if (errEl) { errEl.textContent = msg; errEl.classList.remove('hidden'); }
        }
      } catch (err) {
        if (errEl) {
          errEl.textContent = 'Netzwerkfehler. Bitte versuchen Sie es später erneut.';
          errEl.classList.remove('hidden');
        }
      } finally {
        submitBtn.disabled = false;
        if (submitLbl) submitLbl.textContent = 'Anfrage senden';
      }
    });
  }
})();
