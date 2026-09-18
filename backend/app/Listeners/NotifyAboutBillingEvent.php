<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\Billing\InvoiceFinalized;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\RefundSucceeded;
use App\Events\Billing\SubscriptionCanceled;
use App\Events\Billing\SubscriptionCreated;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Notifications\BillingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Доменное событие → уведомление участникам организации от developer и выше.
 * В очереди и после commit: событие уже точно случилось.
 */
class NotifyAboutBillingEvent implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handleInvoiceFinalized(InvoiceFinalized $event): void
    {
        $invoice = $event->invoice;
        $this->notify($invoice->organization_id, 'invoice.created', "Инвойс {$invoice->number} выставлен",
            $this->customerName($invoice->customer_id).": {$invoice->totalMoney()->format()}, срок оплаты ".$invoice->due_at?->format('d.m.Y'),
            ['type' => 'invoice', 'id' => $invoice->id], "invoice.created:{$invoice->id}");
    }

    public function handleInvoicePaid(InvoicePaid $event): void
    {
        $invoice = $event->invoice;
        $this->notify($invoice->organization_id, 'invoice.paid', "Инвойс {$invoice->number} оплачен",
            $this->customerName($invoice->customer_id).": {$invoice->totalMoney()->format()}",
            ['type' => 'invoice', 'id' => $invoice->id], "invoice.paid:{$invoice->id}");
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $payment = $event->payment;
        $invoice = Invoice::query()->find($payment->invoice_id);
        $this->notify($payment->organization_id, 'payment.failed', 'Платёж не прошёл: '.($invoice ? $invoice->number : 'инвойс'),
            $this->customerName($payment->customer_id).": {$payment->amountMoney()->format()}, попытка #{$payment->attempt_number} - ".($payment->failure_message ?? $payment->failure_code),
            ['type' => 'payment', 'id' => $payment->id], "payment.failed:{$payment->id}");
    }

    public function handleSubscriptionCreated(SubscriptionCreated $event): void
    {
        $subscription = $event->subscription;
        $this->notify($subscription->organization_id, 'subscription.created', 'Новая подписка',
            $this->customerName($subscription->customer_id).': статус '.$subscription->status->value,
            ['type' => 'subscription', 'id' => $subscription->id], "subscription.created:{$subscription->id}");
    }

    public function handleSubscriptionCanceled(SubscriptionCanceled $event): void
    {
        $subscription = $event->subscription;
        $reason = match ($subscription->cancel_reason) {
            'payment_failed' => 'не удалось списать оплату',
            'period_end' => 'по окончании периода',
            'portal' => 'клиент отменил сам',
            default => 'по запросу',
        };
        $this->notify($subscription->organization_id, 'subscription.canceled', 'Подписка отменена',
            $this->customerName($subscription->customer_id).": {$reason}",
            ['type' => 'subscription', 'id' => $subscription->id], "subscription.canceled:{$subscription->id}");
    }

    public function handleRefundSucceeded(RefundSucceeded $event): void
    {
        $refund = $event->refund;
        $this->notify($refund->organization_id, 'refund.succeeded', 'Возврат выполнен',
            "{$refund->amountMoney()->format()} по платежу {$refund->payment_id}",
            ['type' => 'payment', 'id' => $refund->payment_id], "refund.succeeded:{$refund->id}");
    }

    /** @param  array{type: string, id: string}|null  $resource */
    private function notify(int $organizationId, string $event, string $title, string $body, ?array $resource, string $dedupeKey): void
    {
        $userIds = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->whereIn('role', [Role::Owner->value, Role::Admin->value, Role::Developer->value])
            ->pluck('user_id');

        $recipients = User::query()->whereIn('id', $userIds)->get();

        Notification::send($recipients, new BillingNotification($event, $organizationId, $title, $body, $resource, $dedupeKey));
    }

    private function customerName(string $customerId): string
    {
        return (string) (Customer::query()->whereKey($customerId)->value('name') ?? 'Клиент');
    }
}
