<?php

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PortalSession;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

function portalToken(Organization $organization, Customer $customer): string
{
    actingIn($organization);
    $token = test()->postJson("/api/v1/customers/{$customer->id}/portal-session")->assertCreated()->json('data.token');

    // дальше ходим как клиент: без учётки пользователя и без организации в заголовке
    test()->flushHeaders();
    app('auth')->forgetGuards();
    test()->withToken($token);

    return $token;
}

it('opens a customer portal by a one-time link and shows only that customer', function () {
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_ok']);
    $other = customer($organization);
    $price = price($organization, ['unit_amount' => 2000]);
    actingIn($organization);
    $mine = $this->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]]])->json('data');
    $theirs = $this->postJson('/api/v1/subscriptions', ['customer_id' => $other->id, 'items' => [['price_id' => $price->id]]])->json('data');

    $url = $this->postJson("/api/v1/customers/{$customer->id}/portal-session")->assertCreated()->json('data.url');
    expect($url)->toStartWith('http://localhost:8090/portal/bps_')
        ->and(PortalSession::count())->toBe(1)
        ->and(PortalSession::first()->token_hash)->not->toContain('bps_');

    portalToken($organization, $customer);

    $this->getJson('/api/v1/portal/session')->assertOk()->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.organization.name', $organization->name);
    $this->getJson('/api/v1/portal/subscriptions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id']);
    $this->getJson('/api/v1/portal/invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'paid');
    $this->getJson('/api/v1/portal/payments')->assertOk()->assertJsonCount(1, 'data');

    $foreignInvoice = Invoice::query()->where('subscription_id', $theirs['id'])->firstOrFail();
    $this->getJson("/api/v1/portal/invoices/{$foreignInvoice->id}")->assertNotFound();
    $this->postJson("/api/v1/portal/subscriptions/{$theirs['id']}/cancel")->assertNotFound();

    // выгрузка инвойса
    $invoice = Invoice::query()->where('subscription_id', $mine['id'])->firstOrFail();
    $csv = $this->get("/api/v1/portal/invoices/{$invoice->id}/export?format=csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($csv->getContent())->toContain('description,quantity,unit_amount,amount,currency')->toContain('"Total",,,2000,EUR');
    $this->get("/api/v1/portal/invoices/{$invoice->id}/export")->assertOk()->assertJsonPath('data.number', $invoice->number);

    // реквизиты и отмена
    $this->patchJson('/api/v1/portal/billing', ['email' => 'new@customer.test', 'default_payment_method' => 'tok_fail'])
        ->assertOk()->assertJsonPath('data.email', 'new@customer.test')->assertJsonPath('data.default_payment_method', 'tok_fail');
    $this->postJson("/api/v1/portal/subscriptions/{$mine['id']}/cancel")->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
    $this->postJson("/api/v1/portal/subscriptions/{$mine['id']}/cancel", ['at_period_end' => false])->assertOk()->assertJsonPath('data.status', 'canceled')->assertJsonPath('data.cancel_reason', 'portal');

    // портальный токен не открывает основной API, а пользовательские маршруты - портал
    $this->getJson('/api/v1/customers')->assertUnauthorized();
});

it('rejects expired, unknown and missing portal tokens', function () {
    $organization = organization();
    $customer = customer($organization);
    $token = portalToken($organization, $customer);

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/portal/session')->assertUnauthorized();
    $this->withToken('bps_nope')->getJson('/api/v1/portal/session')->assertUnauthorized();

    PortalSession::query()->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    $this->withToken($token)->getJson('/api/v1/portal/session')->assertUnauthorized();
});

it('lets a customer pay an open invoice from the portal', function () {
    $organization = organization();
    $customer = customer($organization);
    $price = price($organization, ['unit_amount' => 900]);
    actingIn($organization);
    $subscription = $this->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]]])->json('data');
    $invoice = Invoice::query()->where('subscription_id', $subscription['id'])->firstOrFail();
    expect($invoice->status->value)->toBe('open');

    portalToken($organization, $customer);
    $this->postJson("/api/v1/portal/invoices/{$invoice->id}/pay", ['payment_method' => 'tok_ok'])
        ->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.invoice.status', 'paid');
});

it('authenticates integrations with hashed api keys acting as developer', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);

    $created = $this->postJson('/api/v1/api-keys', ['name' => 'CRM sync'])->assertCreated();
    $plain = $created->json('plain_key');
    expect($plain)->toStartWith('bos_live_')
        ->and($created->json('data.prefix'))->toBe(substr($plain, 0, 16))
        ->and(ApiKey::first()->key_hash)->toBe(hash('sha256', $plain))
        ->and($this->getJson('/api/v1/api-keys')->json('data.0'))->not->toHaveKey('plain_key');

    // ключом можно работать без X-Organization и без пользовательского токена
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($plain)->postJson('/api/v1/customers', ['name' => 'Via API key'])->assertCreated();
    $this->withToken($plain)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data');

    // но права - developer: возвраты и ключи недоступны
    $this->withToken($plain)->getJson('/api/v1/api-keys')->assertForbidden();
    $this->withToken($plain)->postJson('/api/v1/coupons', ['code' => 'X', 'name' => 'x', 'type' => 'percent', 'percent_off' => 1])->assertForbidden();
    expect(ApiKey::first()->last_used_at)->not->toBeNull();

    // чужая организация по ключу недоступна
    $foreign = customer(organization());
    $this->withToken($plain)->getJson("/api/v1/customers/{$foreign->id}")->assertNotFound();
});

it('revokes api keys and rejects expired or garbage keys', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);
    $plain = $this->postJson('/api/v1/api-keys', ['name' => 'temp'])->json('plain_key');
    $id = ApiKey::first()->id;

    $this->deleteJson("/api/v1/api-keys/{$id}")->assertNoContent();
    expect(ApiKey::find($id)->isActive())->toBeFalse();

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/customers')->assertUnauthorized();
    $this->withToken('bos_live_garbage')->getJson('/api/v1/customers')->assertUnauthorized();

    // developer не может выдавать ключи
    Sanctum::actingAs(member($organization, Role::Developer));
    $this->withHeader('X-Organization', (string) $organization->id)->postJson('/api/v1/api-keys', ['name' => 'x'])->assertForbidden();

    // истёкший ключ
    actingIn($organization, Role::Admin);
    $expiring = $this->postJson('/api/v1/api-keys', ['name' => 'short', 'expires_at' => now()->addMinute()->toIso8601String()])->json('plain_key');
    CarbonImmutable::setTestNow(now()->addHour());
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($expiring)->getJson('/api/v1/customers')->assertUnauthorized();
    CarbonImmutable::setTestNow();
});
