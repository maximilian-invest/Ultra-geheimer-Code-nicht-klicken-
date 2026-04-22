/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Referenzen / Verkauft Page
   ═══════════════════════════════════════════════════════════════ */

(async function() {
  const grid = document.getElementById('ref-grid');
  const statsEl = document.getElementById('ref-stats');
  const emptyEl = document.getElementById('ref-empty');
  if (!grid) return;

  const raw = await fetchProperties();
  const allProps = raw.map(mapProperty);
  const soldProps = allProps.filter(p => p.realty_status === 'verkauft');

  // Sort by sold_at desc
  soldProps.sort((a, b) => (b.sold_at || '').localeCompare(a.sold_at || ''));

  if (!soldProps.length) {
    if (emptyEl) emptyEl.style.display = '';
    return;
  }

  // Stats
  if (statsEl) {
    const totalVolume = soldProps.reduce((sum, p) => sum + parseFloat(p.purchase_price || p.rental_price || 0), 0);
    const volStr = totalVolume >= 1e6
      ? '€ ' + (totalVolume / 1e6).toFixed(1).replace('.', ',') + 'M'
      : '€ ' + fmt(totalVolume);
    const brokers = new Set(soldProps.filter(p => p.broker_name).map(p => p.broker_name));
    const stats = [
      { val: soldProps.length, label: 'Objekte' },
      { val: volStr, label: 'Volumen' },
      { val: brokers.size || '—', label: 'Makler' },
    ];
    statsEl.innerHTML = stats.map(s =>
      '<div><div style="font-size:2rem;font-weight:800;color:#D4743B;letter-spacing:-0.03em">' + s.val + '</div>' +
      '<div style="font-size:0.7rem;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:0.15em;margin-top:4px">' + s.label + '</div></div>'
    ).join('');
  }

  // Render cards
  grid.innerHTML = soldProps.map(p => refCard(p)).join('');

  function refCard(p) {
    const img = p.images[0] || '';
    const soldDate = p.sold_at ? fmtDate(p.sold_at) : '';
    const price = fmtPr(p);
    const broker = p.broker_name || '';
    const brokerTitle = p.broker_title || 'Immobilienmakler/in';
    const initials = broker ? broker.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase() : '';

    return '<div style="border-radius:20px;overflow:hidden;background:#fff;border:1px solid #F0ECE6;transition:transform 0.5s cubic-bezier(0.22,1,0.36,1),box-shadow 0.5s cubic-bezier(0.22,1,0.36,1)" onmouseover="this.style.transform=\'translateY(-4px)\';this.style.boxShadow=\'0 24px 48px -12px rgba(10,10,8,0.1)\'" onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'none\'">' +
      '<div style="position:relative;height:220px;overflow:hidden">' +
        (img ? '<img src="' + esc(img) + '" alt="' + esc(p.title) + '" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;filter:grayscale(20%)" />' : '<div style="width:100%;height:100%;background:#F0ECE6"></div>') +
        '<div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.4) 0%,transparent 50%)"></div>' +
        /* VERKAUFT stamp */
        '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-12deg);z-index:3;padding:8px 28px;border:3px solid #D4743B;border-radius:8px;font-size:1.1rem;font-weight:900;letter-spacing:0.2em;text-transform:uppercase;color:#D4743B;opacity:0.85;pointer-events:none">Verkauft</div>' +
        (soldDate ? '<div style="position:absolute;bottom:12px;right:12px;z-index:2;padding:4px 10px;border-radius:6px;font-size:0.65rem;font-weight:600;color:#fff;background:rgba(0,0,0,0.6);backdrop-filter:blur(8px)">' + soldDate + '</div>' : '') +
      '</div>' +
      '<div style="padding:20px">' +
        '<div style="font-size:1.05rem;font-weight:700;color:#0A0A08;letter-spacing:-0.01em;margin-bottom:4px">' + esc(p.title) + '</div>' +
        '<div style="font-size:0.75rem;color:#9A958C;margin-bottom:8px">' + esc(p.address) + (p.city ? ', ' + esc(p.city) : '') + '</div>' +
        (price ? '<div style="font-size:1rem;font-weight:800;color:#0A0A08;margin-bottom:12px;letter-spacing:-0.02em">' + price + '</div>' : '') +
        '<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">' +
          (p.area > 0 ? '<span style="font-size:0.7rem;color:#5A564E;padding:4px 10px;background:#F5F0EB;border-radius:6px">' + p.area + ' m²</span>' : '') +
          (p.rooms > 0 ? '<span style="font-size:0.7rem;color:#5A564E;padding:4px 10px;background:#F5F0EB;border-radius:6px">' + p.rooms + ' Zi.</span>' : '') +
          (p.object_type ? '<span style="font-size:0.7rem;color:#5A564E;padding:4px 10px;background:#F5F0EB;border-radius:6px">' + esc(p.object_type) + '</span>' : '') +
        '</div>' +
        (broker ? '<div style="display:flex;align-items:center;gap:10px;padding-top:14px;border-top:1px solid #F0ECE6"><div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#D4743B,#E8934A);display:flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:800;color:#fff">' + initials + '</div><div><div style="font-size:0.8rem;font-weight:600;color:#0A0A08">' + esc(broker) + '</div><div style="font-size:0.6rem;color:#9A958C">' + esc(brokerTitle) + '</div></div></div>' : '') +
      '</div>' +
    '</div>';
  }

  function fmtDate(dateStr) {
    try {
      const d = new Date(dateStr);
      const months = ['Jän.','Feb.','März','Apr.','Mai','Juni','Juli','Aug.','Sep.','Okt.','Nov.','Dez.'];
      return months[d.getMonth()] + ' ' + d.getFullYear();
    } catch(e) { return ''; }
  }

  function fmtPr(p) {
    const price = parseFloat(p.purchase_price || p.rental_price || 0);
    if (!price) return '';
    return price >= 1e6
      ? 'EUR ' + (price / 1e6).toFixed(2).replace('.', ',') + ' Mio.'
      : 'EUR ' + fmt(price);
  }
})();
