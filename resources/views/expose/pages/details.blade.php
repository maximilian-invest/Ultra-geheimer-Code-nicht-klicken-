@php
    $p = $ctx->property;

    $fmtArea = fn($v) => $v ? number_format($v, 0, ',', '.') . ' m²' : null;
    $fmtMoney = fn($v) => $v !== null && $v !== '' ? '€ ' . number_format($v, 0, ',', '.') : null;

    // Count + Fläche kombinieren, z. B. "3 Stk. · 6 m²" — die m²-Angabe ist
    // dabei die GESAMTflaeche aller Einheiten (nicht pro Stueck), "Stk." macht
    // das explizit damit "3 × 6 m²" nicht als Multiplikation gelesen wird.
    $fmtCountArea = function ($count, $area) use ($fmtArea) {
        $c = (int) ($count ?? 0);
        $a = $fmtArea($area);
        if ($c > 1 && $a) return $c . ' Stk. · ' . $a;
        if ($a) return $a;
        if ($c > 0) return $c . ' Stk.';
        return null;
    };
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

    // Heizung: bevorzugt building_details.heating (strukturiert: types + fuel).
    // Flat-Feld $p->heating dient nur als Fallback — und nur, wenn es
    // plausibel aussieht (nicht "Nach Vereinbarung" o. Ä., das Leute bei
    // der Erstanlage oft versehentlich ins Heizungs-Feld eintragen).
    $heatingDisplay = null;
    $heatingBlock = is_array($bd['heating'] ?? null) ? $bd['heating'] : [];
    $hTypes = array_values(array_filter(is_array($heatingBlock['types'] ?? null) ? $heatingBlock['types'] : [], fn($t) => is_string($t) && trim($t) !== ''));
    $hFuel  = trim((string) ($heatingBlock['fuel'] ?? ''));
    $hParts = [];
    if ($hTypes) $hParts[] = implode(' / ', $hTypes);
    if ($hFuel !== '') $hParts[] = $hFuel;
    if ($hParts) {
        $heatingDisplay = implode(' · ', $hParts);
    } else {
        $flat = trim((string) ($p->heating ?? ''));
        $flatLower = mb_strtolower($flat);
        $blocked = ['nach vereinbarung', 'auf anfrage', 'k.a.', 'ka', 'n/a', 'keine angabe', '-', '–'];
        if ($flat !== '' && !in_array($flatLower, $blocked, true)) {
            $heatingDisplay = $flat;
        }
    }

    // Helper zum Erstellen einer Row nur wenn Wert vorhanden
    $row = function ($k, $v, $total = false) {
        if ($v === null || $v === '') return '';
        $cls = $total ? ' total' : '';
        return '<div class="r"><span class="k">' . e($k) . '</span><span class="v' . $cls . '">' . e($v) . '</span></div>';
    };

    // Merkmale/Features: Liste der aktivierten Boolean-Felder.
    $featureLabels = [
        'has_elevator'          => 'Aufzug',
        'has_barrier_free'      => 'Barrierefrei',
        'has_fitted_kitchen'    => 'Einbauküche',
        'has_air_conditioning'  => 'Klimaanlage',
        'has_pool'              => 'Pool',
        'has_sauna'             => 'Sauna',
        'has_fireplace'         => 'Kamin',
        'has_alarm'             => 'Alarmanlage',
        'has_guest_wc'          => 'Gäste-WC',
        'has_storage_room'      => 'Abstellraum',
        'has_washing_connection'=> 'Waschmaschinenanschluss',
        'has_photovoltaik'      => 'Photovoltaik',
        'has_charging_station'  => 'E-Ladestation',
        'has_wohnraumlueftung'  => 'Wohnraumlüftung',
    ];
    // Abstellraum nur in Merkmalen zeigen, wenn keine Anzahl/Fläche gesetzt ist
    // (sonst erscheint die Info detailliert in "Flächen & Räume").
    $storageRoomDetailed = ((int) ($p->storage_room_count ?? 0)) > 0 || ((float) ($p->area_storage_room ?? 0)) > 0;
    $activeFeatures = [];
    foreach ($featureLabels as $key => $label) {
        if ($key === 'has_storage_room' && $storageRoomDetailed) continue;
        if (!empty($p->{$key})) $activeFeatures[] = $label;
    }

    // Allgemeinräume: kommaseparierter Freitext (aus IntakeProtocol-Konvention),
    // in lesbare Liste wandeln.
    $commonAreasRaw = trim((string) ($p->common_areas ?? ''));
    $commonAreas = [];
    if ($commonAreasRaw !== '') {
        // Leading/trailing JSON-Klammern abschneiden falls Legacy-Format.
        $decoded = null;
        if (($commonAreasRaw[0] ?? '') === '[') {
            $decoded = json_decode($commonAreasRaw, true);
        }
        $items = is_array($decoded) ? $decoded : preg_split('/[,;]\s*/', $commonAreasRaw);
        foreach ($items as $item) {
            $t = ucfirst(trim((string) $item));
            if ($t !== '') $commonAreas[] = $t;
        }
    }

    // Objektart-Details: Eigentumsart, Bauart lesbar.
    $ownershipLabels = [
        'alleineigentum' => 'Alleineigentum',
        'miteigentum'    => 'Miteigentum',
        'wohnungseigentum' => 'Wohnungseigentum',
        'baurecht'       => 'Baurecht',
    ];
    $ownership = $ownershipLabels[strtolower((string) ($p->ownership_type ?? ''))] ?? $p->ownership_type;
    $constructionLabels = [
        'massiv'      => 'Massivbauweise',
        'holz'        => 'Holzbauweise',
        'fertigteil'  => 'Fertigteilbau',
        'ziegel'      => 'Ziegelbau',
        'mischbau'    => 'Mischbauweise',
    ];
    $construction = $constructionLabels[strtolower((string) ($p->construction_type ?? ''))] ?? $p->construction_type;

    $conditionLabels = [
        'neuwertig'   => 'Neuwertig',
        'gepflegt'    => 'Gepflegt',
        'renovierungsbeduerftig' => 'Renovierungsbedürftig',
        'sanierungsbeduerftig'   => 'Sanierungsbedürftig',
        'erstbezug'   => 'Erstbezug',
        'neu'         => 'Neu',
        'gebraucht'   => 'Gebraucht',
        'gut'         => 'Gut',
    ];
    $condition = $conditionLabels[strtolower((string) ($p->realty_condition ?? ''))] ?? $p->realty_condition;

    $qualityLabels = [
        'einfach'   => 'Einfach',
        'normal'    => 'Normal',
        'gehoben'   => 'Gehoben',
        'luxus'     => 'Luxus',
    ];
    $quality = $qualityLabels[strtolower((string) ($p->quality ?? ''))] ?? $p->quality;

    // Etage-Info: "2. Stock von 5"
    $floorInfo = null;
    if ($p->floor_number !== null && $p->floor_count) {
        $floorInfo = $p->floor_number . '. Stock von ' . $p->floor_count;
    } elseif ($p->floor_count) {
        $floorInfo = $p->floor_count . ' Etagen';
    } elseif ($p->floor_number !== null) {
        $floorInfo = $p->floor_number . '. Stock';
    }
@endphp

<style>
  .details-page .grid {
    position: absolute; top: 124px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1fr 1fr; column-gap: 56px;
  }
  .details-page .list-value {
    font-family: Georgia, serif; color: var(--text-primary);
    font-size: 13px; line-height: 1.5;
    padding: 5px 0 3px;
  }
  .details-page .list-value .bullet {
    color: var(--accent); margin: 0 6px; opacity: 0.6;
  }
  /* Badges fuer Merkmale & Allgemeinraeume — vermeidet Haengeinruecker
     beim Umbruch und wirkt visuell klarer als bullet-separierte Liste. */
  .details-page .badge-list {
    display: flex; flex-wrap: wrap; gap: 5px 5px;
    padding: 6px 0 2px;
  }
  .details-page .badge-list .bdg {
    display: inline-block;
    font-family: var(--font-sans, 'Inter', sans-serif);
    font-size: 11.5px; line-height: 1;
    color: #3f3f46;
    background: #f4f4f5;
    border: 1px solid #e4e4e7;
    border-radius: 9999px;
    padding: 5px 10px 5px 10px;
    white-space: nowrap;
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
                {!! $row('Bauart', $construction) !!}
                {!! $row('Eigentumsart', $ownership) !!}
                {!! $row('Zimmer', $rooms) !!}
                {!! $row('Etage', $floorInfo) !!}
                {!! $row('Baujahr', $p->construction_year) !!}
                {!! $row('Um- oder Zubauten', $p->conversions_additions) !!}
                {!! $row('Sanierungsjahr', $p->year_renovated) !!}
                {!! $row('Letzte Kernsanierung', $p->last_renovation_note) !!}
                {!! $row('Wohnfläche', $fmtArea($p->living_area)) !!}
                {!! $row('Nutzfläche', $fmtArea($p->free_area)) !!}
                {!! $row('Grundstück', $fmtArea($p->realty_area)) !!}
                {!! $row('Zustand', $condition) !!}
                {!! $row('Qualität', $quality) !!}
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

            @if ($p->reserves_balance !== null && (float) $p->reserves_balance > 0)
                <div class="grp">
                    <div class="gh">Rücklagen</div>
                    {!! $row('Rücklagenstand', $fmtMoney($p->reserves_balance)) !!}
                </div>
            @endif
        </div>

        {{-- Rechte Spalte: Flächen + Ausstattung + Merkmale + Allgemein + Energie --}}
        <div>
            <div class="grp">
                <div class="gh">Flächen &amp; Räume</div>
                {!! $row('Balkon', $fmtArea($p->area_balcony)) !!}
                {!! $row('Terrasse', $fmtArea($p->area_terrace)) !!}
                {!! $row('Dachterrasse', $fmtArea($p->area_dachterrasse)) !!}
                {!! $row('Loggia', $fmtArea($p->area_loggia)) !!}
                {!! $row('Garten', $fmtArea($p->area_garden)) !!}
                {!! $row('Keller', $fmtArea($p->area_basement)) !!}
                {!! $row('Abstellraum', $fmtCountArea($p->storage_room_count, $p->area_storage_room)) !!}
                {!! $row('Stellplatz', $parking) !!}
            </div>

            <div class="grp">
                <div class="gh">Ausstattung</div>
                {!! $row('Bodenbelag', $p->flooring) !!}
                {!! $row('Bad', $p->bathroom_equipment) !!}
                {!! $row('Küche', $p->has_fitted_kitchen ? 'inkl. Einbauküche' : null) !!}
                {!! $row('Ausrichtung', $p->orientation) !!}
            </div>

            @if (!empty($activeFeatures))
                <div class="grp">
                    <div class="gh">Merkmale</div>
                    <div class="badge-list">
                        @foreach ($activeFeatures as $feat)
                            <span class="bdg">{{ $feat }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (!empty($commonAreas))
                <div class="grp">
                    <div class="gh">Allgemeinräume</div>
                    <div class="badge-list">
                        @foreach ($commonAreas as $area)
                            <span class="bdg">{{ $area }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grp">
                <div class="gh">Energie</div>
                {!! $row('Heizung', $heatingDisplay) !!}
                {!! $row('Energieträger', $p->energy_primary_source) !!}
                {!! $row('HWB', $p->heating_demand_value ? $p->heating_demand_value . ' kWh/m²a' : null) !!}
                {!! $row('fGEE', $p->energy_efficiency_value) !!}
                {!! $row('Energieklasse', $p->heating_demand_class) !!}
                {!! $row('Ausweis bis', $p->energy_valid_until?->format('m/Y') ?: null) !!}
            </div>
        </div>
    </div>
</div>
