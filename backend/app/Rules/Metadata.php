<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Произвольные ключ-значение интегратора: до 50 ключей, плоские скалярные значения. */
class Metadata implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Поле :attribute должно быть объектом.');

            return;
        }

        if (count($value) > 50) {
            $fail('Поле :attribute не может содержать больше 50 ключей.');

            return;
        }

        foreach ($value as $key => $item) {
            if (! is_string($key) || $key === '' || strlen($key) > 40) {
                $fail('Ключи :attribute - строки до 40 символов.');

                return;
            }
            if (! is_scalar($item) && $item !== null) {
                $fail("Значение {$key} в :attribute должно быть строкой, числом или булевым.");

                return;
            }
            if (is_string($item) && strlen($item) > 500) {
                $fail("Значение {$key} в :attribute длиннее 500 символов.");

                return;
            }
        }
    }
}
