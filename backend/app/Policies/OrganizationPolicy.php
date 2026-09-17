<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Viewer);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Admin);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Admin);
    }

    public function transfer(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Owner);
    }
}
