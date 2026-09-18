<?php

namespace App\Auth;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Guard для ключей интеграции (bos_live_…): активный ключ по хешу представляет вызов
 * как действие создателя ключа. Права капятся до developer в ResolveOrganization,
 * который сам находит ключ по токену и не зависит от кэша guard'а.
 */
class ApiKeyGuard
{
    public function __invoke(Request $request): ?User
    {
        $key = self::fromRequest($request);

        if (! $key) {
            return null;
        }

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        // от чьего имени работает ключ: его создатель, а если тот ушёл - владелец организации
        return $key->creator ?? User::query()->find($key->organization->owner_id);
    }

    /** Активный ключ из Bearer-токена запроса или null. */
    public static function fromRequest(Request $request): ?ApiKey
    {
        $token = (string) $request->bearerToken();

        if (! str_starts_with($token, ApiKey::PREFIX)) {
            return null;
        }

        $key = ApiKey::query()->with('organization', 'creator')->where('key_hash', ApiKey::hash($token))->first();

        return $key && $key->isActive() ? $key : null;
    }
}
