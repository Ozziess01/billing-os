<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Ключ даёт доступ ко всем данным организации, поэтому выдаёт и отзывает его admin+. */
class ApiKeyPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->current->atLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $this->current->atLeast(Role::Admin);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->inScope($model) && $this->current->atLeast(Role::Admin);
    }
}
