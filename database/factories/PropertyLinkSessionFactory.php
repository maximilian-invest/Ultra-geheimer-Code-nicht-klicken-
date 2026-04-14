<?php

namespace Database\Factories;

use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class PropertyLinkSessionFactory extends Factory
{
    protected $model = PropertyLinkSession::class;

    public function definition(): array
    {
        return [
            'property_link_id' => PropertyLink::factory(),
            'email' => $this->faker->safeEmail(),
            'dsgvo_accepted_at' => now(),
            'ip_hash' => hash('sha256', '127.0.0.1' . 'test-salt'),
            'user_agent_hash' => hash('sha256', 'test-ua' . 'test-salt'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
        ];
    }
}
