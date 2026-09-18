<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);

// realtime-события организации видят только её участники
Broadcast::channel('organization.{id}', function (User $user, int $id) {
    $organization = Organization::query()->find($id);

    return $organization !== null && $user->roleIn($organization) !== null;
});
