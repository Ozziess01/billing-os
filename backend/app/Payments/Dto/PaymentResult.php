<?php

namespace App\Payments\Dto;

/**
 * Ответ провайдера на создание/чтение платежа. Терминальные исходы - succeeded и failed;
 * pending значит «ждём вебхук», requires_action - клиенту нужно что-то подтвердить.
 */
final readonly class PaymentResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const REQUIRES_ACTION = 'requires_action';

    /** @param  array<string, mixed>|null  $nextAction */
    public function __construct(
        public string $status,
        public string $providerPaymentId,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?array $nextAction = null,
    ) {}

    public static function succeeded(string $providerPaymentId): self
    {
        return new self(self::SUCCEEDED, $providerPaymentId);
    }

    public static function failed(string $providerPaymentId, string $code, string $message): self
    {
        return new self(self::FAILED, $providerPaymentId, $code, $message);
    }

    public static function pending(string $providerPaymentId): self
    {
        return new self(self::PENDING, $providerPaymentId);
    }

    /** @param  array<string, mixed>  $nextAction */
    public static function requiresAction(string $providerPaymentId, array $nextAction): self
    {
        return new self(self::REQUIRES_ACTION, $providerPaymentId, nextAction: $nextAction);
    }

    public function isFinal(): bool
    {
        return $this->status === self::SUCCEEDED || $this->status === self::FAILED;
    }
}
