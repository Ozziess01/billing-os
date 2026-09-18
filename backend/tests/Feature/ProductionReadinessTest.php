<?php

use App\Enums\Role;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('answers health checks at three levels', function () {
    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    $this->getJson('/health/live')->assertOk();
    $this->getJson('/health/ready')->assertOk()->assertJsonPath('checks.database.status', 'ok')->assertJsonPath('checks.redis.status', 'ok');

    Cache::put('heartbeat:scheduler', time(), 3600);
    $deep = $this->getJson('/health/deep');
    expect($deep->status())->toBeIn([200, 503])
        ->and($deep->json('checks.scheduler.status'))->toBe('ok')
        ->and($deep->json('checks.queue.status'))->toBe('ok')
        ->and($deep->json('checks.payments.status'))->toBe('ok');

    Cache::put('heartbeat:scheduler', time() - 3600, 3600);
    $this->getJson('/health/deep')->assertStatus(503)->assertJsonPath('checks.scheduler.status', 'fail');
});

it('serves prometheus metrics only with the token', function () {
    config(['observability.metrics_token' => '']);
    $this->get('/metrics')->assertNotFound();

    config(['observability.metrics_token' => 'prom-secret']);
    $this->get('/metrics')->assertUnauthorized();
    $this->get('/metrics?token=wrong')->assertUnauthorized();

    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization, 700);
    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice, 'payment_method' => 'tok_ok'])->assertCreated();

    $body = $this->withToken('prom-secret')->get('/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();
    expect($body)->toContain('billingos_invoices{status="paid"} 1')
        ->toContain('billingos_payments_24h{status="succeeded"} 1')
        ->toContain('billingos_subscriptions{status="active"}')
        ->toContain('billingos_http_request_duration_seconds_bucket{le="+Inf"}')
        ->toContain('billingos_queue_size{queue="billing"}')
        ->toContain('billingos_failed_jobs 0');
});

it('sets secure headers and hsts only over https', function () {
    $plain = $this->getJson('/health');
    $plain->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('keeps an append-only audit trail of who did what', function () {
    $organization = organization();
    $customer = customer($organization, ['default_payment_method' => 'tok_ok']);
    $price = price($organization, ['unit_amount' => 1200]);
    actingIn($organization, Role::Admin);

    $this->postJson('/api/v1/customers', ['name' => 'Audited'])->assertCreated();
    $subscription = $this->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]]])->json('data');
    $this->postJson("/api/v1/subscriptions/{$subscription['id']}/cancel", ['at_period_end' => false])->assertOk();
    $this->postJson('/api/v1/api-keys', ['name' => 'k'])->assertCreated();

    $actions = ActivityLog::query()->where('organization_id', $organization->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toContain('customer.created', 'subscription.created', 'invoice.finalized', 'payment.succeeded', 'subscription.canceled', 'api_key.created');

    $log = ActivityLog::query()->where('action', 'payment.succeeded')->firstOrFail();
    expect($log->actor_type)->toBe('user')
        ->and($log->metadata['amount'])->toBe(1200)
        ->and($log->metadata['automatic'])->toBeTrue()
        ->and($log->ip)->not->toBeNull();

    $this->getJson('/api/v1/activity?action=payment')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor.type', 'user');
    $this->getJson("/api/v1/activity?resource_type=subscription&resource_id={$subscription['id']}")->assertOk()->assertJsonCount(2, 'data');

    // журнал нельзя ни править, ни чистить мимо обслуживания
    dbFails(fn () => DB::table('activity_logs')->where('id', $log->id)->update(['action' => 'nope']), 'append-only');
    dbFails(fn () => DB::table('activity_logs')->where('id', $log->id)->delete(), 'append-only');
});

it('lets the maintenance command prune old activity but nobody else', function () {
    $organization = organization();
    actingIn($organization, Role::Admin);
    $this->postJson('/api/v1/customers', ['name' => 'Old'])->assertCreated();

    DB::transaction(function () {
        DB::statement("SET LOCAL billingos.audit_maintenance = '1'");
        DB::table('activity_logs')->update(['created_at' => now()->subDays(400)]);
    });

    $this->artisan('activity:prune')->assertSuccessful();
    expect(ActivityLog::count())->toBe(0);

    // developer журнал не видит; viewer тоже
    actingIn($organization, Role::Developer);
    $this->getJson('/api/v1/activity')->assertForbidden();
});

it('records api key and portal actors', function () {
    $organization = organization();
    $customer = customer($organization);
    actingIn($organization, Role::Admin);
    $plain = $this->postJson('/api/v1/api-keys', ['name' => 'integration'])->json('plain_key');
    $portalToken = $this->postJson("/api/v1/customers/{$customer->id}/portal-session")->json('data.token');

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($plain)->postJson('/api/v1/customers', ['name' => 'By key'])->assertCreated();
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->withToken($portalToken)->patchJson('/api/v1/portal/billing', ['email' => 'me@customer.test'])->assertOk();

    expect(ActivityLog::query()->where('action', 'customer.created')->where('actor_type', 'api_key')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'portal.billing_updated')->where('actor_type', 'customer')->where('actor_id', $customer->id)->exists())->toBeTrue();
});

it('rate limits payment requests per user', function () {
    $organization = organization();
    actingIn($organization);
    $invoice = openInvoice($organization);

    // 60 в минуту на POST /payments: 61-й запрос получает 429 независимо от исхода
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['invoice_id' => $invoice]);
    }
    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice])->assertStatus(429);
});
