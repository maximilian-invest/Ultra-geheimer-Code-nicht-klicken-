<!DOCTYPE html>
<html lang="de">
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#171717;line-height:1.6;font-size:15px;max-width:600px;margin:0 auto;padding:24px;background:#ffffff">
@php
    // Robuste Umwandlung des Plain-Text-Bodies in strukturiertes HTML.
    // Mail-Clients (Gmail, Outlook, iOS Mail) kollabieren \n ohne
    // explizite <br>/<p>-Tags — `white-space:pre-line` wird oft gestrippt.
    // Daher: Leerzeilen (\n\n) = neuer Paragraph, \n = <br>.
    $escaped = e($body ?? '');
    $paragraphs = preg_split('/\n\s*\n+/', $escaped);
@endphp
@foreach ($paragraphs as $p)
    @php($clean = trim($p))
    @if ($clean !== '')
        <p style="margin:0 0 14px 0">{!! nl2br($clean) !!}</p>
    @endif
@endforeach
</body>
</html>
