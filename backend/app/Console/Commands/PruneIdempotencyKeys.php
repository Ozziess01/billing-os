<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Удалить истёкшие ключи идемпотентности';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()->where('expires_at', '<', now())->delete();
        $this->info("Удалено ключей: {$deleted}");

        return self::SUCCESS;
    }
}
