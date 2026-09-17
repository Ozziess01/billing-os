<?php

namespace App\Payments\Dto;

/** Проверенное событие провайдера: подпись сошлась, полезная нагрузка разобрана. */
final readonly class WebhookEvent
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
    ) {}
}
