<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceItemRequest extends FormRequest
{
    public function rules(): array
    {
        return InvoiceRequest::itemRules();
    }
}
