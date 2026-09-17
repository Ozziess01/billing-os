<?php

namespace App\Enums;

/**
 * Упрощённая двойная запись. Активные счета (cash, receivable) растут по дебету,
 * пассивные (revenue) - по кредиту; adjustments собирает списания по void/uncollectible.
 */
enum LedgerAccountType: string
{
    case Cash = 'cash';
    case Receivable = 'receivable';
    case Revenue = 'revenue';
    case Adjustments = 'adjustments';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Receivable => 'Accounts receivable',
            self::Revenue => 'Revenue',
            self::Adjustments => 'Adjustments',
        };
    }

    /** Нормальный баланс: у активных счетов дебет минус кредит, у остальных наоборот. */
    public function isDebitNormal(): bool
    {
        return $this === self::Cash || $this === self::Receivable || $this === self::Adjustments;
    }
}
