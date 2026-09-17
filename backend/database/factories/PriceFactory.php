<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Price> */
class PriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'organization_id' => fn (array $attributes) => Product::query()->findOrFail($attributes['product_id'])->organization_id,
            'nickname' => null,
            'currency' => 'EUR',
            'unit_amount' => fake()->randomElement([999, 1999, 4900, 9900]),
            'billing_interval' => BillingInterval::Month,
            'interval_count' => 1,
            'active' => true,
            'metadata' => null,
        ];
    }

    public function yearly(): static
    {
        return $this->state(['billing_interval' => BillingInterval::Year, 'unit_amount' => 19900]);
    }
}
