<?php

namespace App\Services;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerTransactionType;
use App\Models\Invoice;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Двойная запись на четырёх счетах организации (на каждую валюту свои):
 *   финализация инвойса  - Dr receivable / Cr revenue
 *   успешный платёж      - Dr cash       / Cr receivable
 *   возврат              - Dr revenue    / Cr cash
 *   void / uncollectible - Dr adjustments / Cr receivable   (на неоплаченный остаток)
 *
 * Проводки только добавляются; баланс каждой проверяет constraint-триггер в базе,
 * а уникальность (type, reference) не даёт провести одно событие дважды.
 */
class LedgerService
{
    public function postInvoice(Invoice $invoice): ?LedgerTransaction
    {
        if ($invoice->total === 0) {
            return null;
        }

        return $this->post(
            $invoice->organization_id,
            LedgerTransactionType::Invoice,
            $invoice->currency,
            $invoice->total,
            "Invoice {$invoice->number}",
            'invoice',
            $invoice->id,
            [LedgerAccountType::Receivable, $invoice->total, 0],
            [LedgerAccountType::Revenue, 0, $invoice->total],
        );
    }

    public function postPayment(Payment $payment): ?LedgerTransaction
    {
        return $this->post(
            $payment->organization_id,
            LedgerTransactionType::Payment,
            $payment->currency,
            $payment->amount,
            "Payment {$payment->id}",
            'payment',
            $payment->id,
            [LedgerAccountType::Cash, $payment->amount, 0],
            [LedgerAccountType::Receivable, 0, $payment->amount],
        );
    }

    public function postRefund(Refund $refund): ?LedgerTransaction
    {
        return $this->post(
            $refund->organization_id,
            LedgerTransactionType::Refund,
            $refund->currency,
            $refund->amount,
            "Refund {$refund->id}",
            'refund',
            $refund->id,
            [LedgerAccountType::Revenue, $refund->amount, 0],
            [LedgerAccountType::Cash, 0, $refund->amount],
        );
    }

    /** Списание неоплаченного остатка при void/uncollectible. */
    public function postWriteOff(Invoice $invoice, string $reason): ?LedgerTransaction
    {
        if ($invoice->amount_due === 0) {
            return null;
        }

        return $this->post(
            $invoice->organization_id,
            LedgerTransactionType::Adjustment,
            $invoice->currency,
            $invoice->amount_due,
            "Invoice {$invoice->number} {$reason}",
            'invoice',
            $invoice->id,
            [LedgerAccountType::Adjustments, $invoice->amount_due, 0],
            [LedgerAccountType::Receivable, 0, $invoice->amount_due],
        );
    }

    /**
     * Счета организации с балансами: дебетовые (cash, receivable, adjustments) как debit − credit,
     * кредитовые (revenue) как credit − debit.
     *
     * @return Collection<int, LedgerAccount>
     */
    public function accountsWithBalances(Organization $organization): Collection
    {
        return LedgerAccount::query()
            ->forOrganization($organization)
            ->select('ledger_accounts.*')
            ->selectSub(
                DB::table('ledger_entries')->selectRaw('coalesce(sum(debit) - sum(credit), 0)')->whereColumn('account_id', 'ledger_accounts.id'),
                'debit_balance',
            )
            ->orderBy('currency')
            ->orderBy('type')
            ->get()
            ->each(function (LedgerAccount $account) {
                $debit = (int) $account->getAttribute('debit_balance');
                $account->setAttribute('balance', $account->type->isDebitNormal() ? $debit : -$debit);
            });
    }

    /**
     * @param  array{0: LedgerAccountType, 1: int, 2: int}  ...$lines  [счёт, дебет, кредит]
     */
    private function post(
        int $organizationId,
        LedgerTransactionType $type,
        string $currency,
        int $amount,
        string $description,
        string $referenceType,
        string $referenceId,
        array ...$lines,
    ): ?LedgerTransaction {
        return DB::transaction(function () use ($organizationId, $type, $currency, $amount, $description, $referenceType, $referenceId, $lines) {
            // событие уже проведено (повторный вебхук, retry job) - второй раз не пишем;
            // вызывающие держат lock на платеже/инвойсе, а уникальный индекс страхует от гонки
            $posted = LedgerTransaction::query()
                ->where('type', $type->value)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->exists();

            if ($posted) {
                return null;
            }

            $transaction = LedgerTransaction::create([
                'organization_id' => $organizationId,
                'type' => $type,
                'currency' => $currency,
                'amount' => $amount,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'posted_at' => now(),
            ]);

            foreach ($lines as [$accountType, $debit, $credit]) {
                $transaction->entries()->create([
                    'account_id' => $this->account($organizationId, $accountType, $currency)->id,
                    'debit' => $debit,
                    'credit' => $credit,
                ]);
            }

            // отложенный триггер сработал бы на commit; проверяем баланс сразу, пока проводка целиком перед глазами
            DB::statement('SET CONSTRAINTS ledger_entries_balanced IMMEDIATE');
            DB::statement('SET CONSTRAINTS ledger_entries_balanced DEFERRED');

            return $transaction;
        });
    }

    private function account(int $organizationId, LedgerAccountType $type, string $currency): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'type' => $type->value, 'currency' => $currency],
            ['name' => $type->label()],
        );
    }
}
