<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy extends TenantPolicy
{
    public function cancel(User $user, Subscription $subscription): bool
    {
        return $this->inScope($subscription) && $this->current->atLeast(Role::Developer);
    }
}
