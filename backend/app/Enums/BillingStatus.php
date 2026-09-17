<?php

namespace App\Enums;

/** Статус со state machine: у каждого значения есть список допустимых переходов. */
interface BillingStatus
{
    public function canTransitionTo(BillingStatus $to): bool;
}
