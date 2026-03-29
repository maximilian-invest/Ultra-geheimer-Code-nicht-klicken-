<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 25px 35px 45px 35px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1e293b; line-height: 1.55; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 6px 35px 0 35px; border-top: 1px solid #e2e8f0; font-size: 7px; color: #94a3b8; }
    .section-title { font-size: 12px; font-weight: 700; color: #0f172a; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #c5943a; }
    .sub-title { font-size: 10px; font-weight: 600; color: #334155; margin: 10px 0 4px; }
    .page-break { page-break-before: always; }
    .no-break { page-break-inside: avoid; }
</style>
</head>
<body>

{{-- ============================================================ --}}
{{-- SEITE 1: DECKBLATT + ZUSAMMENFASSUNG                         --}}
{{-- ============================================================ --}}

{{-- HEADER --}}
<table style="width:100%; border-bottom:3px solid #c5943a; margin-bottom:16px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:8px; vertical-align:bottom;">
        @if(isset($logoBase64) && $logoBase64)
            <img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:36px; margin-bottom:2px;" /><br>
        @else
            <span style="font-size:20px; font-weight:bold; color:#1e293b;">@if(isset($logoBase64) && $logoBase64)<img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:18px;" />@else SR<span style="color:#c5943a;">-</span>HOMES @endif</span><br>
        @endif
        <span style="font-size:9px; color:#64748b;">IMMOBILIEN &middot; Vermarktungsbericht</span>
    </td>
    <td style="padding-bottom:8px; vertical-align:bottom; text-align:right; font-size:9px; color:#64748b;">
        Erstellt: {{ $generatedAt }}<br>
        Vertraulich &mdash; Nur f&uuml;r den Eigent&uuml;mer
    </td>
</tr>
</table>

{{-- OBJEKT-INFO --}}
<div style="font-size:16px; font-weight:bold; color:#0f172a; margin-bottom:2px;">{{ $property->address ?? 'Objekt' }}, {{ $property->city ?? '' }}</div>
<div style="font-size:9px; color:#64748b; margin-bottom:4px;">
    @if($property->ref_id)Ref: {{ $property->ref_id }} &middot; @endif
    {{ $property->type ?? '' }}
    @if($property->living_area) &middot; {{ $property->living_area }} m&sup2; @endif
    @if($property->rooms) &middot; {{ $property->rooms }} Zimmer @endif
    @if($property->purchase_price && $property->purchase_price > 0) &middot; &euro; {{ number_format($property->purchase_price, 0, ',', '.') }} @endif
</div>

{{-- STATUS BADGE --}}
@if(isset($owner['status']))
@php
    $raw = strtolower($owner['status'] ?? '');
    $sColor = match(true) { str_contains($raw,'gru') || str_contains($raw,'green') => '#16a34a', str_contains($raw,'gelb') || str_contains($raw,'yellow') => '#ca8a04', str_contains($raw,'orange') => '#ea580c', str_contains($raw,'rot') || str_contains($raw,'red') => '#dc2626', default => '#64748b' };
    $sLabel = match(true) { str_contains($raw,'gru') || str_contains($raw,'green') => 'Auf Kurs', str_contains($raw,'gelb') || str_contains($raw,'yellow') => 'Beobachten', str_contains($raw,'orange') => 'Handlungsbedarf', str_contains($raw,'rot') || str_contains($raw,'red') => 'Dringend', default => ucfirst($owner['status']) };
@endphp
<div style="margin:8px 0 12px;">
    <span style="background:{{ $sColor }}; color:#fff; font-size:9px; font-weight:600; padding:2px 10px;">{{ $sLabel }}</span>
    @if(isset($meta['datenqualitaet']))
        <span style="background:#f1f5f9; color:#64748b; font-size:8px; padding:2px 8px; margin-left:6px;">Datenqualit&auml;t: {{ $meta['datenqualitaet'] }}</span>
    @endif
</div>
@endif

<div style="border-bottom:1px solid #e2e8f0; margin-bottom:10px;"></div>

{{-- KURZFAZIT --}}
@if(isset($owner['kurzfazit']))
<div style="font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#0369a1; margin-bottom:5px;">Kurzfazit</div>
@if(isset($owner['kurzfazit']['stand']))
<div style="margin-bottom:3px;"><strong>Stand:</strong> {{ $owner['kurzfazit']['stand'] }}</div>
@endif
@if(isset($owner['kurzfazit']['erkenntnis']))
<div style="margin-bottom:3px;"><strong>Erkenntnis:</strong> {{ $owner['kurzfazit']['erkenntnis'] }}</div>
@endif
@if(isset($owner['kurzfazit']['ausblick']))
<div style="margin-bottom:3px;"><strong>Ausblick:</strong> {{ $owner['kurzfazit']['ausblick'] }}</div>
@endif
@endif

{{-- MARKTAUFNAHME --}}
<div class="section-title">Marktaufnahme</div>
@if(isset($owner['marktaufnahme']))
@php
    $resonanz = $owner['marktaufnahme']['resonanz'] ?? 'verhalten';
    $barColor = match($resonanz) { 'stark' => '#16a34a', 'verhalten' => '#ca8a04', 'schwach' => '#dc2626', default => '#ca8a04' };
    $barPct = match($resonanz) { 'stark' => 85, 'verhalten' => 55, 'schwach' => 25, default => 55 };
@endphp
<div class="sub-title">Resonanz: {{ ucfirst($resonanz) }}</div>
<table style="width:100%; margin-bottom:6px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="width:{{ $barPct }}%; background:{{ $barColor }}; font-size:1px; line-height:6px;">&nbsp;</td>
    <td style="width:{{ 100 - $barPct }}%; background:#e2e8f0; font-size:1px; line-height:6px;">&nbsp;</td>
</tr>
</table>
@if(isset($owner['marktaufnahme']['text']))
<div style="margin-bottom:8px;">{{ $owner['marktaufnahme']['text'] }}</div>
@endif
@endif

{{-- NACHFRAGEQUALITÄT (from broker data, anonymized for owner) --}}
@if(isset($broker['nachfragequalitaet']))
<div class="sub-title">Nachfragedetails</div>
<table style="width:100%; margin-bottom:10px;" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width:22%; padding:4px 6px; font-size:8px; font-weight:700; color:#64748b; border-bottom:1px solid #cbd5e1;">Dimension</td>
        <td style="padding:4px 6px; font-size:8px; font-weight:700; color:#64748b; border-bottom:1px solid #cbd5e1;">Bewertung</td>
    </tr>
    @foreach(['quantitaet' => 'Quantit&auml;t', 'qualitaet' => 'Qualit&auml;t', 'reifegrad' => 'Reifegrad', 'progression' => 'Entwicklung'] as $key => $label)
    @if(isset($broker['nachfragequalitaet'][$key]))
    <tr>
        <td style="padding:3px 6px; font-size:9px; font-weight:600; border-bottom:1px solid #f1f5f9;">{!! $label !!}</td>
        <td style="padding:3px 6px; font-size:9px; border-bottom:1px solid #f1f5f9;">{{ $broker['nachfragequalitaet'][$key] }}</td>
    </tr>
    @endif
    @endforeach
</table>
@endif

{{-- TRANSAKTIONSAUSBLICK --}}
<div class="section-title">Transaktionsausblick</div>
@if(isset($owner['transaktionsausblick']))
<table style="width:100%; margin-bottom:10px;" cellpadding="0" cellspacing="0">
<tr style="background:#f8fafc;">
    <td style="width:15%; padding:3px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Zeitraum</td>
    <td style="width:12%; padding:3px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Wahrsch.</td>
    <td style="padding:3px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Einsch&auml;tzung</td>
</tr>
@foreach(['tage_14' => '14 Tage', 'tage_30' => '30 Tage', 'tage_90' => '90 Tage'] as $key => $label)
    @if(isset($owner['transaktionsausblick'][$key]))
<tr>
    <td style="padding:4px 6px; font-size:9px; border-bottom:1px solid #f1f5f9;">{{ $label }}</td>
    <td style="padding:4px 6px; font-size:9px; font-weight:700; border-bottom:1px solid #f1f5f9;">{{ $owner['transaktionsausblick'][$key]['prozent'] ?? '-' }}%</td>
    <td style="padding:4px 6px; font-size:9px; border-bottom:1px solid #f1f5f9;">{{ $owner['transaktionsausblick'][$key]['text'] ?? '' }}</td>
</tr>
    @endif
@endforeach
</table>
@endif


{{-- ============================================================ --}}
{{-- SEITE 2: FEEDBACK-ANALYSE & STÄRKEN/HEMMNISSE                --}}
{{-- ============================================================ --}}
<div class="page-break"></div>

{{-- Mini-Header Seite 2 --}}
<table style="width:100%; border-bottom:2px solid #c5943a; margin-bottom:14px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:6px; font-size:10px; font-weight:bold; color:#1e293b;">@if(isset($logoBase64) && $logoBase64)<img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:18px;" />@else SR<span style="color:#c5943a;">-</span>HOMES @endif &middot; {{ $property->address ?? '' }}, {{ $property->city ?? '' }}</td>
    <td style="padding-bottom:6px; text-align:right; font-size:8px; color:#94a3b8;">Seite 2 von {{ isset($owner['szenarien']) || isset($owner['szenario_ohne_aktion']) ? '4' : '3' }}</td>
</tr>
</table>

{{-- FEEDBACK-ANALYSE --}}
@if(isset($broker['feedback_cluster']) && count($broker['feedback_cluster']) > 0)
<div class="section-title">Feedback-Analyse</div>
<div style="margin-bottom:6px; font-size:9px; color:#475569;">Zusammenfassung des Interessenten-Feedbacks aus {{ array_sum(array_column($broker['feedback_cluster'], 'anzahl')) }} Einzelr&uuml;ckmeldungen:</div>

<table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
<tr style="background:#f8fafc;">
    <td style="width:18%; padding:4px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Thema</td>
    <td style="width:10%; padding:4px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Anzahl</td>
    <td style="width:15%; padding:4px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Gewicht</td>
    <td style="padding:4px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Details</td>
</tr>
@foreach($broker['feedback_cluster'] as $cluster)
@php
    $gewichtColor = match($cluster['gewicht'] ?? '') { 'transaktionskritisch' => '#dc2626', 'substanziell' => '#ca8a04', default => '#64748b' };
@endphp
<tr>
    <td style="padding:5px 6px; font-size:9px; font-weight:600; border-bottom:1px solid #f1f5f9;">{{ $cluster['thema'] ?? '' }}</td>
    <td style="padding:5px 6px; font-size:9px; font-weight:700; border-bottom:1px solid #f1f5f9;">{{ $cluster['anzahl'] ?? '' }}</td>
    <td style="padding:5px 6px; font-size:8px; color:{{ $gewichtColor }}; font-weight:600; border-bottom:1px solid #f1f5f9;">{{ ucfirst($cluster['gewicht'] ?? '') }}</td>
    <td style="padding:5px 6px; font-size:9px; border-bottom:1px solid #f1f5f9;">{{ $cluster['details'] ?? '' }}</td>
</tr>
@endforeach
</table>
@endif

{{-- PREIS-MARKT-FIT --}}
@if(isset($broker['preis_markt_fit']))
<div class="section-title">Preis-Markt-Analyse</div>
@php
    $pmfColor = match($broker['preis_markt_fit']['bewertung'] ?? '') { 'passend' => '#16a34a', 'leicht_ambitioniert' => '#ca8a04', 'ambitioniert' => '#ea580c', 'deutlich_ambitioniert' => '#dc2626', default => '#64748b' };
    $pmfLabel = match($broker['preis_markt_fit']['bewertung'] ?? '') { 'passend' => 'Passend', 'leicht_ambitioniert' => 'Leicht ambitioniert', 'ambitioniert' => 'Ambitioniert', 'deutlich_ambitioniert' => 'Deutlich ambitioniert', default => ucfirst($broker['preis_markt_fit']['bewertung'] ?? '-') };
@endphp
<div style="margin-bottom:4px;">
    <span style="font-weight:600;">Bewertung:</span>
    <span style="color:{{ $pmfColor }}; font-weight:700;">{{ $pmfLabel }}</span>
</div>
<div style="margin-bottom:8px;">{{ $broker['preis_markt_fit']['begruendung'] ?? '' }}</div>

@if(isset($broker['preisargumentation']['eigentuemer_gespraech']))
<div class="sub-title">Einsch&auml;tzung f&uuml;r Sie</div>
<div style="background:#f8fafc; padding:8px 10px; margin-bottom:10px;">{{ $broker['preisargumentation']['eigentuemer_gespraech'] }}</div>
@endif
@endif

{{-- STÄRKEN & HEMMNISSE --}}
<div class="section-title">St&auml;rken &amp; Hemmnisse</div>
<table style="width:100%; margin-bottom:10px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="width:48%; vertical-align:top; padding-right:10px;">
        <div style="font-weight:600; color:#16a34a; margin-bottom:4px;">&#10003; St&auml;rken</div>
        @foreach(($owner['staerken'] ?? []) as $s)
        <div style="font-size:9px; margin-bottom:3px;">&#8226; {{ $s }}</div>
        @endforeach
    </td>
    <td style="width:4%; background:#e2e8f0; font-size:1px;">&nbsp;</td>
    <td style="width:48%; vertical-align:top; padding-left:10px;">
        <div style="font-weight:600; color:#dc2626; margin-bottom:4px;">&#10007; Hemmnisse</div>
        @foreach(($owner['hemmnisse'] ?? []) as $h)
        <div style="font-size:9px; margin-bottom:3px;">&#8226; {{ $h }}</div>
        @endforeach
    </td>
</tr>
</table>


{{-- ============================================================ --}}
{{-- SEITE 3: RISIKO, EMPFEHLUNGEN, SZENARIEN                     --}}
{{-- ============================================================ --}}
<div class="page-break"></div>

{{-- Mini-Header Seite 3 --}}
<table style="width:100%; border-bottom:2px solid #c5943a; margin-bottom:14px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:6px; font-size:10px; font-weight:bold; color:#1e293b;">@if(isset($logoBase64) && $logoBase64)<img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:18px;" />@else SR<span style="color:#c5943a;">-</span>HOMES @endif &middot; {{ $property->address ?? '' }}, {{ $property->city ?? '' }}</td>
    <td style="padding-bottom:6px; text-align:right; font-size:8px; color:#94a3b8;">Seite 3 von {{ isset($owner['szenarien']) || isset($owner['szenario_ohne_aktion']) ? '4' : '3' }}</td>
</tr>
</table>

{{-- RISIKOBEWERTUNG --}}
@if(isset($broker['risiko']))
<div class="section-title">Risikobewertung</div>
<table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
<tr style="background:#f8fafc;">
    <td style="width:25%; padding:4px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Risikotyp</td>
    <td style="padding:4px 6px; font-size:8px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Einsch&auml;tzung</td>
</tr>
@foreach(['marktalterung' => 'Marktalterung', 'imageverlust' => 'Imageverlust', 'zeitverlust' => 'Zeitverlust'] as $key => $label)
@if(isset($broker['risiko'][$key]))
<tr>
    <td style="padding:5px 6px; font-size:9px; font-weight:600; border-bottom:1px solid #f1f5f9;">{{ $label }}</td>
    <td style="padding:5px 6px; font-size:9px; border-bottom:1px solid #f1f5f9;">{{ $broker['risiko'][$key] }}</td>
</tr>
@endif
@endforeach
</table>
@endif

{{-- EMPFOHLENE MASSNAHMEN --}}
@if(isset($owner['empfohlene_schritte']) && count($owner['empfohlene_schritte']) > 0)
<div class="section-title">Empfohlene Ma&szlig;nahmen</div>
@foreach($owner['empfohlene_schritte'] as $i => $step)
<div style="border-left:3px solid #c5943a; padding-left:10px; margin-bottom:8px;" class="no-break">
    <div style="font-size:10px; font-weight:600; color:#0f172a;">{{ $i + 1 }}. {{ $step['titel'] ?? '' }}</div>
    @if(isset($step['text']))
    <div style="font-size:9px; color:#475569;">{{ $step['text'] }}</div>
    @endif
</div>
@endforeach
@endif

{{-- DETAILLIERTE EMPFEHLUNGSLOGIK --}}
@if(isset($broker['empfehlungslogik']) && count($broker['empfehlungslogik']) > 0)
<div class="sub-title" style="margin-top:14px;">Detaillierte Ma&szlig;nahmenanalyse</div>
@foreach($broker['empfehlungslogik'] as $emp)
<div style="background:#f8fafc; padding:8px 10px; margin-bottom:8px;" class="no-break">
    <div style="font-size:10px; font-weight:600; color:#0f172a; margin-bottom:2px;">{{ $emp['was'] ?? '' }}</div>
    <div style="font-size:9px; color:#475569; margin-bottom:3px;"><strong>Warum:</strong> {{ $emp['warum'] ?? '' }}</div>
    @if(isset($emp['signale']) && count($emp['signale']) > 0)
    <div style="font-size:8px; color:#64748b;">
        <strong>Signale:</strong>
        @foreach($emp['signale'] as $sig)
        &middot; {{ $sig }}
        @endforeach
    </div>
    @endif
    @if(isset($emp['erwarteter_effekt']))
    <div style="font-size:9px; color:#16a34a; margin-top:2px;"><strong>Erwarteter Effekt:</strong> {{ $emp['erwarteter_effekt'] }}</div>
    @endif
</div>
@endforeach
@endif

{{-- SZENARIEN --}}
@php
    $hatSzenarien = isset($owner['szenarien']) || (isset($owner['szenario_ohne_aktion']) && isset($owner['szenario_mit_aktion']));
    $ohneAktion = $owner['szenarien']['szenario_ohne_aktion'] ?? $owner['szenario_ohne_aktion'] ?? null;
    $mitAktion = $owner['szenarien']['szenario_mit_aktion'] ?? $owner['szenario_mit_aktion'] ?? null;
@endphp
@if($hatSzenarien)
<div class="section-title" style="margin-top:16px;">Szenarien</div>
<table style="width:100%; margin-bottom:10px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="width:48%; vertical-align:top; padding-right:8px;">
        @if($ohneAktion)
        <div style="background:#fef2f2; padding:8px 10px;">
            <div style="font-size:8px; font-weight:700; text-transform:uppercase; color:#dc2626; margin-bottom:3px;">Ohne Ma&szlig;nahmen</div>
            <div style="font-size:9px; color:#334155;">{{ $ohneAktion }}</div>
        </div>
        @endif
    </td>
    <td style="width:4%;"></td>
    <td style="width:48%; vertical-align:top; padding-left:8px;">
        @if($mitAktion)
        <div style="background:#f0fdf4; padding:8px 10px;">
            <div style="font-size:8px; font-weight:700; text-transform:uppercase; color:#16a34a; margin-bottom:3px;">Mit Ma&szlig;nahmen</div>
            <div style="font-size:9px; color:#334155;">{{ $mitAktion }}</div>
        </div>
        @endif
    </td>
</tr>
</table>
@endif

{{-- ABSCHLUSS / DISCLAIMER --}}
<div style="border-top:1px solid #e2e8f0; padding-top:10px; margin-top:16px;">
    <div style="font-size:8px; color:#94a3b8; line-height:1.4;">
        <strong>Hinweis:</strong> Dieser Bericht wurde auf Basis der aktuellen Marktdaten, Anfragen und Interessenten-Feedbacks erstellt.
        Die Einsch&auml;tzungen und Prognosen basieren auf der derzeitigen Datenlage und k&ouml;nnen sich bei ver&auml;nderten Marktbedingungen anpassen.
        Alle Angaben ohne Gew&auml;hr. Bei Fragen stehen wir Ihnen gerne pers&ouml;nlich zur Verf&uuml;gung.
    </div>
    <div style="font-size:9px; font-weight:600; color:#1e293b; margin-top:10px;">
        SR-Homes Immobilien GmbH<br>
        <span style="font-size:8px; font-weight:400; color:#64748b;">Ihr Partner f&uuml;r erfolgreiche Immobilienvermarktung</span>
    </div>
</div>

{{-- FOOTER --}}
<div class="footer">
    <table style="width:100%;" cellpadding="0" cellspacing="0">
    <tr>
        <td style="font-size:7px; color:#94a3b8;">SR-Homes Immobilien GmbH &middot; Vermarktungsbericht &middot; Vertraulich</td>
        <td style="font-size:7px; color:#94a3b8; text-align:right;">{{ $generatedAt }}</td>
    </tr>
    </table>
</div>

</body>
</html>
