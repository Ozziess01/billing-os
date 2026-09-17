<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Price;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    /**
     * @param  list<array{price_id: string, quantity?: int}>  $items
     * @param  array<string, mixed>  $metadata
     */
    public function create(Customer $customer, array $items, int $trialDays = 0, ?CarbonImmutable $startsAt = null, array $metadata = []): Subscription
    {
        $prices = $this->pricesFor($customer, $items);
        $first = $prices->first();
        $start = $startsAt ?? CarbonImmutable::now();

        $trialEndsAt = $trialDays > 0 ? $start->addDays($trialDays) : null;
        $periodEnd = $trialEndsAt ?? $first->billing_interval->advance($start, $first->interval_count);

        return DB::transaction(function () use ($customer, $items, $prices, $first, $start, $trialEndsAt, $periodEnd, $metadata) {
            $subscription = Subscription::create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'status' => $trialEndsAt ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                'currency' => $first->currency,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $start,
                'current_period_end' => $periodEnd,
                'metadata' => $metadata ?: null,
            ]);

            foreach ($items as $item) {
                $subscription->items()->create([
                    'price_id' => $prices[$item['price_id']]->id,
                    'quantity' => $item['quantity'] ?? 1,
                ]);
            }

            return $subscription->load('items.price.product', 'customer');
        });
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
    public function cancelNow(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->isCanceled()) {
                throw ValidationException::withMessages(['subscription' => 'Подписка уже отменена.']);
            }

            $subscription->transition(SubscriptionStatus::Canceled, [
                'canceled_at' => now(),
                'cancel_at_period_end' => false,
            ]);

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
