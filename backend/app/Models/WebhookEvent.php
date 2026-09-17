<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $organization_id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property array<array-key, mixed> $payload
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization|null $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'provider', 'event_id', 'type', 'payload', 'status', 'attempts', 'error', 'processed_at'])]
class WebhookEvent extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookEventStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
