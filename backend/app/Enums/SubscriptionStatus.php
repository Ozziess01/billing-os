<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::PastDue, self::Canceled],
            self::Active => [self::PastDue, self::Canceled],
            self::PastDue => [self::Active, self::Canceled],
            self::Incomplete => [self::Active, self::Canceled],
            // отменённая подписка - терминальное состояние, продлить её нельзя ни вручную, ни джобой
            self::Canceled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** Подписка ещё обслуживается: с неё выставляются инвойсы и её можно отменить. */
    public function isLive(): bool
    {
        return $this !== self::Canceled;
    }
}
