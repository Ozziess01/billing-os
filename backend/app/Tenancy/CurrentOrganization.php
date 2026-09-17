<?php

namespace App\Tenancy;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Организация текущего запроса и роль пользователя в ней.
 * Заполняется middleware ResolveOrganization, дальше на неё опираются
 * биндинг маршрутов, политики и сервисы.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    private ?Role $role = null;

    private ?User $user = null;

    public function set(Organization $organization, User $user, Role $role): void
    {
        $this->organization = $organization;
        $this->user = $user;
        $this->role = $role;
    }

    public function resolved(): bool
    {
        return $this->organization !== null;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new HttpException(404, 'Organization not resolved.');
    }

    public function id(): int
    {
        return $this->organization()->id;
    }

    public function user(): User
    {
        return $this->user ?? throw new HttpException(401, 'Unauthenticated.');
    }

    public function role(): Role
    {
        return $this->role ?? throw new HttpException(403, 'No role in organization.');
    }

    public function atLeast(Role $role): bool
    {
        return $this->role !== null && $this->role->atLeast($role);
    }
}
