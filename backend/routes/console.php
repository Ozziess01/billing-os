<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// биллинговый цикл: продления по окончании периода и плановые попытки автосписания.
// onOneServer: воркеров может быть несколько, планировщик в каждом, а запуск - один
Schedule::command('subscriptions:renew')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('invoices:collect')->everyMinute()->withoutOverlapping()->onOneServer();

// ключи идемпотентности живут сутки, дальше только мешают
Schedule::command('idempotency:prune')->hourly()->onOneServer();
Schedule::command('activity:prune')->daily()->onOneServer();

// /health/deep смотрит, что планировщик жив
Schedule::call(fn () => Cache::put('heartbeat:scheduler', time(), 3600))->everyMinute();
