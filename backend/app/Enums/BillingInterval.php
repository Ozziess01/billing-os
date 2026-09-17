<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * Конец периода, начатого в $start. Месяцы и годы прибавляются без переполнения:
     * 31 января + 1 месяц = 28/29 февраля, а не 3 марта.
     */
    public function advance(CarbonImmutable $start, int $count): CarbonImmutable
    {
        return match ($this) {
            self::Day => $start->addDays($count),
            self::Week => $start->addWeeks($count),
            self::Month => $start->addMonthsNoOverflow($count),
            self::Year => $start->addYearsNoOverflow($count),
        };
    }

    /** Сколько таких интервалов в месяце: для нормализации к MRR. */
    public function perMonth(): float
    {
        return match ($this) {
            self::Day => 365 / 12,
            self::Week => 52 / 12,
            self::Month => 1,
            self::Year => 1 / 12,
        };
    }
}
