<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * ZENTRALE Kaufanbot-Zaehlung.
 * ALLE Stellen im Projekt muessen diese Klasse verwenden.
 *
 * EINZIGE REGEL: Ein Kaufanbot zaehlt NUR wenn ein PDF hochgeladen wurde.
 * - Neubauprojekte: property_units.kaufanbot_pdf
 * - Bestandsobjekte: property_kaufanbote.pdf_path (eigene Tabelle)
 * Keine Activities, keine Stakeholder-Zaehlung. NUR PDFs.
 *
 * DEDUPLIKATION:
 * Wenn eine Zeile in property_kaufanbote.unit_ids auf ein Unit zeigt, wird
 * dieses Unit NICHT nochmal separat gezaehlt - es ist dasselbe Kaufanbot.
 */
class KaufanbotHelper
{
    /**
     * Holt alle Unit-IDs die schon ueber property_kaufanbote referenziert sind.
     * Diese Units duerfen NICHT nochmal ueber property_units gezaehlt werden.
     */
    private static function referencedUnitIds(?int $propertyId = null, ?int $brokerId = null): array
    {
        try {
            $query = DB::table('property_kaufanbote')
                ->whereNotNull('pdf_path')
                ->where('pdf_path', '!=', '')
                ->whereNotNull('unit_ids');
            if ($propertyId !== null) {
                $query->where('property_id', $propertyId);
            }
            if ($brokerId !== null) {
                $query->whereIn('property_id', function ($q) use ($brokerId) {
                    $q->select('id')->from('properties')->where('broker_id', $brokerId);
                });
            }
            $rows = $query->pluck('unit_ids');

            $ids = [];
            foreach ($rows as $json) {
                $decoded = json_decode($json ?? '[]', true);
                if (is_array($decoded)) {
                    foreach ($decoded as $uid) {
                        if (is_numeric($uid)) {
                            $ids[] = (int) $uid;
                        }
                    }
                }
            }
            return array_values(array_unique($ids));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Kaufanbote fuer eine Property zaehlen.
     * NUR hochgeladene PDFs zaehlen. Units die ueber property_kaufanbote
     * referenziert sind werden nicht doppelt gezaehlt.
     */
    public static function count(int $propertyId): int
    {
        // 1) Standalone Kaufanbote (property_kaufanbote mit PDF)
        $fromStandalone = 0;
        try {
            $fromStandalone = DB::table('property_kaufanbote')
                ->where('property_id', $propertyId)
                ->whereNotNull('pdf_path')
                ->where('pdf_path', '!=', '')
                ->count();
        } catch (\Exception $e) {
            // Tabelle existiert noch nicht
        }

        // 2) Units mit kaufanbot_pdf - aber NICHT die schon in property_kaufanbote referenziert sind
        $referencedUnits = self::referencedUnitIds($propertyId);
        $unitQuery = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->whereNotNull('kaufanbot_pdf')
            ->where('kaufanbot_pdf', '!=', '');
        if (!empty($referencedUnits)) {
            $unitQuery->whereNotIn('id', $referencedUnits);
        }
        $fromUnits = $unitQuery->count();

        return $fromStandalone + $fromUnits;
    }

    /**
     * Kaufanbote ueber ALLE Properties (optional broker-gefiltert).
     */
    public static function countAll(?int $brokerId = null): int
    {
        $brokerFilter = '';
        $params = [];
        if ($brokerId) {
            $brokerFilter = "AND property_id IN (SELECT id FROM properties WHERE broker_id = ?)";
            $params = [$brokerId];
        }

        // 1) Standalone Kaufanbote
        $fromStandalone = 0;
        try {
            $fromStandalone = (int) DB::selectOne(
                "SELECT COUNT(*) as cnt FROM property_kaufanbote
                 WHERE pdf_path IS NOT NULL AND pdf_path != '' {$brokerFilter}",
                $params
            )->cnt;
        } catch (\Exception $e) {}

        // 2) Units mit kaufanbot_pdf, ohne die schon referenzierten
        $referencedUnits = self::referencedUnitIds(null, $brokerId);
        $notInFilter = '';
        if (!empty($referencedUnits)) {
            $notInFilter = ' AND id NOT IN (' . implode(',', array_map('intval', $referencedUnits)) . ')';
        }
        $fromUnits = (int) DB::selectOne(
            "SELECT COUNT(*) as cnt FROM property_units
             WHERE kaufanbot_pdf IS NOT NULL AND kaufanbot_pdf != '' {$brokerFilter} {$notInFilter}",
            $params
        )->cnt;

        return $fromStandalone + $fromUnits;
    }

    /**
     * Kaufanbote pro Property als Map: [property_id => count].
     */
    public static function countByProperty(?int $brokerId = null): array
    {
        $brokerFilter = '';
        $params = [];
        if ($brokerId) {
            $brokerFilter = "AND property_id IN (SELECT id FROM properties WHERE broker_id = ?)";
            $params = [$brokerId];
        }

        $result = [];

        // 1) Standalone Kaufanbote (volle Zaehlung)
        try {
            $standaloneCounts = DB::select(
                "SELECT property_id, COUNT(*) as cnt FROM property_kaufanbote
                 WHERE pdf_path IS NOT NULL AND pdf_path != '' {$brokerFilter}
                 GROUP BY property_id",
                $params
            );
            foreach ($standaloneCounts as $row) {
                $result[$row->property_id] = (int) $row->cnt;
            }
        } catch (\Exception $e) {}

        // 2) Units mit kaufanbot_pdf - ohne die schon referenzierten
        $referencedUnits = self::referencedUnitIds(null, $brokerId);
        $notInFilter = '';
        if (!empty($referencedUnits)) {
            $notInFilter = ' AND id NOT IN (' . implode(',', array_map('intval', $referencedUnits)) . ')';
        }
        $unitCounts = DB::select(
            "SELECT property_id, COUNT(*) as cnt FROM property_units
             WHERE kaufanbot_pdf IS NOT NULL AND kaufanbot_pdf != '' {$brokerFilter} {$notInFilter}
             GROUP BY property_id",
            $params
        );
        foreach ($unitCounts as $row) {
            $result[$row->property_id] = ($result[$row->property_id] ?? 0) + (int) $row->cnt;
        }

        return $result;
    }
}
