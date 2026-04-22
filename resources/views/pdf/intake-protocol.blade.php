<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
body { font-family: sans-serif; font-size: 10pt; color: #171717; }
h1 { font-size: 18pt; margin-bottom: 4px; }
h2 { font-size: 12pt; margin-top: 18px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
table.data td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
table.data td.label { color: #737373; font-size: 9pt; width: 35%; }
.signature-block { margin-top: 40px; padding-top: 20px; border-top: 2px solid #171717; }
.sig-image { max-height: 80px; max-width: 320px; border: 1px solid #e5e5e5; }
.disclaimer { background: #f9fafb; padding: 12px; border-left: 3px solid #EE7600; margin-top: 12px; font-size: 9pt; }
.audit { font-size: 7pt; color: #a3a3a3; margin-top: 24px; }
</style>
</head>
<body>

<h1>Aufnahmeprotokoll</h1>
<div style="font-size: 11pt; color: #525252;">
    {{ $property['address'] ?? '' }} {{ $property['house_number'] ?? '' }},
    {{ $property['zip'] ?? '' }} {{ $property['city'] ?? '' }}
</div>
<div style="margin-top: 8px; font-size: 9pt; color: #737373;">
    Ref-ID: <strong>{{ $property['ref_id'] ?? '–' }}</strong>
    · Aufnahme-Datum: {{ $signed_at instanceof \DateTimeInterface ? $signed_at->format('d.m.Y') : ($signed_at ?? '–') }}
</div>

<h2>Eigentümer</h2>
<table class="data">
    <tr><td class="label">Name</td><td>{{ $owner['name'] ?? '–' }}</td></tr>
    <tr><td class="label">E-Mail</td><td>{{ $owner['email'] ?? '–' }}</td></tr>
    @if(!empty($owner['phone']))
    <tr><td class="label">Telefon</td><td>{{ $owner['phone'] }}</td></tr>
    @endif
</table>

<h2>Objekt-Stammdaten</h2>
<table class="data">
    <tr><td class="label">Typ</td><td>{{ $property['object_type'] ?? '–' }} @if(!empty($property['object_subtype'])) ({{ $property['object_subtype'] }}) @endif</td></tr>
    <tr><td class="label">Vermarktung</td><td>{{ $property['marketing_type'] ?? '–' }}</td></tr>
    @if(!empty($property['living_area']))
    <tr><td class="label">Wohnfläche</td><td>{{ $property['living_area'] }} m²</td></tr>
    @endif
    @if(!empty($property['rooms_amount']))
    <tr><td class="label">Zimmer</td><td>{{ $property['rooms_amount'] }}</td></tr>
    @endif
    @if(!empty($property['construction_year']))
    <tr><td class="label">Baujahr</td><td>{{ $property['construction_year'] }}</td></tr>
    @endif
</table>

@if(!empty($broker_notes))
<h2>Notizen vom Termin</h2>
<p style="white-space: pre-line;">{{ $broker_notes }}</p>
@endif

<div class="signature-block">
    <div class="disclaimer">{{ $disclaimer_text }}</div>
    <div style="margin-top: 20px;">
        @if(!empty($signature_png_path) && file_exists(storage_path('app/' . $signature_png_path)))
            <img class="sig-image" src="{{ storage_path('app/' . $signature_png_path) }}" alt="Unterschrift">
        @else
            <div style="border-bottom: 1px solid #999; width: 320px; height: 60px;"></div>
        @endif
        <div style="font-size: 9pt; color: #525252; margin-top: 4px;">
            {{ $signed_by_name ?? '–' }},
            {{ $signed_at instanceof \DateTimeInterface ? $signed_at->format('d.m.Y H:i') : ($signed_at ?? '–') }}
        </div>
    </div>
    <div style="margin-top: 20px;">
        <div style="border-bottom: 1px solid #999; width: 320px; height: 60px;"></div>
        <div style="font-size: 9pt; color: #525252; margin-top: 4px;">
            {{ $broker['name'] ?? '–' }} (Makler)
        </div>
    </div>
</div>

<div class="audit">
    @if(!empty($client_ip)) IP: {{ $client_ip }} @endif
    @if(!empty($user_agent)) · UA: {{ substr($user_agent, 0, 120) }} @endif
</div>

</body>
</html>
