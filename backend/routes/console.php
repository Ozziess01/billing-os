<?php

use Illuminate\Support\Facades\Schedule;

// ключи идемпотентности живут сутки, дальше только мешают
Schedule::command('idempotency:prune')->hourly();
