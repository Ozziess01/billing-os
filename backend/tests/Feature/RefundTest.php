<?php

use App\Enums\Role;
use App\Models\LedgerTransaction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

function paidInvoice(Organization $organization, int $amount = 5000): array
{
    $invoice = openInvoice($organization, $amount);
    $payment = test()->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertCreated()->json('data');

    return [$invoice, $payment['id']];
}

it('refunds partially and fully with compensating ledger entries, leaving the payment untouched', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);
    [, $paymentId] = paidInvoice($organization, 5000);

    $this->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 2000, 'reason' => 'Downgrade'])
        ->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.amount.amount', 2000);

    $this->getJson("/api/v1/payments/{$paymentId}")
        ->assertJsonPath('data.amount.amount', 5000)
        ->assertJsonPath('data.amount_refunded.amount', 2000)
        ->assertJsonPath('data.refundable_amount.amount', 3000)
        ->assertJsonPath('data.status', 'succeeded');

    // без суммы - остаток целиком
    $this->postJson("/api/v1/payments/{$paymentId}/refund")->assertCreated()->assertJsonPath('data.amount.amount', 3000);
    $this->getJson("/api/v1/payments/{$paymentId}")->assertJsonPath('data.refundable_amount.amount', 0);

    $accounts = collect($this->getJson('/api/v1/ledger/accounts')->json('data'))->keyBy('type');
    expect($accounts['cash']['balance']['amount'])->toBe(0)
        ->and($accounts['revenue']['balance']['amount'])->toBe(0)
        ->and(LedgerTransaction::query()->where('type', 'refund')->count())->toBe(2);

    // оригинальная проводка платежа не тронута
    expect(LedgerTransaction::query()->where('type', 'payment')->where('reference_id', $paymentId)->value('amount'))->toBe(5000);
});

it('never refunds more than was captured', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);
    [, $paymentId] = paidInvoice($organization, 1000);

    $this->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 1001])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 0])->assertUnprocessable();
    $this->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 600])->assertCreated();
    $this->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 500])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 400])->assertCreated();
    $this->postJson("/api/v1/payments/{$paymentId}/refund")->assertUnprocessable();

    // и база не даст обойти сервис
    dbFails(fn () => DB::table('payments')->where('id', $paymentId)->update(['amount_refunded' => 1001]), 'payments_refunded_within_amount');
});

it('is idempotent for repeated refund requests', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);
    [, $paymentId] = paidInvoice($organization, 3000);

    $first = $this->withHeaders(['Idempotency-Key' => 'refund-1'])->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 1000])->assertCreated()->json('data.id');
    $second = $this->withHeaders(['Idempotency-Key' => 'refund-1'])->postJson("/api/v1/payments/{$paymentId}/refund", ['amount' => 1000])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->json('data.id');

    expect($first)->toBe($second)
        ->and(Refund::count())->toBe(1)
        ->and(Payment::find($paymentId)->amount_refunded)->toBe(1000);
});

it('records provider refusals and keeps the money in place', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);
    $invoice = openInvoice($organization, 2000);
    $paymentId = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok_norefund'])->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/refund")
        ->assertCreated()->assertJsonPath('data.status', 'failed');

    $this->getJson("/api/v1/payments/{$paymentId}")->assertJsonPath('data.amount_refunded.amount', 0);
    expect(LedgerTransaction::query()->where('type', 'refund')->count())->toBe(0);

    // неуспешный платёж возвращать нечего
    $failed = openInvoice($organization);
    $failedPayment = $this->postJson('/api/v1/payments', ['invoice_id' => $failed, 'payment_method' => 'tok_fail'])->json('data.id');
    $this->postJson("/api/v1/payments/{$failedPayment}/refund")->assertUnprocessable();
});

it('lets only admins refund', function () {
    $organization = organization();
    actingIn($organization, Role::Developer);
    [, $paymentId] = paidInvoice($organization);

    $this->postJson("/api/v1/payments/{$paymentId}/refund")->assertForbidden();
    $this->getJson("/api/v1/refunds?payment_id={$paymentId}")->assertOk()->assertJsonCount(0, 'data');
});
