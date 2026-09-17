<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отчёты об использовании для metered-цен. Событие с idempotency_key можно слать сколько угодно раз -
 * запишется одно; повтор возвращает уже сохранённое событие.
 */
class UsageService
{
    /** @param  array<string, mixed>  $metadata */
    public function record(SubscriptionItem $item, int $quantity, ?CarbonImmutable $timestamp = null, ?string $idempotencyKey = null, array $metadata = []): UsageEvent
    {
        $subscription = Subscription::query()->findOrFail($item->subscription_id);
        $item->loadMissing('price');

        if (! $item->price->isMetered()) {
            throw ValidationException::withMessages(['subscription_item_id' => 'У этой позиции фиксированная цена, использование по ней не учитывается.']);
        }
        if (! $subscription->status->isLive()) {
            throw ValidationException::withMessages(['subscription_item_id' => 'Подписка отменена, использование больше не принимается.']);
        }

        $timestamp ??= CarbonImmutable::now();

        if ($timestamp->lt($subscription->current_period_start)) {
            throw ValidationException::withMessages(['timestamp' => 'Событие относится к уже выставленному периоду.']);
        }

        try {
            return DB::transaction(fn () => UsageEvent::create([
                'organization_id' => $subscription->organization_id,
                'subscription_item_id' => $item->id,
                'customer_id' => $subscription->customer_id,
                'quantity' => $quantity,
                'timestamp' => $timestamp,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata ?: null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return UsageEvent::query()
                ->where('organization_id', $subscription->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /**
     * Сводка по metered-позициям подписки за текущий период: единицы и оценка суммы.
     *
     * @return list<array{subscription_item_id: string, price_id: string, units: int, unbilled_units: int, estimated_amount: int}>
     */
    public function summary(Subscription $subscription): array
    {
        $subscription->loadMissing('items.price');
        $rows = [];

        foreach ($subscription->items as $item) {
            if (! $item->price->isMetered()) {
                continue;
            }

            $period = UsageEvent::query()
                ->where('subscription_item_id', $item->id)
                ->where('timestamp', '>=', $subscription->current_period_start)
                ->where('timestamp', '<', $subscription->current_period_end);

            $units = (int) (clone $period)->sum('quantity');
            $unbilled = (int) (clone $period)->whereNull('invoice_item_id')->sum('quantity');

            $rows[] = [
                'subscription_item_id' => $item->id,
                'price_id' => $item->price_id,
                'units' => $units,
                'unbilled_units' => $unbilled,
                'estimated_amount' => $item->price->usageAmount($unbilled),
            ];
        }

        return $rows;
    }
}
