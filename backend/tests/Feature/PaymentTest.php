<?php

use App\Enums\Role;
use App\Jobs\DeliverFakeWebhook;
use App\Models\Invoice;
use App\Models\LedgerTransaction;
use App\Models\Payment;
use App\Payments\Providers\FakeProvider;
use Illuminate\Support\Facades\Queue;

it('pays an open invoice and settles it through the ledger', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization, 1999);

    $payment = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.attempt_number', 1)
        ->assertJsonPath('data.amount.amount', 1999)
        ->assertJsonPath('data.invoice.status', 'paid')
        ->assertJsonPath('data.invoice.amount_due.amount', 0)
        ->json('data');

    expect($payment['provider_payment_id'])->toStartWith('fpay_');

    $accounts = collect($this->getJson('/api/v1/ledger/accounts')->json('data'))->keyBy('type');
    expect($accounts['cash']['balance']['amount'])->toBe(1999)
        ->and($accounts['receivable']['balance']['amount'])->toBe(0)
        ->and($accounts['revenue']['balance']['amount'])->toBe(1999);

    $transactions = $this->getJson("/api/v1/ledger/transactions?reference_id={$payment['id']}")->assertOk()->json('data');
    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]['type'])->toBe('payment')
        ->and(collect($transactions[0]['entries'])->sum('debit.amount'))->toBe(1999)
        ->and(collect($transactions[0]['entries'])->sum('credit.amount'))->toBe(1999);
});

it('records failed attempts and allows another attempt', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);

    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_fail'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failure_code', 'card_declined')
        ->assertJsonPath('data.invoice.status', 'open');

    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_error'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failure_code', 'provider_error')
        ->assertJsonPath('data.attempt_number', 2);

    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])
        ->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempt_number', 3);

    $this->getJson("/api/v1/payments?invoice_id={$invoice}")->assertOk()->assertJsonCount(3, 'data');
    expect(LedgerTransaction::query()->where('type', 'payment')->count())->toBe(1);
});

it('refuses to pay anything but an open invoice with a balance', function () {
    $organization = organization();
    actingIn($organization);
    $price = price($organization);
    $draft = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');

    $this->postJson('/api/v1/payments', ['invoice_id' => $draft, 'payment_method' => 'tok_ok'])
        ->assertUnprocessable()->assertJsonValidationErrors('invoice');

    $paid = openInvoice($organization);
    $this->postJson('/api/v1/payments', ['invoice_id' => $paid, 'payment_method' => 'tok_ok'])->assertCreated();
    $this->postJson('/api/v1/payments', ['invoice_id' => $paid, 'payment_method' => 'tok_ok'])
        ->assertUnprocessable()->assertJsonValidationErrors('invoice');

    $foreign = Invoice::factory()->create(['status' => 'open', 'number' => 'INV-000001']);
    $this->postJson('/api/v1/payments', ['invoice_id' => $foreign->id, 'payment_method' => 'tok_ok'])
        ->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
});

it('allows only one payment in flight per invoice', function () {
    Queue::fake([DeliverFakeWebhook::class]);
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);

    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_async'])
        ->assertCreated()->assertJsonPath('data.status', 'processing');

    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertStatus(409);

    // тот же инвариант держит частичный уникальный индекс, минуя сервис
    $first = Payment::query()->firstOrFail();
    dbFails(fn () => Payment::factory()->create([
        'invoice_id' => $invoice, 'attempt_number' => 2, 'status' => 'pending', 'provider_payment_id' => 'fpay_other',
    ]), 'payments_one_in_flight');
    expect($first->fresh()->status->value)->toBe('processing');
});

it('completes an async payment when the provider webhook arrives, and ignores duplicates', function () {
    Queue::fake([DeliverFakeWebhook::class]);
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization, 4200);

    $payment = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_async'])
        ->assertCreated()->assertJsonPath('data.status', 'processing')->json('data');

    // провайдер поставил доставку вебхука в очередь
    Queue::assertPushed(DeliverFakeWebhook::class, fn ($job) => $job->organizationId === $organization->id && $job->type === 'payment.succeeded');

    $body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'account' => $organization->id, 'data' => ['payment' => ['id' => $payment['provider_payment_id'], 'status' => 'succeeded']]]);
    $header = FakeProvider::signatureHeader($organization->webhook_secret, $body);

    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_FAKE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(202)->assertJsonPath('duplicate', false);

    $this->getJson("/api/v1/payments/{$payment['id']}")->assertJsonPath('data.status', 'succeeded');
    $this->getJson("/api/v1/invoices/{$invoice}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.amount_paid.amount', 4200);

    // повтор того же события - 200 и никаких изменений
    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_FAKE_SIGNATURE' => FakeProvider::signatureHeader($organization->webhook_secret, $body), 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()->assertJsonPath('duplicate', true);
    // и даже событие с другим id о том же платеже не задваивает деньги
    $body2 = json_encode(['id' => 'evt_2', 'type' => 'payment.succeeded', 'account' => $organization->id, 'data' => ['payment' => ['id' => $payment['provider_payment_id']]]]);
    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_FAKE_SIGNATURE' => FakeProvider::signatureHeader($organization->webhook_secret, $body2), 'CONTENT_TYPE' => 'application/json'], $body2)
        ->assertStatus(202);

    expect(LedgerTransaction::query()->where('type', 'payment')->count())->toBe(1)
        ->and(Invoice::find($invoice)->amount_paid)->toBe(4200);
    $this->getJson('/api/v1/webhooks/events')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'processed');
});

it('handles requires_action through the confirm endpoint', function () {
    Queue::fake([DeliverFakeWebhook::class]);
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);

    $payment = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_action'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.next_action.type', 'confirm')
        ->json('data');

    $this->postJson("/api/v1/providers/fake/payments/{$payment['provider_payment_id']}/confirm")->assertOk();
    $this->postJson("/api/v1/providers/fake/payments/{$payment['provider_payment_id']}/confirm")->assertStatus(409);
    $this->postJson('/api/v1/providers/fake/payments/fpay_nope/confirm')->assertNotFound();

    Queue::assertPushed(DeliverFakeWebhook::class, fn ($job) => $job->type === 'payment.succeeded' && $job->data['payment']['id'] === $payment['provider_payment_id']);
});

it('cancels a stuck payment and lets the invoice be paid again', function () {
    Queue::fake([DeliverFakeWebhook::class]);
    $organization = organization();
    actingIn($organization, Role::Developer);
    $invoice = openInvoice($organization);

    $stuck = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_action'])->json('data.id');
    $this->postJson("/api/v1/payments/{$stuck}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
    $this->postJson("/api/v1/payments/{$stuck}/cancel")->assertUnprocessable();

    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])
        ->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempt_number', 2);
});
