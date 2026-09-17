<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Одно продление за раз на подписку; повтор безопасен - сервис сам проверяет, что период действительно истёк. */
class RenewSubscription implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $subscriptionId)
    {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return $this->subscriptionId;
    }

    public function handle(SubscriptionService $subscriptions): void
    {
        $subscription = Subscription::query()->find($this->subscriptionId);

        if ($subscription) {
            $subscriptions->renew($subscription);
        }
    }
}
