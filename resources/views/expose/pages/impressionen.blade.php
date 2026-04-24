@php
    $layout = $page['layout'] ?? 'L4';
    $ids = $page['image_ids'] ?? [];
    $imgs = collect($ids)->map(fn($id) => $ctx->image($id))->filter()->values();
    $url = fn($img) => asset('storage/' . $img->path);
    $caption = $page['caption'] ?? null;
    $editorialLayouts = ['M1', 'M3', 'M4'];
    $isEditorial = in_array($layout, $editorialLayouts, true);
@endphp

<style>
  .impr-page .box {
    position: absolute; top: 124px; left: 48px; right: 48px; bottom: 28px;
    display: grid; gap: 8px;
  }
  .impr-page .box.L1 { grid-template-columns: 1fr; }
  .impr-page .box.L2 { grid-template-columns: 1fr 1fr; }
  .impr-page .box.L3 { grid-template-columns: 1.6fr 1fr; grid-template-rows: 1fr 1fr; }
  .impr-page .box.L3 > :first-child { grid-row: 1 / 3; }
  .impr-page .box.L4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
  .impr-page .box.L5 { grid-template-columns: 1.5fr 1fr; grid-template-rows: 1fr 1fr 1fr; }
  .impr-page .box.L5 > :first-child { grid-row: 1 / 4; }

  /* Masonry LM: 1 groß + 3 kleine, asymmetrisch. */
  .impr-page .box.LM {
    grid-template-columns: 1.7fr 1fr 1fr;
    grid-template-rows: 1fr 1fr;
  }
  .impr-page .box.LM > :nth-child(1) { grid-row: 1 / 3; grid-column: 1; }
  .impr-page .box.LM > :nth-child(2) { grid-row: 1; grid-column: 2 / 4; }
  .impr-page .box.LM > :nth-child(3) { grid-row: 2; grid-column: 2; }
  .impr-page .box.LM > :nth-child(4) { grid-row: 2; grid-column: 3; }

  /* M1: 2 Bilder oben, 1 Bild unten-links, Text-Zelle unten-rechts. */
  .impr-page .box.M1 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }

  /* M3: Text-Band oben (33%), darunter 3 Bilder asymmetrisch. */
  .impr-page .box.M3 {
    grid-template-columns: 1.5fr 1fr;
    grid-template-rows: auto 1fr 1fr;
  }
  .impr-page .box.M3 > .text-band { grid-column: 1 / 3; grid-row: 1; }
  .impr-page .box.M3 > :nth-child(2) { grid-row: 2 / 4; grid-column: 1; }
  .impr-page .box.M3 > :nth-child(3) { grid-row: 2; grid-column: 2; }
  .impr-page .box.M3 > :nth-child(4) { grid-row: 3; grid-column: 2; }

  /* M4: Vollbild mit Zitat-Overlay unten. */
  .impr-page .box.M4 { grid-template-columns: 1fr; grid-template-rows: 1fr; }

  .impr-page .cell {
    border-radius: 3px; overflow: hidden; position: relative;
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
    margin-bottom: 14px;
  }
  .impr-page .text-cell .quote {
    font-family: 'Cormorant Garamond', Georgia, serif;
    font-size: 24px; line-height: 1.22;
    color: var(--text-primary);
    font-weight: 300; font-style: italic;
    letter-spacing: -0.2px;
  }

  .impr-page .text-band {
    background: var(--bg-cream, #fdfcfa);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 18px 24px;
    display: flex; align-items: center; gap: 16px;
  }
  .impr-page .text-band .band-dash {
    width: 28px; height: 2px; background: var(--accent);
    flex-shrink: 0;
  }
  .impr-page .text-band .band-quote {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 22px; line-height: 1.25;
    color: var(--text-primary);
    font-weight: 400; font-style: italic;
    flex: 1;
  }

  .impr-page .m4-wrap { position: relative; overflow: hidden; border-radius: 3px; }
  .impr-page .m4-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .impr-page .m4-wrap::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.05) 0%, rgba(0,0,0,0.45) 60%, rgba(0,0,0,0.75) 100%);
  }
  .impr-page .m4-overlay {
    position: absolute; bottom: 32px; left: 40px; right: 40px;
    z-index: 2;
  }
  .impr-page .m4-overlay .dash {
    width: 34px; height: 2px; background: var(--accent);
    margin-bottom: 14px;
  }
  .impr-page .m4-overlay .quote {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 34px; line-height: 1.15;
    color: #fff;
    font-weight: 400; font-style: italic;
    text-shadow: 0 2px 8px rgba(0,0,0,0.35);
    max-width: 70%;
  }
</style>

<div class="page impr-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Impressionen</div>
    <div class="aline"></div>

    <div class="box {{ $layout }}">
        @if ($layout === 'M1')
            {{-- 3 Bilder + Text-Zelle unten-rechts --}}
            @foreach ($imgs->take(3) as $img)
                <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
            @endforeach
            <div class="text-cell">
                <div class="dash"></div>
                <div class="quote">{{ $caption ?: 'Wo Tageslicht den Raum formt.' }}</div>
            </div>

        @elseif ($layout === 'M3')
            {{-- Text-Band oben, darunter 3 Bilder asymmetrisch --}}
            <div class="text-band">
                <div class="band-dash"></div>
                <div class="band-quote">{{ $caption ?: 'Ein Ort, an dem Tage länger bleiben.' }}</div>
            </div>
            @foreach ($imgs->take(3) as $img)
                <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
            @endforeach

        @elseif ($layout === 'M4')
            {{-- Vollbild mit Overlay-Zitat --}}
            @if ($imgs->first())
                <div class="m4-wrap">
                    <img src="{{ $url($imgs->first()) }}" alt="">
                    <div class="m4-overlay">
                        <div class="dash"></div>
                        <div class="quote">{{ $caption ?: 'Mehr als vier Wände.' }}</div>
                    </div>
                </div>
            @endif

        @else
            {{-- Standard-Bild-Layouts L1-L5 + Masonry LM --}}
            @foreach ($imgs as $img)
                <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
            @endforeach
        @endif
    </div>
</div>
