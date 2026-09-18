<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Журнал действий читают admin и выше; писать в него из API нельзя вовсе. */
class ActivityLogPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->current->atLeast(Role::Admin);
    }
}
