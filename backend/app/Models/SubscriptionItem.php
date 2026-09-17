<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $subscription_id
 * @property string $price_id
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Price $price
 * @property-read Subscription $subscription
 * @property-read Collection<int, UsageEvent> $usageEvents
 * @property-read int|null $usage_events_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem wherePriceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem whereSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionItem whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['subscription_id', 'price_id', 'quantity'])]
class SubscriptionItem extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /** @return HasMany<UsageEvent, $this> */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }
}
