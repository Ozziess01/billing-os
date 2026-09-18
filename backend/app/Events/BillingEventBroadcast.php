<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Сигнал фронтенду в приватный канал организации: что изменилось и какого ресурса это касается.
 * Данных не несёт - клиент перезапрашивает нужный запрос сам.
 */
class BillingEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $type,
        public readonly array $payload,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("organization.{$this->organizationId}")];
    }

    public function broadcastAs(): string
    {
        return 'billing.event';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['type' => $this->type, ...$this->payload];
    }
}
