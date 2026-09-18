<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property string $event
 * @property bool $in_app
 * @property bool $mail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereInApp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereMail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'organization_id', 'event', 'in_app', 'mail'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'mail' => 'boolean'];
    }
}
