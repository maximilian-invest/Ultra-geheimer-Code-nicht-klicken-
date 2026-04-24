@php
    $p = $ctx->property;
    $raw = $p->property_history;
    $entries = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $entries = $decoded;
    } elseif (is_array($raw)) {
        $entries = $raw;
    }

    // Nur nicht-leere Einträge (title oder description gesetzt)
    $entries = array_values(array_filter($entries, function ($e) {
        return !empty($e['title']) || !empty($e['description']);
    }));

    // Chronologisch absteigend: neueste zuerst. Einträge ohne Jahr ans Ende.
    usort($entries, function ($a, $b) {
        $ya = (int) ($a['year'] ?? 0);
        $yb = (int) ($b['year'] ?? 0);
        if ($ya === $yb) return 0;
        return $ya === 0 ? 1 : ($yb === 0 ? -1 : $yb <=> $ya);
    });

    // Gesamt-Zeitspanne für Intro-Zeile ("2010–2022 · 7 Eingriffe")
    $years = array_filter(array_map(fn($e) => (int) ($e['year'] ?? 0), $entries));
    $yearMin = $years ? min($years) : null;
    $yearMax = $years ? max($years) : null;
    $spanLabel = null;
    if ($yearMin && $yearMax) {
        $spanLabel = $yearMin === $yearMax ? (string) $yearMin : $yearMin . '–' . $yearMax;
    }
    $summaryLine = ($spanLabel ? $spanLabel . ' · ' : '') . count($entries) . ' ' . (count($entries) === 1 ? 'Maßnahme' : 'Maßnahmen');
@endphp

<style>
  .san-page .intro {
    position: absolute; top: 124px; left: 48px; right: 48px;
    font-family: Georgia, serif; font-style: italic;
    font-size: 15px; color: var(--text-secondary);
    letter-spacing: 0.5px;
  }
  .san-page .grid {
    position: absolute; top: 162px; left: 48px; right: 48px; bottom: 32px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
    align-content: start;
  }
  /* Bei wenigen Einträgen (≤ 3) in einer Spalte für mehr Atem */
  .san-page .grid.sparse { grid-template-columns: 1fr; }

  .san-page .card {
    display: flex; gap: 14px;
    padding: 14px 16px;
    background: #fff;
    border-left: 3px solid var(--accent);
    border-radius: 3px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
  }
  .san-page .year {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 24px;
    line-height: 1;
    color: var(--accent);
    font-weight: 400;
    flex-shrink: 0;
    min-width: 52px;
  }
  .san-page .body { flex: 1; min-width: 0; }
  .san-page .title-s-entry {
    font-family: Georgia, serif;
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 600;
    line-height: 1.2;
    margin-bottom: 3px;
  }
  .san-page .desc {
    font-size: 11.5px;
    line-height: 1.45;
    color: #555;
  }
  .san-page .year.empty {
    color: #bbb;
    font-size: 13px;
    font-style: italic;
    font-family: Georgia, serif;
    letter-spacing: 1px;
  }
</style>

<div class="page san-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Sanierungen</div>
    <div class="aline"></div>

    @if ($summaryLine)
        <div class="intro">{{ $summaryLine }}</div>
    @endif

    <div class="grid {{ count($entries) <= 3 ? 'sparse' : '' }}">
        @foreach ($entries as $entry)
            @php
                $year = trim((string) ($entry['year'] ?? ''));
                $title = trim((string) ($entry['title'] ?? ''));
                $desc = trim((string) ($entry['description'] ?? ''));
            @endphp
            <div class="card">
                @if ($year)
                    <div class="year">{{ $year }}</div>
                @else
                    <div class="year empty">o. Jahr</div>
                @endif
                <div class="body">
                    @if ($title)
                        <div class="title-s-entry">{{ $title }}</div>
                    @endif
                    @if ($desc)
                        <div class="desc">{{ $desc }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
