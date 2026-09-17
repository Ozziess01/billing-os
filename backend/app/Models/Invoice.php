<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\TransitionsStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
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
 * @property string|null $subscription_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property string $currency
 * @property int $subtotal
 * @property int $discount
 * @property int $total
 * @property int $amount_paid
 * @property int $amount_due
 * @property string|null $description
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $uncollectible_at
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $auto_collect
 * @property CarbonImmutable|null $next_payment_attempt_at
 * @property int $collection_attempts
 * @property string|null $coupon_id
 * @property-read Customer $customer
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read int|null $items_count
 * @property-read Organization $organization
 * @property-read Collection<int, Payment> $payments
 * @property-read int|null $payments_count
 * @property-read Subscription|null $subscription
 *
 * @method static \Database\Factories\InvoiceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereAmountDue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereAmountPaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereAutoCollect($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCollectionAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCouponId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDiscount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereFinalizedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereNextPaymentAttemptAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUncollectibleAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereVoidedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'organization_id', 'customer_id', 'subscription_id', 'number', 'status', 'currency',
    'subtotal', 'discount', 'total', 'amount_paid', 'amount_due', 'description', 'coupon_id',
    'auto_collect', 'next_payment_attempt_at', 'collection_attempts',
    'period_start', 'period_end', 'due_at', 'finalized_at', 'paid_at', 'voided_at', 'uncollectible_at', 'metadata',
])]
class Invoice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUlids, TransitionsStatus;

    protected $attributes = ['subtotal' => 0, 'discount' => 0, 'total' => 0, 'amount_paid' => 0, 'amount_due' => 0, 'auto_collect' => false, 'collection_attempts' => 0];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'amount_due' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'uncollectible_at' => 'immutable_datetime',
            'auto_collect' => 'boolean',
            'next_payment_attempt_at' => 'immutable_datetime',
            'collection_attempts' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('created_at');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('attempt_number');
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    public function amountDueMoney(): Money
    {
        return Money::of($this->amount_due, $this->currency);
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isOpen(): bool
    {
        return $this->status === InvoiceStatus::Open;
    }
}
