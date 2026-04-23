<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repariert Properties, die vor dem Bugfix (2026-04-23) ueber das Auf-
 * nahmeprotokoll angelegt wurden und rohe JSON-Array-Strings in Multi-
 * Select-Feldern stehen hatten, z. B.:
 *     heating = '["Heizkörper"]'
 *     flooring = '["Teppich","Natursteinboden"]'
 *     bathroom_equipment = '["Gäste-WC","Badewanne"]'
 *     common_areas = '["fahrradraum","kinderwagenraum"]'
 *
 * Konvention der DB ist kommaseparierter Freitext — entsprechend konver-
 * tieren wir hier. Zusaetzlich werden Orientation-Codes (N/NO/O/...) zu
 * lesbarer Form ("Nord"/"Nord-Ost"/...) aufgeloest.
 *
 * Idempotent: Werte, die bereits Komma-Freitext sind, bleiben unveraendert.
 */
return new class extends Migration {
    public function up(): void
    {
        $orientationMap = [
            'N'  => 'Nord',
            'NO' => 'Nord-Ost',
            'O'  => 'Ost',
            'SO' => 'Süd-Ost',
            'S'  => 'Süd',
            'SW' => 'Süd-West',
            'W'  => 'West',
            'NW' => 'Nord-West',
        ];

        $rows = DB::table('properties')
            ->select('id', 'heating', 'flooring', 'bathroom_equipment', 'common_areas', 'orientation')
            ->where(function ($q) {
                $q->where('heating', 'LIKE', '[%')
                  ->orWhere('flooring', 'LIKE', '[%')
                  ->orWhere('bathroom_equipment', 'LIKE', '[%')
                  ->orWhere('common_areas', 'LIKE', '[%');
            })
            ->orWhereIn('orientation', array_keys($orientationMap))
            ->get();

        foreach ($rows as $row) {
            $update = ['updated_at' => now()];

            foreach (['heating', 'flooring', 'bathroom_equipment', 'common_areas'] as $field) {
                $val = $row->$field;
                if (is_string($val) && $val !== '' && $val[0] === '[') {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $clean = [];
                        foreach ($decoded as $item) {
                            if (is_scalar($item)) {
                                $t = trim((string) $item);
                                if ($t !== '') $clean[] = $t;
                            }
                        }
                        $update[$field] = $clean === [] ? null : implode(', ', $clean);
                    }
                }
            }

            $ori = $row->orientation;
            if (is_string($ori) && isset($orientationMap[strtoupper(trim($ori))])) {
                $update['orientation'] = $orientationMap[strtoupper(trim($ori))];
            }

            if (count($update) > 1) {
                DB::table('properties')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Keine Rueckabwicklung — die korrigierten Werte sind dauerhaft richtig.
    }
};
