<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\BillingInterval;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PriceFactory;
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
 * @property string $product_id
 * @property string|null $nickname
 * @property string $currency
 * @property int $unit_amount
 * @property BillingInterval $billing_interval
 * @property int $interval_count
 * @property bool $active
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Product $product
 * @property-read Collection<int, SubscriptionItem> $subscriptionItems
 * @property-read int|null $subscription_items_count
 *
 * @method static \Database\Factories\PriceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereBillingInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereIntervalCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereUnitAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'product_id', 'nickname', 'currency', 'unit_amount', 'billing_interval', 'interval_count', 'active', 'metadata'])]
class Price extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PriceFactory> */
    use HasFactory, HasUlids;

    protected $attributes = ['active' => true, 'interval_count' => 1];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'integer',
            'interval_count' => 'integer',
            'billing_interval' => BillingInterval::class,
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<SubscriptionItem, $this> */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function unitMoney(): Money
    {
        return Money::of($this->unit_amount, $this->currency);
    }

    /** Сумма за период для количества $quantity. */
    public function amountFor(int $quantity): Money
    {
        return $this->unitMoney()->multiply($quantity);
    }

    /** Приведение к месячной сумме для MRR: годовая цена / 12, недельная × 52 / 12. */
    public function monthlyAmount(int $quantity = 1): int
    {
        return (int) round($this->unit_amount * $quantity * $this->billing_interval->perMonth() / $this->interval_count);
    }
}
