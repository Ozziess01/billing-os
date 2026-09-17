<?php

namespace App\Billing;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Сумма в минорных единицах валюты. Никаких float: 19.99 EUR - это Money(1999, 'EUR').
 * Объект неизменяемый, арифметика между разными валютами запрещена.
 */
final readonly class Money implements JsonSerializable
{
    public string $currency;

    public function __construct(public int $amount, string $currency)
    {
        $this->currency = Currency::normalize($currency);
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /** Доля в процентах (20 = 20%), округление half-up в минорных единицах. */
    public function percentage(int $percent): self
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException("Percent must be between 0 and 100, {$percent} given.");
        }

        return new self(intdiv($this->amount * $percent + 50, 100), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    /** Человеческое представление: 1999 EUR -> "19.99 EUR". Для UI и писем, не для расчётов. */
    public function format(): string
    {
        $exponent = Currency::exponent($this->currency);
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);

        if ($exponent === 0) {
            return "{$sign}{$abs} {$this->currency}";
        }

        $major = intdiv($abs, 10 ** $exponent);
        $minor = str_pad((string) ($abs % (10 ** $exponent)), $exponent, '0', STR_PAD_LEFT);

        return "{$sign}{$major}.{$minor} {$this->currency}";
    }

    /** @return array{amount:int, currency:string, formatted:string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatch($this->currency, $other->currency);
        }
    }
}
