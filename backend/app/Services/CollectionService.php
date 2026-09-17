<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Events\Billing\SubscriptionCanceled;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Автосписание и dunning. Инвойс с auto_collect пробуем оплатить платёжным методом клиента:
 * первая попытка сразу, дальше по расписанию +1 / +3 / +7 дней. Каждая попытка сначала
 * фиксируется в базе (счётчик и «следующая попытка» сбрасывается), потом идёт к провайдеру -
 * поэтому повтор job не создаст второй платёж.
 */
class CollectionService
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Попытка списания; null - платить нечего или нечем (нет платёжного метода). */
    public function collect(Invoice $invoice): ?Payment
    {
        $claimed = DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpen() || $invoice->amount_due <= 0 || ! $invoice->auto_collect) {
                return null;
            }
            if ($invoice->payments()->whereIn('status', ['pending', 'processing'])->exists()) {
                return null;
            }

            $method = Customer::query()->whereKey($invoice->customer_id)->value('default_payment_method');

            if (! $method) {
                // нечем списать: ждём ручной оплаты, подписка помечается past_due по сроку
                $invoice->forceFill(['next_payment_attempt_at' => null])->save();

                return null;
            }

            $invoice->forceFill([
                'collection_attempts' => $invoice->collection_attempts + 1,
                'next_payment_attempt_at' => null,
            ])->save();

            return [$invoice, (string) $method];
        });

        if ($claimed === null) {
            return null;
        }

        [$invoice, $method] = $claimed;

        return $this->payments->pay($invoice, $method, automatic: true);
    }

    /**
     * После неудачной автоматической попытки: следующая по расписанию, подписка - past_due;
     * когда попытки кончились - подписка отменяется, инвойс остаётся открытым.
     */
    public function scheduleRetry(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpen() || ! $invoice->auto_collect) {
                return;
            }

            $schedule = (array) config('billing.dunning.retry_after_days', [1, 3, 7]);
            $retryIndex = $invoice->collection_attempts - 1; // первая попытка - не ретрай

            if (array_key_exists($retryIndex, $schedule)) {
                $invoice->forceFill(['next_payment_attempt_at' => now()->addDays((int) $schedule[$retryIndex])])->save();
                $this->markSubscription($invoice, SubscriptionStatus::PastDue);

                return;
            }

            $invoice->forceFill(['next_payment_attempt_at' => null])->save();

            if (config('billing.dunning.cancel_after_retries', true)) {
                $this->cancelSubscription($invoice);
            }
        });
    }

    /** Долга нет (инвойс оплачен или выставлять было нечего): подписка, если ждала денег, снова активна. */
    public function markCollected(Subscription|Invoice $subject, ?Invoice $invoice = null): void
    {
        $subscriptionId = $subject instanceof Subscription ? $subject->id : $subject->subscription_id;
        $invoice ??= $subject instanceof Invoice ? $subject : null;

        DB::transaction(function () use ($subscriptionId, $invoice) {
            if ($invoice) {
                Invoice::query()->whereKey($invoice->id)->update(['next_payment_attempt_at' => null]);
            }

            if (! $subscriptionId) {
                return;
            }

            $subscription = Subscription::query()->lockForUpdate()->find($subscriptionId);

            if ($subscription && in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Incomplete, SubscriptionStatus::Trialing], true)) {
                $subscription->transition(SubscriptionStatus::Active);
            }
        });
    }

    private function markSubscription(Invoice $invoice, SubscriptionStatus $to): void
    {
        if (! $invoice->subscription_id) {
            return;
        }

        $subscription = Subscription::query()->lockForUpdate()->find($invoice->subscription_id);

        if ($subscription && $subscription->status !== $to && $subscription->status->canTransitionTo($to)) {
            $subscription->transition($to);
        }
    }

    private function cancelSubscription(Invoice $invoice): void
    {
        if (! $invoice->subscription_id) {
            return;
        }

        $subscription = Subscription::query()->lockForUpdate()->find($invoice->subscription_id);

        if ($subscription && $subscription->status->canTransitionTo(SubscriptionStatus::Canceled)) {
            $subscription->transition(SubscriptionStatus::Canceled, [
                'canceled_at' => now(),
                'ended_at' => now(),
                'cancel_reason' => 'payment_failed',
                'cancel_at_period_end' => false,
            ]);
            SubscriptionCanceled::dispatch($subscription);
        }
    }
}
