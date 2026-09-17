<?php

namespace App\Http\Requests;

use App\Rules\Metadata;
use Illuminate\Foundation\Http\FormRequest;

class UsageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'subscription_item_id' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'timestamp' => ['sometimes', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', new Metadata],
        ];
    }
}
