<?php

namespace App\Services\Expose;

use App\Models\Property;

/**
 * Baut eine Default-Exposé-Konfiguration aus einer Property.
 * Entscheidet, welches Bild Cover ist, verteilt den Rest auf Impressionen-Seiten
 * nach Layout-Tabelle aus Spec §3.6.
 */
class ExposeConfigBuilder
{
    /**
     * Seitenreihenfolge: cover → details → haus → lage → impressionen × n → kontakt.
     */
    public function build(Property $property): array
    {
        $images = $property->images()
            ->where('is_public', true)
            ->where('is_floorplan', false)
            ->reorder()
            ->orderByDesc('is_title_image')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $coverImage = $images->first();
        $rest = $images->slice(1)->values();

        $pages = [];
        $pages[] = ['type' => 'cover', 'image_id' => $coverImage?->id];
        $pages[] = ['type' => 'details'];
        $pages[] = [
            'type'     => 'haus',
            'image_id' => $coverImage?->id,
        ];
        $pages[] = ['type' => 'lage'];

        // Alle Nicht-Cover-Bilder kommen in Impressionen.
        $forImpressionen = $rest;
        foreach ($this->chunkForImpressionen($forImpressionen->pluck('id')->all()) as $chunk) {
            $pages[] = [
                'type'      => 'impressionen',
                'layout'    => $chunk['layout'],
                'image_ids' => $chunk['ids'],
            ];
        }

        $pages[] = ['type' => 'kontakt'];

        return [
            'claim_text' => null,
            'pages'      => $pages,
        ];
    }

    /**
     * Verteilt Bilder auf Impressionen-Seiten anhand Tabelle aus Spec §3.6.
     * Gibt Liste von ['layout' => 'L1-L5', 'ids' => [...]] zurück.
     */
    private function chunkForImpressionen(array $imageIds): array
    {
        $chunks = [];
        $i = 0;
        $n = count($imageIds);

        while ($i < $n) {
            $remaining = $n - $i;
            [$layout, $count] = match (true) {
                $remaining === 1       => ['L1', 1],
                $remaining === 2       => ['L2', 2],
                $remaining === 3       => ['L3', 3],
                $remaining === 5       => ['L5', 5],
                $remaining >= 4        => ['L4', 4],
                default                => ['L1', 1],
            };
            $chunks[] = [
                'layout' => $layout,
                'ids'    => array_slice($imageIds, $i, $count),
            ];
            $i += $count;
        }
        return $chunks;
    }
}
