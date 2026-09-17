<?php

namespace App\Payments;

use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\Dto\RefundRequest;
use App\Payments\Dto\RefundResult;
use App\Payments\Dto\WebhookEvent;

/**
 * Граница между биллингом и платёжным провайдером. Провайдер не трогает состояние
 * приложения: он только отвечает на запросы и присылает подписанные события.
 */
interface PaymentProvider
{
    public function name(): string;

    /** @throws ProviderException если провайдер недоступен */
    public function createPayment(PaymentRequest $request): PaymentResult;

    /** @throws ProviderException */
    public function retrievePayment(string $providerPaymentId): PaymentResult;

    /** @throws ProviderException */
    public function refund(RefundRequest $request): RefundResult;

    /**
     * @param  array<string, string>  $headers
     *
     * @throws InvalidWebhook
     */
    public function verifyWebhook(string $rawBody, array $headers, string $secret): WebhookEvent;
}
