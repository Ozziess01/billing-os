<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\DedupedDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Уведомление о событии биллинга. Каналы - по настройкам пользователя в организации,
 * с дефолтами из config/notifications.php. Одинаковый dedupe_key - одна запись.
 */
class BillingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array{type: string, id: string}|null  $resource */
    public function __construct(
        public readonly string $event,
        public readonly int $organizationId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?array $resource,
        public readonly string $dedupeKey,
    ) {
        $this->onQueue('default');
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        // имя события содержит точку, поэтому не через config('a.b.c')
        $defaults = config('notifications.events')[$this->event] ?? ['in_app' => true, 'mail' => false];
        $preference = NotificationPreference::query()
            ->where('user_id', $notifiable->id)
            ->where('organization_id', $this->organizationId)
            ->where('event', $this->event)
            ->first();

        $channels = [];
        if ($preference ? $preference->in_app : $defaults['in_app']) {
            $channels[] = DedupedDatabaseChannel::class;
            $channels[] = 'broadcast';
        }
        if ($preference ? $preference->mail : $defaults['mail']) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => $this->event,
            'organization_id' => $this->organizationId,
            'title' => $this->title,
            'body' => $this->body,
            'resource' => $this->resource,
        ];
    }

    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable) + ['dedupe_key' => $this->dedupeKey]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $link = match ($this->resource['type'] ?? null) {
            'invoice' => "{$url}/invoices/{$this->resource['id']}",
            'payment' => "{$url}/payments/{$this->resource['id']}",
            'subscription' => "{$url}/subscriptions/{$this->resource['id']}",
            default => "{$url}/dashboard",
        };

        return (new MailMessage)
            ->subject($this->title)
            ->line($this->body)
            ->action('Открыть в BillingOS', $link);
    }
}
