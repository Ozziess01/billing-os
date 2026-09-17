<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Каждая биллинговая сущность принадлежит организации. Скоуп явный, а не глобальный:
 * запрос без forOrganization() не должен молча отдавать чужие данные, но и не должен
 * зависеть от того, какой пользователь сейчас залогинен.
 */
trait BelongsToOrganization
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where($this->qualifyColumn('organization_id'), $organization instanceof Organization ? $organization->id : $organization);
    }

    public function belongsToOrganization(Organization $organization): bool
    {
        return (int) $this->organization_id === (int) $organization->id;
    }

    /**
     * Маршруты ищут модель только внутри организации запроса: чужой id - 404,
     * даже если пользователь состоит и в той организации тоже.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->newQuery()
            ->forOrganization(app(CurrentOrganization::class)->organization())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
