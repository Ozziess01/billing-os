<?php

use App\Enums\Role;
use App\Events\BillingEventBroadcast;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Notifications\BillingNotification;
use App\Notifications\Channels\DedupedDatabaseChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('notifies developers and above about billing events through in-app and mail per preferences', function () {
    $organization = organization();
    $admin = member($organization, Role::Admin);
    $viewer = member($organization, Role::Viewer);
    $customer = customer($organization, ['default_payment_method' => 'tok_fail']);
    $price = price($organization, ['unit_amount' => 1000]);
    actingIn($organization);

    // подписка → инвойс → неудачное списание: три события
    $this->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'items' => [['price_id' => $price->id]]])->assertCreated();

    $events = fn ($user) => DatabaseNotification::query()->where('notifiable_id', $user->id)->pluck('event')->sort()->values()->all();
    expect($events($organization->owner))->toBe(['invoice.created', 'payment.failed', 'subscription.created'])
        ->and($events($admin))->toBe(['invoice.created', 'payment.failed', 'subscription.created'])
        ->and($events($viewer))->toBe([]);

    // письмо только по payment.failed (дефолт), и только участникам developer+
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(2);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.unread', 3);

    $id = $this->getJson('/api/v1/notifications')->json('data.0.id');
    $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();
    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 2);
    $this->postJson('/api/v1/notifications/read-all')->assertNoContent();
    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 0);
});

it('does not duplicate a notification for the same event and honours per-user preferences', function () {
    $organization = organization();
    $owner = $organization->owner;
    Notification::fake();

    $invoice = Invoice::factory()->for(customer($organization))->create(['status' => 'open', 'number' => 'INV-000009', 'total' => 100, 'subtotal' => 100, 'amount_due' => 100]);
    $notification = new BillingNotification('invoice.paid', $organization->id, 'x', 'y', ['type' => 'invoice', 'id' => $invoice->id], "invoice.paid:{$invoice->id}");
    $notification->id = (string) Str::uuid();

    // канал БД с дедупом: второй send с тем же ключом не пишет вторую строку
    Notification::assertNothingSent();
    $channel = app(DedupedDatabaseChannel::class);
    $channel->send($owner, $notification);
    $channel->send($owner, $notification);
    expect(DatabaseNotification::query()->where('notifiable_id', $owner->id)->count())->toBe(1);

    // выключенный in_app убирает и базу, и broadcast; включённая почта добавляет mail
    NotificationPreference::create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'event' => 'invoice.paid', 'in_app' => false, 'mail' => true]);
    expect($notification->via($owner))->toBe(['mail']);
    NotificationPreference::query()->update(['in_app' => true, 'mail' => false]);
    expect($notification->via($owner))->toBe([DedupedDatabaseChannel::class, 'broadcast']);
});

it('exposes and updates notification preferences per organization', function () {
    $organization = organization();
    actingIn($organization);

    $this->getJson('/api/v1/notifications/preferences')
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonFragment(['event' => 'payment.failed', 'in_app' => true, 'mail' => true]);

    $this->patchJson('/api/v1/notifications/preferences', ['preferences' => [
        ['event' => 'payment.failed', 'in_app' => true, 'mail' => false],
        ['event' => 'invoice.paid', 'in_app' => false, 'mail' => true],
    ]])->assertOk()
        ->assertJsonFragment(['event' => 'payment.failed', 'in_app' => true, 'mail' => false])
        ->assertJsonFragment(['event' => 'invoice.paid', 'in_app' => false, 'mail' => true]);

    $this->patchJson('/api/v1/notifications/preferences', ['preferences' => [['event' => 'nope', 'in_app' => true, 'mail' => true]]])->assertUnprocessable();

    // настройки другой организации не видны
    actingIn(organization($organization->owner, 'Second'));
    $this->getJson('/api/v1/notifications/preferences')->assertJsonFragment(['event' => 'invoice.paid', 'in_app' => true, 'mail' => false]);
});

it('broadcasts billing events to the organization channel', function () {
    Event::fake([BillingEventBroadcast::class]);
    $organization = organization();
    $price = price($organization, ['unit_amount' => 500]);
    actingIn($organization);

    $subscription = $this->postJson('/api/v1/subscriptions', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data');
    $invoice = Invoice::query()->where('subscription_id', $subscription['id'])->firstOrFail();
    $this->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'payment_method' => 'tok_ok'])->assertCreated();

    Event::assertDispatched(BillingEventBroadcast::class, fn ($e) => $e->type === 'subscription.created' && $e->organizationId === $organization->id);
    Event::assertDispatched(BillingEventBroadcast::class, fn ($e) => $e->type === 'invoice.finalized');
    Event::assertDispatched(BillingEventBroadcast::class, fn ($e) => $e->type === 'payment.succeeded' && $e->payload['invoice_id'] === $invoice->id);
    Event::assertDispatched(BillingEventBroadcast::class, fn ($e) => $e->type === 'invoice.paid');

    $event = new BillingEventBroadcast($organization->id, 'invoice.paid', ['invoice_id' => $invoice->id]);
    expect($event->broadcastOn()[0]->name)->toBe('private-organization.'.$organization->id)
        ->and($event->broadcastAs())->toBe('billing.event')
        ->and($event->broadcastWith())->toBe(['type' => 'invoice.paid', 'invoice_id' => $invoice->id]);
});

it('authorizes the organization channel for members only', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a', 'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http']]]);
    // в тестах broadcaster null, поэтому каналы регистрируем руками
    require base_path('routes/channels.php');
    $organization = organization();
    $stranger = organization()->owner;

    actingIn($organization, Role::Viewer);
    $this->postJson('/api/broadcasting/auth', ['channel_name' => "private-organization.{$organization->id}", 'socket_id' => '1.1'])->assertOk();

    Sanctum::actingAs($stranger);
    $this->postJson('/api/broadcasting/auth', ['channel_name' => "private-organization.{$organization->id}", 'socket_id' => '1.1'])->assertForbidden();
});
