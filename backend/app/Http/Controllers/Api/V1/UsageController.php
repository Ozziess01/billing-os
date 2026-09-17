<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsageRequest;
use App\Http\Resources\UsageEventResource;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\UsageEvent;
use App\Services\UsageService;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class UsageController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly UsageService $usage,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UsageEvent::class);

        $filters = $request->validate([
            'subscription_item_id' => ['sometimes', 'string', 'size:26'],
            'customer_id' => ['sometimes', 'string', 'size:26'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $events = UsageEvent::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['subscription_item_id']), fn ($q) => $q->where('subscription_item_id', $filters['subscription_item_id']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['from']), fn ($q) => $q->where('timestamp', '>=', CarbonImmutable::parse($filters['from'])))
            ->when(isset($filters['to']), fn ($q) => $q->where('timestamp', '<', CarbonImmutable::parse($filters['to'])))
            ->orderByDesc('timestamp')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return UsageEventResource::collection($events);
    }

    /** POST /usage - безопасен к повтору: тот же idempotency_key вернёт то же событие. */
    public function store(UsageRequest $request): JsonResponse
    {
        $this->authorize('create', UsageEvent::class);

        $item = SubscriptionItem::query()
            ->whereKey($request->validated('subscription_item_id'))
            ->whereHas('subscription', fn ($q) => $q->forOrganization($this->current->organization()))
            ->first();

        if (! $item) {
            throw ValidationException::withMessages(['subscription_item_id' => 'Позиция подписки не найдена.']);
        }

        $before = UsageEvent::count();
        $event = $this->usage->record(
            $item,
            (int) $request->validated('quantity'),
            $request->filled('timestamp') ? CarbonImmutable::parse($request->validated('timestamp')) : null,
            $request->validated('idempotency_key'),
            $request->validated('metadata') ?? [],
        );

        return (new UsageEventResource($event))->response()->setStatusCode(UsageEvent::count() > $before ? 201 : 200);
    }

    public function summary(Subscription $subscription): JsonResponse
    {
        $this->authorize('view', $subscription);

        return response()->json([
            'data' => [
                'subscription_id' => $subscription->id,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'items' => $this->usage->summary($subscription),
            ],
        ]);
    }
}
