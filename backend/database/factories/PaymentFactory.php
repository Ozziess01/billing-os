<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'organization_id' => fn (array $attributes) => Invoice::query()->findOrFail($attributes['invoice_id'])->organization_id,
            'customer_id' => fn (array $attributes) => Invoice::query()->findOrFail($attributes['invoice_id'])->customer_id,
            'attempt_number' => 1,
            'status' => PaymentStatus::Succeeded,
            'currency' => 'EUR',
            'amount' => 1999,
            'provider' => 'fake',
            'provider_payment_id' => 'fpay_'.fake()->unique()->lexify('????????????'),
            'payment_method' => 'tok_ok',
            'succeeded_at' => now(),
        ];
    }
}
