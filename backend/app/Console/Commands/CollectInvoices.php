<?php

namespace App\Console\Commands;

use App\Jobs\CollectInvoice;
use App\Models\Invoice;
use Illuminate\Console\Command;

class CollectInvoices extends Command
{
    protected $signature = 'invoices:collect';

    protected $description = 'Поставить в очередь плановые попытки автосписания';

    public function handle(): int
    {
        $count = 0;

        Invoice::query()
            ->where('status', 'open')
            ->where('auto_collect', true)
            ->whereNotNull('next_payment_attempt_at')
            ->where('next_payment_attempt_at', '<=', now())
            ->limit(500)
            ->pluck('id')
            ->each(function (string $id) use (&$count) {
                CollectInvoice::dispatch($id);
                $count++;
            });

        $this->info("В очередь на списание: {$count}");

        return self::SUCCESS;
    }
}
