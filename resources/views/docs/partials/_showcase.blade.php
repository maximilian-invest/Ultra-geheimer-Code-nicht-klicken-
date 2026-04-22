<!-- resources/views/docs/partials/_showcase.blade.php -->
@php
    // Gallery: everything after the first image (which is already in the hero)
    $galleryImages = array_slice($heroImages ?? [], 1);
@endphp

@if (!empty($galleryImages) || !empty($showcase['description']) || !empty($showcase['location']) || !empty($showcase['facts']))
<section class="project-showcase">

    @if (!empty($galleryImages))
        <div class="showcase-gallery showcase-gallery-{{ min(count($galleryImages), 5) }}">
            @foreach ($galleryImages as $i => $img)
                <figure class="showcase-gallery-tile" style="animation-delay: {{ $i * 60 }}ms;">
                    <img src="{{ $img }}" alt="{{ $link->property->project_name ?? 'Projektbild' }} — Bild {{ $i + 2 }}" loading="lazy">
                </figure>
            @endforeach
        </div>
    @endif

    @if (!empty($showcase['facts']))
        <div class="showcase-facts">
            @foreach ($showcase['facts'] as $fact)
                <div class="fact-tile">
                    <div class="fact-icon">
                        @switch($fact['icon'])
                            @case('units')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                @break
                            @case('year')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                @break
                            @case('price')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                @break
                            @case('area')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                                @break
                            @case('location')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                @break
                            @case('energy')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                @break
                            @default
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>
                        @endswitch
                    </div>
                    <div class="fact-meta">
                        <span class="fact-label">{{ $fact['label'] }}</span>
                        <span class="fact-value">{{ $fact['value'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (!empty($showcase['description']) || !empty($showcase['location']))
        <div class="showcase-prose">
            @if (!empty($showcase['description']))
                <article class="showcase-prose-block">
                    <h2>Warum dieses Projekt?</h2>
                    <p>{!! nl2br(e($showcase['description'])) !!}</p>
                </article>
            @endif
            @if (!empty($showcase['location']))
                <article class="showcase-prose-block">
                    <h2>Lage &amp; Umgebung</h2>
                    <p>{!! nl2br(e($showcase['location'])) !!}</p>
                </article>
            @endif
            @if (!empty($showcase['equipment']))
                <article class="showcase-prose-block">
                    <h2>Ausstattung</h2>
                    <p>{!! nl2br(e($showcase['equipment'])) !!}</p>
                </article>
            @endif
        </div>
    @endif

    @if (!empty($showcase['coords']))
        @php($c = $showcase['coords'])
        <details class="showcase-map" open>
            <summary>
                <h2 style="margin:0">Lage auf der Karte</h2>
                <span class="showcase-map-toggle">
                    <span>Karte ein-/ausblenden</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </summary>
            <div class="showcase-map-wrap">
                <div id="sr-map-canvas"
                     data-lat="{{ $c['lat'] }}"
                     data-lng="{{ $c['lng'] }}"
                     style="height:380px;width:100%;background:#F0ECE6;border-radius:18px;overflow:hidden"></div>
                <p class="showcase-map-note">
                    Standort des Objekts in {{ $c['region'] ?: 'der Region' }}.
                </p>
            </div>
        </details>

        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
              crossorigin="" />
        <style>
            .showcase-map{margin-top:2.5rem}
            .showcase-map > summary{list-style:none;display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:0.75rem 0;border-bottom:1px solid rgba(0,0,0,0.08)}
            .showcase-map > summary::-webkit-details-marker{display:none}
            .showcase-map-toggle{display:inline-flex;align-items:center;gap:0.5rem;font-size:0.75rem;color:#6b7280;transition:color 0.2s}
            .showcase-map[open] .showcase-map-toggle svg{transform:rotate(180deg)}
            .showcase-map-toggle svg{transition:transform 0.2s}
            .showcase-map-wrap{margin-top:1rem}
            .showcase-map-note{margin-top:0.75rem;font-size:0.75rem;color:#6b7280}
            #sr-map-canvas .leaflet-tile-pane{filter:grayscale(1) contrast(1.1)}
            #sr-map-canvas .leaflet-container{background:#FAF8F5;border-radius:18px}
            #sr-map-canvas .leaflet-control-attribution{background:rgba(255,255,255,0.9);font-size:10px}
            /* Orangefarbener Custom-Marker statt Default-Blau */
            .sr-pin{
                width:22px;height:30px;position:relative;
                filter:drop-shadow(0 2px 3px rgba(0,0,0,0.25));
            }
            .sr-pin svg{width:100%;height:100%}
        </style>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
                crossorigin=""></script>
        <script>
          (function() {
            var el = document.getElementById('sr-map-canvas');
            if (!el || typeof L === 'undefined') return;
            var lat = parseFloat(el.dataset.lat);
            var lng = parseFloat(el.dataset.lng);
            if (!isFinite(lat) || !isFinite(lng)) return;
            function init() {
              if (el.dataset.inited === '1') return;
              el.dataset.inited = '1';
              var map = L.map(el, { scrollWheelZoom:false, zoomControl:true, attributionControl:true })
                .setView([lat, lng], 16);  // Naeher dran, da exakter Pin
              L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                maxZoom: 19,
                subdomains: 'abcd',
                attribution: '&copy; OpenStreetMap &copy; CARTO'
              }).addTo(map);
              // Exakter Pin in SR-Homes orange
              var pinIcon = L.divIcon({
                className: 'sr-pin',
                iconSize: [22, 30],
                iconAnchor: [11, 30],
                html: '<svg viewBox="0 0 22 30" xmlns="http://www.w3.org/2000/svg">'
                    + '<path d="M11 0C4.9 0 0 4.9 0 11c0 7.7 11 19 11 19s11-11.3 11-19C22 4.9 17.1 0 11 0z" fill="#D4743B"/>'
                    + '<circle cx="11" cy="11" r="4" fill="#fff"/>'
                    + '</svg>',
              });
              L.marker([lat, lng], { icon: pinIcon }).addTo(map);
            }
            setTimeout(init, 0);
          })();
        </script>
    @endif

</section>
@endif
