<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneActivity extends Command
{
    protected $signature = 'activity:prune {--days=}';

    protected $description = 'Ротация журнала действий по сроку хранения';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('billing.activity_retention_days', 365));

        // единственное место, где журнал можно уменьшить: флаг снимается вместе с транзакцией
        $deleted = DB::transaction(function () use ($days) {
            DB::statement("SET LOCAL billingos.audit_maintenance = '1'");

            return DB::table('activity_logs')->where('created_at', '<', now()->subDays($days))->delete();
        });

        $this->info("Удалено записей старше {$days} дн.: {$deleted}");

        return self::SUCCESS;
    }
}
