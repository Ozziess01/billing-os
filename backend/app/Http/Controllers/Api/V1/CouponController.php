<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\CouponService;
use App\Services\SubscriptionService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly CouponService $coupons,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Coupon::class);

        $coupons = Coupon::query()
            ->forOrganization($this->current->organization())
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return CouponResource::collection($coupons);
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validated();

        if (! empty($data['customer_id']) && ! Customer::query()->forOrganization($this->current->organization())->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Клиент не найден.']);
        }

        $coupon = $this->coupons->create($this->current->organization(), $data);

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        $this->authorize('view', $coupon);

        return new CouponResource($coupon);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $this->authorize('update', $coupon);

        $coupon->update($request->validated());

        return new CouponResource($coupon);
    }

    /** Применить купон к существующей подписке по коду. */
    public function apply(Request $request, Subscription $subscription): SubscriptionResource
    {
        $this->authorize('update', $subscription);

        $code = $request->validate(['coupon_code' => ['required', 'string', 'max:40']])['coupon_code'];
        $coupon = $this->coupons->findByCode($this->current->organization(), $code);

        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => 'Купон не найден.']);
        }

        return new SubscriptionResource($this->subscriptions->applyCoupon($subscription, $coupon));
    }
}
