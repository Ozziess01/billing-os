<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// биллинговый цикл: продления по окончании периода и плановые попытки автосписания
Schedule::command('subscriptions:renew')->everyMinute()->withoutOverlapping();
Schedule::command('invoices:collect')->everyMinute()->withoutOverlapping();

// ключи идемпотентности живут сутки, дальше только мешают
Schedule::command('idempotency:prune')->hourly();
Schedule::command('activity:prune')->daily();

// /health/deep смотрит, что планировщик жив
Schedule::call(fn () => Cache::put('heartbeat:scheduler', time(), 3600))->everyMinute();
