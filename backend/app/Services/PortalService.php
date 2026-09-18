<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PortalSession;
use App\Models\User;
use Illuminate\Support\Str;

class PortalService
{
    /** @return array{session: PortalSession, token: string, url: string} */
    public function createSession(Customer $customer, ?User $createdBy = null): array
    {
        $token = 'bps_'.Str::random(48);

        $session = PortalSession::create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours((int) config('billing.portal_session_hours', 24)),
            'created_by' => $createdBy?->id,
        ]);

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return ['session' => $session, 'token' => $token, 'url' => "{$frontend}/portal/{$token}"];
    }
}
