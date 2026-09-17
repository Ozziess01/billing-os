<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\RefundRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly PaymentService $payments,
        private readonly RefundService $refunds,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'invoice_id' => ['sometimes', 'string', 'size:26'],
            'customer_id' => ['sometimes', 'string', 'size:26'],
        ]);

        $payments = Payment::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['invoice_id']), fn ($q) => $q->where('invoice_id', $filters['invoice_id']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->with(['customer', 'invoice'])
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return PaymentResource::collection($payments);
    }

    /** POST /payments - принимает Idempotency-Key: повтор с тем же ключом не создаст вторую попытку. */
    public function store(PaymentRequest $request): JsonResponse
    {
        $invoice = Invoice::query()->forOrganization($this->current->organization())->find($request->validated('invoice_id'));

        if (! $invoice) {
            throw ValidationException::withMessages(['invoice_id' => 'Инвойс не найден.']);
        }

        $this->authorize('pay', $invoice);

        $payment = $this->payments->pay($invoice, $request->validated('payment_method'));

        return (new PaymentResource($payment->load(['invoice', 'customer', 'refunds'])))->response()->setStatusCode(201);
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load(['invoice', 'customer', 'refunds']));
    }

    public function refund(RefundRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        $refund = $this->refunds->refund($payment, $request->validated('amount'), $request->validated('reason'));

        return (new RefundResource($refund))->response()->setStatusCode(201);
    }

    public function cancel(Payment $payment): PaymentResource
    {
        $this->authorize('cancel', $payment);

        return new PaymentResource($this->payments->cancel($payment)->load(['invoice', 'customer', 'refunds']));
    }

    public function refunds(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validate(['payment_id' => ['sometimes', 'string', 'size:26']]);

        $refunds = Refund::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['payment_id']), fn ($q) => $q->where('payment_id', $filters['payment_id']))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return RefundResource::collection($refunds);
    }
}
