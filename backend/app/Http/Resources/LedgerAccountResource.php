<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerAccount */
class LedgerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'currency' => $this->currency,
            'balance' => Money::of((int) $this->getAttribute('balance'), $this->currency),
        ];
    }
}
