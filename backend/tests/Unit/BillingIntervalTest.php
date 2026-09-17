<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

it('advances periods without month overflow', function (string $start, string $interval, int $count, string $expected) {
    $end = BillingInterval::from($interval)->advance(CarbonImmutable::parse($start), $count);

    expect($end->toDateString())->toBe($expected);
})->with([
    'monthly from 31 jan' => ['2026-01-31', 'month', 1, '2026-02-28'],
    'monthly in leap year' => ['2028-01-31', 'month', 1, '2028-02-29'],
    'quarterly' => ['2026-11-30', 'month', 3, '2027-02-28'],
    'yearly from 29 feb' => ['2028-02-29', 'year', 1, '2029-02-28'],
    'weekly' => ['2026-01-01', 'week', 2, '2026-01-15'],
    'daily' => ['2026-12-31', 'day', 1, '2027-01-01'],
]);

it('normalizes intervals to a month for mrr', function () {
    expect(BillingInterval::Month->perMonth())->toBe(1.0)
        ->and(round(BillingInterval::Year->perMonth() * 12000))->toBe(1000.0)
        ->and(round(BillingInterval::Week->perMonth() * 1000))->toBe(4333.0);
});

it('never lets a canceled subscription come back', function () {
    expect(SubscriptionStatus::Canceled->transitions())->toBe([])
        ->and(SubscriptionStatus::Canceled->canTransitionTo(SubscriptionStatus::Active))->toBeFalse()
        ->and(SubscriptionStatus::Active->canTransitionTo(SubscriptionStatus::Canceled))->toBeTrue()
        ->and(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Incomplete))->toBeFalse();
});
