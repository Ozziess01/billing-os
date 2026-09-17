<?php

namespace App\Billing;

use DomainException;

/** Переход состояния, которого нет в state machine: отвечаем 409, а не молча правим статус. */
class InvalidTransition extends DomainException
{
    public static function between(string $subject, string $from, string $to): self
    {
        return new self("{$subject}: переход {$from} → {$to} невозможен.");
    }
}
