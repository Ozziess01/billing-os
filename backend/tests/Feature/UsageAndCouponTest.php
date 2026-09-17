<?php

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;

it('bills metered usage in arrears with a single rounding and marks events as billed', function () {
    CarbonImmutable::setTestNow('2026-03-01 00:00:00');
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_ok']);
    $product = product($organization, ['name' => 'API']);
    $base = price($organization, ['unit_amount' => 1000], $product);
    $metered = Price::factory()->for($product)->metered('0.1')->create(['organization_id' => $organization->id, 'nickname' => 'Requests']);
    actingIn($organization);

    $subscription = $this->postJson('/api/v1/subscriptions', [
        'customer_id' => $customer->id,
        'items' => [['price_id' => $base->id], ['price_id' => $metered->id, 'quantity' => 500]],
    ])->assertCreated()->json('data');

    // metered позиция всегда с quantity 1, а первый инвойс - только за фиксированную часть
    $meteredItem = collect($subscription['items'])->firstWhere('price_id', $metered->id);
    expect($meteredItem['quantity'])->toBe(1)
        ->and($subscription['period_amount']['amount'])->toBe(1000)
        ->and(Invoice::query()->where('subscription_id', $subscription['id'])->value('total'))->toBe(1000);

    // 10 000 запросов по 0.1 цента = 10.00 EUR, отправлено тремя отчётами, один из них продублирован
    foreach ([[4000, 'k1'], [4000, 'k1'], [3333, 'k2'], [2667, null]] as [$quantity, $key]) {
        $this->postJson('/api/v1/usage', ['subscription_item_id' => $meteredItem['id'], 'quantity' => $quantity, 'idempotency_key' => $key])
            ->assertSuccessful();
    }
    expect(UsageEvent::count())->toBe(3);

    $this->getJson("/api/v1/subscriptions/{$subscription['id']}/usage")
        ->assertOk()
        ->assertJsonPath('data.items.0.units', 10000)
        ->assertJsonPath('data.items.0.estimated_amount', 1000);

    CarbonImmutable::setTestNow('2026-04-01 00:00:00');
    app(SubscriptionService::class)->renew(Subscription::find($subscription['id']));

    $renewal = Invoice::query()->where('subscription_id', $subscription['id'])->latest('created_at')->firstOrFail()->load('items');
    expect($renewal->total)->toBe(2000)
        ->and($renewal->items->firstWhere('price_id', $metered->id)->amount)->toBe(1000)
        ->and($renewal->items->firstWhere('price_id', $metered->id)->metadata['units'])->toBe(10000)
        ->and(UsageEvent::query()->whereNull('invoice_item_id')->count())->toBe(0)
        ->and($renewal->status->value)->toBe('paid');

    CarbonImmutable::setTestNow();
});

it('validates usage reports', function () {
    $organization = organization();
    $licensed = price($organization);
    actingIn($organization);
    $subscription = $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $licensed->id]]])->json('data');
    $item = $subscription['items'][0]['id'];

    $this->postJson('/api/v1/usage', ['subscription_item_id' => $item, 'quantity' => 5])->assertUnprocessable()->assertJsonValidationErrors('subscription_item_id');
    $this->postJson('/api/v1/usage', ['subscription_item_id' => $item, 'quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->postJson('/api/v1/usage', ['subscription_item_id' => str_repeat('0', 26), 'quantity' => 1])->assertUnprocessable();

    $foreign = organization();
    $foreignMetered = Price::factory()->for(product($foreign))->metered()->create(['organization_id' => $foreign->id]);
    $foreignSub = Subscription::factory()->for(customer($foreign))->create();
    $foreignItem = $foreignSub->items()->create(['price_id' => $foreignMetered->id, 'quantity' => 1]);
    $this->postJson('/api/v1/usage', ['subscription_item_id' => $foreignItem->id, 'quantity' => 1])->assertUnprocessable();
});

it('applies percent and fixed coupons with limits and expiry', function () {
    CarbonImmutable::setTestNow('2026-03-01 00:00:00');
    $organization = organization();
    $price = price($organization, ['unit_amount' => 1999]);
    actingIn($organization, Role::Admin);

    $this->postJson('/api/v1/coupons', ['code' => 'save20', 'name' => 'Save 20', 'type' => 'percent', 'percent_off' => 20, 'max_redemptions' => 2, 'redeem_by' => '2026-12-31'])
        ->assertCreated()->assertJsonPath('data.code', 'SAVE20')->assertJsonPath('data.valid', true);
    $this->postJson('/api/v1/coupons', ['code' => 'SAVE20', 'name' => 'dup', 'type' => 'percent', 'percent_off' => 5])->assertUnprocessable();
    $this->postJson('/api/v1/coupons', ['code' => 'FIVE', 'name' => 'Five euros once', 'type' => 'fixed', 'amount_off' => 500, 'currency' => 'EUR', 'duration' => 'once'])->assertCreated();
    $this->postJson('/api/v1/coupons', ['code' => 'BAD', 'name' => 'x', 'type' => 'fixed', 'amount_off' => 500])->assertUnprocessable()->assertJsonValidationErrors('currency');

    // 20% от 19.99 = 3.998 → 4.00 (half-up)
    $withPercent = $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'save20'])
        ->assertCreated()->assertJsonPath('data.coupon.code', 'SAVE20')->json('data');
    $invoice = Invoice::query()->where('subscription_id', $withPercent['id'])->firstOrFail();
    expect($invoice->discount)->toBe(400)->and($invoice->total)->toBe(1599)->and($invoice->coupon_id)->not->toBeNull();

    // once: скидка только на первый инвойс
    $withFixed = $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'FIVE'])->json('data');
    expect(Invoice::query()->where('subscription_id', $withFixed['id'])->value('total'))->toBe(1499);
    CarbonImmutable::setTestNow('2026-04-01 00:00:00');
    app(SubscriptionService::class)->renew(Subscription::find($withFixed['id']));
    expect(Invoice::query()->where('subscription_id', $withFixed['id'])->latest('created_at')->value('total'))->toBe(1999);

    // лимит: второе использование проходит, третье - нет
    $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'SAVE20'])->assertCreated();
    $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'SAVE20'])
        ->assertUnprocessable()->assertJsonValidationErrors('coupon');
    $this->getJson('/api/v1/coupons')->assertOk()->assertJsonPath('data.1.times_redeemed', 2)->assertJsonPath('data.1.valid', false);

    $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'NOPE'])
        ->assertUnprocessable()->assertJsonValidationErrors('coupon_code');

    CarbonImmutable::setTestNow();
});

it('restricts coupons to a customer and to admins', function () {
    $organization = organization();
    $vip = customer($organization);
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization, Role::Admin);
    $this->postJson('/api/v1/coupons', ['code' => 'VIP', 'name' => 'VIP only', 'type' => 'percent', 'percent_off' => 50, 'customer_id' => $vip->id])->assertCreated();

    $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'VIP'])
        ->assertUnprocessable()->assertJsonValidationErrors('coupon');
    $ok = $this->postJson('/api/v1/subscriptions', ['customer_id' => $vip->id, 'items' => [['price_id' => $price->id]], 'coupon_code' => 'VIP'])->assertCreated()->json('data');
    expect(Invoice::query()->where('subscription_id', $ok['id'])->value('total'))->toBe(500);

    // купон на существующую подписку - со следующего инвойса; второй купон не вешается
    $plain = $this->postJson('/api/v1/subscriptions', ['customer_id' => $vip->id, 'items' => [['price_id' => $price->id]]])->json('data');
    $this->postJson("/api/v1/subscriptions/{$plain['id']}/coupon", ['coupon_code' => 'VIP'])->assertOk()->assertJsonPath('data.coupon.code', 'VIP');
    $this->postJson("/api/v1/subscriptions/{$plain['id']}/coupon", ['coupon_code' => 'VIP'])->assertUnprocessable();

    actingIn($organization, Role::Developer);
    $this->postJson('/api/v1/coupons', ['code' => 'DEV', 'name' => 'x', 'type' => 'percent', 'percent_off' => 1])->assertForbidden();
    $this->getJson('/api/v1/coupons')->assertOk();
});
