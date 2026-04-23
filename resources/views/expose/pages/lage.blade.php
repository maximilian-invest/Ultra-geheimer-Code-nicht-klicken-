@php
    $p = $ctx->property;
    $lat = (float) ($p->latitude ?? 47.7529);
    $lng = (float) ($p->longitude ?? 13.0260);
    $mapId = 'map-' . $p->id;
    $text = $p->location_description ?? '';
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n+/', $text))));
    $lead = '';
    $rest = [];
    if (!empty($paragraphs)) {
        $first = $paragraphs[0];
        if (preg_match('/^(.+?[.!?])(\s|$)(.*)/s', $first, $m)) {
            $lead = trim($m[1]);
            $rest = array_merge([trim($m[3])], array_slice($paragraphs, 1));
            $rest = array_values(array_filter($rest));
        } else {
            $lead = $first;
            $rest = array_slice($paragraphs, 1);
        }
    }
@endphp

<style>
  .lage-page .grid {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1.1fr 1fr; gap: 32px;
  }
  .lage-page .map-container {
    border-radius: 3px; overflow: hidden; position: relative;
    border: 1px solid var(--border);
  }
  .lage-page .map-container .mapbox { width: 100%; height: 100%; }
  .lage-page .map-container .leaflet-tile-pane { filter: grayscale(1) contrast(1.05); }
  .lage-page .map-badge {
    position: absolute; bottom: 14px; left: 16px; z-index: 400;
    font-family: Georgia, serif; font-size: 15px; color: var(--text-primary);
    background: rgba(255,255,255,0.94); padding: 6px 14px; border-radius: 2px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
  }
  .lage-page .txt .lead { font-family: Georgia, serif; font-style: italic; font-size: 16px; line-height: 1.4; color: var(--text-primary); margin-bottom: 12px; }
  .lage-page .txt .p { font-size: 12px; line-height: 1.55; color: #333; margin-bottom: 6px; }
</style>

<div class="page lage-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Lage</div>
    <div class="aline"></div>
    <div class="grid">
        <div class="map-container">
            <div id="{{ $mapId }}" class="mapbox"></div>
            @if ($p->city)
                <div class="map-badge">{{ strtoupper($p->city) }}</div>
            @endif
        </div>
        <div class="txt">
            @if ($lead)
                <div class="lead">{{ $lead }}</div>
            @endif
            @foreach ($rest as $para)
                <div class="p">{{ $para }}</div>
            @endforeach
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function() {
        function init() {
            if (!window.L) { setTimeout(init, 80); return; }
            var el = document.getElementById({!! json_encode($mapId) !!});
            if (!el) return;
            var map = L.map(el, { scrollWheelZoom: false, zoomControl: false, attributionControl: false })
                       .setView([{{ $lat }}, {{ $lng }}], 14);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                subdomains: 'abcd', maxZoom: 19
            }).addTo(map);
            L.circle([{{ $lat }}, {{ $lng }}], {
                radius: 400, color: '#ee7600', weight: 2.5,
                fillColor: '#ee7600', fillOpacity: 0.22
            }).addTo(map);
        }
        init();
    })();
</script>
@endpush
