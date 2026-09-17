<?php

namespace App\Payments\Providers;

use App\Jobs\DeliverFakeWebhook;
use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\Dto\RefundRequest;
use App\Payments\Dto\RefundResult;
use App\Payments\Dto\WebhookEvent;
use App\Payments\InvalidWebhook;
use App\Payments\PaymentProvider;
use App\Payments\ProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Детерминированный провайдер для разработки и E2E. Исход задаёт платёжный метод:
 *
 *   tok_ok            - успех сразу
 *   tok_ok_norefund   - успех, но возвраты отклоняются
 *   tok_fail          - отказ card_declined
 *   tok_insufficient  - отказ insufficient_funds
 *   tok_async         - pending, через несколько секунд провайдер сам присылает вебхук об успехе
 *   tok_action        - requires_action: платёж завершится после подтверждения (эндпоинт confirm) и вебхука
 *   tok_error         - провайдер «упал» (исключение, не отказ)
 *
 * Состояние «на стороне провайдера» лежит в кеше, чтобы retrievePayment и confirm видели одно и то же.
 */
class FakeProvider implements PaymentProvider
{
    public const NAME = 'fake';

    public const SIGNATURE_HEADER = 'Fake-Signature';

    public const TOLERANCE = 300;

    public function name(): string
    {
        return self::NAME;
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $id = 'fpay_'.Str::lower((string) Str::ulid());
        $token = $request->paymentMethod;

        $result = match ($token) {
            'tok_ok', 'tok_ok_norefund' => PaymentResult::succeeded($id),
            'tok_fail' => PaymentResult::failed($id, 'card_declined', 'Карта отклонена банком.'),
            'tok_insufficient' => PaymentResult::failed($id, 'insufficient_funds', 'Недостаточно средств.'),
            'tok_async' => PaymentResult::pending($id),
            'tok_action' => PaymentResult::requiresAction($id, ['type' => 'confirm', 'provider_payment_id' => $id]),
            'tok_error' => throw new ProviderException('Fake provider is unavailable.'),
            default => PaymentResult::failed($id, 'invalid_payment_method', "Неизвестный платёжный метод {$token}."),
        };

        $this->remember($id, [
            'status' => $result->status,
            'token' => $token,
            'organization_id' => $request->organizationId,
            'payment_id' => $request->paymentId,
            'amount' => $request->amount->amount,
            'currency' => $request->amount->currency,
            'failure_code' => $result->failureCode,
            'failure_message' => $result->failureMessage,
        ]);

        if ($token === 'tok_async') {
            DeliverFakeWebhook::dispatch($request->organizationId, 'payment.succeeded', ['payment' => ['id' => $id, 'status' => 'succeeded']])
                ->delay(now()->addSeconds((int) config('billing.providers.fake.async_delay', 3)));
            $this->remember($id, ['status' => PaymentResult::SUCCEEDED] + $this->state($id));
        }

        return $result;
    }

    public function retrievePayment(string $providerPaymentId): PaymentResult
    {
        $state = $this->state($providerPaymentId);

        if ($state === []) {
            throw new ProviderException("Fake provider knows nothing about {$providerPaymentId}.");
        }

        return new PaymentResult($state['status'], $providerPaymentId, $state['failure_code'] ?? null, $state['failure_message'] ?? null);
    }

    /** Клиент «подтвердил» платёж (аналог 3-D Secure): провайдер завершает его и шлёт вебхук. */
    public function completeAction(string $providerPaymentId, bool $success = true): void
    {
        $state = $this->state($providerPaymentId);

        if (($state['status'] ?? null) !== PaymentResult::REQUIRES_ACTION) {
            throw new ProviderException("Payment {$providerPaymentId} is not waiting for an action.");
        }

        $status = $success ? 'succeeded' : 'failed';
        $this->remember($providerPaymentId, [
            'status' => $status,
            'failure_code' => $success ? null : 'authentication_failed',
            'failure_message' => $success ? null : 'Подтверждение не пройдено.',
        ] + $state);

        DeliverFakeWebhook::dispatch($state['organization_id'], "payment.{$status}", ['payment' => [
            'id' => $providerPaymentId,
            'status' => $status,
            'failure_code' => $success ? null : 'authentication_failed',
            'failure_message' => $success ? null : 'Подтверждение не пройдено.',
        ]]);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $state = $this->state($request->providerPaymentId);
        $id = 'fref_'.Str::lower((string) Str::ulid());

        if (($state['token'] ?? null) === 'tok_ok_norefund') {
            return RefundResult::failed($id, 'Провайдер отклонил возврат по этому платежу.');
        }

        return RefundResult::succeeded($id);
    }

    public function verifyWebhook(string $rawBody, array $headers, string $secret): WebhookEvent
    {
        $header = $headers[strtolower(self::SIGNATURE_HEADER)] ?? $headers[self::SIGNATURE_HEADER] ?? '';
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = $parts['v1'] ?? '';

        if ($timestamp === 0 || $signature === '') {
            throw new InvalidWebhook('Signature header is malformed.');
        }
        if (abs(time() - $timestamp) > self::TOLERANCE) {
            throw new InvalidWebhook('Signature timestamp is outside the tolerance window.');
        }
        if (! hash_equals(self::sign($secret, $rawBody, $timestamp), $signature)) {
            throw new InvalidWebhook('Signature does not match.');
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || empty($payload['id']) || empty($payload['type'])) {
            throw new InvalidWebhook('Payload is not a webhook event.');
        }

        return new WebhookEvent((string) $payload['id'], (string) $payload['type'], (array) ($payload['data'] ?? []));
    }

    /** Подпись в стиле Stripe: HMAC-SHA256 от "timestamp.body", заголовок "t=...,v1=...". */
    public static function sign(string $secret, string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    public static function signatureHeader(string $secret, string $rawBody, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".self::sign($secret, $rawBody, $timestamp);
    }

    /** @return array<string, mixed> */
    private function state(string $providerPaymentId): array
    {
        return Cache::get("fake_provider:payment:{$providerPaymentId}", []);
    }

    /** @param  array<string, mixed>  $state */
    private function remember(string $providerPaymentId, array $state): void
    {
        Cache::put("fake_provider:payment:{$providerPaymentId}", $state, now()->addDays(7));
    }
}
