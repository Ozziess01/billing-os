<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->notifications()->where('data->organization_id', $this->current->id());

        $notifications = (clone $query)->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json([
            'data' => collect($notifications->items())->map(fn ($n) => [
                'id' => $n->id,
                'event' => $n->data['event'] ?? null,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'resource' => $n->data['resource'] ?? null,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread' => (clone $query)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['read_at' => $notification->read_at]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->where('data->organization_id', $this->current->id())->update(['read_at' => now()]);

        return response()->json(null, 204);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferenceRows($request)]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.event' => ['required', 'string', 'in:'.implode(',', array_keys(config('notifications.events')))],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.mail' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $row) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $request->user()->id, 'organization_id' => $this->current->id(), 'event' => $row['event']],
                ['in_app' => $row['in_app'], 'mail' => $row['mail']],
            );
        }

        return response()->json(['data' => $this->preferenceRows($request)]);
    }

    /** @return list<array<string, mixed>> */
    private function preferenceRows(Request $request): array
    {
        $saved = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $this->current->id())
            ->get()
            ->keyBy('event');

        $rows = [];
        foreach (config('notifications.events') as $event => $defaults) {
            $preference = $saved->get($event);
            $rows[] = [
                'event' => $event,
                'label' => $defaults['label'],
                'in_app' => $preference ? $preference->in_app : $defaults['in_app'],
                'mail' => $preference ? $preference->mail : $defaults['mail'],
            ];
        }

        return $rows;
    }
}
