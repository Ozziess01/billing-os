<?php

namespace App\Services;

use App\Audit\Activity;
use App\Enums\PaymentStatus;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\PaymentSucceeded;
use App\Models\Invoice;
use App\Models\Payment;
use App\Payments\Dto\PaymentRequest;
use App\Payments\Dto\PaymentResult;
use App\Payments\ProviderException;
use App\Payments\ProviderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Платёж по инвойсу в три шага: короткая транзакция на создание попытки, сетевой вызов
 * провайдера вне транзакции, вторая транзакция на применение результата. Один и тот же
 * результат может прийти и синхронно, и вебхуком - applyResult идемпотентен.
 */
class PaymentService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly InvoiceService $invoices,
        private readonly LedgerService $ledger,
        private readonly Activity $activity,
    ) {}

    public function pay(Invoice $invoice, string $paymentMethod, ?string $providerName = null, bool $automatic = false): Payment
    {
        $provider = $this->providers->get($providerName);

        $payment = DB::transaction(function () use ($invoice, $paymentMethod, $provider, $automatic) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpen()) {
                throw ValidationException::withMessages(['invoice' => "Инвойс в статусе {$invoice->status->value}, оплатить можно только открытый."]);
            }
            if ($invoice->amount_due <= 0) {
                throw ValidationException::withMessages(['invoice' => 'По инвойсу нечего платить.']);
            }
            if ($invoice->payments()->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])->exists()) {
                throw new ConflictHttpException('По инвойсу уже есть платёж в обработке.');
            }

            try {
                return Payment::create([
                    'organization_id' => $invoice->organization_id,
                    'customer_id' => $invoice->customer_id,
                    'invoice_id' => $invoice->id,
                    'attempt_number' => (int) $invoice->payments()->max('attempt_number') + 1,
                    'status' => PaymentStatus::Pending,
                    'currency' => $invoice->currency,
                    'amount' => $invoice->amount_due,
                    'provider' => $provider->name(),
                    'payment_method' => $paymentMethod,
                    'automatic' => $automatic,
                ]);
            } catch (UniqueConstraintViolationException) {
                // частичный уникальный индекс: параллельный запрос успел первым
                throw new ConflictHttpException('По инвойсу уже есть платёж в обработке.');
            }
        });

        try {
            $result = $provider->createPayment(new PaymentRequest(
                paymentId: $payment->id,
                amount: $payment->amountMoney(),
                paymentMethod: $paymentMethod,
                description: "Invoice {$invoice->number}",
                organizationId: $payment->organization_id,
            ));
        } catch (ProviderException $e) {
            return $this->applyResult($payment, PaymentResult::failed('', 'provider_error', $e->getMessage()));
        }

        return $this->applyResult($payment, $result);
    }

    /**
     * Результат от провайдера - синхронный ответ или вебхук. Терминальный статус применяется
     * один раз: атомарный переход + проводка с уникальной ссылкой на платёж.
     */
    public function applyResult(Payment $payment, PaymentResult $result): Payment
    {
        return DB::transaction(function () use ($payment, $result) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status->isFinal()) {
                return $payment;
            }

            $providerId = $result->providerPaymentId !== '' ? $result->providerPaymentId : $payment->provider_payment_id;

            switch ($result->status) {
                case PaymentResult::SUCCEEDED:
                    $payment->transition(PaymentStatus::Succeeded, [
                        'provider_payment_id' => $providerId,
                        'succeeded_at' => now(),
                        'next_action' => null,
                    ]);
                    $this->ledger->postPayment($payment);
                    $this->invoices->applyPayment($payment);
                    PaymentSucceeded::dispatch($payment);
                    $this->activity->record('payment.succeeded', $payment->organization_id, $payment, ['invoice_id' => $payment->invoice_id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'automatic' => $payment->automatic]);
                    break;

                case PaymentResult::FAILED:
                    $payment->transition(PaymentStatus::Failed, [
                        'provider_payment_id' => $providerId,
                        'failure_code' => $result->failureCode,
                        'failure_message' => $result->failureMessage,
                        'failed_at' => now(),
                        'next_action' => null,
                    ]);
                    PaymentFailed::dispatch($payment);
                    $this->activity->record('payment.failed', $payment->organization_id, $payment, ['invoice_id' => $payment->invoice_id, 'code' => $result->failureCode, 'automatic' => $payment->automatic]);
                    break;

                default:
                    // pending / requires_action: ждём провайдера, платёж остаётся «в полёте»
                    if ($payment->status === PaymentStatus::Pending) {
                        $payment->transition(PaymentStatus::Processing, [
                            'provider_payment_id' => $providerId,
                            'next_action' => $result->nextAction,
                        ]);
                    }
            }

            return $payment;
        });
    }

    /** Отменить платёж, застрявший в обработке (например, клиент так и не подтвердил действие). */
    public function cancel(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $payment->status->inFlight()) {
                throw ValidationException::withMessages(['payment' => 'Отменить можно только платёж в обработке.']);
            }

            $payment->transition(PaymentStatus::Canceled, ['canceled_at' => now(), 'next_action' => null]);

            return $payment;
        });
    }
}
