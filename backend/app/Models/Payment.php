<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\TransitionsStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property string $customer_id
 * @property string $invoice_id
 * @property int $attempt_number
 * @property PaymentStatus $status
 * @property string $currency
 * @property int $amount
 * @property int $amount_refunded
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property string $payment_method
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<array-key, mixed>|null $next_action
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $canceled_at
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Invoice $invoice
 * @property-read Organization $organization
 * @property-read Collection<int, Refund> $refunds
 * @property-read int|null $refunds_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereAmountRefunded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereAttemptNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCanceledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereFailureCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereFailureMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereInvoiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereNextAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereProviderPaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereSucceededAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'organization_id', 'customer_id', 'invoice_id', 'attempt_number', 'status', 'currency', 'amount', 'amount_refunded',
    'provider', 'provider_payment_id', 'payment_method', 'failure_code', 'failure_message', 'next_action',
    'succeeded_at', 'failed_at', 'canceled_at', 'metadata',
])]
class Payment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids, TransitionsStatus;

    protected $attributes = ['amount_refunded' => 0];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'attempt_number' => 'integer',
            'amount' => 'integer',
            'amount_refunded' => 'integer',
            'next_action' => 'array',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    /** Сколько ещё можно вернуть с учётом уже успешных возвратов. */
    public function refundableAmount(): int
    {
        return $this->amount - $this->amount_refunded;
    }

    public function isSucceeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
