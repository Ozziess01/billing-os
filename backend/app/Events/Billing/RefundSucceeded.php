<?php

namespace App\Events\Billing;

use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Refund $refund) {}
}
