<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property array<array-key, mixed>|null $response_body
 * @property CarbonImmutable $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereRequestHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereResponseBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereResponseStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'key', 'request_hash', 'status', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
