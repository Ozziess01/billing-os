<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelSubscriptionRequest;
use App\Http\Requests\SubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subscription::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
            'customer_id' => ['sometimes', 'string', 'size:26'],
        ]);

        $subscriptions = Subscription::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->with(['customer', 'items.price.product'])
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return SubscriptionResource::collection($subscriptions);
    }

    public function store(SubscriptionRequest $request): JsonResponse
    {
        $this->authorize('create', Subscription::class);

        $customer = Customer::query()
            ->forOrganization($this->current->organization())
            ->find($request->validated('customer_id'));

        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Клиент не найден.']);
        }

        $subscription = $this->subscriptions->create(
            $customer,
            $request->validated('items'),
            (int) $request->validated('trial_days', 0),
            $request->filled('starts_at') ? CarbonImmutable::parse($request->validated('starts_at')) : null,
            $request->validated('metadata') ?? [],
        );

        return (new SubscriptionResource($subscription))->response()->setStatusCode(201);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        $this->authorize('view', $subscription);

        return new SubscriptionResource($subscription->load(['customer', 'items.price.product']));
    }

    public function cancel(CancelSubscriptionRequest $request, Subscription $subscription): SubscriptionResource
    {
        $this->authorize('cancel', $subscription);

        $subscription = $request->boolean('at_period_end', true)
            ? $this->subscriptions->cancelAtPeriodEnd($subscription)
            : $this->subscriptions->cancelNow($subscription);

        return new SubscriptionResource($subscription->load(['customer', 'items.price.product']));
    }

    public function resume(Subscription $subscription): SubscriptionResource
    {
        $this->authorize('cancel', $subscription);

        return new SubscriptionResource($this->subscriptions->resume($subscription)->load(['customer', 'items.price.product']));
    }
}
