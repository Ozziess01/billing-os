<?php

namespace App\Models;

use App\Enums\CouponDuration;
use App\Enums\CouponType;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property CouponType $type
 * @property int|null $percent_off
 * @property int|null $amount_off
 * @property string|null $currency
 * @property CouponDuration $duration
 * @property CarbonImmutable|null $redeem_by
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property string|null $customer_id
 * @property bool $active
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Organization $organization
 * @property-read Collection<int, CouponRedemption> $redemptions
 * @property-read int|null $redemptions_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereAmountOff($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereMaxRedemptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon wherePercentOff($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereRedeemBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereTimesRedeemed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'code', 'name', 'type', 'percent_off', 'amount_off', 'currency', 'duration', 'redeem_by', 'max_redemptions', 'times_redeemed', 'customer_id', 'active', 'metadata'])]
class Coupon extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $attributes = ['active' => true, 'duration' => 'forever', 'times_redeemed' => 0];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'duration' => CouponDuration::class,
            'percent_off' => 'integer',
            'amount_off' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'redeem_by' => 'immutable_datetime',
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** Скидка на сумму: процент округляется half-up, фиксированная не больше суммы. */
    public function discountFor(int $subtotal, string $currency): int
    {
        if ($this->type === CouponType::Percent) {
            return intdiv($subtotal * (int) $this->percent_off + 50, 100);
        }

        if ($this->currency !== $currency) {
            return 0;
        }

        return min((int) $this->amount_off, $subtotal);
    }

    public function isExpired(): bool
    {
        return $this->redeem_by !== null && $this->redeem_by->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions;
    }
}
