<?php

namespace App\Http\Requests;

use App\Billing\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'default_currency' => ['sometimes', 'string', Rule::in(Currency::codes())],
        ];
    }
}
