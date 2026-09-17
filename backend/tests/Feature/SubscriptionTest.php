<?php

use App\Billing\InvalidTransition;
use App\Enums\BillingInterval;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

it('creates an active subscription with items and a billing period', function () {
    CarbonImmutable::setTestNow('2026-01-31 10:00:00');
    $organization = organization();
    $customer = customer($organization);
    $product = product($organization);
    $seat = price($organization, ['unit_amount' => 500, 'nickname' => 'Seat'], $product);
    $base = price($organization, ['unit_amount' => 1999, 'nickname' => 'Base'], $product);
    actingIn($organization);

    $response = $this->postJson('/api/v1/subscriptions', [
        'customer_id' => $customer->id,
        'items' => [['price_id' => $base->id], ['price_id' => $seat->id, 'quantity' => 3]],
        'metadata' => ['source' => 'test'],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.period_amount.amount', 3499)
        ->assertJsonPath('data.period_amount.formatted', '34.99 EUR')
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.trial_ends_at', null);

    // 31 января + месяц = 28 февраля, без переполнения в март
    expect($response->json('data.current_period_start'))->toStartWith('2026-01-31T10:00:00')
        ->and($response->json('data.current_period_end'))->toStartWith('2026-02-28T10:00:00');

    CarbonImmutable::setTestNow();
});

it('starts a trial when trial days are given', function () {
    CarbonImmutable::setTestNow('2026-03-01 00:00:00');
    $organization = organization();
    $price = price($organization);
    actingIn($organization);

    $this->postJson('/api/v1/subscriptions', [
        'customer_id' => customer($organization)->id,
        'items' => [['price_id' => $price->id]],
        'trial_days' => 14,
    ])->assertCreated()
        ->assertJsonPath('data.status', 'trialing')
        ->assertJsonPath('data.trial_ends_at', '2026-03-15T00:00:00.000000Z')
        ->assertJsonPath('data.current_period_end', '2026-03-15T00:00:00.000000Z');

    CarbonImmutable::setTestNow();
});

it('rejects inconsistent items', function () {
    $organization = organization();
    $customer = customer($organization);
    $eur = price($organization, ['currency' => 'EUR']);
    $usd = price($organization, ['currency' => 'USD']);
    $yearly = price($organization, ['billing_interval' => BillingInterval::Year]);
    $inactive = price($organization, ['active' => false]);
    $foreign = price(organization());
    actingIn($organization);

    $attempt = fn (array $items) => $this->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors('items');

    $attempt([['price_id' => $eur->id], ['price_id' => $usd->id]]);
    $attempt([['price_id' => $eur->id], ['price_id' => $yearly->id]]);
    $attempt([['price_id' => $inactive->id]]);
    $attempt([['price_id' => $foreign->id]]);
    $attempt([['price_id' => $eur->id], ['price_id' => $eur->id]]);
    $attempt([]);

    $this->postJson('/api/v1/subscriptions', ['customer_id' => customer(organization())->id, 'items' => [['price_id' => $eur->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    expect(Subscription::count())->toBe(0);
});

it('cancels at period end, resumes, and cancels immediately', function () {
    $organization = organization();
    $price = price($organization);
    actingIn($organization, Role::Developer);

    $id = $this->postJson('/api/v1/subscriptions', [
        'customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]],
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/subscriptions/{$id}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.cancel_at_period_end', true);

    $this->postJson("/api/v1/subscriptions/{$id}/resume")
        ->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
    $this->postJson("/api/v1/subscriptions/{$id}/resume")->assertUnprocessable();

    $this->postJson("/api/v1/subscriptions/{$id}/cancel", ['at_period_end' => false])
        ->assertOk()->assertJsonPath('data.status', 'canceled')->assertJsonPath('data.cancel_at_period_end', false);
    expect(Subscription::find($id)->canceled_at)->not->toBeNull();

    // отменённая - терминальная
    $this->postJson("/api/v1/subscriptions/{$id}/cancel")->assertUnprocessable();
    $this->postJson("/api/v1/subscriptions/{$id}/resume")->assertUnprocessable();
});

it('never renews a canceled subscription by mistake', function () {
    $subscription = Subscription::factory()->canceled()->create();

    expect(fn () => $subscription->transition(SubscriptionStatus::Active))->toThrow(InvalidTransition::class);

    // обход через "устаревшую" модель тоже упирается в атомарный UPDATE ... WHERE status = :from
    $stale = Subscription::find($subscription->id);
    Subscription::whereKey($subscription->id)->update(['status' => SubscriptionStatus::Active->value]);
    Subscription::whereKey($subscription->id)->update(['status' => SubscriptionStatus::Canceled->value]);
    $stale->status = SubscriptionStatus::Active;
    expect(fn () => $stale->transition(SubscriptionStatus::PastDue))->toThrow(InvalidTransition::class)
        ->and(Subscription::find($subscription->id)->status)->toBe(SubscriptionStatus::Canceled);
});

it('lists and filters subscriptions within the organization only', function () {
    $organization = organization();
    $customer = customer($organization);
    Subscription::factory()->for($customer)->create();
    Subscription::factory()->for($customer)->canceled()->create();
    $foreign = Subscription::factory()->create();
    actingIn($organization, Role::Viewer);

    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/subscriptions?status=canceled')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/subscriptions?customer_id={$customer->id}")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/subscriptions/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/subscriptions/{$foreign->id}/cancel")->assertNotFound();

    // viewer видит, но не отменяет
    $mine = Subscription::forOrganization($organization)->first();
    $this->postJson("/api/v1/subscriptions/{$mine->id}/cancel")->assertForbidden();
});

it('shows a dashboard with mrr per currency', function () {
    $organization = organization();
    $customer = customer($organization);
    $monthly = price($organization, ['unit_amount' => 1000]);
    $yearly = price($organization, ['unit_amount' => 12000, 'billing_interval' => BillingInterval::Year]);
    $usd = price($organization, ['unit_amount' => 500, 'currency' => 'USD']);
    actingIn($organization);

    foreach ([[$monthly, 2], [$yearly, 1], [$usd, 1]] as [$price, $quantity]) {
        $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'items' => [['price_id' => $price->id, 'quantity' => $quantity]],
        ])->assertCreated();
    }
    Subscription::factory()->for($customer)->canceled()->create();

    $this->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.customers', 1)
        ->assertJsonPath('data.subscriptions.total', 4)
        ->assertJsonPath('data.subscriptions.by_status.active', 3)
        ->assertJsonPath('data.subscriptions.by_status.canceled', 1)
        ->assertJsonPath('data.mrr.0', ['amount' => 3000, 'currency' => 'EUR', 'formatted' => '30.00 EUR'])
        ->assertJsonPath('data.mrr.1.amount', 500)
        ->assertJsonCount(4, 'data.recent_subscriptions');
});
