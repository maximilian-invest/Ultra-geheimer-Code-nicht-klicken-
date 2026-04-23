@php
    $img = $ctx->image($page['image_id'] ?? null);
    $imgUrl = $img ? asset('storage/' . $img->path) : null;
    $text = $ctx->property->realty_description ?? '';
    $mode = $ctx->hausTextMode;
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n+/', $text))));
    $leadSentence = '';
    $rest = $paragraphs;
    if (!empty($paragraphs)) {
        // Ersten Satz als Lead extrahieren
        $first = $paragraphs[0];
        if (preg_match('/^(.+?[.!?])(\s|$)(.*)/s', $first, $m)) {
            $leadSentence = trim($m[1]);
            $rest = array_merge([trim($m[3])], array_slice($paragraphs, 1));
            $rest = array_values(array_filter($rest));
        } else {
            $leadSentence = $first;
            $rest = array_slice($paragraphs, 1);
        }
    }
@endphp

<style>
  .haus-page .layout {
    position: absolute; top: 112px; left: 48px; right: 48px; bottom: 28px;
    display: flex; gap: 32px;
  }
  .haus-page .txt { flex: 1.2; }
  .haus-page .img-wrap { flex: 1; border-radius: 3px; overflow: hidden; }
  .haus-page .img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .haus-page .lead {
    font-family: Georgia, serif; font-style: italic; font-size: 18px; line-height: 1.4;
    color: var(--text-primary); margin-bottom: 14px;
  }
  .haus-page .p { font-size: 13px; line-height: 1.6; color: #333; margin-bottom: 8px; }
  .haus-page .cols-2 { column-count: 2; column-gap: 24px; column-rule: 1px solid var(--border); }
  .haus-page .cols-3 { column-count: 3; column-gap: 18px; column-rule: 1px solid var(--border); }
  .haus-page .cont-hint {
    position: absolute; bottom: 14px; right: 48px;
    font-size: 10px; color: var(--accent); letter-spacing: 2px;
    text-transform: uppercase; font-weight: 700;
  }
</style>

<div class="page haus-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Das Haus</div>
    <div class="aline"></div>

    @if ($mode === 'short')
        <div class="layout">
            <div class="txt">
                @if ($leadSentence)
                    <div class="lead">{{ $leadSentence }}</div>
                @endif
                @foreach ($rest as $para)
                    <div class="p">{{ $para }}</div>
                @endforeach
            </div>
            @if ($imgUrl)
                <div class="img-wrap"><img src="{{ $imgUrl }}" alt=""></div>
            @endif
        </div>
    @elseif ($mode === 'medium')
        <div class="layout" style="display:block">
            @if ($leadSentence)
                <div class="lead">{{ $leadSentence }}</div>
            @endif
            <div class="cols-2">
                @foreach ($rest as $para)
                    <div class="p">{{ $para }}</div>
                @endforeach
            </div>
        </div>
    @else
        <div class="layout" style="display:block">
            @if ($leadSentence)
                <div class="lead">{{ $leadSentence }}</div>
            @endif
            <div class="cols-3">
                @foreach ($rest as $para)
                    <div class="p">{{ $para }}</div>
                @endforeach
            </div>
        </div>
    @endif
</div>
