<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LedgerTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerAccountResource;
use App\Http\Resources\LedgerTransactionResource;
use App\Models\LedgerTransaction;
use App\Services\LedgerService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LedgerController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly LedgerService $ledger,
    ) {}

    public function accounts(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LedgerTransaction::class);

        return LedgerAccountResource::collection($this->ledger->accountsWithBalances($this->current->organization()));
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LedgerTransaction::class);

        $filters = $request->validate([
            'type' => ['sometimes', Rule::enum(LedgerTransactionType::class)],
            'reference_id' => ['sometimes', 'string', 'size:26'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $transactions = LedgerTransaction::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['reference_id']), fn ($q) => $q->where('reference_id', $filters['reference_id']))
            ->when(isset($filters['currency']), fn ($q) => $q->where('currency', $filters['currency']))
            ->with('entries.account')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return LedgerTransactionResource::collection($transactions);
    }
}
