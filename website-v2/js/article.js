/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Blog Article Page JS (Premium Design)
   ═══════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  function fmtDate(dateStr) {
    if (!dateStr) return '';
    try {
      return new Date(dateStr).toLocaleDateString('de-AT', { day: '2-digit', month: 'long', year: 'numeric' });
    } catch (e) { return dateStr; }
  }

  function categoryLabel(cat) {
    var MAP = { ratgeber: 'Ratgeber', news: 'News & Markt' };
    return MAP[cat] || (cat ? cat.charAt(0).toUpperCase() + cat.slice(1) : 'Allgemein');
  }

  function getSlug() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('slug')) return params.get('slug');
    var path = window.location.pathname.replace(/^\/|\/$/g, '');
    if (path && path !== 'blog-article.html' && path !== 'blog-article') return path;
    return null;
  }

  /* ─── Markdown to HTML ─── */
  function markdownToHtml(md) {
    if (!md) return '';
    var lines = md.split('\n');
    var result = [];
    var inList = false, inOrderedList = false, inTable = false, tableHeader = false;

    function closeList() {
      if (inList) { result.push('</ul>'); inList = false; }
      if (inOrderedList) { result.push('</ol>'); inOrderedList = false; }
    }
    function closeTable() {
      if (inTable) { result.push('</tbody></table>'); inTable = false; tableHeader = false; }
    }
    function inlineFormat(text) {
      return text
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
    }

    for (var i = 0; i < lines.length; i++) {
      var trimmed = lines[i].trim();

      // Table
      if (trimmed.startsWith('|') && trimmed.endsWith('|')) {
        var cells = trimmed.slice(1, -1).split('|').map(function(c) { return c.trim(); });
        if (cells.every(function(c) { return /^[-:]+$/.test(c); })) { tableHeader = true; continue; }
        closeList();
        if (!inTable) {
          result.push('<table>');
          inTable = true; tableHeader = false;
          result.push('<thead><tr>' + cells.map(function(c) { return '<th>' + inlineFormat(c) + '</th>'; }).join('') + '</tr></thead><tbody>');
          continue;
        }
        if (tableHeader) {
          result.push('<tr>' + cells.map(function(c) { return '<td>' + inlineFormat(c) + '</td>'; }).join('') + '</tr>');
        }
        continue;
      } else { closeTable(); }

      if (trimmed.startsWith('#### ')) { closeList(); result.push('<h4>' + inlineFormat(trimmed.slice(5)) + '</h4>'); continue; }
      if (trimmed.startsWith('### ')) { closeList(); result.push('<h3 id="' + slugifyHeading(trimmed.slice(4)) + '">' + inlineFormat(trimmed.slice(4)) + '</h3>'); continue; }
      if (trimmed.startsWith('## ')) { closeList(); result.push('<h2 id="' + slugifyHeading(trimmed.slice(3)) + '">' + inlineFormat(trimmed.slice(3)) + '</h2>'); continue; }
      if (trimmed.startsWith('# ')) { closeList(); result.push('<h2 id="' + slugifyHeading(trimmed.slice(2)) + '">' + inlineFormat(trimmed.slice(2)) + '</h2>'); continue; }

      if (trimmed.startsWith('- ') || trimmed.startsWith('* ')) {
        closeTable();
        if (inOrderedList) { result.push('</ol>'); inOrderedList = false; }
        if (!inList) { result.push('<ul>'); inList = true; }
        result.push('<li>' + inlineFormat(trimmed.slice(2)) + '</li>');
        continue;
      }

      var orderedMatch = trimmed.match(/^\d+\.\s+(.*)/);
      if (orderedMatch) {
        closeTable();
        if (inList) { result.push('</ul>'); inList = false; }
        if (!inOrderedList) { result.push('<ol>'); inOrderedList = true; }
        result.push('<li>' + inlineFormat(orderedMatch[1]) + '</li>');
        continue;
      }

      if (trimmed === '') { closeList(); closeTable(); result.push(''); continue; }
      if (/^---+$/.test(trimmed)) { closeList(); closeTable(); result.push('<hr>'); continue; }

      closeList(); closeTable();
      result.push('<p>' + inlineFormat(trimmed) + '</p>');
    }
    closeList(); closeTable();
    return result.join('\n');
  }

  function slugifyHeading(text) {
    return text.toLowerCase()
      .replace(/[äöüÄÖÜ]/g, function(c) { return ({ä:'ae',ö:'oe',ü:'ue',Ä:'ae',Ö:'oe',Ü:'ue'})[c] || c; })
      .replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-');
  }

  /* ─── TOC ─── */
  function buildTOC(contentEl) {
    var headings = contentEl.querySelectorAll('h2');
    if (headings.length < 2) return;
    var tocWrap = document.getElementById('toc-wrap');
    var tocNav = document.getElementById('toc-nav');
    if (!tocWrap || !tocNav) return;

    var html = '';
    headings.forEach(function(h) {
      var level = h.tagName.toLowerCase();
      if (!h.id) h.id = slugifyHeading(h.textContent);
      html += '<a href="#' + h.id + '" class="toc-link">' + esc(h.textContent) + '</a>';
    });
    tocNav.innerHTML = html;
    tocWrap.classList.remove('hidden');

    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          tocNav.querySelectorAll('.toc-link').forEach(function(link) {
            link.classList.toggle('active', link.getAttribute('href') === '#' + entry.target.id);
          });
        }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });
    headings.forEach(function(h) { observer.observe(h); });
  }

  /* ─── Progress Bar ─── */
  function initProgressBar() {
    var bar = document.getElementById('reading-progress');
    if (!bar) return;
    window.addEventListener('scroll', function() {
      var doc = document.documentElement;
      var pct = doc.scrollHeight - doc.clientHeight;
      bar.style.width = (pct > 0 ? Math.min((doc.scrollTop / pct) * 100, 100) : 0) + '%';
    }, { passive: true });
  }

  /* ─── Share ─── */
  function initShare(title) {
    var wrap = document.getElementById('share-wrap');
    if (wrap) wrap.classList.remove('hidden');
    var url = window.location.href;
    var eUrl = encodeURIComponent(url);
    var eTitle = encodeURIComponent(title || '');

    var li = document.getElementById('share-linkedin');
    var wa = document.getElementById('share-whatsapp');
    var em = document.getElementById('share-email');
    var cp = document.getElementById('share-copy');

    if (li) li.addEventListener('click', function() { window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + eUrl, '_blank', 'width=600,height=500'); });
    if (wa) wa.addEventListener('click', function() { window.open('https://wa.me/?text=' + eTitle + '%20' + eUrl, '_blank'); });
    if (em) em.addEventListener('click', function() { window.location.href = 'mailto:?subject=' + eTitle + '&body=' + eTitle + '%0A%0A' + eUrl; });
    if (cp) cp.addEventListener('click', async function() {
      try {
        await navigator.clipboard.writeText(url);
        var span = document.getElementById('share-copy-text');
        if (span) { span.textContent = 'Kopiert!'; setTimeout(function() { span.textContent = 'Link kopieren'; }, 2000); }
      } catch (e) {
        var ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
      }
    });
  }

  /* ─── Tags ─── */
  function renderTags(tags) {
    if (!tags || !tags.length) return;
    var container = document.getElementById('tags-container');
    var wrap = document.getElementById('article-tags');
    if (!container || !wrap) return;
    container.innerHTML = tags.map(function(tag) {
      return '<span class="inline-block text-xs font-medium px-3 py-1.5 rounded-full" style="background:#F0ECE6;color:#5A564E">#' + esc(tag) + '</span>';
    }).join('');
    wrap.classList.remove('hidden');
  }

  /* ─── Meta Tags ─── */
  function setMeta(post) {
    document.title = (post.seo_title || post.title) + ' | SR Homes News';
    var metaDesc = document.querySelector('meta[name="description"]');
    if (metaDesc) metaDesc.content = post.meta_description || post.excerpt || post.title || '';
    function setOG(prop, val) {
      var el = document.querySelector('meta[property="' + prop + '"]');
      if (!el) { el = document.createElement('meta'); el.setAttribute('property', prop); document.head.appendChild(el); }
      el.setAttribute('content', val);
    }
    setOG('og:title', post.seo_title || post.title || '');
    setOG('og:description', post.meta_description || post.excerpt || '');
    setOG('og:type', 'article');
    setOG('og:url', window.location.href);
    var img = post.featured_image_url || post.featured_image || '';
    if (img) setOG('og:image', img);

    var canonical = document.querySelector('link[rel="canonical"]');
    if (!canonical) { canonical = document.createElement('link'); canonical.rel = 'canonical'; document.head.appendChild(canonical); }
    canonical.href = window.location.href;

    var schema = {
      '@context': 'https://schema.org', '@type': 'BlogPosting',
      headline: post.title || '', description: post.excerpt || '', url: window.location.href,
      datePublished: post.published_at || post.created_at || '',
      dateModified: post.updated_at || post.published_at || '',
      publisher: { '@type': 'Organization', name: 'SR Homes GmbH', url: 'https://www.sr-homes.at' }
    };
    if (img) schema.image = img;
    if (post.author) schema.author = { '@type': 'Person', name: post.author };
    var sd = document.getElementById('schema-blogposting');
    if (sd) sd.textContent = JSON.stringify(schema);
  }

  /* ─── Related Articles ─── */
  function renderRelated(posts, currentSlug) {
    var related = posts.filter(function(p) { return p.slug !== currentSlug; }).slice(0, 3);
    if (related.length === 0) return;
    var section = document.getElementById('related-section');
    var grid = document.getElementById('related-grid');
    if (!section || !grid) return;

    grid.innerHTML = related.map(function(post) {
      var img = post.featured_image_url || post.featured_image || '';
      var slug = post.slug || '';
      var href = slug ? '/' + esc(slug) : 'blog-article.html?slug=' + esc(slug);
      return '<a href="' + href + '" class="group flex flex-col related-card rounded-2xl overflow-hidden" style="background:#FAF8F5;border:1px solid #F0ECE6;text-decoration:none">' +
        '<div class="rel-img overflow-hidden" style="aspect-ratio:16/10">' +
          (img ? '<img src="' + esc(img) + '" alt="' + esc(post.title || '') + '" class="w-full h-full object-cover" />'
               : '<div class="w-full h-full flex items-center justify-center" style="background:#F0ECE6;min-height:180px"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#C5C0B8" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>') +
        '</div>' +
        '<div class="flex flex-col flex-1 p-5">' +
          '<span class="text-[11px] font-semibold uppercase tracking-[0.12em] mb-3 inline-block" style="color:#D4743B">' + esc(categoryLabel(post.category || '')) + '</span>' +
          '<h4 class="font-display font-semibold leading-snug mb-3 group-hover:text-accent transition-colors duration-300" style="color:#0A0A08;font-size:1.05rem">' + esc(post.title || '') + '</h4>' +
          '<span class="text-xs mt-auto" style="color:#9A958C">' + esc(fmtDate(post.published_at || post.created_at || '')) + '</span>' +
        '</div>' +
      '</a>';
    }).join('');
    section.classList.remove('hidden');
    setTimeout(function() { if (typeof initScrollAnimations === 'function') initScrollAnimations(); }, 50);
  }

  /* ─── Main ─── */
  async function init() {
    initProgressBar();
    var slug = getSlug();
    if (!slug) { window.location.href = '/blog.html'; return; }

    try {
      var res = await fetch(API + '/blog/post/' + encodeURIComponent(slug));
      if (!res.ok) throw new Error('Not found');
      var data = await res.json();
      if (!data.success || !data.post) throw new Error('No post');
      var post = data.post;

      setMeta(post);

      var bc = document.getElementById('breadcrumb-title');
      if (bc) bc.textContent = post.title || 'Artikel';

      // Hero meta
      var metaEl = document.getElementById('article-meta');
      if (metaEl) {
        metaEl.innerHTML =
          '<span class="text-xs font-semibold uppercase tracking-[0.15em] px-3 py-1.5 rounded-full" style="background:rgba(212,116,59,0.15);color:#D4743B">' + esc(categoryLabel(post.category || '')) + '</span>' +
          '<span class="text-sm" style="color:rgba(255,255,255,0.4)">' + esc(fmtDate(post.published_at || post.created_at || '')) + '</span>' +
          (post.reading_time_min ? '<span class="text-sm" style="color:rgba(255,255,255,0.4)">' + esc(post.reading_time_min) + ' Min. Lesezeit</span>' : '');
      }

      var titleEl = document.getElementById('article-title');
      if (titleEl) titleEl.textContent = post.title || '';

      var excerptEl = document.getElementById('article-excerpt');
      if (excerptEl) {
        if (post.excerpt) { excerptEl.textContent = post.excerpt; }
        else { excerptEl.style.display = 'none'; }
      }

      // Author pill
      var authorEl = document.getElementById('article-author');
      if (authorEl) authorEl.classList.remove('hidden');

      // Featured Image
      var imgWrap = document.getElementById('article-image-wrap');
      var imgEl = document.getElementById('article-image');
      var featImg = post.featured_image_url || post.featured_image || '';
      if (imgWrap && imgEl && featImg) {
        imgEl.src = featImg;
        imgEl.alt = post.featured_image_alt || post.title || '';
        imgWrap.classList.remove('hidden');
      }

      // Body
      var bodyEl = document.getElementById('article-body');
      var skeleton = document.getElementById('article-skeleton');
      if (skeleton) skeleton.remove();
      if (bodyEl) {
        var html = markdownToHtml(post.content || post.body || '');
        bodyEl.innerHTML = html;
        buildTOC(bodyEl);
      }

      // Tags
      renderTags(post.tags);

      // Share
      initShare(post.title || '');

      // Related
      try {
        var allRes = await fetch(API + '/blog/posts');
        var allData = await allRes.json();
        if (allData.success && Array.isArray(allData.posts)) renderRelated(allData.posts, slug);
      } catch (e) {}

    } catch (e) {
      console.error('Article load failed:', e);
      window.location.href = '/blog.html';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
