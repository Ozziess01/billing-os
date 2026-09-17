<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy extends TenantPolicy
{
    /** Финализация и оплата - рабочие операции developer'а. */
    public function finalize(User $user, Invoice $invoice): bool
    {
        return $this->inScope($invoice) && $this->current->atLeast(Role::Developer);
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        return $this->inScope($invoice) && $this->current->atLeast(Role::Developer);
    }

    /** Аннулирование и списание меняют финансовую картину - только admin+. */
    public function writeOff(User $user, Invoice $invoice): bool
    {
        return $this->inScope($invoice) && $this->current->atLeast(Role::Admin);
    }
}
