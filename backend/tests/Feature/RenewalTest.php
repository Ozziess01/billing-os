<?php

use App\Enums\SubscriptionStatus;
use App\Jobs\CollectInvoice;
use App\Jobs\RenewSubscription;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function subscribe(Organization $organization, Customer $customer, Price $price, array $extra = []): array
{
    return test()->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]], ...$extra])
        ->assertCreated()->json('data');
}

it('issues and collects the first invoice when the customer has a payment method', function () {
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_ok']);
    $price = price($organization, ['unit_amount' => 2500]);
    actingIn($organization);

    $subscription = subscribe($organization, $customer, $price);

    expect($subscription['status'])->toBe('active');
    $invoice = Invoice::query()->where('subscription_id', $subscription['id'])->firstOrFail();
    expect($invoice->status->value)->toBe('paid')
        ->and($invoice->auto_collect)->toBeTrue()
        ->and($invoice->collection_attempts)->toBe(1)
        ->and(Payment::query()->where('invoice_id', $invoice->id)->value('automatic'))->toBeTrue();
});

it('leaves the invoice open and the subscription active when there is nothing to charge with', function () {
    $organization = organization();
    $price = price($organization, ['unit_amount' => 2500]);
    actingIn($organization);

    $subscription = subscribe($organization, customer($organization), $price);

    expect($subscription['status'])->toBe('active');
    $invoice = Invoice::query()->where('subscription_id', $subscription['id'])->firstOrFail();
    expect($invoice->status->value)->toBe('open')->and($invoice->collection_attempts)->toBe(0);

    // выставить ещё один за тот же период нельзя - вернётся этот
    $this->postJson("/api/v1/subscriptions/{$subscription['id']}/invoice")->assertCreated()->assertJsonPath('data.id', $invoice->id);
});

it('marks a subscription incomplete when the first automatic charge fails and cancels it after the retry schedule', function () {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_fail']);
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization);

    $subscription = subscribe($organization, $customer, $price);
    expect($subscription['status'])->toBe('incomplete');

    $invoice = Invoice::query()->where('subscription_id', $subscription['id'])->firstOrFail();
    expect($invoice->collection_attempts)->toBe(1)
        ->and($invoice->next_payment_attempt_at->toDateTimeString())->toBe('2026-03-02 12:00:00');

    // планировщик ещё рано: попытка в будущем
    $this->artisan('invoices:collect')->assertSuccessful();
    expect($invoice->fresh()->collection_attempts)->toBe(1);

    foreach (['2026-03-02 12:00:01' => '2026-03-05 12:00:01', '2026-03-05 12:00:01' => '2026-03-12 12:00:01'] as $now => $next) {
        CarbonImmutable::setTestNow($now);
        $this->artisan('invoices:collect')->assertSuccessful();
        expect($invoice->fresh()->next_payment_attempt_at->toDateTimeString())->toBe($next);
    }

    // последняя попытка: расписание кончилось, подписка отменена, инвойс остаётся открытым
    CarbonImmutable::setTestNow('2026-03-12 12:00:01');
    $this->artisan('invoices:collect')->assertSuccessful();
    $invoice->refresh();
    expect($invoice->collection_attempts)->toBe(4)
        ->and($invoice->next_payment_attempt_at)->toBeNull()
        ->and($invoice->status->value)->toBe('open')
        ->and(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(4)
        ->and(Subscription::find($subscription['id'])->status)->toBe(SubscriptionStatus::Canceled)
        ->and(Subscription::find($subscription['id'])->cancel_reason)->toBe('payment_failed');

    CarbonImmutable::setTestNow();
});

it('recovers a past_due subscription when a retry succeeds', function () {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_ok']);
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization);

    $subscription = subscribe($organization, $customer, $price);
    $customer->update(['default_payment_method' => 'tok_fail']);

    // период кончился - продление создаёт инвойс, списание падает, подписка past_due
    CarbonImmutable::setTestNow('2026-04-01 12:00:00');
    $this->artisan('subscriptions:renew')->assertSuccessful();
    $model = Subscription::find($subscription['id']);
    expect($model->status)->toBe(SubscriptionStatus::PastDue)
        ->and($model->current_period_start->toDateString())->toBe('2026-04-01')
        ->and($model->current_period_end->toDateString())->toBe('2026-05-01')
        ->and(Invoice::query()->where('subscription_id', $model->id)->count())->toBe(2);

    // клиент поменял карту - следующая попытка проходит, подписка снова active
    $customer->update(['default_payment_method' => 'tok_ok']);
    CarbonImmutable::setTestNow('2026-04-02 12:00:01');
    $this->artisan('invoices:collect')->assertSuccessful();
    expect($model->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(Invoice::query()->where('subscription_id', $model->id)->where('status', 'open')->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

it('ends a trial with an invoice and does not renew twice for the same period', function () {
    CarbonImmutable::setTestNow('2026-03-01 00:00:00');
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_ok']);
    $price = price($organization, ['unit_amount' => 1500]);
    actingIn($organization);

    $subscription = subscribe($organization, $customer, $price, ['trial_days' => 14]);
    expect($subscription['status'])->toBe('trialing')
        ->and(Invoice::query()->where('subscription_id', $subscription['id'])->count())->toBe(0);

    CarbonImmutable::setTestNow('2026-03-15 00:00:00');
    $service = app(SubscriptionService::class);
    $service->renew(Subscription::find($subscription['id']));
    $service->renew(Subscription::find($subscription['id']));

    $model = Subscription::find($subscription['id']);
    expect($model->status)->toBe(SubscriptionStatus::Active)
        ->and($model->current_period_start->toDateString())->toBe('2026-03-15')
        ->and($model->current_period_end->toDateString())->toBe('2026-04-15')
        ->and(Invoice::query()->where('subscription_id', $model->id)->count())->toBe(1)
        ->and(Invoice::query()->where('subscription_id', $model->id)->first()->status->value)->toBe('paid');

    CarbonImmutable::setTestNow();
});

it('never renews a canceled subscription and finishes cancel_at_period_end at the boundary', function () {
    CarbonImmutable::setTestNow('2026-03-01 00:00:00');
    $organization = organization();
    $price = price($organization, ['unit_amount' => 1500]);
    actingIn($organization);

    $ending = subscribe($organization, customer($organization), $price);
    $canceled = subscribe($organization, customer($organization), $price);
    $this->postJson("/api/v1/subscriptions/{$ending['id']}/cancel")->assertOk();
    $this->postJson("/api/v1/subscriptions/{$canceled['id']}/cancel", ['at_period_end' => false])->assertOk();

    CarbonImmutable::setTestNow('2026-04-01 00:00:00');
    Queue::fake([CollectInvoice::class]);
    $this->artisan('subscriptions:renew')->assertSuccessful();
    Queue::assertNotPushed(RenewSubscription::class, fn ($job) => $job->subscriptionId === $canceled['id']);

    $endingModel = Subscription::find($ending['id']);
    expect($endingModel->status)->toBe(SubscriptionStatus::Canceled)
        ->and($endingModel->cancel_reason)->toBe('period_end')
        ->and($endingModel->ended_at->toDateString())->toBe('2026-04-01')
        ->and(Invoice::query()->where('subscription_id', $ending['id'])->count())->toBe(1)
        ->and(Subscription::find($canceled['id'])->current_period_end->toDateString())->toBe('2026-04-01');

    CarbonImmutable::setTestNow();
});

it('stops collecting open invoices once the subscription is canceled', function () {
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_fail']);
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization);

    $subscription = subscribe($organization, $customer, $price);
    $invoice = Invoice::query()->where('subscription_id', $subscription['id'])->firstOrFail();
    expect($invoice->next_payment_attempt_at)->not->toBeNull();

    $this->postJson("/api/v1/subscriptions/{$subscription['id']}/cancel", ['at_period_end' => false])->assertOk();
    $invoice->refresh();
    expect($invoice->auto_collect)->toBeFalse()->and($invoice->next_payment_attempt_at)->toBeNull();
});
