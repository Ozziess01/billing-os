<?php

namespace App\Listeners;

use App\Events\Billing\InvoiceFinalized;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoiceVoided;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\PaymentSucceeded;
use App\Events\Billing\RefundSucceeded;
use App\Events\Billing\SubscriptionCanceled;
use App\Events\Billing\SubscriptionCreated;
use App\Events\Billing\SubscriptionRenewed;
use App\Events\BillingEventBroadcast;

/** Каждое доменное событие уходит в realtime-канал организации; синхронно, без данных, только ссылки. */
class BroadcastBillingEvent
{
    public function handleInvoiceFinalized(InvoiceFinalized $e): void
    {
        $this->send($e->invoice->organization_id, 'invoice.finalized', ['invoice_id' => $e->invoice->id, 'subscription_id' => $e->invoice->subscription_id]);
    }

    public function handleInvoicePaid(InvoicePaid $e): void
    {
        $this->send($e->invoice->organization_id, 'invoice.paid', ['invoice_id' => $e->invoice->id, 'subscription_id' => $e->invoice->subscription_id]);
    }

    public function handleInvoiceVoided(InvoiceVoided $e): void
    {
        $this->send($e->invoice->organization_id, 'invoice.voided', ['invoice_id' => $e->invoice->id]);
    }

    public function handlePaymentSucceeded(PaymentSucceeded $e): void
    {
        $this->send($e->payment->organization_id, 'payment.succeeded', ['payment_id' => $e->payment->id, 'invoice_id' => $e->payment->invoice_id]);
    }

    public function handlePaymentFailed(PaymentFailed $e): void
    {
        $this->send($e->payment->organization_id, 'payment.failed', ['payment_id' => $e->payment->id, 'invoice_id' => $e->payment->invoice_id]);
    }

    public function handleRefundSucceeded(RefundSucceeded $e): void
    {
        $this->send($e->refund->organization_id, 'refund.succeeded', ['payment_id' => $e->refund->payment_id]);
    }

    public function handleSubscriptionCreated(SubscriptionCreated $e): void
    {
        $this->send($e->subscription->organization_id, 'subscription.created', ['subscription_id' => $e->subscription->id]);
    }

    public function handleSubscriptionRenewed(SubscriptionRenewed $e): void
    {
        $this->send($e->subscription->organization_id, 'subscription.renewed', ['subscription_id' => $e->subscription->id]);
    }

    public function handleSubscriptionCanceled(SubscriptionCanceled $e): void
    {
        $this->send($e->subscription->organization_id, 'subscription.canceled', ['subscription_id' => $e->subscription->id]);
    }

    /** @param  array<string, mixed>  $payload */
    private function send(int $organizationId, string $type, array $payload): void
    {
        BillingEventBroadcast::dispatch($organizationId, $type, $payload);
    }
}
