<?php

namespace App\Payments;

use App\Payments\Providers\FakeProvider;
use InvalidArgumentException;

/** Провайдеры по имени; сейчас один, но биллинг знает их только через интерфейс. */
class ProviderRegistry
{
    /** @var array<string, class-string<PaymentProvider>> */
    private array $providers = [
        FakeProvider::NAME => FakeProvider::class,
    ];

    public function get(?string $name = null): PaymentProvider
    {
        $name ??= (string) config('billing.default_provider');

        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException("Unknown payment provider {$name}.");
        }

        return app($this->providers[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }
}
