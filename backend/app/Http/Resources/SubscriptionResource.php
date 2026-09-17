<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'status' => $this->status,
            'currency' => $this->currency,
            'period_amount' => $this->when($this->relationLoaded('items'), fn () => $this->periodAmount()),
            'trial_ends_at' => $this->trial_ends_at,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at,
            'cancel_reason' => $this->cancel_reason,
            'ended_at' => $this->ended_at,
            'coupon' => new CouponResource($this->whenLoaded('coupon')),
            'items' => SubscriptionItemResource::collection($this->whenLoaded('items')),
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
