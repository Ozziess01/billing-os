<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'email'],
            'role' => ['required', Rule::in(Role::assignable())],
        ];
    }
}
