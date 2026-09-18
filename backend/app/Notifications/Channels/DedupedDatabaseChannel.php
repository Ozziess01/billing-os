<?php

namespace App\Notifications\Channels;

use App\Notifications\BillingNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/** Стандартный канал БД плюс dedupe_key: дубликат события молча пропускается. */
class DedupedDatabaseChannel extends DatabaseChannel
{
    public function send($notifiable, Notification $notification)
    {
        try {
            return DB::transaction(fn () => parent::send($notifiable, $notification));
        } catch (UniqueConstraintViolationException) {
            // уже уведомляли этим же dedupe_key - отдаём существующую запись
            return $notifiable->routeNotificationFor('database', $notification)
                ->where('dedupe_key', $notification instanceof BillingNotification ? $notification->dedupeKey : '')
                ->firstOrFail();
        }
    }

    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof BillingNotification) {
            $payload['event'] = $notification->event;
            $payload['dedupe_key'] = $notification->dedupeKey;
        }

        return $payload;
    }
}
