<?php

namespace Tests\Unit\Services;

use App\Services\ImmojiSyncStateService;
use PHPUnit\Framework\TestCase;

class ImmojiSyncStateServiceTest extends TestCase
{
    private ImmojiSyncStateService $service;

    protected function setUp(): void
    {
        $this->service = new ImmojiSyncStateService();
    }

    public function test_hash_is_deterministic_for_identical_input(): void
    {
        $a = $this->service->hashSection(['title' => 'Haus', 'price' => 100]);
        $b = $this->service->hashSection(['title' => 'Haus', 'price' => 100]);
        $this->assertSame($a, $b);
    }

    public function test_hash_ignores_key_order(): void
    {
        $a = $this->service->hashSection(['title' => 'Haus', 'price' => 100]);
        $b = $this->service->hashSection(['price' => 100, 'title' => 'Haus']);
        $this->assertSame($a, $b);
    }

    public function test_hash_respects_nested_key_order(): void
    {
        $a = $this->service->hashSection(['outer' => ['a' => 1, 'b' => 2]]);
        $b = $this->service->hashSection(['outer' => ['b' => 2, 'a' => 1]]);
        $this->assertSame($a, $b);
    }

    public function test_hash_differs_when_value_changes(): void
    {
        $a = $this->service->hashSection(['title' => 'Haus']);
        $b = $this->service->hashSection(['title' => 'Wohnung']);
        $this->assertNotSame($a, $b);
    }

    public function test_hash_of_null_is_stable_and_distinct(): void
    {
        $null = $this->service->hashSection(null);
        $empty = $this->service->hashSection([]);
        $this->assertNotSame($null, $empty);
        $this->assertSame($null, $this->service->hashSection(null));
    }

    public function test_files_signature_depends_only_on_visible_attributes(): void
    {
        $imagesA = [
            (object) [
                'id' => 1, 'sort_order' => 0, 'is_title_image' => 1,
                'title' => 'Front', 'original_name' => 'front.jpg',
                'filename' => '001_front.jpg', 'category' => 'aussenansicht',
                'path' => 'property_images/5/001_front.jpg',
                'immoji_source' => 'tmp/abc', // must be ignored
                'updated_at' => '2026-04-19 10:00:00', // must be ignored
            ],
        ];
        $imagesB = [
            (object) [
                'id' => 1, 'sort_order' => 0, 'is_title_image' => 1,
                'title' => 'Front', 'original_name' => 'front.jpg',
                'filename' => '001_front.jpg', 'category' => 'aussenansicht',
                'path' => 'property_images/5/001_front.jpg',
                'immoji_source' => 'tmp/xyz', // different token
                'updated_at' => '2026-04-20 14:30:00', // different timestamp
            ],
        ];
        $this->assertSame(
            $this->service->filesSignature($imagesA),
            $this->service->filesSignature($imagesB),
        );
    }

    public function test_files_signature_changes_when_image_added(): void
    {
        $one = [(object) ['id' => 1, 'sort_order' => 0, 'is_title_image' => 1, 'title' => '', 'original_name' => '', 'filename' => 'a', 'category' => 'sonstiges', 'path' => 'p/a']];
        $two = array_merge($one, [(object) ['id' => 2, 'sort_order' => 1, 'is_title_image' => 0, 'title' => '', 'original_name' => '', 'filename' => 'b', 'category' => 'sonstiges', 'path' => 'p/b']]);
        $this->assertNotSame($this->service->filesSignature($one), $this->service->filesSignature($two));
    }

    public function test_files_signature_ignores_input_order(): void
    {
        $img1 = (object) ['id' => 1, 'sort_order' => 0, 'is_title_image' => 1, 'title' => '', 'original_name' => '', 'filename' => 'a', 'category' => 'sonstiges', 'path' => 'p/a'];
        $img2 = (object) ['id' => 2, 'sort_order' => 1, 'is_title_image' => 0, 'title' => '', 'original_name' => '', 'filename' => 'b', 'category' => 'sonstiges', 'path' => 'p/b'];
        $this->assertSame(
            $this->service->filesSignature([$img1, $img2]),
            $this->service->filesSignature([$img2, $img1]),
        );
    }

    public function test_diff_reports_changed_sections(): void
    {
        $old = [
            'general' => 'h1', 'costs' => 'h2', 'areas' => 'h3',
            'descriptions' => 'h4', 'building' => 'h5', 'files' => 'h6',
        ];
        $new = [
            'general' => 'h1',        // unchanged
            'costs' => 'h2-changed',  // changed
            'areas' => 'h3',          // unchanged
            'descriptions' => 'h4-changed', // changed
            'building' => 'h5',       // unchanged
            'files' => 'h6',          // unchanged
        ];
        $this->assertSame(['costs', 'descriptions'], $this->service->diffSections($old, $new));
    }

    public function test_diff_reports_all_sections_when_old_is_null(): void
    {
        $new = [
            'general' => 'h1', 'costs' => 'h2', 'areas' => 'h3',
            'descriptions' => 'h4', 'building' => 'h5', 'files' => 'h6',
        ];
        $this->assertSame(
            ['general', 'costs', 'areas', 'descriptions', 'building', 'files'],
            $this->service->diffSections(null, $new),
        );
    }

    public function test_diff_reports_nothing_when_everything_identical(): void
    {
        $hashes = [
            'general' => 'h1', 'costs' => 'h2', 'areas' => 'h3',
            'descriptions' => 'h4', 'building' => 'h5', 'files' => 'h6',
        ];
        $this->assertSame([], $this->service->diffSections($hashes, $hashes));
    }
}
