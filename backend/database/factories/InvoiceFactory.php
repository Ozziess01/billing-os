<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'organization_id' => fn (array $attributes) => Customer::query()->findOrFail($attributes['customer_id'])->organization_id,
            'status' => InvoiceStatus::Draft,
            'currency' => 'EUR',
        ];
    }
}
