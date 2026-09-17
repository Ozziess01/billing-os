<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Money;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Subscription;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Subscription::class);

        $organization = $this->current->organization();

        $byStatus = Subscription::query()
            ->forOrganization($organization)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // MRR: активные и триальные подписки, суммы приведены к месяцу, отдельно по валютам
        $mrr = [];
        Subscription::query()
            ->forOrganization($organization)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value, SubscriptionStatus::PastDue->value])
            ->with('items.price')
            ->each(function (Subscription $subscription) use (&$mrr) {
                $sum = $subscription->items->sum(fn ($item) => $item->price->monthlyAmount($item->quantity));
                $mrr[$subscription->currency] = ($mrr[$subscription->currency] ?? 0) + $sum;
            });
        ksort($mrr);

        $recent = Subscription::query()
            ->forOrganization($organization)
            ->with(['customer', 'items.price.product'])
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'customers' => Customer::query()->forOrganization($organization)->count(),
                'products' => Product::query()->forOrganization($organization)->where('active', true)->count(),
                'subscriptions' => [
                    'total' => (int) $byStatus->sum(),
                    'by_status' => collect(SubscriptionStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
                ],
                'mrr' => collect($mrr)->map(fn ($amount, $currency) => Money::of($amount, $currency))->values(),
                'recent_subscriptions' => SubscriptionResource::collection($recent),
            ],
        ]);
    }
}
