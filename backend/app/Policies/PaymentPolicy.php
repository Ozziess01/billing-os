<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends TenantPolicy
{
    public function refund(User $user, Payment $payment): bool
    {
        return $this->inScope($payment) && $this->current->atLeast(Role::Admin);
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $this->inScope($payment) && $this->current->atLeast(Role::Developer);
    }
}
