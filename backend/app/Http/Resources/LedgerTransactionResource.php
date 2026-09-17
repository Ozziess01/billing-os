<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerTransaction */
class LedgerTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'description' => $this->description,
            'amount' => Money::of($this->amount, $this->currency),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'posted_at' => $this->posted_at,
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn (LedgerEntry $entry) => [
                'account_id' => $entry->account_id,
                'account_type' => $entry->relationLoaded('account') ? $entry->account->type : null,
                'account_name' => $entry->relationLoaded('account') ? $entry->account->name : null,
                'debit' => Money::of($entry->debit, $this->currency),
                'credit' => Money::of($entry->credit, $this->currency),
            ])),
        ];
    }
}
