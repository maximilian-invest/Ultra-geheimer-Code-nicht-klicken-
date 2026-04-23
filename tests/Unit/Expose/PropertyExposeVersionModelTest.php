<?php

namespace Tests\Unit\Expose;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyExposeVersionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_json_is_cast_to_array(): void
    {
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => ['pages' => [['type' => 'cover']]],
        ]);

        $fresh = PropertyExposeVersion::find($version->id);
        $this->assertIsArray($fresh->config_json);
        $this->assertEquals('cover', $fresh->config_json['pages'][0]['type']);
    }

    public function test_belongs_to_property(): void
    {
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'config_json' => [],
        ]);

        $this->assertEquals($property->id, $version->property->id);
    }

    public function test_belongs_to_creator(): void
    {
        $user = \App\Models\User::factory()->create();
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'created_by'  => $user->id,
            'config_json' => [],
        ]);

        $this->assertEquals($user->id, $version->creator->id);
    }

    public function test_created_by_can_be_null(): void
    {
        $property = Property::factory()->create();
        $version = PropertyExposeVersion::create([
            'property_id' => $property->id,
            'created_by'  => null,
            'config_json' => [],
        ]);

        $fresh = PropertyExposeVersion::find($version->id);
        $this->assertNull($fresh->created_by);
    }
}
