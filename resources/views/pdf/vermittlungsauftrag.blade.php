<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
body { font-family: sans-serif; font-size: 10pt; color: #171717; line-height: 1.5; }
h1 { font-size: 16pt; text-align: center; margin-bottom: 4px; }
.subtitle { text-align: center; color: #525252; margin-bottom: 24px; }
.party-block { margin: 16px 0; padding: 12px; background: #f9fafb; border-radius: 4px; }
.signature-line { display: inline-block; width: 45%; border-bottom: 1px solid #171717; height: 40px; margin-top: 60px; }
.signature-label { font-size: 9pt; color: #737373; display: inline-block; width: 45%; }
ol { padding-left: 20px; }
ol li { margin-bottom: 6px; }
</style>
</head>
<body>

<h1>Alleinvermittlungsauftrag</h1>
<div class="subtitle">zwischen Eigentümer und Makler · Ref-ID {{ $property['ref_id'] ?? '–' }}</div>

<div class="party-block">
    <strong>Eigentümer (Auftraggeber):</strong><br>
    {{ $owner['name'] ?? '–' }}<br>
    {{ $owner['address'] ?? '' }}<br>
    {{ $owner['zip'] ?? '' }} {{ $owner['city'] ?? '' }}<br>
    @if(!empty($owner['email'])) E-Mail: {{ $owner['email'] }}<br> @endif
    @if(!empty($owner['phone'])) Telefon: {{ $owner['phone'] }} @endif
</div>

<div class="party-block">
    <strong>Makler (Auftragnehmer):</strong><br>
    {{ $broker['name'] ?? '–' }}<br>
    {{ $broker['company'] ?? 'SR-Homes Immobilien GmbH' }}
</div>

<h3>Vermittlungsobjekt</h3>
<p>
    {{ $property['address'] ?? '' }} {{ $property['house_number'] ?? '' }},
    {{ $property['zip'] ?? '' }} {{ $property['city'] ?? '' }}<br>
    Ref-ID: <strong>{{ $property['ref_id'] ?? '–' }}</strong>
</p>

<h3>Vereinbarungen</h3>
<ol>
    <li>Der Eigentümer beauftragt den Makler mit der Vermittlung des oben genannten Objekts auf Alleinbasis.</li>
    <li>Die Käuferprovision beträgt {{ $commission_percent ?? 3.0 }}% des Kaufpreises zzgl. gesetzlicher USt.</li>
    <li>Der Makler ist berechtigt, zur Vermarktung erforderliche Unterlagen (Grundbuch, Energieausweis, Nutzwertgutachten, Rücklagenstand etc.) direkt bei der zuständigen Hausverwaltung, dem Grundbuchsamt oder anderen Stellen einzuholen. Der Eigentümer bevollmächtigt den Makler hierzu ausdrücklich.</li>
    <li>Der Auftrag gilt für 6 Monate ab Unterschrift und verlängert sich automatisch um jeweils 3 Monate, sofern er nicht mit einer Frist von 4 Wochen schriftlich gekündigt wird.</li>
    <li>Die Vermarktung auf allen gängigen Plattformen (willhaben, Immobilienscout24, ImmoWelt, SR-Homes-Website) ist vom Eigentümer genehmigt.</li>
</ol>

<div style="margin-top: 40px;">
    <span class="signature-line"></span>
    <span style="display: inline-block; width: 5%;"></span>
    <span class="signature-line"></span><br>
    <span class="signature-label">Eigentümer — Datum, Unterschrift</span>
    <span style="display: inline-block; width: 5%;"></span>
    <span class="signature-label">Makler — Datum, Unterschrift</span>
</div>

</body>
</html>
