<?php

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Payment;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;

function account(int $organizationId, LedgerAccountType $type, string $currency = 'EUR'): LedgerAccount
{
    return LedgerAccount::query()->firstOrCreate(
        ['organization_id' => $organizationId, 'type' => $type->value, 'currency' => $currency],
        ['name' => $type->label()],
    );
}

function transaction(int $organizationId, string $referenceId = 'ref'): LedgerTransaction
{
    return LedgerTransaction::create([
        'organization_id' => $organizationId, 'type' => 'adjustment', 'currency' => 'EUR', 'amount' => 100,
        'description' => 'test', 'reference_type' => 'test', 'reference_id' => str_pad($referenceId, 26, '0'), 'posted_at' => now(),
    ]);
}

it('rejects an unbalanced transaction at the database level', function () {
    $organization = organization();
    $cash = account($organization->id, LedgerAccountType::Cash);
    $revenue = account($organization->id, LedgerAccountType::Revenue);

    $transaction = transaction($organization->id, 'unbalanced');
    LedgerEntry::create(['transaction_id' => $transaction->id, 'account_id' => $cash->id, 'debit' => 100, 'credit' => 0]);
    LedgerEntry::create(['transaction_id' => $transaction->id, 'account_id' => $revenue->id, 'debit' => 0, 'credit' => 99]);

    dbFails(fn () => DB::statement('SET CONSTRAINTS ledger_entries_balanced IMMEDIATE'), 'is not balanced');
});

it('rejects one-sided and single-line transactions', function () {
    $organization = organization();
    $cash = account($organization->id, LedgerAccountType::Cash);

    $transaction = transaction($organization->id, 'oneside');
    dbFails(fn () => LedgerEntry::create(['transaction_id' => $transaction->id, 'account_id' => $cash->id, 'debit' => 100, 'credit' => 100]), 'ledger_entries_one_side');
});

it('is append-only: nothing in the ledger can be updated or deleted', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization, 700);
    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertCreated();

    $transaction = LedgerTransaction::query()->firstOrFail();
    $entry = LedgerEntry::query()->firstOrFail();

    dbFails(fn () => DB::table('ledger_transactions')->where('id', $transaction->id)->update(['amount' => 1]), 'append-only');
    dbFails(fn () => DB::table('ledger_transactions')->where('id', $transaction->id)->delete(), 'append-only');
    dbFails(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['debit' => 1]), 'append-only');
    dbFails(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete(), 'append-only');
});

it('posts every event once even if the service is called twice', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization, 900);
    $paymentId = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->json('data.id');

    $payment = Payment::findOrFail($paymentId);
    $ledger = app(LedgerService::class);
    expect($ledger->postPayment($payment))->toBeNull()
        ->and(LedgerTransaction::query()->where('reference_id', $paymentId)->count())->toBe(1);

    // sum(debit) == sum(credit) по всему леджеру организации
    $totals = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_accounts.organization_id', $organization->id)
        ->selectRaw('sum(debit) as debit, sum(credit) as credit')
        ->first();
    expect((int) $totals->debit)->toBe((int) $totals->credit)->toBe(1800);
});

it('shows balances per account and currency', function () {
    $organization = organization();
    actingIn($organization);
    $eur = openInvoice($organization, 1000);
    $this->postJson('/api/v1/payments', ['invoice_id' => $eur, 'payment_method' => 'tok_ok'])->assertCreated();

    $usdPrice = price($organization, ['unit_amount' => 500, 'currency' => 'USD']);
    $usd = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'currency' => 'USD', 'items' => [['price_id' => $usdPrice->id]]])->json('data.id');
    $this->postJson("/api/v1/invoices/{$usd}/finalize")->assertOk();

    $accounts = $this->getJson('/api/v1/ledger/accounts')->assertOk()->json('data');
    $find = fn (string $type, string $currency) => collect($accounts)->first(fn ($a) => $a['type'] === $type && $a['currency'] === $currency)['balance']['amount'];

    expect($find('cash', 'EUR'))->toBe(1000)
        ->and($find('receivable', 'EUR'))->toBe(0)
        ->and($find('revenue', 'EUR'))->toBe(1000)
        ->and($find('receivable', 'USD'))->toBe(500)
        ->and($find('revenue', 'USD'))->toBe(500);

    $this->getJson('/api/v1/ledger/transactions?type=invoice&currency=USD')->assertOk()->assertJsonCount(1, 'data');
    actingIn(organization());
    $this->getJson('/api/v1/ledger/accounts')->assertOk()->assertJsonCount(0, 'data');
});
