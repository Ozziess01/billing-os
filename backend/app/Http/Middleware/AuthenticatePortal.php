<?php

namespace App\Http\Middleware;

use App\Models\PortalSession;
use App\Tenancy\CurrentCustomer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Портал клиента живёт по токену сессии (bps_…), а не по учётке пользователя.
 * Токен приходит Bearer-заголовком, хранится только его хеш, срок - сутки.
 */
class AuthenticatePortal
{
    public function __construct(private readonly CurrentCustomer $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) ($request->bearerToken() ?? $request->header('X-Portal-Token', ''));

        if (! str_starts_with($token, 'bps_')) {
            return response()->json(['message' => 'Portal session required.'], 401);
        }

        $session = PortalSession::query()->with('customer')->where('token_hash', hash('sha256', $token))->first();

        if (! $session || ! $session->isActive()) {
            return response()->json(['message' => 'Portal session expired.'], 401);
        }

        if (! $session->last_used_at || $session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->save();
        }

        $this->current->set($session->customer, $session);

        return $next($request);
    }
}
