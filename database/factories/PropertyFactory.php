<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        // customers table has a FK constraint — ensure a customer exists.
        $customerId = DB::table('customers')->insertGetId([
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'customer_id' => $customerId,
            'ref_id' => 'TST-' . $this->faker->unique()->randomNumber(6),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'zip' => $this->faker->postcode(),
            'price' => $this->faker->numberBetween(200000, 900000),
        ];
    }
}
