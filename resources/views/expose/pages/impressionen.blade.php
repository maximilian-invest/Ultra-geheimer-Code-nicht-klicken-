@php
    $layout = $page['layout'] ?? 'L4';
    $ids = $page['image_ids'] ?? [];
    $imgs = collect($ids)->map(fn($id) => $ctx->image($id))->filter()->values();
    $url = fn($img) => asset('storage/' . $img->path);
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
  .impr-page .cell {
    border-radius: 3px; overflow: hidden;
  }
  .impr-page .cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
</style>

<div class="page impr-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Impressionen</div>
    <div class="aline"></div>
    <div class="box {{ $layout }}">
        @foreach ($imgs as $img)
            <div class="cell"><img src="{{ $url($img) }}" alt=""></div>
        @endforeach
    </div>
</div>
