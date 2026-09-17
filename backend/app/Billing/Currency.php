<?php

namespace App\Billing;

use InvalidArgumentException;

final class Currency
{
    public static function normalize(string $code): string
    {
        $code = strtoupper($code);

        if (! self::supported($code)) {
            throw new InvalidArgumentException("Unsupported currency {$code}.");
        }

        return $code;
    }

    public static function supported(string $code): bool
    {
        return array_key_exists(strtoupper($code), config('billing.currencies', []));
    }

    public static function exponent(string $code): int
    {
        return (int) config('billing.currencies.'.self::normalize($code));
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(config('billing.currencies', []));
    }
}
