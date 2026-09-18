<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ActivityLog::class);

        $filters = $request->validate([
            'action' => ['sometimes', 'string', 'max:60'],
            'resource_type' => ['sometimes', 'string', 'max:40'],
            'resource_id' => ['sometimes', 'string', 'max:26'],
        ]);

        $logs = ActivityLog::query()
            ->forOrganization($this->current->organization())
            ->when(isset($filters['action']), fn ($q) => $q->where('action', 'like', $filters['action'].'%'))
            ->when(isset($filters['resource_type']), fn ($q) => $q->where('resource_type', $filters['resource_type']))
            ->when(isset($filters['resource_id']), fn ($q) => $q->where('resource_id', $filters['resource_id']))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($logs->items())->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => ['type' => $log->actor_type, 'id' => $log->actor_id, 'label' => $log->actor_label],
                'resource' => $log->resource_type ? ['type' => $log->resource_type, 'id' => $log->resource_id] : null,
                'metadata' => $log->metadata ?? (object) [],
                'ip' => $log->ip,
                'created_at' => $log->created_at,
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
