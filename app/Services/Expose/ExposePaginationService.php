<?php

namespace App\Services\Expose;

/**
 * Ermittelt auf Basis der Wortzahl, wie viel Spalten ein Text bekommt
 * (kurz → 1 + Bild, mittel → 2, lang → 3 + ggf. Umbruch). Dieser Modus
 * wird im Blade-Template zum Layoutwechsel benutzt.
 */
class ExposePaginationService
{
    public const SHORT_MAX = 80;
    public const MEDIUM_MAX = 400;

    public function textFlowMode(?string $text): string
    {
        $count = $this->wordCount($text);
        if ($count <= self::SHORT_MAX)  return 'short';
        if ($count <= self::MEDIUM_MAX) return 'medium';
        return 'long';
    }

    public function wordCount(?string $text): int
    {
        if ($text === null || trim($text) === '') return 0;
        return str_word_count(strip_tags($text), 0, 'ÄÖÜäöüß');
    }
}
