@php
    // Grundriss-Seite: zentriertes Bild mit Header. image_id ist Pflicht —
    // ohne Bild wird die Seite gar nicht gerendert (Whitelist hat den Type
    // schon erlaubt, aber ohne Bild ist sie sinnlos).
    $img      = $ctx->image($page['image_id'] ?? null);
    $imageUrl = $img ? \App\Support\ExposeImage::url($img, \App\Support\ExposeImage::SIZE_COVER) : null;
    // Stockwerk-Label: aus property_images.title (vom User im ExposeTab
    // gesetzt). Fallback description, sonst kein Label.
    $stockwerk  = trim((string) ($img?->title ?: ''));
    $captionRaw = $page['caption'] ?? ($img?->description ?: null);
    $caption    = $captionRaw ? trim((string) $captionRaw) : null;

    // Header-Title: lange Stockwerk-Bezeichnungen werden zu kompakten
    // Abkürzungen verkürzt — "Grundriss EG", "Grundriss 1. OG", etc.
    // Freitext-Eingaben (nicht im Mapping) werden 1:1 uebernommen, damit
    // der User auch Sonderfaelle wie "Souterrain" oder "Maisonette OG"
    // schreiben kann.
    $abbrevMap = [
        'keller'             => 'KG',
        'kellergeschoss'     => 'KG',
        'untergeschoss'      => 'UG',
        'souterrain'         => 'SO',
        'erdgeschoss'        => 'EG',
        'hochparterre'       => 'HP',
        'parterre'           => 'EG',
        'mezzanin'           => 'MZ',
        'obergeschoss'       => 'OG',
        '1. obergeschoss'    => '1. OG',
        '2. obergeschoss'    => '2. OG',
        '3. obergeschoss'    => '3. OG',
        '4. obergeschoss'    => '4. OG',
        '5. obergeschoss'    => '5. OG',
        '1.obergeschoss'     => '1. OG',
        '2.obergeschoss'     => '2. OG',
        'dachgeschoss'       => 'DG',
        'aussenanlage'       => 'Aussen',
        'außenanlage'        => 'Außen',
    ];
    $abbreviate = static function (string $raw) use ($abbrevMap): string {
        $key = mb_strtolower(trim($raw));
        return $abbrevMap[$key] ?? $raw;
    };

    $headerTitle = $stockwerk !== ''
        ? 'Grundriss ' . $abbreviate($stockwerk)
        : 'Grundriss';
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
    <div class="title-s">{{ $headerTitle }}</div>
    <div class="aline"></div>
    <div class="gr-wrap">
        <div class="gr-stage">
            <img src="{{ $imageUrl }}" alt="{{ $stockwerk !== '' ? 'Grundriss ' . $stockwerk : 'Grundriss' }}">
        </div>
        @if ($caption)
            <div class="gr-cap">{{ $caption }}</div>
        @endif
    </div>
</div>
@endif
