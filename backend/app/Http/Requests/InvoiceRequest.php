<?php

namespace App\Http\Requests;

use App\Billing\Currency;
use App\Rules\Metadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'size:26'],
            'currency' => ['sometimes', 'string', Rule::in(Currency::codes())],
            'description' => ['nullable', 'string', 'max:500'],
            'items' => ['sometimes', 'array', 'max:100'],
            ...self::itemRules('items.*.'),
            'metadata' => ['nullable', new Metadata],
        ];
    }

    /** @return array<string, list<string>> */
    public static function itemRules(string $prefix = ''): array
    {
        return [
            $prefix.'price_id' => ['nullable', 'string', 'size:26'],
            $prefix.'description' => ['nullable', 'string', 'max:300'],
            $prefix.'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            $prefix.'unit_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
