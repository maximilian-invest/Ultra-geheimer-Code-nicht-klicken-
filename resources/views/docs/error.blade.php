<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Unterlagen nicht verfuegbar</title></head>
<body>
<main>
    @if ($reason === 'not_found')
        <h1>Link nicht gefunden</h1>
        <p>Dieser Link existiert nicht oder wurde entfernt.</p>
    @elseif ($reason === 'expired')
        <h1>Link abgelaufen</h1>
        <p>Dieser Link ist am {{ $link->expires_at->format('d.m.Y') }} abgelaufen. Bitte kontaktieren Sie uns fuer einen neuen Link.</p>
    @elseif ($reason === 'revoked')
        <h1>Zugriff beendet</h1>
        <p>Der Zugriff wurde beendet. Bitte kontaktieren Sie uns fuer Details.</p>
    @endif
</main>
</body>
</html>
