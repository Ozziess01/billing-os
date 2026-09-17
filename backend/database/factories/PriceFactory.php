<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\UsageType;
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

    public function metered(string $unitAmountDecimal = '0.1'): static
    {
        return $this->state(['usage_type' => UsageType::Metered, 'unit_amount' => 0, 'unit_amount_decimal' => $unitAmountDecimal]);
    }

    public function yearly(): static
    {
        return $this->state(['billing_interval' => BillingInterval::Year, 'unit_amount' => 19900]);
    }
}
