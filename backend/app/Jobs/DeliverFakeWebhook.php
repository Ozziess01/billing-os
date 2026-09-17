<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Payments\Providers\FakeProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * «Сторона провайдера»: собирает событие, подписывает секретом организации и стучится
 * в наш же вебхук по HTTP - тем же путём, что и настоящий провайдер. Повторяет доставку
 * с нарастающей паузой, поэтому получатель обязан быть идемпотентным.
 */
class DeliverFakeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public string $eventId;

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $type,
        public readonly array $data,
    ) {
        // id события фиксируется при создании: повторная доставка несёт тот же id
        $this->eventId = 'evt_'.Str::lower((string) Str::ulid());
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        $organization = Organization::query()->findOrFail($this->organizationId);

        $body = json_encode([
            'id' => $this->eventId,
            'type' => $this->type,
            'created' => time(),
            'account' => $organization->id,
            'data' => $this->data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = Http::timeout(10)
            ->withHeaders([
                FakeProvider::SIGNATURE_HEADER => FakeProvider::signatureHeader((string) $organization->webhook_secret, $body),
                'Content-Type' => 'application/json',
            ])
            ->withBody($body, 'application/json')
            ->post((string) config('billing.providers.fake.webhook_url'));

        if (! $response->successful()) {
            throw new RuntimeException("Webhook endpoint answered {$response->status()}.");
        }
    }
}
