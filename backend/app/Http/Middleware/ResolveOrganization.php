<?php

namespace App\Http\Middleware;

use App\Auth\ApiKeyGuard;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Организация запроса приходит в заголовке X-Organization (id или slug).
 * Если пользователь состоит ровно в одной - заголовок можно не слать.
 * Чужая или несуществующая организация - 404, чтобы не раскрывать, что она есть.
 */
class ResolveOrganization
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // запрос по API-ключу: организация - у ключа, права - не выше developer
        if ($key = ApiKeyGuard::fromRequest($request)) {
            $this->current->set($key->organization, $user, Role::Developer);

            return $next($request);
        }
        $memberships = OrganizationMember::query()->with('organization')->where('user_id', $user->id)->get();
        $header = trim((string) $request->header('X-Organization'));

        if ($header === '') {
            if ($memberships->count() !== 1) {
                abort(400, 'X-Organization header is required.');
            }
            $membership = $memberships->first();
        } else {
            $organization = Organization::query()
                ->when(ctype_digit($header), fn ($q) => $q->whereKey((int) $header), fn ($q) => $q->where('slug', $header))
                ->first();
            $membership = $organization ? $memberships->firstWhere('organization_id', $organization->id) : null;
        }

        if (! $membership) {
            abort(404, 'Organization not found.');
        }

        $this->current->set($membership->organization, $user, $membership->role);

        return $next($request);
    }
}
