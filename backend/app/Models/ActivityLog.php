<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property array<array-key, mixed>|null $metadata
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereActorLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereActorType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereResourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereUserAgent($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'actor_type', 'actor_id', 'actor_label', 'action', 'resource_type', 'resource_id', 'metadata', 'ip', 'user_agent', 'created_at'])]
class ActivityLog extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
