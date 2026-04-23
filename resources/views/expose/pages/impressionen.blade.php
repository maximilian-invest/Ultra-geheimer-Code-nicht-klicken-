@php
    $layout = $page['layout'] ?? 'L4';
    $ids = $page['image_ids'] ?? [];
    $imgs = collect($ids)->map(fn($id) => $ctx->image($id))->filter()->values();
    $url = fn($img) => asset('storage/' . $img->path);
    $caption = $page['caption'] ?? null;
    $isEditorial = in_array($layout, ['M1'], true);
@endphp

<style>
  .impr-page .box {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: grid; gap: 8px;
  }
  .impr-page .box.L1 { grid-template-columns: 1fr; }
  .impr-page .box.L2 { grid-template-columns: 1fr 1fr; }
  .impr-page .box.L3 { grid-template-columns: 1.6fr 1fr; grid-template-rows: 1fr 1fr; }
  .impr-page .box.L3 > :first-child { grid-row: 1 / 3; }
  .impr-page .box.L4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
  .impr-page .box.L5 { grid-template-columns: 1.5fr 1fr; grid-template-rows: 1fr 1fr 1fr; }
  .impr-page .box.L5 > :first-child { grid-row: 1 / 4; }

  /* Editorial M1: 2 Bilder oben, 1 Bild unten-links, Text-Zelle unten-rechts. */
  .impr-page .box.M1 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }

  .impr-page .cell {
    border-radius: 3px; overflow: hidden;
  }
  .impr-page .cell img { width: 100%; height: 100%; object-fit: cover; display: block; }

  .impr-page .text-cell {
    background: var(--bg-cream, #fdfcfa);
    border-radius: 3px;
    padding: 22px 24px;
    display: flex; flex-direction: column; justify-content: center;
    border: 1px solid var(--border);
  }
  .impr-page .text-cell .dash {
    width: 34px; height: 2px; background: var(--accent);
    margin-bottom: 16px;
  }
  .impr-page .text-cell .quote {
    font-family: 'Cormorant Garamond', Georgia, serif;
    font-size: 26px; line-height: 1.2;
    color: var(--text-primary);
    font-weight: 300; font-style: italic;
    letter-spacing: -0.2px;
  }
</style>

<div class="page impr-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Impressionen</div>
    <div class="aline"></div>
    <div class="box {{ $layout }}">
        @if ($isEditorial && $layout === 'M1')
            {{-- 3 Bilder + Text-Zelle unten rechts --}}
            @foreach ($imgs->take(3) as $img)
                <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
            @endforeach
            <div class="text-cell">
                <div class="dash"></div>
                <div class="quote">{{ $caption ?: 'Wo Tageslicht den Raum formt.' }}</div>
            </div>
        @else
            @foreach ($imgs as $img)
                <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
            @endforeach
        @endif
    </div>
</div>
