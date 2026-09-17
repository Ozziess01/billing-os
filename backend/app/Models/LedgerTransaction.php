<?php

namespace App\Models;

use App\Enums\LedgerTransactionType;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property LedgerTransactionType $type
 * @property string $currency
 * @property int $amount
 * @property string $description
 * @property string $reference_type
 * @property string $reference_id
 * @property CarbonImmutable $posted_at
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property-read Collection<int, LedgerEntry> $entries
 * @property-read int|null $entries_count
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction forOrganization(\App\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction wherePostedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereReferenceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerTransaction whereType($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['organization_id', 'type', 'currency', 'amount', 'description', 'reference_type', 'reference_id', 'posted_at', 'metadata'])]
class LedgerTransaction extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'amount' => 'integer',
            'posted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }
}
