<?php

namespace App\Enums;

enum PaymentStatus: string implements BillingStatus
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Succeeded, self::Failed, self::Canceled],
            self::Processing => [self::Succeeded, self::Failed, self::Canceled],
            self::Succeeded, self::Failed, self::Canceled => [],
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

    public function inFlight(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
