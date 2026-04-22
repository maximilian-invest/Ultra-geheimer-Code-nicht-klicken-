/* ═══════════════════════════════════════════════════════════════
   SR-HOMES — Blog Listing Page JS (Premium Design)
   ═══════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  const CATEGORY_LABELS = { ratgeber: 'Ratgeber', news: 'News & Markt' };

  function fmtDate(dateStr) {
    if (!dateStr) return '';
    try {
      return new Date(dateStr).toLocaleDateString('de-AT', { day: '2-digit', month: 'long', year: 'numeric' });
    } catch (e) { return dateStr; }
  }

  function categoryLabel(cat) {
    return CATEGORY_LABELS[cat] || (cat ? cat.charAt(0).toUpperCase() + cat.slice(1) : 'Allgemein');
  }

  function readingTimeLabel(minutes) {
    return minutes ? minutes + ' Min. Lesezeit' : '';
  }

  /* ─── Featured Card (full-width, 2-col split) ─── */
  function buildFeaturedCard(post) {
    const img = post.featured_image_url || post.featured_image || '';
    const slug = post.slug || '';
    const href = slug ? '/' + esc(slug) : 'blog-article.html?slug=' + esc(slug);

    return '<a href="' + href + '" class="group block featured-card rounded-2xl overflow-hidden" style="background:#fff;border:1px solid #F0ECE6;text-decoration:none;transition:transform 0.5s cubic-bezier(0.22,1,0.36,1),box-shadow 0.5s cubic-bezier(0.22,1,0.36,1)" onmouseover="this.style.transform=\'translateY(-4px)\';this.style.boxShadow=\'0 24px 48px -12px rgba(10,10,8,0.1)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'none\'" data-animate>' +
      '<div class="grid grid-cols-1 lg:grid-cols-5">' +
        '<div class="lg:col-span-3 featured-img overflow-hidden" style="aspect-ratio:16/10;max-height:480px">' +
          (img
            ? '<img src="' + esc(img) + '" alt="' + esc(post.title || '') + '" loading="lazy" decoding="async" class="w-full h-full object-cover" />'
            : '<div class="w-full h-full flex items-center justify-center" style="background:#F0ECE6;min-height:300px"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#C5C0B8" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>') +
        '</div>' +
        '<div class="lg:col-span-2 flex flex-col justify-center p-8 md:p-10">' +
          '<div class="flex items-center gap-3 mb-5">' +
            '<span class="text-xs font-semibold uppercase tracking-[0.12em] px-3 py-1.5 rounded-full" style="background:#0A0A08;color:#FAF8F5">' + esc(categoryLabel(post.category || '')) + '</span>' +
          '</div>' +
          '<h2 class="font-display font-semibold leading-snug mb-4 group-hover:text-accent transition-colors duration-300" style="color:#0A0A08;font-size:clamp(1.35rem,2.2vw,1.9rem);letter-spacing:-0.01em">' + esc(post.title || '') + '</h2>' +
          (post.excerpt ? '<p class="text-sm leading-relaxed mb-6" style="color:#5A564E;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">' + esc(post.excerpt) + '</p>' : '') +
          '<div class="flex items-center gap-3 mt-auto pt-5" style="border-top:1px solid #F0ECE6">' +
            '<div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold" style="background:#D4743B;color:#fff">SR</div>' +
            '<div class="flex items-center gap-3">' +
              '<span class="text-xs font-medium" style="color:#5A564E">SR Homes</span>' +
              '<span class="text-xs" style="color:#E5E0D8">&middot;</span>' +
              '<span class="text-xs" style="color:#9A958C">' + esc(fmtDate(post.published_at || post.created_at || '')) + '</span>' +
              (post.reading_time_min ? '<span class="text-xs" style="color:#E5E0D8">&middot;</span><span class="text-xs" style="color:#9A958C">' + esc(readingTimeLabel(post.reading_time_min)) + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</a>';
  }

  /* ─── Grid Card ─── */
  function buildCard(post) {
    const img = post.featured_image_url || post.featured_image || '';
    const slug = post.slug || '';
    const href = slug ? '/' + esc(slug) : 'blog-article.html?slug=' + esc(slug);

    return '<a href="' + href + '" class="group flex flex-col blog-card rounded-2xl overflow-hidden" style="background:#fff;border:1px solid #F0ECE6;text-decoration:none" data-animate>' +
      '<div class="card-img overflow-hidden" style="aspect-ratio:16/10">' +
        (img
          ? '<img src="' + esc(img) + '" alt="' + esc(post.title || '') + '" loading="lazy" decoding="async" class="w-full h-full object-cover" />'
          : '<div class="w-full h-full flex items-center justify-center" style="background:#F0ECE6"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#C5C0B8" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>') +
      '</div>' +
      '<div class="flex flex-col flex-1 p-6">' +
        '<div class="flex items-center gap-3 mb-4">' +
          '<span class="text-[11px] font-semibold uppercase tracking-[0.12em] px-3 py-1 rounded-full" style="background:rgba(212,116,59,0.08);color:#D4743B">' + esc(categoryLabel(post.category || '')) + '</span>' +
        '</div>' +
        '<h3 class="font-display font-semibold leading-snug mb-3 group-hover:text-accent transition-colors duration-300" style="color:#0A0A08;font-size:1.15rem;letter-spacing:-0.01em">' + esc(post.title || '') + '</h3>' +
        (post.excerpt ? '<p class="text-sm leading-relaxed mb-4" style="color:#9A958C;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">' + esc(post.excerpt) + '</p>' : '') +
        '<div class="flex items-center gap-3 mt-auto pt-4" style="border-top:1px solid #F0ECE6">' +
          '<div class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold" style="background:#D4743B;color:#fff">SR</div>' +
          '<span class="text-xs" style="color:#9A958C">' + esc(fmtDate(post.published_at || post.created_at || '')) + '</span>' +
          (post.reading_time_min ? '<span class="text-xs" style="color:#E5E0D8">&middot;</span><span class="text-xs" style="color:#9A958C">' + esc(readingTimeLabel(post.reading_time_min)) + '</span>' : '') +
        '</div>' +
      '</div>' +
    '</a>';
  }

  let allPosts = [];
  let activeFilter = 'all';

  function renderPosts(posts) {
    var loading = document.getElementById('blog-loading');
    var featured = document.getElementById('blog-featured');
    var grid = document.getElementById('blog-grid');
    var empty = document.getElementById('blog-empty');

    if (loading) loading.classList.add('hidden');

    if (!posts || posts.length === 0) {
      if (featured) featured.classList.add('hidden');
      if (grid) grid.classList.add('hidden');
      if (empty) empty.classList.remove('hidden');
      return;
    }

    if (empty) empty.classList.add('hidden');

    var first = posts[0];
    var rest = posts.slice(1);

    if (featured) {
      featured.innerHTML = buildFeaturedCard(first);
      featured.classList.remove('hidden');
    }

    if (grid) {
      if (rest.length > 0) {
        grid.innerHTML = rest.map(function(p) { return buildCard(p); }).join('');
        grid.classList.remove('hidden');
      } else {
        grid.classList.add('hidden');
      }
    }

    setTimeout(function() {
      if (typeof initScrollAnimations === 'function') initScrollAnimations();
    }, 50);
  }

  function applyFilter(filter) {
    activeFilter = filter;

    document.querySelectorAll('.filter-chip').forEach(function(btn) {
      var isActive = btn.dataset.filter === filter;
      if (isActive) {
        btn.style.background = '#0A0A08';
        btn.style.color = '#FAF8F5';
        btn.style.borderColor = '#0A0A08';
        btn.classList.add('active');
      } else {
        btn.style.background = 'transparent';
        btn.style.color = '#5A564E';
        btn.style.borderColor = '#E5E0D8';
        btn.classList.remove('active');
      }
    });

    var filtered = filter === 'all'
      ? allPosts
      : allPosts.filter(function(p) { return (p.category || '').toLowerCase() === filter; });

    renderPosts(filtered);
  }

  async function init() {
    document.querySelectorAll('.filter-chip').forEach(function(btn) {
      btn.addEventListener('click', function() { applyFilter(btn.dataset.filter); });
    });

    try {
      var res = await fetch(API + '/blog/posts');
      var data = await res.json();
      if (data.success && Array.isArray(data.posts)) {
        allPosts = data.posts;
      } else {
        allPosts = [];
      }
    } catch (e) {
      console.error('Blog fetch failed:', e);
      allPosts = [];
    }

    renderPosts(allPosts);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
