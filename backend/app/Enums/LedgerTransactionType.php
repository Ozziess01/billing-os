<?php

namespace App\Enums;

enum LedgerTransactionType: string
{
    case Invoice = 'invoice';
    case Payment = 'payment';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
