<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class WebhookEventPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->current->atLeast(Role::Developer);
    }
}
