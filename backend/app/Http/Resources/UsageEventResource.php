<?php

namespace App\Http\Resources;

use App\Models\UsageEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UsageEvent */
class UsageEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_item_id' => $this->subscription_item_id,
            'customer_id' => $this->customer_id,
            'quantity' => $this->quantity,
            'timestamp' => $this->timestamp,
            'idempotency_key' => $this->idempotency_key,
            'invoice_item_id' => $this->invoice_item_id,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
        ];
    }
}
