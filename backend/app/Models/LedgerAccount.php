<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property LedgerAccountType $type
 * @property string $currency
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, LedgerEntry> $entries
 * @property-read int|null $entries_count
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerAccount whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'type', 'currency', 'name'])]
class LedgerAccount extends Model
{
    use BelongsToOrganization, HasUlids;

    protected function casts(): array
    {
        return ['type' => LedgerAccountType::class];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }
}
