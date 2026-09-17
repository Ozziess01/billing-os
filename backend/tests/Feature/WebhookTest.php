<?php

use App\Enums\Role;
use App\Jobs\DeliverFakeWebhook;
use App\Models\WebhookEvent;
use App\Payments\Providers\FakeProvider;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function webhook(int $account, string $secret, array $overrides = [], ?string $header = null): TestResponse
{
    $body = json_encode(array_merge(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'account' => $account, 'data' => []], $overrides));

    return test()->call('POST', '/api/v1/webhooks/fake', [], [], [], [
        'HTTP_FAKE_SIGNATURE' => $header ?? FakeProvider::signatureHeader($secret, $body),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('accepts only correctly signed, fresh events for a known account', function () {
    $organization = organization();
    $secret = $organization->webhook_secret;

    webhook($organization->id, $secret)->assertStatus(202);
    webhook($organization->id, 'whsec_wrong')->assertStatus(400);
    webhook($organization->id, $secret, header: 'garbage')->assertStatus(400);
    webhook($organization->id, $secret, header: 't='.(time() - 3600).',v1=deadbeef')->assertStatus(400);
    webhook(999999, $secret)->assertStatus(400);

    // подпись верная, но timestamp старый - replay-атака не проходит
    $body = json_encode(['id' => 'evt_old', 'type' => 'payment.succeeded', 'account' => $organization->id, 'data' => []]);
    $stale = FakeProvider::signatureHeader($secret, $body, time() - 3600);
    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_FAKE_SIGNATURE' => $stale, 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);

    $this->postJson('/api/v1/webhooks/stripe', [])->assertNotFound();
    expect(WebhookEvent::count())->toBe(1);
});

it('stores unknown event types as ignored and unknown payments too', function () {
    $organization = organization();

    webhook($organization->id, $organization->webhook_secret, ['type' => 'customer.updated'])->assertStatus(202);
    webhook($organization->id, $organization->webhook_secret, ['type' => 'payment.failed', 'data' => ['payment' => ['id' => 'fpay_ghost']]])->assertStatus(202);

    expect(WebhookEvent::query()->pluck('status')->map->value->all())->toBe(['ignored', 'ignored']);
});

it('signs deliveries so that the endpoint verifies them end to end', function () {
    $organization = organization();
    Http::fake(['nginx/*' => Http::response(['received' => true], 202)]);

    (new DeliverFakeWebhook($organization->id, 'payment.succeeded', ['payment' => ['id' => 'fpay_x']]))->handle();

    Http::assertSent(function ($request) use ($organization) {
        $header = $request->header(FakeProvider::SIGNATURE_HEADER)[0] ?? '';
        $body = $request->body();

        // подпись из доставки принимается нашим же verifyWebhook
        $event = app(FakeProvider::class)->verifyWebhook($body, ['fake-signature' => $header], $organization->webhook_secret);

        return $event->type === 'payment.succeeded' && str_starts_with($event->id, 'evt_') && $request->url() === 'http://nginx/api/v1/webhooks/fake';
    });
});

it('retries delivery when the endpoint is down', function () {
    $organization = organization();
    Http::fake(['nginx/*' => Http::response('down', 503)]);

    expect(fn () => (new DeliverFakeWebhook($organization->id, 'payment.succeeded', []))->handle())
        ->toThrow(RuntimeException::class, '503');
});

it('lists webhook events for developers of the organization only', function () {
    $organization = organization();
    webhook($organization->id, $organization->webhook_secret)->assertStatus(202);
    $other = organization();
    webhook($other->id, $other->webhook_secret)->assertStatus(202);

    actingIn($organization, Role::Developer);
    $this->getJson('/api/v1/webhooks/events')->assertOk()->assertJsonCount(1, 'data');

    actingIn($organization, Role::Viewer);
    $this->getJson('/api/v1/webhooks/events')->assertForbidden();
});

it('keeps a failed processing attempt retryable', function () {
    $organization = organization();
    $event = WebhookEvent::create([
        'organization_id' => $organization->id, 'provider' => 'fake', 'event_id' => 'evt_retry', 'type' => 'payment.succeeded',
        'payload' => ['data' => ['payment' => ['id' => 'fpay_missing']]], 'status' => 'received',
    ]);

    $service = app(WebhookService::class);
    $service->process($event);
    expect($event->fresh()->status->value)->toBe('ignored')->and($event->fresh()->attempts)->toBe(1);

    // повторный запуск для обработанного события ничего не делает
    $service->process($event->fresh());
    expect($event->fresh()->attempts)->toBe(1);
});
