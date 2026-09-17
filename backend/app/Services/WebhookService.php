<?php

namespace App\Services;

use App\Enums\RefundStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Payments\Dto\PaymentResult;
use App\Payments\Dto\RefundResult;
use App\Payments\InvalidWebhook;
use App\Payments\ProviderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Приём вебхука: проверить подпись, сохранить событие, отбросить дубликат по event_id,
 * поставить обработку в очередь. Сама обработка - отдельная транзакция, безопасная к повтору.
 */
class WebhookService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly PaymentService $payments,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return array{event: WebhookEvent, duplicate: bool}
     *
     * @throws InvalidWebhook
     */
    public function receive(string $providerName, string $rawBody, array $headers): array
    {
        $provider = $this->providers->get($providerName);
        $payload = json_decode($rawBody, true);
        $organization = is_array($payload) && isset($payload['account'])
            ? Organization::query()->find((int) $payload['account'])
            : null;

        if (! $organization || ! $organization->webhook_secret) {
            throw new InvalidWebhook('Unknown account.');
        }

        $verified = $provider->verifyWebhook($rawBody, $headers, (string) $organization->webhook_secret);

        try {
            // в своей транзакции: при дубликате откатывается только savepoint, а не всё вокруг
            $event = DB::transaction(fn () => WebhookEvent::create([
                'organization_id' => $organization->id,
                'provider' => $provider->name(),
                'event_id' => $verified->id,
                'type' => $verified->type,
                'payload' => $payload,
                'status' => WebhookEventStatus::Received,
            ]));
        } catch (UniqueConstraintViolationException) {
            // провайдер повторил доставку: событие уже у нас, второй раз не обрабатываем
            $event = WebhookEvent::query()->where('provider', $provider->name())->where('event_id', $verified->id)->firstOrFail();

            return ['event' => $event, 'duplicate' => true];
        }

        ProcessWebhookEvent::dispatch($event->id);

        return ['event' => $event, 'duplicate' => false];
    }

    /** Обработка одного события. Повторный вызов для уже обработанного события - no-op. */
    public function process(WebhookEvent $event): void
    {
        $claimed = WebhookEvent::query()
            ->whereKey($event->id)
            ->whereIn('status', [WebhookEventStatus::Received->value, WebhookEventStatus::Failed->value])
            ->update(['status' => WebhookEventStatus::Processing->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $event->refresh();

        try {
            $handled = $this->handle($event);
            $event->forceFill([
                'status' => $handled ? WebhookEventStatus::Processed : WebhookEventStatus::Ignored,
                'processed_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $event->forceFill(['status' => WebhookEventStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }
    }

    private function handle(WebhookEvent $event): bool
    {
        $data = $event->payload['data'] ?? [];

        return match ($event->type) {
            'payment.succeeded', 'payment.failed' => $this->handlePayment($event, $data['payment'] ?? []),
            'refund.succeeded', 'refund.failed' => $this->handleRefund($event, $data['refund'] ?? []),
            default => false,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function handlePayment(WebhookEvent $event, array $data): bool
    {
        $payment = Payment::query()
            ->forOrganization($event->organization_id)
            ->where('provider', $event->provider)
            ->where('provider_payment_id', $data['id'] ?? '')
            ->first();

        if (! $payment) {
            return false;
        }

        $result = $event->type === 'payment.succeeded'
            ? PaymentResult::succeeded($payment->provider_payment_id)
            : PaymentResult::failed($payment->provider_payment_id, (string) ($data['failure_code'] ?? 'payment_failed'), (string) ($data['failure_message'] ?? 'Платёж отклонён.'));

        $this->payments->applyResult($payment, $result);

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function handleRefund(WebhookEvent $event, array $data): bool
    {
        $refund = Refund::query()
            ->forOrganization($event->organization_id)
            ->where('provider_refund_id', $data['id'] ?? '')
            ->where('status', RefundStatus::Pending->value)
            ->first();

        if (! $refund) {
            return false;
        }

        $this->refunds->applyResult($refund, $event->type === 'refund.succeeded'
            ? RefundResult::succeeded($refund->provider_refund_id)
            : RefundResult::failed($refund->provider_refund_id, (string) ($data['failure_message'] ?? 'Возврат отклонён.')));

        return true;
    }
}
