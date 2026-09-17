<?php

namespace App\Billing;

use DomainException;

class CurrencyMismatch extends DomainException
{
    public function __construct(public readonly string $expected, public readonly string $actual)
    {
        parent::__construct("Currency mismatch: expected {$expected}, got {$actual}.");
    }
}
