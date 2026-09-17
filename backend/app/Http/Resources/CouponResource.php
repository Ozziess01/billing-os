<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'percent_off' => $this->percent_off,
            'amount_off' => $this->amount_off !== null && $this->currency ? Money::of($this->amount_off, $this->currency) : null,
            'currency' => $this->currency,
            'duration' => $this->duration,
            'redeem_by' => $this->redeem_by,
            'max_redemptions' => $this->max_redemptions,
            'times_redeemed' => $this->times_redeemed,
            'customer_id' => $this->customer_id,
            'active' => $this->active,
            'valid' => $this->active && ! $this->isExpired() && ! $this->isExhausted(),
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
        ];
    }
}
