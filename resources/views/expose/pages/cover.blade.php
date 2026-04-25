@php
    $img = $ctx->image($page['image_id'] ?? null);
    // Cover ist Vollbild — größere Stufe damit das Hintergrundbild knackig bleibt.
    $imgUrl = \App\Support\ExposeImage::url($img, \App\Support\ExposeImage::SIZE_COVER);
    $address = trim(($ctx->property->address ?? '') . ' ' . ($ctx->property->house_number ?? ''));
    $zipCity = trim(($ctx->property->zip ?? '') . ' ' . ($ctx->property->city ?? ''));
    $defaultSubtitle = trim($address . ($address && $zipCity ? ' · ' : '') . $zipCity);
    $living = $ctx->property->living_area ? number_format($ctx->property->living_area, 0, ',', '.') . ' m²' : null;
    $rooms = $ctx->property->rooms_amount ? rtrim(rtrim(number_format($ctx->property->rooms_amount, 1, ',', ''), '0'), ',') . ' Zimmer' : null;
    $year = $ctx->property->construction_year ? 'Baujahr ' . $ctx->property->construction_year : null;
    $price = $ctx->property->purchase_price ? '€ ' . number_format($ctx->property->purchase_price, 0, ',', '.') : null;
    $badges = array_filter([$living, $rooms, $year]);

    // Makler-Overrides vorziehen, sonst Default aus Property-Daten.
    $propertyType = $ctx->property->expose_cover_kicker   ?: ($ctx->property->object_type ?: 'Immobilie');
    $coverTitle   = $ctx->property->expose_cover_title    ?: ($ctx->property->city ?: 'Immobilie');
    $coverSubline = $ctx->property->expose_cover_subtitle ?: $defaultSubtitle;
@endphp

<style>
  .cover-page {
    position: relative;
  }
  .cover-page .bg { position: absolute; inset: 0; }
  .cover-page .bg img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cover-page::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.1) 25%, rgba(0,0,0,0.2) 55%, rgba(0,0,0,0.55) 100%);
  }
  .cover-page .logo {
    position: absolute; top: 36px; left: 48px;
    height: 30px; width: auto;
    filter: brightness(0) invert(1);
    z-index: 2;
  }
  /* Text-Block ist ein Flex-Container — lange Titel brechen um, Adresse
     rutscht automatisch nach unten, keine Überlappung mehr. */
  .cover-page .text-block {
    position: absolute; top: 50%; left: 48px; right: 48px;
    transform: translateY(-50%);
    display: flex; flex-direction: column; align-items: center;
    text-align: center;
    z-index: 2;
    gap: 18px;
  }
  .cover-page .kicker {
    font-size: 12px; color: rgba(255,255,255,0.85); letter-spacing: 6px;
    text-transform: uppercase; font-weight: 600;
  }
  .cover-page .title {
    font-family: 'Playfair Display', serif; font-size: 52px; font-weight: 400;
    color: #fff; letter-spacing: 8px; text-transform: uppercase;
    text-shadow: 0 3px 12px rgba(0,0,0,0.4); line-height: 1.08;
    max-width: 100%;
    /* Bei sehr langem Titel (> ca. 22 Zeichen) leicht kleinere Schrift damit
       nichts seitlich rausläuft und der Umbruch nicht zu breit wird. */
    word-break: break-word;
  }
  .cover-page .title.title-long   { font-size: 42px; letter-spacing: 6px; }
  .cover-page .title.title-xlong  { font-size: 34px; letter-spacing: 4px; }
  .cover-page .address {
    font-family: Georgia, serif; font-size: 18px; color: rgba(255,255,255,0.95);
    letter-spacing: 2.5px; font-style: italic;
  }
  .cover-page .address::before, .cover-page .address::after {
    content: ''; display: inline-block; width: 34px; height: 1px;
    background: rgba(255,255,255,0.5); vertical-align: middle; margin: 0 18px;
  }
  .cover-page .badges {
    position: absolute; bottom: 52px; left: 0; right: 0;
    display: flex; justify-content: center; gap: 14px; z-index: 2;
  }
  .cover-page .badge {
    background: rgba(255,255,255,0.96); color: #222;
    padding: 12px 24px; border-radius: 22px;
    font-size: 15px; font-weight: 600;
    box-shadow: 0 3px 12px rgba(0,0,0,0.25);
  }
  .cover-page .badge.accent { background: var(--accent); color: #fff; }
</style>

<div class="page cover-page">
    @if ($imgUrl)
        <div class="bg"><img src="{{ $imgUrl }}" alt=""></div>
    @else
        <div class="bg" style="background:linear-gradient(135deg,#5d4e37,#1a1a1a)"></div>
    @endif

    <img class="logo" src="{{ asset('assets/logo-full-white.svg') }}" alt="SR Homes">
    @php
        // Auto-Shrink: je länger der Titel, desto kleiner die Schrift,
        // damit er auf dem Cover nicht in den Untertitel läuft.
        $titleLen = mb_strlen($coverTitle);
        $titleClass = $titleLen > 32 ? 'title-xlong' : ($titleLen > 20 ? 'title-long' : '');
    @endphp
    <div class="text-block">
        <div class="kicker">{{ strtoupper($propertyType) }}</div>
        <div class="title {{ $titleClass }}">{{ $coverTitle }}</div>
        @if ($coverSubline)
            <div class="address">{{ $coverSubline }}</div>
        @endif
    </div>

    <div class="badges">
        @foreach ($badges as $b)
            <div class="badge">{{ $b }}</div>
        @endforeach
        @if ($price)
            <div class="badge accent">{{ $price }}</div>
        @endif
    </div>
</div>
