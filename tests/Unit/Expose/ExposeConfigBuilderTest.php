<?php

namespace Tests\Unit\Expose;

use App\Models\Property;
use App\Models\PropertyImage;
use App\Services\Expose\ExposeConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposeConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function mkProperty(array $imageSpecs = []): Property
    {
        $p = Property::factory()->create([
            'realty_description' => 'Ein schönes Haus.',
        ]);
        foreach ($imageSpecs as $i => $spec) {
            PropertyImage::create(array_merge([
                'property_id'     => $p->id,
                'filename'        => "img{$i}.jpg",
                'path'            => "property_images/{$p->id}/img{$i}.jpg",
                'sort_order'      => $i,
                'is_title_image'  => false,
                'is_floorplan'    => false,
                'is_public'       => true,
                'category'        => 'sonstiges',
            ], $spec));
        }
        return $p->fresh();
    }

    public function test_minimal_property_produces_five_fixed_pages(): void
    {
        $p = $this->mkProperty();
        $config = (new ExposeConfigBuilder())->build($p);

        $types = array_column($config['pages'], 'type');
        $this->assertEquals(['cover', 'details', 'haus', 'lage', 'kontakt'], $types);
    }

    public function test_title_image_is_selected_as_cover(): void
    {
        $p = $this->mkProperty([
            ['is_title_image' => false],
            ['is_title_image' => true],
            ['is_title_image' => false],
        ]);

        $config = (new ExposeConfigBuilder())->build($p);
        $coverPage = collect($config['pages'])->firstWhere('type', 'cover');

        $titleImage = $p->images()->where('is_title_image', true)->first();
        $this->assertEquals($titleImage->id, $coverPage['image_id']);
    }

    public function test_five_images_produce_one_L3_impressionen_page(): void
    {
        $p = $this->mkProperty([[], [], [], [], ['is_title_image' => true]]);

        $config = (new ExposeConfigBuilder())->build($p);
        $impressionen = array_values(array_filter(
            $config['pages'],
            fn($page) => $page['type'] === 'impressionen'
        ));

        // 5 Bilder total: 1 Cover, 1 fürs Haus, 3 in Impressionen → L3.
        $this->assertCount(1, $impressionen);
        $this->assertEquals('L3', $impressionen[0]['layout']);
        $this->assertCount(3, $impressionen[0]['image_ids']);
    }

    public function test_eight_images_produce_two_impressionen_pages(): void
    {
        $specs = array_fill(0, 8, []);
        $specs[0]['is_title_image'] = true;
        $p = $this->mkProperty($specs);

        $config = (new ExposeConfigBuilder())->build($p);
        $impressionen = array_values(array_filter(
            $config['pages'],
            fn($page) => $page['type'] === 'impressionen'
        ));

        // 8 Bilder total: 1 Cover, 1 fürs Haus, 6 in Impressionen → L4 (4) + L2 (2).
        $this->assertCount(2, $impressionen);
        $this->assertEquals('L4', $impressionen[0]['layout']);
        $this->assertCount(4, $impressionen[0]['image_ids']);
        $this->assertEquals('L2', $impressionen[1]['layout']);
        $this->assertCount(2, $impressionen[1]['image_ids']);
    }

    public function test_haus_image_is_different_from_cover(): void
    {
        $p = $this->mkProperty([
            ['is_title_image' => true],  // cover
            [],                          // for haus
            [],                          // impressionen
            [],                          // impressionen
        ]);

        $config = (new ExposeConfigBuilder())->build($p);
        $pages = collect($config['pages']);

        $cover = $pages->firstWhere('type', 'cover');
        $haus  = $pages->firstWhere('type', 'haus');

        $this->assertNotNull($cover['image_id']);
        $this->assertNotNull($haus['image_id']);
        $this->assertNotEquals($cover['image_id'], $haus['image_id']);
    }
}
