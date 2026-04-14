(function() {
  // Only activate in preview mode
  if (!new URLSearchParams(window.location.search).has("preview") || 
      new URLSearchParams(window.location.search).get("preview") !== "1") return;

  // Hide nav, footer, grain, related section, back link
  var style = document.createElement("style");
  style.textContent = [
    "#nav-placeholder { display:none !important }",
    "#footer-placeholder { display:none !important }",
    ".grain { display:none !important }",
    "main { padding-top:0 !important }",
    "#detail-content > .max-w-\\[1440px\\] { padding-top:12px !important }",
  ].join("\n");
  document.head.appendChild(style);

  function hideElements() {
    var backLink = document.querySelector('#detail-content a[href="immobilien.html"]');
    if (backLink) backLink.style.display = "none";
    var related = document.querySelector("#detail-content > section");
    if (related) related.style.display = "none";
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", hideElements);
  } else {
    hideElements();
  }

  // VORSCHAU badge
  var badge = document.createElement("div");
  badge.textContent = "VORSCHAU";
  badge.style.cssText = "position:fixed;top:8px;left:8px;z-index:9999;background:#D4743B;color:#fff;font-size:10px;font-weight:700;letter-spacing:0.1em;padding:3px 10px;border-radius:4px;pointer-events:none;opacity:0.9";
  document.body.appendChild(badge);

  // SVG icons for stats grid rebuild
  var svgIcons = {
    area: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
    rooms: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>',
    bath: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16a1 1 0 0 1 1 1v3a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-3a1 1 0 0 1 1-1z"/><path d="M6 12V5a2 2 0 0 1 2-2h3v2.25"/><line x1="8" y1="20" x2="7" y2="22"/><line x1="16" y1="20" x2="17" y2="22"/></svg>',
    garden: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V10"/><path d="M6 22V16c0-3.3 2.7-6 6-6s6 2.7 6 6v6"/></svg>',
    terrace: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="12" width="20" height="2" rx="1"/><path d="M4 14v8"/><path d="M20 14v8"/><path d="M12 14v8"/><path d="M2 22h20"/></svg>',
    balcony: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="10" width="18" height="2" rx="1"/><path d="M5 12v8"/><path d="M19 12v8"/><path d="M12 12v8"/><path d="M3 20h18"/></svg>',
    year: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D4743B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
  };

  function rebuildStatsGrid(f) {
    var grid = document.getElementById('stats-grid');
    if (!grid) return;
    var items = [];
    if (f.area) items.push({ icon: 'area', val: f.area + ' m\u00B2', label: 'Wohnfl\u00E4che' });
    if (f.rooms) items.push({ icon: 'rooms', val: f.rooms, label: 'Zimmer' });
    if (f.bathrooms) items.push({ icon: 'bath', val: f.bathrooms, label: 'Badezimmer' });
    // Features as stat items
    if (f.features && Array.isArray(f.features)) {
      if (f.features.indexOf('Garten') >= 0) items.push({ icon: 'garden', val: 'Ja', label: 'Garten' });
      if (f.features.indexOf('Terrasse') >= 0) items.push({ icon: 'terrace', val: 'Ja', label: 'Terrasse' });
      if (f.features.indexOf('Balkon') >= 0) items.push({ icon: 'balcony', val: 'Ja', label: 'Balkon' });
    }
    if (f.year) items.push({ icon: 'year', val: f.year, label: 'Baujahr' });

    if (items.length > 0) {
      grid.innerHTML = '<div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6 rounded-2xl" style="background:#F0ECE6">' +
        items.slice(0, 4).map(function(s) {
          return '<div class="text-center"><div class="flex justify-center mb-2">' + (svgIcons[s.icon] || '') + '</div><div class="text-lg font-bold" style="color:#0A0A08">' + s.val + '</div><div class="text-xs" style="color:#9A958C">' + s.label + '</div></div>';
        }).join('') + '</div>';
    } else {
      grid.innerHTML = '';
    }
  }

  // Listen for preview updates from admin iframe parent
  window.addEventListener("message", function(e) {
    if (!e.data || e.data.type !== "sr-preview-update") return;
    var f = e.data.fields;

    if (f.title !== undefined) {
      var titleEl = document.getElementById("prop-title");
      if (titleEl) titleEl.textContent = f.title;
      document.title = f.title + " | SR-Homes";
    }
    if (f.type !== undefined) {
      var typeEl = document.getElementById("prop-type");
      if (typeEl) typeEl.textContent = f.type;
    }
    if (f.ref !== undefined) {
      var refEl = document.getElementById("prop-ref");
      if (refEl) refEl.textContent = f.ref ? "Ref: " + f.ref : "";
    }
    if (f.subtitle !== undefined) {
      var subEl = document.getElementById("prop-subtitle");
      if (subEl) subEl.textContent = f.subtitle;
    }
    if (f.address !== undefined) {
      var addrEl = document.getElementById("prop-address");
      if (addrEl) addrEl.textContent = f.address;
    }
    if (f.price !== undefined) {
      var priceEl = document.getElementById("prop-price");
      if (priceEl) {
        if (f.price) {
          var n = parseFloat(f.price);
          priceEl.textContent = n >= 1000000
            ? "ab \u20AC " + (n/1000000).toFixed(2).replace(".", ",") + " Mio."
            : "\u20AC " + n.toLocaleString("de-AT");
        } else {
          priceEl.textContent = "Preis auf Anfrage";
        }
      }
    }

    // Rebuild stats grid with current data
    rebuildStatsGrid(f);

    // Description
    if (f.description !== undefined) {
      var descEl = document.getElementById("tab-desc");
      if (descEl) {
        descEl.innerHTML = f.description ? "<p>" + f.description.replace(/\n\n/g, "</p><p>").replace(/\n/g, "<br>") + "</p>" : "";
      }
    }

    // Features badges
    if (f.features !== undefined) {
      var featEl = document.getElementById("features");
      if (featEl && Array.isArray(f.features)) {
        if (f.features.length === 0) {
          featEl.innerHTML = "";
        } else {
          featEl.innerHTML = '<h3 class="text-lg font-bold mb-4" style="color:#0A0A08">Ausstattung</h3><div class="flex flex-wrap gap-2">' +
            f.features.map(function(feat) {
              return '<span class="px-4 py-2 rounded-full text-xs font-medium" style="background:#F0ECE6;color:#5A564E">' + feat + '</span>';
            }).join('') + '</div>';
        }
      }
    }
  });
})();
