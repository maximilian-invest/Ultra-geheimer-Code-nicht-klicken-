@php
    $img = $ctx->image($page['image_id'] ?? null);
    // Intro ist Vollbild-Background — größere Stufe.
    $imgUrl = \App\Support\ExposeImage::url($img, \App\Support\ExposeImage::SIZE_COVER);
@endphp

<style>
  .intro-page { position: relative; }
  .intro-page .bg { position: absolute; inset: 0; }
  .intro-page .bg img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .intro-page::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.25) 0%, rgba(0,0,0,0.12) 40%, rgba(0,0,0,0.55) 100%);
  }
  .intro-page .pn {
    position: absolute; top: 30px; right: 42px;
    font-size: 12px; color: rgba(255,255,255,0.65); letter-spacing: 2.5px; font-weight: 500;
    z-index: 2;
  }
  .intro-page .label {
    position: absolute; top: 50%; left: 0; right: 0;
    transform: translateY(-50%);
    text-align: center; color: #fff; z-index: 2;
  }
  .intro-page .kicker {
    font-size: 12px; letter-spacing: 6px;
    color: rgba(255,255,255,0.85);
    text-transform: uppercase; font-weight: 600;
    margin-bottom: 18px;
  }
  .intro-page .head {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 72px; letter-spacing: 12px;
    font-weight: 400; text-transform: uppercase;
    color: #fff; line-height: 1;
    text-shadow: 0 3px 14px rgba(0,0,0,0.45);
  }
  .intro-page .dash {
    width: 64px; height: 2px;
    background: var(--accent);
    margin: 26px auto 0;
  }
</style>

<div class="page intro-page">
    @if ($imgUrl)
        <div class="bg"><img src="{{ $imgUrl }}" alt=""></div>
    @else
        <div class="bg" style="background: linear-gradient(135deg, #5d4e37 0%, #1a1a1a 100%);"></div>
    @endif
    <div class="pn">{{ $pageNum }}</div>
    <div class="label">
        <div class="kicker">PERSÖNLICHE EINDRÜCKE</div>
        <div class="head">Impressionen</div>
        <div class="dash"></div>
    </div>
</div>
