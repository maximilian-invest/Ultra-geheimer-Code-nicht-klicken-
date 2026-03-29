/**
 * SR-Homes Units Table v6 — Serhant-Style
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

  /* Attach click handlers to gallery images on detail page */
  function attachGalleryClicks(allImageUrls) {
    if (!window._srOpenLightbox || !allImageUrls.length) return;
    /* Find the gallery section — the grid with aspect-ratio images */
    var galleryImgs = document.querySelectorAll('section img[class*="object-cover"]');
    galleryImgs.forEach(function(img) {
      if (img.dataset.srLb === '1') return;
      img.dataset.srLb = '1';
      img.style.cursor = 'zoom-in';
      img.addEventListener('click', function(e) {
        e.stopPropagation();
        /* Find which index this image is */
        var src = img.src;
        var idx = 0;
        for (var i = 0; i < allImageUrls.length; i++) {
          if (allImageUrls[i] === src || src.indexOf(allImageUrls[i]) !== -1 || allImageUrls[i].indexOf(src.split('/').pop()) !== -1) {
            idx = i;
            break;
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
              if (p.property_category === 'newbuild') {
                newbuildProps[p.project_name || p.ref_id] = { id: p.id, price: p.price };
              }
            });
            setTimeout(addAbPrefix, 800);
            setTimeout(addAbPrefix, 2500);
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

  /* ── Replace stats grid values with ranges for Neubauprojekte ── */
  function patchStatsGrid(prop) {
    if (!prop.area_range && !prop.rooms_range) return;
    /* The stats grid has class "grid grid-cols-2 sm:grid-cols-4" */
    var grids = document.querySelectorAll('div[class*="grid-cols-2"][class*="grid-cols-4"]');
    grids.forEach(function(grid) {
      var cells = grid.querySelectorAll('div.text-center');
      cells.forEach(function(cell) {
        var spans = cell.querySelectorAll('span, p, div');
        spans.forEach(function(sp) {
          var t = sp.textContent.trim();
          /* Replace area value */
          if (prop.area_range && t.match(/^\d+.*m²$/)) {
            sp.textContent = prop.area_range;
          }
          /* Replace rooms value */
          if (prop.rooms_range && t.match(/^\d+$/) && parseInt(t) === (prop.rooms || prop.rooms_amount || 0)) {
            sp.textContent = prop.rooms_range;
          }
        });
      });
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

        /* Patch stats grid with ranges for Neubauprojekte */
        if (prop.area_range || prop.rooms_range) {
          setTimeout(function(){ patchStatsGrid(prop); }, 300);
          setTimeout(function(){ patchStatsGrid(prop); }, 1500);
        }

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

        var html = buildDescriptions(prop);
        if(!html) return;

        var wrap = document.createElement('div');
        wrap.id = 'sr-extra-descriptions';
        wrap.innerHTML = html;

        /* Find the "Beschreibung" h2 and its text paragraph, insert after them */
        var h2s = document.querySelectorAll('h2');
        var beschreibungP = null;
        for (var i = 0; i < h2s.length; i++) {
          if (h2s[i].textContent.trim() === 'Beschreibung') {
            /* The description text is the next sibling (a <p> tag) */
            beschreibungP = h2s[i].nextElementSibling;
            break;
          }
        }

        if (beschreibungP) {
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
      if(Object.keys(newbuildProps).length > 0) addAbPrefix();
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
