<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PropertyExposeVersionFactory extends Factory
{
    protected $model = PropertyExposeVersion::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Default Expose',
            'config_json' => ['pages' => []],
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
