<?php

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

it('creates a draft with items, finalizes it with a sequential number and posts to the ledger', function () {
    $organization = organization();
    $customer = customer($organization);
    $price = price($organization, ['unit_amount' => 1999, 'nickname' => 'Pro monthly'], product($organization, ['name' => 'Pro plan']));
    actingIn($organization);

    $invoice = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customer->id,
        'items' => [
            ['price_id' => $price->id, 'quantity' => 2],
            ['description' => 'Onboarding', 'unit_amount' => 5000],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.total.amount', 8998)
        ->assertJsonPath('data.amount_due.amount', 8998)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.description', 'Pro plan (Pro monthly)')
        ->json('data');

    $this->postJson("/api/v1/invoices/{$invoice['id']}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.number', 'INV-000001')
        ->assertJsonPath('data.total.formatted', '89.98 EUR');

    $second = $this->postJson('/api/v1/invoices', ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    $this->postJson("/api/v1/invoices/{$second}/finalize")->assertOk()->assertJsonPath('data.number', 'INV-000002');

    // финализация проводит дебиторку против выручки
    $transaction = LedgerTransaction::query()->where('reference_id', $invoice['id'])->where('type', 'invoice')->firstOrFail();
    $entries = LedgerEntry::query()->where('transaction_id', $transaction->id)->with('account')->get();
    expect($entries->sum('debit'))->toBe(8998)
        ->and($entries->sum('credit'))->toBe(8998)
        ->and($entries->firstWhere('debit', 8998)->account->type->value)->toBe('receivable')
        ->and($entries->firstWhere('credit', 8998)->account->type->value)->toBe('revenue');
});

it('freezes a finalized invoice at the database level', function () {
    $organization = organization();
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization);

    $id = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    $this->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();

    // API: позиции больше не меняются
    $this->postJson("/api/v1/invoices/{$id}/items", ['description' => 'Late', 'unit_amount' => 1])->assertUnprocessable();

    // база: ни суммы, ни позиции, ни откат в draft
    dbFails(fn () => DB::table('invoices')->where('id', $id)->update(['total' => 1, 'subtotal' => 1, 'amount_due' => 1]), 'immutable');
    dbFails(fn () => DB::table('invoice_items')->where('invoice_id', $id)->update(['amount' => 1, 'unit_amount' => 1]), 'items are immutable');
    dbFails(fn () => DB::table('invoice_items')->where('invoice_id', $id)->delete(), 'items are immutable');
    dbFails(fn () => DB::table('invoices')->where('id', $id)->update(['status' => 'draft']), 'cannot go from open to draft');
    dbFails(fn () => DB::table('invoices')->where('id', $id)->delete(), 'cannot be deleted');
});

it('never lets a paid invoice go back to open', function () {
    $organization = organization();
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization);

    $id = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    $this->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();
    $this->postJson('/api/v1/payments', ['invoice_id' => $id, 'payment_method' => 'tok_ok'])->assertCreated();
    $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.status', 'paid');

    $this->postJson("/api/v1/invoices/{$id}/void")->assertStatus(409);
    $this->postJson("/api/v1/invoices/{$id}/uncollectible")->assertStatus(409);
    dbFails(fn () => DB::table('invoices')->where('id', $id)->update(['status' => 'open']), 'cannot go from paid to open');
    expect(Invoice::find($id)->status->value)->toBe('paid');
});

it('marks a zero invoice paid on finalization without a payment', function () {
    $organization = organization();
    actingIn($organization);

    $id = $this->postJson('/api/v1/invoices', [
        'customer_id' => customer($organization)->id,
        'items' => [['description' => 'Free tier', 'unit_amount' => 0]],
    ])->json('data.id');

    $this->postJson("/api/v1/invoices/{$id}/finalize")
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.amount_due.amount', 0);

    expect(LedgerTransaction::query()->where('reference_id', $id)->count())->toBe(0);
});

it('voids and writes off open invoices with a compensating ledger entry', function () {
    $organization = organization();
    $price = price($organization, ['unit_amount' => 2500]);
    actingIn($organization, Role::Admin);

    $void = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    $this->postJson("/api/v1/invoices/{$void}/finalize")->assertOk();
    $this->postJson("/api/v1/invoices/{$void}/void")->assertOk()->assertJsonPath('data.status', 'void');

    $bad = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    $this->postJson("/api/v1/invoices/{$bad}/finalize")->assertOk();
    $this->postJson("/api/v1/invoices/{$bad}/uncollectible")->assertOk()->assertJsonPath('data.status', 'uncollectible');

    // дебиторка обнулилась: +2500 при финализации, −2500 при списании, по каждому инвойсу
    $accounts = $this->getJson('/api/v1/ledger/accounts')->assertOk()->json('data');
    $byType = collect($accounts)->keyBy('type');
    expect($byType['receivable']['balance']['amount'])->toBe(0)
        ->and($byType['revenue']['balance']['amount'])->toBe(5000)
        ->and($byType['adjustments']['balance']['amount'])->toBe(5000);

    // черновик просто удаляется, аннулировать его нечем
    $draft = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    $this->deleteJson("/api/v1/invoices/{$draft}")->assertNoContent();
});

it('issues an invoice for the current subscription period exactly once', function () {
    $organization = organization();
    $customer = customer($organization);
    $price = price($organization, ['unit_amount' => 1200]);
    actingIn($organization);

    $subscription = $this->postJson('/api/v1/subscriptions', [
        'customer_id' => $customer->id, 'items' => [['price_id' => $price->id, 'quantity' => 3]],
    ])->assertCreated()->json('data.id');

    $first = $this->postJson("/api/v1/subscriptions/{$subscription}/invoice")
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.subscription_id', $subscription)
        ->assertJsonPath('data.total.amount', 3600)
        ->json('data');

    $again = $this->postJson("/api/v1/subscriptions/{$subscription}/invoice")->assertCreated()->json('data');
    expect($again['id'])->toBe($first['id'])
        ->and($again['items'][0]['period_start'])->toBe($first['period_start']);

    $this->getJson("/api/v1/invoices?subscription_id={$subscription}")->assertOk()->assertJsonCount(1, 'data');
    expect(Subscription::find($subscription)->invoices()->count())->toBe(1);
});

it('validates draft items and keeps invoices inside the organization', function () {
    $organization = organization();
    $usd = price($organization, ['currency' => 'USD']);
    $foreign = customer(organization());
    actingIn($organization);

    $this->postJson('/api/v1/invoices', ['customer_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'currency' => 'EUR', 'items' => [['price_id' => $usd->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['unit_amount' => 100]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items');

    $empty = $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/invoices/{$empty}/finalize")->assertUnprocessable();

    $foreignInvoice = Invoice::factory()->for($foreign)->create();
    $this->getJson("/api/v1/invoices/{$foreignInvoice->id}")->assertNotFound();
    $this->postJson("/api/v1/invoices/{$foreignInvoice->id}/finalize")->assertNotFound();

    // viewer только смотрит, developer не аннулирует
    actingIn($organization, Role::Viewer);
    $this->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id])->assertForbidden();
    actingIn($organization, Role::Developer);
    $this->postJson("/api/v1/invoices/{$empty}/void")->assertForbidden();
});
