<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
