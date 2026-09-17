<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'amount' => Money::of($this->amount, $this->currency),
            'amount_refunded' => Money::of($this->amount_refunded, $this->currency),
            'refundable_amount' => Money::of($this->refundableAmount(), $this->currency),
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'payment_method' => $this->payment_method,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'next_action' => $this->next_action,
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'succeeded_at' => $this->succeeded_at,
            'failed_at' => $this->failed_at,
            'canceled_at' => $this->canceled_at,
            'created_at' => $this->created_at,
        ];
    }
}
