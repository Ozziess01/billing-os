<?php

namespace App\Http\Requests;

use App\Rules\Metadata;
use Illuminate\Foundation\Http\FormRequest;

class SubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'size:26'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.price_id' => ['required', 'string', 'size:26'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'starts_at' => ['sometimes', 'date'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', new Metadata],
        ];
    }
}
