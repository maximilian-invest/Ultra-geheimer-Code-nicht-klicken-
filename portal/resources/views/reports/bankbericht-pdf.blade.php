<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 30px 40px 65px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; line-height: 1.65; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 8px 40px 0 40px; border-top: 1px solid #e2e8f0; font-size: 8px; color: #94a3b8; }
    .section-title { font-size: 16px; font-weight: 700; color: #0f172a; margin: 22px 0 10px; padding-bottom: 5px; border-bottom: 2px solid #ee7606; }
    .sub-title { font-size: 13px; font-weight: 600; color: #334155; margin: 14px 0 6px; }
    .page-break { page-break-before: always; }
    .no-break { page-break-inside: avoid; }
    .kpi-box { background: #f8fafc; padding: 14px 16px; text-align: center; border-radius: 4px; }
    .kpi-value { font-size: 24px; font-weight: 700; color: #0f172a; }
    .kpi-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    .positive { color: #10b981; }
    .highlight-box { background: #fff7ed; border-left: 4px solid #ee7606; padding: 12px 16px; margin: 12px 0; }
</style>
</head>
<body>

{{-- ============================================================ --}}
{{-- SEITE 1: DECKBLATT + EXECUTIVE SUMMARY                       --}}
{{-- ============================================================ --}}

{{-- HEADER --}}
<table style="width:100%; border-bottom:3px solid #ee7606; margin-bottom:16px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:8px; vertical-align:bottom;">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:36px; margin-bottom:2px;" /><br>
        @else
            <span style="font-size:20px; font-weight:bold; color:#1e293b;">SR<span style="color:#ee7606;">-</span>HOMES</span><br>
        @endif
        <span style="font-size:12px; color:#64748b;">IMMOBILIEN GMBH &middot; Vertriebsbericht</span>
    </td>
    <td style="padding-bottom:8px; vertical-align:bottom; text-align:right; font-size:12px; color:#64748b;">
        Erstellt: {{ $generatedAt }}<br>
        Vertraulich
    </td>
</tr>
</table>

{{-- OBJEKT-INFO --}}
<div style="font-size:22px; font-weight:bold; color:#0f172a; margin-bottom:4px;">Vertriebsbericht</div>
<div style="font-size:16px; font-weight:600; color:#334155; margin-bottom:2px;">{{ $property->address ?? 'Projekt' }}, {{ $property->city ?? '' }}</div>
<div style="font-size:12px; color:#64748b; margin-bottom:4px;">
    @if($property->ref_id)Ref: {{ $property->ref_id }} &middot; @endif
    Neubauprojekt &middot; {{ $totalUnits }} Einheiten
    @if($property->zip) &middot; {{ $property->zip }} {{ $property->city ?? '' }} @endif
</div>

<div style="border-bottom:1px solid #e2e8f0; margin:12px 0;"></div>

{{-- EXECUTIVE SUMMARY --}}
<div class="section-title">Executive Summary</div>

<div class="highlight-box">
    <div style="font-size:12px; font-weight:600; color:#0f172a; margin-bottom:4px;">Zusammenfassung</div>
    <div style="font-size:12px; color:#334155; line-height:1.6;">
        Das Projekt <strong>{{ $property->address ?? '' }}, {{ $property->city ?? '' }}</strong> wird sehr gut vom Markt aufgenommen.
        @if($verkaufsquote >= 50)
            Mit einer Verkaufsquote von {{ $verkaufsquote }}% liegt das Projekt &uuml;ber den Markterwartungen.
        @elseif($verkaufsquote >= 25)
            Mit einer Verkaufsquote von {{ $verkaufsquote }}% befindet sich das Projekt planm&auml;&szlig;ig in der Vermarktung.
        @else
            Die Vermarktung hat begonnen und es besteht reges Interesse am Projekt.
        @endif
        Insgesamt wurden <strong>{{ $uniqueInteressenten }} Interessenten</strong> erfasst, was die Attraktivit&auml;t des Standorts und der Preisgestaltung best&auml;tigt.
        Die Nachfrage ist stabil und die Konversionsraten entsprechen den Erwartungen f&uuml;r Neubauprojekte in dieser Lage.
    </div>
</div>

{{-- KPI OVERVIEW --}}
<table style="width:100%; margin-top:14px;" cellpadding="0" cellspacing="6">
<tr>
    <td style="width:33%;">
        <div class="kpi-box">
            <div class="kpi-value positive">{{ $verkaufsquote }}%</div>
            <div class="kpi-label">Verkaufsquote</div>
        </div>
    </td>
    <td style="width:33%;">
        <div class="kpi-box">
            <div class="kpi-value">{{ $avgInquiriesPerWeek }}</div>
            <div class="kpi-label">Anfragen / Woche</div>
        </div>
    </td>
    <td style="width:34%;">
        <div class="kpi-box">
            <div class="kpi-value">&euro; {{ number_format($soldVolume, 0, ',', '.') }}</div>
            <div class="kpi-label">Verkauftes Volumen</div>
        </div>
    </td>
</tr>
</table>


{{-- ============================================================ --}}
{{-- SEITE 2: VERKAUFSKENNZAHLEN                                  --}}
{{-- ============================================================ --}}
<div class="page-break"></div>

{{-- Mini-Header --}}
<table style="width:100%; border-bottom:2px solid #ee7606; margin-bottom:14px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:6px; font-size:12px; font-weight:bold; color:#1e293b;">SR<span style="color:#ee7606;">-</span>HOMES &middot; {{ $property->address ?? '' }}, {{ $property->city ?? '' }}</td>
    <td style="padding-bottom:6px; text-align:right; font-size:12px; color:#94a3b8;">Seite 2 von 3</td>
</tr>
</table>

<div class="section-title">Verkaufskennzahlen</div>

<table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
<tr style="background:#ee7606;">
    <td style="width:50%; padding:10px 14px; font-size:12px; font-weight:700; color:#fff; border-bottom:1px solid #d16805;">Kennzahl</td>
    <td style="width:50%; padding:10px 14px; font-size:12px; font-weight:700; color:#fff; border-bottom:1px solid #d16805; text-align:right;">Wert</td>
</tr>
<tr style="background:#fff7ed;">
    <td style="padding:10px 14px; font-size:12px; font-weight:600; border-bottom:1px solid #fed7aa;">Verkauftes Volumen</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:700; border-bottom:1px solid #fed7aa; text-align:right;">&euro; {{ number_format($soldVolume, 0, ',', '.') }}</td>
</tr>
<tr style="background:#f8fafc;">
    <td style="padding:10px 14px; font-size:12px; border-bottom:1px solid #f1f5f9;">Verkaufsquote</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:700; color:#10b981; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $verkaufsquote }}%</td>
</tr>
<tr style="background:#f8fafc;">
    <td style="padding:10px 14px; font-size:12px; border-bottom:1px solid #f1f5f9;">Anfragen gesamt (unique Interessenten)</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $uniqueInteressenten }}</td>
</tr>
<tr>
    <td style="padding:10px 14px; font-size:12px; border-bottom:1px solid #f1f5f9;">Besichtigungen / Beratungsgespr&auml;che</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $besichtigungenCount }}</td>
</tr>
<tr style="background:#f8fafc;">
    <td style="padding:10px 14px; font-size:12px; border-bottom:1px solid #f1f5f9;">Kaufanbote eingegangen</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $kaufanboteCount }}</td>
</tr>
<tr style="background:#f8fafc;">
    <td style="padding:10px 14px; font-size:12px; border-bottom:1px solid #f1f5f9;">Preisspanne</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">&euro; {{ number_format($preisMin, 0, ',', '.') }} &ndash; &euro; {{ number_format($preisMax, 0, ',', '.') }}</td>
</tr>
<tr>
    <td style="padding:10px 14px; font-size:12px; border-bottom:1px solid #f1f5f9;">Durchschnittspreis pro m&sup2;</td>
    <td style="padding:10px 14px; font-size:12px; font-weight:700; color:#0f172a; border-bottom:1px solid #f1f5f9; text-align:right;">&euro; {{ number_format($avgPricePerM2, 0, ',', '.') }} / m&sup2;</td>
</tr>
</table>

{{-- Verkaufsfortschritt Balken --}}
<div class="sub-title">Verkaufsfortschritt</div>
<table style="width:100%; margin-bottom:6px;" cellpadding="0" cellspacing="0">
<tr>
    @if($verkaufsquote > 0)
    <td style="width:{{ max($verkaufsquote, 8) }}%; background:#10b981; font-size:12px; color:#fff; text-align:center; line-height:16px; font-weight:600;">{{ $verkaufsquote }}% verkauft</td>
    @endif
    @php $freiPct = 100 - $verkaufsquote; @endphp
    @if($freiPct > 0)
    <td style="width:{{ $freiPct }}%; background:#e2e8f0; font-size:12px; color:#64748b; text-align:center; line-height:16px; font-weight:600;">{{ $freiPct }}% verf&uuml;gbar</td>
    @endif
</tr>
</table>


{{-- ============================================================ --}}
{{-- SEITE 3: NACHFRAGEANALYSE                                    --}}
{{-- ============================================================ --}}
<div class="page-break"></div>

{{-- Mini-Header --}}
<table style="width:100%; border-bottom:2px solid #ee7606; margin-bottom:14px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:6px; font-size:12px; font-weight:bold; color:#1e293b;">SR<span style="color:#ee7606;">-</span>HOMES &middot; {{ $property->address ?? '' }}, {{ $property->city ?? '' }}</td>
    <td style="padding-bottom:6px; text-align:right; font-size:12px; color:#94a3b8;">Seite 3 von 3</td>
</tr>
</table>

<div class="section-title">Nachfrageanalyse</div>

{{-- Anfragen pro Woche --}}
<div class="sub-title">Anfragen-Entwicklung (letzte {{ count($weeklyInquiries) }} Wochen)</div>
<table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
<tr style="background:#f8fafc;">
    <td style="width:30%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Kalenderwoche</td>
    <td style="width:20%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1; text-align:right;">Anfragen</td>
    <td style="width:50%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Trend</td>
</tr>
@foreach($weeklyInquiries as $week)
<tr>
    <td style="padding:4px 8px; font-size:12px; border-bottom:1px solid #f1f5f9;">KW {{ $week['kw'] }}</td>
    <td style="padding:4px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $week['count'] }}</td>
    <td style="padding:4px 0; border-bottom:1px solid #f1f5f9;">
        @if($week['count'] > 0)
        @php $barW = min(100, round(($week['count'] / max(1, max(array_column($weeklyInquiries, 'count')))) * 100)); @endphp
        <div style="width:{{ $barW }}%; background:#ee7606; height:10px; border-radius:2px;"></div>
        @endif
    </td>
</tr>
@endforeach
</table>

{{-- Plattform-Verteilung --}}
@if(count($platformDistribution) > 0)
<div class="sub-title">Plattform-Verteilung</div>
<table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
<tr style="background:#f8fafc;">
    <td style="width:35%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Plattform</td>
    <td style="width:15%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1; text-align:right;">Anfragen</td>
    <td style="width:15%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1; text-align:right;">Anteil</td>
    <td style="width:35%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;"></td>
</tr>
@foreach($platformDistribution as $platform)
<tr>
    <td style="padding:4px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9;">{{ $platform['name'] }}</td>
    <td style="padding:4px 8px; font-size:12px; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $platform['count'] }}</td>
    <td style="padding:4px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $platform['percent'] }}%</td>
    <td style="padding:4px 0; border-bottom:1px solid #f1f5f9;">
        <div style="width:{{ $platform['percent'] }}%; background:#ee7606; height:10px; border-radius:2px;"></div>
    </td>
</tr>
@endforeach
</table>
@endif

{{-- Konversionsraten --}}
<div class="sub-title">Konversionsraten</div>
<table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
<tr style="background:#f8fafc;">
    <td style="width:50%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1;">Stufe</td>
    <td style="width:25%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1; text-align:right;">Anzahl</td>
    <td style="width:25%; padding:4px 8px; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; border-bottom:1px solid #cbd5e1; text-align:right;">Rate</td>
</tr>
<tr>
    <td style="padding:5px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9;">Anfragen (unique Interessenten)</td>
    <td style="padding:5px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $uniqueInteressenten }}</td>
    <td style="padding:5px 8px; font-size:12px; border-bottom:1px solid #f1f5f9; text-align:right;">100%</td>
</tr>
<tr style="background:#f8fafc;">
    <td style="padding:5px 8px; font-size:12px; border-bottom:1px solid #f1f5f9;">&#8594; Besichtigung / Beratung</td>
    <td style="padding:5px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $besichtigungenCount }}</td>
    <td style="padding:5px 8px; font-size:12px; font-weight:600; color:#10b981; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $konversionBesichtigung }}%</td>
</tr>
<tr>
    <td style="padding:5px 8px; font-size:12px; border-bottom:1px solid #f1f5f9;">&#8594; Kaufanbot</td>
    <td style="padding:5px 8px; font-size:12px; font-weight:600; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $kaufanboteCount }}</td>
    <td style="padding:5px 8px; font-size:12px; font-weight:600; color:#10b981; border-bottom:1px solid #f1f5f9; text-align:right;">{{ $konversionKaufanbot }}%</td>
</tr>
</table>

{{-- Pipeline --}}
<div class="highlight-box">
    <div style="font-size:12px; font-weight:600; color:#0f172a;">Interessenten-Pipeline</div>
    <div style="font-size:12px; color:#334155; margin-top:4px;">
        Aktuell befinden sich <strong>{{ $uniqueInteressenten }} Interessenten</strong> in der Pipeline.
        @if($besichtigungenCount > 0)
            Davon haben bereits <strong>{{ $besichtigungenCount }}</strong> eine Besichtigung oder ein Beratungsgespr&auml;ch wahrgenommen.
        @endif
        Die hohe Nachfrage best&auml;tigt die Marktpositionierung des Projekts.
    </div>
</div>


{{-- ============================================================ --}}
{{-- FAZIT (eigene Seite)                                         --}}
{{-- ============================================================ --}}
<div class="page-break"></div>

{{-- Mini-Header --}}
<table style="width:100%; border-bottom:2px solid #ee7606; margin-bottom:14px;" cellpadding="0" cellspacing="0">
<tr>
    <td style="padding-bottom:8px; vertical-align:bottom;">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:28px;" />
        @else
            <span style="font-size:12px; font-weight:bold; color:#1e293b;">SR<span style="color:#ee7606;">-</span>HOMES</span>
        @endif
    </td>
    <td style="padding-bottom:8px; text-align:right; font-size:10px; color:#94a3b8;">{{ $property->address ?? '' }}, {{ $property->city ?? '' }}</td>
</tr>
</table>

<div class="section-title">Fazit</div>

<div style="background:#f0fdf4; border-left:4px solid #10b981; padding:12px 14px; margin-bottom:14px;">
    <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:6px;">Positive Projektentwicklung</div>
    <div style="font-size:12px; color:#334155; line-height:1.7;">
        Das Projekt <strong>{{ $property->address ?? '' }}, {{ $property->city ?? '' }}</strong>
        befindet sich planm&auml;&szlig;ig in der Vermarktung.
        @if($verkaufsquote >= 50)
            Die Verkaufsquote von <strong style="color:#10b981;">{{ $verkaufsquote }}%</strong> &uuml;bertrifft die Erwartungen.
        @else
            Die bisherige Verkaufsquote von <strong style="color:#10b981;">{{ $verkaufsquote }}%</strong> entspricht den Erwartungen f&uuml;r den aktuellen Vermarktungszeitraum.
        @endif
        Die hohe Nachfrage mit <strong>{{ $uniqueInteressenten }} registrierten Interessenten</strong>
        und die solide Konversionsrate best&auml;tigen die Attraktivit&auml;t des Standorts und der Preisgestaltung.
        <br><br>
        <strong>Die Vermarktung verl&auml;uft positiv und die Prognose f&uuml;r den weiteren Absatz ist g&uuml;nstig.</strong>
    </div>
</div>

{{-- Kontaktdaten --}}
{{-- Ansprechpartner --}}
<div style="border-top:1px solid #e2e8f0; padding-top:12px; margin-top:16px;">
    <table style="width:100%;" cellpadding="0" cellspacing="0">
    <tr>
        <td style="vertical-align:top; width:50%;">
            @if($logoBase64)
                <img src="{{ $logoBase64 }}" alt="SR-Homes" style="height:28px; margin-bottom:6px;" /><br>
            @endif
            <div style="font-size:12px; color:#64748b; line-height:1.5;">
                SR-Homes Immobilien GmbH<br>
                Ihr Partner f&uuml;r erfolgreiche Immobilienvermarktung<br>
                www.sr-homes.at
            </div>
        </td>
        <td style="vertical-align:top; text-align:right;">
            <div style="font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px;">Ihr Ansprechpartner</div>
            <div style="font-size:12px; font-weight:700; color:#1e293b;">{{ $broker['name'] }}</div>
            @if($broker['title'])
                <div style="font-size:12px; color:#64748b;">{{ $broker['title'] }}</div>
            @endif
            @if($broker['phone'])
                <div style="font-size:12px; color:#64748b; margin-top:2px;">Tel: {{ $broker['phone'] }}</div>
            @endif
            @if($broker['email'])
                <div style="font-size:12px; color:#64748b;">E-Mail: {{ $broker['email'] }}</div>
            @endif
        </td>
    </tr>
    </table>
</div>

{{-- FOOTER --}}
<div class="footer">
    <table style="width:100%;" cellpadding="0" cellspacing="0">
    <tr>
        <td style="font-size:12px; color:#94a3b8;">SR-Homes Immobilien GmbH &middot; Vertriebsbericht &middot; Vertraulich</td>
        <td style="font-size:12px; color:#94a3b8; text-align:right;">{{ $generatedAt }}</td>
    </tr>
    </table>
</div>

</body>
</html>
