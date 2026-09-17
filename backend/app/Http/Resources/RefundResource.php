<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Refund */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'status' => $this->status,
            'amount' => Money::of($this->amount, $this->currency),
            'reason' => $this->reason,
            'provider_refund_id' => $this->provider_refund_id,
            'failure_message' => $this->failure_message,
            'succeeded_at' => $this->succeeded_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
        ];
    }
}
