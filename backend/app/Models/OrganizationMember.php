<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property Role $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationMember whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'user_id', 'role'])]
class OrganizationMember extends Model
{
    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
