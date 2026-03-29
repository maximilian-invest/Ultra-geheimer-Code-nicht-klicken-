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

  var injected = false;
  var descriptionsInjected = false;
  var lightboxInjected = false;
  var propMap = {};
  var propCategoryMap = {};
  var newbuildProps = {};
  var propHighlights = {};
  var propFeatures = {};

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

  /* ── Gallery navigation arrows below the gallery ── */
  function injectGalleryNav(allImageUrls) {
    if (document.getElementById('sr-gallery-nav')) return;
    if (allImageUrls.length <= 1) return;

    /* Find the gallery container — search broadly, React may not use <section> */
    var gallerySection = null;

    /* Strategy 1: find large property images and walk up to their common container */
    var allImgs = document.querySelectorAll('img');
    var galleryImgs = [];
    for (var gi = 0; gi < allImgs.length; gi++) {
      var im = allImgs[gi];
      if (im.closest('#sr-units-section') || im.closest('#sr-extra-descriptions') || im.closest('#sr-objektdaten') || im.closest('#sr-lightbox') || im.closest('nav') || im.closest('footer')) continue;
      var r = im.getBoundingClientRect();
      if (r.width > 200 && r.height > 100) {
        var src = im.src || '';
        if (src.indexOf('property') !== -1 || src.indexOf('image/') !== -1 || src.indexOf('storage/') !== -1) {
          galleryImgs.push(im);
        }
      }
    }
    if (galleryImgs.length > 0) {
      /* Walk up from the first gallery image to find the wrapping container */
      var el = galleryImgs[0].parentElement;
      for (var up = 0; up < 8 && el; up++) {
        /* Good container: contains gallery images and is reasonably large */
        var elImgs = el.querySelectorAll('img');
        var elR = el.getBoundingClientRect();
        if (elImgs.length >= galleryImgs.length && elR.height > 200) {
          /* Don't go too high — stop if we hit body, main, or root */
          if (el.tagName === 'BODY' || el.tagName === 'MAIN' || el.id === 'root') break;
          gallerySection = el;
          /* Keep walking up 1 more level if it's a direct wrapper (section/div) */
          if (el.parentElement && el.parentElement.tagName !== 'BODY' && el.parentElement.tagName !== 'MAIN' && el.parentElement.id !== 'root') {
            var parentImgs = el.parentElement.querySelectorAll('img');
            var parentH2s = el.parentElement.querySelectorAll('h2');
            /* Stop if parent has h2s (that's the content section, too high) */
            if (parentH2s.length === 0 && parentImgs.length <= galleryImgs.length + 2) {
              gallerySection = el.parentElement;
            }
          }
          break;
        }
        el = el.parentElement;
      }
    }

    /* Strategy 2: find by the "1 / X" counter badge */
    if (!gallerySection) {
      var badges = document.querySelectorAll('div');
      for (var bi = 0; bi < badges.length; bi++) {
        if (/^\d+\s*\/\s*\d+$/.test(badges[bi].textContent.trim())) {
          /* Walk up a few levels to find a reasonable container */
          var parent = badges[bi].parentElement;
          for (var pu = 0; pu < 5 && parent; pu++) {
            if (parent.querySelectorAll('img').length >= 1) { gallerySection = parent; break; }
            parent = parent.parentElement;
          }
          if (gallerySection) break;
        }
      }
    }
    if (!gallerySection) return;

    var currentIdx = 0;
    var mainImg = gallerySection.querySelector('img');

    var nav = document.createElement('div');
    nav.id = 'sr-gallery-nav';
    nav.style.cssText = 'max-width:1440px;margin:0 auto;padding:8px 64px 0;display:flex;align-items:center;justify-content:space-between;font-family:Outfit,system-ui,sans-serif';

    var btnStyle = 'background:'+TD+';color:#fff;border:none;width:44px;height:44px;border-radius:50%;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:all 0.2s ease;opacity:0.8';

    nav.innerHTML =
      '<button id="sr-gal-prev" style="'+btnStyle+'">\u2039</button>' +
      '<div style="display:flex;align-items:center;gap:16px">' +
        '<span id="sr-gal-counter" style="font-size:14px;font-weight:600;color:'+TD+';letter-spacing:0.5px">1 / '+allImageUrls.length+'</span>' +
        '<div id="sr-gal-dots" style="display:flex;gap:6px"></div>' +
        '<button id="sr-gal-expand" style="background:none;border:1.5px solid '+BD+';color:'+TD+';padding:6px 16px;border-radius:100px;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;transition:all 0.2s">Alle anzeigen</button>' +
      '</div>' +
      '<button id="sr-gal-next" style="'+btnStyle+'">\u203A</button>';

    gallerySection.parentNode.insertBefore(nav, gallerySection.nextSibling);

    /* Dots */
    var dotsEl = document.getElementById('sr-gal-dots');
    allImageUrls.forEach(function(_, di) {
      var dot = document.createElement('div');
      dot.style.cssText = 'width:8px;height:8px;border-radius:50%;background:'+(di===0?A:BD)+';transition:all 0.3s;cursor:pointer';
      dot.dataset.idx = di;
      dot.addEventListener('click', function() { goTo(parseInt(this.dataset.idx)); });
      dotsEl.appendChild(dot);
    });

    function updateDots() {
      var dots = dotsEl.children;
      for (var d = 0; d < dots.length; d++) {
        dots[d].style.background = (d === currentIdx) ? A : BD;
        dots[d].style.transform = (d === currentIdx) ? 'scale(1.3)' : 'scale(1)';
      }
    }

    function goTo(idx) {
      if (idx < 0) idx = allImageUrls.length - 1;
      if (idx >= allImageUrls.length) idx = 0;
      currentIdx = idx;

      /* Update the main gallery image */
      if (mainImg) {
        mainImg.style.opacity = '0.5';
        mainImg.style.transition = 'opacity 0.3s ease';
        setTimeout(function() {
          mainImg.src = allImageUrls[currentIdx];
          mainImg.onload = function() { mainImg.style.opacity = '1'; };
        }, 150);
      }

      document.getElementById('sr-gal-counter').textContent = (currentIdx + 1) + ' / ' + allImageUrls.length;
      updateDots();
    }

    document.getElementById('sr-gal-prev').addEventListener('click', function() { goTo(currentIdx - 1); });
    document.getElementById('sr-gal-next').addEventListener('click', function() { goTo(currentIdx + 1); });
    document.getElementById('sr-gal-expand').addEventListener('click', function() {
      if (window._srOpenLightbox) window._srOpenLightbox(allImageUrls, currentIdx);
    });

    /* Hover effects */
    ['sr-gal-prev','sr-gal-next'].forEach(function(id) {
      var el = document.getElementById(id);
      el.addEventListener('mouseenter', function() { this.style.opacity = '1'; this.style.background = A; });
      el.addEventListener('mouseleave', function() { this.style.opacity = '0.8'; this.style.background = TD; });
    });
    var expandBtn = document.getElementById('sr-gal-expand');
    expandBtn.addEventListener('mouseenter', function() { this.style.borderColor = A; this.style.color = A; });
    expandBtn.addEventListener('mouseleave', function() { this.style.borderColor = BD; this.style.color = TD; });
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

  /* ── Inject highlights/features on listing overview cards ── */
  function injectListingHighlights() {
    if (isDetailPage()) return;
    /* Find all listing cards — they are typically <a> or <div> with links to property details */
    var cards = document.querySelectorAll('a[href*="/immobilien/"], a[href*="/property/"], a[href*="/objekt/"]');
    if (!cards.length) {
      /* Fallback: look for cards containing property images and price text */
      cards = document.querySelectorAll('[class*="group"]');
    }
    cards.forEach(function(card) {
      if (card.dataset.srHighlights === '1') return;
      /* Find the property ID for this card by matching text content */
      var cardText = card.textContent || '';
      var matchedId = null;
      var keys = Object.keys(propMap);
      for (var ki = 0; ki < keys.length; ki++) {
        /* Match by ref_id or project_name found in the card text */
        if (cardText.indexOf(keys[ki]) !== -1 && propMap[keys[ki]]) {
          matchedId = propMap[keys[ki]];
          break;
        }
      }
      /* Also try matching by href */
      if (!matchedId) {
        var href = card.getAttribute('href') || '';
        var hrefMatch = href.match(/\/(\d+)(?:\/|$)/);
        if (hrefMatch) {
          var hid = parseInt(hrefMatch[1]);
          if (propHighlights[hid] || propFeatures[hid]) matchedId = hid;
        }
      }
      if (!matchedId) return;

      var highlights = propHighlights[matchedId];
      var features = propFeatures[matchedId];
      if (!highlights && (!features || !features.length)) return;

      /* Build tags */
      var tags = [];
      if (features && features.length) {
        features.forEach(function(f) { if (tags.length < 6) tags.push(f); });
      }
      if (highlights && typeof highlights === 'string') {
        /* Split highlights by common delimiters */
        var parts = highlights.split(/[,;\n|•·–—]+/).map(function(s){ return s.trim(); }).filter(function(s){ return s.length > 0 && s.length < 40; });
        parts.forEach(function(p) { if (tags.length < 6 && tags.indexOf(p) === -1) tags.push(p); });
      }
      if (!tags.length) return;

      /* Find insertion point — look for the subtitle or price element at bottom of card */
      var tagsContainer = document.createElement('div');
      tagsContainer.className = 'sr-highlight-tags';
      tagsContainer.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px 6px;margin-top:8px;padding:0 2px';
      tags.forEach(function(tag) {
        var chip = document.createElement('span');
        chip.style.cssText = 'display:inline-block;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600;letter-spacing:0.3px;background:'+BG+';color:'+TM+';border:1px solid '+BD+';font-family:Outfit,system-ui,sans-serif;white-space:nowrap';
        chip.textContent = tag;
        tagsContainer.appendChild(chip);
      });

      /* Insert at the bottom of the card content area */
      var textContainer = card.querySelector('div > div:last-child') || card.querySelector('div');
      if (textContainer) {
        textContainer.appendChild(tagsContainer);
      }
      card.dataset.srHighlights = '1';
    });
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
              if (p.property_category === 'newbuild') {
                newbuildProps[p.project_name || p.ref_id] = { id: p.id, price: p.price };
              }
            });
            setTimeout(addAbPrefix, 800);
            setTimeout(addAbPrefix, 2500);
            setTimeout(injectListingHighlights, 1000);
            setTimeout(injectListingHighlights, 3000);
          }
        }).catch(function(){});
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
      h += '<h2 class="text-xl font-bold" style="font-size:20px;font-weight:700;color:'+TD+';margin-bottom:12px">'+sec.title+'</h2>';
      h += '<p style="font-size:15px;line-height:1.7;color:'+TM+';max-width:70ch;white-space:pre-line">'+sec.text+'</p>';
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

  /* ── Patch stats grid: always 4 items with smart fallbacks ── */
  function patchStatsGridSmart(prop) {
    var candidates = [];
    /* Build prioritized list of stat items */
    var area = prop.area_range || fmtArea(prop.living_area);
    if (area) candidates.push({icon:'\uD83D\uDCCF', label:'Wohnfl\u00E4che', value:area});
    var rooms = prop.rooms_range || fmtNum(prop.rooms_amount || prop.rooms);
    if (rooms) candidates.push({icon:'\uD83D\uDEAA', label:'Zimmer', value:rooms});
    if (fmtNum(prop.bathrooms)) candidates.push({icon:'\uD83D\uDEC1', label:'B\u00E4der', value:fmtNum(prop.bathrooms)});
    if (fmtArea(prop.area_garden)) candidates.push({icon:'\uD83C\uDF33', label:'Garten', value:fmtArea(prop.area_garden)});
    if (fmtArea(prop.area_terrace)) candidates.push({icon:'\u2600\uFE0F', label:'Terrasse', value:fmtArea(prop.area_terrace)});
    if (fmtArea(prop.area_balcony)) candidates.push({icon:'\uD83C\uDF05', label:'Balkon', value:fmtArea(prop.area_balcony)});
    if (fmtArea(prop.area_loggia)) candidates.push({icon:'\uD83C\uDFDB\uFE0F', label:'Loggia', value:fmtArea(prop.area_loggia)});
    if (prop.parking_spaces > 0 || prop.garage_spaces > 0) {
      var pv = (parseInt(prop.garage_spaces)||0) + (parseInt(prop.parking_spaces)||0);
      candidates.push({icon:'\uD83D\uDE97', label:'Stellpl\u00E4tze', value:pv.toString()});
    }
    if (prop.construction_year) candidates.push({icon:'\uD83C\uDFD7\uFE0F', label:'Baujahr', value:prop.construction_year.toString()});
    if (prop.floor_number) candidates.push({icon:'\u2B06\uFE0F', label:'Stockwerk', value:prop.floor_number.toString()});
    if (prop.energy_certificate) candidates.push({icon:'\u26A1', label:'Energie', value:prop.energy_certificate});
    if (fmtArea(prop.total_area) && prop.total_area != prop.living_area) candidates.push({icon:'\uD83D\uDCCA', label:'Gesamtfl\u00E4che', value:fmtArea(prop.total_area)});

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
      cell.innerHTML =
        '<div style="font-size:22px;margin-bottom:6px">' + item.icon + '</div>' +
        '<div style="font-size:20px;font-weight:700;color:'+TD+';margin-bottom:2px">' + item.value + '</div>' +
        '<div style="font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;color:'+TM+'">' + item.label + '</div>';
      grid.appendChild(cell);
    });
  }

  function doInjectDescriptions(propId) {
    if (descriptionsInjected) return;
    var old = document.getElementById('sr-extra-descriptions');
    if (old) old.remove();

    fetch(API + '/property/' + propId)
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.success||!d.property) return;
        var prop = d.property;

        /* Patch stats grid — always 4 smart items */
        setTimeout(function(){ patchStatsGridSmart(prop); }, 300);
        setTimeout(function(){ patchStatsGridSmart(prop); }, 1500);

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
          setTimeout(function(){ attachGalleryClicks(allUrls); injectGalleryNav(allUrls); }, 500);
          setTimeout(function(){ attachGalleryClicks(allUrls); injectGalleryNav(allUrls); }, 2000);
        }

        var descHtml = buildDescriptions(prop);
        var objektHtml = buildObjektdaten(prop);
        if (!descHtml && !objektHtml) return;

        var wrap = document.createElement('div');
        wrap.id = 'sr-extra-descriptions';
        wrap.innerHTML = (descHtml || '') + (objektHtml || '');

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
      .then(function(r){return r.json();})
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
    if(isDetailPage()) {
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
      var oldNav = document.getElementById('sr-gallery-nav');
      if(oldNav) oldNav.remove();
      if(Object.keys(newbuildProps).length > 0) addAbPrefix();
      injectListingHighlights();
    }
  }

  var timer = null;
  var obs = new MutationObserver(function(){
    if(timer) clearTimeout(timer);
    timer = setTimeout(check, 500);
  });
  obs.observe(document.body, {childList:true, subtree:true});

  setTimeout(check, 2000);
  window.addEventListener('popstate', function(){ injected=false; setTimeout(check, 800); });
})();
