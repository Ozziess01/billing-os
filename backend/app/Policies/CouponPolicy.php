<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Купоны - это скидки с денег, поэтому создаёт и меняет их admin+; смотреть могут все. */
class CouponPolicy extends TenantPolicy
{
    public function create(User $user): bool
    {
        return $this->current->atLeast(Role::Admin);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->inScope($model) && $this->current->atLeast(Role::Admin);
    }
}
