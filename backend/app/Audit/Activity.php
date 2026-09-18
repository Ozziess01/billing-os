<?php

namespace App\Audit;

use App\Auth\ApiKeyGuard;
use App\Models\ActivityLog;
use App\Models\User;
use App\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Журнал действий: кто, что, с чем и откуда. Актор определяется по контексту запроса:
 * пользователь, API-ключ, клиент в портале или система (планировщик, вебхуки).
 * Журнал append-only, значения секретов в metadata не попадают.
 */
class Activity
{
    /** @param  array<string, mixed>  $metadata */
    public function record(string $action, int $organizationId, ?Model $resource = null, array $metadata = []): ActivityLog
    {
        [$type, $id, $label] = $this->actor();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return ActivityLog::create([
            'organization_id' => $organizationId,
            'actor_type' => $type,
            'actor_id' => $id,
            'actor_label' => $label,
            'action' => $action,
            'resource_type' => $resource ? strtolower(class_basename($resource)) : null,
            'resource_id' => $resource ? (string) $resource->getKey() : null,
            'metadata' => $metadata ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            'created_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: string|null, 2: string|null} */
    private function actor(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['system', null, 'scheduler'];
        }

        /** @var Request $request */
        $request = request();

        if ($key = ApiKeyGuard::fromRequest($request)) {
            return ['api_key', $key->id, "API key {$key->name}"];
        }

        $portal = app(CurrentCustomer::class);
        try {
            $customer = $portal->customer();

            return ['customer', $customer->id, $customer->name];
        } catch (\Throwable) {
            // не портальный запрос
        }

        $user = $request->user();
        if ($user instanceof User) {
            return ['user', (string) $user->id, $user->email];
        }

        return ['system', null, 'system'];
    }
}
