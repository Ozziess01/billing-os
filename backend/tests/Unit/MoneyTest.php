<?php

use App\Billing\CurrencyMismatch;
use App\Billing\Money;

it('keeps amounts as integers in minor units', function () {
    $price = Money::of(1999, 'eur');

    expect($price->amount)->toBe(1999)
        ->and($price->currency)->toBe('EUR')
        ->and($price->format())->toBe('19.99 EUR')
        ->and(Money::of(1000, 'JPY')->format())->toBe('1000 JPY')
        ->and(Money::of(12345, 'KWD')->format())->toBe('12.345 KWD')
        ->and(Money::of(-501, 'USD')->format())->toBe('-5.01 USD');
});

it('adds, subtracts and multiplies without floats', function () {
    $a = Money::of(1999, 'EUR');

    expect($a->add(Money::of(1, 'EUR'))->amount)->toBe(2000)
        ->and($a->subtract(Money::of(2000, 'EUR'))->amount)->toBe(-1)
        ->and($a->multiply(3)->amount)->toBe(5997)
        ->and(Money::zero('EUR')->isZero())->toBeTrue();
});

it('rounds percentages half up in minor units', function () {
    expect(Money::of(1999, 'EUR')->percentage(20)->amount)->toBe(400)   // 399.8
        ->and(Money::of(1005, 'EUR')->percentage(50)->amount)->toBe(503)   // 502.5
        ->and(Money::of(1, 'EUR')->percentage(10)->amount)->toBe(0)        // 0.1
        ->and(Money::of(1999, 'EUR')->percentage(100)->amount)->toBe(1999);
});

it('refuses to mix currencies', function () {
    Money::of(100, 'EUR')->add(Money::of(100, 'USD'));
})->throws(CurrencyMismatch::class);

it('rejects unknown currencies', function () {
    Money::of(100, 'XXX');
})->throws(InvalidArgumentException::class);

it('serializes for the api', function () {
    expect(json_decode(json_encode(Money::of(2500, 'usd')), true))
        ->toBe(['amount' => 2500, 'currency' => 'USD', 'formatted' => '25.00 USD']);
});
