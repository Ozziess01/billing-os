<?php

namespace App\Http\Resources;

use App\Models\SubscriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionItem */
class SubscriptionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price_id' => $this->price_id,
            'price' => new PriceResource($this->whenLoaded('price')),
            'quantity' => $this->quantity,
            'amount' => $this->when($this->relationLoaded('price'), fn () => $this->price->amountFor($this->quantity)),
        ];
    }
}
