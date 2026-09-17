<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceRequest;
use App\Http\Resources\PriceResource;
use App\Models\Price;
use App\Models\Product;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PriceController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Price::class);

        $prices = Price::query()
            ->forOrganization($this->current->organization())
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->query('product_id')))
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->with('product')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return PriceResource::collection($prices);
    }

    public function store(PriceRequest $request): JsonResponse
    {
        $this->authorize('create', Price::class);

        $product = Product::query()
            ->forOrganization($this->current->organization())
            ->find($request->validated('product_id'));

        if (! $product) {
            throw ValidationException::withMessages(['product_id' => 'Продукт не найден.']);
        }

        $price = $product->prices()->create([
            'organization_id' => $this->current->id(),
            ...$request->safe()->except('product_id'),
        ]);

        return (new PriceResource($price->load('product')))->response()->setStatusCode(201);
    }

    public function show(Price $price): PriceResource
    {
        $this->authorize('view', $price);

        return new PriceResource($price->load('product'));
    }

    public function update(PriceRequest $request, Price $price): PriceResource
    {
        $this->authorize('update', $price);

        $price->update($request->validated());

        return new PriceResource($price->load('product'));
    }

    public function destroy(Price $price): JsonResponse
    {
        $this->authorize('delete', $price);

        if ($price->subscriptionItems()->exists()) {
            throw ValidationException::withMessages(['price' => 'Цена используется в подписках: деактивируйте её вместо удаления.']);
        }

        $price->delete();

        return response()->json(null, 204);
    }
}
