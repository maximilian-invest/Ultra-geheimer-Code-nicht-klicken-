<?php

namespace App\Services\Expose;

use App\Models\Property;

/**
 * Baut eine Default-Exposé-Konfiguration aus einer Property.
 * Entscheidet, welches Bild Cover ist, verteilt den Rest auf Impressionen-Seiten
 * und streut Editorial-Mixed-Seiten (M1: 3 Bilder + Text-Zelle) ein, sobald
 * genug Material da ist (≥ 5 Nicht-Cover-Bilder).
 */
class ExposeConfigBuilder
{
    /** Standard-Zitate-Pool, wird benutzt wenn die Property nichts hinterlegt hat. */
    private const DEFAULT_CAPTIONS = [
        'Wo Tageslicht den Raum formt.',
        'Ein Ort, an dem Tage länger bleiben.',
        'Mehr als vier Wände.',
        'Zuhause ist, wo das Herz bleibt.',
        'Das nächste Kapitel beginnt hier.',
    ];

    /** Seitenreihenfolge: cover → details → haus → lage → impressionen × n → kontakt. */
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
            'image_id' => $rest->first()?->id,
        ];
        $pages[] = ['type' => 'lage'];

        // Bild auf der Haus-Seite wird aus dem Impressionen-Pool entfernt.
        $forImpressionen = $rest->slice(1)->values();
        $captions = $this->captionPool($property);

        foreach ($this->chunkForImpressionen($forImpressionen->pluck('id')->all(), count($captions) > 0) as $idx => $chunk) {
            $page = [
                'type'      => 'impressionen',
                'layout'    => $chunk['layout'],
                'image_ids' => $chunk['ids'],
            ];
            // Editorial-Mixed-Layouts bekommen einen rotierenden Caption-Text.
            if ($chunk['layout'] === 'M1' && $captions) {
                $page['caption'] = $captions[$idx % count($captions)];
            }
            $pages[] = $page;
        }

        $pages[] = ['type' => 'kontakt'];

        return [
            'claim_text' => $property->expose_claim ?: null,
            'pages'      => $pages,
        ];
    }

    /**
     * Verteilt Bilder auf Impressionen-Seiten. Streut bei ≥ 5 verfügbaren
     * Bildern einen Editorial-Mixed (M1: 3 Bilder + Text-Zelle) alle 2 Seiten
     * ein, damit der Bild-Flow Atemluft bekommt. M1 verbraucht 3 Bilder.
     *
     * @param  bool  $editorialAllowed  false → nur klassische L-Layouts
     * @return array<int, array{layout: string, ids: array<int>}>
     */
    private function chunkForImpressionen(array $imageIds, bool $editorialAllowed): array
    {
        $chunks = [];
        $i = 0;
        $n = count($imageIds);
        $pagesSinceLastEditorial = 0;

        while ($i < $n) {
            $remaining = $n - $i;

            // Editorial-Mixed einstreuen: wenn erlaubt, ≥ 3 Bilder übrig,
            // und seit der letzten Editorial mindestens 1 klassische Seite.
            // Nicht auf der ersten Impressionen-Seite (zu früh, bricht Flow).
            $useEditorial = $editorialAllowed
                && $remaining >= 3
                && $i > 0
                && $pagesSinceLastEditorial >= 1
                && $remaining !== 3 // bei genau 3 bevorzugen wir L3 statt M1 (kein nachfolgender Rest)
                && $remaining !== 4;

            if ($useEditorial) {
                $chunks[] = [
                    'layout' => 'M1',
                    'ids'    => array_slice($imageIds, $i, 3),
                ];
                $i += 3;
                $pagesSinceLastEditorial = 0;
                continue;
            }

            [$layout, $count] = match (true) {
                $remaining === 1 => ['L1', 1],
                $remaining === 2 => ['L2', 2],
                $remaining === 3 => ['L3', 3],
                $remaining === 5 => ['L5', 5],
                $remaining >= 4  => ['L4', 4],
                default          => ['L1', 1],
            };
            $chunks[] = [
                'layout' => $layout,
                'ids'    => array_slice($imageIds, $i, $count),
            ];
            $i += $count;
            $pagesSinceLastEditorial++;
        }
        return $chunks;
    }

    /** Liest den Property-Pool zeilenweise, fällt auf Default-Vorschläge zurück. */
    private function captionPool(Property $property): array
    {
        $raw = (string) ($property->expose_captions_pool ?? '');
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $raw) ?: []),
            fn($s) => $s !== ''
        ));
        if (!empty($lines)) return $lines;

        return self::DEFAULT_CAPTIONS;
    }
}
