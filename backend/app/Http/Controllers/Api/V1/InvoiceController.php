<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceItemRequest;
use App\Http\Requests\InvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Subscription;
use App\Services\InvoiceService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly InvoiceService $invoices,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'customer_id' => ['sometimes', 'string', 'size:26'],
            'subscription_id' => ['sometimes', 'string', 'size:26'],
        ]);

        $invoices = Invoice::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['subscription_id']), fn ($q) => $q->where('subscription_id', $filters['subscription_id']))
            ->with('customer')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return InvoiceResource::collection($invoices);
    }

    public function store(InvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $customer = Customer::query()->forOrganization($this->current->organization())->find($request->validated('customer_id'));

        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Клиент не найден.']);
        }

        $invoice = $this->invoices->createDraft(
            $customer,
            $request->validated('items') ?? [],
            $request->validated('currency'),
            $request->validated('description'),
            $request->validated('metadata') ?? [],
        );

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }

    /** Инвойс за текущий период подписки: черновик с позициями подписки, сразу финализированный. */
    public function forSubscription(Subscription $subscription): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = $this->invoices->createForSubscriptionPeriod($subscription);

        if (! $invoice) {
            throw ValidationException::withMessages(['subscription' => 'За этот период выставлять нечего.']);
        }

        if ($invoice->isDraft()) {
            $invoice = $this->invoices->finalize($invoice);
        }

        return (new InvoiceResource($invoice->load('payments')))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['customer', 'items', 'payments.refunds']));
    }

    public function addItem(InvoiceItemRequest $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize('update', $invoice);

        $this->invoices->addItem($invoice, $request->validated());

        return new InvoiceResource($invoice->refresh()->load(['customer', 'items']));
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): InvoiceResource
    {
        $this->authorize('update', $invoice);
        abort_unless($item->invoice_id === $invoice->id, 404);

        $this->invoices->removeItem($invoice, $item);

        return new InvoiceResource($invoice->refresh()->load(['customer', 'items']));
    }

    public function finalize(Invoice $invoice): InvoiceResource
    {
        $this->authorize('finalize', $invoice);

        return new InvoiceResource($this->invoices->finalize($invoice)->load('payments'));
    }

    public function void(Invoice $invoice): InvoiceResource
    {
        $this->authorize('writeOff', $invoice);

        return new InvoiceResource($this->invoices->void($invoice)->load('payments'));
    }

    public function uncollectible(Invoice $invoice): InvoiceResource
    {
        $this->authorize('writeOff', $invoice);

        return new InvoiceResource($this->invoices->markUncollectible($invoice)->load('payments'));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);

        $this->invoices->deleteDraft($invoice);

        return response()->json(null, 204);
    }
}
