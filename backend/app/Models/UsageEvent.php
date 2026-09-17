<?php

namespace App\Models;

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
 * @property string $subscription_item_id
 * @property string $customer_id
 * @property int $quantity
 * @property CarbonImmutable $timestamp
 * @property string|null $idempotency_key
 * @property string|null $invoice_item_id
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property-read Customer $customer
 * @property-read Organization $organization
 * @property-read SubscriptionItem $subscriptionItem
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereIdempotencyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereInvoiceItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereSubscriptionItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UsageEvent whereTimestamp($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'subscription_item_id', 'customer_id', 'quantity', 'timestamp', 'idempotency_key', 'invoice_item_id', 'metadata'])]
class UsageEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'timestamp' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
