<?php

use Illuminate\Support\Facades\Schedule;

// биллинговый цикл: продления по окончании периода и плановые попытки автосписания
Schedule::command('subscriptions:renew')->everyMinute()->withoutOverlapping();
Schedule::command('invoices:collect')->everyMinute()->withoutOverlapping();

// ключи идемпотентности живут сутки, дальше только мешают
Schedule::command('idempotency:prune')->hourly();
