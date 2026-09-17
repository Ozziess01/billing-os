<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'subscription_id' => $this->subscription_id,
            'currency' => $this->currency,
            'subtotal' => Money::of($this->subtotal, $this->currency),
            'discount' => Money::of($this->discount, $this->currency),
            'total' => Money::of($this->total, $this->currency),
            'amount_paid' => Money::of($this->amount_paid, $this->currency),
            'amount_due' => Money::of($this->amount_due, $this->currency),
            'description' => $this->description,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'due_at' => $this->due_at,
            'finalized_at' => $this->finalized_at,
            'paid_at' => $this->paid_at,
            'voided_at' => $this->voided_at,
            'uncollectible_at' => $this->uncollectible_at,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
