<?php

namespace App\Enums;

enum UsageType: string
{
    case Licensed = 'licensed';
    case Metered = 'metered';
}
