<?php

namespace App\Http\Requests;

use App\Billing\Currency;
use App\Enums\BillingInterval;
use App\Rules\Metadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PriceRequest extends FormRequest
{
    public function rules(): array
    {
        // сумма, валюта и интервал у созданной цены не меняются: под неё уже могут быть подписки.
        // Нужна другая сумма - создаётся новая цена, старая деактивируется
        if ($this->isMethod('POST')) {
            return [
                'product_id' => ['required', 'string', 'size:26'],
                'nickname' => ['nullable', 'string', 'max:120'],
                'currency' => ['required', 'string', Rule::in(Currency::codes())],
                'unit_amount' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
                'billing_interval' => ['required', Rule::enum(BillingInterval::class)],
                'interval_count' => ['sometimes', 'integer', 'min:1', 'max:365'],
                'active' => ['sometimes', 'boolean'],
                'metadata' => ['nullable', new Metadata],
            ];
        }

        return [
            'nickname' => ['nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', new Metadata],
            'currency' => ['prohibited'],
            'unit_amount' => ['prohibited'],
            'billing_interval' => ['prohibited'],
            'interval_count' => ['prohibited'],
            'product_id' => ['prohibited'],
        ];
    }
}
