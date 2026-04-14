<!-- resources/views/docs/error.blade.php -->
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unterlagen nicht verfuegbar</title>
    <link rel="stylesheet" href="{{ asset('docs/docs.css') }}">
</head>
<body>
<main class="docs-container error-page">
    @if ($reason === 'not_found')
        <h1>Link nicht gefunden</h1>
        <p>Dieser Link existiert nicht oder wurde entfernt. Bitte pruefen Sie den URL in Ihrer Email oder kontaktieren Sie uns.</p>
    @elseif ($reason === 'expired')
        <h1>Link abgelaufen</h1>
        <p>Dieser Link ist am {{ $link->expires_at->format('d.m.Y') }} abgelaufen.</p>
        <p>Gerne stellen wir Ihnen einen neuen Zugriff zur Verfuegung — kontaktieren Sie uns unter <strong>office@sr-homes.at</strong>.</p>
    @elseif ($reason === 'revoked')
        <h1>Zugriff beendet</h1>
        <p>Der Zugriff zu diesen Unterlagen wurde beendet. Bitte kontaktieren Sie uns fuer Details.</p>
    @elseif ($reason === 'rate_limited')
        <h1>Zu viele Versuche</h1>
        <p>Bitte versuchen Sie es in einer Stunde erneut.</p>
    @endif

    <a href="mailto:office@sr-homes.at" class="cta-link">office@sr-homes.at</a>
</main>
</body>
</html>
