<?php

namespace App\Enums;

enum InvoiceStatus: string implements BillingStatus
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Void],
            self::Open => [self::Paid, self::Void, self::Uncollectible],
            // paid никогда не возвращается в open: возврат денег - это refund, а не откат инвойса
            self::Paid, self::Void, self::Uncollectible => [],
        };
    }

    public function canTransitionTo(BillingStatus $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }
}
