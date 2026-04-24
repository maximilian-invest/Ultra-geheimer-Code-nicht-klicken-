<?php

namespace App\Services\Expose;

use App\Models\Property;

/**
 * Baut eine Default-Exposé-Konfiguration aus einer Property.
 * Variiert zwischen klassischen Bild-Layouts (L1–L5, LM) und Editorial-
 * Mixed-Layouts (M1/M3/M4) für Abwechslung — aber nicht auf jeder Seite,
 * damit das Exposé nicht überladen wirkt.
 */
class ExposeConfigBuilder
{
    /** Fallback-Zitate wenn die Property keinen eigenen Pool hat. */
    private const DEFAULT_CAPTIONS = [
        'Wo Tageslicht den Raum formt.',
        'Ein Ort, an dem Tage länger bleiben.',
        'Mehr als vier Wände.',
        'Zuhause ist, wo das Herz bleibt.',
        'Das nächste Kapitel beginnt hier.',
    ];

    /** Editorial-Layouts rotieren in dieser Reihenfolge durch das Exposé. */
    private const EDITORIAL_CYCLE = ['M1', 'M4', 'M3'];

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

        // Sanierungen-Seite nur wenn property_history Einträge enthält.
        if ($this->hasSanierungen($property)) {
            $pages[] = ['type' => 'sanierungen'];
        }

        $pages[] = ['type' => 'lage'];

        // Haus-Bild aus Impressionen-Pool entfernen.
        $forImpressionen = $rest->slice(1)->values();

        // Wenn Impressionen-Pool Bilder hat: Intro-Seite mit erstem Bild voranstellen.
        // Das Intro-Bild wird aus dem Pool entfernt, damit es nicht doppelt erscheint.
        $introImage = $forImpressionen->first();
        if ($introImage) {
            $pages[] = [
                'type'     => 'impressionen_intro',
                'image_id' => $introImage->id,
            ];
            $forImpressionen = $forImpressionen->slice(1)->values();
        }

        $captions = $this->captionPool($property);
        $editorialIdx = 0;

        foreach ($this->chunkForImpressionen($forImpressionen->pluck('id')->all()) as $chunk) {
            $page = [
                'type'      => 'impressionen',
                'layout'    => $chunk['layout'],
                'image_ids' => $chunk['ids'],
            ];
            if (in_array($chunk['layout'], self::EDITORIAL_CYCLE, true) && !empty($captions)) {
                $page['caption'] = $captions[$editorialIdx % count($captions)];
                $editorialIdx++;
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
     * Verteilt Bilder auf Impressionen-Seiten mit Variation. Grundidee:
     *  - Klassische Seiten (L4, LM) zeigen pure Bilder.
     *  - Editorial-Seiten (M1/M3/M4) unterbrechen den Flow mit Text +/- Bildern.
     *  - Reihenfolge: alle 2 klassische Seiten kommt eine Editorial-Seite.
     *  - Masonry (LM) wird bei 4+ Bildern bevorzugt gegenüber L4 für mehr
     *    visuelle Asymmetrie (1 großes + 3 kleine).
     *
     * @return array<int, array{layout: string, ids: array<int>}>
     */
    private function chunkForImpressionen(array $imageIds): array
    {
        $chunks = [];
        $i = 0;
        $n = count($imageIds);
        $pagesSinceLastEditorial = 0;
        $classicalPageIdx = 0;

        // Alterniere zwischen L4 (gleichmäßig) und LM (Masonry) für Variation.
        $classicalCycle = ['LM', 'L4'];

        while ($i < $n) {
            $remaining = $n - $i;

            // Editorial einstreuen? Regel:
            //  - Mindestens eine klassische Seite vorher (nicht gleich am Anfang)
            //  - Genug Bilder für das Layout übrig (M1=3, M3=3, M4=1)
            //  - Seit letzter Editorial-Seite mindestens 1 klassische
            //  - Editorials werden zyklisch durchrotiert (M1 → M4 → M3 → M1 …)
            $editorialSlot = $i > 0 && $pagesSinceLastEditorial >= 1;
            if ($editorialSlot) {
                $nextEditorial = self::EDITORIAL_CYCLE[count($chunks) % count(self::EDITORIAL_CYCLE)];
                $need = $this->imageCountFor($nextEditorial);
                if ($remaining >= $need && $remaining > $need) {
                    // Nur wenn danach noch eine klassische Seite möglich ist,
                    // damit die Editorial nicht als letzte einsame Seite steht.
                    $chunks[] = [
                        'layout' => $nextEditorial,
                        'ids'    => array_slice($imageIds, $i, $need),
                    ];
                    $i += $need;
                    $pagesSinceLastEditorial = 0;
                    continue;
                }
            }

            // Klassische Seite
            [$layout, $count] = match (true) {
                $remaining === 1 => ['L1', 1],
                $remaining === 2 => ['L2', 2],
                $remaining === 3 => ['L3', 3],
                $remaining >= 4  => [$classicalCycle[$classicalPageIdx % count($classicalCycle)], 4],
                default          => ['L1', 1],
            };
            $chunks[] = [
                'layout' => $layout,
                'ids'    => array_slice($imageIds, $i, $count),
            ];
            $i += $count;
            $pagesSinceLastEditorial++;
            $classicalPageIdx++;
        }

        return $chunks;
    }

    /** Wie viele Bilder braucht ein Editorial-Layout? */
    private function imageCountFor(string $layout): int
    {
        return match ($layout) {
            'M1' => 3,
            'M3' => 3,
            'M4' => 1,
            default => 1,
        };
    }

    /**
     * Prüft ob die Property mindestens einen Sanierungs-/Historie-Eintrag
     * mit Titel oder Beschreibung hat. Leere History → keine Seite.
     */
    private function hasSanierungen(Property $property): bool
    {
        $raw = $property->property_history;
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) return false;

        foreach ($raw as $entry) {
            if (!is_array($entry)) continue;
            if (!empty($entry['title']) || !empty($entry['description'])) {
                return true;
            }
        }
        return false;
    }

    /** Liest Makler-Pool zeilenweise; fällt auf Default zurück. */
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
