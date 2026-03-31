<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 20mm 16mm 20mm 16mm; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8pt; color: #1e293b; line-height: 1.45; }
    h1 { font-size: 12pt; font-weight: bold; color: #1e293b; margin: 5mm 0 3mm; padding-bottom: 1.5mm; border-bottom: 2px solid #ee7606; }
    h2 { font-size: 9.5pt; font-weight: bold; color: #334155; margin: 4mm 0 2mm; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 3mm; font-size: 7.5pt; }
    th { background: #1e293b; color: #fff; padding: 2mm 2.5mm; text-align: left; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
    td { padding: 1.5mm 2.5mm; border-bottom: 0.5px solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f8fafc; }
    .b { display: inline-block; padding: 0.8mm 2.5mm; border-radius: 1.5mm; font-size: 6pt; font-weight: bold; text-transform: uppercase; }
    .b-hi { background: #fef2f2; color: #dc2626; }
    .b-md { background: #fffbeb; color: #d97706; }
    .b-lo { background: #f0fdf4; color: #16a34a; }
    .b-up { background: #f0fdf4; color: #16a34a; }
    .b-dn { background: #fef2f2; color: #dc2626; }
    .b-sd { background: #eff6ff; color: #2563eb; }
    .b-nt { background: #f1f5f9; color: #475569; }
    .b-bl { background: #dbeafe; color: #1d4ed8; }
    .b-bu { background: #f0fdf4; color: #16a34a; }
    .b-be { background: #fef2f2; color: #dc2626; }
    .b-mb { background: #fff7ed; color: #ea580c; }
    .box { background: #fff7ed; border: 1px solid #fed7aa; border-left: 3px solid #ee7606; padding: 3mm 4mm; margin: 3mm 0; font-size: 8.5pt; line-height: 1.55; }
    .info { background: #f8fafc; border-left: 3px solid #ee7606; padding: 2.5mm 3.5mm; margin: 2mm 0; font-size: 7.5pt; }
    .card { border: 1px solid #e2e8f0; border-radius: 1.5mm; padding: 2mm 3mm; margin-bottom: 2mm; }
    .nb { background: #fff7ed; border: 1.5px solid #ee7606; border-radius: 2mm; padding: 3mm 4mm; margin: 3mm 0; }
    .disc { margin-top: 5mm; padding: 3mm 4mm; background: #f1f5f9; border: 1px solid #e2e8f0; font-size: 6.5pt; color: #64748b; line-height: 1.4; }
    .pb { page-break-before: always; }
    .sm { font-size: 6.5pt; color: #475569; }
    .orange { color: #ee7606; }
    .bold { font-weight: bold; }
</style>
</head>
<body>

{{-- DECKBLATT --}}
<div style="text-align:center;padding-top:50mm;">
<div style="font-size:26pt;font-weight:bold;color:#ee7606;letter-spacing:2px;">SR<span style="color:#1e293b;">-HOMES</span></div>
<div style="font-size:9pt;color:#64748b;letter-spacing:3px;text-transform:uppercase;margin-bottom:18mm;">Immobilien GmbH</div>
<div style="width:50mm;height:2px;background:#ee7606;margin:0 auto 8mm;"></div>
<div style="font-size:20pt;font-weight:bold;color:#1e293b;line-height:1.3;">Immobilienmarkt &Ouml;sterreich<br>Marktbericht</div>
<div style="font-size:13pt;color:#ee7606;font-weight:bold;margin:6mm 0 10mm;">{{ $quarter }}</div>
<div style="font-size:9pt;color:#64748b;">Erstellt am {{ $reportDate }}</div>
<div style="width:50mm;height:2px;background:#ee7606;margin:8mm auto;"></div>
<table style="width:55%;margin:12mm auto 0;border:1px solid #e2e8f0;">
<tr><td style="background:#1e293b;color:#fff;padding:2mm 4mm;font-size:7pt;text-transform:uppercase;font-weight:bold;">Sentiment</td>
<td style="padding:2mm 4mm;text-align:center;"><span class="b b-mb">{{ $sentimentLabel }}</span></td></tr>
<tr><td style="background:#f8fafc;padding:2mm 4mm;font-size:7.5pt;font-weight:bold;">EZB Leitzins</td>
<td style="background:#f8fafc;padding:2mm 4mm;text-align:center;font-weight:bold;color:#ee7606;">{{ $ecbRate }}</td></tr>
</table>
<div style="font-size:7.5pt;color:#94a3b8;font-style:italic;margin-top:25mm;">Vertraulich &ndash; ausschlie&szlig;lich f&uuml;r den internen Gebrauch</div>
</div>

{{-- INHALTSVERZEICHNIS --}}
<div class="pb"></div>
<h1>Inhaltsverzeichnis</h1>
@foreach(['Executive Summary','Makroökonomische Kennzahlen','EZB-Zinsentwicklung','Regionale Marktanalyse','Asset-Vergleich','Risikofaktoren','Chancen & Opportunitäten','Investitionsausblick','Neubauprojekte','Regulierung','Aktuelle Meldungen','Disclaimer'] as $i => $t)
<div style="padding:1.2mm 0;border-bottom:1px dotted #cbd5e1;font-size:8.5pt;"><span style="display:inline-block;width:7mm;font-weight:bold;color:#ee7606;">{{ $i+1 }}</span> {{ $t }}</div>
@endforeach

{{-- 1. EXECUTIVE SUMMARY --}}
<div class="pb"></div>
<h1>1 &nbsp; Executive Summary</h1>
<div class="box">{{ $executiveSummary }}</div>
<table style="margin-top:3mm;">
<tr><td style="width:30%;font-weight:bold;border:none;">Sentiment:</td><td style="border:none;"><span class="b b-mb">{{ $sentimentLabel }}</span></td></tr>
<tr><td style="font-weight:bold;border:none;">Zeitraum:</td><td style="border:none;">{{ $quarter }}</td></tr>
</table>

{{-- 2. KENNZAHLEN --}}
<h1 style="margin-top:6mm;">2 &nbsp; Makro&ouml;konomische Kennzahlen</h1>
<table>
<thead><tr><th style="width:20%;">Kennzahl</th><th style="width:13%;">Aktuell</th><th style="width:12%;">&#916;</th><th style="width:6%;">&#8597;</th><th>Kontext</th></tr></thead>
<tbody>
@foreach($keyMetrics as $m)
<tr>
<td class="bold">{{ $m['label'] }}</td>
<td class="bold">{{ $m['value'] }}</td>
<td>@if(($m['direction']??'')==='up')<span class="b b-up">{{ $m['change'] }}</span>@elseif(($m['direction']??'')==='down')<span class="b b-dn">{{ $m['change'] }}</span>@else<span class="b b-sd">{{ $m['change'] }}</span>@endif</td>
<td style="text-align:center;">@if(($m['direction']??'')==='up')&#9650;@elseif(($m['direction']??'')==='down')&#9660;@else&#9654;@endif</td>
<td class="sm">{{ \Illuminate\Support\Str::limit($m['context']??'',110) }}</td>
</tr>
@endforeach
</tbody>
</table>

{{-- 3. EZB --}}
<h1 style="margin-top:6mm;">3 &nbsp; EZB-Zinsentwicklung</h1>
<table>
<thead><tr><th>Datum</th><th>Leitzins</th><th>&#916;</th><th>Niveau</th></tr></thead>
<tbody>
@foreach($ecbHistory as $i => $e)
<tr>
<td>{{ \Carbon\Carbon::parse($e['date'])->format('d.m.Y') }}</td>
<td class="bold orange">{{ number_format($e['rate'],2,',','.') }}%</td>
<td>@if(isset($ecbHistory[$i+1]))@php $d=$e['rate']-$ecbHistory[$i+1]['rate']; @endphp<span class="b {{ $d>0?'b-up':($d<0?'b-dn':'b-nt') }}">{{ $d>0?'+':'' }}{{ number_format($d*100,0) }} BP</span>@else &ndash; @endif</td>
<td class="sm">@if($e['rate']>=4)Restriktiv @elseif($e['rate']>=3)&Uuml;ber neutral @elseif($e['rate']>=2)Neutral @elseif($e['rate']>=1)Akkommodierend @else Niedrig @endif</td>
</tr>
@endforeach
</tbody>
</table>

{{-- 4. REGIONALE ANALYSE --}}
<div class="pb"></div>
<h1>4 &nbsp; Regionale Marktanalyse</h1>
<table>
<thead><tr><th style="width:12%;">Region</th><th style="width:11%;">Trend</th><th style="width:8%;">YoY</th><th style="width:30%;">Nachfrage</th><th>Ausblick</th></tr></thead>
<tbody>
@foreach($regional as $r)
@php $tl=['bullish'=>'Bull.','moderat_bullish'=>'Mod.+','neutral'=>'Neutral','moderat_bearish'=>'Mod.-','bearish'=>'Bear.']; @endphp
<tr>
<td class="bold">{{ $r['region'] }}</td>
<td><span class="b @if(str_contains($r['trend']??'','bullish'))b-bu @elseif(str_contains($r['trend']??'','bearish')){{ str_contains($r['trend'],'moderat')?'b-mb':'b-be' }} @else b-nt @endif">{{ $tl[$r['trend']]??'Neutral' }}</span></td>
<td class="bold">{{ $r['price_yoy']??'–' }}</td>
<td class="sm">{{ \Illuminate\Support\Str::limit($r['demand']??'',90) }}</td>
<td class="sm">{{ \Illuminate\Support\Str::limit($r['outlook']??'',90) }}</td>
</tr>
@endforeach
</tbody>
</table>

{{-- 5. ASSET VERGLEICH --}}
<h1 style="margin-top:6mm;">5 &nbsp; Asset-Vergleich</h1>
<table>
<thead><tr><th style="width:14%;">Klasse</th><th style="width:14%;">Rendite</th><th style="width:18%;">Empfehlung</th><th>Begr&uuml;ndung</th></tr></thead>
<tbody>
@foreach($assetComparison as $k=>$a)
<tr>
<td class="bold">{{ ucfirst($k) }}</td>
<td class="bold orange">{{ $a['expected_return']??'–' }}</td>
<td><span class="b b-nt">{{ $a['verdict']??'–' }}</span></td>
<td class="sm">{{ \Illuminate\Support\Str::limit($a['rationale']??'',130) }}</td>
</tr>
@endforeach
</tbody>
</table>

{{-- 6. RISIKOFAKTOREN --}}
<div class="pb"></div>
<h1>6 &nbsp; Risikofaktoren</h1>
@foreach($riskFactors as $risk)
<div class="card">
<span class="b {{ ($risk['severity']??'')==='high'?'b-hi':(($risk['severity']??'')==='medium'?'b-md':'b-lo') }}">{{ strtoupper($risk['severity']??'MITTEL') }}</span>
<strong style="font-size:8pt;margin-left:2mm;">{{ $risk['title'] }}</strong>
<div class="sm" style="margin-top:1mm;">{{ \Illuminate\Support\Str::limit($risk['description']??'',180) }}</div>
</div>
@endforeach

{{-- 7. CHANCEN --}}
<h1 style="margin-top:5mm;">7 &nbsp; Chancen &amp; Opportunit&auml;ten</h1>
<table>
<thead><tr><th style="width:10%;">&#9733;</th><th style="width:25%;">Chance</th><th>Beschreibung</th></tr></thead>
<tbody>
@foreach($opportunities as $o)
@php $pc=match(strtolower($o['potential']??'')){  'high'=>'b-bu','medium-high'=>'b-up',default=>'b-nt'}; $pl=match(strtolower($o['potential']??'')){'high'=>'HOCH','medium-high'=>'M-HOCH','medium'=>'MITTEL',default=>strtoupper($o['potential']??'–')}; @endphp
<tr>
<td><span class="b {{ $pc }}">{{ $pl }}</span></td>
<td class="bold" style="font-size:7.5pt;">{{ $o['title'] }}</td>
<td class="sm">{{ \Illuminate\Support\Str::limit($o['description']??'',160) }}</td>
</tr>
@endforeach
</tbody>
</table>

{{-- 8. INVESTITIONSAUSBLICK --}}
<div class="pb"></div>
<h1>8 &nbsp; Investitionsausblick</h1>
<table>
<thead><tr><th style="width:16%;">Zeitraum</th><th>Einsch&auml;tzung</th></tr></thead>
<tbody>
<tr><td class="bold">Kurzfristig (3-6M)</td><td class="sm">{{ \Illuminate\Support\Str::limit($investmentOutlook['short_term']??'',220) }}</td></tr>
<tr><td class="bold">Mittelfristig (6-18M)</td><td class="sm">{{ \Illuminate\Support\Str::limit($investmentOutlook['medium_term']??'',220) }}</td></tr>
<tr><td class="bold">Langfristig (18M+)</td><td class="sm">{{ \Illuminate\Support\Str::limit($investmentOutlook['long_term']??'',220) }}</td></tr>
<tr><td class="bold">Geopolitik</td><td class="sm">{{ \Illuminate\Support\Str::limit($investmentOutlook['geopolitical']??'',220) }}</td></tr>
</tbody>
</table>
@if(!empty($investmentOutlook['recommendation']))
<div class="info"><strong class="orange">Empfehlung:</strong> {{ \Illuminate\Support\Str::limit($investmentOutlook['recommendation']??'',300) }}</div>
@endif

{{-- 9. NEUBAU --}}
<h1 style="margin-top:5mm;">9 &nbsp; Relevanz f&uuml;r Neubauprojekte</h1>
<div class="nb">
<div style="font-size:9pt;font-weight:bold;color:#ee7606;margin-bottom:2mm;">Besonders relevant f&uuml;r Bautr&auml;ger &amp; Eigent&uuml;mer</div>
@php
$bau=collect($keyMetrics)->firstWhere('label','Österreich Baukosten Index');
$foerd=collect($regulationWatch)->first(fn($r)=>str_contains($r['title']??'','Wohnbau'));
$kim=collect($regulationWatch)->first(fn($r)=>str_contains($r['title']??'','KIM'));
@endphp
<table style="margin-bottom:0;">
<thead><tr><th style="background:#ee7606;">Thema</th><th style="background:#ee7606;">Stand</th><th style="background:#ee7606;">Auswirkung</th></tr></thead>
<tbody>
<tr><td class="bold">Baukosten</td><td>{{ $bau['value']??'+4,5-5% YoY' }}</td><td class="sm">{{ \Illuminate\Support\Str::limit($bau['context']??'Energie+Logistik',100) }}</td></tr>
<tr><td class="bold">Wohnbauf&ouml;rderung</td><td>{{ $foerd['status']??'In-Force' }}</td><td class="sm">{{ \Illuminate\Support\Str::limit($foerd['impact']??'–',100) }}</td></tr>
<tr><td class="bold">KIM-VO</td><td>LTV 80%/70%</td><td class="sm">{{ \Illuminate\Support\Str::limit($kim['impact']??'–',100) }}</td></tr>
<tr><td class="bold">Zinsprognose</td><td>{{ $ecbRate }}</td><td class="sm">Weitere Senkungen Q2/Q3 erwartet</td></tr>
<tr><td class="bold">Baugenehmigungen</td><td>R&uuml;ckl&auml;ufig</td><td class="sm">Neubau unterversorgt; Bestand profitiert</td></tr>
</tbody>
</table>
</div>

{{-- 10. REGULIERUNG --}}
<div class="pb"></div>
<h1>10 &nbsp; Regulierung &amp; Gesetzgebung</h1>
@foreach($regulationWatch as $reg)
<div class="card">
<span class="b b-bl">{{ $reg['status']??'–' }}</span> <strong style="font-size:7.5pt;">{{ $reg['title'] }}</strong>
<div class="sm" style="margin-top:1mm;">{{ \Illuminate\Support\Str::limit($reg['impact']??'',160) }}</div>
</div>
@endforeach

{{-- 11. NEWS --}}
<h1 style="margin-top:5mm;">11 &nbsp; Aktuelle Meldungen</h1>
<table>
<thead><tr><th style="width:11%;">Kat.</th><th style="width:24%;">Schlagzeile</th><th style="width:33%;">Details</th><th>Auswirkung</th></tr></thead>
<tbody>
@foreach(array_slice($newsHighlights,0,12) as $n)
<tr>
<td><span class="b b-nt">{{ $n['category']??'–' }}</span></td>
<td class="bold" style="font-size:6.5pt;">{{ \Illuminate\Support\Str::limit($n['headline']??'',70) }}</td>
<td class="sm">{{ \Illuminate\Support\Str::limit($n['summary']??'',110) }}</td>
<td class="sm">{{ \Illuminate\Support\Str::limit($n['impact']??'',80) }}</td>
</tr>
@endforeach
</tbody>
</table>

{{-- 12. DISCLAIMER --}}
<div class="disc">
<strong>Haftungsausschluss</strong> &ndash;
Dieser Bericht wurde von SR-Homes Immobilien GmbH auf Basis &ouml;ffentlich zug&auml;nglicher Daten erstellt.
Er dient ausschlie&szlig;lich zu Informationszwecken und stellt keine Anlageberatung dar.
Prognosen k&ouml;nnen sich jederzeit &auml;ndern. SR-Homes &uuml;bernimmt keine Gew&auml;hr f&uuml;r Vollst&auml;ndigkeit oder Richtigkeit.
Investitionsentscheidungen sollten nach R&uuml;cksprache mit Finanz- und Rechtsberatern getroffen werden.<br>
<strong>Quellen:</strong> EZB, &Ouml;NB, Statistik Austria, WKO, FMA. &copy; {{ date('Y') }} SR-Homes Immobilien GmbH.
</div>
<div style="text-align:center;margin-top:4mm;font-size:7pt;color:#94a3b8;">
SR-Homes Immobilien GmbH &middot; Wien &middot; www.sr-homes.at &middot; Stand: {{ $reportDate }}
</div>

</body>
</html>
