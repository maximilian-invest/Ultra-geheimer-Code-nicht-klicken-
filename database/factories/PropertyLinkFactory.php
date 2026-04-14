<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PropertyLinkFactory extends Factory
{
    protected $model = PropertyLink::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Erstanfrage',
            'token' => Str::random(43),
            'is_default' => false,
            'expires_at' => now()->addDays(30),
            'created_by' => User::factory(),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (array $attrs) => [
            'revoked_at' => now(),
            'revoked_by' => $attrs['created_by'] ?? User::factory(),
        ]);
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
