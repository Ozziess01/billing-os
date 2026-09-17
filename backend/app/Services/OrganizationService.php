<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public function create(User $owner, string $name, ?string $currency = null): Organization
    {
        return DB::transaction(function () use ($owner, $name, $currency) {
            $organization = Organization::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $owner->id,
                'default_currency' => $currency ?? config('billing.default_currency'),
            ]);

            $organization->members()->create(['user_id' => $owner->id, 'role' => Role::Owner]);

            return $organization;
        });
    }

    public function addMember(Organization $organization, string $email, Role $role): OrganizationMember
    {
        if ($role === Role::Owner) {
            throw ValidationException::withMessages(['role' => 'Владелец у организации один, передайте владение отдельно.']);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Пользователь с такой почтой не зарегистрирован.']);
        }

        if ($organization->members()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Пользователь уже состоит в организации.']);
        }

        return $organization->members()->create(['user_id' => $user->id, 'role' => $role]);
    }

    public function changeRole(OrganizationMember $member, Role $role): OrganizationMember
    {
        if ($member->role === Role::Owner || $role === Role::Owner) {
            throw ValidationException::withMessages(['role' => 'Роль владельца меняется только передачей владения.']);
        }

        $member->update(['role' => $role]);

        return $member;
    }

    public function removeMember(OrganizationMember $member): void
    {
        if ($member->role === Role::Owner) {
            throw ValidationException::withMessages(['member' => 'Владельца нельзя удалить из организации.']);
        }

        $member->delete();
    }

    public function transferOwnership(Organization $organization, User $to): void
    {
        DB::transaction(function () use ($organization, $to) {
            $target = $organization->members()->where('user_id', $to->id)->lockForUpdate()->first();

            if (! $target) {
                throw ValidationException::withMessages(['user_id' => 'Новый владелец должен состоять в организации.']);
            }

            $organization->members()->where('user_id', $organization->owner_id)->update(['role' => Role::Admin]);
            $target->update(['role' => Role::Owner]);
            $organization->update(['owner_id' => $to->id]);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;

        for ($i = 2; Organization::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
