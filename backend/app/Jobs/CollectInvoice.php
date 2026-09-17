<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\CollectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Плановая попытка автосписания; дубликат job не создаст вторую попытку - счётчик фиксируется до вызова провайдера. */
class CollectInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $invoiceId)
    {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return $this->invoiceId;
    }

    public function handle(CollectionService $collection): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice) {
            $collection->collect($invoice);
        }
    }
}
