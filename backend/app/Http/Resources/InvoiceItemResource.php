<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceItem */
class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price_id' => $this->price_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => Money::of($this->unit_amount, $this->currency),
            'amount' => Money::of($this->amount, $this->currency),
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
        ];
    }
}
