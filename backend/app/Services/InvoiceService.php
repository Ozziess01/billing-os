<?php

namespace App\Services;

use App\Enums\CouponDuration;
use App\Enums\InvoiceStatus;
use App\Events\Billing\InvoiceFinalized;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoiceVoided;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Черновик с позициями. Позиция - либо цена (сумма берётся из неё), либо произвольная строка.
     *
     * @param  list<array{price_id?: string|null, description?: string|null, quantity?: int, unit_amount?: int|null}>  $items
     * @param  array<string, mixed>  $metadata
     */
    public function createDraft(Customer $customer, array $items, ?string $currency = null, ?string $description = null, array $metadata = []): Invoice
    {
        $currency ??= (string) $customer->organization()->value('default_currency');

        return DB::transaction(function () use ($customer, $items, $currency, $description, $metadata) {
            $invoice = Invoice::create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'status' => InvoiceStatus::Draft,
                'currency' => $currency,
                'description' => $description,
                'metadata' => $metadata ?: null,
            ]);

            foreach ($items as $item) {
                $this->addItem($invoice, $item);
            }

            return $invoice->load('items', 'customer');
        });
    }

    /**
     * Инвойс за текущий период подписки: фиксированные позиции - вперёд за новый период,
     * metered - по факту за прошедший ($usageFrom..$usageTo). Открытый инвойс за этот период
     * уже есть - возвращаем его. Нечего выставлять (только metered без использования) - null.
     */
    public function createForSubscriptionPeriod(Subscription $subscription, ?CarbonImmutable $usageFrom = null, ?CarbonImmutable $usageTo = null): ?Invoice
    {
        return DB::transaction(function () use ($subscription, $usageFrom, $usageTo) {
            $subscription = Subscription::query()->lockForUpdate()->with('items.price.product', 'coupon')->findOrFail($subscription->id);

            $existing = $subscription->invoices()
                ->where('period_start', $subscription->current_period_start)
                ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Open->value, InvoiceStatus::Paid->value])
                ->first();

            if ($existing) {
                return $existing->load('items', 'customer');
            }

            $invoice = Invoice::create([
                'organization_id' => $subscription->organization_id,
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'status' => InvoiceStatus::Draft,
                'currency' => $subscription->currency,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'auto_collect' => true,
            ]);

            foreach ($subscription->items as $item) {
                if ($item->price->isMetered()) {
                    if ($usageFrom && $usageTo) {
                        $this->addUsageLine($invoice, $item, $usageFrom, $usageTo);
                    }

                    continue;
                }

                $this->addItem($invoice, [
                    'price_id' => $item->price_id,
                    'quantity' => $item->quantity,
                    'period_start' => $subscription->current_period_start,
                    'period_end' => $subscription->current_period_end,
                ]);
            }

            if (! $invoice->items()->exists()) {
                $invoice->delete();

                return null;
            }

            $this->applyCoupon($invoice, $subscription);

            return $invoice->load('items', 'customer');
        });
    }

    /** Строка за использование: units × unit_amount_decimal, округление один раз; события помечаются выставленными. */
    private function addUsageLine(Invoice $invoice, SubscriptionItem $item, CarbonImmutable $from, CarbonImmutable $to): void
    {
        // сначала блокируем строки, потом считаем: параллельный отчёт не попадёт в уже закрытый период
        $ids = UsageEvent::query()
            ->where('subscription_item_id', $item->id)
            ->whereNull('invoice_item_id')
            ->where('timestamp', '>=', $from)
            ->where('timestamp', '<', $to)
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $units = (int) UsageEvent::query()->whereIn('id', $ids)->sum('quantity');

        $price = $item->price;
        $amount = $price->usageAmount($units);
        $line = $invoice->items()->create([
            'price_id' => $price->id,
            'description' => trim($price->product->name.' '.($price->nickname ? "({$price->nickname})" : '')).": {$units} × ".rtrim(rtrim((string) $price->unit_amount_decimal, '0'), '.').' '.$price->currency.'/100',
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'period_start' => $from,
            'period_end' => $to,
            'metadata' => ['units' => $units, 'unit_amount_decimal' => (string) $price->unit_amount_decimal],
        ]);

        UsageEvent::query()->whereIn('id', $ids)->update(['invoice_item_id' => $line->id]);
        $this->recalculate($invoice);
    }

    /** Купон подписки: forever - на каждый инвойс, once - только на первый. */
    private function applyCoupon(Invoice $invoice, Subscription $subscription): void
    {
        $coupon = $subscription->coupon;

        if (! $coupon) {
            return;
        }

        if ($coupon->duration === CouponDuration::Once && $subscription->invoices()->where('coupon_id', $coupon->id)->whereKeyNot($invoice->id)->exists()) {
            return;
        }

        $discount = $coupon->discountFor($invoice->subtotal, $invoice->currency);
        $total = $invoice->subtotal - $discount;

        // одним UPDATE, иначе CHECK total = subtotal - discount сработает раньше пересчёта
        $invoice->forceFill([
            'coupon_id' => $coupon->id,
            'discount' => $discount,
            'total' => $total,
            'amount_due' => $total - $invoice->amount_paid,
        ])->save();
    }

    /** @param  array{price_id?: string|null, description?: string|null, quantity?: int, unit_amount?: int|null, period_start?: mixed, period_end?: mixed}  $item */
    public function addItem(Invoice $invoice, array $item): InvoiceItem
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => 'Позиции можно менять только у черновика.']);
        }

        $price = null;
        if (! empty($item['price_id'])) {
            $price = Price::query()->forOrganization($invoice->organization_id)->with('product')->find($item['price_id']);
            if (! $price) {
                throw ValidationException::withMessages(['items' => 'Цена не найдена.']);
            }
            if ($price->currency !== $invoice->currency) {
                throw ValidationException::withMessages(['items' => "Цена в {$price->currency}, а инвойс в {$invoice->currency}."]);
            }
        }

        $quantity = (int) ($item['quantity'] ?? 1);
        $unitAmount = $price ? $price->unit_amount : (int) ($item['unit_amount'] ?? 0);
        $description = $item['description'] ?? ($price ? trim($price->product->name.' '.($price->nickname ? "({$price->nickname})" : '')) : null);

        if ($description === null || $description === '') {
            throw ValidationException::withMessages(['items' => 'У позиции без цены нужно описание.']);
        }

        $line = $invoice->items()->create([
            'price_id' => $price?->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'amount' => $unitAmount * $quantity,
            'currency' => $invoice->currency,
            'period_start' => $item['period_start'] ?? null,
            'period_end' => $item['period_end'] ?? null,
        ]);

        $this->recalculate($invoice);

        return $line;
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): void
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => 'Позиции можно менять только у черновика.']);
        }

        $item->delete();
        $this->recalculate($invoice);
    }

    /**
     * draft → open: номер, срок оплаты, замороженные суммы и проводка в леджер.
     * Нулевой инвойс сразу становится paid - платить нечего.
     */
    public function finalize(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['invoice' => 'Финализировать можно только черновик.']);
            }
            if (! $invoice->items()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'В инвойсе нет ни одной позиции.']);
            }

            $this->recalculate($invoice);
            $number = $this->nextNumber($invoice->organization_id);

            $invoice->transition(InvoiceStatus::Open, [
                'number' => $number,
                'finalized_at' => now(),
                'due_at' => now()->addDays((int) config('billing.invoice_due_days', 7)),
            ]);

            $this->ledger->postInvoice($invoice);
            InvoiceFinalized::dispatch($invoice);

            if ($invoice->total === 0) {
                $invoice->transition(InvoiceStatus::Paid, ['paid_at' => now()]);
                InvoicePaid::dispatch($invoice);
            }

            return $invoice->load('items', 'customer');
        });
    }

    /** Успешный платёж закрывает долг; инвойс становится paid, когда остаток - ноль. Вызывается под lock платежа. */
    public function applyPayment(Payment $payment): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);

        if (! $invoice->isOpen()) {
            return $invoice;
        }

        $paid = min($invoice->amount_paid + $payment->amount, $invoice->total);
        $invoice->forceFill(['amount_paid' => $paid, 'amount_due' => $invoice->total - $paid])->save();

        if ($invoice->amount_due === 0) {
            $invoice->transition(InvoiceStatus::Paid, ['paid_at' => now()]);
            InvoicePaid::dispatch($invoice);
        }

        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        return $this->writeOff($invoice, InvoiceStatus::Void, 'voided_at', 'voided');
    }

    public function markUncollectible(Invoice $invoice): Invoice
    {
        return $this->writeOff($invoice, InvoiceStatus::Uncollectible, 'uncollectible_at', 'uncollectible');
    }

    public function deleteDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => 'Удалить можно только черновик; финализированный инвойс аннулируется.']);
        }

        $invoice->delete();
    }

    private function writeOff(Invoice $invoice, InvoiceStatus $to, string $timestampField, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $to, $timestampField, $reason) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->payments()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['invoice' => 'По инвойсу есть платёж в обработке, дождитесь его завершения.']);
            }

            $wasOpen = $invoice->isOpen();
            $invoice->transition($to, [$timestampField => now()]);

            if ($wasOpen) {
                $this->ledger->postWriteOff($invoice, $reason);
            }

            InvoiceVoided::dispatch($invoice);

            return $invoice->load('items', 'customer');
        });
    }

    private function recalculate(Invoice $invoice): void
    {
        $subtotal = (int) $invoice->items()->sum('amount');
        $total = $subtotal - $invoice->discount;

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'total' => $total,
            'amount_due' => $total - $invoice->amount_paid,
        ])->save();
    }

    /** Сквозной номер в организации: атомарный инкремент, две финализации не получат один номер. */
    private function nextNumber(int $organizationId): string
    {
        $row = DB::selectOne(
            'UPDATE organizations SET next_invoice_number = next_invoice_number + 1 WHERE id = ? RETURNING next_invoice_number - 1 AS number',
            [$organizationId],
        );

        return sprintf('INV-%06d', (int) $row->number);
    }
}
