<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\RefundStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property string $payment_id
 * @property RefundStatus $status
 * @property string $currency
 * @property int $amount
 * @property string|null $reason
 * @property string|null $provider_refund_id
 * @property string|null $failure_message
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $failed_at
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Payment $payment
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereFailureMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereProviderRefundId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereSucceededAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Refund whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'payment_id', 'status', 'currency', 'amount', 'reason', 'provider_refund_id', 'failure_message', 'succeeded_at', 'failed_at', 'metadata'])]
class Refund extends Model
{
    use BelongsToOrganization, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'integer',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
