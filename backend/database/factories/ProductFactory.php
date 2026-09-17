<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->randomElement(['Starter', 'Pro', 'Team', 'Enterprise']).' plan',
            'description' => fake()->sentence(),
            'active' => true,
            'metadata' => null,
        ];
    }
}
