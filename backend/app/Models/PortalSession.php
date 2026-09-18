<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property string $customer_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Customer $customer
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortalSession whereTokenHash($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'customer_id', 'token_hash', 'expires_at', 'last_used_at', 'created_by'])]
class PortalSession extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
