<?php

namespace App\Payments\Dto;

use App\Billing\Money;

/** Что уходит провайдеру: наша ссылка на платёж, сумма и платёжный метод клиента. */
final readonly class PaymentRequest
{
    public function __construct(
        public string $paymentId,
        public Money $amount,
        public string $paymentMethod,
        public string $description,
        public int $organizationId,
    ) {}
}
