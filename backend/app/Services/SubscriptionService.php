<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Events\Billing\SubscriptionCanceled;
use App\Events\Billing\SubscriptionCreated;
use App\Events\Billing\SubscriptionRenewed;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly CollectionService $collection,
        private readonly CouponService $coupons,
    ) {}

    /**
     * Новая подписка. Без триала сразу выставляется инвойс за первый период и, если у клиента
     * есть платёжный метод, списывается: успех - active, отказ - incomplete до следующей попытки.
     *
     * @param  list<array{price_id: string, quantity?: int}>  $items
     * @param  array<string, mixed>  $metadata
     */
    public function create(Customer $customer, array $items, int $trialDays = 0, ?CarbonImmutable $startsAt = null, array $metadata = [], ?Coupon $coupon = null): Subscription
    {
        $prices = $this->pricesFor($customer, $items);
        $first = $prices->first();
        $start = $startsAt ?? CarbonImmutable::now();

        $trialEndsAt = $trialDays > 0 ? $start->addDays($trialDays) : null;
        $periodEnd = $trialEndsAt ?? $first->billing_interval->advance($start, $first->interval_count);

        if ($coupon) {
            $this->coupons->assertRedeemable($coupon, $customer->id, $first->currency);
        }

        [$subscription, $invoice] = DB::transaction(function () use ($customer, $items, $prices, $first, $start, $trialEndsAt, $periodEnd, $metadata, $coupon) {
            $subscription = Subscription::create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'status' => $trialEndsAt ? SubscriptionStatus::Trialing : SubscriptionStatus::Incomplete,
                'currency' => $first->currency,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $start,
                'current_period_end' => $periodEnd,
                'metadata' => $metadata ?: null,
            ]);

            foreach ($items as $item) {
                $subscription->items()->create([
                    'price_id' => $prices[$item['price_id']]->id,
                    'quantity' => $prices[$item['price_id']]->isMetered() ? 1 : ($item['quantity'] ?? 1),
                ]);
            }

            if ($coupon) {
                $this->coupons->redeem($coupon, $subscription);
            }

            $invoice = null;
            if (! $trialEndsAt) {
                $draft = $this->invoices->createForSubscriptionPeriod($subscription);
                $invoice = $draft ? $this->invoices->finalize($draft) : null;
                // нечем списать или нечего платить - подписка активна, инвойс ждёт оплаты
                if (! $invoice || $invoice->status->value === 'paid' || ! $customer->default_payment_method) {
                    $subscription->transition(SubscriptionStatus::Active);
                }
            }

            SubscriptionCreated::dispatch($subscription);

            return [$subscription, $invoice];
        });

        if ($invoice && $invoice->isOpen()) {
            $this->collection->collect($invoice);
        }

        return $subscription->fresh()->load('items.price.product', 'customer', 'coupon');
    }

    /** Купон на уже существующую подписку: скидка пойдёт со следующего инвойса. */
    public function applyCoupon(Subscription $subscription, Coupon $coupon): Subscription
    {
        $this->coupons->redeem($coupon, $subscription);

        return $subscription->fresh()->load('items.price.product', 'customer', 'coupon');
    }

    /**
     * Продление по окончании периода: следующий период, инвойс, автосписание.
     * Отменённая подписка не продлевается; с cancel_at_period_end - завершается здесь.
     */
    public function renew(Subscription $subscription): Subscription
    {
        $invoice = DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->lockForUpdate()->with('items.price')->findOrFail($subscription->id);

            if (! $subscription->status->isLive() || $subscription->current_period_end->isFuture()) {
                return null;
            }

            if ($subscription->cancel_at_period_end) {
                $subscription->transition(SubscriptionStatus::Canceled, [
                    'canceled_at' => now(),
                    'ended_at' => $subscription->current_period_end,
                    'cancel_reason' => 'period_end',
                    'cancel_at_period_end' => false,
                ]);
                SubscriptionCanceled::dispatch($subscription);

                return null;
            }

            $price = $subscription->items->first()->price;
            $usageFrom = $subscription->current_period_start;
            $start = $subscription->current_period_end;
            $end = $price->billing_interval->advance($start, $price->interval_count);

            // сдвиг периода только от того значения, что мы прочитали под lock: второй прогон job ничего не сдвинет
            $moved = Subscription::query()
                ->whereKey($subscription->id)
                ->where('current_period_end', $subscription->current_period_end)
                ->update(['current_period_start' => $start, 'current_period_end' => $end, 'updated_at' => now()]);

            if ($moved !== 1) {
                return null;
            }

            $subscription->forceFill(['current_period_start' => $start, 'current_period_end' => $end])->syncOriginal();

            // фиксированные позиции - за новый период, использование - за только что закончившийся
            $draft = $this->invoices->createForSubscriptionPeriod($subscription, $usageFrom, $start);
            $invoice = $draft ? $this->invoices->finalize($draft) : null;

            if (! $invoice || $invoice->status->value === 'paid') {
                $this->collection->markCollected($subscription, $invoice);
            }

            SubscriptionRenewed::dispatch($subscription);

            return $invoice;
        });

        if ($invoice && $invoice->isOpen()) {
            $this->collection->collect($invoice);
        }

        return $subscription->fresh();
    }

    /** Отмена в конце периода: подписка дорабатывает оплаченное, продления не будет. */
    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->isCanceled()) {
                throw ValidationException::withMessages(['subscription' => 'Подписка уже отменена.']);
            }

            $subscription->update(['cancel_at_period_end' => true]);

            return $subscription;
        });
    }

    /** Немедленная отмена: терминальный статус, дальше только новая подписка. */
    public function cancelNow(Subscription $subscription, string $reason = 'requested'): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->isCanceled()) {
                throw ValidationException::withMessages(['subscription' => 'Подписка уже отменена.']);
            }

            $subscription->transition(SubscriptionStatus::Canceled, [
                'canceled_at' => now(),
                'ended_at' => now(),
                'cancel_reason' => $reason,
                'cancel_at_period_end' => false,
            ]);

            // открытые автоинвойсы этой подписки больше не списываем
            Invoice::query()->where('subscription_id', $subscription->id)->where('status', 'open')
                ->update(['auto_collect' => false, 'next_payment_attempt_at' => null]);

            SubscriptionCanceled::dispatch($subscription);

            return $subscription;
        });
    }

    /** Передумали до конца периода - снимаем флаг, подписка продолжит продлеваться. */
    public function resume(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->isCanceled() || ! $subscription->cancel_at_period_end) {
                throw ValidationException::withMessages(['subscription' => 'Возобновить можно только подписку, ожидающую отмены в конце периода.']);
            }

            $subscription->update(['cancel_at_period_end' => false]);

            return $subscription;
        });
    }

    /**
     * Цены подписки: все из той же организации, активные, одной валюты и одного интервала.
     *
     * @param  list<array{price_id: string, quantity?: int}>  $items
     * @return Collection<string, Price>
     */
    private function pricesFor(Customer $customer, array $items): Collection
    {
        $ids = array_column($items, 'price_id');

        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'Нужна хотя бы одна позиция, цены не должны повторяться.']);
        }

        $prices = Price::query()
            ->forOrganization($customer->organization_id)
            ->where('active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($prices->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Одна из цен не найдена или неактивна.']);
        }

        $first = $prices->first();

        foreach ($prices as $price) {
            if ($price->currency !== $first->currency) {
                throw ValidationException::withMessages(['items' => 'Все позиции подписки должны быть в одной валюте.']);
            }
            if ($price->billing_interval !== $first->billing_interval || $price->interval_count !== $first->interval_count) {
                throw ValidationException::withMessages(['items' => 'Все позиции подписки должны иметь одинаковый интервал оплаты.']);
            }
        }

        return $prices;
    }
}
