<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly ApiKeyService $keys,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApiKey::class);

        return ApiKeyResource::collection(
            ApiKey::query()->forOrganization($this->current->organization())->with('creator')->latest()->get()
        );
    }

    /** Ключ показывается один раз - в ответе на создание; дальше только префикс. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ApiKey::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $created = $this->keys->create(
            $this->current->organization(),
            $request->user(),
            $data['name'],
            isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
        );

        return (new ApiKeyResource($created['key']->load('creator')))
            ->additional(['plain_key' => $created['plain']])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(ApiKey $key): JsonResponse
    {
        $this->authorize('delete', $key);

        $this->keys->revoke($key);

        return response()->json(null, 204);
    }
}
