<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ApiKeyService
{
    /** @return array{key: ApiKey, plain: string} plain показывается один раз */
    public function create(Organization $organization, User $creator, string $name, ?CarbonImmutable $expiresAt = null): array
    {
        $plain = ApiKey::PREFIX.Str::random(40);

        $key = ApiKey::create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'name' => $name,
            'prefix' => substr($plain, 0, 16),
            'key_hash' => ApiKey::hash($plain),
            'expires_at' => $expiresAt,
        ]);

        return ['key' => $key, 'plain' => $plain];
    }

    public function revoke(ApiKey $key): ApiKey
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return $key;
    }
}
