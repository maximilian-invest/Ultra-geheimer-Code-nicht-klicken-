/**
 * SR-Homes Units Table v7 — Serhant-Style
 * - Full image gallery lightbox with navigation for ALL properties
 * - Injects extra descriptions (Lage, Ausstattung) for ALL properties
 * - Injects units table for Neubauprojekte
 * - Matches website container layout (max-width 1440px, centered, proper padding)
 * - "ab" prefix on listing cards for Neubauprojekte
 */
(function() {
  'use strict';

  var API = 'https://kundenportal.sr-homes.at/api/website';
  /* Design tokens matching SR-Homes website */
  var A  = '#D4743B'; /* accent orange */
  var TD = '#0A0A08'; /* text dark */
  var TM = '#8A8680'; /* text muted */
  var BD = '#E8E4DF'; /* border */
  var BG = '#FAF8F5'; /* page bg */
  var SB = '#9B9590'; /* sold badge */
  var RB = '#D4A03B'; /* reserved badge */

  /* Sanitize text to prevent XSS from API responses */
  function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  var injected = false;
  var descriptionsInjected = false;
  var lightboxInjected = false;
  var propMap = {};
  var propCategoryMap = {};
  var newbuildProps = {};
  var propHighlights = {};
  var propFeatures = {};
  var propData = {}; /* full property data for listing card stats */

  /* ══════════════════════════════════════════════
     BROWSER HISTORY — React SPA uses useState only,
     no pushState. We add proper history entries so
     the browser back button works within the site.
     ══════════════════════════════════════════════ */
  var srCurrentView = null; /* tracks: 'home','immobilien','detail','verkaufen','bewerten','portal','über','kontakt' */
  var srHistoryManaged = false;
  var srPoppingState = false; /* true while we handle popstate to avoid re-pushing */

  /* URL path <-> view mapping */
  var viewToPath = {
    'home': '/', 'immobilien': '/immobilien', 'verkaufen': '/verkaufen',
    'bewerten': '/bewerten', 'portal': '/kundenportal', 'über': '/ueber-uns',
    'kontakt': '/kontakt', 'detail': '/immobilien',
    'impressum': '/impressum', 'datenschutz': '/datenschutz'
  };
  var pathToView = {
    '/': 'home', '/immobilien': 'immobilien', '/verkaufen': 'verkaufen',
    '/bewerten': 'bewerten', '/kundenportal': 'portal', '/ueber-uns': 'über',
    '/kontakt': 'kontakt', '/impressum': 'impressum', '/datenschutz': 'datenschutz'
  };

  /* Title map for <title> tag */
  var viewTitles = {
    'home': 'SR-Homes Immobilien GmbH | Salzburg & Oberoesterreich',
    'immobilien': 'Immobilien | SR-Homes',
    'verkaufen': 'Immobilie verkaufen | SR-Homes',
    'bewerten': 'Immobilie bewerten | SR-Homes',
    'portal': 'Kundenportal | SR-Homes',
    'über': 'Über uns | SR-Homes',
    'kontakt': 'Kontakt | SR-Homes',
    'detail': 'Immobilie | SR-Homes',
    'impressum': 'Impressum | SR-Homes',
    'datenschutz': 'Datenschutz | SR-Homes'
  };

  function getPathForView(view) {
    return viewToPath[view] || '/';
  }

  function getViewFromPath() {
    var path = window.location.pathname.replace(/\/+$/, '') || '/';
    return pathToView[path] || 'home';
  }

  /* Intercept nav button clicks to reliably detect page changes */
  var srNavHooked = false;
  function hookNavButtons() {
    var navBtns = document.querySelectorAll('nav button');
    if (navBtns.length === 0) return;
    /* Re-hook if nav buttons changed (React re-renders) */
    var firstBtn = navBtns[0];
    if (firstBtn && firstBtn._srHooked) return;
    srNavHooked = true;
    var textMap = {'Start':'home','Immobilien':'immobilien','Verkaufen':'verkaufen',
                   'Bewerten':'bewerten','Kundenportal':'portal','Über uns':'über','Kontakt':'kontakt'};
    navBtns.forEach(function(btn) {
      btn._srHooked = true;
      btn.addEventListener('click', function() {
        if (srPoppingState) return;
        var txt = (btn.textContent || '').trim();
        var view = textMap[txt];
        if (view && view !== srCurrentView) {
          var prev = srCurrentView;
          srCurrentView = view;
          var urlPath = getPathForView(view);
          var title = viewTitles[view] || viewTitles['home'];
          document.title = title;
          try { history.pushState({srView: view, srPrev: prev}, title, urlPath); } catch(e){}
        }
      });
    });
    /* Also hook mobile menu buttons if they exist (duplicate nav in hamburger) */
    var mobileBtns = document.querySelectorAll('div[class*="fixed"] button, div[class*="absolute"] button');
    mobileBtns.forEach(function(btn) {
      var txt = (btn.textContent || '').trim();
      if (textMap[txt] && !btn._srHooked) {
        btn._srHooked = true;
        btn.addEventListener('click', function() {
          if (srPoppingState) return;
          var view = textMap[txt];
          if (view && view !== srCurrentView) {
            var prev = srCurrentView;
            srCurrentView = view;
            var urlPath = getPathForView(view);
            var title = viewTitles[view] || viewTitles['home'];
            document.title = title;
            try { history.pushState({srView: view, srPrev: prev}, title, urlPath); } catch(e){}
          }
        });
      }
    });
  }

  function detectCurrentView() {
    /* Detail page: has h2 "Beschreibung" + "Details" */
    if (isDetailPage()) return 'detail';
    /* Check all nav buttons for active state using multiple heuristics */
    var navBtns = document.querySelectorAll('nav button');
    var textMap = {'Start':'home','Immobilien':'immobilien','Verkaufen':'verkaufen',
                   'Bewerten':'bewerten','Kundenportal':'portal','Über uns':'über','Kontakt':'kontakt'};
    for (var i = 0; i < navBtns.length; i++) {
      var btn = navBtns[i];
      var txt = (btn.textContent || '').trim();
      if (!textMap[txt]) continue;
      var style = window.getComputedStyle(btn);
      var col = style.color || '';
      /* Check for accent color in various formats */
      var isActive = false;
      /* rgb(212, 116, 59) or similar */
      if (col.indexOf('212') !== -1 && col.indexOf('116') !== -1) isActive = true;
      /* rgb(232, 116, 58) — #E8743A */
      if (col.indexOf('232') !== -1 && col.indexOf('116') !== -1) isActive = true;
      /* hex check */
      if (col.toLowerCase().indexOf('d4743b') !== -1 || col.toLowerCase().indexOf('e8743a') !== -1) isActive = true;
      /* Check font-weight as fallback — active nav items are often bolder */
      var fw = parseInt(style.fontWeight || '400');
      if (fw >= 600 && col !== 'rgb(255, 255, 255)' && col.indexOf('255, 255, 255') === -1) {
        /* Bold + not white = likely active */
      }
      if (isActive) return textMap[txt];
    }
    /* Content-based fallback: detect page by unique headings/selectors */
    var headings = document.querySelectorAll('h2');
    for (var h = 0; h < headings.length; h++) {
      var ht = (headings[h].textContent || '').trim();
      if (ht === 'Unsere Top-Immobilien' || ht.indexOf('Exklusive Objekte') !== -1) return 'home';
    }
    /* Immobilien listing: has filter select elements */
    var selects = document.querySelectorAll('select');
    if (selects.length >= 3) {
      var hasTypeFilter = false;
      selects.forEach(function(s) { if (s.innerHTML.indexOf('Alle Typen') !== -1 || s.innerHTML.indexOf('Haus') !== -1) hasTypeFilter = true; });
      if (hasTypeFilter) return 'immobilien';
    }
    /* Check for page-specific buttons or text in first h1/h2 */
    var h1 = document.querySelector('h1');
    if (h1) {
      var h1t = (h1.textContent || '').toLowerCase();
      if (h1t.indexOf('verkauf') !== -1) return 'verkaufen';
      if (h1t.indexOf('bewert') !== -1) return 'bewerten';
      if (h1t.indexOf('kontakt') !== -1) return 'kontakt';
    }
    /* Fallback: if we have a tracked view, keep it */
    if (srCurrentView) return srCurrentView;
    return 'home';
  }

  function clickNavButton(viewKey) {
    var labelMap = {'home':'Start','immobilien':'Immobilien','verkaufen':'Verkaufen',
                    'bewerten':'Bewerten','portal':'Kundenportal','über':'Über uns','kontakt':'Kontakt'};
    var label = labelMap[viewKey];
    if (!label && viewKey === 'detail') {
      /* Can't navigate directly to detail from nav — go to immobilien instead */
      label = 'Immobilien';
    }
    if (!label) label = 'Start';
    var navBtns = document.querySelectorAll('nav button');
    for (var i = 0; i < navBtns.length; i++) {
      if ((navBtns[i].textContent || '').trim() === label) {
        navBtns[i].click();
        return true;
      }
    }
    return false;
  }

  function trackHistory() {
    /* Hook nav buttons on every check — they may re-render */
    hookNavButtons();
    if (srPoppingState) return;
    var view = detectCurrentView();
    if (view === srCurrentView) return;
    var prev = srCurrentView;
    srCurrentView = view;
    var urlPath = getPathForView(view);
    var title = viewTitles[view] || viewTitles['home'];
    document.title = title;
    if (!srHistoryManaged) {
      /* Replace initial state so the starting point has the correct URL */
      try { history.replaceState({srView: view}, title, urlPath); } catch(e){}
      srHistoryManaged = true;
      return;
    }
    /* Only push if path actually changed */
    var currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
    var targetPath = urlPath.replace(/\/+$/, '') || '/';
    if (currentPath !== targetPath) {
      try { history.pushState({srView: view, srPrev: prev}, title, urlPath); } catch(e){}
    }
  }

  /* Navigate to the correct page based on URL on first load */
  var srInitialNavDone = false;
  function navigateFromUrl() {
    if (srInitialNavDone) return;
    srInitialNavDone = true;
    var urlView = getViewFromPath();
    if (urlView !== 'home') {
      /* Wait for React to mount, then click the nav button */
      var attempts = 0;
      var tryNav = function() {
        if (clickNavButton(urlView)) {
          srCurrentView = urlView;
          try { history.replaceState({srView: urlView}, '', getPathForView(urlView)); } catch(e){}
        } else if (attempts < 10) {
          attempts++;
          setTimeout(tryNav, 300);
        }
      };
      setTimeout(tryNav, 500);
    }
  }

  window.addEventListener('popstate', function(e) {
    var state = e.state;
    /* Reset injection flags so check() re-injects on back-nav */
    injected = false;
    descriptionsInjected = false;
    /* Determine target view: from state or from URL path */
    var targetView = (state && state.srView) ? state.srView : getViewFromPath();
    window._srPopState = true;
    srPoppingState = true;
    srCurrentView = targetView;
    document.title = viewTitles[targetView] || viewTitles['home'];
    /* Do NOT clickNavButton — the React bundle's popstate handler already updates state.
       Clicking the nav button would trigger pushState again, corrupting the history stack. */
    /* Reset flag after React has time to re-render */
    setTimeout(function() { srPoppingState = false; window._srPopState = false; }, 600);
    setTimeout(check, 800);
  });

  /* ══════════════════════════════════════════════
     LIGHTBOX — Full gallery with prev/next
     ══════════════════════════════════════════════ */
  function initLightbox() {
    if (lightboxInjected) return;
    if (document.getElementById('sr-lightbox')) return;

    /* Create lightbox overlay */
    var lb = document.createElement('div');
    lb.id = 'sr-lightbox';
    lb.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.92);backdrop-filter:blur(12px);opacity:0;transition:opacity 0.3s ease;cursor:zoom-out';

    lb.innerHTML =
      '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:48px">' +
        '<img id="sr-lb-img" src="" alt="" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;user-select:none;transition:opacity 0.25s ease" />' +
      '</div>' +
      /* Close button */
      '<button id="sr-lb-close" style="position:absolute;top:20px;right:24px;background:none;border:none;color:#fff;font-size:32px;cursor:pointer;opacity:0.7;transition:opacity 0.2s;z-index:10;padding:8px;line-height:1">&times;</button>' +
      /* Prev button */
      '<button id="sr-lb-prev" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.1);border:none;color:#fff;font-size:28px;width:52px;height:52px;border-radius:50%;cursor:pointer;opacity:0.7;transition:all 0.2s;display:flex;align-items:center;justify-content:center">\u2039</button>' +
      /* Next button */
      '<button id="sr-lb-next" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.1);border:none;color:#fff;font-size:28px;width:52px;height:52px;border-radius:50%;cursor:pointer;opacity:0.7;transition:all 0.2s;display:flex;align-items:center;justify-content:center">\u203A</button>' +
      /* Counter */
      '<div id="sr-lb-counter" style="position:absolute;bottom:24px;left:50%;transform:translateX(-50%);color:#fff;font-size:14px;font-weight:500;opacity:0.7;font-family:Outfit,system-ui,sans-serif;letter-spacing:1px"></div>';

    document.body.appendChild(lb);

    var lbImg = document.getElementById('sr-lb-img');
    var lbCounter = document.getElementById('sr-lb-counter');
    var images = [];
    var currentIdx = 0;

    function showImage(idx) {
      if (idx < 0) idx = images.length - 1;
      if (idx >= images.length) idx = 0;
      currentIdx = idx;
      lbImg.style.opacity = '0';
      setTimeout(function() {
        lbImg.src = images[currentIdx];
        lbImg.onload = function() { lbImg.style.opacity = '1'; };
        lbCounter.textContent = (currentIdx + 1) + ' / ' + images.length;
      }, 150);
    }

    function openLightbox(imgs, startIdx) {
      images = imgs;
      currentIdx = startIdx || 0;
      lb.style.display = 'block';
      requestAnimationFrame(function() { lb.style.opacity = '1'; });
      lbImg.src = images[currentIdx];
      lbCounter.textContent = (currentIdx + 1) + ' / ' + images.length;
      document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
      lb.style.opacity = '0';
      setTimeout(function() { lb.style.display = 'none'; }, 300);
      document.body.style.overflow = '';
    }

    lb.addEventListener('click', function(e) {
      if (e.target === lb || e.target === lb.firstElementChild) closeLightbox();
    });
    document.getElementById('sr-lb-close').addEventListener('click', closeLightbox);
    document.getElementById('sr-lb-prev').addEventListener('click', function(e) { e.stopPropagation(); showImage(currentIdx - 1); });
    document.getElementById('sr-lb-next').addEventListener('click', function(e) { e.stopPropagation(); showImage(currentIdx + 1); });

    /* Hover effects */
    ['sr-lb-prev','sr-lb-next','sr-lb-close'].forEach(function(id) {
      var el = document.getElementById(id);
      el.addEventListener('mouseenter', function() { this.style.opacity = '1'; this.style.background = id === 'sr-lb-close' ? 'none' : 'rgba(255,255,255,0.2)'; });
      el.addEventListener('mouseleave', function() { this.style.opacity = '0.7'; this.style.background = id === 'sr-lb-close' ? 'none' : 'rgba(255,255,255,0.1)'; });
    });

    /* Keyboard navigation */
    document.addEventListener('keydown', function(e) {
      if (lb.style.display === 'none') return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowLeft') showImage(currentIdx - 1);
      if (e.key === 'ArrowRight') showImage(currentIdx + 1);
    });

    /* Expose open function */
    window._srOpenLightbox = openLightbox;
    lightboxInjected = true;
  }

  /* Attach click handlers to ALL images in gallery area */
  function attachGalleryClicks(allImageUrls) {
    if (!window._srOpenLightbox || !allImageUrls.length) return;
    /* Find gallery images — they are inside the first section with py-8 */
    var allImgs = document.querySelectorAll('img');
    allImgs.forEach(function(img) {
      if (img.dataset.srLb === '1') return;
      /* Only target images that look like gallery images (large, in first section area) */
      var rect = img.getBoundingClientRect();
      if (rect.width < 200 || rect.height < 100) return;
      /* Skip logo, icons, contact images */
      if (img.alt === 'SR-Homes' || img.closest('nav') || img.closest('footer')) return;
      if (img.closest('#sr-units-section') || img.closest('#sr-extra-descriptions') || img.closest('#sr-objektdaten')) return;
      /* Must be a property image URL */
      var src = img.src;
      if (src.indexOf('property') === -1 && src.indexOf('image/') === -1 && src.indexOf('storage/') === -1) return;

      img.dataset.srLb = '1';
      img.style.cursor = 'zoom-in';
      img.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var idx = 0;
        for (var i = 0; i < allImageUrls.length; i++) {
          if (src === allImageUrls[i] || src.indexOf(allImageUrls[i].split('/').pop()) !== -1 || allImageUrls[i].indexOf(src.split('/').pop()) !== -1) {
            idx = i; break;
          }
        }
        window._srOpenLightbox(allImageUrls, idx);
      });
    });
  }


  function fmt(p) {
    if (!p || p <= 0) return '\u2014';
    return 'EUR\u00A0' + Math.round(p).toLocaleString('de-AT').replace(/,/g, '\u2009');
  }
  function fmtA(a) {
    if (!a || a <= 0) return '\u2014';
    return parseFloat(a).toFixed(1).replace('.', ',') + '\u00A0m\u00B2';
  }
  function badge(s) {
    var x = (s || 'frei').toLowerCase().trim();
    var c = x === 'verkauft' ? SB : x === 'reserviert' ? RB : A;
    var l = x === 'verkauft' ? 'Verkauft' : x === 'reserviert' ? 'Reserviert' : 'Verf\u00FCgbar';
    return '<span style="display:inline-block;padding:4px 14px;border-radius:4px;font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;background:'+c+';color:#fff">'+l+'</span>';
  }

  function thStyle(al) {
    return 'padding:12px 20px;font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;text-align:'+(al||'left')+';color:'+TM+';border-bottom:2px solid '+TD;
  }

  function rowHtml(u, sold) {
    var o = sold ? '0.4' : '1';
    var pr = sold ? '\u2014' : fmt(u.price);
    var rm = u.rooms ? parseFloat(u.rooms).toFixed(0) : '\u2014';
    var arrow = sold ? '' : '<span class="sr-arrow" style="display:inline-block;opacity:0;transition:all 0.3s ease;font-size:20px;color:'+A+';font-weight:300;transform:translateX(-4px)">\u203A</span>';
    return '<tr class="sr-row" data-type="'+(u.unit_type||'')+'" data-sold="'+(sold?1:0)+'" style="opacity:'+o+';border-bottom:1px solid '+BD+';transition:all 0.2s ease;cursor:'+(sold?'default':'pointer')+'">'+
      '<td style="padding:16px 20px;font-weight:600;font-size:15px;white-space:nowrap;color:'+TD+'">'+(u.unit_number||'\u2014')+'</td>'+
      '<td style="padding:16px 20px;font-size:14px;color:'+TM+'">'+(u.unit_type||'\u2014')+'</td>'+
      '<td style="padding:16px 20px;font-size:14px;text-align:center;color:'+TD+'">'+rm+'</td>'+
      '<td style="padding:16px 20px;font-size:14px;text-align:right;color:'+TD+'">'+fmtA(u.area_m2)+'</td>'+
      '<td style="padding:16px 20px;font-size:15px;font-weight:600;text-align:right;letter-spacing:-0.3px;color:'+TD+'">'+pr+'</td>'+
      '<td style="padding:16px 20px;text-align:center">'+badge(u.status)+'</td>'+
      '<td style="padding:16px 20px;text-align:right;width:36px">'+arrow+'</td>'+
      '</tr>';
  }

  function buildSection(units) {
    var fr = units.filter(function(u){var s=(u.status||'').toLowerCase();return s==='frei'||s==='';});
    var re = units.filter(function(u){return(u.status||'').toLowerCase()==='reserviert';});
    var vk = units.filter(function(u){return(u.status||'').toLowerCase()==='verkauft';});
    var av = fr.concat(re);
    if(!units.length) return '';

    /* Collect filter types from available units */
    var types=[];
    av.forEach(function(u){if(u.unit_type&&types.indexOf(u.unit_type)===-1)types.push(u.unit_type);});
    types.sort();

    var thead = '<thead><tr>'+
      '<th style="'+thStyle('left')+'">Einheit</th>'+
      '<th style="'+thStyle('left')+'">Typ</th>'+
      '<th style="'+thStyle('center')+'">Zimmer</th>'+
      '<th style="'+thStyle('right')+'">Fl\u00E4che</th>'+
      '<th style="'+thStyle('right')+'">Kaufpreis</th>'+
      '<th style="'+thStyle('center')+'">Status</th>'+
      '<th style="width:36px;border-bottom:2px solid '+TD+'"></th></tr></thead>';

    var chipBase = 'display:inline-flex;align-items:center;padding:8px 20px;border-radius:100px;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.25s ease;margin:0 6px 8px 0;border:1.5px solid ';

    /* Outer wrapper — matches site container */
    var h = '<section id="sr-units-section" style="padding:48px 0 64px;font-family:Outfit,system-ui,sans-serif">';
    h += '<div style="max-width:1440px;margin:0 auto;padding:0 64px">';

    /* ── Verfügbare Einheiten ── */
    h += '<div style="margin-bottom:64px">';
    h += '<div style="display:flex;align-items:baseline;gap:14px;margin-bottom:8px;flex-wrap:wrap">';
    h += '<h2 class="text-xl font-bold" style="font-family:Outfit,system-ui,sans-serif;font-size:clamp(24px,3.5vw,36px);font-weight:800;color:'+TD+';letter-spacing:-0.5px;margin:0">Verf\u00FCgbare Einheiten</h2>';
    h += '<span style="font-size:14px;color:'+TM+';font-weight:500">'+av.length+' von '+(units.length)+' Einheiten</span></div>';
    h += '<div style="width:48px;height:3px;background:'+A+';margin-bottom:32px;border-radius:2px"></div>';

    /* Filter chips */
    if(types.length > 1) {
      h += '<div style="margin-bottom:24px;display:flex;flex-wrap:wrap">';
      h += '<button class="sr-chip active" data-filter="all" style="'+chipBase+TD+';background:'+TD+';color:#fff">Alle</button>';
      types.forEach(function(t){
        h += '<button class="sr-chip" data-filter="'+t+'" style="'+chipBase+BD+';background:transparent;color:'+TD+'">'+t+'</button>';
      });
      h += '</div>';
    }

    /* Table */
    h += '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.04)">';
    h += '<table id="sr-avail-table" style="width:100%;border-collapse:collapse;min-width:700px">'+thead+'<tbody>';
    av.forEach(function(u){h+=rowHtml(u,false);});
    h += '</tbody></table></div>';
    if(!av.length) h+='<p style="text-align:center;padding:48px;color:'+TM+';font-size:16px">Derzeit keine Einheiten verf\u00FCgbar.</p>';
    h += '</div>';

    /* ── Verkaufte Einheiten ── */
    if(vk.length>0){
      h+='<div>';
      h+='<div style="display:flex;align-items:baseline;gap:14px;margin-bottom:8px;flex-wrap:wrap">';
      h+='<h2 class="text-xl font-bold" style="font-family:Outfit,system-ui,sans-serif;font-size:clamp(24px,3.5vw,36px);font-weight:800;color:'+TD+';letter-spacing:-0.5px;margin:0">Verkaufte Einheiten</h2>';
      h+='<span style="font-size:14px;color:'+TM+';font-weight:500">'+vk.length+' Einheiten</span></div>';
      h+='<div style="width:48px;height:3px;background:'+SB+';margin-bottom:32px;border-radius:2px"></div>';
      h+='<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.04)">';
      h+='<table style="width:100%;border-collapse:collapse;min-width:700px">'+thead+'<tbody>';
      vk.forEach(function(u){h+=rowHtml(u,true);});
      h+='</tbody></table></div></div>';
    }

    h += '</div>'; /* close container */
    h += '</section>';
    return h;
  }

  /* ── Fix "150.00 m²" → "150 m²" and "4.0 Zimmer" → "4 Zimmer" on listing cards ── */
  function fixListingCardFormatting() {
    if (isDetailPage()) return;
    var allEls = document.querySelectorAll('*');
    for (var i = 0; i < allEls.length; i++) {
      var el = allEls[i];
      if (el.children.length > 0) continue;
      if (el.dataset.srFmt === '1') continue;
      var txt = el.textContent.trim();
      /* Fix "150.00 m²" → "150 m²" */
      if (/^\d+\.\d+\s*m/.test(txt)) {
        el.textContent = txt.replace(/(\d+)\.\d+(\s*m)/, '$1$2');
        el.dataset.srFmt = '1';
      }
      /* Fix "4.0 Zimmer" → "4 Zimmer" */
      if (/^\d+\.\d+\s*Zimmer/.test(txt)) {
        el.textContent = txt.replace(/(\d+)\.\d+(\s*Zimmer)/, '$1$2');
        el.dataset.srFmt = '1';
      }
    }
  }

  /* Listing highlights now handled by patchListingCardStats — adds to the stats row */

  /* ── Patch listing card stats: add Bäder, Garten etc. ── */
  function patchListingCardStats() {
    if (isDetailPage()) return;
    if (!Object.keys(propData).length) return;

    /* Strategy: find every leaf-span containing "m²" that's inside a listing card,
       then walk up to the stats row (its parent) and patch it.
       This is far more robust than matching specific Tailwind class names. */
    var spans = document.querySelectorAll('span');
    for (var si = 0; si < spans.length; si++) {
      var sp = spans[si];
      var spTxt = sp.textContent.trim();
      if (spTxt.indexOf('m\u00B2') === -1) continue;
      /* Must be a short stat like "150 m²" or "74 m²", not a long description */
      if (spTxt.length > 20) continue;

      /* The stats row is the parent of these spans */
      var row = sp.parentElement;
      if (!row || row.tagName !== 'DIV') continue;
      if (row.dataset.srStats === '1') continue;
      /* Skip the detail-page stats grid (it has our injected sr-stats-grid) */
      if (row.id === 'sr-stats-grid' || row.closest('#sr-stats-grid')) continue;
      /* Must have a few child spans (the existing stats like "m²", "Zimmer") */
      if (row.children.length < 1 || row.children.length > 6) continue;

      /* Walk up to card container for text matching */
      var card = row;
      for (var up = 0; up < 8; up++) {
        if (!card.parentElement) break;
        card = card.parentElement;
        var ccl = card.className || '';
        if (ccl.indexOf('cursor-pointer') !== -1 || ccl.indexOf('group') !== -1 || ccl.indexOf('hover-lift') !== -1) break;
        if (card.tagName === 'BODY' || card.id === 'root') break;
      }
      var cardText = card.textContent || '';

      /* Match to property by address or project_name */
      var matchedId = null;
      var allIds = Object.keys(propData);
      for (var pi = 0; pi < allIds.length; pi++) {
        var p = propData[allIds[pi]];
        if (p.address && cardText.indexOf(p.address) !== -1) { matchedId = p.id; break; }
        if (p.project_name && cardText.indexOf(p.project_name) !== -1) { matchedId = p.id; break; }
      }
      if (!matchedId) continue;

      var prop = propData[matchedId];
      var features = propFeatures[matchedId] || [];
      var existing = row.textContent;

      /* Build extras — skip what's already shown */
      var extras = [];
      if (prop.bathrooms && parseInt(prop.bathrooms) > 0 && existing.indexOf('Bad') === -1) {
        extras.push(parseInt(prop.bathrooms) + ' Bad');
      }
      features.forEach(function(f) {
        if (extras.length < 2 && existing.indexOf(f) === -1) extras.push(f);
      });
      if (!extras.length) { row.dataset.srStats = '1'; continue; }

      /* Clone style from existing span in the row */
      var template = row.querySelector('span');
      extras.forEach(function(ex) {
        var span = document.createElement('span');
        span.className = template ? template.className : 'flex items-center gap-1.5 text-xs font-medium';
        span.style.cssText = template ? template.style.cssText : 'color:rgb(154,149,140)';
        span.textContent = ex;
        row.appendChild(span);
      });
      row.dataset.srStats = '1';
    }
  }

  /* ── "ab" prefix for listing cards ── */
  function addAbPrefix() {
    var keys = Object.keys(newbuildProps);
    if (!keys.length) return;
    var allEls = document.querySelectorAll('*');
    for (var i = 0; i < allEls.length; i++) {
      var el = allEls[i];
      if (el.closest('#sr-units-section')) continue;
      if (el.dataset.srAb === '1') continue;
      if (el.children.length > 0) continue;
      var txt = el.textContent.trim();
      if (!txt.match(/^EUR[\s\u00A0]+[\d\s\u2009.]+$/)) continue;
      if (txt.indexOf('ab') === 0) continue;
      var numStr = txt.replace(/EUR[\s\u00A0]+/, '').replace(/[\s\u2009.]/g, '');
      var num = parseInt(numStr, 10);
      if (isNaN(num)) continue;
      for (var j = 0; j < keys.length; j++) {
        var nb = newbuildProps[keys[j]];
        if (nb && nb.price && Math.abs(Math.round(nb.price) - num) < 2) {
          el.textContent = 'ab ' + txt;
          el.dataset.srAb = '1';
          break;
        }
      }
    }
  }

  /* ── Fetch interceptor ── */
  var _fetch = window.fetch;
  window.fetch = function() {
    var url = typeof arguments[0]==='string' ? arguments[0] : '';
    return _fetch.apply(this, arguments).then(function(r) {
      if (url.indexOf('/api/website/properties') !== -1 && url.indexOf('/property/') === -1) {
        r.clone().json().then(function(d) {
          if (d.properties) {
            d.properties.forEach(function(p) {
              propMap[p.ref_id] = p.id;
              if (p.project_name) propMap[p.project_name] = p.id;
              propCategoryMap[p.id] = p.property_category;
              if (p.highlights) propHighlights[p.id] = p.highlights;
              if (p.features && p.features.length) propFeatures[p.id] = p.features;
              propData[p.id] = p; /* store full property for card stats */
              if (p.property_category === 'newbuild') {
                newbuildProps[p.project_name || p.ref_id] = { id: p.id, price: p.price };
              }
            });
            /* Batch DOM patches at staggered intervals */
            [600, 2000, 4000].forEach(function(ms) {
              setTimeout(function() {
                addAbPrefix();
                patchListingCardStats();
                fixListingCardFormatting();
              }, ms);
            });
          }
        }).catch(function(e){ console.warn('SR interceptor:', e); });
      }
      return r;
    });
  };

  function findPropId() {
    var spans = document.querySelectorAll('span');
    for (var i = 0; i < spans.length; i++) {
      var txt = spans[i].textContent.trim();
      var m = txt.match(/^Ref:\s*(.+)/);
      if (m && propMap[m[1]]) return propMap[m[1]];
    }
    var headings = document.querySelectorAll('h1, h2');
    for (var j = 0; j < headings.length; j++) {
      var t = headings[j].textContent.trim();
      if (propMap[t]) return propMap[t];
    }
    return null;
  }

  function isDetailPage() {
    var h2s = document.querySelectorAll('h2');
    var has = {desc:false,det:false};
    h2s.forEach(function(h){
      var t = h.textContent.trim();
      if(t==='Beschreibung') has.desc=true;
      if(t==='Details') has.det=true;
    });
    return has.desc && has.det;
  }

  function isNewbuildDetail() {
    var id = findPropId();
    if (!id) return false;
    var keys = Object.keys(newbuildProps);
    for (var i = 0; i < keys.length; i++) {
      if (newbuildProps[keys[i]].id === id) return true;
    }
    return false;
  }

  /* ── Build extra description sections (Lage, Ausstattung-Detail, Sonstiges) ── */
  function buildDescriptions(prop) {
    var sections = [];
    if (prop.location_description) {
      sections.push({title: 'Lage', text: prop.location_description});
    }
    if (prop.equipment_description) {
      sections.push({title: 'Ausstattung im Detail', text: prop.equipment_description});
    }
    if (prop.other_description) {
      sections.push({title: 'Sonstiges', text: prop.other_description});
    }
    if (!sections.length) return '';

    var h = '';
    sections.forEach(function(sec) {
      h += '<div style="margin-top:32px">';
      h += '<h2 class="text-xl font-bold" style="font-size:20px;font-weight:700;color:'+TD+';margin-bottom:12px">'+escHtml(sec.title)+'</h2>';
      h += '<p style="font-size:15px;line-height:1.7;color:'+TM+';max-width:70ch;white-space:pre-line">'+escHtml(sec.text)+'</p>';
      h += '</div>';
    });
    return h;
  }

  /* ── Build comprehensive Objektdaten section ── */
  function fmtPrice(v) {
    if (!v || v <= 0) return null;
    return Math.round(parseFloat(v)).toLocaleString('de-AT') + ' \u20AC';
  }
  function fmtArea(v) {
    if (!v || parseFloat(v) <= 0) return null;
    return parseFloat(v).toFixed(0).replace('.', ',') + ' m\u00B2';
  }
  function fmtNum(v) {
    if (!v || parseFloat(v) <= 0) return null;
    var n = parseFloat(v);
    return n === Math.floor(n) ? n.toString() : n.toFixed(1).replace('.', ',');
  }

  function buildObjektdaten(p) {
    /* Collect all displayable data in categories */
    var groups = [];

    /* ── Eckdaten ── */
    var eck = [];
    if (p.ref_id) eck.push({l:'Objektnummer', v:p.ref_id});
    if (p.object_type || p.type) eck.push({l:'Objekttyp', v:p.object_type || p.type});
    if (p.realty_condition || p.condition_note) eck.push({l:'Zustand', v:p.realty_condition || p.condition_note});
    if (p.quality) eck.push({l:'Qualit\u00E4t', v:p.quality.charAt(0).toUpperCase() + p.quality.slice(1)});
    if (p.marketing_type) eck.push({l:'Vermarktung', v:p.marketing_type === 'kauf' ? 'Kauf' : p.marketing_type === 'miete' ? 'Miete' : p.marketing_type});
    if (p.available_text) eck.push({l:'Beziehbar ab', v:p.available_text});
    else if (p.available_from && p.available_from !== '0000-00-00') eck.push({l:'Beziehbar ab', v:p.available_from});
    if (eck.length) groups.push({title:'Eckdaten', items:eck});

    /* ── Flächen ── */
    var fl = [];
    if (fmtArea(p.living_area)) fl.push({l:'Wohnfl\u00E4che', v:fmtArea(p.living_area)});
    if (p.area_range) fl.push({l:'Wohnfl\u00E4chen', v:p.area_range});
    if (fmtArea(p.total_area) && p.total_area != p.living_area) fl.push({l:'Gesamtfl\u00E4che', v:fmtArea(p.total_area)});
    if (fmtArea(p.free_area)) fl.push({l:'Freifl\u00E4che', v:fmtArea(p.free_area)});
    if (fmtArea(p.area_garden)) fl.push({l:'Garten', v:fmtArea(p.area_garden)});
    if (fmtArea(p.area_terrace)) fl.push({l:'Terrasse', v:fmtArea(p.area_terrace)});
    if (fmtArea(p.area_balcony)) fl.push({l:'Balkon', v:fmtArea(p.area_balcony)});
    if (fmtArea(p.area_loggia)) fl.push({l:'Loggia', v:fmtArea(p.area_loggia)});
    if (fmtArea(p.area_basement)) fl.push({l:'Keller', v:fmtArea(p.area_basement)});
    if (fmtArea(p.area_garage)) fl.push({l:'Garage', v:fmtArea(p.area_garage)});
    if (fl.length) groups.push({title:'Fl\u00E4chen', items:fl});

    /* ── Zimmer & Aufteilung ── */
    var zi = [];
    var roomsVal = p.rooms_range || fmtNum(p.rooms_amount || p.rooms);
    if (roomsVal) zi.push({l:'Zimmer', v:roomsVal});
    if (fmtNum(p.bedrooms)) zi.push({l:'Schlafzimmer', v:fmtNum(p.bedrooms)});
    if (fmtNum(p.bathrooms)) zi.push({l:'Badezimmer', v:fmtNum(p.bathrooms)});
    if (fmtNum(p.toilets)) zi.push({l:'WC', v:fmtNum(p.toilets)});
    if (p.floor_number) zi.push({l:'Stockwerk', v:p.floor_number + (p.floor_count ? ' von ' + p.floor_count : '')});
    else if (fmtNum(p.floor_count)) zi.push({l:'Stockwerke', v:fmtNum(p.floor_count)});
    if (p.orientation) zi.push({l:'Ausrichtung', v:p.orientation});
    if (zi.length) groups.push({title:'Zimmer & Aufteilung', items:zi});

    /* ── Ausstattung ── */
    var au = [];
    if (p.flooring) au.push({l:'Boden', v:p.flooring});
    if (p.heating) au.push({l:'Heizung', v:p.heating});
    if (p.kitchen_type) au.push({l:'K\u00FCche', v:p.kitchen_type === 'offen' ? 'Offene K\u00FCche' : p.kitchen_type});
    if (p.bathroom_equipment) au.push({l:'Badezimmer', v:p.bathroom_equipment});
    if (p.furnishing) au.push({l:'M\u00F6blierung', v:p.furnishing});
    if (p.parking_type) au.push({l:'Stellpl\u00E4tze', v:p.parking_type});
    else if (fmtNum(p.parking_spaces) || fmtNum(p.garage_spaces)) {
      var pk = [];
      if (p.garage_spaces > 0) pk.push(p.garage_spaces + ' Garage');
      if (p.parking_spaces > 0) pk.push(p.parking_spaces + ' Stellplatz');
      au.push({l:'Stellpl\u00E4tze', v:pk.join(', ')});
    }
    var extras = [];
    if (p.has_fitted_kitchen) extras.push('Einbauk\u00FCche');
    if (p.has_air_conditioning) extras.push('Klimaanlage');
    if (p.has_pool) extras.push('Pool');
    if (p.has_sauna) extras.push('Sauna');
    if (p.has_fireplace) extras.push('Kamin');
    if (p.has_alarm) extras.push('Alarmanlage');
    if (p.has_barrier_free) extras.push('Barrierefrei');
    if (p.has_guest_wc) extras.push('G\u00E4ste-WC');
    if (p.has_storage_room) extras.push('Abstellraum');
    if (p.has_washing_connection) extras.push('Waschanschluss');
    if (extras.length) au.push({l:'Extras', v:extras.join(', ')});
    if (au.length) groups.push({title:'Ausstattung', items:au});

    /* ── Bau & Energie ── */
    var en = [];
    if (p.construction_year) en.push({l:'Baujahr', v:p.construction_year});
    if (p.year_renovated) en.push({l:'Renoviert', v:p.year_renovated});
    if (p.energy_certificate) en.push({l:'Energieausweis', v:p.energy_certificate});
    if (p.heating_demand_value) {
      var hdv = parseFloat(p.heating_demand_value).toFixed(1).replace('.', ',') + ' kWh/m\u00B2a';
      if (p.heating_demand_class) hdv += ' (Klasse ' + p.heating_demand_class + ')';
      en.push({l:'Heizw\u00E4rmebedarf', v:hdv});
    }
    if (p.energy_efficiency_value) en.push({l:'fGEE', v:parseFloat(p.energy_efficiency_value).toFixed(2).replace('.', ',')});
    if (p.energy_type) en.push({l:'Energietr\u00E4ger', v:p.energy_type});
    if (en.length) groups.push({title:'Bau & Energie', items:en});

    /* ── Kosten ── */
    var ko = [];
    if (fmtPrice(p.purchase_price)) ko.push({l:'Kaufpreis', v:fmtPrice(p.purchase_price)});
    if (p.price_range) ko.push({l:'Preisspanne', v:p.price_range});
    if (fmtPrice(p.price_per_m2)) ko.push({l:'Preis/m\u00B2', v:fmtPrice(p.price_per_m2)});
    if (fmtPrice(p.operating_costs)) ko.push({l:'Betriebskosten', v:fmtPrice(p.operating_costs) + '/Monat'});
    if (fmtPrice(p.maintenance_reserves)) ko.push({l:'R\u00FCcklage', v:fmtPrice(p.maintenance_reserves) + '/Monat'});
    if (p.buyer_commission_percent) ko.push({l:'K\u00E4uferprovision', v:parseFloat(p.buyer_commission_percent).toFixed(1).replace('.', ',') + '% zzgl. USt.'});
    if (ko.length) groups.push({title:'Kosten', items:ko});

    if (!groups.length) return '';

    /* ── Render ── */
    var h = '<div id="sr-objektdaten" style="margin-top:48px;font-family:Outfit,system-ui,sans-serif">';
    h += '<h2 style="font-size:clamp(22px,3vw,28px);font-weight:800;color:'+TD+';letter-spacing:-0.3px;margin-bottom:8px">Objektdaten</h2>';
    h += '<div style="width:48px;height:3px;background:'+A+';margin-bottom:32px;border-radius:2px"></div>';
    h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:32px">';

    groups.forEach(function(g) {
      h += '<div>';
      h += '<h3 style="font-size:13px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:'+A+';margin-bottom:16px">'+g.title+'</h3>';
      g.items.forEach(function(item) {
        h += '<div style="display:flex;justify-content:space-between;align-items:baseline;padding:10px 0;border-bottom:1px solid '+BD+'">';
        h += '<span style="font-size:14px;color:'+TM+';font-weight:500">'+item.l+'</span>';
        h += '<span style="font-size:14px;color:'+TD+';font-weight:600;text-align:right;max-width:60%;word-break:break-word">'+item.v+'</span>';
        h += '</div>';
      });
      h += '</div>';
    });

    h += '</div></div>';
    return h;
  }

  /* ── SVG line icons (24x24, stroke-only, elegant thin lines) ── */
  var IC = {
    area: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 9h18M9 3v18"/></svg>',
    rooms: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 12h8V3"/></svg>',
    bath: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16a1 1 0 0 1 1 1v3a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-3a1 1 0 0 1 1-1z"/><path d="M6 12V5a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v1"/></svg>',
    garden: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V10"/><path d="M8 22h8"/><path d="M12 10c-3 0-6-2.5-6-5.5S9 2 12 2s6 0 6 2.5S15 10 12 10z"/></svg>',
    terrace: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V11"/><path d="M19 21V11"/><path d="M3 11h18"/><path d="M12 11V3"/><path d="M8 7h8"/></svg>',
    balcony: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h18"/><path d="M4 14v7"/><path d="M20 14v7"/><path d="M12 14v7"/><rect x="6" y="3" width="12" height="11" rx="1"/></svg>',
    loggia: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v16"/><path d="M3 14h18"/><path d="M8 14v7"/><path d="M16 14v7"/></svg>',
    parking: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9 17V7h4a3 3 0 0 1 0 6H9"/></svg>',
    year: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
    floor: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="1"/><path d="M4 8h16M4 14h16"/></svg>',
    energy: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
    totalarea: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="'+TM+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 3H3v18h18V3z"/><path d="M3 3l18 18"/></svg>',
    download: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    pdf: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
    doc: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'
  };

  /* ── Patch stats grid: always 4 items with smart fallbacks ── */
  function patchStatsGridSmart(prop) {
    var candidates = [];
    /* Build prioritized list of stat items */
    var area = prop.area_range || fmtArea(prop.living_area);
    if (area) candidates.push({icon:IC.area, label:'Wohnfl\u00E4che', value:area});
    var rooms = prop.rooms_range || fmtNum(prop.rooms_amount || prop.rooms);
    if (rooms) candidates.push({icon:IC.rooms, label:'Zimmer', value:rooms});
    if (fmtNum(prop.bathrooms)) candidates.push({icon:IC.bath, label:'B\u00E4der', value:fmtNum(prop.bathrooms)});
    if (fmtArea(prop.area_garden)) candidates.push({icon:IC.garden, label:'Garten', value:fmtArea(prop.area_garden)});
    if (fmtArea(prop.area_terrace)) candidates.push({icon:IC.terrace, label:'Terrasse', value:fmtArea(prop.area_terrace)});
    if (fmtArea(prop.area_balcony)) candidates.push({icon:IC.balcony, label:'Balkon', value:fmtArea(prop.area_balcony)});
    if (fmtArea(prop.area_loggia)) candidates.push({icon:IC.loggia, label:'Loggia', value:fmtArea(prop.area_loggia)});
    if (prop.parking_spaces > 0 || prop.garage_spaces > 0) {
      var pv = (parseInt(prop.garage_spaces)||0) + (parseInt(prop.parking_spaces)||0);
      candidates.push({icon:IC.parking, label:'Stellpl\u00E4tze', value:pv.toString()});
    }
    if (prop.construction_year) candidates.push({icon:IC.year, label:'Baujahr', value:prop.construction_year.toString()});
    if (prop.floor_number) candidates.push({icon:IC.floor, label:'Stockwerk', value:prop.floor_number.toString()});
    if (prop.energy_certificate) candidates.push({icon:IC.energy, label:'Energie', value:prop.energy_certificate});
    if (fmtArea(prop.total_area) && prop.total_area != prop.living_area) candidates.push({icon:IC.totalarea, label:'Gesamtfl\u00E4che', value:fmtArea(prop.total_area)});

    if (candidates.length < 1) return;

    /* Always show exactly 4 */
    while (candidates.length < 4 && candidates.length > 0) candidates.push(candidates[candidates.length-1]);
    var show = candidates.slice(0, 4);

    /* Find and replace the stats grid */
    var grids = document.querySelectorAll('div[class*="grid-cols-2"][class*="grid-cols-4"]');
    if (!grids.length) return;
    var grid = grids[0];
    grid.innerHTML = '';
    show.forEach(function(item) {
      var cell = document.createElement('div');
      cell.className = 'text-center';
      cell.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:6px';
      cell.innerHTML =
        '<div style="opacity:0.6">' + item.icon + '</div>' +
        '<div style="font-size:20px;font-weight:700;color:'+TD+';line-height:1.2">' + item.value + '</div>' +
        '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:'+TM+'">' + item.label + '</div>';
      grid.appendChild(cell);
    });
    /* Mark as patched and fade in */
    grid.dataset.srPatched = '1';
    grid.style.opacity = '1';
  }

  /* ── Build downloads section ── */
  function buildDownloads(downloads) {
    if (!downloads || !downloads.length) return '';

    var h = '<div id="sr-downloads" style="margin-top:48px;font-family:Outfit,system-ui,sans-serif">';
    h += '<h2 style="font-size:clamp(22px,3vw,28px);font-weight:800;color:'+TD+';letter-spacing:-0.3px;margin-bottom:8px">Downloads</h2>';
    h += '<div style="width:48px;height:3px;background:'+A+';margin-bottom:24px;border-radius:2px"></div>';
    h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">';

    downloads.forEach(function(dl) {
      var ext = (dl.filename || '').split('.').pop().toLowerCase();
      var icon = (ext === 'pdf') ? IC.pdf : IC.doc;
      var sizeStr = '';
      if (dl.file_size) {
        var mb = dl.file_size / (1024 * 1024);
        sizeStr = mb >= 1 ? mb.toFixed(1).replace('.', ',') + ' MB' : Math.round(dl.file_size / 1024) + ' KB';
      }

      h += '<a href="' + dl.url + '" target="_blank" rel="noopener" download';
      h += ' style="display:flex;align-items:center;gap:14px;padding:16px 20px;border-radius:10px;border:1px solid '+BD+';background:#fff;text-decoration:none;transition:all 0.2s ease;cursor:pointer"';
      h += ' onmouseenter="this.style.borderColor=\''+A+'\';this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.06)\'"';
      h += ' onmouseleave="this.style.borderColor=\''+BD+'\';this.style.boxShadow=\'none\'">';
      h += '<div style="flex-shrink:0;color:'+A+'">' + icon + '</div>';
      h += '<div style="flex:1;min-width:0">';
      h += '<div style="font-size:14px;font-weight:600;color:'+TD+';white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + (dl.label || dl.filename) + '</div>';
      if (sizeStr) h += '<div style="font-size:12px;color:'+TM+';margin-top:2px">' + ext.toUpperCase() + ' · ' + sizeStr + '</div>';
      h += '</div>';
      h += '<div style="flex-shrink:0;color:'+TM+';transition:color 0.2s">' + IC.download + '</div>';
      h += '</a>';
    });

    h += '</div></div>';
    return h;
  }

  function doInjectDescriptions(propId) {
    if (descriptionsInjected) return;
    var old = document.getElementById('sr-extra-descriptions');
    if (old) old.remove();

    fetch(API + '/property/' + propId)
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function(d){
        if(!d.success||!d.property) return;
        var prop = d.property;

        /* Patch stats grid — always 4 smart items, do immediately + retry */
        patchStatsGridSmart(prop);
        setTimeout(function(){ patchStatsGridSmart(prop); }, 800);

        /* Init lightbox and attach to gallery images */
        initLightbox();
        var allUrls = [];
        if (prop.images && prop.images.length) {
          prop.images.forEach(function(img) {
            var u = typeof img === 'string' ? img : img.url;
            if (u && allUrls.indexOf(u) === -1) allUrls.push(u);
          });
        }
        if (allUrls.length > 0) {
          setTimeout(function(){ attachGalleryClicks(allUrls); }, 500);
          setTimeout(function(){ attachGalleryClicks(allUrls); }, 2000);
        }

        var descHtml = buildDescriptions(prop);
        var objektHtml = buildObjektdaten(prop);
        var downloadsHtml = buildDownloads(prop.downloads);

        if (!descHtml && !objektHtml && !downloadsHtml) return;

        var wrap = document.createElement('div');
        wrap.id = 'sr-extra-descriptions';
        wrap.innerHTML = (descHtml || '') + (objektHtml || '') + (downloadsHtml || '');

        /* Find the "Details" h2 and insert before it, or after Beschreibung */
        var h2s = document.querySelectorAll('h2');
        var detailsH2 = null;
        var beschreibungP = null;
        for (var i = 0; i < h2s.length; i++) {
          var txt = h2s[i].textContent.trim();
          if (txt === 'Beschreibung') beschreibungP = h2s[i].nextElementSibling;
          if (txt === 'Details' && !h2s[i].closest('#sr-extra-descriptions') && !h2s[i].closest('#sr-units-section')) detailsH2 = h2s[i];
        }

        /* Hide the old sparse "Details" section from React */
        if (detailsH2) {
          /* Hide the Details h2 and all siblings until next h2 or end */
          var el = detailsH2;
          while (el) {
            var next = el.nextElementSibling;
            el.style.display = 'none';
            if (next && (next.tagName === 'H2' || next.id === 'sr-extra-descriptions')) break;
            el = next;
          }
          detailsH2.parentNode.insertBefore(wrap, detailsH2);
        } else if (beschreibungP) {
          beschreibungP.parentNode.insertBefore(wrap, beschreibungP.nextSibling);
        }

        descriptionsInjected = true;

        /* Animate in */
        wrap.style.opacity='0';
        wrap.style.transition='opacity 0.5s ease';
        requestAnimationFrame(function(){requestAnimationFrame(function(){
          wrap.style.opacity='1';
        });});
      })
      .catch(function(e){console.error('SR Descriptions:',e);});
  }

  function doInject(propId) {
    if (injected) return;
    var old = document.getElementById('sr-units-section');
    if (old) old.remove();

    fetch(API + '/property/' + propId)
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function(d){
        if(!d.success||!d.property||!d.property.units||!d.property.units.length) return;
        /* Only wohn-units, no parking */
        var html = buildSection(d.property.units);
        if(!html) return;

        /* Hide old "Verfügbare Einheiten" progress bar from the React app */
        document.querySelectorAll('h2').forEach(function(h){
          if(h.textContent.trim()==='Verf\u00FCgbare Einheiten' && !h.closest('#sr-units-section')){
            var p = h;
            if(p){ p.style.display='none';
            var ns = p.nextElementSibling; if(ns) ns.style.display='none'; }
          }
        });

        /* Find insertion point: AFTER the Beschreibung/Details section, BEFORE "Weitere Objekte" */
        var sections = document.querySelectorAll('section');
        var detailSection = null;
        var weitereSection = null;
        sections.forEach(function(s){
          var titles = [];
          s.querySelectorAll('h2').forEach(function(h){ titles.push(h.textContent.trim()); });
          if(titles.indexOf('Beschreibung') !== -1 || titles.indexOf('Details') !== -1) detailSection = s;
          if(titles.indexOf('Weitere Objekte') !== -1) weitereSection = s;
        });

        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var sec = wrap.firstElementChild;

        if(weitereSection) {
          /* Insert before "Weitere Objekte" section */
          weitereSection.parentElement.insertBefore(sec, weitereSection);
        } else if(detailSection && detailSection.nextElementSibling) {
          /* Insert after the detail section */
          detailSection.parentElement.insertBefore(sec, detailSection.nextElementSibling);
        } else {
          /* Fallback: append to main */
          var main = document.querySelector('main') || document.querySelector('#root > div');
          if(main) main.appendChild(sec);
        }

        injected = true;

        /* Animate in */
        sec.style.opacity='0';
        sec.style.transform='translateY(24px)';
        sec.style.transition='opacity 0.7s cubic-bezier(.4,0,.2,1),transform 0.7s cubic-bezier(.4,0,.2,1)';
        requestAnimationFrame(function(){requestAnimationFrame(function(){
          sec.style.opacity='1';sec.style.transform='translateY(0)';
        });});

        /* Row hover effects */
        sec.querySelectorAll('.sr-row').forEach(function(r){
          if(r.dataset.sold==='1') return;
          r.addEventListener('mouseenter',function(){
            this.style.background='rgba(212,116,59,0.03)';
            var a=this.querySelector('.sr-arrow');
            if(a){a.style.opacity='1';a.style.transform='translateX(0)';}
          });
          r.addEventListener('mouseleave',function(){
            this.style.background='transparent';
            var a=this.querySelector('.sr-arrow');
            if(a){a.style.opacity='0';a.style.transform='translateX(-4px)';}
          });
        });

        /* Filter chip logic */
        var chips = sec.querySelectorAll('.sr-chip');
        var rows = sec.querySelectorAll('#sr-avail-table tbody .sr-row');
        chips.forEach(function(chip){
          chip.addEventListener('click',function(){
            chips.forEach(function(c){
              c.style.background='transparent';c.style.color=TD;c.style.borderColor=BD;
              c.classList.remove('active');
            });
            this.style.background=TD;this.style.color='#fff';this.style.borderColor=TD;
            this.classList.add('active');
            var f=this.dataset.filter;
            rows.forEach(function(r){
              r.style.display=(f==='all'||r.dataset.type===f)?'':'none';
            });
          });
          chip.addEventListener('mouseenter',function(){
            if(!this.classList.contains('active')){this.style.borderColor=A;this.style.color=A;}
          });
          chip.addEventListener('mouseleave',function(){
            if(!this.classList.contains('active')){this.style.borderColor=BD;this.style.color=TD;}
          });
        });
      })
      .catch(function(e){console.error('SR Units:',e);});
  }

  function check() {
    trackHistory();
    if(isDetailPage()) {
      /* Immediately hide React's stats grid to prevent flash of old icons */
      var grids = document.querySelectorAll('div[class*="grid-cols-2"][class*="grid-cols-4"]');
      for (var gi = 0; gi < grids.length; gi++) {
        if (!grids[gi].dataset.srPatched) {
          grids[gi].style.opacity = '0';
          grids[gi].style.transition = 'opacity 0.3s ease';
        }
      }
      var id = findPropId();
      if(id) {
        /* Inject extra descriptions for ALL properties */
        if(!descriptionsInjected) doInjectDescriptions(id);
        /* Inject units table only for Neubauprojekte */
        if(isNewbuildDetail() && !injected) doInject(id);
      }
    } else {
      if(injected) {
        var old = document.getElementById('sr-units-section');
        if(old) old.remove();
        injected = false;
      }
      if(descriptionsInjected) {
        var oldDesc = document.getElementById('sr-extra-descriptions');
        if(oldDesc) oldDesc.remove();
        descriptionsInjected = false;
      }
      if(lightboxInjected) {
        var oldLb = document.getElementById('sr-lightbox');
        if(oldLb) oldLb.remove();
        lightboxInjected = false;
      }
      if(Object.keys(newbuildProps).length > 0) addAbPrefix();
      fixListingCardFormatting();
      patchListingCardStats();
    }
  }

  var timer = null;
  var obs = new MutationObserver(function(){
    if(timer) clearTimeout(timer);
    timer = setTimeout(check, 500);
  });
  obs.observe(document.body, {childList:true, subtree:true});

  setTimeout(check, 2000);
  /* Navigate to correct page if user loaded a deep URL like /immobilien */
  setTimeout(navigateFromUrl, 1000);
  /* popstate is handled by the unified listener above */

  /* ── Proactive data fetch — the fetch interceptor misses the initial
       React load because module scripts run before defer scripts ── */
  var apiFetchRetries = 0;
  function fetchPropertiesData() {
    _fetch(API + '/properties')
      .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function(d) {
        if (!d.properties) return;
        d.properties.forEach(function(p) {
          propMap[p.ref_id] = p.id;
          if (p.project_name) propMap[p.project_name] = p.id;
          propCategoryMap[p.id] = p.property_category;
          if (p.highlights) propHighlights[p.id] = p.highlights;
          if (p.features && p.features.length) propFeatures[p.id] = p.features;
          propData[p.id] = p;
          if (p.property_category === 'newbuild') {
            newbuildProps[p.project_name || p.ref_id] = { id: p.id, price: p.price };
          }
        });
        /* Batch DOM patches at staggered intervals */
        [300, 1500, 3500].forEach(function(ms) {
          setTimeout(function() {
            addAbPrefix();
            patchListingCardStats();
            fixListingCardFormatting();
          }, ms);
        });
        setTimeout(check, 500);
      })
      .catch(function(e) {
        console.error('SR proactive fetch:', e);
        /* Retry with exponential backoff (max 3 retries) */
        if (apiFetchRetries < 3) {
          apiFetchRetries++;
          setTimeout(fetchPropertiesData, apiFetchRetries * 2000);
        }
      });
  }
  fetchPropertiesData();

  /* ── Fix Impressum/Datenschutz footer links visibility & clickability ── */
  function fixFooterLegalLinks() {
    var footer = document.querySelector('footer') || document.querySelector('[class*="footer"]');
    if (!footer) return;
    var buttons = footer.querySelectorAll('button');
    buttons.forEach(function(btn) {
      var t = (btn.textContent || '').trim().toLowerCase();
      if (t === 'impressum' || t === 'datenschutz') {
        btn.style.setProperty('color', 'rgba(255,255,255,0.6)', 'important');
        btn.style.setProperty('cursor', 'pointer', 'important');
        btn.style.setProperty('text-decoration', 'underline', 'important');
        btn.style.setProperty('text-underline-offset', '3px', 'important');
        btn.style.setProperty('font-size', '13px', 'important');
        btn.style.transition = 'color 0.3s';
        btn.addEventListener('mouseenter', function() { btn.style.setProperty('color', '#fff', 'important'); });
        btn.addEventListener('mouseleave', function() { btn.style.setProperty('color', 'rgba(255,255,255,0.6)', 'important'); });
      }
    });
  }
  setTimeout(fixFooterLegalLinks, 500);
  setTimeout(fixFooterLegalLinks, 2000);
})();
