<?php

namespace App\Listeners;

use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentFailed;
use App\Models\Invoice;
use App\Services\CollectionService;

/**
 * Связывает платежи с состоянием подписки: оплаченный инвойс возвращает подписку в active,
 * неудачное автосписание планирует следующую попытку (или отменяет подписку, когда попытки кончились).
 * Синхронно, внутри той же транзакции, что и переход платежа; методы handle* находит автодискавери.
 */
class ApplyPaymentOutcomeToSubscription
{
    public function __construct(private readonly CollectionService $collection) {}

    public function handleInvoicePaid(InvoicePaid $event): void
    {
        $this->collection->markCollected($event->invoice);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        if (! $event->payment->automatic) {
            return;
        }

        $invoice = Invoice::query()->find($event->payment->invoice_id);

        if ($invoice) {
            $this->collection->scheduleRetry($invoice);
        }
    }
}
