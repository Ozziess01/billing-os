<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Tenancy\CurrentCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Кабинет клиента: только его подписки, инвойсы и платежи; авторизация - токеном портала. */
class PortalController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $current,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentService $payments,
    ) {}

    public function session(): JsonResponse
    {
        $customer = $this->current->customer();
        $organization = Organization::query()->findOrFail($customer->organization_id);

        return response()->json([
            'data' => [
                'customer' => new CustomerResource($customer),
                'organization' => ['name' => $organization->name, 'default_currency' => $organization->default_currency],
                'expires_at' => $this->current->session()->expires_at,
            ],
        ]);
    }

    public function updateBilling(Request $request): CustomerResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'default_payment_method' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $customer = $this->current->customer();
        $customer->update($data);

        return new CustomerResource($customer);
    }

    public function subscriptions(): AnonymousResourceCollection
    {
        return SubscriptionResource::collection(
            Subscription::query()->where('customer_id', $this->current->customer()->id)->with('items.price.product', 'coupon')->latest()->get()
        );
    }

    public function cancelSubscription(Request $request, string $id): SubscriptionResource
    {
        $subscription = Subscription::query()->where('customer_id', $this->current->customer()->id)->findOrFail($id);
        $atPeriodEnd = $request->boolean('at_period_end', true);

        $subscription = $atPeriodEnd
            ? $this->subscriptions->cancelAtPeriodEnd($subscription)
            : $this->subscriptions->cancelNow($subscription, 'portal');

        return new SubscriptionResource($subscription->load('items.price.product', 'coupon'));
    }

    public function invoices(): AnonymousResourceCollection
    {
        return InvoiceResource::collection(
            Invoice::query()->where('customer_id', $this->current->customer()->id)->where('status', '!=', 'draft')->latest()->get()
        );
    }

    public function invoice(string $id): InvoiceResource
    {
        return new InvoiceResource($this->invoiceFor($id)->load('items', 'payments'));
    }

    /** Выгрузка инвойса: JSON или CSV по позициям. */
    public function export(Request $request, string $id): Response
    {
        $invoice = $this->invoiceFor($id)->load('items');
        $format = $request->query('format', 'json');
        $filename = ($invoice->number ?? $invoice->id).'.'.($format === 'csv' ? 'csv' : 'json');

        if ($format === 'csv') {
            $lines = ['description,quantity,unit_amount,amount,currency'];
            foreach ($invoice->items as $item) {
                $lines[] = implode(',', ['"'.str_replace('"', '""', $item->description).'"', $item->quantity, $item->unit_amount, $item->amount, $item->currency]);
            }
            $lines[] = implode(',', ['"Total"', '', '', $invoice->total, $invoice->currency]);

            return response(implode("\n", $lines)."\n", 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        return response()->json(['data' => new InvoiceResource($invoice)], 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'string', 'max:120']]);
        $invoice = $this->invoiceFor($id);

        $payment = $this->payments->pay($invoice, $data['payment_method']);

        return (new PaymentResource($payment->load('invoice')))->response()->setStatusCode(201);
    }

    public function payments(): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            Payment::query()->where('customer_id', $this->current->customer()->id)->with('invoice')->latest()->get()
        );
    }

    private function invoiceFor(string $id): Invoice
    {
        $invoice = Invoice::query()->where('customer_id', $this->current->customer()->id)->where('status', '!=', 'draft')->find($id);

        if (! $invoice) {
            throw ValidationException::withMessages(['invoice' => 'Инвойс не найден.'])->status(404);
        }

        return $invoice;
    }
}
