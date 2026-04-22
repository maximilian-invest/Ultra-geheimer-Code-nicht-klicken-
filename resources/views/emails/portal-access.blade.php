<!DOCTYPE html>
<html lang="de">
<body style="font-family:sans-serif;color:#171717;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">

<p>Sehr geehrte/r {{ $owner['name'] ?? 'Damen und Herren' }},</p>

<p>wir haben für Sie einen Zugang zum SR-Homes-Kundenportal angelegt. Dort sehen Sie jederzeit aktuelle Informationen zu Ihrer Immobilie — Aktivitäten, Dokumente, Interessenten-Anfragen und Besichtigungen.</p>

<p style="background:#f9fafb;padding:16px;border-radius:8px;border-left:3px solid #EE7600;font-family:monospace">
    <strong>Login:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
    <strong>E-Mail:</strong> {{ $loginEmail }}<br>
    <strong>Initiales Passwort:</strong> {{ $initialPassword }}
</p>

<p><strong>Wichtig:</strong> Bitte ändern Sie das Passwort nach dem ersten Login in den Einstellungen.</p>

<p>Bei Fragen wenden Sie sich direkt an
<a href="mailto:{{ $broker['email'] ?? 'office@sr-homes.at' }}">{{ $broker['email'] ?? 'office@sr-homes.at' }}</a>.</p>

<p>Herzliche Grüße<br>
<strong>{{ $broker['name'] ?? 'Ihr SR-Homes Team' }}</strong></p>

</body>
</html>
