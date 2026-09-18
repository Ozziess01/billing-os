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
 * @property int|null $created_by
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereKeyHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey wherePrefix($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiKey whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'created_by', 'name', 'prefix', 'key_hash', 'last_used_at', 'expires_at', 'revoked_at'])]
class ApiKey extends Model
{
    use BelongsToOrganization, HasUlids;

    public const PREFIX = 'bos_live_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
