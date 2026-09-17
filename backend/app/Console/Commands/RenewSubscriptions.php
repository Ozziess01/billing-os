<?php

namespace App\Console\Commands;

use App\Jobs\RenewSubscription;
use App\Models\Subscription;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew';

    protected $description = 'Поставить в очередь продление подписок, у которых закончился период';

    public function handle(): int
    {
        $count = 0;

        Subscription::query()
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->where('current_period_end', '<=', now())
            ->orderBy('current_period_end')
            ->limit(500)
            ->pluck('id')
            ->each(function (string $id) use (&$count) {
                RenewSubscription::dispatch($id);
                $count++;
            });

        $this->info("В очередь на продление: {$count}");

        return self::SUCCESS;
    }
}
