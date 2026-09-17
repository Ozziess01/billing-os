<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookEventResource;
use App\Models\WebhookEvent;
use App\Payments\InvalidWebhook;
use App\Payments\ProviderException;
use App\Payments\ProviderRegistry;
use App\Payments\Providers\FakeProvider;
use App\Services\WebhookService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly ProviderRegistry $providers,
    ) {}

    /** Публичный вход для провайдера: подпись вместо аутентификации, тело - как есть, сырое. */
    public function handle(Request $request, string $provider): JsonResponse
    {
        if (! $this->providers->has($provider)) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        try {
            $received = $this->webhooks->receive($provider, (string) $request->getContent(), $headers);
        } catch (InvalidWebhook $e) {
            Log::warning('webhook rejected', ['provider' => $provider, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json([
            'received' => true,
            'duplicate' => $received['duplicate'],
            'event' => $received['event']->id,
        ], $received['duplicate'] ? 200 : 202);
    }

    public function events(Request $request, CurrentOrganization $current): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WebhookEvent::class);

        $events = WebhookEvent::query()
            ->where('organization_id', $current->id())
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return WebhookEventResource::collection($events);
    }

    /**
     * Симуляция действия клиента у fake-провайдера (аналог подтверждения 3-D Secure):
     * провайдер завершает платёж и присылает вебхук - как в жизни, только без банка.
     */
    public function confirmFakePayment(Request $request, CurrentOrganization $current, FakeProvider $fake, string $providerPaymentId): JsonResponse
    {
        $this->authorize('viewAny', WebhookEvent::class);

        $data = $request->validate(['success' => ['sometimes', 'boolean']]);

        $owns = $current->organization()->payments()->where('provider', FakeProvider::NAME)->where('provider_payment_id', $providerPaymentId)->exists();
        abort_unless($owns, 404);

        try {
            $fake->completeAction($providerPaymentId, (bool) ($data['success'] ?? true));
        } catch (ProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['confirmed' => true]);
    }
}
