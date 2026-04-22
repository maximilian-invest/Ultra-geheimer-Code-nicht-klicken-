<!DOCTYPE html>
<html lang="de">
<body style="font-family:sans-serif;color:#171717;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">

<p>Sehr geehrte/r {{ $owner['name'] ?? 'Damen und Herren' }},</p>

<p>vielen Dank für unseren heutigen Termin zur Aufnahme Ihrer Immobilie
{{ $property['address'] ?? '' }}.</p>

<p>Anbei finden Sie das unterschriebene Aufnahmeprotokoll als PDF.</p>

<p>Damit wir Ihr Objekt bestmöglich vermarkten können, benötigen wir noch folgende Unterlagen:</p>

<ul style="background:#f9fafb;padding:16px 24px;border-left:3px solid #EE7600">
    @foreach($missingDocs as $doc)
        <li>{{ $doc }}</li>
    @endforeach
</ul>

<p><strong>Zwei Möglichkeiten:</strong></p>

<p><strong>Variante A</strong> — Sie senden uns diese Unterlagen per E-Mail an
<a href="mailto:{{ $broker['email'] ?? 'office@sr-homes.at' }}">{{ $broker['email'] ?? 'office@sr-homes.at' }}</a>.</p>

<p><strong>Variante B</strong> — Sie unterschreiben den beigefügten Vermittlungsauftrag, dann holen wir die fehlenden Unterlagen direkt bei Ihrer Hausverwaltung ein.</p>

<p>Herzliche Grüße<br>
<strong>{{ $broker['name'] ?? 'Ihr SR-Homes Team' }}</strong><br>
SR-Homes Immobilien</p>

</body>
</html>
