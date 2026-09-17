<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $q = trim((string) $request->query('q'));

        $customers = Customer::query()
            ->forOrganization($this->current->organization())
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereRaw('name ILIKE ?', ["%{$q}%"])
                ->orWhereRaw('email ILIKE ?', ["%{$q}%"])
                ->orWhere('external_id', $q)))
            ->withCount('subscriptions')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return CustomerResource::collection($customers);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $this->assertExternalIdFree($request->validated('external_id'));

        $customer = Customer::create(['organization_id' => $this->current->id(), ...$request->validated()]);

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer->load('subscriptions.items.price.product'));
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('update', $customer);

        if ($request->has('external_id')) {
            $this->assertExternalIdFree($request->validated('external_id'), $customer);
        }

        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        if ($customer->subscriptions()->exists()) {
            throw ValidationException::withMessages(['customer' => 'У клиента есть подписки, удалить его нельзя.']);
        }

        $customer->delete();

        return response()->json(null, 204);
    }

    private function assertExternalIdFree(?string $externalId, ?Customer $except = null): void
    {
        if ($externalId === null) {
            return;
        }

        $taken = Customer::query()
            ->forOrganization($this->current->organization())
            ->where('external_id', $externalId)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['external_id' => 'Клиент с таким external_id уже есть.']);
        }
    }
}
