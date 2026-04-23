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

    public function test_four_images_produce_one_L4_impressionen_page(): void
    {
        $p = $this->mkProperty([[], [], [], [], ['is_title_image' => true]]);

        $config = (new ExposeConfigBuilder())->build($p);
        $impressionen = array_values(array_filter(
            $config['pages'],
            fn($p) => $p['type'] === 'impressionen'
        ));

        $this->assertCount(1, $impressionen);
        $this->assertEquals('L4', $impressionen[0]['layout']);
        $this->assertCount(4, $impressionen[0]['image_ids']);
    }

    public function test_seven_images_produce_two_impressionen_pages(): void
    {
        $specs = array_fill(0, 8, []); // index 0 wird Title
        $specs[0]['is_title_image'] = true;
        $p = $this->mkProperty($specs);

        $config = (new ExposeConfigBuilder())->build($p);
        $impressionen = array_values(array_filter(
            $config['pages'],
            fn($p) => $p['type'] === 'impressionen'
        ));

        // 7 Nicht-Cover-Bilder → L4 (4) + L3 (3)
        $this->assertCount(2, $impressionen);
        $this->assertEquals('L4', $impressionen[0]['layout']);
        $this->assertCount(4, $impressionen[0]['image_ids']);
        $this->assertEquals('L3', $impressionen[1]['layout']);
        $this->assertCount(3, $impressionen[1]['image_ids']);
    }
}
