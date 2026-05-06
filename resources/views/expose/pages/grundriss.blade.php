@php
    // Grundriss-Seite: zentriertes Bild mit Header. image_id ist Pflicht —
    // ohne Bild wird die Seite gar nicht gerendert (Whitelist hat den Type
    // schon erlaubt, aber ohne Bild ist sie sinnlos).
    $img      = $ctx->image($page['image_id'] ?? null);
    $imageUrl = $img ? \App\Support\ExposeImage::url($img, \App\Support\ExposeImage::SIZE_COVER) : null;
    $captionRaw = $page['caption'] ?? ($img?->title ?: $img?->description ?: null);
    $caption    = $captionRaw ? trim((string) $captionRaw) : null;
@endphp

@if ($imageUrl)
<style>
  .grundriss-page .gr-wrap {
    position: absolute; top: 124px; left: 48px; right: 48px; bottom: 28px;
    display: flex; flex-direction: column; gap: 14px;
  }
  .grundriss-page .gr-stage {
    flex: 1; min-height: 0;
    background: #fafafa; border: 1px solid var(--border); border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
    padding: 16px;
  }
  .grundriss-page .gr-stage img {
    max-width: 100%; max-height: 100%; object-fit: contain;
    display: block;
  }
  .grundriss-page .gr-cap {
    font-size: 11px; color: var(--text-secondary); letter-spacing: 0.4px;
    text-align: center;
  }
</style>

<div class="page grundriss-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Grundriss</div>
    <div class="aline"></div>
    <div class="gr-wrap">
        <div class="gr-stage">
            <img src="{{ $imageUrl }}" alt="Grundriss">
        </div>
        @if ($caption)
            <div class="gr-cap">{{ $caption }}</div>
        @endif
    </div>
</div>
@endif
