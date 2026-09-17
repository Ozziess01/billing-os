<?php

namespace App\Payments\Dto;

final readonly class RefundResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public function __construct(
        public string $status,
        public string $providerRefundId,
        public ?string $failureMessage = null,
    ) {}

    public static function succeeded(string $providerRefundId): self
    {
        return new self(self::SUCCEEDED, $providerRefundId);
    }

    public static function failed(string $providerRefundId, string $message): self
    {
        return new self(self::FAILED, $providerRefundId, $message);
    }

    public static function pending(string $providerRefundId): self
    {
        return new self(self::PENDING, $providerRefundId);
    }
}
