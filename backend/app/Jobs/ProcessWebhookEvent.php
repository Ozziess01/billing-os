<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Одно событие - одна job; повторный запуск после сбоя безопасен, обработчик идемпотентен. */
class ProcessWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(WebhookService $webhooks): void
    {
        $event = WebhookEvent::query()->find($this->eventId);

        if ($event) {
            $webhooks->process($event);
        }
    }
}
