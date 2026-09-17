<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $coupon_id
 * @property int $organization_id
 * @property string $customer_id
 * @property string|null $subscription_id
 * @property CarbonImmutable $redeemed_at
 * @property-read Coupon $coupon
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption whereCouponId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption whereRedeemedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CouponRedemption whereSubscriptionId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['coupon_id', 'organization_id', 'customer_id', 'subscription_id', 'redeemed_at'])]
class CouponRedemption extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['redeemed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
