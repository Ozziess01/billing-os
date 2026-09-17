<?php

namespace App\Services;

use App\Enums\RefundStatus;
use App\Events\Billing\RefundSucceeded;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Dto\RefundRequest;
use App\Payments\Dto\RefundResult;
use App\Payments\ProviderException;
use App\Payments\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Возврат - компенсирующая операция: оригинальный платёж не меняется, растёт только
 * amount_refunded, а в леджер уходит обратная проводка. Сумма всех возвратов никогда
 * не превысит списанное: это держат и lock платежа, и CHECK в базе.
 */
class RefundService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly LedgerService $ledger,
    ) {}

    public function refund(Payment $payment, ?int $amount = null, ?string $reason = null): Refund
    {
        $refund = DB::transaction(function () use ($payment, $amount, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $payment->isSucceeded()) {
                throw ValidationException::withMessages(['payment' => 'Вернуть можно только успешный платёж.']);
            }

            // незавершённые возвраты тоже резервируют сумму, иначе два параллельных запроса вернут больше списанного
            $reserved = (int) $payment->refunds()->where('status', RefundStatus::Pending->value)->sum('amount');
            $available = $payment->refundableAmount() - $reserved;
            $amount ??= $available;

            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => "Доступно к возврату {$available} {$payment->currency}."]);
            }

            return Refund::create([
                'organization_id' => $payment->organization_id,
                'payment_id' => $payment->id,
                'status' => RefundStatus::Pending,
                'currency' => $payment->currency,
                'amount' => $amount,
                'reason' => $reason,
            ]);
        });

        try {
            $result = $this->providers->get($payment->provider)->refund(new RefundRequest(
                refundId: $refund->id,
                providerPaymentId: (string) $payment->provider_payment_id,
                amount: $refund->amountMoney(),
                reason: $reason,
            ));
        } catch (ProviderException $e) {
            return $this->applyResult($refund, RefundResult::failed('', $e->getMessage()));
        }

        return $this->applyResult($refund, $result);
    }

    public function applyResult(Refund $refund, RefundResult $result): Refund
    {
        return DB::transaction(function () use ($refund, $result) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status !== RefundStatus::Pending) {
                return $refund;
            }

            $providerId = $result->providerRefundId !== '' ? $result->providerRefundId : $refund->provider_refund_id;

            if ($result->status === RefundResult::SUCCEEDED) {
                $refund->forceFill(['status' => RefundStatus::Succeeded, 'provider_refund_id' => $providerId, 'succeeded_at' => now()])->save();
                $payment->forceFill(['amount_refunded' => $payment->amount_refunded + $refund->amount])->save();
                $this->ledger->postRefund($refund);
                RefundSucceeded::dispatch($refund);
            } elseif ($result->status === RefundResult::FAILED) {
                $refund->forceFill(['status' => RefundStatus::Failed, 'provider_refund_id' => $providerId, 'failure_message' => $result->failureMessage, 'failed_at' => now()])->save();
            } else {
                $refund->forceFill(['provider_refund_id' => $providerId])->save();
            }

            return $refund;
        });
    }
}
