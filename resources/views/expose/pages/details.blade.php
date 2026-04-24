@php
    $p = $ctx->property;

    $fmtArea = fn($v) => $v ? number_format($v, 0, ',', '.') . ' m²' : null;
    $fmtMoney = fn($v) => $v !== null && $v !== '' ? '€ ' . number_format($v, 0, ',', '.') : null;
    $rooms = $p->rooms_amount ? rtrim(rtrim(number_format($p->rooms_amount, 1, ',', ''), '0'), ',') : null;

    // Nebenkosten-Summe
    $sum = 0;
    $costs = array_filter([
        'Betriebskosten' => $p->operating_costs,
        'Heizkosten'     => $p->heating_costs,
        'Warmwasser'     => $p->warm_water_costs,
        'Rücklagen'      => $p->maintenance_reserves,
    ], fn($v) => $v !== null);
    foreach ($costs as $v) $sum += (float) $v;

    // Parking-Text:
    //  (1) bevorzugt building_details.parking_spaces (strukturiert, pro Typ)
    //  (2) sonst Flat-Felder (garage_spaces / parking_spaces) mit parking_type
    //      als Label, damit „Carport" / „Tiefgarage" / „Doppelgarage" korrekt
    //      erscheint statt hartkodiert „Garage" / „Stellpl."
    $parking = null;
    $parts = [];

    $typeLabels = [
        'garage'      => ['Garage', 'Garagen'],
        'tiefgarage'  => ['Tiefgarage', 'Tiefgaragen'],
        'carport'     => ['Carport', 'Carports'],
        'duplex'      => ['Duplex-Stellplatz', 'Duplex-Stellplätze'],
        'outdoor'     => ['Stellplatz', 'Stellplätze'],
        'stellplatz'  => ['Stellplatz', 'Stellplätze'],
    ];
    $plural = fn(int $n, array $forms) => $n === 1 ? $forms[0] : $forms[1];

    $bd = is_string($p->building_details) ? json_decode($p->building_details, true) : $p->building_details;
    $structured = is_array($bd['parking_spaces'] ?? null) ? $bd['parking_spaces'] : null;

    if ($structured) {
        foreach ($structured as $entry) {
            $type = strtolower((string) ($entry['type'] ?? ''));
            $count = (int) ($entry['count'] ?? 0);
            if ($count <= 0 || $type === '') continue;
            $forms = $typeLabels[$type] ?? ['Stellplatz', 'Stellplätze'];
            $parts[] = $count . ' ' . $plural($count, $forms);
        }
    } else {
        $garageSpaces = (int) ($p->garage_spaces ?? 0);
        $parkingSpaces = (int) ($p->parking_spaces ?? 0);
        $ptype = trim((string) ($p->parking_type ?? ''));

        if ($garageSpaces > 0) {
            // parking_type kann eine spezifische Garagen-Art sein (Doppelgarage,
            // Carport, Tiefgarage). Wenn gesetzt → als Label verwenden.
            $label = $ptype ?: $plural($garageSpaces, ['Garage', 'Garagen']);
            $parts[] = $garageSpaces . ' ' . $label;
        }
        if ($parkingSpaces > 0) {
            // Edge-Case: kein garage_spaces aber parking_type='Carport' — der
            // Carport wurde in parking_spaces statt garage_spaces hinterlegt.
            if ($garageSpaces === 0 && $ptype !== '') {
                $parts[] = $parkingSpaces . ' ' . $ptype;
            } else {
                $parts[] = $parkingSpaces . ' ' . $plural($parkingSpaces, ['Stellplatz', 'Stellplätze']);
            }
        }
    }

    if ($parts) $parking = implode(' · ', $parts);

    // Helper zum Erstellen einer Row nur wenn Wert vorhanden
    $row = function ($k, $v, $total = false) {
        if ($v === null || $v === '') return '';
        $cls = $total ? ' total' : '';
        return '<div class="r"><span class="k">' . e($k) . '</span><span class="v' . $cls . '">' . e($v) . '</span></div>';
    };
@endphp

<style>
  .details-page .grid {
    position: absolute; top: 124px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1fr 1fr; column-gap: 56px;
  }
</style>

<div class="page details-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Details</div>
    <div class="aline"></div>
    <div class="grid">
        {{-- Linke Spalte: Objekt + Nebenkosten --}}
        <div>
            <div class="grp">
                <div class="gh">Objekt</div>
                {!! $row('Objektart', $p->object_type) !!}
                {!! $row('Zimmer', $rooms) !!}
                {!! $row('Baujahr', $p->construction_year) !!}
                {!! $row('Letzte Kernsanierung', $p->last_renovation_note) !!}
                {!! $row('Wohnfläche', $fmtArea($p->living_area)) !!}
                {!! $row('Grundstück', $fmtArea($p->realty_area)) !!}
                {!! $row('Verfügbar ab', $p->available_from?->format('d.m.Y') ?: $p->available_text ?: null) !!}
            </div>

            @if ($costs)
                <div class="grp">
                    <div class="gh">Nebenkosten (monatlich)</div>
                    @foreach ($costs as $k => $v)
                        {!! $row($k, $fmtMoney($v)) !!}
                    @endforeach
                    @if ($sum > 0)
                        {!! $row('Summe', $fmtMoney($sum), true) !!}
                    @endif
                </div>
            @endif
        </div>

        {{-- Rechte Spalte: Flächen + Ausstattung + Energie --}}
        <div>
            <div class="grp">
                <div class="gh">Flächen &amp; Räume</div>
                {!! $row('Balkon', $fmtArea($p->area_balcony)) !!}
                {!! $row('Terrasse', $fmtArea($p->area_terrace)) !!}
                {!! $row('Garten', $fmtArea($p->area_garden)) !!}
                {!! $row('Keller', $fmtArea($p->area_basement)) !!}
                {!! $row('Stellplatz', $parking) !!}
            </div>

            <div class="grp">
                <div class="gh">Ausstattung</div>
                {!! $row('Bodenbelag', $p->flooring) !!}
                {!! $row('Bad', $p->bathroom_equipment) !!}
                {!! $row('Küche', $p->has_fitted_kitchen ? 'inkl. Einbauküche' : null) !!}
                {!! $row('Ausrichtung', $p->orientation) !!}
            </div>

            <div class="grp">
                <div class="gh">Energie</div>
                {!! $row('Heizung', $p->heating) !!}
                {!! $row('HWB', $p->heating_demand_value ? $p->heating_demand_value . ' kWh/m²a' : null) !!}
                {!! $row('Energieklasse', $p->heating_demand_class) !!}
                {!! $row('Photovoltaik', $p->has_photovoltaik ? 'ja' : null) !!}
                {!! $row('Wohnraumlüftung', $p->has_wohnraumlueftung ? 'ja' : null) !!}
            </div>
        </div>
    </div>
</div>
