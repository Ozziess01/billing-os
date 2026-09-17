<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'string', 'size:26'],
            'payment_method' => ['required', 'string', 'max:120'],
        ];
    }
}
