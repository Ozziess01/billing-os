<?php

namespace App\Models\Concerns;

use App\Billing\InvalidTransition;
use App\Enums\BillingStatus;
use BackedEnum;

/**
 * Смена статуса строго по таблице переходов enum'а и одним атомарным
 * UPDATE ... WHERE status = :from: два процесса не переведут запись дважды,
 * а терминальные состояния никогда не оживут.
 */
trait TransitionsStatus
{
    /** @param  array<string, mixed>  $extra */
    public function transition(BillingStatus&BackedEnum $to, array $extra = []): void
    {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidTransition::between(class_basename($this)." {$this->getKey()}", $from->value, $to->value);
        }

        $changed = static::query()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => now(), ...$extra]);

        if ($changed !== 1) {
            throw InvalidTransition::between(class_basename($this)." {$this->getKey()}", $from->value, $to->value);
        }

        $this->forceFill(['status' => $to, ...$extra])->syncOriginal();
    }
}
