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
 */
class KaufanbotHelper
{
    /**
     * Kaufanbote fuer eine Property zaehlen.
     * NUR hochgeladene PDFs zaehlen.
     */
    public static function count(int $propertyId): int
    {
        // Neubauprojekte: Units mit kaufanbot_pdf
        $fromUnits = DB::table('property_units')
            ->where('property_id', $propertyId)
            ->whereNotNull('kaufanbot_pdf')
            ->where('kaufanbot_pdf', '!=', '')
            ->count();

        // Bestandsobjekte: eigene Kaufanbot-Tabelle
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

        return $fromUnits + $fromStandalone;
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

        $fromUnits = (int) DB::selectOne(
            "SELECT COUNT(*) as cnt FROM property_units
             WHERE kaufanbot_pdf IS NOT NULL AND kaufanbot_pdf != '' {$brokerFilter}",
            $params
        )->cnt;

        $fromStandalone = 0;
        try {
            $fromStandalone = (int) DB::selectOne(
                "SELECT COUNT(*) as cnt FROM property_kaufanbote
                 WHERE pdf_path IS NOT NULL AND pdf_path != '' {$brokerFilter}",
                $params
            )->cnt;
        } catch (\Exception $e) {}

        return $fromUnits + $fromStandalone;
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

        $unitCounts = DB::select(
            "SELECT property_id, COUNT(*) as cnt FROM property_units
             WHERE kaufanbot_pdf IS NOT NULL AND kaufanbot_pdf != '' {$brokerFilter}
             GROUP BY property_id",
            $params
        );

        $result = [];
        foreach ($unitCounts as $row) {
            $result[$row->property_id] = (int) $row->cnt;
        }

        try {
            $standaloneCounts = DB::select(
                "SELECT property_id, COUNT(*) as cnt FROM property_kaufanbote
                 WHERE pdf_path IS NOT NULL AND pdf_path != '' {$brokerFilter}
                 GROUP BY property_id",
                $params
            );
            foreach ($standaloneCounts as $row) {
                $result[$row->property_id] = ($result[$row->property_id] ?? 0) + (int) $row->cnt;
            }
        } catch (\Exception $e) {}

        return $result;
    }
}
