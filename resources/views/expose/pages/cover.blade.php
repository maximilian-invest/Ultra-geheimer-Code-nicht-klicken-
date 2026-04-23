@php
    $img = $ctx->image($page['image_id'] ?? null);
    $imgUrl = $img ? asset('storage/' . $img->path) : null;
    $address = trim(($ctx->property->address ?? '') . ' ' . ($ctx->property->house_number ?? ''));
    $zipCity = trim(($ctx->property->zip ?? '') . ' ' . ($ctx->property->city ?? ''));
    $living = $ctx->property->living_area ? number_format($ctx->property->living_area, 0, ',', '.') . ' m²' : null;
    $rooms = $ctx->property->rooms_amount ? rtrim(rtrim(number_format($ctx->property->rooms_amount, 1, ',', ''), '0'), ',') . ' Zimmer' : null;
    $year = $ctx->property->construction_year ? 'Baujahr ' . $ctx->property->construction_year : null;
    $price = $ctx->property->purchase_price ? '€ ' . number_format($ctx->property->purchase_price, 0, ',', '.') : null;
    $badges = array_filter([$living, $rooms, $year]);
    $propertyType = $ctx->property->object_type ?: 'Immobilie';
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
  .cover-page .kicker {
    position: absolute; top: 210px; left: 0; right: 0; text-align: center;
    font-size: 12px; color: rgba(255,255,255,0.85); letter-spacing: 6px;
    text-transform: uppercase; font-weight: 600; z-index: 2;
  }
  .cover-page .title {
    position: absolute; top: 238px; left: 0; right: 0; text-align: center;
    font-family: 'Playfair Display', serif; font-size: 52px; font-weight: 400;
    color: #fff; letter-spacing: 8px; text-transform: uppercase;
    text-shadow: 0 3px 12px rgba(0,0,0,0.4); z-index: 2; line-height: 1;
  }
  .cover-page .address {
    position: absolute; top: 320px; left: 0; right: 0; text-align: center;
    font-family: Georgia, serif; font-size: 18px; color: rgba(255,255,255,0.95);
    letter-spacing: 2.5px; font-style: italic; z-index: 2;
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
    <div class="kicker">{{ strtoupper($propertyType) }}</div>
    <div class="title">{{ $ctx->property->city ?: 'Immobilie' }}</div>
    @if ($address)
        <div class="address">{{ $address }}@if ($zipCity) · {{ $zipCity }}@endif</div>
    @endif

    <div class="badges">
        @foreach ($badges as $b)
            <div class="badge">{{ $b }}</div>
        @endforeach
        @if ($price)
            <div class="badge accent">{{ $price }}</div>
        @endif
    </div>
</div>
