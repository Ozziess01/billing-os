<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::now()->subDays(fake()->numberBetween(0, 20));

        return [
            'customer_id' => Customer::factory(),
            'organization_id' => fn (array $attributes) => Customer::query()->findOrFail($attributes['customer_id'])->organization_id,
            'status' => SubscriptionStatus::Active,
            'currency' => 'EUR',
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => $start->addMonthNoOverflow(),
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'metadata' => null,
        ];
    }

    public function canceled(): static
    {
        return $this->state(['status' => SubscriptionStatus::Canceled, 'canceled_at' => now()]);
    }
}
