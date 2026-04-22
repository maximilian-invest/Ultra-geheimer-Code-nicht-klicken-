<!DOCTYPE html>
<html lang="de">
<body style="font-family:sans-serif;color:#171717;line-height:1.6;max-width:600px;margin:0 auto;padding:24px">

<p>Sehr geehrte/r {{ $owner['name'] ?? 'Damen und Herren' }},</p>

<p>vielen Dank für unseren heutigen Termin zur Aufnahme Ihrer Immobilie
{{ $property['address'] ?? '' }}.</p>

<p>Anbei finden Sie das unterschriebene Aufnahmeprotokoll als PDF-Anhang zu Ihrer Unterlage.</p>

<p>Wir melden uns in den nächsten Tagen mit dem Vermittlungsauftrag und den weiteren Schritten.</p>

<p>Herzliche Grüße<br>
<strong>{{ $broker['name'] ?? 'Ihr SR-Homes Team' }}</strong><br>
SR-Homes Immobilien</p>

</body>
</html>
