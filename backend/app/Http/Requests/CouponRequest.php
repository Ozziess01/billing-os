<?php

namespace App\Http\Requests;

use App\Billing\Currency;
use App\Enums\CouponDuration;
use App\Enums\CouponType;
use App\Rules\Metadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethod('POST')) {
            return [
                'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
                'name' => ['required', 'string', 'max:120'],
                'type' => ['required', Rule::enum(CouponType::class)],
                'percent_off' => ['required_if:type,percent', 'nullable', 'integer', 'min:1', 'max:100'],
                'amount_off' => ['required_if:type,fixed', 'nullable', 'integer', 'min:1'],
                'currency' => ['required_if:type,fixed', 'nullable', 'string', Rule::in(Currency::codes())],
                'duration' => ['sometimes', Rule::enum(CouponDuration::class)],
                'redeem_by' => ['nullable', 'date', 'after:now'],
                'max_redemptions' => ['nullable', 'integer', 'min:1'],
                'customer_id' => ['nullable', 'string', 'size:26'],
                'metadata' => ['nullable', new Metadata],
            ];
        }

        // условия купона после выдачи не меняются - только имя и выключатель
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', new Metadata],
        ];
    }
}
