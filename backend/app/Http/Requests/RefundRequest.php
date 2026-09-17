<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }
}
