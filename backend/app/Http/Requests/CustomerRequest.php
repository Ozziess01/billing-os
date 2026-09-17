<?php

namespace App\Http\Requests;

use App\Rules\Metadata;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:254'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_payment_method' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', new Metadata],
        ];
    }
}
