<?php

namespace App\Payments\Dto;

use App\Billing\Money;

final readonly class RefundRequest
{
    public function __construct(
        public string $refundId,
        public string $providerPaymentId,
        public Money $amount,
        public ?string $reason,
    ) {}
}
