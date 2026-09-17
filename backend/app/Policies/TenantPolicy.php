<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Общая схема прав для сущностей организации: viewer читает, developer создаёт и правит,
 * admin удаляет. Ресурс должен принадлежать организации запроса - иначе это чужие данные.
 */
abstract class TenantPolicy
{
    public function __construct(protected readonly CurrentOrganization $current) {}

    public function viewAny(User $user): bool
    {
        return $this->current->atLeast(Role::Viewer);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->inScope($model) && $this->current->atLeast(Role::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->current->atLeast(Role::Developer);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->inScope($model) && $this->current->atLeast(Role::Developer);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->inScope($model) && $this->current->atLeast(Role::Admin);
    }

    protected function inScope(Model $model): bool
    {
        return $this->current->resolved() && (int) $model->getAttribute('organization_id') === $this->current->id();
    }
}
