<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Legt bei Bearbeitungen am Inserat automatisch eine kundensichtbare
 * Aktivitaet an (Kategorie 'objekt_edit'), damit der Eigentuemer im
 * Kundenportal sieht, dass aktiv an seiner Vermarktung gearbeitet wird.
 *
 * Granularitaet:
 *   - Pro Save-Request wird maximal EIN Eintrag geschrieben (mit
 *     Feld-Liste als Titel).
 *   - Wiederholte Saves innerhalb von 10 Minuten vom selben Makler
 *     mergen sich in den letzten Eintrag (statt den Verlauf zuzuspammen).
 *
 * Privacy:
 *   - Wir speichern keine konkreten alten/neuen Werte — nur die
 *     kundenfreundlichen Feld-Labels. So entstehen keine unbeabsichtigten
 *     Datenlecks (z.B. "Preis von 900k auf 850k gesenkt" wuerde dem
 *     Eigentuemer Verhandlungsinfo geben die er nicht bekommen soll).
 */
class PropertyActivityLogger
{
    public const CATEGORY = 'objekt_edit';

    /** Debounce-Fenster in Minuten fuer Merge-Logik. */
    private const DEDUP_WINDOW_MINUTES = 10;

    /** Max. Anzahl Feld-Labels im sichtbaren Activity-Text (Rest: "und N weitere"). */
    private const MAX_LABELS_IN_TEXT = 4;

    /**
     * Kundenfreundliche Labels je Datenbank-Spalte.
     *
     * Spalten die hier NICHT vorkommen werden bewusst nicht geloggt (technische
     * Felder wie immoji_id, updated_at, internal_rating, broker_id, is_published
     * gehoeren nicht in die Kunden-Timeline).
     */
    private const FIELD_LABELS = [
        // Basis
        'title' => 'Titel',
        'project_name' => 'Projektname',
        'ref_id' => 'Objektnummer',
        'subtitle' => 'Untertitel',
        'address' => 'Adresse',
        'city' => 'Ort',
        'zip' => 'PLZ',
        'house_number' => 'Hausnummer',
        'staircase' => 'Stiege',
        'door' => 'Tuer',
        'entrance' => 'Eingang',
        'address_floor' => 'Etage',
        'object_type' => 'Immobilientyp',
        'property_category' => 'Kategorie',
        'object_subtype' => 'Unterkategorie',
        'marketing_type' => 'Vermarktungsart',

        // Preise & Konditionen
        'purchase_price' => 'Kaufpreis',
        'rental_price' => 'Miete',
        'rent_warm' => 'Warmmiete',
        'rent_deposit' => 'Kaution',
        'price_per_m2' => 'Preis pro m²',
        'commission_percent' => 'Provision',
        'buyer_commission_percent' => 'Käufer-Provision',
        'commission_total' => 'Gesamt-Provision',
        'commission_makler' => 'Makler-Provision',
        'commission_note' => 'Provisions-Hinweis',

        // Flaechen
        'living_area' => 'Wohnfläche',
        'realty_area' => 'Nutzfläche',
        'free_area' => 'Grundstücksfläche',
        'area_balcony' => 'Balkonfläche',
        'area_terrace' => 'Terrassenfläche',
        'area_garden' => 'Gartenfläche',
        'area_basement' => 'Kellerfläche',
        'area_loggia' => 'Loggiafläche',
        'area_garage' => 'Garagenfläche',
        'office_space' => 'Bürofläche',
        'balcony_count' => 'Balkon-Anzahl',
        'terrace_count' => 'Terrassen-Anzahl',
        'garden_count' => 'Garten-Anzahl',
        'basement_count' => 'Keller-Anzahl',
        'loggia_count' => 'Loggia-Anzahl',
        'total_area' => 'Gesamtfläche',

        // Zimmer / Stockwerk
        'rooms_amount' => 'Zimmer',
        'bedrooms' => 'Schlafzimmer',
        'bathrooms' => 'Badezimmer',
        'toilets' => 'WCs',
        'floor_number' => 'Stockwerk',
        'floor_count' => 'Stockwerke gesamt',

        // Beschreibungen
        'realty_description' => 'Objektbeschreibung',
        'location_description' => 'Lagebeschreibung',
        'equipment_description' => 'Ausstattungsbeschreibung',
        'other_description' => 'Sonstige Angaben',
        'highlights' => 'Highlights',

        // Bau / Zustand
        'construction_year' => 'Baujahr',
        'year_renovated' => 'Sanierungsjahr',
        'construction_type' => 'Bauart',
        'ownership_type' => 'Eigentumsform',
        'condition_note' => 'Zustandsbeschreibung',
        'realty_condition' => 'Zustand',
        'quality' => 'Ausstattungsqualität',
        'furnishing' => 'Ausstattung',
        'flooring' => 'Bodenbelag',
        'bathroom_equipment' => 'Badausstattung',
        'kitchen_type' => 'Küche',
        'orientation' => 'Ausrichtung',
        'noise_level' => 'Lärmbelastung',

        // Energie
        'heating' => 'Heizung',
        'energy_certificate' => 'Energieausweis',
        'heating_demand_value' => 'HWB',
        'energy_efficiency_value' => 'fGEE',
        'heating_demand_class' => 'Energieklasse',
        'energy_type' => 'Energieträger',
        'energy_primary_source' => 'Primärenergieträger',
        'energy_valid_until' => 'Energieausweis gültig bis',

        // Parken
        'garage_spaces' => 'Garagen',
        'parking_spaces' => 'Stellplätze',
        'parking_type' => 'Stellplatzart',
        'parking_price' => 'Stellplatz-Preis',

        // Ausstattung (booleans)
        'has_basement' => 'Keller',
        'has_garden' => 'Garten',
        'has_elevator' => 'Aufzug',
        'has_balcony' => 'Balkon',
        'has_terrace' => 'Terrasse',
        'has_loggia' => 'Loggia',
        'has_fitted_kitchen' => 'Einbauküche',
        'has_air_conditioning' => 'Klimaanlage',
        'has_pool' => 'Pool',
        'has_sauna' => 'Sauna',
        'has_fireplace' => 'Kamin',
        'has_alarm' => 'Alarmanlage',
        'has_barrier_free' => 'Barrierefreiheit',
        'has_guest_wc' => 'Gäste-WC',
        'has_storage_room' => 'Abstellraum',
        'has_washing_connection' => 'Waschmaschinenanschluss',
        'has_cellar' => 'Kellerabteil',

        // Nebenkosten / Kosten
        'operating_costs' => 'Betriebskosten',
        'maintenance_reserves' => 'Rücklage',
        'heating_costs' => 'Heizkosten',
        'warm_water_costs' => 'Warmwasserkosten',
        'cooling_costs' => 'Kühlungskosten',
        'admin_costs' => 'Verwaltungskosten',
        'elevator_costs' => 'Aufzugskosten',
        'parking_costs_monthly' => 'Parkplatzkosten',
        'other_costs' => 'Sonstige Kosten',
        'monthly_costs' => 'Monatliche Kosten',

        // Neubau
        'builder_company' => 'Bauträger',
        'property_manager' => 'Hausverwaltung',
        'construction_start' => 'Baustart',
        'construction_end' => 'Fertigstellung',
        'move_in_date' => 'Einzugstermin',
        'available_from' => 'Verfügbar ab',
        'total_units' => 'Einheiten',

        // Grundstueck
        'plot_dedication' => 'Widmung',
        'plot_buildable' => 'Bebaubarkeit',
        'plot_developed' => 'Aufschließung',

        // Kontakt / Eigentuemer-Stammdaten (selten, aber doch)
        'owner_name' => 'Eigentümer-Name',
        'owner_phone' => 'Eigentümer-Telefon',
        'owner_email' => 'Eigentümer-E-Mail',
        'contact_person' => 'Ansprechpartner',
        'contact_phone' => 'Ansprechpartner-Telefon',
        'contact_email' => 'Ansprechpartner-E-Mail',

        // Komplexe JSON-Felder (Diff auf Strukturebene)
        'property_history' => 'Sanierungen',
        'building_details' => 'Gebäudedetails',
        'platforms' => 'Online-Portale',

        // Status / Verfuegbarkeit
        'realty_status' => 'Status',
        'available_text' => 'Verfügbarkeitstext',
        'ad_tag' => 'Werbetag',
    ];

    /** Technische Felder die nie geloggt werden (defensive Liste). */
    private const IGNORED_COLUMNS = [
        'id', 'updated_at', 'created_at',
        'immoji_id', 'immoji_source', 'immoji_last_sync', 'immoji_error',
        'broker_id', 'customer_id', 'is_published', 'internal_rating',
        'parent_id', 'project_group_id', 'sold_at',
        'latitude', 'longitude',
        'on_hold', 'inserat_since',
    ];

    /**
     * Vergleicht alten und neuen Zustand und schreibt eine Aktivitaet, falls
     * kundensichtbare Felder geaendert wurden. Gibt die Activity-ID zurueck
     * (neu oder gemergt) oder null falls nichts zu loggen war.
     */
    public function logFieldChanges(
        int $propertyId,
        array $oldRow,
        array $newValues,
        ?string $stakeholder = null
    ): ?int {
        $changed = [];

        foreach ($newValues as $col => $newVal) {
            if (in_array($col, self::IGNORED_COLUMNS, true)) continue;
            if (!array_key_exists($col, self::FIELD_LABELS)) continue;

            $oldVal = $oldRow[$col] ?? null;
            if ($this->valuesEqual($oldVal, $newVal, $col)) continue;

            $changed[] = self::FIELD_LABELS[$col];
        }

        if (empty($changed)) return null;

        return $this->persistOrMerge($propertyId, $changed, $stakeholder);
    }

    /**
     * Schreibt ein freies Event (z.B. "Foto hinzugefuegt: hausfront.jpg"),
     * bei dem kein Diff-Vergleich moeglich ist. Mergt ebenfalls in den
     * letzten 'objekt_edit'-Eintrag, falls Fenster nicht abgelaufen.
     */
    public function logEvent(int $propertyId, string $label, ?string $stakeholder = null): ?int
    {
        $label = trim($label);
        if ($label === '') return null;
        return $this->persistOrMerge($propertyId, [$label], $stakeholder);
    }

    private function persistOrMerge(int $propertyId, array $newLabels, ?string $stakeholder): int
    {
        $stakeholder = $stakeholder ?: ($this->currentUserName() ?: 'Makler');
        $now = now();

        try {
            $last = DB::table('activities')
                ->where('property_id', $propertyId)
                ->where('category', self::CATEGORY)
                ->where('stakeholder', $stakeholder)
                ->where('created_at', '>=', $now->copy()->subMinutes(self::DEDUP_WINDOW_MINUTES))
                ->orderByDesc('id')
                ->first();

            if ($last) {
                $existingLabels = $last->result ? array_filter(array_map('trim', explode('|', $last->result))) : [];
                $merged = array_values(array_unique(array_merge($existingLabels, $newLabels)));
                DB::table('activities')->where('id', $last->id)->update([
                    'activity'   => $this->buildActivityText($merged),
                    'result'     => implode('|', $merged),
                    'updated_at' => $now,
                ]);
                return (int) $last->id;
            }

            return (int) DB::table('activities')->insertGetId([
                'property_id'   => $propertyId,
                'activity_date' => $now->toDateString(),
                'stakeholder'   => $stakeholder,
                'activity'      => $this->buildActivityText($newLabels),
                'result'        => implode('|', $newLabels),
                'category'      => self::CATEGORY,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } catch (\Throwable $e) {
            // Niemals den eigentlichen Save-Request sprengen wenn Activity-Log scheitert.
            Log::warning("PropertyActivityLogger failed for property={$propertyId}: " . $e->getMessage());
            return 0;
        }
    }

    private function buildActivityText(array $labels): string
    {
        $n = count($labels);
        if ($n === 0) return 'Objektdaten aktualisiert';
        if ($n === 1) return "Objektdaten aktualisiert: {$labels[0]}";
        if ($n <= self::MAX_LABELS_IN_TEXT) {
            return 'Objektdaten aktualisiert: ' . implode(', ', $labels);
        }
        $head = array_slice($labels, 0, self::MAX_LABELS_IN_TEXT);
        $rest = $n - self::MAX_LABELS_IN_TEXT;
        return 'Objektdaten aktualisiert: ' . implode(', ', $head) . " und {$rest} weitere";
    }

    /**
     * Robuste Gleichheitspruefung:
     *   - null/"" gelten als gleichwertig ("leer")
     *   - numerische Strings werden als Zahlen verglichen (3.6 == "3.60")
     *   - JSON-Spalten werden kanonisch geparst und verglichen
     */
    private function valuesEqual($a, $b, string $column): bool
    {
        $a = $this->normalizeEmpty($a);
        $b = $this->normalizeEmpty($b);

        if ($a === null && $b === null) return true;
        if ($a === null || $b === null) return false;

        // JSON-Spalten: inhaltlicher Vergleich (Reihenfolge egal).
        if (in_array($column, ['property_history', 'building_details', 'platforms'], true)) {
            $aj = is_string($a) ? json_decode($a, true) : $a;
            $bj = is_string($b) ? json_decode($b, true) : $b;
            $aCanon = is_array($aj) ? $this->canonicalize($aj) : $a;
            $bCanon = is_array($bj) ? $this->canonicalize($bj) : $b;
            return json_encode($aCanon) === json_encode($bCanon);
        }

        // Numerik-tolerant
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0001;
        }

        // Booleans / 0/1-Strings
        if (in_array((string) $a, ['0', '1'], true) && in_array((string) $b, ['0', '1'], true)) {
            return (string) $a === (string) $b;
        }

        if (is_string($a) && is_string($b)) {
            return trim($a) === trim($b);
        }

        return $a == $b;
    }

    private function normalizeEmpty($v)
    {
        if ($v === '' || $v === 'null') return null;
        if (is_string($v) && trim($v) === '') return null;
        return $v;
    }

    private function canonicalize($v)
    {
        if (!is_array($v)) return $v;
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) {
            $out = [];
            foreach ($v as $item) $out[] = $this->canonicalize($item);
            // Listen sortiert vergleichen (Reihenfolge bei Immoji-Sync irrelevant)
            usort($out, function ($a, $b) {
                return strcmp(json_encode($a), json_encode($b));
            });
            return $out;
        }
        ksort($v);
        $out = [];
        foreach ($v as $k => $item) $out[$k] = $this->canonicalize($item);
        return $out;
    }

    private function currentUserName(): ?string
    {
        try {
            $u = Auth::user();
            return $u?->name ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
