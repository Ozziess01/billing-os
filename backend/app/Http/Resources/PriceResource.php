<?php

namespace App\Http\Resources;

use App\Models\Price;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Price */
class PriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'nickname' => $this->nickname,
            'currency' => $this->currency,
            'unit_amount' => $this->unit_amount,
            'unit_amount_formatted' => $this->unitMoney()->format(),
            'billing_interval' => $this->billing_interval,
            'interval_count' => $this->interval_count,
            'active' => $this->active,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
        ];
    }
}
