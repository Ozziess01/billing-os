<?php

namespace App\Http\Resources;

use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookEvent */
class WebhookEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'event_id' => $this->event_id,
            'type' => $this->type,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'payload' => $this->payload,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
        ];
    }
}
