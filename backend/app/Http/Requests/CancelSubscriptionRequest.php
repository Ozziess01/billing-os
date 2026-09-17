<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'at_period_end' => ['sometimes', 'boolean'],
        ];
    }
}
