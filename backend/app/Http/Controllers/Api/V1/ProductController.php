<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->forOrganization($this->current->organization())
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->with(['prices' => fn ($q) => $q->orderBy('created_at')])
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ProductResource::collection($products);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::create(['organization_id' => $this->current->id(), ...$request->validated()]);

        return (new ProductResource($product->load('prices')))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load('prices'));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product->update($request->validated());

        return new ProductResource($product->load('prices'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        if ($product->prices()->exists()) {
            throw ValidationException::withMessages(['product' => 'У продукта есть цены: деактивируйте его вместо удаления.']);
        }

        $product->delete();

        return response()->json(null, 204);
    }
}
