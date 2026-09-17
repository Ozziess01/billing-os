<?php

use App\Models\IdempotencyKey;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;

it('returns the original response for a repeated request with the same key', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization, 1500);
    $headers = ['Idempotency-Key' => 'pay-once-7f6c'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])
        ->assertCreated()->assertHeaderMissing('Idempotent-Replayed')->json();

    $second = $this->withHeaders($headers)->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->json();

    expect($second)->toBe($first)
        ->and(Payment::count())->toBe(1);
});

it('rejects the same key with a different payload and keys in flight', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);

    $this->withHeaders(['Idempotency-Key' => 'k1'])->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertCreated();
    $this->withHeaders(['Idempotency-Key' => 'k1'])->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_fail'])
        ->assertStatus(409)->assertJsonFragment(['message' => 'Idempotency-Key уже использован для другого запроса.']);

    IdempotencyKey::create([
        'organization_id' => $organization->id, 'key' => 'k-running',
        'request_hash' => hash('sha256', 'POST api/v1/payments '.json_encode(['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])),
        'status' => 'processing', 'expires_at' => now()->addDay(),
    ]);
    $this->withHeaders(['Idempotency-Key' => 'k-running'])->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])
        ->assertStatus(409)->assertJsonFragment(['message' => 'Запрос с этим Idempotency-Key ещё выполняется.']);

    $this->withHeaders(['Idempotency-Key' => str_repeat('x', 121)])->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertStatus(400);
});

it('stores validation errors too and frees the key when the request crashes', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);

    $this->withHeaders(['Idempotency-Key' => 'bad'])->postJson('/api/v1/payments', ['invoice_id' => $invoice])
        ->assertUnprocessable();
    $this->withHeaders(['Idempotency-Key' => 'bad'])->postJson('/api/v1/payments', ['invoice_id' => $invoice])
        ->assertUnprocessable()->assertHeader('Idempotent-Replayed', 'true');

    expect(IdempotencyKey::query()->where('key', 'bad')->value('response_status'))->toBe(422);
});

it('scopes keys per organization and expires them', function () {
    $organization = organization();
    $other = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);
    $this->withHeaders(['Idempotency-Key' => 'shared'])->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertCreated();

    actingIn($other);
    $otherInvoice = openInvoice($other);
    $this->withHeaders(['Idempotency-Key' => 'shared'])->postJson('/api/v1/payments', ['invoice_id' => $otherInvoice, 'payment_method' => 'tok_ok'])
        ->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(Payment::count())->toBe(2);

    // истёкший ключ освобождается
    IdempotencyKey::query()->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    $this->artisan('idempotency:prune')->assertSuccessful();
    expect(IdempotencyKey::count())->toBe(0);
});

it('makes subscription and invoice creation idempotent as well', function () {
    $organization = organization();
    $customer = customer($organization);
    $price = price($organization);
    actingIn($organization);

    $payload = ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]]];
    $a = $this->withHeaders(['Idempotency-Key' => 'inv'])->postJson('/api/v1/invoices', $payload)->assertCreated()->json('data.id');
    $b = $this->withHeaders(['Idempotency-Key' => 'inv'])->postJson('/api/v1/invoices', $payload)->assertCreated()->json('data.id');

    expect($a)->toBe($b)->and(Invoice::count())->toBe(1);
});
